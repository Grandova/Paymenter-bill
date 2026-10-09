<?php

namespace App\Admin\Resources\CurrencyResource\Pages;

use App\Admin\Resources\CurrencyResource;
use Filament\Resources\Pages\ListRecords;

class ListCurrencies extends ListRecords
{
    protected static string $resource = CurrencyResource::class;
}
