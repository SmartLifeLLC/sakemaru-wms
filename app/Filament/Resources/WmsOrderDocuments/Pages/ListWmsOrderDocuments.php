<?php

namespace App\Filament\Resources\WmsOrderDocuments\Pages;

use App\Enums\AutoOrder\TransmissionDocumentStatus;
use App\Enums\AutoOrder\TransmissionType;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsOrderDocuments\WmsOrderDocumentResource;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderJxDocument;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListWmsOrderDocuments extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsOrderDocumentResource::class;

    public function table(\Filament\Tables\Table $table): \Filament\Tables\Table
    {
        // JX送信対象の発注先IDを取得
        $jxContractorIds = WmsContractorSetting::where('transmission_type', TransmissionType::JX_FINET)
            ->pluck('contractor_id')
            ->toArray();

        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['warehouse', 'contractor'])
                ->whereIn('contractor_id', $jxContractorIds)
                ->orderBy('created_at', 'desc')
            );
    }

    public function getPresetViews(): array
    {
        // JX送信対象の発注先IDを取得
        $jxContractorIds = WmsContractorSetting::where('transmission_type', TransmissionType::JX_FINET)
            ->pluck('contractor_id')
            ->toArray();

        // 各ステータスの件数を取得（JX対象のみ）
        $pendingCount = WmsOrderJxDocument::where('status', TransmissionDocumentStatus::PENDING)
            ->whereIn('contractor_id', $jxContractorIds)
            ->count();
        $transmittedCount = WmsOrderJxDocument::where('status', TransmissionDocumentStatus::TRANSMITTED)
            ->whereIn('contractor_id', $jxContractorIds)
            ->count();
        $cancelledCount = WmsOrderJxDocument::where('status', TransmissionDocumentStatus::CANCELLED)
            ->whereIn('contractor_id', $jxContractorIds)
            ->count();
        $testCount = WmsOrderJxDocument::whereIn('status', [
            TransmissionDocumentStatus::TEST,
            TransmissionDocumentStatus::DRAFT,
        ])
            ->whereIn('contractor_id', $jxContractorIds)
            ->count();

        return [
            'default' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TransmissionDocumentStatus::PENDING))
                ->favorite()
                ->label("送信前 ({$pendingCount})")
                ->default(),

            'transmitted' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TransmissionDocumentStatus::TRANSMITTED))
                ->favorite()
                ->label("送信済 ({$transmittedCount})"),

            'cancelled' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TransmissionDocumentStatus::CANCELLED))
                ->favorite()
                ->label("送信取消 ({$cancelledCount})"),

            'test' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    TransmissionDocumentStatus::TEST,
                    TransmissionDocumentStatus::DRAFT,
                ]))
                ->favorite()
                ->label("テスト ({$testCount})"),

            'all' => PresetView::make()
                ->favorite()
                ->label('全て'),
        ];
    }
}
