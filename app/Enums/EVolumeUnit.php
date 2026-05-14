<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EVolumeUnit: string
{
    use EnumExtensionTrait;

    case MILLILITER = 'MILLILITER';
    case GRAM = 'GRAM';
    case PIECE = 'PIECE';
    case PACK = 'PACK';
    case SHEET = 'SHEET';
    case BOTTLE = 'BOTTLE';
    case BAG = 'BAG';
    case GRAIN = 'GRAIN';
    case KILOGRAM = 'KILOGRAM';
    case INCLUDED_QUANTITY = 'INCLUDED_QUANTITY';

    public function name() : string
    {
        return match ($this) {
            self::MILLILITER => 'ml',
            self::GRAM => 'g',
            self::PIECE => '個',
            self::PACK => 'P',
            self::SHEET => '枚',
            self::BOTTLE => '本',
            self::BAG => '袋',
            self::GRAIN => '粒',
            self::KILOGRAM => 'Kg',
            self::INCLUDED_QUANTITY => '内数',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::MILLILITER => 0,
            self::GRAM => 1,
            self::PIECE => 2,
            self::PACK => 3,
            self::SHEET => 4,
            self::BOTTLE => 5,
            self::BAG => 6,
            self::GRAIN => 7,
            self::KILOGRAM => 8,
            self::INCLUDED_QUANTITY => 9,
        };
    }

    public function packagingVolume(int $volume): string {
        $is_kilo_unit  = $volume >= 1000 && $volume % 1000 == 0;
        $display_volume = $is_kilo_unit ? intval($volume / 1000) : $volume;
        if(auth()->user()->client->setting->uses_custom_packaging_connection){
            return $volume . auth()->user()->client->setting->custom_packaging_connection;
        }

        switch ($this) {
            case self::MILLILITER:
                return $volume . 'ml';
            case self::GRAM:
                return $volume . 'g';
            case self::KILOGRAM:
                return $display_volume . 'Kg';
            case self::INCLUDED_QUANTITY:
                if($volume > 0) {
                    return $volume . '×';
                } else {
                    return '×';
                }
            default:
                return $volume . $this->name();
        }
    }

    public static function fromPrevID(int $id) : self
    {
        return match($id) {
            0 => self::MILLILITER,
            1 => self::GRAM,
            2 => self::PIECE,
            3 => self::PACK,
            4 => self::SHEET,
            5 => self::BOTTLE,
            6 => self::BAG,
            7 => self::GRAIN,
            8 => self::KILOGRAM,
            9 => self::INCLUDED_QUANTITY,
            default => self::INCLUDED_QUANTITY,
        };
    }


    public function calculateLiter(string $value) : string
    {
        return match ($this) {
            self::MILLILITER => bcdiv($value, 1000, 2),
            self::KILOGRAM,
            self::GRAM => $value,
            default => $value,
        };
    }
}
