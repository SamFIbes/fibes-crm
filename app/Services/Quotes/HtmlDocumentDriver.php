<?php

namespace App\Services\Quotes;

class HtmlDocumentDriver implements DocumentDriver
{
    public function print(string $html): array
    {
        return ['body' => $html, 'mime' => 'text/html; charset=UTF-8'];
    }
}
