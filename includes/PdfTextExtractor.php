<?php
/**
 * Pure PHP PDF Text Extractor
 * 
 * Extracts selectable text and metadata from digital PDF files without
 * requiring poppler, pdftotext, exec(), or external binaries.
 * Compatible with standard PHP 8.0+ shared hosting (including InfinityFree).
 */

class PdfTextExtractor {

    /**
     * Inspect and extract selectable text from a PDF file.
     *
     * @param string $filePath Absolute path to PDF file.
     * @return array{
     *   success: bool,
     *   page_count: int,
     *   text: string,
     *   has_selectable_text: bool,
     *   error?: string
     * }
     */
    public static function extract(string $filePath): array {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return [
                'success' => false,
                'page_count' => 0,
                'text' => '',
                'has_selectable_text' => false,
                'error' => 'File is not readable or does not exist.'
            ];
        }

        $content = @file_get_contents($filePath);
        if ($content === false || strlen($content) === 0) {
            return [
                'success' => false,
                'page_count' => 0,
                'text' => '',
                'has_selectable_text' => false,
                'error' => 'Could not read PDF file content.'
            ];
        }

        // 1. Detect page count
        $pageCount = self::detectPageCount($content);

        // 2. Extract streams and parse text tokens
        $rawText = self::extractTextFromStreams($content);
        $cleanText = self::cleanExtractedText($rawText);

        $hasSelectableText = (mb_strlen(trim($cleanText)) >= 25);

        return [
            'success' => true,
            'page_count' => max(1, $pageCount),
            'text' => $cleanText,
            'has_selectable_text' => $hasSelectableText
        ];
    }

    /**
     * Determine total pages in the PDF document.
     */
    public static function detectPageCount(string $content): int {
        // Method A: Look for /Pages dictionary count
        if (preg_match('/\/Type\s*\/Pages[^>]*\/Count\s+(\d+)/i', $content, $m)) {
            return (int)$m[1];
        }
        if (preg_match('/\/Count\s+(\d+)[^>]*\/Type\s*\/Pages/i', $content, $m)) {
            return (int)$m[1];
        }

        // Method B: Count /Page objects (excluding /Pages)
        $count = preg_match_all('/\/Type\s*\/Page\b/i', $content, $matches);
        if ($count > 0) {
            return $count;
        }

        return 1;
    }

    /**
     * Find and decompress streams, then parse PDF text operators (BT...ET).
     */
    private static function extractTextFromStreams(string $content): string {
        $extractedText = '';

        // Find all streams: stream ... endstream
        $pattern = '/stream[\r\n]+(.*?)[\r\n]+endstream/s';
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[1] as $stream) {
                // Try decompressing if FlateDecode
                $data = self::decompressStream($stream);
                if ($data === false) {
                    $data = $stream; // Might be uncompressed
                }

                // Extract text from text blocks (BT ... ET)
                if (strpos($data, 'BT') !== false) {
                    $streamText = self::parseTextBlocks($data);
                    if ($streamText !== '') {
                        $extractedText .= $streamText . "\n";
                    }
                }
            }
        }

        return $extractedText;
    }

    /**
     * Attempt decompression using gzuncompress or gzinflate.
     */
    private static function decompressStream(string $stream): string|false {
        $decompressed = @gzuncompress($stream);
        if ($decompressed !== false) {
            return $decompressed;
        }

        $decompressed = @gzinflate($stream);
        if ($decompressed !== false) {
            return $decompressed;
        }

        // Some streams have zlib header trimmed or 2-byte prefix
        if (strlen($stream) > 2) {
            $decompressed = @gzinflate(substr($stream, 2));
            if ($decompressed !== false) {
                return $decompressed;
            }
        }

        return false;
    }

    /**
     * Parse text operators within BT (Begin Text) ... ET (End Text) blocks.
     */
    private static function parseTextBlocks(string $stream): string {
        $blockText = '';
        if (preg_match_all('/BT[\s\r\n]+(.*?)[\s\r\n]+ET/s', $stream, $blocks)) {
            foreach ($blocks[1] as $block) {
                // Handle Tj (single string) and TJ (array of strings & kerning)
                // 1. Look for array of strings: [ (Text) -120 (More text) ] TJ
                if (preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $tjMatches)) {
                    foreach ($tjMatches[1] as $arrContent) {
                        if (preg_match_all('/\((.*?)\)/s', $arrContent, $strMatches)) {
                            $line = implode('', array_map([self::class, 'unescapePdfString'], $strMatches[1]));
                            $blockText .= $line . ' ';
                        }
                    }
                    $blockText .= "\n";
                }

                // 2. Look for single strings: (Text) Tj or ' or "
                if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)\s*(?:Tj|\'|")/s', $block, $singleMatches)) {
                    foreach ($singleMatches[1] as $str) {
                        $blockText .= self::unescapePdfString($str) . "\n";
                    }
                }

                // 3. Look for hex encoded strings: <00410042> Tj
                if (preg_match_all('/<([0-9a-fA-F]+)>\s*(?:Tj|TJ)/s', $block, $hexMatches)) {
                    foreach ($hexMatches[1] as $hex) {
                        $decoded = @hex2bin($hex);
                        if ($decoded !== false) {
                            // Filter readable ascii/utf8
                            $clean = preg_replace('/[^\x20-\x7E\t\r\n]/', '', $decoded);
                            if (strlen($clean) > 0) {
                                $blockText .= $clean . "\n";
                            }
                        }
                    }
                }
            }
        }

        return $blockText;
    }

    /**
     * Unescape standard PDF string escapes.
     */
    private static function unescapePdfString(string $str): string {
        $search  = ['\\\\', '\\(', '\\)', '\\n', '\\r', '\\t', '\\b', '\\f'];
        $replace = ['\\',   '(',   ')',   "\n",  "\r",  "\t",  "\x08", "\x0C"];
        $str = str_replace($search, $replace, $str);

        // Octal escape sequences: \040, \101
        $str = preg_replace_callback('/\\\\([0-7]{1,3})/', function ($m) {
            return chr(octdec($m[1]));
        }, $str);

        return $str;
    }

    /**
     * Clean and normalize extracted text layout.
     */
    private static function cleanExtractedText(string $text): string {
        // Normalize line breaks
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Collapse excessive blank lines
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        // Trim each line
        $lines = explode("\n", $text);
        $cleanLines = [];
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t !== '') {
                $cleanLines[] = $t;
            }
        }
        return implode("\n", $cleanLines);
    }
}
