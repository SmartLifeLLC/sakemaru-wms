<?php

namespace App\Filament\Pages;

use App\Enums\EMenu;
use App\Filament\Support\AdminPage;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Warehouse;
use App\Services\Statistics\WarehouseOrderReviewExport;
use App\Services\Statistics\WarehouseOrderReviewService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WarehouseOrderReview extends AdminPage
{
    // The report uses the existing product ordering master read permission.
    protected static string $permissionResource = 'item-contractor';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowDown;

    protected static ?string $slug = 'warehouse-order-review';

    protected static ?string $title = '倉庫別発注検討データ';

    protected string $view = 'filament.pages.warehouse-order-review';

    public array $data = [];

    public function mount(): void
    {
        $defaultWarehouse = $this->warehouses()->where('code', 91)->value('id');
        $this->form->fill(['date' => ClientSetting::systemDateYMD(), 'warehouse_id' => $defaultWarehouse]);
    }

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WAREHOUSE_ORDER_REVIEW->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WAREHOUSE_ORDER_REVIEW->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WAREHOUSE_ORDER_REVIEW->sort();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            DatePicker::make('date')->label('基準日')->required()->rules(['date_format:Y-m-d'])
                ->validationMessages(['required' => '基準日を入力してください。', 'date_format' => '基準日を正しい日付で入力してください。']),
            Select::make('warehouse_id')->label('倉庫')->required()->searchable()
                ->options(fn () => $this->warehouses()->orderBy('code')->get(['id', 'code', 'name'])
                    ->mapWithKeys(fn ($warehouse) => [$warehouse->id => "[{$warehouse->code}]{$warehouse->name}"]))
                ->validationMessages(['required' => '倉庫を選択してください。', 'in' => '出力できる倉庫を選択してください。']),
        ])->columns(2)->statePath('data');
    }

    private function clientId(): int
    {
        $clientId = (int) auth()->user()?->client_id;
        abort_if($clientId <= 0, 403);

        return $clientId;
    }

    private function warehouses(): \Illuminate\Database\Eloquent\Builder
    {
        return Warehouse::query()->where('client_id', $this->clientId())->where('is_virtual', false);
    }

    public function export(): ?StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        $warehouse = $this->warehouses()->find($data['warehouse_id']);
        if (! $warehouse) {
            throw ValidationException::withMessages(['data.warehouse_id' => '出力できる倉庫を選択してください。']);
        }

        $book = null;
        $path = null;
        try {
            $rows = app(WarehouseOrderReviewService::class)->rows($this->clientId(), $data['date'], (int) $warehouse->code);
            if ($rows === []) {
                Notification::make()->title('指定した条件の出力対象商品がありません。')->warning()->send();

                return null;
            }
            $book = app(WarehouseOrderReviewExport::class)->spreadsheet($rows, (int) $warehouse->code);
            $path = tempnam(sys_get_temp_dir(), 'warehouse_order_review_');
            (new Xlsx($book))->save($path);
            $binary = file_get_contents($path);
            $filename = '倉庫別発注検討データ_'.$warehouse->code.'_'.str_replace('-', '', $data['date']).'.xlsx';

            return response()->streamDownload(fn () => print ($binary), $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Excelを出力できませんでした。')->body('時間をおいて再度お試しください。解消しない場合は、基準日と倉庫を管理者へお知らせください。')->danger()->send();

            return null;
        } finally {
            $book?->disconnectWorksheets();
            if ($path && is_file($path)) {
                unlink($path);
            }
        }
    }
}
