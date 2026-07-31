<?php

namespace App\Services;

use thiagoalessio\TesseractOCR\TesseractOCR;
use Illuminate\Support\Facades\Storage;

class OcrService
{
    private const MIN_TEXT_LENGTH_FOR_SELECTABLE = 100;
    private const TEMP_IMAGE_DIR = 'storage/ocr_temp';

    /**
     * Extracts text from PDF, auto-detecting between selectable text and OCR.
     * Returns array with 'text' and 'is_ocr' flag.
     */
    public function extractFromPdf(string $storagePath): array
    {
        $absolutePath = Storage::path($storagePath);

        // First, try extracting selectable text
        $selectableText = $this->extractSelectableText($absolutePath);

        // If substantial text found, use it
        if (strlen($selectableText) >= self::MIN_TEXT_LENGTH_FOR_SELECTABLE) {
            return [
                'text' => $selectableText,
                'is_ocr' => false,
            ];
        }

        // Otherwise, use OCR
        $ocrText = $this->extractViaOcr($absolutePath);

        return [
            'text' => $ocrText,
            'is_ocr' => true,
        ];
    }

    /**
     * Extracts selectable text from PDF using PDFParser.
     */
    private function extractSelectableText(string $pdfPath): string
    {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($pdfPath);
            $text = '';

            foreach ($pdf->getPages() as $page) {
                $text .= (string) $page->getText() . "\n";
            }

            return trim($text);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Extracts text from PDF via OCR by converting pages to images.
     */
    private function extractViaOcr(string $pdfPath): string
    {
        $tempDir = sys_get_temp_dir() . '/laravel_ocr_' . uniqid();
        @mkdir($tempDir, 0777, true);

        try {
            // Convert PDF pages to images using ImageMagick
            $imagePaths = $this->convertPdfToImages($pdfPath, $tempDir);

            $allText = '';

            // Run OCR on each image
            foreach ($imagePaths as $imagePath) {
                try {
                    $ocr = new TesseractOCR($imagePath);
                    $text = $ocr->run();
                    $allText .= $text . "\n";
                } catch (\Throwable $e) {
                    // Skip failed OCR on individual images
                    continue;
                }
            }

            return trim($allText);
        } finally {
            // Clean up temp directory
            $this->cleanupDirectory($tempDir);
        }
    }

    /**
     * Converts PDF pages to PNG images using ImageMagick.
     * Returns array of image file paths.
     */
    private function convertPdfToImages(string $pdfPath, string $outputDir): array
    {
        $outputPattern = $outputDir . '/page-%d.png';

        // Use ImageMagick 'convert' command to convert PDF to images
        // -density 150 for better OCR quality
        $command = sprintf(
            'convert -density 150 -background white %s %s',
            escapeshellarg($pdfPath),
            escapeshellarg($outputPattern)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException('Failed to convert PDF to images');
        }

        // Collect generated image files
        $imagePaths = glob($outputDir . '/page-*.png');
        sort($imagePaths, SORT_NATURAL);

        if (empty($imagePaths)) {
            throw new \RuntimeException('No images generated from PDF conversion');
        }

        return $imagePaths;
    }

    /**
     * Recursively clean up a directory.
     */
    private function cleanupDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') as $file) {
            if (is_dir($file)) {
                $this->cleanupDirectory($file);
            } else {
                @unlink($file);
            }
        }

        @rmdir($dir);
    }
}
