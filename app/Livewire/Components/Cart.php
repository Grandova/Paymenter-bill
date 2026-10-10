<?php

namespace App\Livewire\Components;

use App\Livewire\Component;
use App\Models\Cart as CartModel;
use Illuminate\Support\Facades\Cookie;
use Livewire\Attributes\On;

class Cart extends Component
{
    public int $cartCount;

    public function mount()
    {
        $this->onCartUpdated();
        if ($this->cartCount === 0) {
            $this->skipRender();
        }
    }

    #[On('cartUpdated')]
    public function onCartUpdated()
    {
        $this->cartCount = Cookie::has('cart')
            ? CartModel::where('ulid', Cookie::get('cart'))->withCount('items')->value('items_count') ?? 0
            : 0;
    }

    public function render()
    {
        return view('components.cart');
    }
}
