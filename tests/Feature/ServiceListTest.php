<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ServiceListTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_and_status_filters_only_show_the_current_users_services(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $product->product->update(['name' => 'Hong Kong Cloud']);
        $active = Service::factory()->create(['user_id' => $user->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => 'active', 'label' => 'Website server']);
        $pending = Service::factory()->create(['user_id' => $user->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => 'pending', 'label' => 'Database server']);
        Service::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => 'active', 'label' => 'Private server']);

        $this->actingAs($user)->withSession($this->loginUser($user));
        Livewire::test('services.index')
            ->assertSee('Website server')
            ->assertSee('Database server')
            ->assertDontSee('Private server')
            ->set('status', 'active')
            ->assertViewHas('services', fn ($services) => $services->pluck('id')->all() === [$active->id])
            ->set('search', 'Database')
            ->assertViewHas('services', fn ($services) => $services->isEmpty())
            ->set('status', '')
            ->assertViewHas('services', fn ($services) => $services->pluck('id')->all() === [$pending->id])
            ->set('search', 'Hong Kong')
            ->assertViewHas('services', fn ($services) => $services->total() === 2)
            ->call('gotoPage', 2)
            ->set('search', 'Website')
            ->assertSet('paginators.page', 1)
            ->assertSee('Website server');

        $this->get(route('dashboard'))->assertOk()->assertSee('Website server')->assertDontSee('Private server');
    }
}
