<?php

return [
    'gs_binary' => env('GS_BINARY', 'gs'),
    'gs_timeout' => (int) env('GS_TIMEOUT', 900),
    'pdf_settings' => env('GS_PDF_SETTINGS', '/ebook'),
];
