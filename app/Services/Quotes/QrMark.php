<?php

namespace App\Services\Quotes;

class QrMark
{
    /** @var array<int, array{0: int, 1: int, 2: list<int>}> */
    private const VERSIONS = [
        1 => [19, 7, []],
        2 => [34, 10, [6, 18]],
        3 => [55, 15, [6, 22]],
        4 => [80, 20, [6, 26]],
        5 => [108, 26, [6, 30]],
    ];

    public function svg(string $text): string
    {
        $matrix = $this->matrix($text);
        $size = count($matrix);
        $box = $size + 4;
        $rects = '';

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix[$y][$x]) {
                    $rects .= '<rect x="'.($x + 2).'" y="'.($y + 2).'" width="1" height="1"/>';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$box.' '.$box.'" shape-rendering="crispEdges" data-qr="1" role="img"><rect width="100%" height="100%" fill="#fff"/>'.$rects.'</svg>';
    }

    /**
     * @return list<list<bool>>
     */
    public function matrix(string $text): array
    {
        $text = substr($text, 0, 100);
        $version = 1;

        foreach (self::VERSIONS as $candidate => [$data]) {
            if (strlen($text) <= $data - 2) {
                $version = $candidate;

                break;
            }

            $version = $candidate;
        }

        [$dataBytes, $eccLen, $align] = self::VERSIONS[$version];
        $size = 17 + (4 * $version);
        $modules = array_fill(0, $size, array_fill(0, $size, false));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));
        $this->functions($modules, $reserved, $size, $align);
        $payload = $this->bytes($text, $dataBytes);
        $codewords = array_merge($payload, $this->ecc($payload, $eccLen));
        $this->place($modules, $reserved, $this->bits($codewords), $size);
        $this->mask($modules, $reserved, $size);
        $this->format($modules, $size, $this->formatBits(1, 0));

        return $modules;
    }

    /**
     * @param  list<list<bool>>  $modules
     * @param  list<list<bool>>  $reserved
     * @param  list<int>  $align
     */
    private function functions(array &$modules, array &$reserved, int $size, array $align): void
    {
        $this->finder($modules, $reserved, 0, 0);
        $this->finder($modules, $reserved, $size - 7, 0);
        $this->finder($modules, $reserved, 0, $size - 7);

        for ($i = 0; $i < 8; $i++) {
            $this->reserve($modules, $reserved, 7, $i, false);
            $this->reserve($modules, $reserved, $i, 7, false);
            $this->reserve($modules, $reserved, $size - 8, $i, false);
            $this->reserve($modules, $reserved, $size - 8 + $i, 7, false);
            $this->reserve($modules, $reserved, 7, $size - 1 - $i, false);
            $this->reserve($modules, $reserved, $i, $size - 8, false);
        }

        for ($i = 0; $i < $size; $i++) {
            $this->reserve($modules, $reserved, 6, $i, $i % 2 === 0);
            $this->reserve($modules, $reserved, $i, 6, $i % 2 === 0);
        }

        foreach ($align as $row) {
            foreach ($align as $col) {
                if (($row < 9 && $col < 9) || ($row < 9 && $col > $size - 10) || ($row > $size - 10 && $col < 9)) {
                    continue;
                }

                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $dark = max(abs($dx), abs($dy)) !== 1;
                        $this->reserve($modules, $reserved, $row + $dy, $col + $dx, $dark);
                    }
                }
            }
        }

        $this->reserve($modules, $reserved, 4 * (($size - 17) / 4) + 9, 8, true);

        for ($i = 0; $i < 9; $i++) {
            $this->reserve($modules, $reserved, 8, $i, false);
            $this->reserve($modules, $reserved, $i, 8, false);
        }

        for ($i = 0; $i < 8; $i++) {
            $this->reserve($modules, $reserved, 8, $size - 1 - $i, false);
            $this->reserve($modules, $reserved, $size - 1 - $i, 8, false);
        }
    }

    /**
     * @param  list<list<bool>>  $modules
     * @param  list<list<bool>>  $reserved
     */
    private function finder(array &$modules, array &$reserved, int $x, int $y): void
    {
        for ($dy = 0; $dy < 7; $dy++) {
            for ($dx = 0; $dx < 7; $dx++) {
                $dark = $dx === 0 || $dy === 0 || $dx === 6 || $dy === 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4);
                $this->reserve($modules, $reserved, $y + $dy, $x + $dx, $dark);
            }
        }
    }

    /**
     * @param  list<list<bool>>  $modules
     * @param  list<list<bool>>  $reserved
     */
    private function reserve(array &$modules, array &$reserved, int $row, int $col, bool $dark): void
    {
        if ($row < 0 || $col < 0 || $row >= count($modules) || $col >= count($modules)) {
            return;
        }

        $modules[$row][$col] = $dark;
        $reserved[$row][$col] = true;
    }

    /**
     * @return list<int>
     */
    private function bytes(string $text, int $dataBytes): array
    {
        $bits = '0100'.str_pad(decbin(strlen($text)), 8, '0', STR_PAD_LEFT);

        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
        }

        $capacity = $dataBytes * 8;
        $bits .= str_repeat('0', min(4, max(0, $capacity - strlen($bits))));

        while (strlen($bits) % 8 !== 0) {
            $bits .= '0';
        }

        $pad = ['11101100', '00010001'];
        $toggle = 0;

        while (strlen($bits) < $capacity) {
            $bits .= $pad[$toggle % 2];
            $toggle++;
        }

        $bytes = [];

        for ($i = 0; $i < $dataBytes; $i++) {
            $bytes[] = bindec(substr($bits, $i * 8, 8));
        }

        return $bytes;
    }

    /**
     * @param  list<int>  $data
     * @return list<int>
     */
    private function ecc(array $data, int $eccLen): array
    {
        [$exp, $log] = $this->tables();
        $gen = [1];

        for ($i = 0; $i < $eccLen; $i++) {
            $gen = $this->polyMul($gen, [1, $exp[$i]], $exp, $log);
        }

        $msg = array_merge($data, array_fill(0, $eccLen, 0));

        foreach ($data as $i => $coef) {
            if ($coef === 0) {
                continue;
            }

            foreach ($gen as $j => $term) {
                $msg[$i + $j] ^= $this->mul($term, $coef, $exp, $log);
            }
        }

        return array_slice($msg, count($data));
    }

    /**
     * @param  list<int>  $codewords
     */
    private function bits(array $codewords): string
    {
        $bits = '';

        foreach ($codewords as $word) {
            $bits .= str_pad(decbin($word), 8, '0', STR_PAD_LEFT);
        }

        return $bits;
    }

    /**
     * @param  list<list<bool>>  $modules
     * @param  list<list<bool>>  $reserved
     */
    private function place(array &$modules, array $reserved, string $bits, int $size): void
    {
        $up = true;
        $bit = 0;
        $length = strlen($bits);

        for ($x = $size - 1; $x > 0; $x -= 2) {
            if ($x === 6) {
                $x--;
            }

            for ($i = 0; $i < $size; $i++) {
                $y = $up ? $size - 1 - $i : $i;

                for ($dx = 0; $dx < 2; $dx++) {
                    $xx = $x - $dx;

                    if ($reserved[$y][$xx]) {
                        continue;
                    }

                    $modules[$y][$xx] = $bit < $length && $bits[$bit] === '1';
                    $bit++;
                }
            }

            $up = ! $up;
        }
    }

    /**
     * @param  list<list<bool>>  $modules
     * @param  list<list<bool>>  $reserved
     */
    private function mask(array &$modules, array $reserved, int $size): void
    {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if (! $reserved[$y][$x] && ($x + $y) % 2 === 0) {
                    $modules[$y][$x] = ! $modules[$y][$x];
                }
            }
        }
    }

    /**
     * @param  list<list<bool>>  $modules
     */
    private function format(array &$modules, int $size, int $data): void
    {
        for ($i = 0; $i < 15; $i++) {
            $on = (($data >> $i) & 1) === 1;

            if ($i < 6) {
                $modules[$i][8] = $on;
            } elseif ($i < 8) {
                $modules[$i + 1][8] = $on;
            } else {
                $modules[$size - 15 + $i][8] = $on;
            }

            if ($i < 8) {
                $modules[8][$size - $i - 1] = $on;
            } elseif ($i < 9) {
                $modules[8][15 - $i] = $on;
            } else {
                $modules[8][15 - $i - 1] = $on;
            }
        }

        $modules[$size - 8][8] = true;
    }

    private function formatBits(int $level, int $mask): int
    {
        $data = ($level << 3) | $mask;
        $value = $data << 10;
        $poly = 0b10100110111;

        for ($i = 14; $i >= 10; $i--) {
            if (($value >> $i) & 1) {
                $value ^= $poly << ($i - 10);
            }
        }

        return (($data << 10) | ($value & 0b1111111111)) ^ 0b101010000010010;
    }

    /**
     * @return array{0: list<int>, 1: list<int>}
     */
    private function tables(): array
    {
        static $exp = [];
        static $log = [];

        if ($exp !== []) {
            return [$exp, $log];
        }

        $log = array_fill(0, 256, 0);
        $x = 1;

        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;

            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }

        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }

        return [$exp, $log];
    }

    /**
     * @param  list<int>  $a
     * @param  list<int>  $b
     * @param  list<int>  $exp
     * @param  list<int>  $log
     * @return list<int>
     */
    private function polyMul(array $a, array $b, array $exp, array $log): array
    {
        $out = array_fill(0, count($a) + count($b) - 1, 0);

        foreach ($a as $i => $left) {
            foreach ($b as $j => $right) {
                $out[$i + $j] ^= $this->mul($left, $right, $exp, $log);
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $exp
     * @param  list<int>  $log
     */
    private function mul(int $left, int $right, array $exp, array $log): int
    {
        if ($left === 0 || $right === 0) {
            return 0;
        }

        return $exp[$log[$left] + $log[$right]];
    }
}
