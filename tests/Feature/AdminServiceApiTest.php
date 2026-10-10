<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminServiceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ApiKey::create([
            'name' => 'Service API test',
            'token' => hash('sha256', 'service-api-test-token'),
            'type' => 'admin',
            'permissions' => ['admin.services.create', 'admin.services.update'],
        ]);
    }

    public function test_admin_api_rejects_a_plan_from_another_product_when_updating_only_the_plan(): void
    {
        $product = $this->createProduct();
        $otherProduct = $this->createProduct();
        $service = Service::factory()->create([
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'user_id' => User::factory()->create()->id,
        ]);

        $this->withToken('service-api-test-token')
            ->patchJson('/api/v1/admin/services/' . $service->id, ['plan_id' => $otherProduct->plan->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan_id');

        $this->assertSame($product->plan->id, $service->fresh()->plan_id);
    }

    public function test_admin_api_rejects_changing_product_without_a_matching_plan(): void
    {
        $product = $this->createProduct();
        $otherProduct = $this->createProduct();
        $service = Service::factory()->create([
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'user_id' => User::factory()->create()->id,
        ]);

        $this->withToken('service-api-test-token')
            ->patchJson('/api/v1/admin/services/' . $service->id, ['product_id' => $otherProduct->product->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan_id');

        $this->assertSame($product->product->id, $service->fresh()->product_id);
    }

    public function test_admin_api_rejects_a_currency_without_a_plan_price(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'user_id' => User::factory()->create()->id,
        ]);

        $this->withToken('service-api-test-token')
            ->patchJson('/api/v1/admin/services/' . $service->id, ['currency_code' => 'USD'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan_id');

        $this->assertSame('CNY', $service->fresh()->currency_code);
    }

    public function test_admin_api_rejects_creating_a_service_when_the_plan_has_no_price_in_its_currency(): void
    {
        $product = $this->createProduct();

        $this->withToken('service-api-test-token')
            ->postJson('/api/v1/admin/services', [
                'product_id' => $product->product->id,
                'plan_id' => $product->plan->id,
                'user_id' => User::factory()->create()->id,
                'quantity' => 1,
                'status' => 'pending',
                'currency_code' => 'USD',
                'price' => 10,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan_id');

        $this->assertDatabaseCount('services', 0);
    }

    public function test_admin_api_creates_a_service_when_the_plan_has_a_price_in_its_currency(): void
    {
        $product = $this->createProduct();
        $user = User::factory()->create();

        $this->withToken('service-api-test-token')
            ->postJson('/api/v1/admin/services', [
                'product_id' => $product->product->id,
                'plan_id' => $product->plan->id,
                'user_id' => $user->id,
                'quantity' => 1,
                'status' => 'pending',
                'currency_code' => 'CNY',
                'price' => 10,
            ])
            ->assertOk();

        $this->assertDatabaseHas('services', [
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'user_id' => $user->id,
            'currency_code' => 'CNY',
        ]);
    }
}
