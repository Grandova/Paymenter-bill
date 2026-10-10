<?php

namespace App\Admin\Resources\ProductResource\Pages;

use App\Admin\Resources\ProductResource;
use App\Helpers\ExtensionHelper;
use App\Models\Server;
use Arr;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $product_config = isset($data['settings'])
            ? ExtensionHelper::getProductConfig(Server::findOrFail($data['server_id']), $data['settings'])
            : null;

        return DB::transaction(function () use ($data, $product_config): Model {
            $record = static::getModel()::create(Arr::except($data, ['settings']));

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

            return $record;
        });
    }
}
