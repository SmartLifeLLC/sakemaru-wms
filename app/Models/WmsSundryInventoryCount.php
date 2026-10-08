<?php

namespace App\Models;

use App\Models\Sakemaru\User;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * 棚卸し（雑貨）ヘッダー。
 *
 * 既存の棚卸し（WmsInventoryCount）とは独立した、対象中分類を限定した金額ベースの棚卸し。
 */
class WmsSundryInventoryCount extends WmsModel
{
    public const STATUS_COUNTING = 'counting';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    /** 雑貨（対象中分類の数量明細 + 在庫管理なし商品の金額明細） */
    public const KIND_SUNDRY = 'sundry';

    /** 量り売り（100ml 単位の数量。実棚はカメ・QT の2欄、期間売上つき） */
    public const KIND_WEIGHED = 'weighed';

    protected $fillable = [
        'count_no',
        'kind',
        'client_id',
        'warehouse_id',
        'warehouse_code',
        'warehouse_name',
        'count_date',
        'status',
        'category_ids',
        'amount_category_ids',
        'amount_report_references',
        'theory_end_date',
        'theory_updated_at',
        'sales_from_date',
        'confirmed_at',
        'confirmed_by',
        'memo',
        'created_by',
    ];

    protected $casts = [
        'count_date' => 'date',
        'category_ids' => 'array',
        'amount_category_ids' => 'array',
        'amount_report_references' => 'array',
        'theory_end_date' => 'date',
        'theory_updated_at' => 'datetime',
        'sales_from_date' => 'date',
        'confirmed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(WmsSundryInventoryCountItem::class, 'sundry_inventory_count_id');
    }

    public function amounts(): HasMany
    {
        return $this->hasMany(WmsSundryInventoryCountAmount::class, 'sundry_inventory_count_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public static function generateCountNo(string $countDate): string
    {
        return 'SC-'.date('Ymd', strtotime($countDate)).'-'.Str::upper(Str::random(8));
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_COUNTING => '入力中',
            self::STATUS_CONFIRMED => '確定済',
            self::STATUS_CANCELLED => '取消',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function kindOptions(): array
    {
        return [
            self::KIND_SUNDRY => '雑貨',
            self::KIND_WEIGHED => '量り売り',
        ];
    }

    public function isWeighed(): bool
    {
        return $this->kind === self::KIND_WEIGHED;
    }

    public function getKindLabelAttribute(): string
    {
        return self::kindOptions()[$this->kind ?: self::KIND_SUNDRY] ?? (string) $this->kind;
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_COUNTING;
    }

    /**
     * @return array<int, int>
     */
    public function targetCategoryIds(): array
    {
        return array_values(array_unique(array_map('intval', array_filter((array) ($this->category_ids ?? [])))));
    }

    /**
     * 金額で棚卸しする大分類（在庫管理なし商品）。
     *
     * @return array<int, int>
     */
    public function amountCategoryIds(): array
    {
        return array_values(array_unique(array_map('intval', array_filter((array) ($this->amount_category_ids ?? [])))));
    }

    public function getStatusLabelAttribute(): string
    {
        return self::statusOptions()[$this->status] ?? (string) $this->status;
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_COUNTING => 'info',
            self::STATUS_CONFIRMED => 'success',
            self::STATUS_CANCELLED => 'danger',
            default => 'gray',
        };
    }
}
