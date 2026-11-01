<?php

namespace App\Services\Quotes;

interface DocumentDriver
{
    /**
     * @return array{body: string, mime: string}
     */
    public function print(string $html): array;
}
