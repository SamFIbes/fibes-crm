<?php

namespace App\Services\Files;

class NullFileScanner implements FileScanner
{
    public const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    public function scan(string $absolutePath): array
    {
        $body = is_file($absolutePath) ? (string) file_get_contents($absolutePath) : '';

        if (str_contains($body, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE')) {
            return ['status' => 'infected', 'detail' => 'EICAR'];
        }

        return ['status' => 'clean', 'detail' => null];
    }
}
