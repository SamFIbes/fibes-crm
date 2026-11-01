<?php

namespace App\Services\Files;

class ClamAvScanner implements FileScanner
{
    public function scan(string $absolutePath): array
    {
        $host = (string) config('fibes.attachments.clamav_host', '127.0.0.1');
        $port = (int) config('fibes.attachments.clamav_port', 3310);
        $socket = @fsockopen($host, $port, $error, $message, 3);

        if ($socket === false) {
            return ['status' => 'error', 'detail' => 'scanner-unavailable'];
        }

        stream_set_timeout($socket, 5);
        fwrite($socket, "zINSTREAM\0");
        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            fclose($socket);

            return ['status' => 'error', 'detail' => 'unreadable'];
        }

        while (! feof($handle)) {
            $chunk = fread($handle, 8192);

            if ($chunk === false || $chunk === '') {
                break;
            }

            fwrite($socket, pack('N', strlen($chunk)).$chunk);
        }

        fclose($handle);
        fwrite($socket, pack('N', 0));
        $reply = '';

        while (! feof($socket)) {
            $line = fgets($socket);

            if ($line === false) {
                break;
            }

            $reply .= $line;
        }

        fclose($socket);
        $reply = trim($reply);

        if (str_contains($reply, 'FOUND')) {
            return ['status' => 'infected', 'detail' => 'FOUND'];
        }

        if (str_contains($reply, 'OK')) {
            return ['status' => 'clean', 'detail' => null];
        }

        return ['status' => 'error', 'detail' => 'scanner-error'];
    }
}
