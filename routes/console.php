<?php

use App\Jobs\SendWeeklySummary;
use App\Models\FormDraft;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new SendWeeklySummary)->weeklyOn(1, '8:00');

Schedule::command('model:prune', ['--model' => [FormDraft::class]])->daily();
