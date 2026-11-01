<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects control characters, including CR/LF, before accepting an email.
 * Laravel 11's default email rule is affected by an unpatched CRLF advisory,
 * so authentication flows use this rule instead.
 */
class SafeEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            $fail(__('validation.email'));

            return;
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $fail(__('validation.email'));
        }
    }
}
