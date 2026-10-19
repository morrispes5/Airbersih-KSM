<?php

namespace Tests\Unit;

use App\Services\PrivateFiles;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class UploadedFileBufferTest extends TestCase
{
    public function test_remote_upload_bytes_are_available_and_buffer_is_removed(): void
    {
        $file = new class(__FILE__, 'cloud.png', null, null, true) extends UploadedFile
        {
            public function getRealPath(): string
            {
                return sys_get_temp_dir().'/nonexistent-air-upload';
            }

            public function readStream()
            {
                $stream = fopen('php://temp', 'w+');
                fwrite($stream, 'synthetic-remote-upload');
                rewind($stream);

                return $stream;
            }
        };
        $buffer = null;
        $result = PrivateFiles::withUpload($file, function ($path) use (&$buffer) {
            $buffer = $path;
            $this->assertSame('synthetic-remote-upload', file_get_contents($path));

            return hash_file('sha256', $path);
        });
        $this->assertSame(hash('sha256', 'synthetic-remote-upload'), $result);
        $this->assertFileDoesNotExist($buffer);
    }

    public function test_buffer_is_removed_when_consuming_upload_fails(): void
    {
        $file = new class(__FILE__, 'cloud.png', null, null, true) extends UploadedFile
        {
            public function getRealPath(): string
            {
                return sys_get_temp_dir().'/nonexistent-air-upload';
            }

            public function readStream()
            {
                return fopen('php://temp', 'w+');
            }
        };
        $buffer = null;
        try {
            PrivateFiles::withUpload($file, function ($path) use (&$buffer) {
                $buffer = $path;
                throw new RuntimeException('synthetic consumer failure');
            });
            $this->fail('Expected callback failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('synthetic consumer failure', $error->getMessage());
        }
        $this->assertFileDoesNotExist($buffer);
    }
}
