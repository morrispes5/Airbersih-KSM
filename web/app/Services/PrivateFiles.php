<?php

namespace App\Services;

use App\Support\CloudNotReady;
use Illuminate\Support\Facades\Storage;

final class PrivateFiles
{
    public static function disk(): string
    {
        $disk = config('airbersih.documents_disk');
        if (config('airbersih.mode') === 'operational') {
            $c = config('filesystems.disks.'.$disk, []);
            if ($disk !== 'cloud' || ($c['driver'] ?? '') !== 's3' || empty($c['key']) || empty($c['secret']) || empty($c['bucket'])
                || ! preg_match('~^https://[a-z0-9]+\.storage\.supabase\.co/storage/v1/s3$~D', $c['endpoint'] ?? '') || ($c['throw'] ?? false) !== true) {
                throw new CloudNotReady('Penyimpanan cloud privat belum terhubung. File tidak disimpan ke penyimpanan lokal.');
            }
            if (app()->environment(['testing', 'e2e']) && ! str_ends_with($c['bucket'], '-test')) {
                throw new \RuntimeException('Pengujian cloud wajib memakai bucket terpisah berakhiran -test.');
            }
        }

        return $disk;
    }

    public static function store(string $source, string $path): string
    {
        $disk = Storage::disk(self::disk());
        if (filesize($source) <= 40 * 1024 * 1024) {
            $stream = fopen($source, 'rb');
            try {
                $disk->put($path, $stream);
            } finally {
                fclose($stream);
            }

            return $path;
        }
        // Logical files larger than the Free plan object limit are split without changing the original bytes.
        $parts = [];
        $stream = fopen($source, 'rb');
        try {
            while (! feof($stream)) {
                $bytes = fread($stream, 20 * 1024 * 1024);
                if ($bytes === '') {
                    break;
                }
                $part = $path.'.part'.str_pad((string) count($parts), 3, '0', STR_PAD_LEFT);
                $disk->put($part, $bytes);
                $parts[] = ['path' => $part, 'hash' => hash('sha256', $bytes)];
            }
            $manifest = $path.'.parts.json';
            $disk->put($manifest, json_encode(['parts' => $parts, 'bytes' => filesize($source), 'hash' => hash_file('sha256', $source)], JSON_THROW_ON_ERROR));

            return $manifest;
        } catch (\Throwable $e) {
            foreach ($parts as $part) {
                $disk->delete($part['path']);
            }
            throw $e;
        } finally {
            fclose($stream);
        }
    }

    public static function paths(string $path): array
    {
        if (! str_ends_with($path, '.parts.json')) {
            return [$path];
        }
        $manifest = json_decode(Storage::disk(self::disk())->get($path), true, flags: JSON_THROW_ON_ERROR);

        return array_merge([$path], array_column($manifest['parts'], 'path'));
    }

    public static function hash(string $path): string
    {
        $stream = Storage::disk(self::disk())->readStream($path);
        if (! is_resource($stream)) {
            throw new \RuntimeException('File cloud tidak dapat dibaca.');
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    public static function temporary(string $path, callable $callback): mixed
    {
        $temp = tempnam(sys_get_temp_dir(), 'air-');
        try {
            if (str_ends_with($path, '.parts.json')) {
                $disk = Storage::disk(self::disk());
                $manifest = json_decode($disk->get($path), true, flags: JSON_THROW_ON_ERROR);
                abort_if(($manifest['bytes'] ?? PHP_INT_MAX) > 1024 * 1024 * 1024 || count($manifest['parts'] ?? []) > 50, 422, 'Manifest file melewati batas.');
                $out = fopen($temp, 'wb');
                try {
                    foreach ($manifest['parts'] as $part) {
                        abort_unless(str_starts_with($part['path'], substr($path, 0, -11).'.part') && ! str_contains($part['path'], '..'), 422, 'Path bagian file ditolak.');
                        abort_unless(hash_equals($part['hash'], self::hash($part['path'])), 422, 'Checksum bagian file tidak cocok.');
                        $in = $disk->readStream($part['path']);
                        try {
                            stream_copy_to_stream($in, $out);
                        } finally {
                            fclose($in);
                        }
                    }
                } finally {
                    fclose($out);
                }
                abort_unless(filesize($temp) === $manifest['bytes'] && hash_equals($manifest['hash'], hash_file('sha256', $temp)), 422, 'Checksum file utuh tidak cocok.');

                return $callback($temp);
            }
            $in = Storage::disk(self::disk())->readStream($path);
            if (! is_resource($in)) {
                throw new \RuntimeException('File sumber tidak tersedia.');
            }
            $out = fopen($temp, 'wb');
            try {
                stream_copy_to_stream($in, $out);
            } finally {
                fclose($in);
                fclose($out);
            }

            return $callback($temp);
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }
}
