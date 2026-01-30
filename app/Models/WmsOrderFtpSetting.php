<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * FTP接続設定
 */
class WmsOrderFtpSetting extends WmsModel
{
    protected $table = 'wms_order_ftp_settings';

    protected $fillable = [
        'name',
        'host',
        'port',
        'username',
        'password',
        'protocol',
        'passive_mode',
        'remote_directory',
        'file_name_pattern',
        'is_active',
    ];

    protected $casts = [
        'passive_mode' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'password',
    ];

    /**
     * パスワードを暗号化して保存
     */
    protected function password(): Attribute
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

    /**
     * ファイル名を生成
     */
    public function generateFileName(?string $date = null): string
    {
        $date = $date ?? now()->format('Ymd');

        return str_replace('{date}', $date, $this->file_name_pattern);
    }
}
