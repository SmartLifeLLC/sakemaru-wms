<x-filament-panels::page>
    @php
        $run = $this->run;
        $working = $run?->isProcessing() ?? false;
        $complete = $run?->isCompleted() ?? false;
        $failed = $run?->isFailed() ?? false;
        $progress = $run?->filters['progress'] ?? [];
        $percent = $complete ? 100 : min(99, max(0, (int) ($progress['percent'] ?? 0)));
    @endphp
    <div class="warehouse-review">
        <div class="wr-intro">
            <p>倉庫ごとの在庫・販売実績・入荷予定を、発注検討用のExcelにまとめます。</p>
            <span class="wr-format">XLSX</span>
        </div>
        <ol class="wr-steps" aria-label="出力の流れ">
            <li class="is-current"><span>1</span>条件を指定</li>
            <li @class(['is-current' => $working || $complete || $failed])><span>2</span>Excelを作成</li>
            <li @class(['is-current' => $complete])><span>3</span>ダウンロード</li>
        </ol>

        <section class="wr-card" aria-labelledby="wr-conditions-title">
            <div class="wr-card-heading">
                <div><h2 id="wr-conditions-title">出力条件</h2><p>基準日と対象の倉庫を選択してください。</p></div>
                <x-filament::icon icon="heroicon-o-adjustments-horizontal" class="wr-heading-icon" />
            </div>
            <form wire:submit="export">
                <div class="wr-form">{{ $this->form }}</div>
                <div class="wr-form-footer">
                    <p><x-filament::icon icon="heroicon-o-information-circle" />在庫は生成時点の現在庫で出力します。</p>
                    <x-filament::button type="submit" color="primary" icon="heroicon-o-document-plus" :disabled="$working" wire:loading.attr="disabled" wire:target="export">
                        <span wire:loading.remove wire:target="export">{{ $working ? 'Excelを作成しています' : ($run ? 'もう一度作成' : 'Excelを作成') }}</span>
                        <span wire:loading wire:target="export">受け付けています…</span>
                    </x-filament::button>
                </div>
            </form>
        </section>

        <section @class(['wr-card', 'wr-result', 'is-working' => $working, 'is-complete' => $complete, 'is-failed' => $failed])
            @if($working) wire:poll.2s="refreshRun" @endif aria-labelledby="wr-result-title">
            <div class="wr-result-top">
                <div class="wr-result-title">
                    <div class="wr-status-icon">
                        <x-filament::icon :icon="$complete ? 'heroicon-o-check-circle' : ($failed ? 'heroicon-o-exclamation-circle' : ($working ? 'heroicon-o-arrow-path' : 'heroicon-o-document-arrow-down'))" :class="$working ? 'wr-spin' : ''" />
                    </div>
                    <div>
                        <h2 id="wr-result-title">{{ $complete ? 'ダウンロードの準備ができました' : ($failed ? 'Excelを作成できませんでした' : ($working ? 'Excelを作成しています' : 'Excelの作成を開始してください')) }}</h2>
                        <p>{{ $run ? '基準日 '.$run->filters['date'].'　／　['.$run->filters['warehouse_code'].'] '.$run->filters['warehouse_name'] : '条件を指定して「Excelを作成」を押すと、ここに生成状況が表示されます。' }}</p>
                    </div>
                </div>
                @if($complete)
                    <x-filament::button wire:click="download" color="success" icon="heroicon-o-arrow-down-tray" wire:loading.attr="disabled" wire:target="download">
                        <span wire:loading.remove wire:target="download">Excelをダウンロード</span>
                        <span wire:loading wire:target="download">ダウンロード中…</span>
                    </x-filament::button>
                @endif
            </div>
            @if($working)
                <div class="wr-progress" role="status" aria-live="polite">
                    <div class="wr-progress-label"><strong>{{ $progress['stage'] ?? '生成の受付が完了しました' }}</strong><span>{{ $percent }}%</span></div>
                    <div class="wr-progress-track" role="progressbar" aria-label="Excel生成の進捗" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}">
                        <div style="width: {{ $percent }}%"></div>
                    </div>
                    @if(($progress['total'] ?? 0) > 0)
                        <p>{{ number_format($progress['done']) }} / {{ number_format($progress['total']) }} 商品</p>
                    @endif
                    <p>処理段階ごとの進捗です。所要時間はデータ量により変わります。この画面を閉じても生成は続きます。</p>
                    @if($run->created_at->lt(now()->subMinutes(10)))
                        <p>処理の開始・完了に時間がかかっています。長時間変わらない場合は管理者へお知らせください。</p>
                    @endif
                </div>
            @elseif($complete)
                <div class="wr-file">
                    <x-filament::icon icon="heroicon-o-document-text" />
                    <div><strong>{{ $run->file_name }}</strong><p>{{ number_format($run->row_count) }} 商品<span>・</span>{{ $run->human_file_size }}<span>・</span>{{ $run->created_at->format('m/d H:i') }} 受付</p></div>
                    <span class="wr-ready">作成完了</span>
                </div>
                @if($run->row_count === 0)<p class="wr-empty">指定した条件の対象商品はありません。Excelには見出しのみ出力しています。</p>@endif
            @elseif($failed)
                <p class="wr-error" role="alert">時間をおいて「もう一度作成」を押してください。解消しない場合は、基準日と倉庫を管理者へお知らせください。</p>
            @endif
        </section>

        <details class="wr-help">
            <summary>Excelの出力内容について</summary>
            <div>
                <p>商品情報、在庫、当日営業出荷数、当日店舗出荷数、販売実績（1週・2週・3週・前月）、入荷予定数、商品発注管理の備考を出力します。</p>
                <p>並び順：発注先CD → 仕入先CD → 備考（空白を最後にして昇順） → 単品CD</p>
                <p>基準日は当日出荷と販売実績の集計基準です。入荷予定数は、基準日以降に予定のある日付のうち先頭7日分を表示します。</p>
            </div>
        </details>
    </div>
</x-filament-panels::page>
