<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;
class PdfAnalyzerService
{
    public function analyzePdf($pdfPath)
    {
        $info = [
            'page_count' => $this->getPageCount($pdfPath),
            'file_size' => filesize($pdfPath),
            'has_images' => $this->detectImages($pdfPath),
            'text_density' => $this->getTextDensity($pdfPath),
            'color_info' => $this->getColorInfo($pdfPath),
            'sample_images' => $this->pdfToImages($pdfPath),
        ];

        return $info;
    }

    private function getPageCount($pdfPath)
    {
        try {
            // Try system tool first
            $output = shell_exec("pdfinfo '$pdfPath' 2>/dev/null | grep Pages");
            if ($output && preg_match('/Pages:\s+(\d+)/', $output, $matches)) {
                return (int)$matches[1];
            }
        } catch (\Exception $e) {
            Log::error('Failed to get page count: ' . $e->getMessage());
        }

        // Fallback to PHP library
        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($pdfPath);
            return count($pdf->getPages());
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function detectImages($pdfPath)
    {
        try {
            // Try pdfimages tool
            $output = shell_exec("pdfimages -list '$pdfPath' 2>/dev/null");
            if ($output && trim($output) !== '') {
                // Check if there are actual images (not just the header)
                $lines = explode("\n", trim($output));
                return count($lines) > 1; // More than just header line
            }
        } catch (Exception $e) {
            // Fallback method
        }

        // Fallback: check PDF content for image markers
        try {
            $content = file_get_contents($pdfPath);
            return strpos($content, '/Image') !== false ||
                strpos($content, '/DCTDecode') !== false ||
                strpos($content, '/FlateDecode') !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    private function getTextDensity($pdfPath)
    {
        try {
            // Try pdftotext
            $text = shell_exec("pdftotext '$pdfPath' - 2>/dev/null");
            if ($text) {
                $pageCount = $this->getPageCount($pdfPath);
                return $pageCount > 0 ? strlen(trim($text)) / $pageCount : 0;
            }
        } catch (Exception $e) {
            // Fallback method
        }

        // Fallback to PHP parser
        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($pdfPath);
            $text = $pdf->getText();
            $pageCount = count($pdf->getPages());
            return $pageCount > 0 ? strlen(trim($text)) / $pageCount : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getColorInfo($pdfPath)
    {
        try {
            // Try pdfinfo
            $output = shell_exec("pdfinfo '$pdfPath' 2>/dev/null");
            if ($output) {
                $colorInfo = [
                    'has_color' => $this->hasColor($output),
                    'colorspace' => $this->extractColorSpace($output),
                ];
                return $colorInfo;
            }
        } catch (Exception $e) {
            // Fallback method
        }

        // Conservative fallback
        return [
            'has_color' => true,
            'colorspace' => 'Unknown'
        ];
    }

    private function hasColor($output)
    {
        return strpos($output, 'RGB') !== false ||
            strpos($output, 'CMYK') !== false ||
            strpos($output, 'Color') !== false;
    }

    private function extractColorSpace($output)
    {
        // Look for explicit colorspace mention
        if (preg_match('/Colorspace:\s*(\w+)/', $output, $matches)) {
            return $matches[1];
        }

        // Parse from general output
        if (strpos($output, 'CMYK') !== false) {
            return 'CMYK';
        }
        if (strpos($output, 'RGB') !== false) {
            return 'RGB';
        }
        if (strpos($output, 'Gray') !== false) {
            return 'Grayscale';
        }

        return 'RGB'; // Default assumption
    }

    private function pdfToImages($pdfPath, $maxPages = 3)
    {
        $images = [];

        try {
            for ($page = 1; $page <= $maxPages; $page++) {
                $tempImagePath = storage_path("app/temp_page_{$page}_" . uniqid() . ".png");

                // Use Ghostscript to convert PDF page to image
                $command = [
                    'gs',
                    '-dNOPAUSE',
                    '-dBATCH',
                    '-dSAFER',
                    '-sDEVICE=png16m',
                    '-r150', // 150 DPI
                    "-dFirstPage={$page}",
                    "-dLastPage={$page}",
                    "-sOutputFile={$tempImagePath}",
                    $pdfPath
                ];

                exec(implode(' ', $command) . ' 2>/dev/null', $output, $returnCode);

                if ($returnCode === 0 && file_exists($tempImagePath)) {
                    $imageData = file_get_contents($tempImagePath);
                    $base64 = base64_encode($imageData);
                    $images[] = $base64;

                    unlink($tempImagePath);
                } else {
                    break;
                }
            }
        } catch (Exception $e) {
            \Log::error("PDF to image conversion failed: " . $e->getMessage());
        }

        return $images;
    }
}
