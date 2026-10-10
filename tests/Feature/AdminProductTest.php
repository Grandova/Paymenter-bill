<?php

namespace Tests\Feature;

use App\Admin\Resources\ProductResource\Pages\CreateProduct;
use App\Admin\Resources\ProductResource\Pages\EditProduct;
use App\Admin\Resources\ProductResource\Pages\ListProducts;
use App\Models\Category;
use App\Models\ConfigOption;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AdminProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_list_shows_sales_and_provisioning_details(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $category = Category::factory()->create();
        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Tokyo VPS',
            'slug' => 'tokyo-vps',
            'sort' => 10,
        ]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));
        app()->setLocale('zh');

        Livewire::test(ListProducts::class)
            ->assertSeeText('Tokyo VPS')
            ->assertSeeText('不限量')
            ->assertSeeText('销售中')
            ->assertSeeText('排序');
    }

    public function test_admin_can_create_an_hourly_server_product_plan(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $category = Category::factory()->create();
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Hourly VPS',
                'slug' => 'hourly-vps',
                'sort' => 10,
                'allow_quantity' => 'separated',
                'category_id' => $category->id,
                'plan' => [[
                    'name' => '6 hours',
                    'type' => 'recurring',
                    'billing_period' => 6,
                    'billing_unit' => 'hour',
                    'pricing' => [[
                        'currency_code' => 'CNY',
                        'price' => 2,
                    ]],
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::where('slug', 'hourly-vps')->sole();
        $this->assertSame('hour', $product->plans()->sole()->billing_unit);
        $this->assertSame(6, $product->plans()->sole()->billing_period);
        $this->assertSame(10, $product->sort);
    }

    public function test_admin_can_assign_sales_options_while_creating_a_product(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $category = Category::factory()->create();
        $option = ConfigOption::create(['name' => 'Memory']);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Configurable VPS',
                'slug' => 'configurable-vps',
                'sort' => 10,
                'allow_quantity' => 'separated',
                'category_id' => $category->id,
                'configurableOptions' => [$option->id],
                'plan' => [[
                    'name' => 'Monthly',
                    'type' => 'recurring',
                    'billing_period' => 1,
                    'billing_unit' => 'month',
                    'pricing' => [[
                        'currency_code' => 'CNY',
                        'price' => 20,
                    ]],
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::where('slug', 'configurable-vps')->sole();
        $this->assertTrue($product->configurableOptions()->whereKey($option->id)->exists());
    }

    public function test_failed_extension_config_does_not_partially_save_a_product(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $category = Category::factory()->create();
        $server = Server::create(['name' => 'Pterodactyl', 'type' => 'server', 'extension' => 'Pterodactyl', 'enabled' => true]);
        $server->settings()->createMany([
            ['key' => 'host', 'value' => 'https://panel.example', 'type' => 'string'],
            ['key' => 'api_key', 'value' => 'test-key', 'type' => 'string'],
        ]);
        Http::fake([
            'https://panel.example/api/application/nodes' => Http::response(['errors' => [['detail' => 'Unavailable']]], 503),
        ]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        $create = new \ReflectionMethod(CreateProduct::class, 'handleRecordCreation');
        try {
            $create->invoke(Livewire::test(CreateProduct::class)->instance(), [
                'name' => 'Failed config VPS',
                'slug' => 'failed-config-vps',
                'category_id' => $category->id,
                'server_id' => $server->id,
                'settings' => ['memory' => 512],
            ]);
        } catch (\Throwable) {
        }

        $this->assertDatabaseMissing('products', ['slug' => 'failed-config-vps']);
        Http::assertSent(fn ($request) => $request->url() === 'https://panel.example/api/application/nodes');

        $product = Product::factory()->create(['name' => 'Existing VPS', 'server_id' => $server->id]);
        $update = new \ReflectionMethod(EditProduct::class, 'handleRecordUpdate');
        try {
            $update->invoke(Livewire::test(EditProduct::class)->instance(), $product, [
                'name' => 'Changed VPS',
                'slug' => $product->slug,
                'server_id' => $server->id,
                'settings' => ['memory' => 512],
            ]);
        } catch (\Throwable) {
        }

        $this->assertSame('Existing VPS', $product->fresh()->name);
    }

    public function test_admin_can_bulk_publish_and_hide_products(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $published = Product::factory()->create(['hidden' => true]);
        $hidden = Product::factory()->create(['hidden' => false]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('publish', [$published])
            ->assertHasNoTableBulkActionErrors();
        Livewire::test(ListProducts::class)
            ->callTableBulkAction('hide', [$hidden])
            ->assertHasNoTableBulkActionErrors();

        $this->assertFalse($published->fresh()->hidden);
        $this->assertTrue($hidden->fresh()->hidden);
    }

    public function test_read_only_product_operator_cannot_publish_or_hide_products(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $role = Role::where('name', 'admin')->first();
        $role->update(['permissions' => ['admin.products.viewAny']]);
        $admin = User::factory()->create(['role_id' => $role->id]);
        $product = Product::factory()->create(['hidden' => true]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(ListProducts::class)
            ->assertTableBulkActionHidden('publish')
            ->assertTableBulkActionHidden('hide')
            ->mountTableBulkAction('publish', [$product])
            ->assertActionNotMounted('publish')
            ->callMountedAction();

        $this->assertTrue($product->fresh()->hidden);
    }

    public function test_edit_permission_does_not_allow_creating_a_product_copy(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $role = Role::where('name', 'admin')->first();
        $role->update(['permissions' => ['admin.products.viewAny', 'admin.products.update']]);
        $admin = User::factory()->create(['role_id' => $role->id]);
        $product = Product::factory()->create();
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->assertActionHidden('duplicate')
            ->mountAction('duplicate')
            ->assertActionNotMounted('duplicate')
            ->callMountedAction();

        $this->assertDatabaseCount('products', 1);
    }

    public function test_admin_can_apply_a_quarterly_billing_cycle_without_changing_plan_storage(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $product = $this->createProduct(['sort' => 0]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        $page = Livewire::test(EditProduct::class, ['record' => $product->product->id]);
        $key = array_key_first($page->get('data.plan'));
        $page->set('data.plan.' . $key . '.billing_cycle', 'month:3')
            ->assertSet('data.plan.' . $key . '.billing_period', 3)
            ->assertSet('data.plan.' . $key . '.billing_unit', 'month')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(3, $product->plan->fresh()->billing_period);
        $this->assertSame('month', $product->plan->fresh()->billing_unit);
    }

    public function test_product_editor_cannot_create_a_category_without_category_permission(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $role = Role::where('name', 'admin')->first();
        $role->update(['permissions' => ['admin.products.viewAny', 'admin.products.create']]);
        $admin = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        $action = TestAction::make('createOption')->schemaComponent('category_id');
        Livewire::test(CreateProduct::class)
            ->assertActionHidden($action)
            ->mountAction($action)
            ->assertActionNotMounted($action);

        $this->assertDatabaseCount('categories', 0);
    }
}
