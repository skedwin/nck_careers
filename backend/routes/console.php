<?php

use App\Models\MailSyncRun;
use App\Services\MicrosoftGraph\AttachmentDownloadDispatcher;
use App\Services\MicrosoftGraph\SyncService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::call(function () {
    $sync = app(SyncService::class);
    $state = $sync->getOrCreateState();

    if ($state->is_paused) {
        return;
    }

    $active = MailSyncRun::query()
        ->where('mailbox', $sync->mailbox())
        ->whereIn('status', [MailSyncRun::STATUS_PENDING, MailSyncRun::STATUS_RUNNING])
        ->exists();

    if ($active) {
        return;
    }

    $sync->startSync(trigger: 'schedule');
})->name('mailbox-scheduled-sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::call(function () {
    app(AttachmentDownloadDispatcher::class)->refillIfNeeded(100, 25);
})->name('mailbox-attachment-refill')
    ->everyMinute()
    ->withoutOverlapping();
