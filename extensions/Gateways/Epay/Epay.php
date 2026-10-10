<?php

namespace Paymenter\Extensions\Gateways\Epay;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Gateway as GatewayModel;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

#[ExtensionMeta(
    name: '彩虹易支付',
    description: '彩虹易支付 V1（PID + KEY / MD5），支持支付宝、微信支付和 QQ 钱包。',
    version: 'builtin',
    author: 'Paymenter-bill',
)]
class Epay extends Gateway
{
    public function boot()
    {
        require __DIR__ . '/routes.php';
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'url',
                'label' => __('Epay gateway URL'),
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'pid',
                'label' => __('Merchant PID'),
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'key',
                'label' => __('Merchant KEY'),
                'type' => 'password',
                'encrypted' => true,
                'required' => true,
            ],
            [
                'name' => 'type',
                'label' => __('Payment Method'),
                'type' => 'select',
                'options' => [
                    '' => __('Cashier (all available methods)'),
                    'alipay' => __('Alipay'),
                    'wxpay' => __('WeChat Pay'),
                    'qqpay' => __('QQ Pay'),
                ],
            ],
        ];
    }

    public function pay(Invoice $invoice, $total)
    {
        if ($invoice->currency_code !== 'CNY') {
            throw new DisplayException(__('Epay only supports CNY invoices.'));
        }
        if ($invoice->status !== 'pending' || $total <= 0 || bccomp((string) $total, (string) $invoice->remaining, 2) !== 0) {
            throw new DisplayException(__('This invoice cannot be paid.'));
        }

        $url = rtrim(trim($this->config('url') ?? ''), '/');
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'])
            || parse_url($url, PHP_URL_QUERY) || parse_url($url, PHP_URL_FRAGMENT)
            || !ctype_digit((string) $this->config('pid')) || !$this->config('key')) {
            throw new DisplayException(__('Epay configuration is incomplete or invalid.'));
        }

        $params = [
            'pid' => (string) $this->config('pid'),
            'type' => $this->config('type') ?? '',
            'out_trade_no' => $this->orderNumber($invoice, $total),
            'notify_url' => route('extensions.gateways.epay.notify'),
            'return_url' => route('invoices.show', $invoice) . '?checkPayment=true',
            'name' => __('Invoice #') . $invoice->id,
            'money' => number_format($total, 2, '.', ''),
        ];
        $params['sign'] = $this->sign($params);
        $params['sign_type'] = 'MD5';

        return $url . '/submit.php?' . http_build_query($params);
    }

    public function canUseGateway($total, $currency, $type, $items = [])
    {
        return $currency === 'CNY';
    }

    private function orderNumber(Invoice $invoice, $amount): string
    {
        // Bind the merchant order to this invoice and amount without exposing the application key.
        $order = 'P' . $invoice->id . '-' . bcmul((string) $amount, '100', 0);

        return $order . '-' . substr(hash_hmac('sha256', $order . '-CNY-' . $this->config('pid'), config('app.key')), 0, 16);
    }

    private function sign(array $params): string
    {
        unset($params['sign'], $params['sign_type']);
        $params = array_filter($params, fn ($value) => $value !== '' && $value !== null);
        ksort($params, SORT_STRING);
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = $key . '=' . $value;
        }

        return md5(implode('&', $parts) . $this->config('key'));
    }

    public function notify(Request $request)
    {
        $params = $request->query();
        if (Validator::make($params, [
            'pid' => ['required', 'string'],
            'trade_no' => ['required', 'string', 'max:128'],
            'out_trade_no' => ['required', 'regex:/\AP[1-9][0-9]*-[1-9][0-9]*-[a-f0-9]{16}\z/'],
            'money' => ['required', 'regex:/\A(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,2})?\z/'],
            'trade_status' => ['required', 'in:TRADE_SUCCESS'],
            'sign' => ['required', 'regex:/\A[a-f0-9]{32}\z/'],
            'sign_type' => ['sometimes', 'in:MD5'],
        ])->fails() || count(array_filter($params, 'is_array')) > 0
            || !$this->config('key') || $params['pid'] !== (string) $this->config('pid')
            || !hash_equals($this->sign($params), $params['sign'])) {
            return response('fail', 400);
        }

        return DB::transaction(function () use ($params) {
            // Serialize callbacks for this gateway, including replays against a different invoice.
            $gateway = GatewayModel::where('extension', 'Epay')->lockForUpdate()->firstOrFail();
            $invoiceId = substr(explode('-', $params['out_trade_no'])[0], 1);
            $invoice = Invoice::whereKey($invoiceId)->lockForUpdate()->first();
            if (!$invoice || $invoice->currency_code !== 'CNY'
                || !hash_equals($this->orderNumber($invoice, $params['money']), $params['out_trade_no'])) {
                return response('fail', 400);
            }

            $transaction = InvoiceTransaction::where('gateway_id', $gateway->id)
                ->where('transaction_id', $params['trade_no'])->first();
            if ($transaction) {
                return $transaction->invoice_id === $invoice->id
                    && $transaction->status === InvoiceTransactionStatus::Succeeded
                    && bccomp($transaction->amount, $params['money'], 2) === 0
                    ? response('success') : response('fail', 400);
            }

            $terminalInvoice = in_array($invoice->status, [Invoice::STATUS_CANCELLED, Invoice::STATUS_PAID], true);
            if (bccomp($params['money'], '0', 2) <= 0
                || (!$terminalInvoice && ($invoice->status !== Invoice::STATUS_PENDING
                    || bccomp($params['money'], (string) $invoice->remaining, 2) !== 0))) {
                return response('fail', 400);
            }

            ExtensionHelper::addPayment($invoice, 'Epay', $params['money'], transactionId: $params['trade_no']);

            return response('success');
        });
    }
}
