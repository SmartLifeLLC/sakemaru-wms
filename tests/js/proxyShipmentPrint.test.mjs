import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

globalThis.document = { getElementById: () => null };
const source = await readFile(new URL('../../resources/js/proxyShipmentPrint.js', import.meta.url), 'utf8');
const { startProxyShipmentPrint } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);

function element() {
    return { hidden: false, disabled: false, textContent: '', dataset: {}, listeners: {},
        setAttribute() {}, removeAttribute() {}, addEventListener(name, handler) { this.listeners[name] = handler; } };
}
function fixture() {
    const elements = Object.fromEntries(['[role="status"]', '[data-print]', '[data-retry]', '[data-download]', '[data-process-title]',
        '[data-stage-label]', '[data-process-help]', '[data-preview-placeholder]', '[data-preview-title]', '[data-preview-help]',
        '[data-elapsed]', '[data-regenerate]'].map(selector => [selector, element()]));
    const frame = element();
    let prints = 0;
    frame.contentWindow = { focus() {}, print() { prints++; } };
    let downloads = 0;
    elements['[data-download]'].click = () => downloads++;
    const close = element();
    const root = { ...element(), dataset: { startUrl: '/start', retryUrl: '/retry', statusUrl: '/status', fileUrl: '/file', completeUrl: '/complete', filename: 'stock-transfer.pdf', returnUrl: '/list' },
        querySelector: selector => elements[selector], querySelectorAll: () => [] };
    globalThis.document = { querySelector: selector => selector === 'iframe' ? frame : selector === '[data-close]' ? close : { content: 'csrf' } };
    globalThis.window = { addEventListener() {}, close() {}, closed: true, location: { assign() {} } };
    return { root, elements, downloads: () => downloads, frame, prints: () => prints };
}

// Tests run against a local DOM double; no browser, business API, storage or printer is accessed.
test('download retry preserves the same attempt and does not duplicate the download or PDF generation', async () => {
    const ui = fixture();
    const calls = [];
    let completeCalls = 0;
    globalThis.fetch = async (url, options) => {
        calls.push([url, options.method ?? 'GET']);
        if (url === '/start') return Response.json({ attempt: 1 });
        if (url === '/status?attempt=1') return Response.json({ status: 'ready' });
        if (url === '/file?attempt=1') return new Response('%PDF-1.4 fixture', { headers: { 'Content-Type': 'application/pdf' } });
        if (url === '/complete?attempt=1') {
            completeCalls++;
            return completeCalls === 1 ? Response.json({ message: '取得完了の記録を再試行してください。' }, { status: 503 }) : new Response(null, { status: 204 });
        }
        throw new Error(`Unexpected URL ${url}`);
    };
    await startProxyShipmentPrint(ui.root);
    assert.equal(ui.root.dataset.state, 'error');
    assert.equal(ui.downloads(), 1);
    await ui.elements['[data-retry]'].listeners.click();
    assert.equal(ui.root.dataset.state, 'ready');
    assert.equal(ui.downloads(), 1);
    assert.equal(calls.filter(([url]) => url === '/start').length, 1);
    assert.equal(calls.filter(([url]) => url.startsWith('/file')).length, 1);
    assert.equal(completeCalls, 2);
});

test('generation retry uses a new attempt while file and completion remain bound to that attempt', async () => {
    const ui = fixture();
    const calls = [];
    globalThis.fetch = async (url, options) => {
        calls.push([url, options.method ?? 'GET']);
        if (url === '/start') return Response.json({ attempt: 1 });
        if (url === '/status?attempt=1') return Response.json({ status: 'failed' });
        if (url === '/retry?attempt=1') return Response.json({ attempt: 2 });
        if (url === '/status?attempt=2') return Response.json({ status: 'ready' });
        if (url === '/file?attempt=2') return new Response('%PDF-1.4 fixture', { headers: { 'Content-Type': 'application/pdf' } });
        if (url === '/complete?attempt=2') return new Response(null, { status: 204 });
        throw new Error(`Unexpected URL ${url}`);
    };
    await startProxyShipmentPrint(ui.root);
    assert.equal(ui.root.dataset.state, 'error');
    assert.equal(ui.elements['[data-regenerate]'].hidden, false);
    assert.equal(ui.downloads(), 0);
    await ui.elements['[data-regenerate]'].listeners.click();
    assert.equal(ui.root.dataset.state, 'ready');
    assert.equal(ui.downloads(), 1);
    assert.equal(calls.some(([url]) => url === '/file?attempt=1'), false);
    assert.equal(calls.some(([url]) => url === '/complete?attempt=1'), false);
});


test('lost regeneration response can be retried with the same failed attempt', async () => {
    const ui = fixture();
    const retryUrls = [];
    globalThis.fetch = async (url) => {
        if (url === '/start') return Response.json({ attempt: 1 });
        if (url === '/status?attempt=1') return Response.json({ status: 'failed' });
        if (url.startsWith('/retry')) {
            retryUrls.push(url);
            if (retryUrls.length === 1) throw new TypeError('Network response lost');
            return Response.json({ attempt: 2 });
        }
        if (url === '/status?attempt=2') return Response.json({ status: 'ready' });
        if (url === '/file?attempt=2') return new Response('%PDF-1.4 fixture', { headers: { 'Content-Type': 'application/pdf' } });
        if (url === '/complete?attempt=2') return new Response(null, { status: 204 });
        throw new Error(`Unexpected URL ${url}`);
    };
    await startProxyShipmentPrint(ui.root);
    await ui.elements['[data-regenerate]'].listeners.click();
    assert.equal(ui.root.dataset.state, 'error');
    assert.equal(ui.elements['[data-regenerate]'].disabled, false);
    await ui.elements['[data-regenerate]'].listeners.click();
    assert.deepEqual(retryUrls, ['/retry?attempt=1', '/retry?attempt=1']);
    assert.equal(ui.root.dataset.state, 'ready');
    assert.equal(ui.downloads(), 1);
});
