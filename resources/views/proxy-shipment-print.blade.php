<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>移動伝票印刷</title>
    @vite(['resources/js/proxyShipmentPrint.js'])
    <style>
        :root { color-scheme: light; --ink: #20324a; --muted: #718096; --line: #e3eaf2; --blue: #2563eb; --green: #16856d; }
        * { box-sizing: border-box; }
        [hidden] { display: none !important; }
        body { margin: 0; background: #f2f5f9; color: var(--ink); font-family: -apple-system, BlinkMacSystemFont, "Hiragino Kaku Gothic ProN", "Yu Gothic", sans-serif; font-size: 14px; line-height: 1.7; }
        button, a { -webkit-tap-highlight-color: transparent; }
        button { font: inherit; cursor: pointer; }
        button:focus-visible, a:focus-visible { outline: 3px solid #93b5ff; outline-offset: 3px; }
        .topbar { height: 68px; padding: 0 32px; display: flex; justify-content: space-between; align-items: center; background: #fff; border-bottom: 1px solid var(--line); }
        .brand { display: flex; gap: 12px; align-items: center; font-weight: 750; font-size: 18px; letter-spacing: .05em; }
        .brand-mark { width: 34px; height: 34px; display: grid; place-items: center; background: #203958; color: #fff; border-radius: 10px; font-size: 19px; }
        .topbar-label { color: var(--muted); font-size: 12px; }
        .page-shell { width: min(1040px, calc(100% - 48px)); margin: 28px auto 40px; }
        .breadcrumb { color: var(--muted); font-size: 12px; margin: 0 0 18px; display: flex; gap: 10px; align-items: center; }
        .breadcrumb strong { color: #52647b; font-weight: 500; }
        .process-card, .preview-card { background: #fff; border: 1px solid var(--line); border-radius: 18px; box-shadow: 0 6px 22px #233e6010; overflow: hidden; }
        .process-card { padding: 30px 32px 26px; }
        .card-heading { display: flex; justify-content: space-between; gap: 16px; align-items: center; }
        .eyebrow { margin: 0 0 4px; color: var(--muted); font-size: 11px; font-weight: 600; letter-spacing: .13em; }
        h1 { margin: 0; font-size: 23px; line-height: 1.5; letter-spacing: -.02em; }
        .registered { display: flex; gap: 7px; align-items: center; color: var(--green); background: #eef9f4; border: 1px solid #d6efe3; padding: 5px 12px; border-radius: 100px; font-size: 12px; white-space: nowrap; }
        svg { display: block; flex-shrink: 0; }
        .steps { display: grid; grid-template-columns: repeat(4, 1fr); list-style: none; padding: 0; margin: 28px 0; }
        .step { position: relative; text-align: center; }
        .step:not(:last-child)::after { content: ""; position: absolute; top: 17px; left: calc(50% + 25px); width: calc(100% - 50px); height: 2px; background: var(--line); }
        .step[data-state="done"]::after { background: #bce3d5; }
        .step-node { position: relative; z-index: 1; margin: 0 auto 8px; width: 36px; height: 36px; display: grid; place-items: center; border: 1px solid #dce4ed; border-radius: 50%; background: #f7f9fc; color: #96a3b5; font-size: 13px; font-weight: 650; }
        .step-check { display: none; }
        .step[data-state="done"] .step-node { color: var(--green); border-color: #d7eee4; background: #eaf7f0; }
        .step[data-state="done"] .step-number { display: none; }
        .step[data-state="done"] .step-check { display: block; }
        .step[data-state="active"] .step-node { color: #fff; background: var(--blue); border-color: var(--blue); box-shadow: 0 0 0 5px #edf3ff; }
        .step[data-state="error"] .step-node { color: #c54343; background: #fff1f1; border-color: #f1c6c6; }
        .step-title { display: block; font-size: 13px; font-weight: 650; }
        .step[data-state="waiting"] .step-title { color: #8d99ab; }
        .step-detail { display: block; margin-top: 2px; font-size: 11px; color: var(--muted); }
        .step[data-state="active"] .step-detail { color: var(--blue); }
        .step[data-state="error"] .step-detail { color: #c54343; }
        .status-panel { display: flex; align-items: center; gap: 20px; background: #f4f7ff; border: 1px solid #e2eaff; border-radius: 12px; padding: 22px 24px; }
        .status-symbol { flex-shrink: 0; position: relative; width: 56px; height: 56px; display: grid; place-items: center; color: var(--blue); background: #fff; border-radius: 50%; }
        .status-symbol::after { content: ""; position: absolute; inset: -3px; border: 2px solid #dbe6ff; border-top-color: var(--blue); border-radius: 50%; animation: rotate 1.3s linear infinite; }
        .status-success, .status-error { display: none; }
        .status-content { flex: 1; min-width: 0; }
        .status-meta { display: flex; align-items: center; gap: 10px; margin-bottom: 5px; }
        .status-badge { color: var(--blue); background: #e5edff; padding: 2px 9px; border-radius: 5px; font-size: 11px; font-weight: 650; }
        .elapsed { font-size: 11px; color: var(--muted); font-variant-numeric: tabular-nums; }
        [role="status"] { margin: 0; font-size: 14px; font-weight: 550; overflow-wrap: anywhere; }
        .activity { display: flex; align-items: center; gap: 4px; margin-top: 12px; height: 5px; }
        .activity span { width: 5px; height: 5px; background: #84a8f4; border-radius: 50%; animation: pulse 1.2s ease-in-out infinite; }
        .activity span:nth-child(2) { animation-delay: .15s; }
        .activity span:nth-child(3) { animation-delay: .3s; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 16px; }
        .action { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 38px; padding: 8px 14px; border: 1px solid #d5dfec; border-radius: 8px; background: #fff; color: #41536c; text-decoration: none; font-size: 12px; font-weight: 600; }
        .action:hover { background: #f6f8fc; }
        .action-primary { color: #fff; background: var(--blue); border-color: var(--blue); }
        .action-primary:hover { background: #1d4ed8; }
        .action-danger { color: #fff; background: #dc2626; border-color: #dc2626; }
        .action-danger:hover { background: #b91c1c; }
        .process-note { margin: 16px 0 0; display: flex; gap: 7px; align-items: flex-start; color: var(--muted); font-size: 12px; }
        .process-note svg { margin-top: 3px; }
        .preview-card { margin-top: 20px; }
        .preview-heading { padding: 17px 24px; display: flex; align-items: center; justify-content: space-between; gap: 16px; border-bottom: 1px solid var(--line); }
        .preview-title { display: flex; align-items: center; gap: 9px; font-size: 13px; font-weight: 650; }
        .filename { color: var(--muted); font-size: 11px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .preview-placeholder { min-height: 254px; padding: 28px; display: flex; align-items: center; justify-content: center; gap: 32px; background: #f9fbfd; }
        .paper { width: 116px; height: 148px; flex-shrink: 0; position: relative; padding: 20px 14px; border: 1px solid #dfe6ee; border-radius: 6px; box-shadow: 0 5px 15px #32475e0c; background: #fff; overflow: hidden; }
        .paper::after { content: ""; position: absolute; inset: 0; background: linear-gradient(110deg, transparent 20%, #e9f0fd80 48%, transparent 76%); transform: translateX(-100%); animation: shimmer 2.3s ease-in-out infinite; }
        .paper i { display: block; height: 5px; border-radius: 2px; margin-bottom: 10px; background: #e7edf5; }
        .paper i:first-child { width: 50%; height: 8px; background: #dce6f6; margin-bottom: 18px; }
        .paper i:last-child { width: 55%; }
        .preview-copy strong { font-size: 14px; font-weight: 550; color: #65788f; }
        .preview-copy p { margin: 5px 0 0; font-size: 12px; color: #93a0b1; }
        iframe { display: block; width: 100%; height: max(540px, 70vh); border: 0; background: #eef1f6; }
        .page-footer { margin: 20px 0 0; color: #98a3b1; text-align: center; font-size: 11px; }
        [data-state="ready"] .status-panel { background: #f0faf5; border-color: #d9eee3; }
        [data-state="ready"] .status-symbol { color: var(--green); }
        [data-state="ready"] .status-badge { color: var(--green); background: #ddf2e7; }
        [data-state="error"] .status-panel { background: #fff6f5; border-color: #f1dcd8; }
        [data-state="error"] .status-symbol { color: #c54343; }
        [data-state="error"] .status-badge { color: #b74444; background: #fbe4e1; }
        [data-state="ready"] .status-symbol::after, [data-state="error"] .status-symbol::after,
        [data-state="ready"] .status-working, [data-state="error"] .status-working,
        [data-state="ready"] .activity, [data-state="error"] .activity { display: none; }
        [data-state="ready"] .status-success, [data-state="error"] .status-error { display: block; }
        [data-state="error"] .paper::after { animation: none; display: none; }
        @keyframes rotate { to { transform: rotate(360deg); } }
        @keyframes pulse { 0%, 80%, 100% { opacity: .3; } 40% { opacity: 1; } }
        @keyframes shimmer { 100% { transform: translateX(100%); } }
        @media (prefers-reduced-motion: reduce) { *, *::after { animation: none !important; } }
        @media (max-width: 600px) {
            .topbar { height: 58px; padding: 0 20px; }
            .brand { font-size: 16px; }
            .page-shell { width: calc(100% - 28px); margin-top: 18px; }
            .process-card { padding: 22px 18px; }
            .card-heading { display: block; position: relative; }
            .eyebrow { margin-bottom: 12px; }
            h1 { font-size: 19px; }
            .registered { position: absolute; top: 0; right: 0; font-size: 10px; padding: 4px 8px; }
            .step-title { font-size: 11px; }
            .step-detail { font-size: 10px; }
            .status-panel { padding: 18px 16px; gap: 14px; align-items: flex-start; }
            .status-symbol { width: 42px; height: 42px; }
            [role="status"] { font-size: 12px; }
            .preview-heading { padding: 14px 18px; }
            .filename { max-width: 48%; }
            .preview-placeholder { gap: 20px; min-height: 226px; padding: 22px 18px; }
            .paper { width: 86px; height: 120px; padding: 16px 12px; }
            .preview-copy strong { font-size: 12px; }
            .preview-copy p { font-size: 11px; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="brand"><span class="brand-mark">酒</span>酒丸蔵</div>
        <span class="topbar-label">伝票出力</span>
    </header>
    <main id="proxy-shipment-print" class="page-shell" data-state="generating" aria-busy="true"
          data-start-url="{{ route('proxy-shipment-print.start', $printRequest->uuid) }}"
          data-retry-url="{{ route('proxy-shipment-print.retry', $printRequest->uuid) }}"
          data-status-url="{{ route('proxy-shipment-print.status', $printRequest->uuid) }}"
          data-file-url="{{ route('proxy-shipment-print.file', $printRequest->uuid) }}"
          data-complete-url="{{ route('proxy-shipment-print.complete', $printRequest->uuid) }}"
          data-return-url="{{ url('/admin/wms-shortage-allocations/new-request') }}"
          data-filename="{{ 'stock-transfer.pdf' }}">
        <p class="breadcrumb"><span>横持ち出荷依頼</span><span aria-hidden="true">／</span><strong>移動伝票印刷</strong></p>
        <section class="process-card" aria-labelledby="process-title">
            <div class="card-heading">
                <div><p class="eyebrow">移動伝票印刷</p><h1 id="process-title" data-process-title>印刷用のPDFを作成しています</h1></div>
                <span class="registered"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>移動伝票作成済み</span>
            </div>
            <p>対象依頼 {{ $allocationCount }}件 ／ 移動伝票 {{ $transferCount }}件</p>
            <ol class="steps" aria-label="印刷の進行状況">
                @foreach(['対象確認', 'PDF作成', 'ファイル取得', '印刷準備'] as $step)
                    <li class="step" data-step="{{ $loop->index }}" data-state="{{ $loop->first ? 'done' : ($loop->index === 1 ? 'active' : 'waiting') }}">
                        <span class="step-node" aria-hidden="true"><span class="step-number">{{ $loop->iteration }}</span><svg class="step-check" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 12 4 4L19 6"/></svg></span>
                        <span class="step-title">{{ $step }}</span>
                        <span class="step-detail" data-step-detail>{{ $loop->first ? '完了' : ($loop->index === 1 ? '処理中' : '待機中') }}</span>
                    </li>
                @endforeach
            </ol>
            <div class="status-panel">
                <div class="status-symbol" aria-hidden="true">
                    <svg class="status-working" width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg>
                    <svg class="status-success" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 12 4 4L19 6"/></svg>
                    <svg class="status-error" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v9M12 18v1"/></svg>
                </div>
                <div class="status-content">
                    <div class="status-meta"><span class="status-badge" data-stage-label>PDF作成中</span><span class="elapsed" data-elapsed>経過 00:00</span></div>
                    <p role="status" aria-live="polite">移動伝票は作成済みです。画面を開いたままお待ちください。</p>
                    <div class="activity" aria-hidden="true"><span></span><span></span><span></span></div>
                    <div class="actions">
                        <button type="button" class="action action-primary" data-print hidden><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6Z"/></svg>印刷ダイアログを開く</button>
                        <button type="button" class="action action-primary" data-retry hidden>PDF取得を再試行</button>
                        <button type="button" class="action action-primary" data-regenerate hidden>PDFを再生成</button>
                        <a class="action" data-download hidden>PDFをダウンロード</a>
                        <button type="button" class="action action-danger" data-close><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>閉じて横持ち出荷依頼に戻る</button>
                    </div>
                </div>
            </div>
            <p class="process-note"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7v1"/></svg><span data-process-help>準備ができると、PDFを自動ダウンロードして印刷ダイアログを開きます。</span></p>
        </section>
        <section class="preview-card" aria-label="伝票プレビュー">
            <div class="preview-heading"><span class="preview-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M3 8h18"/></svg>伝票プレビュー</span><span class="filename">{{ 'stock-transfer.pdf' }}</span></div>
            <div class="preview-placeholder" data-preview-placeholder>
                <div class="paper" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></div>
                <div class="preview-copy"><strong data-preview-title>伝票のプレビューを準備しています</strong><p data-preview-help>PDFを取得すると、ここに伝票が表示されます。</p></div>
            </div>
            <iframe title="移動伝票PDF" hidden></iframe>
        </section>
        <p class="page-footer">酒丸蔵 · 横持ち出荷・移動伝票印刷</p>
    </main>
</body>
</html>
