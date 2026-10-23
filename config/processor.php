<?php

return [
    'gs_binary' => env('GS_BINARY', 'gs'),
    'gs_timeout' => (int) env('GS_TIMEOUT', 900),

    // Distiller preset: /screen (72 dpi, smallest), /ebook (150 dpi),
    // /printer or /prepress (300 dpi, print quality).
    'pdf_settings' => env('GS_PDF_SETTINGS', '/ebook'),

    // Overrides the preset's image resolution in dpi (0 = keep the preset).
    // 120 dpi is a good size/quality trade-off for scanned documents.
    'image_resolution' => (int) env('GS_IMAGE_RESOLUTION', 120),

    // Re-encode embedded JPEG/JPEG2000 images instead of passing them through
    // untouched. This is where most of the size win comes from on scanned PDFs.
    'recompress_images' => (bool) env('GS_RECOMPRESS_IMAGES', true),
];
