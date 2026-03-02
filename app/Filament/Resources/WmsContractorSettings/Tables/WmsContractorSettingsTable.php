<?php

namespace App\Filament\Resources\WmsContractorSettings\Tables;

use App\Enums\AutoOrder\TransmissionType;
use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Models\Sakemaru\Contractor;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsContractorSettingsTable
{
    use HasExportAction;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'sticky-actions'])
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['contractor', 'transmissionContractor', 'jxSetting', 'supplyWarehouse']))
            ->columns([
                TextColumn::make('contractor.code')
                    ->label('発注先CD')
                    ->searchable()
                    ->sortable()
                    ->width('80px'),

                TextColumn::make('contractor.name')
                    ->label('発注先名')
                    ->searchable()
                    ->sortable()
                    ->width('200px'),

                TextColumn::make('transmission_type')
                    ->label('送信方式')
                    ->badge()
                    ->formatStateUsing(fn (TransmissionType $state) => $state->label())
                    ->color(fn (TransmissionType $state) => match ($state) {
                        TransmissionType::JX_FINET => 'success',
                        TransmissionType::MANUAL_CSV => 'gray',
                        TransmissionType::FTP => 'info',
                        TransmissionType::INTERNAL => 'warning',
                    })
                    ->sortable(),

                TextColumn::make('jxSetting.name')
                    ->label('JX設定')
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('transmissionContractor.name')
                    ->label('発注データ集約先')
                    ->placeholder('-')
                    ->tooltip('この発注先の発注データは、指定した発注先の設定で送信されます'),

                TextColumn::make('supplyWarehouse.name')
                    ->label('供給倉庫')
                    ->placeholder('-')
                    ->visible(fn ($record) => $record?->transmission_type === TransmissionType::INTERNAL),

                TextColumn::make('transmission_time')
                    ->label('送信時刻')
                    ->placeholder('-')
                    ->alignCenter(),

                TextColumn::make('auto_order_generation_time')
                    ->label('自動発注時刻')
                    ->placeholder('-')
                    ->alignCenter(),

                TextColumn::make('transmission_days_label')
                    ->label('送信曜日')
                    ->placeholder('-')
                    ->alignCenter(),

                IconColumn::make('is_auto_transmission')
                    ->label('自動送信')
                    ->boolean()
                    ->alignCenter(),

                TextColumn::make('created_at')
                    ->label('作成日')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('contractor_id')
                    ->label('発注先')
                    ->options(fn () => Contractor::all()->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}] {$c->name}"])->toArray())
                    ->searchable(),

                SelectFilter::make('transmission_type')
                    ->label('送信方式')
                    ->options(collect(TransmissionType::cases())->mapWithKeys(fn ($type) => [$type->value => $type->label()])->toArray()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                static::getExportAction(),
                CreateAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('contractor_id', 'asc');
    }
}
