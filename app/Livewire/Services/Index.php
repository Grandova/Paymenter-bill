<?php

namespace App\Livewire\Services;

use App\Livewire\Component;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Service\RenewServiceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    #[Url]
    public $status = null;

    public $search = '';

    public array $selectedServices = [];

    public bool $dashboard = false;

    public function updatedSearch()
    {
        $this->resetPage();
    }

    public function updatedStatus()
    {
        $this->resetPage();
    }

    public function renewSelected(): void
    {
        $this->validate([
            'selectedServices' => ['required', 'array', 'min:1', 'max:50'],
            'selectedServices.*' => ['required', 'integer', 'distinct'],
        ]);

        $result = DB::transaction(function () {
            $services = Auth::user()->services()
                ->whereIn('id', $this->selectedServices)
                ->with(['plan', 'product', 'configs.configValue', 'coupon'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($services->count() !== count(array_unique($this->selectedServices))) {
                return ['error' => __('services.renew_selection_invalid')];
            }

            if ($services->pluck('currency_code')->unique()->count() !== 1) {
                return ['error' => __('services.renew_same_currency')];
            }

            foreach ($services as $service) {
                if (!in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)
                    || $service->plan->type !== 'recurring'
                    || $service->cancellation()->exists()
                    || $service->invoices()->where('status', Invoice::STATUS_PENDING)->exists()
                    || $service->upgrade()->where('status', 'pending')->exists()) {
                    return ['error' => __('services.renew_selection_unavailable')];
                }
            }

            $billableServices = collect();
            foreach ($services as $service) {
                $service->price = $service->calculatePrice();
                $service->save();

                if ($service->price * $service->quantity <= 0) {
                    (new RenewServiceService)->handle($service);

                    continue;
                }

                $billableServices->push($service);
            }

            if ($billableServices->isEmpty()) {
                return ['renewed' => true];
            }

            $invoice = new Invoice([
                'user_id' => Auth::id(),
                'currency_code' => $services->first()->currency_code,
                'status' => Invoice::STATUS_PENDING,
                'due_at' => now(),
            ]);
            $invoice->save();

            foreach ($billableServices as $service) {
                $invoice->items()->create([
                    'reference_id' => $service->id,
                    'reference_type' => Service::class,
                    'price' => $service->price,
                    'quantity' => $service->quantity,
                    'description' => $service->description,
                ]);
            }

            return ['invoice' => $invoice];
        });

        if (isset($result['error'])) {
            $this->notify($result['error'], 'error');

            return;
        }

        if (isset($result['renewed'])) {
            $this->notify(__('services.renewed_without_payment'), 'success');
            $this->redirect(route('services'), true);

            return;
        }

        $this->redirect(route('invoices.show', $result['invoice']) . '?pay=1', true);
    }

    public function render()
    {
        $query = Auth::user()->services()
            ->with(['product.category', 'product.server', 'product.settings', 'properties', 'plan', 'currency'])
            ->withExists([
                'cancellation',
                'invoices as has_pending_invoice' => fn ($query) => $query->where('status', Invoice::STATUS_PENDING),
                'upgrade as has_pending_upgrade' => fn ($query) => $query->where('status', 'pending'),
            ])
            ->orderBy('created_at', 'desc');

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
