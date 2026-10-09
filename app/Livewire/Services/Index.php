<?php

namespace App\Livewire\Services;

use App\Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    #[Url]
    public $status = null;

    public $search = '';

    public bool $dashboard = false;

    public function updatedSearch()
    {
        $this->resetPage();
    }

    public function updatedStatus()
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = Auth::user()->services()->with(['product.category', 'product.server', 'properties', 'plan', 'currency'])->orderBy('created_at', 'desc');

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->search !== '') {
            $query->where(function ($query) {
                $query->where('label', 'like', '%' . $this->search . '%')
                    ->orWhereHas('product', fn ($query) => $query->where('name', 'like', '%' . $this->search . '%'));
            });
        }

        return view('services.index', [
            'services' => $query->paginate(config('settings.pagination')),
        ])->layoutData([
            'title' => __('Services'),
        ]);
    }
}
