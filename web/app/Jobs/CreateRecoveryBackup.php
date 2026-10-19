<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Backups;
use App\Services\Operations;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CreateRecoveryBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $actorId) {}

    public function handle(Backups $backups): void
    {
        $user = User::findOrFail($this->actorId);
        app(Operations::class)->admin($user);
        $backups->create();
        app(Operations::class)->audit($user, 'recovery.backup.completed', 'operational');
    }
}
