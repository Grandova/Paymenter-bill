<?php

namespace Tests\Feature;

use App\Admin\Resources\GatewayResource\Pages\CreateGateway;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Paymenter\Extensions\Gateways\Epay\Epay;
use Tests\TestCase;

class EpayTest extends TestCase
{
    use RefreshDatabase;

    private Epay $epay;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $config = ['url' => 'https://pay.example.test', 'pid' => '1001', 'key' => 'test-merchant-key', 'type' => 'alipay'];
        $gateway = (new \ReflectionMethod(CreateGateway::class, 'handleRecordCreation'))->invoke(new CreateGateway, [
            'name' => '彩虹易支付', 'extension' => 'Epay', 'type' => 'gateway', 'settings' => $config,
        ]);
        $key = $gateway->settings()->where('key', 'key')->first();
        $this->assertTrue($key->encrypted);
        $this->assertSame($config['key'], $key->value);
        $this->assertNotSame($config['key'], \DB::table('settings')->where('id', $key->id)->value('value'));
        $this->epay = new Epay($config);
        $this->epay->boot();
        app('router')->getRoutes()->refreshNameLookups();
        $this->invoice = Invoice::factory()->create(['user_id' => User::factory()->create()->id, 'currency_code' => 'CNY', 'status' => 'pending']);
        $this->invoice->items()->create(['description' => '云服务器', 'quantity' => 1, 'price' => 19.90]);
        $this->invoice->refresh();
    }

    private function notification(array $overrides = []): array
    {
        parse_str(parse_url($this->epay->pay($this->invoice, $this->invoice->remaining), PHP_URL_QUERY), $order);
        $params = array_merge([
            'pid' => '1001', 'trade_no' => '20261009000001', 'out_trade_no' => $order['out_trade_no'],
            'type' => 'alipay', 'name' => '云服务器 & 测试+中文', 'money' => $order['money'], 'trade_status' => 'TRADE_SUCCESS',
        ], $overrides);
        ksort($params);
        $params['sign'] = md5(urldecode(http_build_query($params)) . 'test-merchant-key');
        $params['sign_type'] = 'MD5';

        return $params;
    }

    public function test_signed_checkout_and_duplicate_callback_only_credit_once(): void
    {
        $url = $this->epay->pay($this->invoice, 19.90);
        $this->assertStringStartsWith('https://pay.example.test/submit.php?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->assertSame('19.90', $params['money']);
        $this->assertStringContainsString('?checkPayment=true', $params['return_url']);
        $sign = $params['sign'];
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        $this->assertSame(md5(urldecode(http_build_query($params)) . 'test-merchant-key'), $sign);
        $this->assertSame(0, $this->invoice->transactions()->count());

        $params = $this->notification();
        $this->get('/extensions/epay/notify?' . http_build_query($params))->assertOk()->assertContent('success');
        $this->get('/extensions/epay/notify?' . http_build_query($params))->assertOk()->assertContent('success');
        $this->assertSame(1, $this->invoice->transactions()->count());
        $this->assertSame('paid', $this->invoice->fresh()->status);
        $this->assertEquals(0, $this->invoice->fresh()->remaining);
    }

    public function test_invalid_notifications_do_not_credit_the_invoice(): void
    {
        foreach ([['pid' => '999'], ['money' => '0.01'], ['money' => '19.901'], ['trade_status' => 'WAIT_BUYER_PAY'], ['out_trade_no' => 'P999-1990-0000000000000000']] as $changes) {
            $params = $this->notification($changes);
            $this->get('/extensions/epay/notify?' . http_build_query($params))->assertStatus(400)->assertContent('fail');
        }
        $params = $this->notification();
        $params['name'] = 'tampered';
        $this->get('/extensions/epay/notify?' . http_build_query($params))->assertStatus(400);
        $params['name'] = ['invalid'];
        $this->get('/extensions/epay/notify?' . http_build_query($params))->assertStatus(400);
        $this->post('/extensions/epay/notify', $this->notification())->assertStatus(405);
        $this->assertSame(0, $this->invoice->transactions()->count());
    }

    public function test_changed_balance_and_cancelled_invoice_are_rejected(): void
    {
        $params = $this->notification();
        ExtensionHelper::addPayment($this->invoice, null, 1, isCreditTransaction: true);
        $this->get('/extensions/epay/notify?' . http_build_query($params))->assertStatus(400);
        $this->invoice->refresh();
        $params = $this->notification();
        $this->invoice->update(['status' => 'cancelled']);
        $this->get('/extensions/epay/notify?' . http_build_query($params))->assertStatus(400);
        $this->assertSame(1, $this->invoice->transactions()->count());
    }

    public function test_platform_transaction_cannot_be_reused_on_another_invoice(): void
    {
        $params = $this->notification();
        $this->get('/extensions/epay/notify?' . http_build_query($params))->assertOk();
        $this->invoice = Invoice::factory()->create(['user_id' => $this->invoice->user_id, 'currency_code' => 'CNY', 'status' => 'pending']);
        $this->invoice->items()->create(['description' => 'Other invoice', 'quantity' => 1, 'price' => 19.90]);
        $this->get('/extensions/epay/notify?' . http_build_query($this->notification()))->assertStatus(400);
        $this->assertSame(0, $this->invoice->transactions()->count());
    }

    public function test_non_cny_invoice_cannot_use_epay(): void
    {
        $this->assertCount(0, ExtensionHelper::getCheckoutGateways(19.90, 'USD', 'invoice'));
        $this->assertCount(1, ExtensionHelper::getCheckoutGateways(19.90, 'CNY', 'invoice'));
        $this->invoice->currency_code = 'USD';
        $this->expectException(DisplayException::class);
        $this->epay->pay($this->invoice, 19.90);
    }

    public function test_partial_credit_payment_can_be_completed_with_epay(): void
    {
        ExtensionHelper::addPayment($this->invoice, null, 10, isCreditTransaction: true);
        $this->invoice->refresh();
        $params = $this->notification();
        $this->assertSame('9.90', $params['money']);
        $this->get('/extensions/epay/notify?' . http_build_query($params))->assertOk()->assertContent('success');
        $this->assertSame('paid', $this->invoice->fresh()->status);
        $this->assertEquals(0, $this->invoice->fresh()->remaining);
    }
}
