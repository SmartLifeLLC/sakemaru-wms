<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\DB;

/**
 * JX接続設定
 */
class WmsOrderJxSetting extends WmsModel
{
    protected $table = 'wms_order_jx_settings';

    protected $fillable = [
        'name',
        'van_center',
        'jx_client_id',
        'server_id',
        'sender_trading_code',
        'sender_station_code',
        'sender_name',
        'sender_office_name',
        'receiver_trading_code',
        'receiver_station_code',
        'send_document_type',
        'receive_document_type',
        'endpoint_url',
        'is_basic_auth',
        'basic_user_id',
        'basic_user_pw',
        'jx_from',
        'jx_to',
        'ssl_certification_file',
        'test_file_path',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            // jx_client_idはclientsテーブルの最初のレコードで固定
            if (empty($model->jx_client_id)) {
                $model->jx_client_id = DB::connection('sakemaru')
                    ->table('clients')
                    ->orderBy('id')
                    ->value('id');
            }
        });
    }

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
