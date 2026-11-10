<?php

use App\Services\ChequeService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('finox:flag-stale', function (ChequeService $cheques) {
    $n = $cheques->flagStale();
    $this->info("Flagged {$n} cheque(s) as past the stale period for review.");
})->purpose('Flag released cheques that passed the stale period (a user confirms the status change)');

Schedule::command('finox:flag-stale')->dailyAt('01:00');

Schedule::command('finox:backup')->dailyAt('02:00');
