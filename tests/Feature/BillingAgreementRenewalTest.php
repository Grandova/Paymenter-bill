<?php

namespace Tests\Feature;

use App\Classes\Extension\Gateway as GatewayExtension;
use App\Models\BillingAgreement;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class BillingAgreementRenewalTest extends TestCase
{
    use RefreshDatabase;

    public function test_removing_a_billing_agreement_clears_it_from_all_services(): void
    {
        $user = User::factory()->create();
        $gateway = Gateway::create([
            'name' => 'Renewal test gateway',
            'extension' => 'RenewalTest',
            'type' => 'gateway',
            'enabled' => true,
        ]);
        $agreement = BillingAgreement::create([
            'user_id' => $user->id,
            'gateway_id' => $gateway->id,
            'name' => 'Test payment method',
            'external_reference' => 'renewal-test-method',
        ]);
        $product = $this->createProduct();
        $services = collect([1, 2])->map(fn () => Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'billing_agreement_id' => $agreement->id,
        ]));

        $agreement->delete();

        $services->each(fn (Service $service) => $this->assertNull($service->fresh()->billing_agreement_id));
    }

    public function test_cron_clears_a_stale_billing_agreement_without_breaking_renewal_processing(): void
    {
        $user = User::factory()->create();
        $gateway = Gateway::create([
            'name' => 'Renewal test gateway',
            'extension' => 'RenewalTest',
            'type' => 'gateway',
            'enabled' => true,
        ]);
        $agreement = BillingAgreement::create([
            'user_id' => $user->id,
            'gateway_id' => $gateway->id,
            'name' => 'Test payment method',
            'external_reference' => 'renewal-test-method',
        ]);
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addDays(2),
            'billing_agreement_id' => $agreement->id,
        ]);
        DB::table('billing_agreements')->where('id', $agreement->id)->update(['deleted_at' => now()]);
        DB::commit();

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertNull($service->fresh()->billing_agreement_id);
        $this->assertSame(Invoice::STATUS_PENDING, $service->invoices()->latest()->firstOrFail()->status);
    }

    #[RunInSeparateProcess]
    public function test_cron_retries_a_billing_agreement_for_an_existing_pending_renewal_invoice(): void
    {
        $extensionName = 'Paymenter\\Extensions\\Gateways\\RenewalTest\\RenewalTest';
        if (!class_exists($extensionName)) {
            $extension = new class extends GatewayExtension
            {
                public static int $charges = 0;

                public function pay(Invoice $invoice, $total) {}

                public function charge(Invoice $invoice, $total, BillingAgreement $billingAgreement): bool
                {
                    self::$charges++;

                    return false;
                }
            };
            class_alias($extension::class, $extensionName);
        }
        $extensionName::$charges = 0;

        $user = User::factory()->create();
        $gateway = Gateway::create([
            'name' => 'Renewal test gateway',
            'extension' => 'RenewalTest',
            'type' => 'gateway',
            'enabled' => true,
        ]);
        $agreement = BillingAgreement::create([
            'user_id' => $user->id,
            'gateway_id' => $gateway->id,
            'name' => 'Test payment method',
            'external_reference' => 'renewal-test-method',
        ]);
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addDays(2),
            'billing_agreement_id' => $agreement->id,
        ]);
        $invoice = $service->invoices()->create([
            'user_id' => $user->id,
            'currency_code' => 'CNY',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => $service->expires_at,
        ]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => 10,
            'quantity' => 1,
        ]);

        DB::commit();

        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(2, $extensionName::$charges);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }
}
