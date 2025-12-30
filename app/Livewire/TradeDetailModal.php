<?php

namespace App\Livewire;

use App\Models\Sakemaru\Trade;
use App\Services\DeliveryCourseChangeService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;

class TradeDetailModal extends Component implements HasForms
{
    use InteractsWithForms;

    public $tradeId;
    public $tradeData = [];
    public $newDeliveryCourseId;
    public $availableCourses = [];

    public function mount(int $tradeId)
    {
        $this->tradeId = $tradeId;
        $this->loadTradeDetails();
        $this->loadAvailableCourses();
        
        $this->form->fill([
            'newDeliveryCourseId' => $this->newDeliveryCourseId,
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Select::make('newDeliveryCourseId')
                    ->hiddenLabel()
                    ->options($this->availableCourses)
                    ->searchable()
                    ->required()
                    ->placeholder('配送コースを選択')
                    ->extraAttributes(['class' => 'min-w-[200px]']),
            ]);
    }

    public function loadTradeDetails()
    {
        $trade = DB::connection('sakemaru')
            ->table('trades')
            ->where('id', $this->tradeId)
            ->first();

        if (!$trade) {
            return;
        }

        $earning = DB::connection('sakemaru')
            ->table('earnings')
            ->where('trade_id', $this->tradeId)
            ->first();

        $partner = DB::connection('sakemaru')
            ->table('partners')
            ->where('id', $trade->partner_id)
            ->first();

        $deliveryCourse = null;
        if ($earning && $earning->delivery_course_id) {
            $deliveryCourse = DB::connection('sakemaru')
                ->table('delivery_courses')
                ->where('id', $earning->delivery_course_id)
                ->first();
            $this->newDeliveryCourseId = $earning->delivery_course_id;
        }

        $tradeItems = DB::connection('sakemaru')
            ->table('trade_items')
            ->where('trade_id', $this->tradeId)
            ->get();

        foreach ($tradeItems as $item) {
            $itemData = DB::connection('sakemaru')
                ->table('items')
                ->where('id', $item->item_id)
                ->first();
            $item->item = $itemData;

            $pickingResult = DB::connection('sakemaru')
                ->table('wms_picking_item_results')
                ->where('trade_item_id', $item->id)
                ->first();
            $item->picking_result = $pickingResult;
        }

        $buyer = DB::connection('sakemaru')
            ->table('buyers')
            ->where('partner_id', $partner->id)
            ->first();

        $buyerDetail = null;
        $salesman = null;
        if ($buyer) {
            $buyerDetail = DB::connection('sakemaru')
                ->table('buyer_details')
                ->where('buyer_id', $buyer->id)
                ->orderBy('start_date', 'desc')
                ->first();

            if ($buyerDetail && $buyerDetail->salesman_id) {
                $salesman = DB::connection('sakemaru')
                    ->table('users')
                    ->where('id', $buyerDetail->salesman_id)
                    ->first();
            }
        }

        $tradePrice = DB::connection('sakemaru')
            ->table('trade_prices')
            ->where('trade_id', $this->tradeId)
            ->first();

        $tradeBalances = DB::connection('sakemaru')
            ->table('trade_balances')
            ->where('trade_id', $this->tradeId)
            ->get();

        $this->tradeData = [
            'trade' => $trade,
            'earning' => $earning,
            'partner' => $partner,
            'buyer' => $buyer,
            'buyer_detail' => $buyerDetail,
            'salesman' => $salesman,
            'delivery_course' => $deliveryCourse,
            'trade_items' => $tradeItems,
            'trade_price' => $tradePrice,
            'trade_balances' => $tradeBalances,
        ];
    }

    public function loadAvailableCourses()
    {
        $warehouseId = null;

        // 1. Try to get warehouse_id from the current delivery course
        if (isset($this->tradeData['delivery_course']) && $this->tradeData['delivery_course']->warehouse_id) {
            $warehouseId = $this->tradeData['delivery_course']->warehouse_id;
        }

        // 2. If not found, try to get from picking task
        if (!$warehouseId) {
            $pickingTask = DB::connection('sakemaru')
                ->table('wms_picking_item_results')
                ->where('trade_id', $this->tradeId)
                ->join('wms_picking_tasks', 'wms_picking_item_results.picking_task_id', '=', 'wms_picking_tasks.id')
                ->select('wms_picking_tasks.warehouse_id')
                ->first();
            
            if ($pickingTask) {
                $warehouseId = $pickingTask->warehouse_id;
            }
        }

        if ($warehouseId) {
            $this->availableCourses = DB::connection('sakemaru')
                ->table('delivery_courses')
                ->where('warehouse_id', $warehouseId)
                ->where('is_active', true)
                ->orderBy('code')
                ->get()
                ->mapWithKeys(function ($course) {
                    return [$course->id => "{$course->code} - {$course->name}"];
                })
                ->toArray();
        } else {
             // Fallback: fetch all active courses if warehouse not found
             $this->availableCourses = DB::connection('sakemaru')
                ->table('delivery_courses')
                ->where('is_active', true)
                ->orderBy('code')
                ->get()
                ->mapWithKeys(function ($course) {
                    return [$course->id => "{$course->code} - {$course->name}"];
                })
                ->toArray();
        }
    }

    public function updateDeliveryCourse()
    {
        $data = $this->form->getState();
        $newCourseId = $data['newDeliveryCourseId'];

        $service = app(DeliveryCourseChangeService::class);

        try {
            $service->changeDeliveryCourse($this->tradeId, $newCourseId);

            Notification::make()
                ->title('配送コース変更完了')
                ->success()
                ->body("配送コースを変更しました。")
                ->send();

            $this->loadTradeDetails(); // Reload data to reflect changes
            // Update form state
            $this->newDeliveryCourseId = $newCourseId;
            $this->form->fill([
                'newDeliveryCourseId' => $this->newDeliveryCourseId,
            ]);

        } catch (\Exception $e) {
            Notification::make()
                ->title('配送コース変更失敗')
                ->danger()
                ->body($e->getMessage())
                ->send();
        }
    }

    public function render()
    {
        return view('livewire.trade-detail-modal', ['trade' => $this->tradeData]);
    }
}
