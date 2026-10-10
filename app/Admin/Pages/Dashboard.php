<?php

namespace App\Admin\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static string|\BackedEnum|null $navigationIcon = 'ri-function-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-function-fill';

    public static function getNavigationGroup(): ?string
    {
        return __('Workbench');
    }

    public static function getNavigationLabel(): string
    {
        return __('Data overview');
    }

    public function getTitle(): string
    {
        return __('Workbench');
    }

    public function getSubheading(): ?string
    {
        return __('Overview of revenue, services and customer support.');
    }
}
