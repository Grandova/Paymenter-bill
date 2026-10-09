<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private $product = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->product = $this->createProduct();
    }

    public function test_product_visible_on_overview_page(): void
    {
        $response = $this->get(route('category.show', [
            $this->product->product->category->slug,
        ]));

        $response->assertStatus(200);
        $response->assertSee($this->product->product->name);
    }

    public function test_product_visible_on_show_page(): void
    {
        $response = $this->get(route('products.show', [
            $this->product->product->category->slug,
            $this->product->product->slug,
        ]));
        $response->assertStatus(200);
        $response->assertSee($this->product->product->name);
    }

    public function test_single_plan_requires_confirmation_before_adding_to_cart(): void
    {
        $response = $this->get(route('products.checkout', [
            $this->product->product->category->slug,
            $this->product->product->slug,
        ]));

        $response->assertOk();
        $this->assertDatabaseCount('cart_items', 0);
        Livewire::test('products.checkout', ['category' => $this->product->product->category, 'product' => $this->product->product->slug])
            ->call('checkout')
            ->assertRedirect(route('cart'));
        $cart = Cart::where('currency_code', 'USD')->first();
        $this->assertNotNull($cart);

        $this->assertNotNull($cart->items()->first());
        Once::flush();

        $response = $this->withCookie('cart', $cart->ulid)->get(route('cart'));
        $response->assertCookie('cart', $cart->ulid);
        $response->assertStatus(200);
        $response->assertSeeText($this->product->product->name);

        $this->assertDatabaseHas('carts', [
            'currency_code' => 'USD',
        ]);

        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $cart->id,
            'product_id' => $this->product->product->id,
            'plan_id' => $this->product->plan->id,
        ]);
    }

    public function test_checkout_page_with_multiple_plans(): void
    {
        // Add plan
        $plan = $this->product->product->plans()->create([
            'name' => 'Test Plan 2',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);
        $plan->prices()->create([
            'price' => 20.00,
            'currency_code' => 'USD',
        ]);

        $response = $this->get(route('products.checkout', [
            $this->product->product->category->slug,
            $this->product->product->slug,
        ]));

        $response->assertStatus(200);
        $response->assertSee($this->product->product->name);
    }

    public function test_checkout_page_with_changed_plan(): void
    {
        // Add plan
        $plan = $this->product->product->plans()->create([
            'name' => 'Test Plan 2',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);
        $plan->prices()->create([
            'price' => 20.00,
            'currency_code' => 'USD',
        ]);

        // Change plan
        Livewire::test('products.checkout', ['category' => $this->product->product->category, 'product' => $this->product->product->slug])
            ->assertSee($this->product->product->name)
            ->assertSee('$10.00')
            ->set('plan_id', $plan->id)
            ->call('updatePricing')
            ->assertSee($this->product->plan->name)
            ->assertSee('$20.00')
            ->call('checkout');

        $this->assertDatabaseHas('carts', [
            'currency_code' => 'USD',
        ]);
    }

    public function test_checkout_page_with_plan_not_in_product(): void
    {
        $plan = $this->product->product->plans()->create([
            'name' => 'Test Plan 2',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);
        $plan->prices()->create([
            'price' => 20.00,
            'currency_code' => 'USD',
        ]);

        // Add plan
        $plan = $this->createProduct()->plan;

        Livewire::test('products.checkout', ['category' => $this->product->product->category, 'product' => $this->product->product->slug])
            ->assertSee($this->product->product->name)
            ->assertSee('$10.00')
            ->set('plan_id', $plan->id)
            ->assertHasErrors(['plan_id' => 'in'])
            ->assertSet('total.price', 10.00);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_location_cards_use_each_products_own_server_and_plan(): void
    {
        $hongKong = $this->product->product;
        $server = Server::create(['name' => 'Hong Kong', 'type' => 'server', 'extension' => 'Pterodactyl', 'enabled' => true]);
        $hongKong->update(['name' => '香港云服务器', 'server_id' => $server->id]);
        $tokyo = $this->createProduct(['category_id' => $hongKong->category_id]);
        $server = Server::create(['name' => 'Tokyo', 'type' => 'server', 'extension' => 'Pterodactyl', 'enabled' => true]);
        $tokyo->product->update(['name' => '东京云服务器', 'server_id' => $server->id]);
        $tokyo->plan->prices()->update(['price' => 25.00]);
        $hidden = $this->createProduct(['category_id' => $hongKong->category_id, 'hidden' => true]);
        $hidden->product->update(['name' => 'Hidden location']);
        $soldOut = $this->createProduct(['category_id' => $hongKong->category_id, 'stock' => 0]);
        $soldOut->product->update(['name' => 'Sold out location']);
        $otherCategory = $this->createProduct();
        $otherCategory->product->update(['name' => 'Unrelated product']);

        $this->get(route('products.checkout', ['category' => $hongKong->category, 'product' => $hongKong]))
            ->assertOk()
            ->assertSee('香港云服务器')
            ->assertSee('东京云服务器')
            ->assertSee(route('products.checkout', ['category' => $hongKong->category, 'product' => $tokyo->product]))
            ->assertDontSee('Hidden location')
            ->assertDontSee('Unrelated product')
            ->assertDontSee(route('products.checkout', ['category' => $hongKong->category, 'product' => $soldOut->product]));
        $this->assertDatabaseCount('cart_items', 0);

        Once::flush();
        Livewire::test('products.checkout', ['category' => $hongKong->category, 'product' => $tokyo->product->slug])
            ->assertSet('plan_id', $tokyo->plan->id)
            ->assertSet('total.price', 25.00)
            ->call('checkout')
            ->assertRedirect(route('cart'));
        $item = Cart::first()->items()->sole();
        $this->assertSame($tokyo->product->id, $item->product_id);
        $this->assertSame($server->id, $item->product->server_id);
        $this->assertSame($tokyo->plan->id, $item->plan_id);
    }
}
