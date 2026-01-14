<?php

namespace App\Filament\Resources\Sakemaru\Warehouses\Pages;

use App\Filament\Resources\Sakemaru\Warehouses\WarehouseResource;
use App\Models\Sakemaru\Client;
use Filament\Resources\Pages\CreateRecord;

class CreateWarehouse extends CreateRecord
{
    protected static string $resource = WarehouseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // client_idは廃止予定だが、一旦は最初のclientのIDを使用
        if (! isset($data['client_id']) || empty($data['client_id'])) {
            $firstClient = Client::first();
            if ($firstClient) {
                $data['client_id'] = $firstClient->id;
            }
        }

        return $data;
    }
}
