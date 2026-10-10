<?php

namespace App\Admin\Clusters;

use App\Models\Invoice;
use Filament\Clusters\Cluster;

class InvoiceCluster extends Cluster
{
    protected static ?int $navigationSort = 20;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-receipt-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-receipt-fill';

    public static function getNavigationGroup(): ?string
    {
        return __('Customers and finance');
    }

    public static function getClusterBreadcrumb(): ?string
    {
        return __('Invoices');
    }

    public static function getNavigationLabel(): string
    {
        return __('Invoice management');
    }

    public static function getNavigationBadge(): ?string
    {
        return Invoice::where('status', 'pending')->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
