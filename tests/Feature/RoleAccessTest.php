<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function product(): Product
    {
        $product = Product::factory()->create(['price' => 1250]);
        $product->inventory()->create(['quantity' => 5, 'reorder_level' => 1]);

        return $product;
    }

    public function test_an_unauthenticated_request_is_rejected_on_every_protected_route(): void
    {
        $product = $this->product();

        $this->getJson('/api/v1/products')->assertStatus(401);
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->getJson('/api/v1/users')->assertStatus(401);
        $this->postJson('/api/v1/products', [])->assertStatus(401);
        $this->patchJson('/api/v1/products/'.$product->id.'/stock', ['stock' => 1])->assertStatus(401);
    }

    public function test_a_plain_request_without_an_accept_header_still_gets_a_json_401(): void
    {
        $response = $this->get('/api/v1/products');

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_a_cashier_can_read_the_catalog_and_history(): void
    {
        $product = $this->product();
        $this->withToken($this->tokenFor(User::factory()->cashier()->create()));

        $this->getJson('/api/v1/products')->assertOk();
        $this->getJson('/api/v1/products/barcode/'.$product->barcode)->assertOk();
        $this->getJson('/api/v1/products/'.$product->id)->assertOk();
        $this->getJson('/api/v1/inventory')->assertOk();
        $this->getJson('/api/v1/inventory/'.$product->id)->assertOk();
        $this->getJson('/api/v1/stock')->assertOk();
        $this->getJson('/api/v1/stock/'.$product->id)->assertOk();
        $this->getJson('/api/v1/sales')->assertOk();
        $this->getJson('/api/v1/transactions')->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_a_cashier_can_reach_the_sale_and_payment_routes(): void
    {
        $this->withToken($this->tokenFor(User::factory()->cashier()->create()));

        // 422 means the request passed authentication and role enforcement.
        $this->postJson('/api/v1/sales/cash', [])->assertStatus(422);
        $this->postJson('/api/v1/sales/checkout', [])->assertStatus(422);
        $this->postJson('/api/v1/payments', [])->assertStatus(422);
    }

    public function test_a_cashier_cannot_change_catalog_stock_or_users(): void
    {
        $product = $this->product();
        $this->withToken($this->tokenFor(User::factory()->cashier()->create()));

        $this->postJson('/api/v1/products', [])->assertStatus(403);
        $this->patchJson('/api/v1/products/'.$product->id, ['name' => 'Nope'])->assertStatus(403);
        $this->putJson('/api/v1/products/'.$product->id, ['name' => 'Nope'])->assertStatus(403);
        $this->patchJson('/api/v1/products/'.$product->id.'/stock', ['stock' => 1])->assertStatus(403);
        $this->putJson('/api/v1/products/'.$product->id.'/stock', ['stock' => 1])->assertStatus(403);
        $this->patchJson('/api/v1/inventory/'.$product->id, ['stock' => 1])->assertStatus(403);
        $this->putJson('/api/v1/inventory/'.$product->id, ['stock' => 1])->assertStatus(403);
        $this->patchJson('/api/v1/stock/'.$product->id, ['stock' => 1])->assertStatus(403);
        $this->putJson('/api/v1/stock/'.$product->id, ['stock' => 1])->assertStatus(403);
        $this->getJson('/api/v1/users')->assertStatus(403);
        $this->postJson('/api/v1/users', [])->assertStatus(403);
    }

    public function test_an_admin_keeps_the_write_surface(): void
    {
        $product = $this->product();
        $this->withToken($this->tokenFor(User::factory()->create()));

        $this->postJson('/api/v1/products', [
            'name' => 'House Blend Coffee',
            'barcode' => '4800000099999',
            'price' => 18500,
            'stock' => 12,
            'reorder_level' => 3,
        ])->assertCreated();

        $this->patchJson('/api/v1/products/'.$product->id.'/stock', ['stock' => 9])
            ->assertOk()
            ->assertJsonPath('data.stock.quantity', 9);

        $this->getJson('/api/v1/users')->assertOk();
    }

    public function test_a_deactivated_cashier_token_is_rejected(): void
    {
        $cashier = User::factory()->cashier()->inactive()->create();
        $this->withToken($this->tokenFor($cashier));

        $this->getJson('/api/v1/products')->assertStatus(401);
    }
}
