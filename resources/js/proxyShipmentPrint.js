export async function startProxyShipmentPrint(root) {
    const message = root.querySelector('[role="status"]');
    const printButton = root.querySelector('[data-print]');
    const retryButton = root.querySelector('[data-retry]');
    const download = root.querySelector('[data-download]');
    const frame = document.querySelector('iframe');
    const title = root.querySelector('[data-process-title]');
    const stageLabel = root.querySelector('[data-stage-label]');
    const help = root.querySelector('[data-process-help]');
    const placeholder = root.querySelector('[data-preview-placeholder]');
    const previewTitle = root.querySelector('[data-preview-title]');
    const previewHelp = root.querySelector('[data-preview-help]');
    const elapsed = root.querySelector('[data-elapsed]');
    const regenerateButton = root.querySelector('[data-regenerate]');
    let attempt = null;
    let generationFailed = false;
    let autoDownloaded = false;
    let pdfUrl = null;
    let running = false;
    let currentStep = 1;
    let elapsedTimer = null;

    function setStage(stage, text) {
        const stages = {
            generating: [1, '印刷用のPDFを作成しています', 'PDF作成中'],
            downloading: [2, 'PDFを取得しています', 'ファイル取得中'],
            completing: [3, '印刷の準備をしています', '印刷準備中'],
            ready: [4, '印刷の準備ができました', '準備完了'],
            error: [currentStep, '印刷の準備が中断されました', '確認が必要です'],
        };
        const [stepIndex, heading, label] = stages[stage];
        currentStep = stepIndex;
        root.dataset.state = stage;
        root.setAttribute('aria-busy', String(!['ready', 'error'].includes(stage)));
        title.textContent = heading;
        stageLabel.textContent = label;
        message.textContent = text;
        root.querySelectorAll('[data-step]').forEach(step => {
            const index = Number(step.dataset.step);
            const state = index < stepIndex ? 'done' : index === stepIndex ? (stage === 'error' ? 'error' : 'active') : 'waiting';
            step.dataset.state = state;
            step.querySelector('[data-step-detail]').textContent = { done: '完了', active: '処理中', waiting: '待機中', error: '要確認' }[state];
            if (state === 'active' || state === 'error') step.setAttribute('aria-current', 'step');
            else step.removeAttribute('aria-current');
        });
        help.textContent = stage === 'ready'
            ? '印刷ダイアログが表示されない場合は、上のボタンまたはPDF表示内の印刷ボタンを使用してください。'
            : stage === 'error'
                ? '移動伝票は作成済みです。移動伝票の再作成は不要です。'
                : '準備ができると、PDFを自動ダウンロードして印刷ダイアログを開きます。';
        previewTitle.textContent = stage === 'error' ? '伝票のプレビューはまだ表示できません' : '伝票のプレビューを準備しています';
        previewHelp.textContent = stage === 'error' ? '上のメッセージを確認し、PDF取得を再試行してください。' : 'PDFを取得すると、ここに伝票が表示されます。';
    }

    async function request(url, options = {}, operation = 'PDF取得') {
        if (attempt && url !== root.dataset.startUrl) {
            url = `${url}?attempt=${attempt}`;
        }
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            ...options,
        });
        if (response.status === 401 || response.status === 419 || response.redirected) {
            throw new Error('ログイン状態が切れています。再ログイン後にPDF取得を再試行してください。移動伝票の再作成は不要です。');
        }
        if (!response.ok) {
            let serverMessage = null;
            if (response.headers.get('content-type')?.includes('application/json')) {
                const body = await response.json().catch(() => null);
                // サーバーの内部例外メッセージは表示しない。
                if ([409, 410, 422, 503].includes(response.status)) serverMessage = body?.message;
            }
            const fallback = response.status === 403
                ? 'このPDFを取得する権限がありません。管理者に確認してください。'
                : `${operation}に失敗しました（HTTP ${response.status}）。再試行してください。移動伝票の再作成は不要です。`;
            throw new Error(serverMessage || fallback);
        }
        return response;
    }

    function print() {
        try {
            frame.contentWindow.focus();
            frame.contentWindow.print();
        } catch {
            message.textContent = 'PDF表示内の印刷ボタンを使用してください。';
        }
    }

    async function run() {
        if (running) return;
        running = true;
        retryButton.hidden = true;
        regenerateButton.hidden = true;
        const startedAt = Date.now();
        elapsed.textContent = '経過 00:00';
        clearInterval(elapsedTimer);
        elapsedTimer = setInterval(() => {
            const seconds = Math.floor((Date.now() - startedAt) / 1000);
            elapsed.textContent = `経過 ${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
        }, 1000);
        setStage('generating', '移動伝票は作成済みです。画面を開いたままお待ちください。');
        try {
            if (!attempt) {
                const started = await (await request(root.dataset.startUrl, { method: 'POST' }, 'PDF作成の受付')).json();
                attempt = started.attempt;
            }
            const deadline = Date.now() + 10 * 60 * 1000;
            while (true) {
                const status = await (await request(root.dataset.statusUrl, {}, 'PDF生成状況の確認')).json();
                if (status.status === 'failed') { generationFailed = true; regenerateButton.hidden = false; throw new Error('PDF生成に失敗しました。「PDFを再生成」から再試行してください。移動伝票の再作成は不要です。'); }
                if (status.status === 'ready') break;
                if (Date.now() >= deadline) throw new Error('PDF生成に時間がかかっています。しばらくしてからPDF取得を再試行してください。移動伝票の再作成は不要です。');
                await new Promise(resolve => setTimeout(resolve, 2000));
            }

            if (!pdfUrl) {
                setStage('downloading', 'PDFを取得しています。取得が完了すると、自動でダウンロードします。');
                const response = await request(root.dataset.fileUrl);
                const blob = await response.blob();
                if (!blob.size || blob.type !== 'application/pdf') throw new Error('PDFを取得できませんでした。再試行してください。');
                pdfUrl = URL.createObjectURL(blob);
                download.href = pdfUrl;
                download.download = root.dataset.filename;
                download.hidden = false;
                if (!autoDownloaded) { download.click(); autoDownloaded = true; }
            }

            setStage('completing', 'PDFを取得しました。PDF取得済み状態を更新しています。');
            await request(root.dataset.completeUrl, { method: 'POST' }, 'PDF取得済み状態の更新');
            setStage('ready', 'PDFを取得し、PDF取得済みを記録しました。印刷ダイアログから印刷してください。');
            printButton.hidden = false;
            placeholder.hidden = true;
            frame.hidden = false;
            frame.onload = print;
            frame.src = pdfUrl;
        } catch (error) {
            setStage('error', error.message);
            retryButton.hidden = generationFailed;
        } finally {
            running = false;
            clearInterval(elapsedTimer);
        }
    }

    printButton.addEventListener('click', print);
    document.querySelector('[data-close]').addEventListener('click', () => {
        window.close();
        if (!window.closed) window.location.assign(root.dataset.returnUrl);
    });
    retryButton.addEventListener('click', run);
    regenerateButton.addEventListener('click', async () => {
        if (running) return;
        regenerateButton.disabled = true;
        try {
            const restarted = await (await request(root.dataset.retryUrl, { method: 'POST' }, 'PDF再生成')).json();
            attempt = restarted.attempt;
            generationFailed = false;
            await run();
        } catch (error) { setStage('error', error.message); }
        finally { regenerateButton.disabled = false; }
    });
    window.addEventListener('beforeunload', () => { clearInterval(elapsedTimer); if (pdfUrl) URL.revokeObjectURL(pdfUrl); });
    await run();
}

const root = document.getElementById('proxy-shipment-print');
if (root) startProxyShipmentPrint(root);
