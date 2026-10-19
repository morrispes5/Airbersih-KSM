<?php

use App\Models\User;
use App\Services\Backups;
use App\Services\MembershipHistory;
use App\Services\OperationalSetup;
use App\Services\WorkbookPreview;
use App\Support\DatabaseGuard;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('air:check', function () {
    DatabaseGuard::check();
    $r = DB::selectOne('SELECT current_database() AS db, current_user AS role');
    $this->info('Target verified: '.$r->db.' / '.$r->role.' / TLS verify-full');
});
Artisan::command('air:record-membership-history {--apply} {--actor=}', function () {
    abort_unless(app()->environment('operational'), 403, 'Perintah ini khusus database operasional.');
    DatabaseGuard::check();
    $history = app(MembershipHistory::class);
    $result = $history->inspect();
    if ($this->option('apply')) {
        abort_unless(ctype_digit((string) $this->option('actor')), 422, 'Pilih ID pengurus yang memberi persetujuan.');
        $result = $history->record(User::findOrFail((int) $this->option('actor')));
    }
    $this->info(json_encode($result, JSON_THROW_ON_ERROR));
})->purpose('Periksa atau catat riwayat daftar anggota 8 Desember 2025; tidak membuat pelanggan atau transaksi.');
Artisan::command('air:backup', function () {
    $path = app(Backups::class)->create();
    $this->info(config('airbersih.mode') === 'operational' ? 'Backup terenkripsi tersimpan di cloud dan salinan pemulihan Aiven.' : 'Encrypted database and file backup saved in private/backups.');
});
Artisan::command('air:bootstrap-operational', function () {
    app(OperationalSetup::class)->bootstrap();
    $this->info('Akun operasional tersedia pada berkas privat. Saldo dan baseline belum dikonfirmasi.');
});
Artisan::command('air:stage-operational-sources', function () {
    $results = app(OperationalSetup::class)->sources();
    file_put_contents(dirname(base_path()).'/docs/evidence/operational-sources.json', json_encode($results, JSON_PRETTY_PRINT));
    $this->info('Sumber tersimpan di cloud dan staging. B/C dideduplikasi; tidak ada posting kas/piutang.');
});
Artisan::command('air:restore-test', function () {
    $result = app(Backups::class)->restoreTest();
    file_put_contents(dirname(base_path()).'/docs/evidence/backup-restore.json', json_encode($result, JSON_PRETTY_PRINT));
    $this->info(json_encode($result, JSON_PRETTY_PRINT));
});
Artisan::command('air:preview-sources', function () {
    $results = [];
    foreach (['excel-A', 'excel-B', 'excel-C'] as $alias) {
        $path = config('airbersih.sources').'/'.$alias.'.xlsx';
        if (! is_file($path)) {
            continue;
        }
        $summary = app(WorkbookPreview::class)->inspect($path);
        $results[] = ['alias' => $alias, 'sheets' => count($summary['sheets']), 'hidden' => count(array_filter($summary['sheets'], fn ($s) => $s['state'] === 'hidden')), 'media' => $summary['media_count'], 'warnings' => array_merge(...array_column($summary['sheets'], 'warnings')), 'peak_memory_mb' => $summary['peak_memory_mb']];
    }
    file_put_contents(dirname(base_path()).'/docs/evidence/application-workbook-preview.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $this->info(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
});
