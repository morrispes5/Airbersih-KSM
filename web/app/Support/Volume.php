<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class Volume
{
    public static function units(string|int $value): int
    {
        if (! preg_match('/^\d{1,7}(?:\.\d{1,3})?$/D', (string) $value)) {
            throw ValidationException::withMessages(['form.current' => 'Angka meter harus positif/nol, maksimal 7 digit dan 3 desimal.']);
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $value), 2, '');

        return (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');
    }

    public static function decimal(int $units): string
    {
        return intdiv($units, 1000).'.'.str_pad((string) ($units % 1000), 3, '0', STR_PAD_LEFT);
    }

    public static function charge(int $units, int $rate): int
    {
        return intdiv($units * $rate + 500, 1000);
    }
}
