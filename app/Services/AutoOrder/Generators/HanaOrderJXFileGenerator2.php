<?php

namespace App\Services\AutoOrder\Generators;

/**
 * ハナ様向け発注ファイル生成クラス（Aレコードなし版）
 *
 * データなし時にAレコードを送信しないバリエーション。
 * ステーションコード MB65D7 向け。
 */
class HanaOrderJXFileGenerator2 extends HanaOrderJXFileGenerator
{
    protected bool $addZeroRecord = false;
}
