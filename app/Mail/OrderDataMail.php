<?php

namespace App\Mail;

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
 */
class OrderDataMail extends Mailable
{
    use Queueable, SerializesModels;

    public bool $attachCsv;

    public bool $attachFax;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public WmsOrderDataFile $dataFile,
        bool $attachCsv = true,
        bool $attachFax = true
    ) {
        $this->attachCsv = $attachCsv;
        $this->attachFax = $attachFax;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $contractor = $this->dataFile->contractor;
        $warehouseName = $this->dataFile->warehouse?->name ?? '倉庫';

        return new Envelope(
            subject: "【発注書】{$warehouseName} - {$this->dataFile->order_date->format('Y/m/d')}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.order-data',
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
}
