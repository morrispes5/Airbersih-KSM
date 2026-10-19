<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Entry;
use App\Models\Invoice;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;

class Reports
{
    public function data(string $period): array
    {
        BusinessClock::period($period);
        $start = $period.'-01';
        $end = CarbonImmutable::parse($start)->endOfMonth()->toDateString();
        $rows = BusinessClock::history(Entry::whereBetween('entry_date', [$start, $end]), 'entry_date')->orderBy('entry_date')->orderBy('id')->get();
        if (config('airbersih.mode') === 'operational') {
            [$from] = BusinessClock::window();
            $start = max($start, $from);
            $end = min($end, BusinessClock::today()->toDateString());
        }
        $opening = (int) Entry::where('entry_date', '<', $start)->sum('amount');
        $income = (int) Entry::whereBetween('entry_date', [$start, $end])->where(function ($q) {
            $q->whereIn('kind', ['payment', 'income'])->orWhere(function ($r) {
                $r->where('kind', 'reversal')->whereIn('reverses_id', Entry::select('id')->whereIn('kind', ['payment', 'income']));
            });
        })->sum('amount');

        $known = config('airbersih.mode') !== 'operational' || (Account::exists() && ! Account::whereNull('opening_by')->orWhere('opening_on', '>', $end)->exists());
        $movements = $rows;
        if (config('airbersih.mode') === 'operational') {
            $opening += (int) $rows->where('kind', 'opening')->sum('amount');
            $movements = $rows->where('kind', '<>', 'opening');
        }

        return ['period' => $period, 'from' => $start, 'until' => $end, 'opening_known' => $known, 'opening' => $known ? $opening : null, 'incoming' => (int) $movements->where('amount', '>', 0)->sum('amount'), 'outgoing' => -(int) $movements->where('amount', '<', 0)->sum('amount'), 'closing' => $known ? $opening + (int) $movements->sum('amount') : null, 'rows' => $rows, 'receivables' => (int) Invoice::where('period', '<=', $period)->where('status', 'issued')->sum('total'), 'transfer_in' => (int) $rows->where('kind', 'transfer')->where('amount', '>', 0)->sum('amount'), 'income' => $income];
    }
}
