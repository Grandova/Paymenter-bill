<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\ConfigOption;
use App\Models\Plan;
use App\Models\Price;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\TestCase;

class CartConfigOptionChangeTest extends TestCase
{
    use RefreshDatabase;

    private $product = null;

    private ConfigOption $option;

    protected function setUp(): void
    {
        parent::setUp();
        $this->product = $this->createProduct();

        $this->option = ConfigOption::create([
            'name' => 'Location',
            'type' => 'radio',
            'sort' => 0,
        ]);
        $this->option->products()->attach($this->product->product->id);

        foreach (['Amsterdam', 'Frankfurt'] as $sort => $name) {
            $this->createOptionValue($name, $sort);
        }
    }

    private function createOptionValue(string $name, int $sort): ConfigOption
    {
        $value = ConfigOption::create([
            'name' => $name,
            'sort' => $sort,
            'parent_id' => $this->option->id,
        ]);

        $plan = Plan::factory()->create([
            'priceable_id' => $value->id,
            'priceable_type' => ConfigOption::class,
            'name' => $name,
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);

        Price::factory()->create([
            'plan_id' => $plan->id,
            'price' => 1.00,
            'currency_code' => 'CNY',
        ]);

        return $value;
    }

    private function addToCart(ConfigOption $value): array
    {
        Livewire::test('products.checkout', [
            'category' => $this->product->product->category,
            'product' => $this->product->product->slug,
        ])
            ->set('configOptions.' . $this->option->id, $value->id)
            ->call('checkout');

        $cart = Cart::firstOrFail();
        Once::flush();

        return [$cart, $cart->items()->firstOrFail()];
    }

    private function editCartItem(Cart $cart, $item)
    {
        return Livewire::withCookie('cart', $cart->ulid)->test('products.checkout', [
            'category' => $this->product->product->category,
            'product' => $this->product->product->slug,
            'cartProductKey' => $item->id,
        ]);
    }

    public function test_cart_item_can_be_edited_after_its_option_value_is_removed(): void
    {
        $values = $this->option->children()->get();
        [$cart, $item] = $this->addToCart($values[0]);

        // The option value the item was ordered with is removed from the product.
        $values[0]->delete();

        $this->editCartItem($cart, $item)
            ->assertSet('configOptions.' . $this->option->id, $values[1]->id)
            ->call('checkout')
            ->assertHasNoErrors();

        $this->assertSame(1, $cart->items()->count());
        $this->assertSame($values[1]->id, $cart->items()->first()->config_options[0]['value']);
    }

    public function test_cart_item_keeps_its_option_value_when_it_is_still_available(): void
    {
        $values = $this->option->children()->get();
        [$cart, $item] = $this->addToCart($values[1]);

        $this->editCartItem($cart, $item)
            ->assertSet('configOptions.' . $this->option->id, $values[1]->id)
            ->call('checkout')
            ->assertHasNoErrors();

        $this->assertSame(1, $cart->items()->count());
        $this->assertSame($values[1]->id, $cart->items()->first()->config_options[0]['value']);
    }

    public function test_cart_checkout_rejects_an_option_value_removed_after_it_was_added(): void
    {
        $user = User::factory()->create();
        $values = $this->option->children()->get();
        [$cart, $item] = $this->addToCart($values[0]);
        $values[0]->delete();
        $this->product->product->update(['stock' => 1]);
        $this->actingAs($user)->withCookie('cart', $cart->ulid);
        Once::flush();

        Livewire::withCookie('cart', $cart->ulid)->test(\App\Livewire\Cart::class)
            ->call('checkout')
            ->assertDispatched('notify', fn ($name, $params) => $params[0]['message'] === __('product.config_changed'));

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(1, $cart->items()->count());
        $this->assertNotNull($item->fresh());
        $this->assertSame(1, $this->product->product->fresh()->stock);
    }

    public function test_cart_checkout_rejects_a_new_required_option_added_after_it_was_added(): void
    {
        $user = User::factory()->create();
        [$cart] = $this->addToCart($this->option->children()->first());
        ConfigOption::create([
            'name' => 'Hostname',
            'type' => 'text',
        ])->products()->attach($this->product->product->id);
        $this->product->product->update(['stock' => 1]);
        $this->actingAs($user)->withCookie('cart', $cart->ulid);
        Once::flush();

        Livewire::withCookie('cart', $cart->ulid)->test(\App\Livewire\Cart::class)
            ->call('checkout')
            ->assertDispatched('notify', fn ($name, $params) => $params[0]['message'] === __('product.config_changed'));

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame(1, $this->product->product->fresh()->stock);
    }

    public function test_cart_checkout_rejects_an_option_removed_from_the_product_after_it_was_added(): void
    {
        $user = User::factory()->create();
        $legacyOption = ConfigOption::create([
            'name' => 'Legacy region',
            'type' => 'radio',
        ]);
        $legacyValue = ConfigOption::create([
            'name' => 'Legacy location',
            'parent_id' => $legacyOption->id,
        ]);
        [$cart, $item] = $this->addToCart($this->option->children()->first());
        $item->update(['config_options' => array_merge($item->config_options, [[
            'option_id' => $legacyOption->id,
            'option_name' => $legacyOption->name,
            'option_type' => $legacyOption->type,
            'value' => $legacyValue->id,
            'value_name' => $legacyValue->name,
        ]])]);
        $this->product->product->update(['stock' => 1]);
        $this->actingAs($user)->withCookie('cart', $cart->ulid);
        Once::flush();

        Livewire::withCookie('cart', $cart->ulid)->test(\App\Livewire\Cart::class)
            ->call('checkout')
            ->assertDispatched('notify', fn ($name, $params) => $params[0]['message'] === __('product.config_changed'));

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(1, $this->product->product->fresh()->stock);
    }

    public function test_cart_checkout_rejects_a_plan_that_no_longer_belongs_to_the_product(): void
    {
        $user = User::factory()->create();
        [$cart] = $this->addToCart($this->option->children()->first());
        $otherProduct = $this->createProduct();
        $this->product->plan->priceable_id = $otherProduct->product->id;
        $this->product->plan->save();
        $this->assertFalse($this->product->product->plans()->whereKey($this->product->plan->id)->exists());
        $this->product->product->update(['stock' => 1]);
        $this->actingAs($user)->withCookie('cart', $cart->ulid);
        Once::flush();

        Livewire::withCookie('cart', $cart->ulid)->test(\App\Livewire\Cart::class)
            ->call('checkout')
            ->assertDispatched('notify', fn ($name, $params) => $params[0]['message'] === __('product.config_changed'));

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame(1, $this->product->product->fresh()->stock);
    }
}
