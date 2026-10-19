<?php

namespace Tests\Unit;

use App\Support\RupiahWords;
use PHPUnit\Framework\TestCase;

class RupiahWordsTest extends TestCase
{
    public function test_integer_rupiah_including_invoice_and_group_boundaries(): void
    {
        foreach ([0 => 'Nol Rupiah', 11 => 'Sebelas Rupiah', 19 => 'Sembilan belas Rupiah', 100 => 'Seratus Rupiah', 101 => 'Seratus satu Rupiah', 1000 => 'Seribu Rupiah', 1011 => 'Seribu sebelas Rupiah', 5000 => 'Lima ribu Rupiah', 305000 => 'Tiga ratus lima ribu Rupiah', 500000 => 'Lima ratus ribu Rupiah', 505000 => 'Lima ratus lima ribu Rupiah', 1005000 => 'Satu juta lima ribu Rupiah', 1000000000 => 'Satu miliar Rupiah', 1000000000000 => 'Satu triliun Rupiah'] as $amount => $words) {
            $this->assertSame($words, RupiahWords::spell($amount));
        }
        $this->assertNotEmpty(RupiahWords::spell(PHP_INT_MAX));
    }

    public function test_negative_amount_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RupiahWords::spell(-1);
    }
}
