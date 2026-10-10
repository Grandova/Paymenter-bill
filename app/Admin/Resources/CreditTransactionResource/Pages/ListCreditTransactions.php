<?php

namespace App\Admin\Resources\CreditTransactionResource\Pages;

use App\Admin\Resources\CreditTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListCreditTransactions extends ListRecords
{
    protected static string $resource = CreditTransactionResource::class;
}
