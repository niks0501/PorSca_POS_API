<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
