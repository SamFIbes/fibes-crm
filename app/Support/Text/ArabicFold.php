<?php

namespace App\Support\Text;

class ArabicFold
{
    /**
     * @return list<string>
     */
    public static function variants(string $value): array
    {
        $folded = str_replace(['أ', 'إ', 'آ', 'ٱ', 'ى', 'ة'], ['ا', 'ا', 'ا', 'ا', 'ي', 'ه'], $value);
        $folded = preg_replace('/[\x{064B}-\x{0652}]/u', '', $folded) ?? $folded;

        return array_values(array_unique(array_filter([$value, $folded], fn (string $item) => $item !== '')));
    }

    public static function sql(string $column): string
    {
        $expression = $column;

        foreach (['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه'] as $from => $to) {
            $expression = "replace({$expression}, '{$from}', '{$to}')";
        }

        return $expression;
    }
}
