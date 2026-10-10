<?php

namespace Tests;

use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\User;
use App\Models\UserSession;
use App\Providers\SettingsProvider;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Qirolab\Theme\Theme;

abstract class TestCase extends BaseTestCase
{
    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsProvider::getSettings(true);
        config(['settings' => collect(config('settings', []))->put('mail_disable', true)]);
        Theme::set(config('settings.theme', 'default'), 'default');
        $this->withoutVite();
    }

    //
    protected function createProduct(array $attributes = [])
    {

        // Create product + plan + price
        $product = Product::factory()->create(array_merge([
            'name' => 'Test Product',
            'description' => 'This is a test product.',
        ], $attributes));

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Test Product',
            'description' => 'This is a test product.',
        ]);

        $plan = Plan::factory()->create([
            'priceable_id' => $product->id,
            'priceable_type' => Product::class,
            'name' => 'Test Plan',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'name' => 'Test Plan',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);

        Price::factory()->create([
            'plan_id' => $plan->id,
            'price' => 10.00,
            'currency_code' => 'CNY',
        ]);

        $this->assertDatabaseHas('prices', [
            'plan_id' => $plan->id,
            'price' => 10.00,
            'currency_code' => 'CNY',
        ]);

        return (object) [
            'product' => $product,
            'plan' => $plan,
        ];
    }

    /**
     * Helper to create a user session and set it in the session
     */
    protected function loginUser(User $user, array $sessionData = []): array
    {
        $userSession = UserSession::create([
            'user_id' => $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => substr(request()->userAgent() ?? '', 0, 512),
            'last_activity' => now(),
            'expires_at' => null,
        ]);

        return array_merge([
            'user_session' => $userSession->ulid,
        ], $sessionData);
    }
}
