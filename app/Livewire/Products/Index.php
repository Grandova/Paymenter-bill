<?php

namespace App\Livewire\Products;

use App\Classes\Cart;
use App\Livewire\Component;
use App\Models\Category;
use Illuminate\Support\Facades\Storage;

class Index extends Component
{
    public $products;

    public $categories;

    public $childCategories;

    public Category $category;

    public function mount()
    {
        $this->products = $this->category->products()->where('hidden', false)->with(['category', 'plans.prices.currency'])->orderBy('sort')->get();
        $this->childCategories = $this->category->children()->where(function ($query) {
            $query->whereHas('children')->orWhereHas('products', function ($query) {
                $query->where('hidden', false);
            });
        })->orderBy('sort')->get();
        $this->categories = Category::whereNull('parent_id')->where(function ($query) {
            $query->whereHas('children')->orWhereHas('products', function ($query) {
                $query->where('hidden', false);
            });
        })->orderBy('sort')->get();
        if (count($this->products) === 0 && count($this->childCategories) === 0) {
            abort(404);
        }
    }

    public function render()
    {
        return view('products.index', [
            'currency' => Cart::get()->currency_code ?? session('currency', config('settings.default_currency')),
        ])->layoutData([
            'title' => $this->category->name,
            'image' => $this->category->image ? Storage::url($this->category->image) : null,
        ]);
    }
}
