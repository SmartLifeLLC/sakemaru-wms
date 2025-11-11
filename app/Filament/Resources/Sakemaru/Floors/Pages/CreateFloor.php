<?php

namespace App\Filament\Resources\Sakemaru\Floors\Pages;

use App\Filament\Resources\Sakemaru\Floors\FloorResource;
use App\Models\Sakemaru\Client;
use App\Models\Sakemaru\Warehouse;
use Filament\Resources\Pages\CreateRecord;

class CreateFloor extends CreateRecord
{
    protected static string $resource = FloorResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // client_idは廃止予定だが、一旦はwarehouseのclient_idまたは最初のclientのIDを使用
        if (!isset($data['client_id']) || empty($data['client_id'])) {
            if (!empty($data['warehouse_id'])) {
                $warehouse = Warehouse::find($data['warehouse_id']);
                if ($warehouse) {
                    $data['client_id'] = $warehouse->client_id;
                    return $data;
                }
            }

            // warehouseが見つからない場合は最初のclientを使用
            $firstClient = Client::first();
            if ($firstClient) {
                $data['client_id'] = $firstClient->id;
            }
        }

        return $data;
    }
}
