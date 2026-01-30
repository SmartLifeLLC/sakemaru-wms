<?php

namespace App\Enums;

namespace App\Enums;

use App\Models\ContainerPickup;
use App\Models\ContainerReturn;
use App\Models\CustomModel;
use App\Models\Deposit;
use App\Models\Earning;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\RebateDeposit;
use App\Models\StockTransfer;
use App\Models\Trade;
use App\Traits\EnumExtensionTrait;
use Illuminate\Support\Arr;

enum TradeCategory: string
{
    use EnumExtensionTrait;

    case ORDER = 'ORDER';
    case PURCHASE = 'PURCHASE';
    //    case RECEIVED_ORDER = 'RECEIVED_ORDER';
    case EARNING = 'EARNING';
    case CONTAINER_PICKUP = 'CONTAINER_PICKUP';
    case CONTAINER_RETURN = 'CONTAINER_RETURN';
    case DEPOSIT = 'DEPOSIT';
    case REBATE_DEPOSIT = 'REBATE_DEPOSIT';
    case PAYMENT = 'PAYMENT';
    case STOCK_TRANSFER = 'STOCK_TRANSFER';

    public function name(): string
    {
        return match ($this) {
            self::ORDER => '発注',
            self::PURCHASE => '仕入',
            //            self::RECEIVED_ORDER => '発注',
            self::EARNING => '売上',
            self::CONTAINER_PICKUP => '容器回収',
            self::CONTAINER_RETURN => '容器返却',
            self::DEPOSIT => '入金',
            self::REBATE_DEPOSIT => 'リベート入金',
            self::PAYMENT => '支払',
            self::STOCK_TRANSFER => '倉庫移動'
        };
    }

    public static function itemTradeCategories(): array
    {
        return [
            self::ORDER,
            self::PURCHASE,
            //            self::RECEIVED_ORDER,
            self::EARNING,
            self::CONTAINER_PICKUP,
            self::CONTAINER_RETURN,
            self::STOCK_TRANSFER,
        ];
    }

    public static function balanceTradeCategories(): array
    {
        return [
            self::DEPOSIT,
            self::PAYMENT,
            self::REBATE_DEPOSIT,
        ];
    }

    public static function supplierClosingBillTrades(): array
    {
        return [
            self::PURCHASE,
            self::CONTAINER_RETURN,
            self::STOCK_TRANSFER,
            self::PAYMENT,
        ];
    }

    public static function buyerClosingBillTrades(): array
    {
        return [
            self::EARNING,
            self::CONTAINER_PICKUP,
            self::DEPOSIT,
        ];
    }

    public static function closingBillTrades(bool $is_supplier): array
    {
        return $is_supplier ? self::supplierClosingBillTrades() : self::buyerClosingBillTrades();
    }

    public function color(): ?BadgeColor
    {
        return match ($this) {
            self::ORDER => BadgeColor::RED,
            self::PURCHASE => BadgeColor::PURPLE,
            //            self::RECEIVED_ORDER => BadgeColor::INDIGO,
            self::EARNING => BadgeColor::BLUE,
            self::CONTAINER_PICKUP => BadgeColor::YELLOW,
            self::CONTAINER_RETURN => BadgeColor::PINK,
            self::DEPOSIT => BadgeColor::GREEN,
            self::REBATE_DEPOSIT => BadgeColor::ORANGE,
            self::PAYMENT => BadgeColor::GRAY,
            self::STOCK_TRANSFER => BadgeColor::BLACK
        };
    }

    public function detailRoute(bool $is_direct = false): WebRoute
    {
        if ($is_direct) {
            $route = match ($this) {
                self::PURCHASE,
                self::EARNING => WebRoute::EARNINGS_DIRECTS_FORM,
                self::CONTAINER_PICKUP,
                self::CONTAINER_RETURN => WebRoute::CONTAINERS_DIRECTS_FORM,
                default => null,
            };
            if ($route) {
                return $route;
            }
        }

        return match ($this) {
            self::PURCHASE => WebRoute::ORDERS_PURCHASES_FORM,
            self::EARNING => WebRoute::EARNINGS_FORM,
            self::CONTAINER_PICKUP => WebRoute::CONTAINERS_PICKUPS_FORM,
            self::CONTAINER_RETURN => WebRoute::CONTAINERS_RETURNS_FORM,
            self::DEPOSIT => WebRoute::DEPOSITS_FORM,
            self::REBATE_DEPOSIT => WebRoute::REBATE_DEPOSIT_FORM,
            self::PAYMENT => WebRoute::PAYMENTS_FORM,
            self::STOCK_TRANSFER => WebRoute::STOCKS_INVENTORY_TRANSFER_FORM,
            self::ORDER => WebRoute::ORDERS_FORM,
        };
        //        if (in_array($this, self::balanceTradeCategories())) {
        //            return WebRoute::TRADE_BALANCES;
        //        } else {
        //            return Webroute::TRADE_ITEMS;
        //        }
    }

    public function modelCls(): string
    {
        return match ($this) {
            self::ORDER => Order::class,
            self::PURCHASE => Purchase::class,
            self::EARNING => Earning::class,
            self::CONTAINER_PICKUP => ContainerPickup::class,
            self::CONTAINER_RETURN => ContainerReturn::class,
            self::DEPOSIT => Deposit::class,
            self::REBATE_DEPOSIT => RebateDeposit::class,
            self::PAYMENT => Payment::class,
            self::STOCK_TRANSFER => StockTransfer::class,
            self::ORDER => Order::class,
        };
    }

    public function detailModel(Trade $trade, bool $for_direct = false): ?CustomModel
    {
        if ($for_direct) {
            $model = match ($this) {
                self::PURCHASE => $trade->purchase?->direct_earning,
                self::CONTAINER_RETURN => $trade->container_return?->direct_earning,
                default => null,
            };
            if ($model) {
                return $model;
            }
        }

        return match ($this) {
            self::PURCHASE => $trade->purchase,
            self::EARNING => $trade->earning,
            self::CONTAINER_PICKUP => $trade->container_pickup,
            self::CONTAINER_RETURN => $trade->container_return,
            self::DEPOSIT => $trade->deposit,
            self::REBATE_DEPOSIT => $trade->rebate_deposit,
            self::PAYMENT => $trade->payment,
            self::STOCK_TRANSFER => $trade->stock_transfer,
            self::ORDER => $trade->order,
        };
    }

    public function isFromSupplier(): bool
    {
        return match ($this) {
            self::PURCHASE,
            self::CONTAINER_RETURN,
            self::PAYMENT,
            self::REBATE_DEPOSIT => true,
            default => false
        };
    }

    public function isBasePurchasePrice(): bool
    {
        return match ($this) {
            self::PURCHASE,
            self::CONTAINER_PICKUP => true,
            default => false
        };
    }

    public function autoFillPriceType(): EAutofillPriceType
    {
        return match ($this) {
            self::EARNING,
            self::CONTAINER_RETURN => EAutofillPriceType::SALE,
            self::STOCK_TRANSFER => EAutofillPriceType::COST,
            default => EAutofillPriceType::PURCHASE
        };
    }

    public function isBaseCostPrice(): bool
    {
        return match ($this) {
            self::STOCK_TRANSFER => true,
            default => false
        };
    }

    public function hasOrderQuantity(): bool
    {
        return match ($this) {
            self::EARNING => true,
            default => false
        };
    }

    public function isForContainer(): bool
    {
        return match ($this) {
            self::CONTAINER_PICKUP,
            self::CONTAINER_RETURN => true,
            default => false
        };
    }

    public function balanceDirection(): int
    {
        return match ($this) {
            self::PURCHASE,
            self::EARNING => 1,
            self::CONTAINER_PICKUP,
            self::CONTAINER_RETURN,
            self::DEPOSIT,
            self::PAYMENT => -1,
            self::STOCK_TRANSFER => 0
        };
    }

    public static function valueNames(): array
    {
        $array = [];
        foreach (self::cases() as $case) {
            $array[$case->value] = $case->name();
        }

        return $array;
    }

    public static function valueNamesForItems(): array
    {
        return Arr::mapWithKeys(self::itemTradeCategories(),
            fn ($case) => [$case->value => $case->name()]
        );
    }

    public static function valueNamesForBalances(): array
    {
        return Arr::mapWithKeys(self::balanceTradeCategories(),
            fn ($case) => [$case->value => $case->name()]
        );
    }

    public static function valueNamesForAchievement(): array
    {
        $categories = [
            self::PURCHASE, self::EARNING, self::CONTAINER_PICKUP, self::CONTAINER_RETURN,
        ];

        return Arr::mapWithKeys($categories,
            fn ($case) => [$case->value => $case->name()]
        );
    }

    public static function valueNamesForRebate(): array
    {
        $categories = [
            self::PURCHASE, self::EARNING,
        ];

        return Arr::mapWithKeys($categories,
            fn ($case) => [$case->value => ERebateConditionType::fromTradeCategory($case)->name()]
        );
    }
    //    public static function tradeModels() : string
    //    {
    //        return match ($this){
    //            self::ORDER => Order::class,
    //            self::PURCHASE => Purchase::class,
    //        }
    //    }
}
