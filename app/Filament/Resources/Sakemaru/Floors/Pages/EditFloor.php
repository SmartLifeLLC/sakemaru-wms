<?php

namespace App\Filament\Resources\Sakemaru\Floors\Pages;

use App\Filament\Resources\Sakemaru\Floors\FloorResource;
use App\Models\Sakemaru\Client;
use App\Models\Sakemaru\Warehouse;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFloor extends EditRecord
{
    protected static string $resource = FloorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // client_idは廃止予定だが、一旦はwarehouseのclient_idまたは最初のclientのIDを使用
        if (! isset($data['client_id']) || empty($data['client_id'])) {
            if (! empty($data['warehouse_id'])) {
                $warehouse = Warehouse::find($data['warehouse_id']);
                if ($warehouse) {
                    $data['client_id'] = $warehouse->client_id;
                }
            }

            // warehouseが見つからない場合は最初のclientを使用
            if (empty($data['client_id'])) {
                $firstClient = Client::first();
                if ($firstClient) {
                    $data['client_id'] = $firstClient->id;
                }
            }
        }

        // last_updater_idを設定（現在は0を使用）
        if (! isset($data['last_updater_id']) || empty($data['last_updater_id'])) {
            $data['last_updater_id'] = 0;
        }

        return $data;
    }
}
