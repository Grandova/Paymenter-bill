<?php

namespace Tests\Feature;

use App\Admin\Resources\ProductResource;
use App\Admin\Resources\ServerResource\Pages\CreateServer;
use App\Admin\Resources\ServerResource\Pages\ListServers;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_interface_list_shows_assigned_products_and_connection_action(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $server = Server::create([
            'name' => 'Tokyo Pterodactyl',
            'extension' => 'Pterodactyl',
            'type' => 'server',
            'enabled' => true,
        ]);
        Product::factory()->create(['server_id' => $server->id]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));
        app()->setLocale('zh');

        Livewire::test(ListServers::class)
            ->assertSeeText('Tokyo Pterodactyl')
            ->assertSeeText('1')
            ->assertSeeText('测试连接');
    }

    public function test_admin_can_create_a_server_and_save_its_extension_settings(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(CreateServer::class)
            ->fillForm([
                'name' => 'Tokyo Pterodactyl',
                'extension' => 'Pterodactyl',
                'settings' => [
                    'host' => 'https://panel.example.test',
                    'api_key' => 'application-secret',
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $server = Server::where('name', 'Tokyo Pterodactyl')->sole();
        $this->assertSame('Pterodactyl', $server->extension);
        $this->assertSame('server', $server->type);
        $this->assertTrue((bool) $server->enabled);
        $this->assertSame('https://panel.example.test', $server->settings()->where('key', 'host')->sole()->value);

        $apiKey = $server->settings()->where('key', 'api_key')->sole();
        $this->assertSame('application-secret', $apiKey->value);
        $this->assertTrue($apiKey->encrypted);
    }

    public function test_admin_can_filter_interfaces_and_open_their_bound_products(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $enabled = Server::create(['name' => 'Tokyo', 'extension' => 'Pterodactyl', 'type' => 'server', 'enabled' => true]);
        $disabled = Server::create(['name' => 'Retired', 'extension' => 'Pterodactyl', 'type' => 'server', 'enabled' => false]);
        Product::factory()->create(['server_id' => $enabled->id]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(ListServers::class)
            ->filterTable('extension', 'Pterodactyl')
            ->filterTable('enabled', 1)
            ->assertCanSeeTableRecords([$enabled])
            ->assertCanNotSeeTableRecords([$disabled])
            ->assertSee(ProductResource::getUrl('index', ['tableFilters' => ['server_id' => ['value' => $enabled->id]]]));
    }
}
