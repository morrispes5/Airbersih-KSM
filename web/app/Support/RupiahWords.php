<?php

namespace App\Support;

final class RupiahWords
{
    public static function spell(int $amount): string
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('Nominal terbilang tidak boleh negatif.');
        }
        if ($amount === 0) {
            return 'Nol Rupiah';
        }
        $suffixes = ['', 'ribu', 'juta', 'miliar', 'triliun', 'kuadriliun', 'kuintiliun'];
        $parts = [];
        $group = 0;
        while ($amount > 0) {
            $value = $amount % 1000;
            if ($value > 0) {
                $parts[] = $group === 1 && $value === 1 ? 'seribu' : trim(self::small($value).' '.$suffixes[$group]);
            }
            $amount = intdiv($amount, 1000);
            $group++;
        }

        return ucfirst(implode(' ', array_reverse($parts))).' Rupiah';
    }

    private static function small(int $value): string
    {
        $words = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
        if ($value < 12) {
            return $words[$value];
        }
        if ($value < 20) {
            return self::small($value - 10).' belas';
        }
        if ($value < 100) {
            return trim(self::small(intdiv($value, 10)).' puluh '.self::small($value % 10));
        }
        if ($value < 200) {
            return trim('seratus '.self::small($value - 100));
        }

        return trim(self::small(intdiv($value, 100)).' ratus '.self::small($value % 100));
    }
}
