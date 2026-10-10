<?php

namespace App\Livewire\Services;

use App\Classes\Price;
use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Service\RenewServiceService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class Show extends Component
{
    public Service $service;

    #[Locked]
    public $buttons = [];

    #[Locked]
    public $views = [];

    #[Locked]
    public $fields = [];

    #[Url('tab', except: false), Locked]
    public $currentView;

    #[Url('cancel', except: false)]
    public bool $showCancel = false;

    public bool $showBillingAgreement = false;

    #[Url('label', except: false)]
    public bool $editLabel = false;

    public ?string $label = null;

    public $selectedMethod;

    public bool $autoRenew = false;

    public bool $showRenewal = false;

    public ?int $renewalPlanId = null;

    public function mount()
    {
        $this->authorize('view', $this->service);

        // Only fetch the actions if the service is active
        if ($this->service->status == Service::STATUS_ACTIVE) {
            $actions = [];
            try {
                $actions = ExtensionHelper::getActions($this->service);
            } catch (Exception $e) {
            }
            // separate the actions into buttons and views
            foreach ($actions as $action) {
                if ($action['type'] == 'button') {
                    $this->buttons[] = $action;
                } elseif ($action['type'] == 'view') {
                    $this->views[] = $action;
                } elseif ($action['type'] == 'text') {
                    $this->fields[] = $action;
                }
            }
            $this->currentView = $this->currentView ?? ($this->views[0]['name'] ?? null);
        }
        $this->label = $this->service->label;
        $this->autoRenew = (bool) $this->service->auto_renew;
        $this->renewalPlanId = $this->service->plan_id;
    }

    public function toggleAutoRenew(): void
    {
        $this->authorize('update', $this->service);
        abort_unless(config('settings.credits_enabled'), 403);

        $service = DB::transaction(function () {
            $service = Service::query()->whereKey($this->service->id)->lockForUpdate()->firstOrFail();
            abort_unless(
                in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)
                    && $service->plan->type === 'recurring'
                    && !$service->cancellation()->exists(),
                403
            );
            $service->auto_renew = !$service->auto_renew;
            $service->save();

            return $service;
        });

        $this->service = $service->fresh();
        $this->autoRenew = (bool) $this->service->auto_renew;
        $this->notify($this->autoRenew ? __('services.auto_renew_enabled') : __('services.auto_renew_disabled'), 'success');
    }

    public function updatedShowBillingAgreement()
    {
        $this->selectedMethod = Auth::user()->billingAgreements()->where('id', $this->service->billing_agreement_id)?->first()?->ulid;
    }

    public function updateBillingAgreement()
    {
        $this->authorize('update', $this->service);
        $gateways = ExtensionHelper::getBillingAgreementGateways(
            $this->service->currency_code,
            $this->service->price * $this->service->quantity
        );
        $agreement = Auth::user()->billingAgreements()
            ->where('ulid', $this->selectedMethod)
            ->whereIn('gateway_id', array_column($gateways, 'id'))
            ->first();
        if (!$agreement) {
            return $this->notify(__('Invalid payment method.'), 'error');
        }

        $this->service->billing_agreement_id = $agreement->id;
        $this->service->save();

        $this->showBillingAgreement = false;
    }

    public function clearBillingAgreement()
    {
        $this->authorize('update', $this->service);
        $this->service->billing_agreement_id = null;
        $this->service->save();
        $this->selectedMethod = null;
    }

    public function renewNow(): void
    {
        $this->authorize('update', $this->service);
        if ($this->service->plan->type !== 'recurring') {
            $this->notify(__('services.renew_unavailable'), 'error');

            return;
        }

        $this->validate([
            'renewalPlanId' => ['required', 'integer', function ($attribute, $value, $fail) {
                if (!$this->renewalPlans()->contains('id', (int) $value)) {
                    $fail(__('services.renew_unavailable'));
                }
            }],
        ]);

        $invoice = DB::transaction(function () {
            $service = Service::query()->whereKey($this->service->id)->lockForUpdate()->firstOrFail();
            if (!in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)
                || $service->plan->type !== 'recurring'
                || $service->cancellation()->exists()) {
                $this->notify(__('services.renew_unavailable'), 'error');

                return null;
            }

            $renewalPlan = $service->product->availablePlans($service->currency_code)
                ->where('type', 'recurring')
                ->firstWhere('id', $this->renewalPlanId);
            if (!$renewalPlan) {
                $this->notify(__('services.renew_unavailable'), 'error');

                return null;
            }

            $invoice = $service->invoices()->where('status', Invoice::STATUS_PENDING)->latest('id')->first();
            if ($invoice) {
                if ((int) $renewalPlan->id !== (int) $service->plan_id) {
                    $this->notify(__('services.renew_invoice_cycle_locked'), 'error');

                    return null;
                }

                return ['invoice' => $invoice];
            }

            $service->setRelation('plan', $renewalPlan);
            $renewalPrice = $service->calculatePrice();
            $service->unsetRelation('plan');

            if ((float) $renewalPrice * $service->quantity <= 0) {
                $service->plan_id = $renewalPlan->id;
                $service->save();
                $service->refresh();
                $service->price = $service->calculatePrice();
                $service->save();
                (new RenewServiceService)->handle($service);

                return ['renewed' => true];
            }

            if ((int) $renewalPlan->id === (int) $service->plan_id) {
                $service->price = $renewalPrice;
                $service->save();
            }

            $invoice = $service->invoices()->make([
                'user_id' => $service->user_id,
                'status' => Invoice::STATUS_PENDING,
                'due_at' => now(),
                'currency_code' => $service->currency_code,
            ]);
            $invoice->save();

            if ((int) $renewalPlan->id !== (int) $service->plan_id) {
                ServiceUpgrade::create([
                    'service_id' => $service->id,
                    'product_id' => $service->product_id,
                    'plan_id' => $renewalPlan->id,
                    'invoice_id' => $invoice->id,
                    'type' => 'renewal_cycle',
                ]);
            }

            $period = trans_choice(__('services.billing_cycles.' . $renewalPlan->billing_unit), $renewalPlan->billing_period);
            $description = (int) $renewalPlan->id === (int) $service->plan_id
                ? $service->description
                : __('services.renewal_invoice_description', [
                    'service' => $service->product->name,
                    'period' => trim($renewalPlan->billing_period . ' ' . $period),
                ]);
            $invoice->items()->create([
                'reference_id' => $service->id,
                'reference_type' => Service::class,
                'price' => $renewalPrice,
                'quantity' => $service->quantity,
                'description' => $description,
            ]);

            return ['invoice' => $invoice];
        });

        if (isset($invoice['renewed'])) {
            $this->notify(__('services.renewed_without_payment'), 'success');
            $this->redirect(route('services.show', $this->service), true);

            return;
        }

        if ($invoice) {
            $this->redirect(route('invoices.show', $invoice['invoice']) . '?pay=1', true);
        }
    }

    public function renewalPlans()
    {
        return $this->service->product->availablePlans($this->service->currency_code)
            ->where('type', 'recurring')
            ->values();
    }

    private function renewalPrice(Plan $plan): string
    {
        $service = clone $this->service;
        $service->setRelation('plan', $plan);

        return $service->calculatePrice();
    }

    public function updateLabel()
    {
        $this->authorize('update', $this->service);
        $this->validate([
            'label' => 'nullable|string|max:255',
        ]);

        $this->service->label = $this->label;
        $this->service->save();

        $this->editLabel = false;
        $this->notify(__('Service label updated successfully'), 'success');
    }

    public function changeView($view)
    {
        if (!$view) {
            return;
        }
        if ($this->currentView === $view || !in_array($view, array_column($this->views, 'name'))) {
            return $this->skipRender();
        }
        $this->currentView = $view;
    }

    public function updatedShowCancel($value)
    {
        if (!$this->service->cancellable) {
            $this->notify(__('This service cannot be cancelled'), 'error');
            $this->showCancel = false;

            return;
        }
    }

    public function goto($function)
    {
        $this->authorize('update', $this->service);

        // Check if function is allowed
        if (!in_array($function, array_column($this->buttons, 'function'))) {
            $this->notify(__('This action is not allowed'), 'error');

            return;
        }
        $result = ExtensionHelper::callService($this->service, $function);
        // If its a response, return it
        if (!is_string($result)) {
            return $result;
        }
        $this->redirect($result);
    }

    public function render()
    {
        $view = null;
        $previousView = $this->currentView;

        if ($this->currentView) {
            try {
                // Search array for the current view
                $currentViewObj = $this->views[array_search($this->currentView, array_column($this->views, 'name'))] ?? null;
                if (!$currentViewObj) {
                    throw new Exception(__('View not found'));
                }
                $view = ExtensionHelper::getView($this->service, $currentViewObj);
            } catch (Exception $e) {
                if ($previousView !== $this->views[0]['name'] ?? null) {
                    $this->notify(__('Got an error while trying to load the view'), 'error');
                }
                $this->currentView = $this->views[0]['name'] ?? null;
            }
        }

        $billingAgreements = [];
        if ($this->service->plan->type === 'recurring') {
            $gateways = ExtensionHelper::getBillingAgreementGateways(
                $this->service->currency_code,
                $this->service->price * $this->service->quantity
            );
            $billingAgreements = Auth::user()->billingAgreements()
                ->whereIn('gateway_id', array_column($gateways, 'id'))
                ->get();
        }

        $renewalPlans = $this->renewalPlans();
        $selectedRenewalPlan = $renewalPlans->firstWhere('id', $this->renewalPlanId) ?? $this->service->plan;
        $renewalPrice = $selectedRenewalPlan ? $this->renewalPrice($selectedRenewalPlan) : 0;

        return view('services.show', [
            'extensionView' => $view,
            'billingAgreements' => $billingAgreements,
            'renewalPlans' => $renewalPlans,
            'renewalPrice' => new Price(['price' => $renewalPrice, 'currency' => $this->service->currency]),
            'hasPendingInvoice' => $this->service->invoices()->where('status', Invoice::STATUS_PENDING)->exists(),
        ])->layoutData([
            'title' => __('Services'),
        ]);
    }
}
