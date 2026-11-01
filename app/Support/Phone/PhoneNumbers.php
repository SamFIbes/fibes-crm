<?php

namespace App\Support\Phone;

use InvalidArgumentException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

class PhoneNumbers
{
    public function normalize(string $input, string $region = 'LB'): string
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            throw new InvalidArgumentException('A phone number is required.');
        }

        $prepared = preg_replace('/[\s().-]+/', '', $trimmed) ?? $trimmed;

        if (str_starts_with($prepared, '00')) {
            $prepared = '+'.substr($prepared, 2);
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            $parsed = $util->parse($prepared, $region);
        } catch (NumberParseException $exception) {
            throw new InvalidArgumentException('That phone number is not valid.', 0, $exception);
        }

        if (! $util->isValidNumber($parsed)) {
            throw new InvalidArgumentException('That phone number is not valid.');
        }

        return $util->format($parsed, PhoneNumberFormat::E164);
    }

    public function display(string $e164): string
    {
        $util = PhoneNumberUtil::getInstance();

        try {
            $parsed = $util->parse($e164, 'LB');
        } catch (NumberParseException) {
            return $e164;
        }

        return $util->format($parsed, PhoneNumberFormat::INTERNATIONAL);
    }

    public function digits(string $e164): string
    {
        return ltrim($this->normalize($e164), '+');
    }
}
