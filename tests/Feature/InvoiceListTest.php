<?php

namespace Tests\Feature;

use App\Livewire\Invoices\Index;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InvoiceListTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_search_own_invoices_by_number_or_item(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $first = Invoice::create([
            'user_id' => $user->id,
            'currency_code' => 'CNY',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now(),
        ]);
        $first->forceFill(['number' => 'INV-2026-001'])->saveQuietly();
        $first->items()->create(['description' => 'Tokyo VPS renewal', 'price' => 99, 'quantity' => 1]);
        $second = Invoice::create([
            'user_id' => $user->id,
            'currency_code' => 'CNY',
            'status' => Invoice::STATUS_PAID,
            'due_at' => now(),
        ]);
        $second->forceFill(['number' => 'INV-2026-002'])->saveQuietly();
        $second->items()->create(['description' => 'Hong Kong VPS renewal', 'price' => 199, 'quantity' => 1]);
        $foreign = Invoice::create([
            'user_id' => $otherUser->id,
            'currency_code' => 'CNY',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now(),
        ]);
        $foreign->forceFill(['number' => 'INV-2026-003'])->saveQuietly();
        $foreign->items()->create(['description' => 'Tokyo VPS renewal', 'price' => 99, 'quantity' => 1]);

        $this->actingAs($user);

        Livewire::test(Index::class)
            ->assertSee(__('invoices.search_placeholder'))
            ->assertViewHas('invoices', fn ($invoices) => $invoices->pluck('id')->all() === [$second->id, $first->id])
            ->set('search', 'Tokyo')
            ->assertViewHas('invoices', fn ($invoices) => $invoices->pluck('id')->all() === [$first->id])
            ->set('search', 'INV-2026-002')
            ->assertViewHas('invoices', fn ($invoices) => $invoices->pluck('id')->all() === [$second->id])
            ->set('search', 'Tokyo')
            ->set('status', Invoice::STATUS_PAID)
            ->assertViewHas('invoices', fn ($invoices) => $invoices->isEmpty())
            ->set('search', '')
            ->assertViewHas('invoices', fn ($invoices) => $invoices->pluck('id')->all() === [$second->id])
            ->set('status', Invoice::STATUS_PENDING)
            ->assertViewHas('invoices', fn ($invoices) => $invoices->pluck('id')->all() === [$first->id]);
    }
}
