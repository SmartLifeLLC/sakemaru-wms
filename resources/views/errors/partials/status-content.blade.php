@php
    $homeUrl ??= url('/');
    $inquiryRoute ??= null;
    $loginUserName ??= '';
    $loginUserEmail ??= '';
    $loginUserLabel = trim(collect([$loginUserName, $loginUserEmail])->filter()->implode(' / '));
    $loginUserLabel = $loginUserLabel !== '' ? $loginUserLabel : '未ログイン';
    $canSubmitInquiry ??= filled($inquiryRoute);
@endphp

<style>
    .status-page-shell {
        width: 100%;
    }

    .status-page-card {
        width: 100%;
        max-width: 760px;
        margin: 0 auto;
        padding: 56px 40px;
        border-radius: 28px;
        background: #ffffff;
        border: 1px solid rgba(226, 232, 240, 0.9);
        box-shadow: 0 28px 70px rgba(15, 23, 42, 0.08);
        text-align: center;
    }

    .status-page-icon {
        width: 92px;
        height: 92px;
        margin: 0 auto 28px;
        border-radius: 9999px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #fef2f2;
        color: #ef4444;
    }

    .status-page-code {
        margin: 0 0 16px;
        color: #2563eb;
        font-size: 14px;
        font-weight: 700;
        letter-spacing: 0.14em;
    }

    .status-page-title {
        margin: 0;
        color: #1e293b;
        font-size: clamp(36px, 5vw, 52px);
        font-weight: 800;
        letter-spacing: -0.03em;
        line-height: 1.15;
    }

    .status-page-message {
        max-width: 560px;
        margin: 24px auto 0;
        color: #475569;
        font-size: 18px;
        line-height: 1.8;
        white-space: pre-line;
    }

    .status-page-actions {
        margin-top: 36px;
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 14px;
    }

    .status-page-button {
        appearance: none;
        border: 1px solid #cbd5e1;
        border-radius: 14px;
        padding: 15px 24px;
        font-size: 16px;
        font-weight: 700;
        cursor: pointer;
        transition: transform 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
    }

    .status-page-button:hover {
        transform: translateY(-1px);
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
    }

    .status-page-button-secondary {
        background: #ffffff;
        color: #334155;
    }

    .status-page-button-primary {
        background: linear-gradient(135deg, #2563eb, #1d4ed8);
        color: #ffffff;
        border-color: transparent;
    }

    .status-page-button-primary:disabled {
        cursor: not-allowed;
        opacity: 0.6;
        box-shadow: none;
        transform: none;
    }

    .status-inquiry {
        position: fixed;
        inset: 0;
        z-index: 80;
    }

    .status-inquiry[hidden] {
        display: none !important;
    }

    .status-inquiry-overlay {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.48);
        backdrop-filter: blur(2px);
    }

    .status-inquiry-modal {
        position: relative;
        z-index: 1;
        width: min(92vw, 720px);
        margin: 5vh auto;
        border-radius: 24px;
        background: #ffffff;
        box-shadow: 0 32px 80px rgba(15, 23, 42, 0.28);
        overflow: hidden;
    }

    .status-inquiry-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 22px 24px;
        border-bottom: 1px solid #e5e7eb;
    }

    .status-inquiry-title {
        display: flex;
        align-items: center;
        gap: 10px;
        color: #1e293b;
        font-size: 18px;
        font-weight: 700;
    }

    .status-inquiry-close {
        appearance: none;
        border: 0;
        background: transparent;
        color: #94a3b8;
        font-size: 28px;
        line-height: 1;
        cursor: pointer;
    }

    .status-inquiry-body {
        padding: 24px;
    }

    .status-inquiry-description {
        margin: 0 0 20px;
        color: #475569;
        font-size: 15px;
        line-height: 1.8;
    }

    .status-inquiry-field {
        margin-bottom: 18px;
    }

    .status-inquiry-label {
        display: block;
        margin-bottom: 8px;
        color: #334155;
        font-size: 14px;
        font-weight: 700;
    }

    .status-inquiry-input,
    .status-inquiry-textarea {
        width: 100%;
        border: 1px solid #dbe1ea;
        border-radius: 12px;
        background: #ffffff;
        color: #0f172a;
        font-size: 16px;
        box-sizing: border-box;
    }

    .status-inquiry-input {
        height: 52px;
        padding: 0 16px;
    }

    .status-inquiry-input[readonly] {
        background: #f8fafc;
        color: #64748b;
    }

    .status-inquiry-textarea {
        min-height: 160px;
        padding: 14px 16px;
        resize: vertical;
        line-height: 1.75;
    }

    .status-inquiry-note {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        margin-top: 6px;
        padding: 14px 16px;
        border-radius: 12px;
        background: #f8fafc;
        color: #64748b;
        font-size: 13px;
        line-height: 1.7;
    }

    .status-inquiry-error {
        margin: 18px 0 0;
        padding: 12px 14px;
        border-radius: 12px;
        background: #fef2f2;
        color: #b91c1c;
        font-size: 14px;
        line-height: 1.7;
    }

    .status-inquiry-footer {
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        margin-top: 24px;
    }

    .status-inquiry-success {
        padding: 56px 24px 48px;
        text-align: center;
    }

    .status-inquiry-success-icon {
        width: 76px;
        height: 76px;
        margin: 0 auto 24px;
        border-radius: 9999px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #ecfdf3;
        color: #22c55e;
    }

    .status-inquiry-success-title {
        margin: 0;
        color: #1e293b;
        font-size: 22px;
        font-weight: 800;
    }

    .status-inquiry-success-message {
        margin: 14px 0 0;
        color: #475569;
        font-size: 16px;
        line-height: 1.8;
    }

    .status-inquiry-success-button {
        margin-top: 28px;
    }

    @media (max-width: 640px) {
        .status-page-card {
            padding: 40px 20px;
            border-radius: 22px;
        }

        .status-page-actions,
        .status-inquiry-footer {
            flex-direction: column;
        }

        .status-page-button,
        .status-inquiry-footer .status-page-button {
            width: 100%;
        }

        .status-inquiry-modal {
            width: min(96vw, 720px);
            margin: 2vh auto;
        }
    }
</style>

<div class="status-page-shell">
    <div class="status-page-card">
        <div class="status-page-icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width: 48px; height: 48px;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86l-7.5 13A2 2 0 004.5 20h15a2 2 0 001.71-3.14l-7.5-13a2 2 0 00-3.42 0z" />
            </svg>
        </div>

        <p class="status-page-code">ERROR {{ $statusCode }}</p>
        <h1 class="status-page-title">{{ $title }}</h1>
        <p class="status-page-message">{{ $message }}</p>

        <div class="status-page-actions">
            <button type="button" class="status-page-button status-page-button-secondary" data-error-page-back data-home-url="{{ $homeUrl }}">
                前のページに戻る
            </button>

            @if ($canSubmitInquiry)
                <button type="button" class="status-page-button status-page-button-primary" data-open-error-inquiry>
                    システム会社に問い合わせる
                </button>
            @endif
        </div>
    </div>
</div>

@if ($canSubmitInquiry)
    <div
        class="status-inquiry"
        data-error-inquiry
        data-route="{{ $inquiryRoute }}"
        data-status-code="{{ $statusCode }}"
        data-error-title="{{ $title }}"
        data-page-url="{{ request()->fullUrl() }}"
        data-login-user="{{ $loginUserLabel }}"
        hidden
    >
        <div class="status-inquiry-overlay" data-inquiry-close></div>

        <div class="status-inquiry-modal" role="dialog" aria-modal="true" aria-labelledby="error-inquiry-title">
            <div class="status-inquiry-header">
                <div class="status-inquiry-title" id="error-inquiry-title">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width: 22px; height: 22px; color: #2563eb;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75A60.723 60.723 0 0112 17.25a60.723 60.723 0 019.75 1.5m-18.5 0a2.25 2.25 0 01-2.25-2.25V6.108c0-1.135.845-2.098 1.976-2.193A48.424 48.424 0 0112 3.75c3.186 0 6.298.308 9.274.915 1.13.095 1.976 1.058 1.976 2.193V16.5a2.25 2.25 0 01-2.25 2.25m-18.5 0A2.25 2.25 0 005 21h14a2.25 2.25 0 002.25-2.25m-18.5 0h18.5M9 10.5h6M9 13.5h3" />
                    </svg>
                    エラーの報告
                </div>

                <button type="button" class="status-inquiry-close" data-inquiry-close aria-label="閉じる">×</button>
            </div>

            <div class="status-inquiry-body" data-inquiry-form-view>
                <p class="status-inquiry-description">
                    問題の早期解決のため、エラー発生時の状況をできるだけ詳しくお聞かせください。
                </p>

                <div class="status-inquiry-field">
                    <label class="status-inquiry-label" for="error-inquiry-login-user">ログインユーザー</label>
                    <input id="error-inquiry-login-user" class="status-inquiry-input" type="text" value="{{ $loginUserLabel }}" readonly>
                </div>

                <div class="status-inquiry-field">
                    <label class="status-inquiry-label" for="error-inquiry-reporter-name">報告者名</label>
                    <input id="error-inquiry-reporter-name" class="status-inquiry-input" type="text" placeholder="ご自身のお名前を入力してください（任意）">
                </div>

                <div class="status-inquiry-field">
                    <label class="status-inquiry-label" for="error-inquiry-details">発生時の操作・状況</label>
                    <textarea id="error-inquiry-details" class="status-inquiry-textarea" placeholder="例：入荷予定一覧を開いた直後にこの画面が表示されました。"></textarea>
                </div>

                <div class="status-inquiry-note">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20" style="width: 16px; height: 16px; flex: 0 0 auto; margin-top: 2px;">
                        <path fill-rule="evenodd" d="M18 10A8 8 0 114 4.906V8a2 2 0 002 2h3.093A8.001 8.001 0 0118 10zm-8-4a1 1 0 00-1 1v3a1 1 0 102 0V7a1 1 0 00-1-1zm0 8a1.25 1.25 0 100-2.5A1.25 1.25 0 0010 14z" clip-rule="evenodd" />
                    </svg>
                    <span>迅速な対応のため、エラーコードと発生URLが自動的に送信されます。</span>
                </div>

                <p class="status-inquiry-error" data-inquiry-error hidden></p>

                <div class="status-inquiry-footer">
                    <button type="button" class="status-page-button status-page-button-secondary" data-inquiry-close>
                        キャンセル
                    </button>
                    <button type="button" class="status-page-button status-page-button-primary" data-inquiry-submit>
                        送信する
                    </button>
                </div>
            </div>

            <div class="status-inquiry-success" data-inquiry-success-view hidden>
                <div class="status-inquiry-success-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width: 42px; height: 42px;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                </div>

                <h2 class="status-inquiry-success-title">送信が完了しました</h2>
                <p class="status-inquiry-success-message">
                    エラーのご報告ありがとうございます。<br>
                    システム担当者が確認し、順次対応いたします。
                </p>

                <button type="button" class="status-page-button status-page-button-secondary status-inquiry-success-button" data-inquiry-success-close>
                    閉じる
                </button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const widget = document.querySelector('[data-error-inquiry]');

            if (!widget) {
                return;
            }

            const openButton = document.querySelector('[data-open-error-inquiry]');
            const closeButtons = widget.querySelectorAll('[data-inquiry-close], [data-inquiry-success-close]');
            const submitButton = widget.querySelector('[data-inquiry-submit]');
            const reporterNameInput = widget.querySelector('#error-inquiry-reporter-name');
            const detailsInput = widget.querySelector('#error-inquiry-details');
            const errorBox = widget.querySelector('[data-inquiry-error]');
            const formView = widget.querySelector('[data-inquiry-form-view]');
            const successView = widget.querySelector('[data-inquiry-success-view]');
            const csrfToken = document.querySelector('meta[name=\"csrf-token\"]')?.getAttribute('content') ?? '';
            const defaultSubmitLabel = submitButton?.textContent ?? '送信する';

            const resetForm = () => {
                reporterNameInput.value = '';
                detailsInput.value = '';
                errorBox.hidden = true;
                errorBox.textContent = '';
                submitButton.disabled = false;
                submitButton.textContent = defaultSubmitLabel;
                formView.hidden = false;
                successView.hidden = true;
            };

            const openModal = () => {
                resetForm();
                widget.hidden = false;
                document.body.style.overflow = 'hidden';
                window.setTimeout(() => reporterNameInput.focus(), 50);
            };

            const closeModal = () => {
                widget.hidden = true;
                document.body.style.overflow = '';
            };

            openButton?.addEventListener('click', openModal);

            closeButtons.forEach((button) => {
                button.addEventListener('click', closeModal);
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !widget.hidden) {
                    closeModal();
                }
            });

            submitButton?.addEventListener('click', async () => {
                const details = detailsInput.value.trim();

                if (details === '') {
                    errorBox.hidden = false;
                    errorBox.textContent = '発生時の操作・状況を入力してください。';
                    detailsInput.focus();
                    return;
                }

                errorBox.hidden = true;
                errorBox.textContent = '';
                submitButton.disabled = true;
                submitButton.textContent = '送信中...';

                try {
                    const response = await fetch(widget.dataset.route, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            status_code: Number(widget.dataset.statusCode),
                            error_title: widget.dataset.errorTitle,
                            page_url: widget.dataset.pageUrl,
                            reporter_name: reporterNameInput.value.trim(),
                            details,
                        }),
                    });

                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        throw new Error(payload.message ?? '問い合わせの送信に失敗しました。');
                    }

                    formView.hidden = true;
                    successView.hidden = false;
                } catch (error) {
                    errorBox.hidden = false;
                    errorBox.textContent = error instanceof Error ? error.message : '問い合わせの送信に失敗しました。';
                    submitButton.disabled = false;
                    submitButton.textContent = defaultSubmitLabel;
                }
            });
        });

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('[data-error-page-back]').forEach((button) => {
                button.addEventListener('click', () => {
                    if (window.history.length > 1) {
                        window.history.back();
                        return;
                    }

                    const homeUrl = button.getAttribute('data-home-url');

                    if (homeUrl) {
                        window.location.href = homeUrl;
                    }
                });
            });
        });
    </script>
@endif
