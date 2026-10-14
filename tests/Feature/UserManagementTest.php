<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_an_admin_can_create_a_cashier_who_can_then_log_in(): void
    {
        $this->withToken($this->tokenFor(User::factory()->create()));

        $response = $this->postJson('/api/v1/users', [
            'name' => 'Counter One',
            'email' => 'cashier@example.test',
            'password' => 'cashier-password',
            'role' => User::ROLE_ADMIN,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.role', User::ROLE_CASHIER)
            ->assertJsonPath('data.user.is_active', true);

        $this->assertDatabaseHas('users', [
            'email' => 'cashier@example.test',
            'role' => User::ROLE_CASHIER,
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'cashier@example.test',
            'password' => 'cashier-password',
        ]);

        $login->assertOk();

        $this->withToken($login->json('data.token'));

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.role', User::ROLE_CASHIER);
    }

    public function test_creating_a_cashier_validates_name_email_and_password(): void
    {
        $this->withToken($this->tokenFor(User::factory()->create()));
        User::factory()->create(['email' => 'taken@example.test']);

        $this->postJson('/api/v1/users', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');

        $this->postJson('/api/v1/users', [
            'name' => 'Short Password',
            'email' => 'ok@example.test',
            'password' => 'short',
        ])->assertStatus(422);

        $this->postJson('/api/v1/users', [
            'name' => 'Duplicate Email',
            'email' => 'taken@example.test',
            'password' => 'long-enough-password',
        ])->assertStatus(422);
    }

    public function test_an_admin_can_list_and_update_cashiers(): void
    {
        $admin = User::factory()->create();
        $cashier = User::factory()->cashier()->create([
            'email' => 'old@example.test',
            'password' => 'old-password',
        ]);
        $this->withToken($this->tokenFor($admin));

        $this->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonPath('data.items.0.role', User::ROLE_ADMIN)
            ->assertJsonPath('data.items.1.role', User::ROLE_CASHIER);

        $this->patchJson('/api/v1/users/'.$cashier->id, [
            'name' => 'Counter Two',
            'email' => 'new@example.test',
            'password' => 'new-password',
        ])->assertOk()->assertJsonPath('data.user.name', 'Counter Two');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'new@example.test',
            'password' => 'new-password',
        ])->assertOk();
    }

    public function test_deactivating_a_cashier_revokes_existing_tokens_and_blocks_login(): void
    {
        $admin = User::factory()->create();
        $cashier = User::factory()->cashier()->create([
            'email' => 'cashier@example.test',
            'password' => 'cashier-password',
        ]);
        $cashierToken = $this->tokenFor($cashier);

        $this->withToken($cashierToken);
        $this->getJson('/api/v1/products')->assertOk();

        $this->withToken($this->tokenFor($admin));
        $this->postJson('/api/v1/users/'.$cashier->id.'/deactivate')
            ->assertStatus(204);

        $this->assertDatabaseHas('users', ['id' => $cashier->id, 'is_active' => false]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $cashier->id,
            'tokenable_type' => User::class,
        ]);

        $this->withToken($cashierToken);
        $this->getJson('/api/v1/products')->assertStatus(401);
        $this->postJson('/api/v1/auth/login', [
            'email' => 'cashier@example.test',
            'password' => 'cashier-password',
        ])->assertStatus(401);
    }

    /**
     * @return array<string, array{bool|int|string}>
     */
    public static function inactiveValues(): array
    {
        return [
            'boolean false' => [false],
            'integer zero' => [0],
            'string zero' => ['0'],
        ];
    }

    #[DataProvider('inactiveValues')]
    public function test_patching_a_cashier_inactive_permanently_revokes_all_tokens(bool|int|string $inactive): void
    {
        $adminToken = $this->tokenFor(User::factory()->create());
        $cashier = User::factory()->cashier()->create();
        $cashierTokens = [$this->tokenFor($cashier), $this->tokenFor($cashier)];

        $this->withToken($cashierTokens[0]);
        $this->getJson('/api/v1/auth/me')->assertOk();

        $this->withToken($adminToken);
        $this->patchJson('/api/v1/users/'.$cashier->id, ['is_active' => $inactive])
            ->assertOk()->assertJsonPath('data.user.is_active', false);

        $this->assertFalse($cashier->fresh()->is_active);
        $this->assertSame(0, $cashier->tokens()->count());

        foreach ($cashierTokens as $token) {
            $this->withToken($token);
            $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        }

        $this->withToken($adminToken);
        $this->patchJson('/api/v1/users/'.$cashier->id, ['is_active' => true])
            ->assertOk()->assertJsonPath('data.user.is_active', true);

        foreach ($cashierTokens as $token) {
            $this->withToken($token);
            $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        }

        $this->withToken($this->tokenFor($cashier->fresh()));
        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_reactivating_an_inactive_cashier_revokes_tokens_left_by_an_older_bug(): void
    {
        $admin = User::factory()->create();
        $cashier = User::factory()->cashier()->inactive()->create();
        $cashierToken = $this->tokenFor($cashier);

        $this->withToken($cashierToken);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->withToken($this->tokenFor($admin));
        $this->patchJson('/api/v1/users/'.$cashier->id, ['is_active' => true])
            ->assertOk()->assertJsonPath('data.user.is_active', true);

        $this->assertSame(0, $cashier->tokens()->count());
        $this->withToken($cashierToken);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->withToken($this->tokenFor($cashier->fresh()));
        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_patching_an_active_cashier_does_not_revoke_tokens(): void
    {
        $cashier = User::factory()->cashier()->create();
        $cashierToken = $this->tokenFor($cashier);
        $this->withToken($this->tokenFor(User::factory()->create()));

        $this->patchJson('/api/v1/users/'.$cashier->id, ['name' => 'Updated Cashier'])->assertOk();
        $this->patchJson('/api/v1/users/'.$cashier->id, ['is_active' => 1])
            ->assertOk()->assertJsonPath('data.user.is_active', true);

        $this->withToken($cashierToken);
        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_a_cashier_cannot_manage_users(): void
    {
        $this->withToken($this->tokenFor(User::factory()->cashier()->create()));
        $other = User::factory()->cashier()->create();

        $this->getJson('/api/v1/users')->assertStatus(403);
        $this->postJson('/api/v1/users', [
            'name' => 'A',
            'email' => 'a@example.test',
            'password' => 'long-enough-password',
        ])->assertStatus(403);
        $this->patchJson('/api/v1/users/'.$other->id, ['name' => 'Nope'])->assertStatus(403);
        $this->postJson('/api/v1/users/'.$other->id.'/deactivate')->assertStatus(403);
    }
}
