<?php

namespace App\Services\Quotes;

class TemplateRenderer
{
    /**
     * @param  array<string, string>  $vars
     */
    public function render(string $body, array $vars): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', function (array $match) use ($vars): string {
            return e($vars[$match[1]] ?? '');
        }, $body);
    }
}
