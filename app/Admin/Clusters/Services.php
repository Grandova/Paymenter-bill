<?php

namespace App\Admin\Clusters;

use Filament\Clusters\Cluster;

class Services extends Cluster
{
    protected static string|\BackedEnum|null $navigationIcon = 'ri-archive-stack-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-archive-stack-fill';

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'services';

    public static function getNavigationGroup(): ?string
    {
        return __('Business management');
    }

    public static function getClusterBreadcrumb(): ?string
    {
        return __('Services');
    }

    public static function getNavigationLabel(): string
    {
        return __('Service records');
    }
}
