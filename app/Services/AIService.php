<?php

namespace App\Services;

use Cloudstudio\Ollama\Facades\Ollama;

class AIService {
    public function getAIRecommendations($analysis)
    {
        $prompt = $this->buildAnalysisPrompt($analysis);

        $response = Ollama::model('llama3.2-vision:11b')
            ->prompt($prompt)
            ->images($analysis['sample_images']) // Base64 encoded
            ->stream(false)
            ->format('json')
            ->ask();

        return json_decode($response['response']);
    }

    private function buildAnalysisPrompt($analysis)
    {
        return "
                Analyze this PDF and recommend optimal Ghostscript compression parameters.

                PDF Info:
                - Pages: {$analysis['page_count']}
                - Size: {$analysis['file_size']} bytes
                - Has Images: " . ($analysis['has_images'] ? 'Yes' : 'No') . "
                - Text Density: {$analysis['text_density']}

                Based on the sample pages shown, recommend:
                1. Compression level (/screen, /ebook, /printer, /prepress)
                2. Image downsampling settings
                3. Color/grayscale conversion
                4. Font optimization

                Respond in JSON format:
                {
                    \"compression_level\": \"ebook\",
                    \"image_downsample\": true,
                    \"color_convert\": \"gray\",
                    \"font_optimization\": true,
                    \"custom_params\": [\"-dPDFSETTINGS=/ebook\", \"-dCompressFonts=true\"],
                }
                ";
    }
}
