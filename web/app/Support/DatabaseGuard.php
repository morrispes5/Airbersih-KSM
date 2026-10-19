<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DatabaseGuard
{
    public static function check(bool $testingOnly = false): void
    {
        $c = config('database.connections.pgsql');
        $destructiveCommand = app()->runningInConsole() && in_array($_SERVER['argv'][1] ?? '', ['migrate:fresh', 'migrate:reset', 'migrate:refresh', 'migrate:rollback', 'db:wipe'], true);
        $allowed = app()->environment(['testing', 'e2e']) || $testingOnly || $destructiveCommand
            ? ['airbersih_test' => ['airbersih_test_runner']]
            : (app()->environment(['operational', 'operational-migration'])
                ? ['airbersih_operasional' => ['airbersih_ops_app', 'airbersih_ops_migrator']]
                : ['airbersih_dev' => ['airbersih_dev_app', 'airbersih_dev_migrator']]);
        if (config('database.default') !== 'pgsql' || ! empty($c['url']) || ! isset($allowed[$c['database']])
            || ! in_array($c['username'], $allowed[$c['database']], true) || $c['sslmode'] !== 'verify-full'
            || ! str_ends_with($c['host'], '.aivencloud.com') || ! is_file(config('airbersih.ca_path'))) {
            throw new RuntimeException('Target database ditolak: wajib database Air Bersih yang terisolasi dengan TLS terverifikasi.');
        }
        $actual = DB::selectOne('SELECT current_database() AS db, current_user AS role');
        if ($actual->db !== $c['database'] || $actual->role !== $c['username']) {
            throw new RuntimeException('Identitas database tidak sesuai konfigurasi.');
        }
    }
}
