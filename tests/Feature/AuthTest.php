<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_a_bearer_token_and_the_user(): void
    {
        User::factory()->create([
            'email' => 'admin@example.test',
            'password' => 'secret-password',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.test',
            'password' => 'secret-password',
            'device_name' => 'Test Phone',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'admin@example.test')
            ->assertJsonPath('data.user.role', User::ROLE_ADMIN)
            ->assertJsonPath('data.user.is_active', true)
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'token_type',
                    'user' => ['id', 'name', 'email', 'role', 'is_active'],
                ],
            ]);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Test Phone']);
    }

    public function test_login_uses_one_message_for_a_wrong_password_and_an_unknown_email(): void
    {
        User::factory()->create([
            'email' => 'admin@example.test',
            'password' => 'secret-password',
        ]);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.test',
            'password' => 'wrong-password',
        ]);

        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.test',
            'password' => 'secret-password',
        ]);

        $wrongPassword->assertStatus(401)->assertJsonPath('error.code', 'unauthorized');
        $unknownEmail->assertStatus(401)->assertJsonPath('error.code', 'unauthorized');
        $this->assertSame($wrongPassword->json('error.message'), $unknownEmail->json('error.message'));
    }

    public function test_login_rejects_invalid_input(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        User::factory()->create([
            'email' => 'admin@example.test',
            'password' => 'secret-password',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'admin@example.test',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.test',
            'password' => 'secret-password',
        ])->assertStatus(429);
    }

    public function test_login_rate_limit_is_shared_across_email_casing(): void
    {
        User::factory()->create([
            'email' => 'admin@example.test',
            'password' => 'secret-password',
        ]);

        foreach ([
            'admin@example.test',
            'Admin@example.test',
            'ADMIN@example.test',
            'admin@Example.test',
            'admin@example.TEST',
        ] as $email) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $email,
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'AdMiN@EXAMPLE.TEST',
            'password' => 'wrong-password',
        ])->assertStatus(429);

        // Other accounts and other IPs retain their own attempt budgets.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'other@example.test',
            'password' => 'wrong-password',
        ])->assertUnauthorized();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'admin@example.test',
                'password' => 'wrong-password',
            ])->assertUnauthorized();

        $this->travel(61)->seconds();

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'admin@example.test',
                'password' => 'secret-password',
            ])->assertOk();
    }

    public function test_me_requires_a_token_and_returns_the_signed_in_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->getJson('/api/v1/auth/me')->assertStatus(401);

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role', User::ROLE_ADMIN);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $first = $user->createToken('first')->plainTextToken;
        $second = $user->createToken('second')->plainTextToken;

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$first])
            ->assertStatus(204);

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$first])->assertStatus(401);
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$second])->assertOk();
    }

    public function test_inactive_user_cannot_log_in_and_its_existing_token_is_rejected(): void
    {
        $user = User::factory()->inactive()->create([
            'email' => 'cashier@example.test',
            'password' => 'secret-password',
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $this->postJson('/api/v1/auth/login', [
            'email' => 'cashier@example.test',
            'password' => 'secret-password',
        ])->assertStatus(401);

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])->assertStatus(401);
    }

    public function test_a_token_older_than_the_configured_expiration_is_rejected(): void
    {
        config(['sanctum.expiration' => 30]);

        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])->assertOk();

        $this->travel(31)->minutes();

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])->assertStatus(401);
    }
}
