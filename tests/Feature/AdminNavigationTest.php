<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceTransactions\InvoiceTransactionResource;
use App\Admin\Resources\ProductResource;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_loads_livewire_script_through_application_route(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        $this->get('/admin')->assertOk()->assertSee('/paymenter/livewire-script?id=', false);
        $this->get('/paymenter/livewire-script')->assertOk()->assertHeader('content-type', 'application/javascript; charset=utf-8');
    }

    public function test_admin_navigation_groups_business_pages_and_links_to_existing_payment_transactions(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        app()->setLocale('zh');

        $groups = collect(Filament::getNavigation())->keyBy(fn ($group) => $group->getLabel());

        $this->assertSame([
            __('Workbench'), __('Business management'), __('Customers and finance'), __('System management'),
        ], $groups->keys()->all());
        $business = collect($groups[__('Business management')]->getItems());
        $this->assertSame([
            __('Product management'), __('Product groups'), __('Automation interfaces'), __('Instance management'), __('Order management'),
        ], $business->take(5)->map(fn ($item) => $item->getLabel())->all());
        $this->assertSame(ProductResource::getUrl(), $business->first()->getUrl());
        $payments = collect($groups[__('Customers and finance')]->getItems())
            ->first(fn ($item) => $item->getLabel() === __('Payment transactions'));
        $this->assertNotNull($payments);
        $this->assertSame(InvoiceTransactionResource::getUrl(), $payments->getUrl());
    }

    public function test_product_operator_cannot_see_or_open_payment_transactions(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $role = Role::where('name', 'admin')->first();
        $role->update(['permissions' => ['admin.products.viewAny']]);
        $admin = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        $items = collect(Filament::getNavigation())->flatMap(fn ($group) => $group->getItems());

        $this->assertTrue($items->contains(fn ($item) => $item->getUrl() === ProductResource::getUrl()));
        $this->assertFalse($items->contains(fn ($item) => $item->getUrl() === InvoiceTransactionResource::getUrl()));
        $this->get(InvoiceTransactionResource::getUrl())->assertForbidden();
    }
}
