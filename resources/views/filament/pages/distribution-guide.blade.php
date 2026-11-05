<x-filament-panels::page>
    <div class="distribution-guide-page">
        <style>
            .distribution-guide-page{display:flex;flex-direction:column;gap:10px;height:calc(100dvh - 76px);min-height:360px}
            .distribution-guide-page .guide-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;font-size:13px;color:#64748b}
            .distribution-guide-page .guide-toolbar strong{font-size:16px;color:#1e293b}
            .distribution-guide-page .guide-toolbar a{display:inline-flex;align-items:center;padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;background:white;color:#334155;text-decoration:none}
            .distribution-guide-page .guide-toolbar a:hover{background:#f1f5f9}
            .distribution-guide-page .guide-toolbar a:focus-visible{outline:2px solid #2563eb;outline-offset:2px}
            .distribution-guide-page iframe{display:block;width:100%;flex:1;min-height:0;border:1px solid #d9e3ec;border-radius:10px;background:white}
            .dark .distribution-guide-page .guide-toolbar strong{color:#e2e8f0}
        </style>
        <div class="guide-toolbar">
            <strong>分配解説</strong>
            <a href="{{ \App\Filament\Pages\DistributionAdjustment::getUrl() }}">本部分配の一覧へ</a>
        </div>
        {{-- Keep the manual's styles and print layout isolated from the admin panel. --}}
        <iframe title="分配解説：運用ルールと操作ガイド"
                sandbox="allow-scripts allow-modals"
                srcdoc="{{ file_get_contents(resource_path('guides/distribution.html')) }}"></iframe>
    </div>
</x-filament-panels::page>
