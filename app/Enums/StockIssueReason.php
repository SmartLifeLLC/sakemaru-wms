<?php

namespace App\Enums;

enum StockIssueReason: string
{
    case EXPIRED = 'expired';
    case DAMAGED = 'damaged';
    case IN_STORE_PROMOTION_GIFT = 'in_store_promotion_gift';
    case IN_STORE_PROMOTION_TASTING = 'in_store_promotion_tasting';
    case CUSTOMER_PROMOTION_SPONSORSHIP = 'customer_promotion_sponsorship';
    case ENTERTAINMENT_COURTESY = 'entertainment_courtesy';

    public function label(): string
    {
        return match ($this) {
            self::EXPIRED => '賞味期限切れ',
            self::DAMAGED => '破損',
            self::IN_STORE_PROMOTION_GIFT => '店内販促（景品）',
            self::IN_STORE_PROMOTION_TASTING => '店内販促（試飲・試食）',
            self::CUSTOMER_PROMOTION_SPONSORSHIP => '得意先販促（協賛）',
            self::ENTERTAINMENT_COURTESY => '交際費（慶弔）',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $reason): array => [$reason->value => $reason->label()])
            ->all();
    }
}
