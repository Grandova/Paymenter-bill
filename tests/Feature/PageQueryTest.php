<?php

namespace Tests\Feature;

use App\Admin\Resources\OrderResource\Pages\ListOrders;
use App\Livewire\Components\Cart as CartComponent;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class PageQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_badge_counts_items_without_loading_product_prices(): void
    {
        $product = $this->createProduct();
        $cart = Cart::create(['currency_code' => 'CNY']);
        $cart->items()->create([
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'quantity' => 3,
        ]);
        $priceQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$priceQueries) {
            if (preg_match('/from ["`](plans|prices|config_options)["`]/', $query->sql)) {
                $priceQueries++;
            }
        });

        $component = Livewire::withCookie('cart', $cart->ulid)->test(CartComponent::class)
            ->assertSet('cartCount', 1);
        $cart->items()->delete();
        $component->dispatch('cartUpdated')->assertSet('cartCount', 0);

        $this->assertSame(0, $priceQueries);
    }

    public function test_cart_badge_ignores_missing_carts(): void
    {
        Livewire::test(CartComponent::class)->assertSet('cartCount', 0);
        Livewire::withCookie('cart', 'missing-cart')->test(CartComponent::class)->assertSet('cartCount', 0);
    }

    public function test_home_and_catalog_do_not_load_checkout_configuration(): void
    {
        $product = $this->createProduct();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->get(route('home'))->assertOk()->assertSee($product->product->category->name);
        $this->assertSame([], preg_grep('/from ["`](plans|prices|config_options)["`]/', $queries));

        $queries = [];
        $this->get(route('category.show', $product->product->category))
            ->assertOk()->assertSee($product->product->name)->assertSee('10.00');
        $this->assertSame([], preg_grep('/from ["`]config_options["`]/', $queries));
    }

    public function test_order_list_loads_service_totals_in_one_query(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $product = $this->createProduct();
        $orders = collect();
        for ($i = 0; $i < 10; $i++) {
            $order = Order::withoutEvents(fn () => Order::create(['user_id' => $admin->id, 'currency_code' => 'CNY']));
            Service::factory()->create([
                'order_id' => $order->id,
                'user_id' => $admin->id,
                'product_id' => $product->product->id,
                'plan_id' => $product->plan->id,
                'price' => 12.5,
                'quantity' => 2,
            ]);
            $orders->push($order);
        }
        $serviceQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$serviceQueries) {
            if (preg_match('/select \* from ["`]services["`]/', $query->sql)) {
                $serviceQueries++;
            }
        });

        $this->actingAs($admin)->withSession($this->loginUser($admin));
        Livewire::test(ListOrders::class)->assertCanSeeTableRecords($orders)->assertSee('25.00');

        $this->assertSame(1, $serviceQueries);
    }
}
