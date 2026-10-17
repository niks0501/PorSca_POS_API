<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class QaResetTest extends TestCase
{
    public function test_reset_propagates_a_failed_migration_and_seed_status_without_claiming_success(): void
    {
        $this->registerMigrationResult(7);

        $this->artisan('qa:reset', ['--force' => true])
            ->expectsOutput('Migration and seed failed.')
            ->doesntExpectOutput('Staging reset to '.DatabaseSeeder::BASELINE_VERSION.'.')
            ->assertExitCode(7);
    }

    public function test_reset_reports_success_after_a_successful_migration_and_seed(): void
    {
        $this->registerMigrationResult(0);
        $this->prepareStagingDatabase(withActiveAdmin: true);

        $this->artisan('qa:reset', ['--force' => true])
            ->expectsOutput('Migration and seed completed.')
            ->expectsOutput('Staging reset to '.DatabaseSeeder::BASELINE_VERSION.'.')
            ->assertSuccessful();
    }

    public function test_reset_fails_loudly_when_no_active_admin_was_seeded(): void
    {
        $this->registerMigrationResult(0);
        $this->prepareStagingDatabase();

        $this->artisan('qa:reset', ['--force' => true])
            ->expectsOutput('No active admin account was seeded. Set ADMIN_EMAIL and ADMIN_PASSWORD, then re-run qa:reset --force.')
            ->doesntExpectOutput('Staging reset to '.DatabaseSeeder::BASELINE_VERSION.'.')
            ->assertExitCode(1);
    }

    public function test_reset_treats_an_inactive_admin_as_no_active_admin(): void
    {
        $this->registerMigrationResult(0);
        $this->prepareStagingDatabase();
        $this->createStagingUser(role: User::ROLE_ADMIN, isActive: false);

        $this->artisan('qa:reset', ['--force' => true])
            ->expectsOutput('No active admin account was seeded. Set ADMIN_EMAIL and ADMIN_PASSWORD, then re-run qa:reset --force.')
            ->doesntExpectOutput('Staging reset to '.DatabaseSeeder::BASELINE_VERSION.'.')
            ->assertExitCode(1);
    }

    public function test_reset_requires_force_without_running_migrations(): void
    {
        $this->registerMigrationResult(0, false);

        $this->artisan('qa:reset')
            ->expectsOutput('This destroys the isolated staging database. Re-run with --force.')
            ->doesntExpectOutput('Staging reset to '.DatabaseSeeder::BASELINE_VERSION.'.')
            ->assertFailed();
    }

    public function test_reset_refuses_production_even_with_force(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->registerMigrationResult(0, false);

        $this->artisan('qa:reset', ['--force' => true])
            ->expectsOutput('qa:reset is disabled in production.')
            ->doesntExpectOutput('Staging reset to '.DatabaseSeeder::BASELINE_VERSION.'.')
            ->assertFailed();
    }

    private function prepareStagingDatabase(bool $withActiveAdmin = false): void
    {
        $this->app->make(Kernel::class)->bootstrap();

        // The reset command switches the default connection to staging, so the
        // admin guard reads the staging database. Point that connection at the
        // in-memory testing database and migrate it.
        config(['database.connections.staging' => config('database.connections.sqlite')]);

        Artisan::call('migrate', ['--database' => 'staging', '--force' => true]);

        if ($withActiveAdmin) {
            $this->createStagingUser(role: User::ROLE_ADMIN, isActive: true);
        }
    }

    private function createStagingUser(string $role, bool $isActive): void
    {
        User::on('staging')->create([
            'name' => 'QA Admin',
            'email' => 'qa-admin@porsca.test',
            'password' => 'password',
            'role' => $role,
            'is_active' => $isActive,
        ]);
    }

    private function registerMigrationResult(int $status, bool $shouldRun = true): void
    {
        $this->app->make(Kernel::class)->bootstrap();
        $test = $this;

        // Replace only the destructive nested operation, exercising qa:reset's
        // real console dispatch, arguments, output and exit status.
        Artisan::command('migrate:fresh {--database=} {--seed} {--force}', function () use ($test, $status, $shouldRun) {
            $test->assertTrue($shouldRun, 'Migrations must not run before the reset safety checks pass.');
            $test->assertSame('staging', $this->option('database'));
            $test->assertTrue($this->option('seed'));
            $test->assertTrue($this->option('force'));
            $test->assertSame('staging', config('database.default'));

            $this->line($status === 0 ? 'Migration and seed completed.' : 'Migration and seed failed.');

            return $status;
        });
    }
}
