<?php

namespace App\Admin\Resources\ProductResource\Pages;

use App\Admin\Actions\AuditAction;
use App\Admin\Resources\ProductResource;
use App\Helpers\ExtensionHelper;
use App\Models\ConfigOptionProduct;
use App\Models\Product;
use App\Models\Server;
use App\Models\ServiceUpgrade;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('duplicate')
                ->label(__('Duplicate'))
                ->authorize(fn () => ProductResource::canCreate())
                ->requiresConfirmation()
                ->action(function (Product $record) {
                    $new_record = DB::transaction(function () use ($record) {
                        $record->loadMissing(['settings', 'upgrades', 'plans.prices']);
                        $new_record = $record->replicate();
                        $new_record->name = __('Copy of ') . $record->name;
                        $new_record->hidden = true;
                        $slug = Str::slug($new_record->name);
                        $baseSlug = $slug;
                        $suffix = 2;
                        while (Product::where('slug', $slug)->exists()) {
                            $slug = $baseSlug . '-' . $suffix++;
                        }
                        $new_record->slug = $slug;
                        $new_record->save();

                        ConfigOptionProduct::where('product_id', $record->id)->get()->each(function (ConfigOptionProduct $configOption) use ($new_record) {
                            ConfigOptionProduct::create([
                                'config_option_id' => $configOption->config_option_id,
                                'product_id' => $new_record->id,
                            ]);
                        });

                        $record->settings->each(function ($setting) use ($new_record) {
                            $new_setting = $setting->replicate();
                            $new_setting->settingable_id = $new_record->id;
                            $new_setting->save();
                        });

                        $record->upgrades->each(function ($upgrade) use ($new_record) {
                            $new_record->upgrades()->attach($upgrade->id);
                        });

                        $record->plans->each(function ($plan) use ($new_record) {
                            $new_plan = $plan->replicate();
                            $new_plan->priceable_id = $new_record->id;
                            $new_plan->save();

                            $plan->prices->each(function ($price) use ($new_plan) {
                                $new_price = $price->replicate();
                                $new_price->plan_id = $new_plan->id;
                                $new_price->save();
                            });
                        });

                        return $new_record;
                    });

                    Notification::make()
                        ->title(__('Product duplicated successfully!'))
                        ->success()
                        ->send();

                    return $this->redirect(static::getResource()::getUrl('edit', [
                        'record' => $new_record,
                    ]), true);
                }),
            DeleteAction::make()
                ->before(function (Product $record, DeleteAction $action) {
                    if ($record->services()->count() > 0 || ServiceUpgrade::where('product_id', $record->id)->where('status', ServiceUpgrade::STATUS_PENDING)->exists()) {
                        Notification::make()
                            ->title(__('Whoops!'))
                            ->body(__('You cannot delete this product while it is used by a service or pending upgrade.'))
                            ->danger()
                            ->send();
                        $action->cancel();
                    }
                })->after(function (Product $record) {
                    $record->settings()->delete();
                }),
            AuditAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach ($this->record->settings as $setting) {
            $data['settings'][$setting->key] = $setting->value;
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if ($record->server_id != ($data['server_id'] ?? null) && $record->services()->exists()) {
            throw ValidationException::withMessages([
                'data.server_id' => __('You cannot change the server while this product has existing services. Create a new product to use another server.'),
            ]);
        }

        $product_config = isset($data['settings']) && isset($data['server_id'])
            ? ExtensionHelper::getProductConfig(Server::findOrFail($data['server_id']), $data['settings'])
            : null;

        return DB::transaction(function () use ($record, $data, $product_config): Model {
            $record->update(Arr::except($data, ['settings']));

            if (!$record->server_id) {
                $record->settings()->delete();

                return $record;
            }

            if ($product_config === null) {
                return $record;
            }

            $things = array_map(function ($option) use ($data, $record) {
                return [
                    'key' => $option['name'],
                    'settingable_id' => $record->id,
                    'settingable_type' => $record->getMorphClass(),
                    'type' => $option['database_type'] ?? 'string',
                    'value' => isset($data['settings'][$option['name']]) ? (is_array($data['settings'][$option['name']]) ? json_encode($data['settings'][$option['name']]) : $data['settings'][$option['name']]) : null,
                ];
            }, $product_config);

            $record->settings()->upsert($things, uniqueBy: [
                'key',
                'settingable_id',
                'settingable_type',
            ], update: [
                'type',
                'value',
            ]);
            $record->settings()->whereNotIn('key', array_column($product_config, 'name'))->delete();

            return $record;
        });
    }
}
