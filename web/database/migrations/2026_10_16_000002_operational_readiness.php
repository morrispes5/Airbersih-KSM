<?php

use App\Support\DatabaseGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DatabaseGuard::check();
        DB::statement('ALTER TABLE meters ALTER COLUMN last_value DROP NOT NULL');
        Schema::table('meters', function (Blueprint $t) {
            $t->date('last_read_on')->nullable();
            $t->foreignId('baseline_by')->nullable()->constrained('users');
        });
        Schema::table('readings', function (Blueprint $t) {
            $t->date('read_from')->nullable();
            $t->date('read_on')->nullable();
        });
        Schema::table('accounts', function (Blueprint $t) {
            $t->date('opening_on')->nullable();
            $t->foreignId('opening_by')->nullable()->constrained('users');
        });
        Schema::table('customers', function (Blueprint $t) {
            $t->string('source_key')->nullable()->unique();
            $t->timestampTz('verified_at')->nullable();
        });
        Schema::create('source_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('import_batch_id')->constrained('import_batches');
            $t->string('sheet');
            $t->unsignedInteger('row_number');
            $t->string('kind');
            $t->string('period', 7)->nullable();
            $t->string('date_precision')->default('unknown');
            $t->jsonb('cells');
            $t->jsonb('issues');
            $t->string('status')->default('review');
            $t->text('decision')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->foreignId('customer_id')->nullable()->constrained('customers');
            $t->timestamps();
            $t->unique(['import_batch_id', 'sheet', 'row_number']);
        });
        DB::statement('ALTER TABLE readings ADD CONSTRAINT reading_dates_valid CHECK (read_from IS NULL OR (read_on IS NOT NULL AND read_on >= read_from))');
    }

    public function down(): void
    {
        DatabaseGuard::check(true);
        Schema::dropIfExists('source_rows');
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['source_key', 'verified_at']));
        Schema::table('accounts', fn (Blueprint $t) => $t->dropColumn(['opening_on', 'opening_by']));
        DB::statement('ALTER TABLE readings DROP CONSTRAINT reading_dates_valid');
        Schema::table('readings', fn (Blueprint $t) => $t->dropColumn(['read_from', 'read_on']));
        Schema::table('meters', fn (Blueprint $t) => $t->dropColumn(['last_read_on', 'baseline_by']));
    }
};
