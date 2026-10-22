"""
OCR Accuracy & Performance Evaluation Benchmark
Computes Character Error Rate (CER), Word Error Rate (WER), and latency (ms)
comparing baseline OCR against the optimized OpenCV + Tesseract pipeline.
"""

import os
import sys
import time
import io
from typing import Dict, Any, List, Tuple
from PIL import Image

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from preprocessor import ImagePreprocessor
import pytesseract

pytesseract.pytesseract.tesseract_cmd = r"D:\tesseract\tesseract.exe"


def levenshtein_distance(s1: str, s2: str) -> int:
    """Compute standard Levenshtein edit distance between two strings or token lists."""
    if len(s1) < len(s2):
        return levenshtein_distance(s2, s1)
    if len(s2) == 0:
        return len(s1)

    previous_row = range(len(s2) + 1)
    for i, c1 in enumerate(s1):
        current_row = [i + 1]
        for j, c2 in enumerate(s2):
            insertions = previous_row[j + 1] + 1
            deletions = current_row[j] + 1
            substitutions = previous_row[j] + (c1 != c2)
            current_row.append(min(insertions, deletions, substitutions))
        previous_row = current_row

    return previous_row[-1]


def compute_metrics(ground_truth: str, recognized_text: str) -> Dict[str, float]:
    """Calculate Character Error Rate (CER) and Word Error Rate (WER)."""
    gt_clean = " ".join(ground_truth.split())
    rec_clean = " ".join(recognized_text.split())

    # CER
    char_dist = levenshtein_distance(gt_clean, rec_clean)
    cer = (char_dist / max(1, len(gt_clean)))
    char_acc = max(0.0, (1.0 - cer) * 100.0)

    # WER
    gt_words = gt_clean.split()
    rec_words = rec_clean.split()
    word_dist = levenshtein_distance(gt_words, rec_words)
    wer = (word_dist / max(1, len(gt_words)))
    word_acc = max(0.0, (1.0 - wer) * 100.0)

    return {
        "cer": round(cer, 4),
        "wer": round(wer, 4),
        "char_accuracy_pct": round(char_acc, 2),
        "word_accuracy_pct": round(word_acc, 2),
        "gt_chars": len(gt_clean),
        "rec_chars": len(rec_clean)
    }


def evaluate_pipeline(
    image: Image.Image,
    ground_truth: str,
    label: str = "Test Sample"
) -> Dict[str, Any]:
    """
    Run comparative benchmark on an image:
    1. Baseline: Raw image, default Tesseract (--oem 3 --psm 3)
    2. Optimized: OpenCV preprocessing + Tesseract (--oem 1 --psm 3/6)
    """
    # 1. Baseline OCR
    t0 = time.time()
    baseline_text = pytesseract.image_to_string(image, config="--oem 3 --psm 3")
    t_baseline_ms = round((time.time() - t0) * 1000, 1)
    base_metrics = compute_metrics(ground_truth, baseline_text)

    # 2. Optimized OCR
    t0 = time.time()
    prep = ImagePreprocessor.process(image, profile="roster")
    opt_text = pytesseract.image_to_string(prep["primary_image"], config="--oem 1 --psm 3")
    if len(opt_text.strip()) < 25:
        opt_text = pytesseract.image_to_string(prep["primary_image"], config="--oem 1 --psm 6")
    t_opt_ms = round((time.time() - t0) * 1000, 1)
    opt_metrics = compute_metrics(ground_truth, opt_text)

    return {
        "label": label,
        "baseline": {
            "time_ms": t_baseline_ms,
            "metrics": base_metrics,
            "text_sample": baseline_text[:120].replace("\n", " ")
        },
        "optimized": {
            "time_ms": t_opt_ms,
            "metrics": opt_metrics,
            "skew_detected": prep["skew_angle"],
            "upscaled": prep["upscaled"],
            "text_sample": opt_text[:120].replace("\n", " ")
        }
    }


def run_benchmark():
    print("=" * 80)
    print(" AMS OCR BENCHMARK EVALUATION (BASELINE vs OPTIMIZED OPENCV PIPELINE)")
    print("=" * 80)

    # Ground truth for sample student roster
    roster_gt = """COLLEGE OF COMPUTER STUDIES - OFFICIAL CLASS ROSTER
Subject: CS101 - Intro to Programming | Section: BSCS 2-A | Semester: 1st Sem 2025-2026
STUDENT NO. STUDENT FULL NAME YEAR & SECTION STATUS
2023-01041 AGUILA, CARLO M. BSCS 2-A Regular
2023-01042 BAUTISTA, SARAH MAE T. BSCS 2-A Regular
2023-01043 CORPUZ, MIGUEL LUIS D. BSCS 2-A Regular
2023-01044 DIMACULANGAN, JOSHUA K. BSCS 2-A Regular
2023-01045 ENRIQUEZ, BEA BIANCA C. BSCS 2-A Regular
2023-01046 FLORES, CHRISTIAN JAY S. BSCS 2-A Regular
2023-01047 GONZALES, PAULA JOYCE R. BSCS 2-A Regular
2023-01048 HERRERA, MARC ANGELO V. BSCS 2-A Regular
2023-01049 ILAGAN, SOPHIA NICOLE E. BSCS 2-A Regular
2023-01050 JAVIER, DANIEL RAY M. BSCS 2-A Regular"""

    # Render PDF to image using pypdfium2
    import pypdfium2 as pdfium
    pdf = pdfium.PdfDocument("sample_student_roster.pdf")
    clean_img = pdf[0].render(scale=200/72).to_pil()

    # 1. Clean Scanned Roster
    res_clean = evaluate_pipeline(clean_img, roster_gt, label="Clean Scanned Roster")

    # 2. Skewed + Low Contrast (Simulating phone photo / photocopy of student roster)
    import cv2, numpy as np
    cv_img = cv2.cvtColor(np.array(clean_img), cv2.COLOR_RGB2BGR)
    h, w = cv_img.shape[:2]
    # Rotate by 3.5 degrees
    M = cv2.getRotationMatrix2D((w / 2, h / 2), 3.5, 1.0)
    cv_skewed = cv2.warpAffine(cv_img, M, (w, h), borderMode=cv2.BORDER_REPLICATE)
    # Add non-uniform lighting shadow across page (left side 75%, right side 100%)
    x = np.linspace(0.75, 1.0, w)
    shadow_mask = np.tile(x, (h, 1))
    cv_shadowed = (cv_skewed.astype(np.float32) * shadow_mask[:, :, np.newaxis]).astype(np.uint8)
    simulated_photo = Image.fromarray(cv2.cvtColor(cv_shadowed, cv2.COLOR_BGR2RGB))

    res_simulated = evaluate_pipeline(simulated_photo, roster_gt, label="Tilted Phone Scan with Lighting Gradient")

    # 3. Faint low-res diagram / school notes image
    er_img = Image.open("817417167_1095568116204497_3938283814883707059_n.png")
    er_gt = "INSTRUCTOR STUDENT TAKES A CLASS BELONG TO WHICH AND HAVE MANY SUBJECT CLASS HAS MANY SESSION MEETINGS A CLASS USES THE GRADING SYSTEM Quizzes, Exam, Activities, Attendance GRADING COMPONENT PRODUCES THE GRADES VIEW MANY ATTENDANCE RECEIVES THE FINAL GRADE"
    res_er = evaluate_pipeline(er_img, er_gt, label="School Notes / Diagram (Resolution & Contrast Test)")

    # Display results
    for test in [res_clean, res_simulated, res_er]:
        print(f"\n--- Scenario: {test['label']} ---")
        base = test["baseline"]
        opt = test["optimized"]

        print(f"  [BASELINE OCR]")
        print(f"    - Processing Time: {base['time_ms']} ms")
        print(f"    - Character Accuracy: {base['metrics']['char_accuracy_pct']}% (CER: {base['metrics']['cer']})")
        print(f"    - Word Accuracy:      {base['metrics']['word_accuracy_pct']}% (WER: {base['metrics']['wer']})")
        print(f"    - Sample Output:      {base['text_sample']}...")

        print(f"  [OPTIMIZED OPENCV PIPELINE]")
        print(f"    - Processing Time: {opt['time_ms']} ms")
        print(f"    - Character Accuracy: {opt['metrics']['char_accuracy_pct']}% (CER: {opt['metrics']['cer']})")
        print(f"    - Word Accuracy:      {opt['metrics']['word_accuracy_pct']}% (WER: {opt['metrics']['wer']})")
        print(f"    - Skew Correction:    {opt['skew_detected']}°")
        print(f"    - Sample Output:      {opt['text_sample']}...")

        acc_gain = round(opt['metrics']['word_accuracy_pct'] - base['metrics']['word_accuracy_pct'], 2)
        print(f"  => Word Accuracy Improvement: {acc_gain:+}%")


if __name__ == "__main__":
    run_benchmark()
