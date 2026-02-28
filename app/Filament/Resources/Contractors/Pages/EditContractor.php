<?php

namespace App\Filament\Resources\Contractors\Pages;

use App\Filament\Resources\Contractors\ContractorResource;
use App\Models\WmsContractorSetting;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditContractor extends EditRecord
{
    protected static string $resource = ContractorResource::class;

    protected static ?string $title = '発注先編集';

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('保存')
                ->action('save')
                ->keyBindings(['mod+s']),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $wmsSetting = $this->record->wmsSetting;

        if ($wmsSetting) {
            $data['wms_order_mail'] = $wmsSetting->order_mail;
            $data['wms_order_mail_from'] = $wmsSetting->order_mail_from;
            $data['wms_order_mail_title'] = $wmsSetting->order_mail_title;
            $data['wms_order_mail_content'] = $wmsSetting->order_mail_content;
            $data['wms_transmission_type'] = $wmsSetting->transmission_type?->value;
            $data['wms_order_jx_setting_id'] = $wmsSetting->wms_order_jx_setting_id;
            $data['wms_order_ftp_setting_id'] = $wmsSetting->wms_order_ftp_setting_id;
            $data['wms_supply_warehouse_id'] = $wmsSetting->supply_warehouse_id;
            $data['wms_auto_order_generation_time'] = $wmsSetting->auto_order_generation_time;
            $data['wms_transmission_time'] = $wmsSetting->transmission_time;
            $data['wms_is_transmission_mon'] = $wmsSetting->is_transmission_mon;
            $data['wms_is_transmission_tue'] = $wmsSetting->is_transmission_tue;
            $data['wms_is_transmission_wed'] = $wmsSetting->is_transmission_wed;
            $data['wms_is_transmission_thu'] = $wmsSetting->is_transmission_thu;
            $data['wms_is_transmission_fri'] = $wmsSetting->is_transmission_fri;
            $data['wms_is_transmission_sat'] = $wmsSetting->is_transmission_sat;
            $data['wms_is_transmission_sun'] = $wmsSetting->is_transmission_sun;
            $data['wms_is_auto_transmission'] = $wmsSetting->is_auto_transmission;
            $data['wms_transmission_contractor_id'] = $wmsSetting->transmission_contractor_id;
            $data['wms_format_strategy_class'] = $wmsSetting->format_strategy_class;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $data = $this->form->getState();

        $wmsFields = [
            'order_mail' => $data['wms_order_mail'] ?? null,
            'order_mail_from' => $data['wms_order_mail_from'] ?? null,
            'order_mail_title' => $data['wms_order_mail_title'] ?? null,
            'order_mail_content' => $data['wms_order_mail_content'] ?? null,
            'transmission_type' => $data['wms_transmission_type'] ?? null,
            'wms_order_jx_setting_id' => $data['wms_order_jx_setting_id'] ?? null,
            'wms_order_ftp_setting_id' => $data['wms_order_ftp_setting_id'] ?? null,
            'supply_warehouse_id' => $data['wms_supply_warehouse_id'] ?? null,
            'auto_order_generation_time' => $data['wms_auto_order_generation_time'] ?? null,
            'transmission_time' => $data['wms_transmission_time'] ?? null,
            'is_transmission_mon' => $data['wms_is_transmission_mon'] ?? false,
            'is_transmission_tue' => $data['wms_is_transmission_tue'] ?? false,
            'is_transmission_wed' => $data['wms_is_transmission_wed'] ?? false,
            'is_transmission_thu' => $data['wms_is_transmission_thu'] ?? false,
            'is_transmission_fri' => $data['wms_is_transmission_fri'] ?? false,
            'is_transmission_sat' => $data['wms_is_transmission_sat'] ?? false,
            'is_transmission_sun' => $data['wms_is_transmission_sun'] ?? false,
            'is_auto_transmission' => $data['wms_is_auto_transmission'] ?? false,
            'transmission_contractor_id' => $data['wms_transmission_contractor_id'] ?? null,
            'format_strategy_class' => $data['wms_format_strategy_class'] ?? null,
        ];

        $wmsSetting = WmsContractorSetting::findOrCreateByContractor($this->record->id);
        $wmsSetting->fill($wmsFields);
        $wmsSetting->save();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
