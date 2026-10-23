<?php

return [
    'api_url' => env('AV_URL', 'http://localhost:5001/scan'),
    'enabled' => (bool) env('AV_ENABLED', false),
    // Scanning a 500 MB PDF streams the file to ClamAV and can take a while;
    // keep it bounded but generous enough for large documents.
    'timeout' => (int) env('AV_TIMEOUT', 300),
    'connect_timeout' => (int) env('AV_CONNECT_TIMEOUT', 5),
];
