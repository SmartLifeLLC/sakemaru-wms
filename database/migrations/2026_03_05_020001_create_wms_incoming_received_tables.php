<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ファイル単位（Aレコード対応）
        Schema::connection('sakemaru')->create('wms_incoming_received_files', function (Blueprint $table) {
            $table->id();
            $table->integer('contractor_id')->nullable()->index();
            $table->string('filename', 255)->nullable();
            $table->string('format_type', 20)->default('JX'); // JX, CSV
            $table->string('status', 20)->default('PENDING'); // PENDING, MATCHED, APPLIED, ERROR

            // Aレコード全項目
            $table->string('a_data_type', 2)->nullable();           // データ種別
            $table->string('a_send_receive_type', 2)->nullable();   // 送受信区分
            $table->string('a_created_date', 6)->nullable();        // データ作成日 YYMMDD
            $table->string('a_created_time', 6)->nullable();        // データ作成時刻 HHMMSS
            $table->integer('a_record_count')->nullable();          // レコード件数(B+D)
            $table->integer('a_slip_count')->nullable();            // 帳票枚数(B数)
            $table->string('a_company_name', 30)->nullable();       // 社名

            // FINETヘッダー情報（ある場合のみ）
            $table->boolean('has_finet_wrapper')->default(false);
            $table->string('finet_sender_code', 20)->nullable();    // 提供企業CD
            $table->string('finet_sender_name', 30)->nullable();    // 提供企業名
            $table->integer('finet_record_count')->nullable();      // 送信データ件数

            $table->integer('parsed_slip_count')->default(0);       // パース済み伝票数
            $table->integer('parsed_detail_count')->default(0);     // パース済み明細数
            $table->text('error_message')->nullable();
            $table->integer('received_by')->nullable();
            $table->timestamps();
        });

        // 伝票単位（Bレコード対応）
        Schema::connection('sakemaru')->create('wms_incoming_received_slips', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('received_file_id')->index();
            $table->string('slip_number', 20)->index();             // 伝票番号（照合キー）
            $table->string('match_status', 20)->default('UNMATCHED'); // UNMATCHED, MATCHED, PARTIAL, SHORTAGE, NOT_FOUND

            // Bレコード全項目
            $table->string('b_data_type', 2)->nullable();           // データ種別
            $table->string('b_shop_code', 4)->nullable();           // 社・店コード
            $table->string('b_category_code', 3)->nullable();       // 分類コード
            $table->string('b_slip_type', 2)->nullable();           // 伝票区分 (01=発注, 02=納品)
            $table->string('b_order_date', 6)->nullable();          // 発注日 YYMMDD
            $table->string('b_delivery_date', 6)->nullable();       // 納品日 YYMMDD
            $table->string('b_delivery_route', 3)->nullable();      // 便
            $table->string('b_contractor_code', 4)->nullable();     // 取引先コード
            $table->string('b_shop_name', 30)->nullable();          // 店名
            $table->string('b_delivery_place', 20)->nullable();     // 納品場所
            $table->string('b_note', 50)->nullable();               // 備考(G)
            $table->string('b_direct_type', 2)->nullable();         // 直送区分

            // 照合結果
            $table->unsignedBigInteger('matched_schedule_id')->nullable()->index(); // 照合先の入荷予定ID
            $table->integer('detail_count')->default(0);
            $table->integer('shortage_count')->default(0);          // 欠品明細数
            $table->timestamps();
        });

        // 明細単位（Dレコード対応）
        Schema::connection('sakemaru')->create('wms_incoming_received_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('received_slip_id')->index();
            $table->unsignedBigInteger('received_file_id')->index();

            // Dレコード全項目
            $table->string('d_data_type', 2)->nullable();           // データ種別
            $table->integer('d_line_number')->nullable();           // 伝票行番号
            $table->string('d_product_name', 64)->nullable();       // 品名
            $table->string('d_jan_code', 13)->nullable()->index();  // JANコード
            $table->string('d_item_code', 6)->nullable()->index();  // 自社コード
            $table->integer('d_pack_quantity')->nullable();         // 入数
            $table->integer('d_case_quantity')->default(0);         // ケース数
            $table->integer('d_piece_quantity')->default(0);        // バラ数
            $table->bigInteger('d_unit_price')->nullable();         // 原単価（整数8桁+小数2桁）
            $table->string('d_total_pieces', 6)->nullable();        // 売単価/単品総数
            $table->string('d_note', 30)->nullable();               // 備考(G)
            $table->bigInteger('d_amount')->nullable();             // 原価金額

            // 計算値
            $table->integer('total_quantity')->default(0);          // 出荷総数（バラ換算）
            $table->boolean('is_shortage')->default(false);         // 欠品フラグ

            // 照合結果
            $table->string('match_status', 20)->default('UNMATCHED'); // UNMATCHED, MATCHED, SHORTAGE, PARTIAL, EXTRA
            $table->unsignedBigInteger('matched_item_id')->nullable(); // 照合先の商品ID
            $table->integer('expected_quantity')->nullable();       // 発注数量（照合後セット）
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_incoming_received_details');
        Schema::connection('sakemaru')->dropIfExists('wms_incoming_received_slips');
        Schema::connection('sakemaru')->dropIfExists('wms_incoming_received_files');
    }
};
