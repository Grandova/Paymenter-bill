<?php

namespace App\Admin\Widgets;

use App\Enums\DashboardPeriod;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Order;
use Carbon\Carbon;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;

class Revenue extends ChartWidget
{
    public function getHeading(): string
    {
        return __('Revenue');
    }

    public ?string $filter = 'month';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '300px';

    protected function getFilters(): ?array
    {
        return DashboardPeriod::options();
    }

    /**
     * Let the overview stats follow the period chosen here.
     */
    public function updatedFilter(): void
    {
        $this->dispatch('dashboard-period-updated', period: $this->filter);
    }

    protected function getData(): array
    {
        $period = DashboardPeriod::fromValue($this->filter);

        $start = $period->start();

        $end = now();

        $interval = $period->interval();
        $currency = config('settings.default_currency');

        $revenue = Trend::query(InvoiceTransaction::query()->where('status', InvoiceTransactionStatus::Succeeded)->where('is_credit_transaction', false)->where('credited_to_balance', false)->whereHas('invoice', fn ($query) => $query->where('status', '!=', Invoice::STATUS_CANCELLED)->where('currency_code', $currency)))
            ->between(
                start: $start,
                end: $end,
            )
            ->interval($interval)
            ->sum('amount - refunded_amount - credited_amount');

        $netRevenue = Trend::query(InvoiceTransaction::query()->where('status', InvoiceTransactionStatus::Succeeded)->where('is_credit_transaction', false)->where('credited_to_balance', false)->whereHas('invoice', fn ($query) => $query->where('status', '!=', Invoice::STATUS_CANCELLED)->where('currency_code', $currency)))
            ->between(
                start: $start,
                end: $end,
            )
            ->interval($interval)
            ->sum('amount - refunded_amount - credited_amount - COALESCE(fee, 0)');

        $newOrders = Trend::model(Order::class)
            ->between(
                start: $start,
                end: $end,
            )
            ->interval($interval)
            ->count();

        return [
            'datasets' => [
                [
                    'label' => __('Revenue') . ' (' . $currency . ')',
                    'data' => $revenue->map(fn (TrendValue $value) => $value->aggregate)->toArray(),
                    'backgroundColor' => '#3490dc',
                    'borderColor' => '#3490dc',
                ],
                [
                    'label' => __('Net Revenue') . ' (' . $currency . ')',
                    'data' => $netRevenue->map(fn (TrendValue $value) => $value->aggregate)->toArray(),
                    'backgroundColor' => '#38c172',
                    'borderColor' => '#38c172',
                ],
                [
                    'label' => __('New Orders'),
                    'data' => $newOrders->map(fn (TrendValue $value) => $value->aggregate)->toArray(),
                    'backgroundColor' => '#e3342f',
                    'borderColor' => '#e3342f',
                ],
            ],
            'labels' => $revenue->map(fn (TrendValue $value) => Carbon::parse($value->date)->translatedFormat($period->dateFormat()))->toArray(),
        ];
    }

    protected function getOptions(): array|RawJs|null
    {
        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    public static function canView(): bool
    {
        return auth()->user()->hasPermission('admin.widgets.revenue');
    }
}
