<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\DB;

class InvoiceNumbers
{
    public function next(string $prefix, int $year): int
    {
        return (int) DB::transaction(function () use ($prefix, $year) {
            $row = DB::table('invoice_sequences')->where('prefix', $prefix)->where('year', $year)->lockForUpdate()->first();

            if ($row === null) {
                DB::table('invoice_sequences')->insert([
                    'prefix' => $prefix,
                    'year' => $year,
                    'last_number' => 1,
                ]);

                return 1;
            }

            $next = ((int) $row->last_number) + 1;
            DB::table('invoice_sequences')->where('id', $row->id)->update(['last_number' => $next]);

            return $next;
        });
    }

    public function format(string $prefix, int $year, int $number): string
    {
        return $prefix.'-'.$year.'-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
