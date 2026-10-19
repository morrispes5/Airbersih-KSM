<?php

namespace App\Services;

use App\Support\DatabaseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class Backups
{
    private const TABLES = ['users', 'customers', 'connections', 'meters', 'readings', 'meter_changes', 'tariffs', 'invoices', 'accounts', 'categories', 'payments', 'entries', 'attachments', 'import_batches', 'source_rows', 'audits'];

    private function data(): array
    {
        $tables = [];
        foreach (self::TABLES as $t) {
            $tables[$t] = DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $tables;
    }

    private function key(): string
    {
        return hash('sha256', config('app.key'), true);
    }

    private function directory(): string
    {
        $dir = dirname(base_path()).'/private/backups';
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir;
    }

    public function create(): string
    {
        DatabaseGuard::check();
        $dir = $this->directory();
        $tmp = $dir.'/'.bin2hex(random_bytes(8)).'.zip';
        $out = $dir.'/'.(config('airbersih.mode') === 'operational' ? 'airbersih-op-'.bin2hex(random_bytes(8)) : 'airbersih-latest').'.enc';
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $buffers = [];
        try {
            $tables = DB::transaction(function () {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

                return $this->data();
            });
            $files = [];
            foreach (array_merge(array_column($tables['attachments'], 'path'), array_filter(array_column($tables['invoices'], 'pdf_path')), array_column($tables['import_batches'], 'path')) as $path) {
                foreach (PrivateFiles::paths($path) as $object) {
                    abort_unless(Storage::disk(PrivateFiles::disk())->exists($object), 422, 'File yang direferensikan database hilang; backup dibatalkan.');
                    $full = tempnam(sys_get_temp_dir(), 'air-backup-');
                    $buffers[] = $full;
                    $stream = Storage::disk(PrivateFiles::disk())->readStream($object);
                    $dest = fopen($full, 'wb');
                    try {
                        stream_copy_to_stream($stream, $dest);
                    } finally {
                        fclose($stream);
                        fclose($dest);
                    }
                    $files[$object] = hash_file('sha256', $full);
                    $zip->addFile($full, 'files/'.$object);
                }
            }
            $zip->addFromString('database.json', json_encode(['tables' => $tables, 'files' => $files], JSON_THROW_ON_ERROR));
            $zip->close();
            [$state,$header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key());
            $in = fopen($tmp, 'rb');
            $dest = fopen($out.'.new', 'wb');
            fwrite($dest, 'AIRBB1'.$header);
            do {
                $plain = fread($in, 1024 * 1024);
                $final = feof($in);
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain, '', $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : 0);
                fwrite($dest, pack('N', strlen($cipher)).$cipher);
            } while (! $final);
            fclose($in);
            fclose($dest);
            rename($out.'.new', $out);
            if (config('airbersih.mode') === 'operational') {
                $path = PrivateFiles::store($out, 'backups/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.enc');
                $this->independentCopy($out);
                Storage::disk(PrivateFiles::disk())->put('backups/latest.json', json_encode(['path' => $path, 'hash' => hash_file('sha256', $out)], JSON_THROW_ON_ERROR));
                unlink($out);

                return $path;
            }

            return $out;
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
            foreach ($buffers as $buffer) {
                if (is_file($buffer)) {
                    unlink($buffer);
                }
            }
            if (config('airbersih.mode') === 'operational' && is_file($out)) {
                unlink($out);
            }
            if (is_file($out.'.new')) {
                unlink($out.'.new');
            }
        }
    }

    private function independentCopy(string $path): void
    {
        $size = (int) DB::selectOne('SELECT pg_database_size(current_database()) AS bytes')->bytes;
        abort_if($size + filesize($path) > 600 * 1024 * 1024, 422, 'Kapasitas salinan pemulihan Aiven perlu ditinjau sebelum backup berikutnya.');
        DB::transaction(function () use ($path) {
            $id = DB::table('recovery_copies')->insertGetId(['checksum' => hash_file('sha256', $path), 'bytes' => filesize($path), 'chunks' => (int) ceil(filesize($path) / (1024 * 1024)), 'created_at' => now()]);
            $stream = fopen($path, 'rb');
            try {
                $sequence = 0;
                while (! feof($stream)) {
                    $chunk = fread($stream, 1024 * 1024);
                    if ($chunk !== '') {
                        DB::insert("INSERT INTO recovery_chunks (recovery_copy_id, sequence, content) VALUES (?, ?, decode(?, 'base64'))", [$id, $sequence++, base64_encode($chunk)]);
                    }
                }
            } finally {
                fclose($stream);
            }
        });
    }

    public function restoreTest(?string $sourcePath = null, ?string $sourceKey = null): array
    {
        DatabaseGuard::check(true);
        $dir = $this->directory();
        $source = $sourcePath ?? $dir.'/airbersih-latest.enc';
        abort_unless(is_file($source), 422, 'Backup belum dibuat.');
        $tmp = $dir.'/restore-'.bin2hex(random_bytes(8)).'.zip';
        $in = fopen($source, 'rb');
        $dest = fopen($tmp, 'wb');
        $final = false;
        try {
            if (fread($in, 6) !== 'AIRBB1') {
                throw new \RuntimeException('Format backup ditolak.');
            }$header = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $sourceKey === null ? $this->key() : hash('sha256', $sourceKey, true));
            while (! feof($in)) {
                $length = fread($in, 4);
                if ($length === '') {
                    break;
                }if (strlen($length) !== 4) {
                    throw new \RuntimeException('Backup terpotong.');
                }$size = unpack('N', $length)[1];
                if ($size > 1024 * 1024 + 64) {
                    throw new \RuntimeException('Ukuran blok ditolak.');
                }$chunk = fread($in, $size);
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);
                if ($result === false || $final) {
                    throw new \RuntimeException('Autentikasi backup gagal.');
                }[$plain,$tag] = $result;
                fwrite($dest, $plain);
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            fclose($in);
            fclose($dest);
            if (! $final) {
                throw new \RuntimeException('Backup belum lengkap.');
            }
            $zip = new ZipArchive;
            abort_unless($zip->open($tmp) === true, 422);
            $manifest = json_decode($zip->getFromName('database.json'), true, flags: JSON_THROW_ON_ERROR);
            $tables = $manifest['tables'];
            if (array_keys($tables) !== self::TABLES) {
                throw new \RuntimeException('Daftar tabel backup tidak cocok.');
            }
            foreach ($manifest['files'] as $path => $hash) {
                if (! preg_match('~^(attachments|invoices|imports|sources)/[A-Za-z0-9._-]+$~D', $path) || str_contains($path, '..')) {
                    throw new \RuntimeException('Path backup ditolak.');
                }$stream = $zip->getStream('files/'.$path);
                if (! $stream) {
                    throw new \RuntimeException('Lampiran backup hilang.');
                }$h = hash_init('sha256');
                hash_update_stream($h, $stream);
                fclose($stream);
                if (! hash_equals($hash, hash_final($h))) {
                    throw new \RuntimeException('Checksum lampiran gagal.');
                }
            }
            DB::transaction(function () use ($tables) {
                DB::table('jobs')->delete();
                DB::table('sessions')->delete();
                foreach (array_reverse(self::TABLES) as $t) {
                    DB::table($t)->delete();
                }foreach ($tables as $t => $rows) {
                    foreach (array_chunk($rows, 50) as $chunk) {
                        DB::table($t)->insert($chunk);
                    }DB::statement("SELECT setval(pg_get_serial_sequence('{$t}', 'id'), COALESCE((SELECT MAX(id) FROM {$t}),1), EXISTS(SELECT 1 FROM {$t}))");
                }
            });
            foreach ($manifest['files'] as $path => $hash) {
                $stream = $zip->getStream('files/'.$path);
                $written = Storage::disk(PrivateFiles::disk())->put($path, $stream);
                fclose($stream);
                if (! $written || ! hash_equals($hash, PrivateFiles::hash($path))) {
                    throw new \RuntimeException('Readback lampiran gagal.');
                }
            }$zip->close();
            if (json_encode($this->data(), JSON_THROW_ON_ERROR) !== json_encode($tables, JSON_THROW_ON_ERROR)) {
                throw new \RuntimeException('Readback database tidak cocok.');
            }

            return ['database' => 'airbersih_test', 'tables' => count($tables), 'rows' => array_sum(array_map('count', $tables)), 'files' => count($manifest['files']), 'readback' => 'identical'];
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }if (is_resource($dest)) {
                fclose($dest);
            }if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }
}
