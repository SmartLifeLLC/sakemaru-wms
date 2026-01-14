<?php

namespace App\Enums;

enum EWMSLogOperationType: string
{
    case ASSIGN_PICKER = 'ASSIGN_PICKER'; // ピッカー割り当て
    case UNASSIGN_PICKER = 'UNASSIGN_PICKER'; // 担当解除
    case CHANGE_DELIVERY_COURSE = 'CHANGE_DELIVERY_COURSE'; // 配送コース変更
    case CHANGE_WAREHOUSE = 'CHANGE_WAREHOUSE'; // 倉庫変更
    case ADJUST_PICKING_QTY = 'ADJUST_PICKING_QTY'; // ピッキング数調節
    case REVERT_PICKING = 'REVERT_PICKING'; // ピッキング取り消し
    case PRINT_SHIPMENT_SLIP = 'PRINT_SHIPMENT_SLIP'; // 出荷伝票印刷
    case FORCE_PRINT_SHIPMENT_SLIP = 'FORCE_PRINT_SHIPMENT_SLIP'; // 出荷伝票強制印刷
    case FORCE_SHIP = 'FORCE_SHIP'; // 強制出荷

    /**
     * 操作タイプのラベルを取得
     */
    public function label(): string
    {
        return match ($this) {
            self::ASSIGN_PICKER => 'ピッカー割り当て',
            self::UNASSIGN_PICKER => '担当解除',
            self::CHANGE_DELIVERY_COURSE => '配送コース変更',
            self::CHANGE_WAREHOUSE => '倉庫変更',
            self::ADJUST_PICKING_QTY => 'ピッキング数調節',
            self::REVERT_PICKING => 'ピッキング取り消し',
            self::PRINT_SHIPMENT_SLIP => '出荷伝票印刷',
            self::FORCE_PRINT_SHIPMENT_SLIP => '出荷伝票強制印刷',
            self::FORCE_SHIP => '強制出荷',
        };
    }

    /**
     * 全ての操作タイプの選択肢を取得
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
            ->toArray();
    }
}
