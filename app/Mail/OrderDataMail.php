<?php

namespace App\Mail;

use App\Models\WmsContractorSetting;
use App\Models\WmsOrderDataFile;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * 発注データメール
 *
 * FAX PDF / CSV を添付して発注先に送信
 * テンプレートはモーダルから渡された値を優先、未指定時はwms_contractor_settings → デフォルト
 */
class OrderDataMail extends Mailable
{
    use Queueable, SerializesModels;

    public bool $attachCsv;

    public bool $attachFax;

    private ?string $overrideFromName;

    private ?string $overrideSubject;

    private ?string $overrideContent;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public WmsOrderDataFile $dataFile,
        bool $attachCsv = true,
        bool $attachFax = true,
        ?string $fromName = null,
        ?string $subject = null,
        ?string $content = null,
    ) {
        $this->attachCsv = $attachCsv;
        $this->attachFax = $attachFax;
        $this->overrideFromName = $fromName;
        $this->overrideSubject = $subject;
        $this->overrideContent = $content;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->resolveSubject();
        $fromName = $this->overrideFromName;

        if (! $fromName) {
            $setting = $this->getContractorSetting();
            $fromName = $setting?->order_mail_from;
        }

        if ($fromName) {
            $this->from(config('mail.from.address'), $fromName);
        }

        return new Envelope(subject: $subject);
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $customContent = $this->overrideContent;

        if (! $customContent) {
            $setting = $this->getContractorSetting();
            $customContent = $setting?->order_mail_content;
        }

        if ($customContent) {
            $body = $this->replaceVariables($customContent);

            return new Content(
                htmlString: nl2br(e($body)),
            );
        }

        // デフォルト: Bladeテンプレートを使用
        return new Content(
            text: 'emails.order-data',
            with: [
                'dataFile' => $this->dataFile,
                'contractor' => $this->dataFile->contractor,
                'warehouse' => $this->dataFile->warehouse,
                'orderDate' => $this->dataFile->order_date->format('Y年m月d日'),
                'expectedArrivalDate' => $this->dataFile->expected_arrival_date?->format('Y年m月d日') ?? '未定',
                'orderCount' => $this->dataFile->order_count,
                'totalQuantity' => $this->dataFile->total_quantity,
                'attachCsv' => $this->attachCsv,
                'attachFax' => $this->attachFax,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        $attachments = [];

        // CSV添付
        if ($this->attachCsv && $this->dataFile->file_path) {
            $csvContent = Storage::disk('s3')->get($this->dataFile->file_path);
            if ($csvContent) {
                $warehouseCode = $this->dataFile->warehouse?->code ?? $this->dataFile->warehouse_id;
                $contractorCode = $this->dataFile->contractor?->code ?? $this->dataFile->contractor_id;
                $filename = "発注データ_{$warehouseCode}_{$contractorCode}_{$this->dataFile->order_date->format('Ymd')}.csv";

                $attachments[] = Attachment::fromData(fn () => $csvContent, $filename)
                    ->withMime('text/csv');
            }
        }

        // FAX PDF添付
        if ($this->attachFax && $this->dataFile->fax_file_path) {
            $pdfContent = Storage::disk('s3')->get($this->dataFile->fax_file_path);
            if ($pdfContent) {
                $warehouseCode = $this->dataFile->warehouse?->code ?? $this->dataFile->warehouse_id;
                $contractorCode = $this->dataFile->contractor?->code ?? $this->dataFile->contractor_id;
                $filename = "発注書_{$warehouseCode}_{$contractorCode}_{$this->dataFile->order_date->format('Ymd')}.pdf";

                $attachments[] = Attachment::fromData(fn () => $pdfContent, $filename)
                    ->withMime('application/pdf');
            }
        }

        return $attachments;
    }

    /**
     * メールタイトルを解決（モーダル → DB設定 → デフォルト）
     */
    private function resolveSubject(): string
    {
        $title = $this->overrideSubject;

        if (! $title) {
            $setting = $this->getContractorSetting();
            $title = $setting?->order_mail_title;
        }

        if ($title) {
            return $this->replaceVariables($title);
        }

        $warehouseName = $this->dataFile->warehouse?->name ?? '倉庫';

        return "【発注書】{$warehouseName} - {$this->dataFile->order_date->format('Y/m/d')}";
    }

    /**
     * テンプレート内の$$VAR_XXX$$を実際の値に置換
     */
    private function replaceVariables(string $template): string
    {
        $attachmentLines = [];
        if ($this->attachCsv) {
            $attachmentLines[] = '・発注データ（CSV形式）';
        }
        if ($this->attachFax) {
            $attachmentLines[] = '・発注書（PDF形式）';
        }

        $variables = [
            '$$VAR_CONTRACTOR_NAME$$' => $this->dataFile->contractor?->name ?? '発注先',
            '$$VAR_WAREHOUSE_NAME$$' => $this->dataFile->warehouse?->name ?? '倉庫',
            '$$VAR_ORDER_DATE$$' => $this->dataFile->order_date->format('Y年m月d日'),
            '$$VAR_ORDER_DATE_SHORT$$' => $this->dataFile->order_date->format('Y/m/d'),
            '$$VAR_EXPECTED_ARRIVAL_DATE$$' => $this->dataFile->expected_arrival_date?->format('Y年m月d日') ?? '未定',
            '$$VAR_ORDER_COUNT$$' => number_format($this->dataFile->order_count),
            '$$VAR_TOTAL_QUANTITY$$' => number_format($this->dataFile->total_quantity),
            '$$VAR_ATTACHMENTS$$' => implode("\n", $attachmentLines),
        ];

        return str_replace(array_keys($variables), array_values($variables), $template);
    }

    private function getContractorSetting(): ?WmsContractorSetting
    {
        return WmsContractorSetting::where('contractor_id', $this->dataFile->contractor_id)->first();
    }
}
