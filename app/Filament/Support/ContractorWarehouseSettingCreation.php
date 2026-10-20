<?php

namespace App\Filament\Support;

use App\Models\WmsContractorWarehouseSetting;
use Filament\Actions\CreateAction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class ContractorWarehouseSettingCreation
{
    public const DUPLICATE_MESSAGE = 'この発注先と倉庫の設定は登録済みです。一覧から既存設定を編集してください。';

    public static function createFromAction(array $data, CreateAction $action): WmsContractorWarehouseSetting
    {
        $livewire = $action->getLivewire();
        $schema = $livewire->getSchema($livewire->getMountedActionSchemaName());

        return self::create($data, $schema->getStatePath());
    }

    public static function create(array $data, string $statePath): WmsContractorWarehouseSetting
    {
        try {
            return WmsContractorWarehouseSetting::create($data);
        } catch (UniqueConstraintViolationException $exception) {
            // Handle a concurrent insert after form validation without hiding other constraints.
            if (! str_contains($exception->getPrevious()?->getMessage() ?? '', 'uniq_wcws_warehouse_contractor')) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                ($statePath !== '' ? "{$statePath}." : '').'warehouse_id' => self::DUPLICATE_MESSAGE,
            ]);
        }
    }
}
