<?php

namespace App\Services;

class BytesConversionService
{
    public static function filesize(int|float $bytes, int $dec = 2): string
    {
        $size   = array('B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
        $factor = (int) floor((strlen((string) $bytes) - 1) / 3);
        if ($factor == 0) {
            $dec = 0;
        }

        return sprintf("%.{$dec}f %s", $bytes / (1024 ** $factor), $size[$factor]);
    }
}
