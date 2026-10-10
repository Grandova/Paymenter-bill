<?php

namespace Tests\Feature;

use App\Admin\Resources\UserResource\Pages\ListUsers;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_filter_users_by_email_verification(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $verified = User::factory()->create(['first_name' => 'Verified customer']);
        $unverified = User::factory()->unverified()->create(['first_name' => 'Unverified customer']);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(ListUsers::class)
            ->filterTable('email_verified', true)
            ->assertCanSeeTableRecords([$verified])
            ->assertCanNotSeeTableRecords([$unverified]);
    }

    public function test_user_list_shows_active_services_and_pending_invoices(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $customer = User::factory()->create();
        $product = $this->createProduct();
        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
        ]);
        Invoice::factory()->create(['user_id' => $customer->id, 'status' => Invoice::STATUS_PENDING]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(ListUsers::class)
            ->assertTableColumnStateSet('active_services_count', 1, $customer)
            ->assertTableColumnStateSet('pending_invoices_count', 1, $customer);
    }
}
