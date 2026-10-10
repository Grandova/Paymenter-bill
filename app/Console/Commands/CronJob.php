<?php

namespace App\Console\Commands;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Models\Credit;
use App\Models\CronStat;
use App\Models\DebugLog;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Service\RenewServiceService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class CronJob extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:cron-job';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run automated tasks';

    private int $successFullCharges = 0;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Config::set('audit.console', true);

        DB::beginTransaction();

        try {
            // Send invoices if due date is x days away
            $this->runCronJob('invoices_created', function ($number = 0) {
                Service::whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])->where('expires_at', '<', now()->addDays((int) config('settings.cronjob_invoice', 7)))->lockForUpdate()->get()->each(function ($service) use (&$number) {
                    // Does the service have already a pending invoice?
                    if ($service->cancellation()->exists()) {
                        return;
                    }
                    $pendingInvoice = $service->invoices()->where('status', Invoice::STATUS_PENDING)->with('items')->first();
                    if ($pendingInvoice) {
                        if (!$pendingInvoice->transactions()->where('status', InvoiceTransactionStatus::Processing)->exists()) {
                            $this->payInvoiceWithCredits($pendingInvoice);
                            $this->chargeBillingAgreement($service, $pendingInvoice);
                        }

                        return;
                    }

                    if ($service->status !== Service::STATUS_ACTIVE) {
                        return;
                    }

                    // Calculate if we should edit the price because of the coupon
                    if ($service->coupon) {
                        $service->price = $service->calculatePrice();
                        $service->save();
                    }

                    // If service price is 0, immediately activate next period
                    if ($service->price <= 0) {
                        (new RenewServiceService)->handle($service);
                        $number++;

                        return;
                    }

                    // Create invoice
                    $invoice = $service->invoices()->make([
                        'user_id' => $service->user_id,
                        'status' => 'pending',
                        'due_at' => $service->expires_at,
                        'currency_code' => $service->currency_code,
                    ]);

                    $invoice->save();
                    // Create invoice items
                    $invoice->items()->create([
                        'reference_id' => $service->id,
                        'reference_type' => Service::class,
                        'price' => $service->price,
                        'quantity' => $service->quantity,
                        'description' => $service->description,
                    ]);

                    $invoice = $invoice->refresh();

                    $this->payInvoiceWithCredits($invoice);

                    $this->chargeBillingAgreement($service, $invoice);

                    $number++;
                });

                return $number;
            });

            $this->runCronJob('orders_cancelled', function ($number = 0) {
                // Cancel services if first invoice is not paid after x days
                Service::where('status', 'pending')->whereDoesntHave('invoices', function ($query) {
                    $query->where('status', 'paid');
                })->whereDoesntHave('invoices', function ($query) {
                    $query->where('status', 'pending')->whereHas('transactions', function ($query) {
                        $query->where('status', InvoiceTransactionStatus::Succeeded->value)
                            ->orWhere(function ($query) {
                                $query->where('status', InvoiceTransactionStatus::Processing->value)
                                    ->where('created_at', '>=', now()->subDay());
                            });
                    });
                })->where('created_at', '<', now()->subDays((int) config('settings.cronjob_order_cancel', 7)))->get()->each(function ($service) use (&$number) {
                    $service->invoices()->where('status', 'pending')->update(['status' => 'cancelled']);

                    $service->update(['status' => 'cancelled']);

                    if ($service->product->stock !== null) {
                        $service->product->increment('stock', $service->quantity);
                    }

                    $number++;
                });

                return $number;
            });

            $this->runCronJob('upgrade_invoices_updated', function ($number = 0) {
                // Update pending upgrade invoices
                ServiceUpgrade::where('status', 'pending')->get()->each(function ($upgrade) use (&$number) {
                    if ($upgrade->type === 'renewal_cycle') {
                        if (!$upgrade->invoice || $upgrade->invoice->status === Invoice::STATUS_CANCELLED) {
                            $upgrade->update(['status' => ServiceUpgrade::STATUS_CANCELLED]);
                        }

                        $number++;

                        return;
                    }

                    if ($upgrade->service->expires_at < now()) {
                        $upgrade->update(['status' => 'cancelled']);
                        // Somehow people manage to have an upgrade without an invoice
                        if ($upgrade->invoice) {
                            $upgrade->invoice->update(['status' => 'cancelled']);
                        }

                        $number++;

                        return;
                    }
                    if (!$upgrade->invoice) {
                        return;
                    }

                    $upgrade->invoice->items()->update([
                        'price' => $upgrade->calculatePrice()->price,
                    ]);

                    $number++;
                });

                return $number;
            });

            $this->runCronJob('services_suspended', function ($number = 0) {
                // Suspend orders if due date is overdue for x days
                Service::where('status', 'active')
                    ->where('expires_at', '<', now()->subDays((int) config('settings.cronjob_order_suspend', 2)))
                    ->where(function ($query) {
                        $query->whereNull('suspend_hold_until')->orWhere('suspend_hold_until', '<', today());
                    })
                    ->get()->each(function ($service) use (&$number) {
                        $service->update(['status' => 'suspended']);
                        SuspendJob::dispatch($service)->afterCommit();
                        $number++;
                    });

                return $number;
            });

            $this->runCronJob('services_terminated', function ($number = 0) {
                // Terminate orders if due date is overdue for x days
                Service::where(function ($query) {
                    $query->where(function ($query) {
                        $query->where('status', 'suspended')
                            ->where('expires_at', '<', now()->subDays((int) config('settings.cronjob_order_terminate', 3)));
                    })->orWhere(function ($query) {
                        $query->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])
                            ->whereHas('cancellation', fn ($query) => $query->where('type', 'immediate'));
                    });
                })->each(function ($service) use (&$number) {
                    TerminateJob::dispatch($service)->afterCommit();
                    $number++;
                });

                return $number;
            });

            $this->runCronJob('tickets_closed', function ($number = 0) {
                // Close tickets if no response for x days
                Ticket::where('status', 'replied')->each(function ($ticket) use (&$number) {
                    $lastMessage = $ticket->messages()->latest('created_at')->first();
                    if ($lastMessage && $lastMessage->created_at < now()->subDays((int) config('settings.cronjob_close_ticket', 7))) {
                        $ticket->update(['status' => 'closed']);
                        $number++;
                    }
                });

                return $number;
            });

            $this->runCronJob('email_logs_deleted', function ($number = 0) {
                $number = EmailLog::where('created_at', '<', now()->subDays((int) config('settings.cronjob_delete_email_logs', 90)))->count();
                // Delete email logs older then x
                EmailLog::where('created_at', '<', now()->subDays((int) config('settings.cronjob_delete_email_logs', 90)))->delete();

                return $number;
            });

        } catch (Exception $e) {
            DB::rollBack();

            NotificationHelper::sendSystemEmailNotification('Cron Job Error', <<<HTML
                An error occurred while running the cron job:<br>
                <pre>{$e->getMessage()}.</pre><br>
                Please check the system and application logs for more details.
                HTML);

            throw $e;
        }

        DB::commit();

        Setting::updateOrCreate(
            ['key' => 'last_cron_run', 'settingable_type' => CronStat::class],
            ['value' => now()->toDateTimeString(), 'type' => 'string']
        );

        CronStat::create([
            'key' => 'invoice_charged',
            'value' => $this->successFullCharges,
            'date' => now()->toDateString(),
        ]);

        $this->info('Successfully charged ' . $this->successFullCharges . ' invoices.');

        // Remove old debug logs
        DebugLog::where('created_at', '<', now()->subDays(30))->delete();

        // Check for updates
        $this->info('Checking for updates...');

        $this->call(CheckForUpdates::class);
    }

    private function payInvoiceWithCredits(Invoice $invoice): void
    {
        if (!config('settings.credits_enabled') || !config('settings.credits_auto_use', true)) {
            return;
        }

        $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->with('items', 'transactions')->firstOrFail();
        if ($invoice->status !== Invoice::STATUS_PENDING
            || $invoice->transactions->contains(fn ($transaction) => $transaction->status === InvoiceTransactionStatus::Processing)
            || $invoice->items->count() !== 1
            || $invoice->items->first()->reference_type !== Service::class
            || !$invoice->items->first()->reference?->auto_renew) {
            return;
        }

        $user = $invoice->user()->lockForUpdate()->firstOrFail();
        $remaining = $invoice->remaining;
        $spent = Credit::spend($user, $invoice->currency_code, $remaining, partial: false, type: 'auto_renewal', reference: $invoice);

        if ($spent >= $remaining && $remaining > 0) {
            ExtensionHelper::addPayment($invoice->id, null, amount: $spent, isCreditTransaction: true);
        }
    }

    private function chargeBillingAgreement(Service $service, Invoice $invoice): void
    {
        if (!$service->billing_agreement_id || $invoice->fresh()->status !== Invoice::STATUS_PENDING) {
            return;
        }

        DB::afterCommit(function () use ($invoice, $service) {
            $billingAgreement = $service->billingAgreement;
            $gateway = $billingAgreement?->gateway;
            if (!$billingAgreement || !$gateway) {
                $service->update(['billing_agreement_id' => null]);
                NotificationHelper::invoicePaymentFailedNotification($invoice->user, $invoice);

                return;
            }

            try {
                $charged = ExtensionHelper::charge(
                    $gateway,
                    $invoice,
                    $billingAgreement
                );

                if ($charged) {
                    $this->successFullCharges++;
                } else {
                    NotificationHelper::invoicePaymentFailedNotification($invoice->user, $invoice);
                }
            } catch (Exception $e) {
                // Ignore errors here
                NotificationHelper::invoicePaymentFailedNotification($invoice->user, $invoice);
            }
        });
    }

    /**
     * Function to run a specific cron job by its key.
     */
    private function runCronJob(string $key, callable $callback): void
    {
        $items = $callback() ?? 0;

        CronStat::create([
            'key' => $key,
            'value' => $items,
            'date' => now()->toDateString(),
        ]);

        $this->info("Cronjob task '" . __('admin.cronjob.' . $key) . "' completed: Processed " . $items . ' items.');
    }
}
