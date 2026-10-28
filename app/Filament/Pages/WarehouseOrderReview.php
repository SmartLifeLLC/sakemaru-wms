<?php

namespace App\Filament\Pages;

use App\Enums\EMenu;
use App\Filament\Support\AdminPage;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Warehouse;
use App\Services\Statistics\WarehouseOrderReviewRunService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
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

    #[\Livewire\Attributes\Locked]
    public ?int $runId = null;

    public function getRunProperty(): ?\App\Models\WmsExportLog
    {
        $query = app(WarehouseOrderReviewRunService::class)->owned((int) auth()->id(), $this->clientId());

        return $this->runId ? $query->find($this->runId) : null;
    }

    public function refreshRun(): void
    {
        abort_unless(static::canAccess(), 403);
        unset($this->run);
    }

    public function mount(): void
    {
        $defaultWarehouse = $this->warehouses()->where('code', 91)->value('id');
        $lastRun = app(WarehouseOrderReviewRunService::class)->owned((int) auth()->id(), $this->clientId())->latest('id')->first();
        $this->runId = $lastRun?->id;
        $this->form->fill([
            'date' => $lastRun?->filters['date'] ?? ClientSetting::systemDateYMD(),
            'warehouse_id' => $lastRun?->filters['warehouse_id'] ?? $defaultWarehouse,
        ]);
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

    public function export(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        $warehouse = $this->warehouses()->find($data['warehouse_id']);
        if (! $warehouse) {
            throw ValidationException::withMessages(['data.warehouse_id' => '出力できる倉庫を選択してください。']);
        }
        try {
            $run = app(WarehouseOrderReviewRunService::class)->start((int) auth()->id(), $this->clientId(), [
                'date' => $data['date'], 'warehouse_id' => $warehouse->id,
                'warehouse_code' => (int) $warehouse->code, 'warehouse_name' => $warehouse->name,
            ]);
            $this->runId = $run->id;
            unset($this->run);
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Excelの作成を開始できませんでした。')
                ->body('時間をおいて再度お試しください。解消しない場合は管理者へお知らせください。')->danger()->send();
        }
    }

    public function download(): ?StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        $run = $this->run;
        abort_unless($run, 404);
        try {
            return app(WarehouseOrderReviewRunService::class)->download($run);
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('ファイルを取得できませんでした。')
                ->body('もう一度Excelを作成してください。')->danger()->send();

            return null;
        }
    }
}
