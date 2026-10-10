<?php

namespace Tests\Feature;

use App\Classes\Extension\Gateway as GatewayExtension;
use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingAgreementGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_billing_agreements_respect_gateway_currency_restrictions(): void
    {
        $extensionName = 'Paymenter\\Extensions\\Gateways\\CurrencyTest\\CurrencyTest';
        if (!class_exists($extensionName)) {
            $extension = new class extends GatewayExtension
            {
                public function pay(Invoice $invoice, $total) {}

                public function supportsBillingAgreements(): bool
                {
                    return true;
                }

                public function canUseGateway($total, $currency, $type, $items = []): bool
                {
                    return $currency === 'CNY';
                }
            };
            class_alias($extension::class, $extensionName);
        }

        $gateway = Gateway::create([
            'name' => 'Currency restricted gateway',
            'extension' => 'CurrencyTest',
            'type' => 'gateway',
        ]);

        $this->assertSame([$gateway->id], array_column(ExtensionHelper::getBillingAgreementGateways('CNY', 10), 'id'));
        $this->assertSame([], ExtensionHelper::getBillingAgreementGateways('USD', 10));
        $this->assertSame([$gateway->id], array_column(ExtensionHelper::getBillingAgreementGateways(), 'id'));
    }
}
