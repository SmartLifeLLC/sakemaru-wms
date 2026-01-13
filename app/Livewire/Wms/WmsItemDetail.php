<?php

namespace App\Livewire\Wms;

use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\RealStock;
use Livewire\Attributes\On;
use Livewire\Component;

class WmsItemDetail extends Component
{
    public ?int $itemId = null;

    public ?Item $item = null;

    public array $stockInfo = [];

    #[On('item-selected')]
    public function loadItem(int $itemId): void
    {
        $this->itemId = $itemId;
        $this->item = Item::with(['itemCategory', 'supplier', 'warehouse'])->find($itemId);

        if ($this->item) {
            $this->loadStockInfo();
        }
    }

    private function loadStockInfo(): void
    {
        if (! $this->item) {
            return;
        }

        // Get real stock information with WMS fields
        // location, expiration_date等はreal_stock_lots経由で取得
        $stocks = RealStock::where('item_id', $this->item->id)
            ->with(['warehouse', 'activeLots.location'])
            ->get();

        $this->stockInfo = [
            'total_qty' => $stocks->sum('current_quantity'),
            'reserved_qty' => $stocks->sum('reserved_quantity'),
            'available_qty' => $stocks->sum('available_quantity'),
            'locations' => $stocks->map(function ($stock) {
                $firstLot = $stock->activeLots->first();

                return [
                    'warehouse_name' => $stock->warehouse?->name,
                    'location_name' => $firstLot?->location?->name,
                    'lot_no' => $stock->lot_no,
                    'expiration_date' => $firstLot?->expiration_date?->format('Y-m-d'),
                    'current_qty' => $stock->current_quantity,
                    'reserved_qty' => $stock->reserved_quantity,
                    'available_qty' => $stock->available_quantity,
                ];
            })->toArray(),
        ];
    }

    public function clear(): void
    {
        $this->itemId = null;
        $this->item = null;
        $this->stockInfo = [];
    }

    public function render()
    {
        return view('livewire.wms.wms-item-detail');
    }
}
