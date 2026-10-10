<?php

namespace App\Livewire\Invoices;

use App\Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    #[Url]
    public $status = null;

    #[Url]
    public $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = Auth::user()->invoices()->with(['user', 'snapshot', 'items'])->orderBy('id', 'desc');

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->search !== '') {
            $query->where(function ($query) {
                $search = '%' . $this->search . '%';
                $query->where('number', 'like', $search)
                    ->orWhereHas('items', fn ($query) => $query->where('description', 'like', $search));
            });
        }

        return view('invoices.index', [
            'invoices' => $query->paginate(config('settings.pagination')),
        ])->layoutData([
            'title' => __('invoices.invoices'),
        ]);
    }
}
