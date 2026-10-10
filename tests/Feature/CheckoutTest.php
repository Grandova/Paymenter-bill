<?php

namespace Tests\Feature;

use App\Classes\Cart as CartHelper;
use App\Exceptions\DisplayException;
use App\Livewire\Products\Checkout;
use App\Models\Cart;
use App\Models\ConfigOption;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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
        config(['settings.default_currency' => 'CNY']);
        session(['currency' => 'CNY']);
        $this->product = $this->createProduct();
    }

    public function test_product_visible_on_overview_page(): void
    {
        $response = $this->get(route('category.show', [
            $this->product->product->category->slug,
        ]));

        $response->assertStatus(200);
        $response->assertSee($this->product->product->name);
        $response->assertSeeText('每个月');
    }

    public function test_product_visible_on_show_page(): void
    {
        $response = $this->get(route('products.show', [
            $this->product->product->category->slug,
            $this->product->product->slug,
        ]));
        $response->assertStatus(200);
        $response->assertSee($this->product->product->name);
        $response->assertSeeText('每个月');
    }

    public function test_product_detail_lists_billing_plans_and_links_to_the_selected_cycle(): void
    {
        $yearlyPlan = $this->product->product->plans()->create([
            'name' => 'Annual',
            'type' => 'recurring',
            'billing_period' => 1,
            'billing_unit' => 'year',
        ]);
        $yearlyPlan->prices()->create(['price' => 100, 'setup_fee' => 15, 'currency_code' => 'CNY']);

        $response = $this->get(route('products.show', [
            $this->product->product->category->slug,
            $this->product->product->slug,
        ]));

        $response->assertOk()
            ->assertSeeText('可选计费方案')
            ->assertSeeText('Annual')
            ->assertSeeText('每年')
            ->assertSeeText('首期另收初装费 ¥15.00')
            ->assertSee(route('products.checkout', [
                'category' => $this->product->product->category->slug,
                'product' => $this->product->product->slug,
                'plan' => $yearlyPlan->id,
            ]), false);
    }

    public function test_recurring_product_pages_disclose_the_first_payment_setup_fee(): void
    {
        $this->product->plan->prices()->update(['setup_fee' => 25]);

        $category = $this->get(route('category.show', [$this->product->product->category->slug]));
        $category->assertOk()->assertSeeText('首期另收初装费 ¥25.00');

        $product = $this->get(route('products.show', [
            $this->product->product->category->slug,
            $this->product->product->slug,
        ]));
        $product->assertOk()->assertSeeText('首期另收初装费 ¥25.00');
    }

    public function test_checkout_discloses_the_initial_setup_fee(): void
    {
        $this->product->plan->prices()->update(['setup_fee' => 25]);

        Livewire::test('products.checkout', ['category' => $this->product->product->category, 'product' => $this->product->product->slug])
            ->assertSeeText('首期另收初装费 ¥25.00');
    }

    public function test_checkout_bills_setup_fee_once_without_adding_it_to_the_recurring_service_price(): void
    {
        $this->product->plan->prices()->update(['setup_fee' => 25]);
        $user = User::factory()->create();
        $cart = Cart::create(['user_id' => $user->id, 'currency_code' => 'CNY']);
        $cart->items()->create([
            'product_id' => $this->product->product->id,
            'plan_id' => $this->product->plan->id,
            'quantity' => 1,
            'config_options' => [],
            'checkout_config' => [],
        ]);
        $this->actingAs($user);
        Once::flush();

        Livewire::withCookie('cart', $cart->ulid)->test(\App\Livewire\Cart::class)
            ->call('checkout')
            ->assertRedirect();

        $service = Service::where('user_id', $user->id)->sole();
        $this->assertSame(10.0, (float) $service->price);
        $this->assertDatabaseHas('invoice_items', [
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => 35.00,
            'quantity' => 1,
        ]);
    }

    public function test_product_options_show_recurring_price_separately_from_the_setup_fee(): void
    {
        $option = ConfigOption::create(['name' => 'Memory', 'type' => 'select']);
        $option->products()->attach($this->product->product->id);
        $value = ConfigOption::create(['name' => '2 GB', 'parent_id' => $option->id]);
        $plan = $value->plans()->create([
            'name' => 'Monthly price',
            'type' => 'recurring',
            'billing_period' => 1,
            'billing_unit' => 'month',
        ]);
        $plan->prices()->create(['price' => 3, 'setup_fee' => 2, 'currency_code' => 'CNY']);

        Livewire::test('products.checkout', ['category' => $this->product->product->category, 'product' => $this->product->product->slug])
            ->assertSeeText('每个月 ¥3.00')
            ->assertSeeText('首期另收初装费 ¥2.00');
    }

    public function test_product_starting_price_uses_the_cheapest_available_plan(): void
    {
        $cheaperPlan = $this->product->product->plans()->create([
            'name' => 'Cheaper Plan',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
            'sort' => 1,
        ]);
        $cheaperPlan->prices()->create(['price' => 5.00, 'currency_code' => 'CNY']);

        $this->assertSame('¥5.00', $this->product->product->price(currency: 'CNY')->formatted->price);

        $response = $this->get(route('category.show', [$this->product->product->category->slug]));

        $response->assertOk()->assertSeeText('¥5.00');
    }

    public function test_product_catalog_does_not_query_currency_for_each_plan_price(): void
    {
        foreach ([20, 30] as $price) {
            $plan = $this->product->product->plans()->create([
                'name' => 'Plan ' . $price,
                'billing_unit' => 'month',
                'billing_period' => 1,
                'type' => 'recurring',
            ]);
            $plan->prices()->create(['price' => $price, 'currency_code' => 'CNY']);
        }

        $currencyLookups = [];
        DB::listen(function (QueryExecuted $query) use (&$currencyLookups) {
            if (preg_match('/from [`\"]?currencies[`\"]?.*where [`\"]?currencies[`\"]?\.[`\"]?code[`\"]? = \?/i', $query->sql)) {
                $currencyLookups[] = $query->sql;
            }
        });

        Livewire::test('products.index', ['category' => $this->product->product->category])
            ->assertSeeText($this->product->product->name);

        $this->assertSame([], $currencyLookups);
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
        $cart = Cart::where('currency_code', 'CNY')->first();
        $this->assertNotNull($cart);

        $this->assertNotNull($cart->items()->first());
        Once::flush();

        $response = $this->withCookie('cart', $cart->ulid)->get(route('cart'));
        $response->assertCookie('cart', $cart->ulid);
        $response->assertStatus(200);
        $response->assertSeeText($this->product->product->name);
        $response->assertSeeText('每个月 ¥10.00');

        $this->assertDatabaseHas('carts', [
            'currency_code' => 'CNY',
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
            'currency_code' => 'CNY',
        ]);

        $response = $this->get(route('products.checkout', [
            $this->product->product->category->slug,
            $this->product->product->slug,
        ]));

        $response->assertStatus(200);
        $response->assertSee($this->product->product->name);
    }

    public function test_checkout_defaults_to_a_plan_available_in_the_current_currency(): void
    {
        $this->product->plan->prices()->delete();
        $availablePlan = $this->product->product->plans()->create([
            'name' => 'CNY Plan',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);
        $availablePlan->prices()->create([
            'price' => 20.00,
            'currency_code' => 'CNY',
        ]);

        Livewire::test('products.checkout', ['category' => $this->product->product->category, 'product' => $this->product->product->slug])
            ->assertSet('plan_id', $availablePlan->id)
            ->assertSet('total.price', 20.00);
    }

    public function test_recurring_plan_shows_billing_period_and_next_payment(): void
    {
        $this->product->plan->update(['billing_period' => 3]);

        Livewire::test('products.checkout', ['category' => $this->product->product->category, 'product' => $this->product->product->slug])
            ->assertSeeText('每3个月')
            ->assertSeeText('之后每 3 个月')
            ->assertSeeText('¥10.00');
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
            'currency_code' => 'CNY',
        ]);

        // Change plan
        Livewire::test('products.checkout', ['category' => $this->product->product->category, 'product' => $this->product->product->slug])
            ->assertSee($this->product->product->name)
            ->assertSee('¥10.00')
            ->set('plan_id', $plan->id)
            ->call('updatePricing')
            ->assertSee($this->product->plan->name)
            ->assertSee('¥20.00')
            ->call('checkout');

        $this->assertDatabaseHas('carts', [
            'currency_code' => 'CNY',
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
            'currency_code' => 'CNY',
        ]);

        // Add plan
        $plan = $this->createProduct()->plan;

        Livewire::test('products.checkout', ['category' => $this->product->product->category, 'product' => $this->product->product->slug])
            ->assertSee($this->product->product->name)
            ->assertSee('¥10.00')
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
        $this->get(route('products.checkout', ['category' => $hongKong->category, 'product' => $hidden->product]))
            ->assertNotFound();
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

    public function test_per_user_limit_counts_combined_service_quantity(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct(['per_user_limit' => 3, 'allow_quantity' => 'combined']);
        Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'quantity' => 2,
            'status' => Service::STATUS_ACTIVE,
        ]);
        $cart = Cart::create(['user_id' => $user->id, 'currency_code' => 'CNY']);
        $cart->items()->create([
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'quantity' => 2,
            'config_options' => [],
            'checkout_config' => [],
        ]);
        $this->actingAs($user)->withCookie('cart', $cart->ulid);
        Once::flush();

        Livewire::test(\App\Livewire\Cart::class)
            ->call('checkout')
            ->assertDispatched('notify');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(1, $cart->items()->count());
    }

    public function test_cancelled_services_do_not_consume_the_per_user_purchase_limit(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct(['per_user_limit' => 1]);
        Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_CANCELLED,
        ]);
        $cart = Cart::create(['user_id' => $user->id, 'currency_code' => 'CNY']);
        $cart->items()->create([
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'quantity' => 1,
            'config_options' => [],
            'checkout_config' => [],
        ]);
        $this->assertSame(0, (int) $user->services()->where('product_id', $product->product->id)->where('status', '!=', Service::STATUS_CANCELLED)->sum('quantity'));
        $this->actingAs($user);
        Once::flush();

        Livewire::withCookie('cart', $cart->ulid)->test(\App\Livewire\Cart::class)
            ->call('checkout');

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(2, $user->services()->where('product_id', $product->product->id)->count());
    }

    public function test_cancelled_unpaid_services_release_coupon_usage(): void
    {
        $user = User::factory()->create();
        $coupon = Coupon::create([
            'type' => 'percentage',
            'applies_to' => 'all',
            'code' => 'RETRY-COUPON',
            'value' => 10,
            'max_uses' => 1,
            'max_uses_per_user' => 1,
        ]);
        Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $this->product->product->id,
            'plan_id' => $this->product->plan->id,
            'coupon_id' => $coupon->id,
            'status' => Service::STATUS_CANCELLED,
        ]);
        $this->actingAs($user);

        $this->assertSame(0, $coupon->usageCount());
        $this->assertSame(0, $coupon->usageCount($user->id));
        $this->assertSame($coupon->id, CartHelper::validateCoupon($coupon->code)->id);
    }

    public function test_product_coupon_is_only_attached_to_eligible_services(): void
    {
        $user = User::factory()->create();
        $otherProduct = $this->createProduct();
        $coupon = Coupon::create([
            'type' => 'percentage',
            'applies_to' => 'all',
            'code' => 'ELIGIBLE-ONLY',
            'value' => 10,
        ]);
        $coupon->products()->attach($this->product->product->id);
        $cart = Cart::create([
            'user_id' => $user->id,
            'currency_code' => 'CNY',
            'coupon_id' => $coupon->id,
        ]);
        foreach ([$this->product, $otherProduct] as $product) {
            $cart->items()->create([
                'product_id' => $product->product->id,
                'plan_id' => $product->plan->id,
                'quantity' => 1,
                'config_options' => [],
                'checkout_config' => [],
            ]);
        }
        $this->actingAs($user);
        Once::flush();

        Livewire::withCookie('cart', $cart->ulid)->test(\App\Livewire\Cart::class)
            ->call('checkout')
            ->assertRedirect();

        $services = $user->services()->get()->keyBy('product_id');
        $this->assertSame($coupon->id, $services[$this->product->product->id]->coupon_id);
        $this->assertNull($services[$otherProduct->product->id]->coupon_id);
        $this->assertSame(1, $coupon->usageCount());
    }

    public function test_cancelled_paid_service_still_consumes_coupon_usage(): void
    {
        $user = User::factory()->create();
        $coupon = Coupon::create([
            'type' => 'percentage',
            'applies_to' => 'all',
            'code' => 'PAID-COUPON',
            'value' => 10,
            'max_uses' => 1,
            'max_uses_per_user' => 1,
        ]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $this->product->product->id,
            'plan_id' => $this->product->plan->id,
            'coupon_id' => $coupon->id,
            'status' => Service::STATUS_CANCELLED,
        ]);
        $invoice = Invoice::create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PAID,
            'currency_code' => 'CNY',
        ]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'description' => 'Paid service',
            'price' => 10,
            'quantity' => 1,
        ]);
        $this->actingAs($user);

        $this->assertSame(1, $coupon->usageCount());
        $this->assertTrue($coupon->hasExceededMaxUsesPerUser($user->id));
        $this->expectException(DisplayException::class);
        CartHelper::validateCoupon($coupon->code);
    }

    public function test_cart_rejects_fractional_quantity_updates(): void
    {
        $product = $this->createProduct(['allow_quantity' => 'combined']);
        $cart = Cart::create(['currency_code' => 'CNY']);
        $item = $cart->items()->create([
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'quantity' => 1,
            'config_options' => [],
            'checkout_config' => [],
        ]);
        Once::flush();

        Livewire::withCookie('cart', $cart->ulid)
            ->test(\App\Livewire\Cart::class)
            ->call('updateQuantity', $item->id, 1.5)
            ->assertDispatched('notify');

        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'quantity' => 1]);
    }

    public function test_checkout_refreshes_the_cart_amount_before_creating_an_invoice(): void
    {
        $user = User::factory()->create();
        $this->product->plan->prices()->update(['price' => 0]);

        $cart = Cart::create(['user_id' => $user->id, 'currency_code' => 'CNY']);
        $cart->items()->create([
            'product_id' => $this->product->product->id,
            'plan_id' => $this->product->plan->id,
            'quantity' => 1,
            'config_options' => [],
            'checkout_config' => [],
        ]);

        $this->actingAs($user);
        Once::flush();
        $checkout = Livewire::withCookie('cart', $cart->ulid)->test(\App\Livewire\Cart::class)
            ->assertSet('total.price', 0.00);

        $this->product->plan->prices()->update(['price' => 15.00]);
        Once::flush();

        $checkout->call('checkout')->assertRedirect();

        $this->assertDatabaseHas('invoices', [
            'user_id' => $user->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('invoice_items', [
            'price' => 15.00,
            'quantity' => 1,
        ]);
    }

    public function test_checkout_uses_the_price_loaded_after_the_product_lock(): void
    {
        $user = User::factory()->create();
        $cart = Cart::create(['user_id' => $user->id, 'currency_code' => 'CNY']);
        $cart->items()->create([
            'product_id' => $this->product->product->id,
            'plan_id' => $this->product->plan->id,
            'quantity' => 1,
            'config_options' => [],
            'checkout_config' => [],
        ]);
        $this->actingAs($user);
        Once::flush();
        $checkout = Livewire::withCookie('cart', $cart->ulid)->test(\App\Livewire\Cart::class)
            ->assertSet('total.price', 10.00);

        $changed = false;
        DB::listen(function (QueryExecuted $query) use (&$changed) {
            if (!$changed && preg_match('/from\s+[`"]?products[`"]?\s+where\s+[`"]?.*id[`"]?\s*=\s*\?/i', $query->sql)) {
                DB::table('prices')->where('plan_id', $this->product->plan->id)->update(['price' => 0]);
                $changed = true;
            }
        });

        $result = $checkout->call('checkout');
        $this->assertTrue($changed);
        $result->assertRedirect();
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(Service::STATUS_ACTIVE, Service::where('user_id', $user->id)->sole()->status);
    }

    public function test_checkout_creates_an_invoice_when_the_locked_price_increases_from_free(): void
    {
        $user = User::factory()->create();
        $this->product->plan->prices()->update(['price' => 0]);
        $cart = Cart::create(['user_id' => $user->id, 'currency_code' => 'CNY']);
        $cart->items()->create([
            'product_id' => $this->product->product->id,
            'plan_id' => $this->product->plan->id,
            'quantity' => 1,
            'config_options' => [],
            'checkout_config' => [],
        ]);
        $this->actingAs($user);
        Once::flush();
        $checkout = Livewire::withCookie('cart', $cart->ulid)->test(\App\Livewire\Cart::class)
            ->assertSet('total.price', 0.00);

        $changed = false;
        DB::listen(function (QueryExecuted $query) use (&$changed) {
            if (!$changed && preg_match('/from\s+[`"]?products[`"]?\s+where\s+[`"]?.*id[`"]?\s*=\s*\?/i', $query->sql)) {
                DB::table('prices')->where('plan_id', $this->product->plan->id)->update(['price' => 15]);
                $changed = true;
            }
        });

        $result = $checkout->call('checkout');
        $this->assertTrue($changed);
        $result->assertRedirect();
        $this->assertDatabaseHas('invoices', [
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('invoice_items', [
            'price' => 15,
            'quantity' => 1,
        ]);
    }

    public function test_checkout_uses_the_latest_coupon_discount(): void
    {
        $user = User::factory()->create();
        $coupon = Coupon::create([
            'type' => 'percentage',
            'applies_to' => 'all',
            'code' => 'UPDATED-COUPON',
            'value' => 10,
        ]);
        $cart = Cart::create([
            'user_id' => $user->id,
            'currency_code' => 'CNY',
            'coupon_id' => $coupon->id,
        ]);
        $cart->items()->create([
            'product_id' => $this->product->product->id,
            'plan_id' => $this->product->plan->id,
            'quantity' => 1,
            'config_options' => [],
            'checkout_config' => [],
        ]);
        $this->actingAs($user);
        Once::flush();
        $checkout = Livewire::withCookie('cart', $cart->ulid)->test(\App\Livewire\Cart::class)
            ->assertSet('total.price', 9.00);

        $coupon->update(['value' => 50]);
        Once::flush();

        $checkout->call('checkout')->assertRedirect();

        $this->assertDatabaseHas('invoice_items', [
            'price' => 5,
            'quantity' => 1,
        ]);
    }

    public function test_disabled_product_cannot_be_purchased_from_an_existing_cart(): void
    {
        $user = User::factory()->create();
        $product = $this->product->product;
        $product->update(['hidden' => true]);
        $cart = Cart::create(['user_id' => $user->id, 'currency_code' => 'CNY']);
        $cart->items()->create([
            'product_id' => $product->id,
            'plan_id' => $this->product->plan->id,
            'quantity' => 1,
            'config_options' => [],
            'checkout_config' => [],
        ]);
        $this->actingAs($user)->withCookie('cart', $cart->ulid);
        Once::flush();

        Livewire::test(\App\Livewire\Cart::class)
            ->call('checkout')
            ->assertDispatched('notify');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(1, $cart->items()->count());
    }

    public function test_numeric_checkout_options_are_validated_with_server_side_bounds(): void
    {
        $checkout = new class extends Checkout
        {
            public array $testConfig = [];

            public function getCheckoutConfig()
            {
                return $this->testConfig;
            }
        };
        $checkout->product = $this->product->product;
        $checkout->testConfig = [
            ['name' => 'cpu', 'type' => 'number', 'required' => true],
            ['name' => 'io', 'type' => 'number', 'required' => true, 'min_value' => 10, 'max_value' => 1000],
            ['name' => 'optional', 'type' => 'number', 'required' => false],
        ];

        $validator = Validator::make([
            'plan_id' => $this->product->plan->id,
            'checkoutConfig' => ['cpu' => 'not-a-number', 'io' => 1001, 'optional' => null],
        ], $checkout->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('Numeric', $validator->failed()['checkoutConfig.cpu']);
        $this->assertArrayHasKey('Max', $validator->failed()['checkoutConfig.io']);
        $this->assertArrayNotHasKey('checkoutConfig.optional', $validator->failed());
    }

    public function test_numeric_product_config_options_reject_non_numeric_values(): void
    {
        $option = ConfigOption::create([
            'name' => 'Memory',
            'type' => 'number',
        ]);
        $option->products()->attach($this->product->product->id);

        Livewire::test('products.checkout', [
            'category' => $this->product->product->category,
            'product' => $this->product->product->slug,
        ])
            ->set('configOptions.' . $option->id, 'not-a-number')
            ->call('checkout')
            ->assertHasErrors(['configOptions.' . $option->id => 'numeric']);
    }

    public function test_checkout_rejects_a_config_value_without_a_price_in_the_selected_currency(): void
    {
        Currency::create(['code' => 'EUR', 'name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $option = ConfigOption::create(['name' => 'Region', 'type' => 'select']);
        $option->products()->attach($this->product->product->id);
        $value = ConfigOption::create(['name' => 'Europe', 'type' => 'option', 'parent_id' => $option->id]);
        $plan = $value->plans()->create(['name' => 'Monthly', 'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'month']);
        $plan->prices()->create(['price' => 5, 'currency_code' => 'EUR']);

        Livewire::test('products.checkout', [
            'category' => $this->product->product->category,
            'product' => $this->product->product->slug,
        ])
            ->set('configOptions.' . $option->id, $value->id)
            ->call('checkout')
            ->assertHasErrors(['configOptions.' . $option->id]);
    }
}
