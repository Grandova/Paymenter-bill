<?php

namespace App\Livewire\Products;

use App\Classes\Cart;
use App\Livewire\Component;
use App\Models\Category;
use Illuminate\Support\Facades\Storage;

class Show extends Component
{
    public $product;

    public Category $category;

    public function mount($product)
    {
        $this->product = $this->category->products()->where('slug', $product)->where('hidden', false)->with(['plans.prices.currency', 'configOptions.children.plans.prices.currency'])->firstOrFail();
    }

    public function render()
    {
        return view('products.show', [
            'currency' => Cart::get()->currency_code ?? session('currency', config('settings.default_currency')),
        ])->layoutData([
            'title' => $this->product->name,
            'image' => $this->product->image ? Storage::url($this->product->image) : null,
        ]);
    }
}
