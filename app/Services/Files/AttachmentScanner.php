<?php

namespace App\Services\Files;

class AttachmentScanner
{
    public function __construct(private FileScanner $scanner) {}

    /**
     * @return array{status: string, detail: ?string}
     */
    public function scan(string $absolutePath): array
    {
        return $this->scanner->scan($absolutePath);
    }
}
