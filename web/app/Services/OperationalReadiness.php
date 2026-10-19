<?php

namespace App\Services;

use App\Support\BusinessClock;
use Illuminate\Support\Facades\DB;

final class OperationalReadiness
{
    public function data(): array
    {
        $today = BusinessClock::today()->toDateString();

        $counts = DB::selectOne("SELECT
            (SELECT count(*) FROM customers) AS customers,
            (SELECT count(*) FROM customers WHERE verified_at IS NOT NULL) AS verified_customers,
            (SELECT count(*) FROM connections) AS connections,
            (SELECT count(*) FROM connections WHERE status = 'active') AS active_connections,
            (SELECT count(*) FROM meters WHERE active = true AND last_value IS NOT NULL AND last_read_on BETWEEN ? AND ? AND baseline_by IS NOT NULL AND connection_id IN (SELECT id FROM connections WHERE status = 'active')) AS verified_baselines,
            (SELECT count(*) FROM accounts) AS accounts,
            (SELECT count(*) FROM accounts WHERE opening_by IS NOT NULL AND opening_on IS NOT NULL AND opening_on <= ?) AS confirmed_accounts,
            (SELECT count(*) FROM source_rows WHERE kind = 'master' AND status = 'review') AS pending_master_rows,
            (SELECT count(*) FROM source_rows WHERE status = 'review' AND issues::text LIKE '%CONFLICT:%') AS held_conflicts,
            (SELECT count(*) FROM source_rows WHERE kind = 'membership_history' AND status = 'recorded_history') AS history_members", [BusinessClock::today()->subDays(62)->toDateString(), $today, $today]);

        return array_map('intval', (array) $counts) + ['human_uat' => 'pending'];
    }

    public function backupPending(): bool
    {
        return DB::table('jobs')->where('payload', 'like', '%CreateRecoveryBackup%')->exists();
    }

    public function backupFailures(): int
    {
        $latest = DB::table('recovery_copies')->max('created_at');

        return DB::table('failed_jobs')->where('payload', 'like', '%CreateRecoveryBackup%')->when($latest, fn ($q) => $q->where('failed_at', '>=', $latest))->count();
    }
}
