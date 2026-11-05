<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\RegistersOnlyForKuraWarehouse;
use App\Filament\Support\AdminPage;
use App\Models\WmsDistributionBatch;
use Livewire\Attributes\Locked;

class DistributionAdjustmentDetail extends AdminPage
{
    use RegistersOnlyForKuraWarehouse;

    protected static string $permissionResource = 'distribution-adjustment';

    protected static ?string $slug = 'distribution-adjustment/{batch}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.distribution-app';

    #[Locked]
    public int $batchId;

    public static function getNavigationUrl(): string
    {
        // The mega menu builds a registry for every page, including hidden detail pages.
        return DistributionAdjustment::getUrl();
    }

    public function mount(int $batch): void
    {
        $this->batchId = $batch;
        $this->getDistributionBatchForView();
        ini_set('memory_limit', '512M');
    }

    public function getDistributionBatchForView(): array
    {
        $batch = WmsDistributionBatch::query()->where('client_id', (int) config('app.client_id'))
            ->where('warehouse_id', (int) auth()->user()->getSelectedWarehouseId())->with(['creator', 'updater'])->findOrFail($this->batchId);

        return ['id' => $batch->id, 'name' => $batch->name, 'shipping_date' => $batch->shipping_date?->format('Y-m-d'),
            'responsible_department' => $batch->responsible_department, 'responsible_department_label' => $batch->responsibleDepartmentLabel(),
            'note' => $batch->note, 'is_legacy' => $batch->is_legacy, 'revision' => $batch->revision, 'archived' => (bool) $batch->archived_at,
            'creator' => $batch->is_legacy ? '既存データ移行' : $batch->creator?->name,
            'warehouse_id' => $batch->warehouse_id, 'client_id' => $batch->client_id];
    }

    public function getDistributionInitialTab(): string
    {
        return 'allocation';
    }

    public function getTitle(): string
    {
        return '本部分配';
    }

    public function getHeading(): ?string
    {
        return null;
    }

    public function getMaxContentWidth(): ?string
    {
        return 'full';
    }
}
