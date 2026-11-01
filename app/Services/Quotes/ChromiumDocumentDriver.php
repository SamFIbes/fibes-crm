<?php

namespace App\Services\Quotes;

class ChromiumDocumentDriver implements DocumentDriver
{
    public function print(string $html): array
    {
        $binary = (string) config('fibes.documents.chromium');

        if ($binary === '' || ! is_executable($binary)) {
            return (new HtmlDocumentDriver)->print($html);
        }

        $dir = sys_get_temp_dir().'/fibes-pdf-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $htmlPath = $dir.'/doc.html';
        $pdfPath = $dir.'/doc.pdf';
        file_put_contents($htmlPath, $html);
        $command = escapeshellarg($binary)
            .' --headless --disable-gpu --no-sandbox --no-pdf-header-footer --print-to-pdf='
            .escapeshellarg($pdfPath).' '.escapeshellarg($htmlPath);
        exec($command, $output, $code);
        $bytes = is_file($pdfPath) ? file_get_contents($pdfPath) : false;
        @unlink($htmlPath);
        @unlink($pdfPath);
        @rmdir($dir);

        if ($code !== 0 || ! is_string($bytes) || ! str_starts_with($bytes, '%PDF')) {
            return (new HtmlDocumentDriver)->print($html);
        }

        return ['body' => $bytes, 'mime' => 'application/pdf'];
    }
}
