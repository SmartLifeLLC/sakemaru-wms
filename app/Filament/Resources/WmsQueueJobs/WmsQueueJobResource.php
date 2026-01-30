<?php

namespace App\Filament\Resources\WmsQueueJobs;

use App\Enums\EMenu;
use App\Filament\Resources\WmsQueueJobs\Pages\ListWmsQueueJobs;
use App\Filament\Resources\WmsQueueJobs\Tables\WmsQueueJobsTable;
use App\Models\WmsQueueJob;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsQueueJobResource extends Resource
{
    protected static ?string $model = WmsQueueJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_QUEUE_JOBS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_QUEUE_JOBS->label();
    }

    public static function getModelLabel(): string
    {
        return 'Queueジョブ';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Queueジョブ';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_QUEUE_JOBS->sort();
    }

    public static function table(Table $table): Table
    {
        return WmsQueueJobsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsQueueJobs::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
