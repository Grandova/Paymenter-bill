<?php

namespace App\Admin\Clusters;

use Filament\Clusters\Cluster;

class Extensions extends Cluster
{
    protected static string|\BackedEnum|null $navigationIcon = 'ri-puzzle-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-puzzle-fill';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'extensions';

    public static function getNavigationGroup(): ?string
    {
        return __('System management');
    }

    public static function getClusterBreadcrumb(): ?string
    {
        return __('Extensions');
    }

    public static function getNavigationLabel(): string
    {
        return __('Plugin management');
    }
}
