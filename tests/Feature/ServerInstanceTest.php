<?php

namespace Tests\Feature;

use App\Admin\Resources\ServerInstanceResource;
use App\Admin\Resources\ServerInstanceResource\Pages\ListServerInstances;
use App\Admin\Resources\ServiceResource;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ServerInstanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_overview_filters_real_services_without_contacting_providers(): void
    {
        Http::preventStrayRequests();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $this->actingAs($user)->withSession($this->loginUser($user));

        $servers = [];
        $services = [];
        foreach (['Hong Kong', 'Tokyo'] as $name) {
            $servers[] = $server = Server::create(['name' => $name, 'type' => 'server', 'extension' => 'Pterodactyl', 'enabled' => true]);
            $product = $this->createProduct(['server_id' => $server->id]);
            $services[] = Service::factory()->create(['user_id' => $user->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => 'active', 'label' => $name . ' instance']);
        }
        $product = $this->createProduct();
        $unassigned = Service::factory()->create(['user_id' => $user->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => 'active']);

        $this->get(ServerInstanceResource::getUrl())->assertOk();
        Livewire::test(ListServerInstances::class)
            ->assertCanSeeTableRecords($services)
            ->assertCanNotSeeTableRecords([$unassigned])
            ->assertSee(ServiceResource::getUrl('edit', ['record' => $services[0]]))
            ->filterTable('server', $servers[1]->id)
            ->assertCanSeeTableRecords([$services[1]])
            ->assertCanNotSeeTableRecords([$services[0], $unassigned])
            ->searchTable('Hong Kong')
            ->assertCanNotSeeTableRecords($services)
            ->resetTableFilters()
            ->assertCanSeeTableRecords([$services[0]])
            ->assertCanNotSeeTableRecords([$services[1], $unassigned]);
        Http::assertNothingSent();
    }

    public function test_overview_requires_service_management_permission(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $role = Role::create(['name' => 'No services', 'permissions' => ['admin.products.viewAny']]);
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user)->withSession($this->loginUser($user))
            ->get(ServerInstanceResource::getUrl())->assertForbidden();
    }
}
