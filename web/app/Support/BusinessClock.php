<?php

namespace App\Support;

use App\Services\Operations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

final class BusinessClock
{
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Jakarta')->startOfDay();
    }

    public static function window(): array
    {
        $today = self::today();

        return [$today->subYearNoOverflow()->toDateString(), $today->addDay()->toDateString()];
    }

    public static function period(string $period): void
    {
        app(Operations::class)->period($period);
        if (config('airbersih.mode') === 'operational') {
            [$from] = self::window();
            abort_unless($period >= substr($from, 0, 7) && $period <= self::today()->format('Y-m'), 422, 'Pilih periode dalam satu tahun berjalan. Riwayat lebih tua dipertahankan dalam sumber arsip.');
        }
    }

    public static function date(string $date): void
    {
        Validator::make(compact('date'), ['date' => 'required|date_format:Y-m-d'])->validate();
        abort_if($date > self::today()->toDateString(), 422, 'Tanggal kejadian tidak boleh di masa depan.');
    }

    public static function history($query, string $column)
    {
        if (config('airbersih.mode') === 'operational') {
            [$from, $until] = self::window();
            if (str_ends_with($column, '_at')) {
                $from = CarbonImmutable::parse($from, 'Asia/Jakarta')->utc()->toDateTimeString();
                $until = CarbonImmutable::parse($until, 'Asia/Jakarta')->utc()->toDateTimeString();
            }
            $query->where($column, '>=', $from)->where($column, '<', $until);
        }

        return $query;
    }
}
