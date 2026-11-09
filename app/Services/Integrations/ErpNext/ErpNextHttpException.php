<?php

namespace App\Services\Integrations\ErpNext;

use RuntimeException;
use Throwable;

class ErpNextHttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : 'Hub ERP request failed with HTTP '.$status, $code, $previous);
    }

    public static function fromStatus(int $status, string $message = ''): self
    {
        $default = match (true) {
            $status === 401 => 'Hub ERP rejected the API credentials (401).',
            $status === 403 => 'Hub ERP denied permission for this document (403).',
            $status === 429 => 'Hub ERP rate-limited the request (429).',
            $status >= 500 => 'Hub ERP is unavailable ('.$status.').',
            default => 'Hub ERP request failed with HTTP '.$status.'.',
        };

        return new self($status, $message !== '' ? $message : $default);
    }

    public function isRetryable(): bool
    {
        return $this->status === 429 || $this->status >= 500;
    }
}
