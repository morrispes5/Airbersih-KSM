<?php

use App\Support\DatabaseGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DatabaseGuard::check();
        Schema::create('recovery_copies', function (Blueprint $t) {
            $t->id();
            $t->string('checksum', 64);
            $t->unsignedBigInteger('bytes');
            $t->unsignedInteger('chunks');
            $t->timestampTz('created_at');
        });
        Schema::create('recovery_chunks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('recovery_copy_id')->constrained('recovery_copies')->restrictOnDelete();
            $t->unsignedInteger('sequence');
            $t->binary('content');
            $t->unique(['recovery_copy_id', 'sequence']);
        });
    }

    public function down(): void
    {
        DatabaseGuard::check(true);
        Schema::dropIfExists('recovery_chunks');
        Schema::dropIfExists('recovery_copies');
    }
};
