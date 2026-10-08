<?php

namespace App\Admin\Resources\CurrencyResource\Pages;

use App\Admin\Resources\CurrencyResource;
use App\Models\Cart;
use App\Models\Currency;
use App\Models\Price;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCurrency extends EditRecord
{
    protected static string $resource = CurrencyResource::class;

    protected function getHeaderActions(): array
    {
        if (config('settings.default_currency') == $this->record->code) {
            return [];
        }

        return [
            DeleteAction::make()->before(function (DeleteAction $action, Currency $record) {
                // Prevent deletion if its being used by services/orders/credits
                $serviceCount = $record->services()->count();
                $orderCount = $record->orders()->count();
                $creditCount = $record->credits()->where('amount', '>', 0)->count();
                if ($serviceCount > 0 || $orderCount > 0 || $creditCount > 0) {
                    $message = __('Cannot delete currency because it is being used by ');
                    $parts = [];
                    if ($serviceCount > 0) {
                        $parts[] = __(':count service(s)', ['count' => $serviceCount]);
                    }
                    if ($orderCount > 0) {
                        $parts[] = __(':count order(s)', ['count' => $orderCount]);
                    }
                    if ($creditCount > 0) {
                        $parts[] = __(':count credit(s)', ['count' => $creditCount]);
                    }
                    $message .= implode(', ', $parts) . '.';
                    Notification::make()
                        ->title(__('Whoops!'))
                        ->body($message)
                        ->danger()
                        ->send();
                    $action->cancel();
                }
            })
                ->after(function (DeleteAction $action, Currency $record) {
                    // Remove carts with this currency
                    Cart::where('currency_code', $record->code)->delete();
                    Price::where('currency_code', $record->code)->delete();
                }),
        ];
    }
}
