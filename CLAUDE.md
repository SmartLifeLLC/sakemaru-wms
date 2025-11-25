# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 重要: 作業開始前の必読ドキュメント

新しいタスクを開始する前に、以下のドキュメントを必ず確認してください:

1. **Filament 4仕様**: `storage/specifications/filament4spec.md`
   - Filament 4の重要な変更点とベストプラクティス
   - テーブルクエリのカスタマイズ方法
   - アクションの位置制御
   - フォームコンポーネントの正しいインポートパス
   - よくあるエラーと解決方法

2. **WMS仕様**: `storage/specifications/2025-10-13-wms-specification.md`
   - システム全体の要件定義
   - データベース設計
   - ビジネスロジック

3. **欠品管理仕様**: `storage/specifications/wms-shortage-allocations/20251115-shorage-algorithm.md`
   - 欠品検出と代理出荷のアルゴリズム
   - データ構造と状態管理

これらのドキュメントに記載されている情報を優先して使用し、古い実装パターンを避けてください。

## Project Overview

Smart WMS (Warehouse Management System) - A Laravel 12 + Filament 4 admin panel application for warehouse management.

This WMS system integrates with a core business system (基幹システム) by:
- Referencing shared database tables for master data
- Managing WMS-specific operations in dedicated `wms_` tables
- Tracking stock reservations and allocations via columns added to the core `real_stocks` table
- **No foreign keys** - data integrity is maintained at the application/job level

**Tech Stack:**
- **Laravel 12** (PHP 8.2+)
- **Filament 4** (Admin Panel Framework)
- **Livewire 3** (For reactive components)
- **Tailwind CSS 4** (Styling)
- **Vite** (Asset bundling)
- **MySQL** (Production database via `sakemaru` connection)

## Development Commands

```bash
# Initial Setup
composer setup  # Installs dependencies, generates key, runs migrations, builds assets

# Development (runs server, queue, logs, and vite concurrently)
composer dev    # http://localhost:8000

# Testing
composer test                           # Clear config cache and run PHPUnit tests
php artisan test --filter=TestName      # Run specific test

# Code Quality
./vendor/bin/pint                       # Laravel Pint (code formatter)

# Assets
npm run build                           # Build production assets
npm run dev                             # Vite dev server

# Test Data Generation (project-specific)
php artisan wms:generate-test-data      # Generate WMS test data
php artisan wms:generate-waves          # Generate waves for testing
php artisan wms:generate-test-shortages # Generate shortage test data
php artisan wms:update-daily-stats      # Update daily statistics
```

## Architecture

### Database Connections

The project uses two database connections:
- **`sakemaru`**: Production MySQL database for core system integration (see `config/database.php`)
- **`sqlite`**: Default/testing database

**WMS Models** must extend `WmsModel` base class which sets the `sakemaru` connection:

```php
// app/Models/WmsModel.php
abstract class WmsModel extends Model
{
    protected $connection = 'sakemaru';
}
```

### Filament 4 Resource Structure

Resources follow this pattern (different from Filament 3):

```
app/Filament/Resources/
├── ModelResource.php           # Main resource class
├── Model/
│   ├── Pages/
│   │   ├── ListModel.php      # List page
│   │   ├── CreateModel.php    # Create page
│   │   └── EditModel.php      # Edit page
│   ├── Schemas/
│   │   └── ModelForm.php      # Form definition (uses Schema, not Form)
│   └── Tables/
│       └── ModelTable.php     # Table definition
```

### Service Layer

Business logic is organized in `app/Services/`:
- `StockAllocationService` - Stock allocation with FEFO→FIFO priority
- `WaveService` - Wave generation and management
- `Shortage/*` - Shortage detection, proxy shipment, confirmation services
- `Picking/*` - Route optimization (A* algorithm, distance caching)
- `Print/*` - Print request handling

### Key Filament 4 Patterns

**CRITICAL: These patterns are Filament 4 specific and differ from Filament 3**

```php
// Schema instead of Form
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;  // NOT Filament\Forms\Components\Section

// Table query customization
public function table(Table $table): Table
{
    return parent::table($table)
        ->modifyQueryUsing(fn (Builder $query) => $query->with(['relation']));
}

// Actions in modals use schema(), not form()
Action::make('myAction')
    ->schema([...])  // NOT ->form([...])
    ->action(function ($record, array $data) { ... });

// Table actions
$table->recordActions([...], position: RecordActionsPosition::BeforeColumns)
      ->toolbarActions([...]);  // NOT ->actions() and ->bulkActions()

// TextEntry for display-only fields (replaces Placeholder)
use Filament\Infolists\Components\TextEntry;
TextEntry::make('field')->state(fn ($record) => $record->value);
```

### Stock Allocation Strategy

**FEFO → FIFO Priority:**
1. **FEFO (First Expiry, First Out)**: Prioritize by `expiration_date` ASC (NULL values last)
2. **FIFO (First In, First Out)**: Within same expiry date, sort by `received_at` ASC
3. **Tie-breaker**: Sort by `real_stock_id` ASC

Uses `wms_v_stock_available` view for real-time available stock calculation.

### Quantity Type Display Guidelines

**IMPORTANT**: Always use `QuantityType` enum for displaying quantity types in the UI.

```php
use App\Enums\QuantityType;

// Correct terminology:
// CASE  → "ケース" (NOT "CS")
// PIECE → "バラ"   (NOT "個")
// CARTON → "ボール"

$caseLabel = QuantityType::CASE->name();   // "ケース"
$pieceLabel = QuantityType::PIECE->name(); // "バラ"

// For volume units
use App\Enums\EVolumeUnit;
$unit = EVolumeUnit::tryFrom($value)->name();  // ml, g, etc.
```

### Key Design Principles

1. **No Foreign Keys**: All relationships managed at application level
2. **Optimistic Locking**: Use `wms_lock_version` to detect concurrent stock updates
3. **Idempotency**: All allocation operations must be idempotent via `wms_idempotency_keys`
4. **Transaction Safety**: Stock reservations must be atomic (reservation + real_stocks update)

### WMS Tables (prefixed with `wms_`)

- `wms_reservations` - Stock allocation records
- `wms_waves` - Wave/batch picking operations
- `wms_picking_tasks` - Picking task management
- `wms_shortages` - Shortage records
- `wms_shortage_allocations` - Proxy shipment allocations
- `wms_picking_logs` - Picking operation logs
- `wms_pickers` - Picker (worker) records
- `wms_picking_areas` - Warehouse picking areas
- `wms_locations` / `wms_location_levels` - Location management