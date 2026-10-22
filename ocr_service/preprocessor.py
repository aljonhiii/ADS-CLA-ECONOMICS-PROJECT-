"""
Image Preprocessing Engine for Tesseract OCR
Uses OpenCV to optimize scanned documents, school notes, and camera captures.

Features:
- EXIF orientation correction
- Intelligent resolution normalization / upscaling
- CLAHE (Contrast Limited Adaptive Histogram Equalization)
- Deskewing via minimum bounding rectangle analysis on text lines
- Edge-preserving denoising (Bilateral filtering)
- Non-destructive adaptive and Otsu thresholding
"""

import math
import logging
from typing import Tuple, Dict, Any, Optional
import cv2
import numpy as np
from PIL import Image, ImageOps

logger = logging.getLogger("ocr_service.preprocessor")


def pil_to_cv(pil_image: Image.Image) -> np.ndarray:
    """Convert a PIL Image to an OpenCV BGR numpy array."""
    if pil_image.mode == "RGBA":
        pil_image = pil_image.convert("RGB")
    elif pil_image.mode not in ("RGB", "L"):
        pil_image = pil_image.convert("RGB")

    arr = np.array(pil_image)
    if len(arr.shape) == 2:
        return cv2.cvtColor(arr, cv2.COLOR_GRAY2BGR)
    elif arr.shape[2] == 3:
        return cv2.cvtColor(arr, cv2.COLOR_RGB2BGR)
    return arr


def cv_to_pil(cv_image: np.ndarray) -> Image.Image:
    """Convert an OpenCV image (grayscale or BGR) to a PIL Image."""
    if len(cv_image.shape) == 2:
        return Image.fromarray(cv_image)
    return Image.fromarray(cv2.cvtColor(cv_image, cv2.COLOR_BGR2RGB))


def correct_exif_orientation(image: Image.Image) -> Image.Image:
    """Transpose image according to EXIF metadata if shot from a smartphone."""
    try:
        return ImageOps.exif_transpose(image)
    except Exception:
        return image


def normalize_resolution(cv_gray: np.ndarray, min_dimension: int = 1800, max_scale: float = 2.5) -> Tuple[np.ndarray, float]:
    """
    Upscale image if its text characters are likely too small for Tesseract's optimal font size.
    Tesseract accuracy peaks when x-height is 20-35 pixels.
    """
    h, w = cv_gray.shape[:2]
    max_dim = max(h, w)
    scale = 1.0

    if max_dim < min_dimension:
        scale = min(max_scale, float(min_dimension) / float(max_dim))
        if scale > 1.15:
            new_w = int(w * scale)
            new_h = int(h * scale)
            # Use Lanczos / Cubic interpolation for smooth character edges
            cv_gray = cv2.resize(cv_gray, (new_w, new_h), interpolation=cv2.INTER_CUBIC)
            logger.debug(f"Upscaled image by {scale:.2f}x to ({new_w}x{new_h})")

    return cv_gray, scale


def detect_skew_angle(cv_gray: np.ndarray) -> float:
    """
    Detect document skew angle in degrees using Otsu thresholding
    and contour orientation analysis. Returns angle in range [-45.0, 45.0].
    """
    h, w = cv_gray.shape[:2]

    # Downsample large images for fast angle computation (< 50ms)
    scale = 1.0
    work_img = cv_gray
    if max(h, w) > 1200:
        scale = 1200.0 / max(h, w)
        work_img = cv2.resize(cv_gray, (int(w * scale), int(h * scale)), interpolation=cv2.INTER_AREA)

    # Apply CLAHE to neutralize shadows, paper gradients, and uneven lighting
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    enhanced = clahe.apply(work_img)

    # Use adaptive Gaussian threshold to isolate text cleanly without shadow artifacts
    thresh = cv2.adaptiveThreshold(
        enhanced,
        255,
        cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
        cv2.THRESH_BINARY_INV,
        blockSize=25,
        C=11
    )

    # Dilate horizontally to connect words into single line blocks
    kernel_w = max(15, int(work_img.shape[1] * 0.025))
    kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (kernel_w, 3))
    dilated = cv2.dilate(thresh, kernel, iterations=2)

    # Find text block contours
    contours, _ = cv2.findContours(dilated, cv2.RETR_LIST, cv2.CHAIN_APPROX_SIMPLE)
    angles = []

    for c in contours:
        area = cv2.contourArea(c)
        # Filter out tiny noise and gigantic page borders
        if area < 100 or area > (work_img.shape[0] * work_img.shape[1] * 0.85):
            continue

        rect = cv2.minAreaRect(c)
        (rw, rh) = rect[1]
        if min(rw, rh) < 3 or max(rw, rh) < 15:
            continue

        ang = rect[2]
        # In OpenCV, if width is narrower than height, add 90 degrees
        if rw < rh:
            calc_ang = 90.0 + ang
        else:
            calc_ang = ang

        if abs(calc_ang) < 45.0:
            angles.append(calc_ang)

    if not angles:
        return 0.0

    # Use median to avoid outliers from graphics or logos
    median_angle = float(np.median(angles))
    return median_angle


def deskew_image(cv_gray: np.ndarray, angle: float) -> np.ndarray:
    """
    Rotate image by -angle around its center with white border fill to restore upright alignment.
    """
    if abs(angle) < 0.5:
        return cv_gray

    h, w = cv_gray.shape[:2]
    center = (w / 2.0, h / 2.0)
    # Counter-rotate by negative angle to un-tilt
    rot_mat = cv2.getRotationMatrix2D(center, -angle, 1.0)

    # Calculate new bounding dimensions to avoid cutting off corners
    cos_val = abs(rot_mat[0, 0])
    sin_val = abs(rot_mat[0, 1])
    new_w = int((h * sin_val) + (w * cos_val))
    new_h = int((h * cos_val) + (w * sin_val))

    rot_mat[0, 2] += (new_w / 2.0) - center[0]
    rot_mat[1, 2] += (new_h / 2.0) - center[1]

    deskewed = cv2.warpAffine(
        cv_gray,
        rot_mat,
        (new_w, new_h),
        flags=cv2.INTER_LINEAR,
        borderMode=cv2.BORDER_REPLICATE
    )
    logger.debug(f"Deskewed document by {-angle:.2f} degrees")
    return deskewed


def enhance_contrast_clahe(cv_gray: np.ndarray, clip_limit: float = 2.0, tile_size: Tuple[int, int] = (8, 8)) -> np.ndarray:
    """
    Apply CLAHE (Contrast Limited Adaptive Histogram Equalization).
    Compensates for uneven lighting, shadows, and faint printing while preventing over-amplification of noise.
    """
    clahe = cv2.createCLAHE(clipLimit=clip_limit, tileGridSize=tile_size)
    return clahe.apply(cv_gray)


def denoise_bilateral(cv_gray: np.ndarray, d: int = 7, sigma_color: float = 50.0, sigma_space: float = 50.0) -> np.ndarray:
    """
    Bilateral filter removes paper texture and grain while keeping character edges razor sharp.
    Far superior to Gaussian blur which softens character strokes.
    """
    return cv2.bilateralFilter(cv_gray, d, sigma_color, sigma_space)


def adaptive_threshold_sauvola_otsu(cv_gray: np.ndarray, mode: str = "otsu") -> np.ndarray:
    """
    Binarize document into clean black & white text.
    - 'otsu': Global optimal clustering, best for even illumination.
    - 'adaptive': Local window Gaussian thresholding, best for shadows or photos.
    """
    if mode == "adaptive":
        return cv2.adaptiveThreshold(
            cv_gray,
            255,
            cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
            cv2.THRESH_BINARY,
            blockSize=25,
            C=11
        )
    # Default Otsu
    return cv2.threshold(cv_gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)[1]


class ImagePreprocessor:
    """
    Unified, non-destructive document preprocessor for Tesseract OCR.
    """

    @classmethod
    def process(
        cls,
        image: Image.Image,
        profile: str = "auto",
        enable_deskew: bool = True,
        enable_clahe: bool = True,
        enable_denoise: bool = True
    ) -> Dict[str, Any]:
        """
        Process input PIL image according to requested profile.
        
        Returns dictionary:
        {
            'primary_image': PIL.Image (usually enhanced grayscale - best for Tesseract LSTM),
            'binary_image': Optional[PIL.Image] (Otsu/Adaptive binarized fallback),
            'skew_angle': float,
            'upscaled': bool,
            'scale_factor': float,
            'profile': str
        }
        """
        # 1. Smartphone EXIF orientation correction
        image = correct_exif_orientation(image)

        # 2. Convert to OpenCV grayscale
        cv_bgr = pil_to_cv(image)
        cv_gray = cv2.cvtColor(cv_bgr, cv2.COLOR_BGR2GRAY)

        # 3. Resolution normalization (upscale if small)
        cv_gray, scale = normalize_resolution(cv_gray, min_dimension=1800, max_scale=2.5)
        upscaled = (scale > 1.05)

        # 4. Deskewing
        detected_angle = 0.0
        if enable_deskew and profile != "raw":
            try:
                detected_angle = detect_skew_angle(cv_gray)
                if abs(detected_angle) >= 0.5:
                    cv_gray = deskew_image(cv_gray, detected_angle)
            except Exception as e:
                logger.warning(f"Deskewing step skipped due to error: {e}")

        # 5. Contrast Enhancement (CLAHE)
        if enable_clahe and profile != "raw":
            clip = 2.5 if profile == "school_notes" else 1.8
            cv_gray = enhance_contrast_clahe(cv_gray, clip_limit=clip, tile_size=(8, 8))

        # 6. Denoising (Bilateral filtering)
        if enable_denoise and profile != "raw":
            cv_gray = denoise_bilateral(cv_gray, d=7, sigma_color=50, sigma_space=50)

        # 7. Non-destructive Binarization pass (Otsu / Adaptive)
        bin_mode = "adaptive" if profile == "school_notes" else "otsu"
        cv_binary = adaptive_threshold_sauvola_otsu(cv_gray, mode=bin_mode)

        # Non-destructive rule: Return enhanced grayscale as primary for Tesseract LSTM,
        # and provide binarized version for secondary/fallback recognition passes!
        primary_pil = cv_to_pil(cv_gray)
        binary_pil = cv_to_pil(cv_binary)

        return {
            "primary_image": primary_pil,
            "binary_image": binary_pil,
            "skew_angle": round(detected_angle, 2),
            "upscaled": upscaled,
            "scale_factor": round(scale, 2),
            "profile": profile
        }
