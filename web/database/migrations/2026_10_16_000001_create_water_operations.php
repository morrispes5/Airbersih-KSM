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
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('petugas');
            $t->boolean('active')->default(true);
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('phone')->nullable();
            $t->text('address');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('connections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->string('code')->unique();
            $t->text('address');
            $t->string('status')->default('active');
            $t->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('fee_override')->nullable();
            $t->text('fee_reason')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('meters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('connection_id')->constrained()->restrictOnDelete();
            $t->string('serial');
            $t->decimal('last_value', 14, 3);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX meters_one_active ON meters (connection_id) WHERE active');
        Schema::create('readings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('connection_id')->constrained()->restrictOnDelete();
            $t->foreignId('meter_id')->constrained()->restrictOnDelete();
            $t->string('period', 7);
            $t->decimal('previous', 14, 3);
            $t->decimal('current', 14, 3);
            $t->string('status')->default('draft');
            $t->foreignId('operator_id')->constrained('users');
            $t->foreignId('reviewer_id')->nullable()->constrained('users');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['meter_id', 'period']);
        });
        Schema::create('meter_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('connection_id')->constrained();
            $t->foreignId('meter_id')->constrained();
            $t->string('period', 7);
            $t->string('serial');
            $t->decimal('opening', 14, 3);
            $t->text('reason');
            $t->string('status')->default('pending');
            $t->foreignId('operator_id')->constrained('users');
            $t->foreignId('approver_id')->nullable()->constrained('users');
            $t->timestamps();
        });
        DB::statement("CREATE UNIQUE INDEX one_pending_meter_change ON meter_changes (connection_id) WHERE status = 'pending'");
        Schema::create('tariffs', function (Blueprint $t) {
            $t->id();
            $t->date('effective_from')->unique();
            $t->unsignedBigInteger('rate');
            $t->unsignedBigInteger('fee');
            $t->foreignId('approved_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('connection_id')->constrained();
            $t->string('period', 7);
            $t->string('number')->nullable()->unique();
            $t->uuid('idempotency_key')->unique();
            $t->string('status')->default('draft');
            $t->jsonb('snapshot');
            $t->unsignedBigInteger('total');
            $t->date('due_date');
            $t->timestampTz('issued_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->text('void_reason')->nullable();
            $t->string('pdf_path')->nullable();
            $t->timestamps();
        });
        DB::statement("CREATE UNIQUE INDEX one_current_invoice ON invoices (connection_id, period) WHERE status <> 'void'");
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('kind');
            $t->timestamps();
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained();
            $t->foreignId('account_id')->constrained('accounts');
            $t->uuid('idempotency_key')->unique();
            $t->unsignedBigInteger('amount');
            $t->string('method');
            $t->date('paid_on');
            $t->string('status')->default('pending');
            $t->foreignId('operator_id')->constrained('users');
            $t->foreignId('confirmed_by')->nullable()->constrained('users');
            $t->text('reason')->nullable();
            $t->timestamps();
        });
        DB::statement("CREATE UNIQUE INDEX one_current_payment ON payments (invoice_id) WHERE status IN ('pending', 'confirmed')");
        Schema::create('entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->constrained('accounts');
            $t->foreignId('category_id')->nullable()->constrained('categories');
            $t->string('kind');
            $t->bigInteger('amount');
            $t->date('entry_date');
            $t->text('description');
            $t->string('source_key')->unique();
            $t->foreignId('operator_id')->constrained('users');
            $t->foreignId('reverses_id')->nullable()->unique()->constrained('entries');
            $t->timestamps();
        });
        Schema::create('attachments', function (Blueprint $t) {
            $t->id();
            $t->string('owner_type');
            $t->unsignedBigInteger('owner_id');
            $t->string('path');
            $t->string('mime');
            $t->unsignedBigInteger('bytes');
            $t->string('checksum', 64);
            $t->timestamps();
            $t->index(['owner_type', 'owner_id']);
        });
        Schema::create('import_batches', function (Blueprint $t) {
            $t->id();
            $t->string('hash', 64)->unique();
            $t->string('name');
            $t->string('path');
            $t->string('status')->default('queued');
            $t->jsonb('summary')->nullable();
            $t->foreignId('operator_id')->constrained('users');
            $t->timestamps();
        });
        Schema::create('audits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('action');
            $t->string('subject');
            $t->jsonb('details');
            $t->timestamps();
        });
        foreach (['invoices' => 'total >= 0', 'payments' => 'amount > 0', 'readings' => 'current >= previous', 'tariffs' => 'rate > 0 AND fee >= 0'] as $table => $check) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_valid CHECK ({$check})");
        }
    }

    public function down(): void
    {
        DatabaseGuard::check(true);
        foreach (['audits', 'import_batches', 'attachments', 'entries', 'payments', 'categories', 'accounts', 'invoices', 'tariffs', 'meter_changes', 'readings', 'meters', 'connections', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'active']));
    }
};
