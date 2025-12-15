<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * JX接続設定
 */
class WmsOrderJxSetting extends WmsModel
{
    protected $table = 'wms_order_jx_settings';

    protected $fillable = [
        'name',
        'van_center',
        'client_id',
        'server_id',
        'endpoint_url',
        'is_basic_auth',
        'basic_user_id',
        'basic_user_pw',
        'jx_from',
        'jx_to',
        'ssl_certification_file',
        'is_active',
    ];

    protected $casts = [
        'is_basic_auth' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'basic_user_pw',
    ];

    /**
     * パスワードを暗号化して保存
     */
    protected function basicUserPw(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value ? encrypt($value) : null,
            get: fn (?string $value) => $value ? decrypt($value) : null,
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
