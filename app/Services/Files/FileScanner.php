<?php

namespace App\Services\Files;

interface FileScanner
{
    /**
     * @return array{status: string, detail: ?string}
     */
    public function scan(string $absolutePath): array;
}
