<?php

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('qa:reset {--force}', function () {
    if (app()->environment('production')) {
        $this->error('qa:reset is disabled in production.');

        return 1;
    }

    if (! $this->option('force')) {
        $this->error('This destroys the isolated staging database. Re-run with --force.');

        return 1;
    }

    config(['database.default' => 'staging']);
    $status = $this->call('migrate:fresh', [
        '--database' => 'staging',
        '--seed' => true,
        '--force' => true,
    ]);

    if ($status !== 0) {
        return $status;
    }

    $this->info('Staging reset to '.DatabaseSeeder::BASELINE_VERSION.'.');

    return 0;
})->purpose('Reset, migrate, and seed the isolated staging database for a QA cycle');

Schedule::command('sanctum:prune-expired --hours=24')->daily();
