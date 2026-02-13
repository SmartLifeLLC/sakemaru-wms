<?php

namespace Database\Seeders;

use App\Enums\AutoOrder\TransmissionType;
use App\Models\Sakemaru\Contractor;
use App\Models\WmsContractorSetting;
use Illuminate\Database\Seeder;

/**
 * 発注先メールテンプレート初期設定シーダー
 *
 * 全発注先に対してデフォルトのメール送信設定を投入する。
 * 既に設定済み（order_mail_contentがnull以外）のレコードはスキップ。
 *
 * 実行方法:
 *   php artisan db:seed --class=ContractorMailSettingSeeder
 */
class ContractorMailSettingSeeder extends Seeder
{
    private const DEFAULT_FROM_NAME = '華発注担当';

    private const DEFAULT_TITLE = '【株式会社華】発注データ送信（$$VAR_ORDER_DATE$$）';

    private const DEFAULT_CONTENT = <<<'TEMPLATE'
$$VAR_CONTRACTOR_NAME$$ 様

いつもお世話になっております。
$$VAR_WAREHOUSE_NAME$$より発注データをお送りいたします。

■ 発注情報
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
発注日　　　：$$VAR_ORDER_DATE$$
入荷予定日　：$$VAR_EXPECTED_ARRIVAL_DATE$$
発注件数　　：$$VAR_ORDER_COUNT$$件
合計数量　　：$$VAR_TOTAL_QUANTITY$$

■ 添付ファイル
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
$$VAR_ATTACHMENTS$$

ご確認のほど、よろしくお願いいたします。

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
※このメールはシステムから自動送信されています。
本メールへの返信には対応しておりません。
お問い合わせは発注担当までにご連絡お願いいたします。



TEMPLATE;

    public function run(): void
    {
        $contractors = Contractor::all();
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($contractors as $contractor) {
            $setting = WmsContractorSetting::where('contractor_id', $contractor->id)->first();

            if ($setting) {
                $setting->update([
                    'order_mail_from' => self::DEFAULT_FROM_NAME,
                    'order_mail_title' => self::DEFAULT_TITLE,
                    'order_mail_content' => self::DEFAULT_CONTENT,
                ]);
                $updated++;
            } else {
                WmsContractorSetting::create([
                    'contractor_id' => $contractor->id,
                    'transmission_type' => TransmissionType::MANUAL_CSV,
                    'order_mail_from' => self::DEFAULT_FROM_NAME,
                    'order_mail_title' => self::DEFAULT_TITLE,
                    'order_mail_content' => self::DEFAULT_CONTENT,
                ]);
                $created++;
            }
        }

        $this->command->info("メール設定完了: 新規={$created}, 更新={$updated}, スキップ={$skipped}");
    }
}
