import { api } from '../api.js';
import { escapeHtml } from '../util.js';
import { play } from '../sound.js';
import { startScanner } from '../scanner.js';

/**
 * Opened on a signed-in phone by scanning the QR-Code a computer shows on the inventory
 * page: the phone's camera reads barcodes and sends each one to that computer.
 */
export async function render(container, { params = {} }) {
    const code = String(params.c || '');
    let info = null;
    let error = null;
    let stopScan = null;
    let lastScan = { code: '', at: 0 };
    const sent = [];

    try {
        info = await api.get(`/scan-links/${encodeURIComponent(code)}`);
    } catch (e) {
        error = e.message;
    }

    container.innerHTML = `
      <section class="card card-pad" style="max-width:520px;margin:0 auto;display:flex;flex-direction:column;gap:12px">
        ${error ? `<div style="font-size:40px;text-align:center">⚠️</div><h2 style="margin:0;text-align:center">ใช้ QR-Code นี้ไม่ได้</h2><div class="error-box">${escapeHtml(error)}</div>` : `
        <h2 style="margin:0">📲 มือถือเป็นเครื่องสแกนให้คอมพิวเตอร์</h2>
        <div class="muted" style="font-size:13px">บาร์โค้ดที่สแกนจะส่งไปที่หน้าสต๊อกสินค้าบนคอมพิวเตอร์ของ <b>${escapeHtml(info.owner_name)}</b> ทันที</div>
        <div class="mc-cam" data-el="cam" style="border-radius:12px" hidden><video playsinline muted></video><div class="mc-aim"></div></div>
        <button type="button" class="btn" data-el="scan" style="font-size:17px;padding:14px">📷 เปิดกล้องสแกน</button>
        <form data-el="form" style="display:flex;gap:6px">
          <input data-el="code" style="flex:1;min-width:0;font-size:17px" placeholder="หรือพิมพ์บาร์โค้ด" inputmode="numeric" autocomplete="off">
          <button type="submit" class="btn secondary">ส่ง</button>
        </form>
        <div data-el="msg"></div>
        <div class="mc-log" data-el="log"></div>`}
      </section>`;
    if (error) return;

    const $ = (name) => container.querySelector(`[data-el="${name}"]`);
    const video = container.querySelector('video');

    function drawLog() {
        $('log').innerHTML = sent.length ? `<div style="font-weight:600;margin:6px 2px">ส่งแล้ว</div>${sent.map((s) => `<div><span>${escapeHtml(s.code)}</span><span>${s.ok ? '✓' : '✕'}</span></div>`).join('')}` : '';
    }
    function say(kind, text) {
        $('msg').innerHTML = `<div class="mc-msg ${kind}">${escapeHtml(text)}</div>`;
    }

    async function send(raw) {
        const barcode = String(raw).trim();
        if (!barcode) return;
        try {
            await api.post(`/scan-links/${code}/codes`, { barcode });
            play('scan');
            navigator.vibrate?.(40);
            sent.unshift({ code: barcode, ok: true });
            say('ok', `ส่ง ${barcode} แล้ว`);
        } catch (e) {
            play('error');
            sent.unshift({ code: barcode, ok: false });
            say('bad', e.message);
            if (e.status === 410) stop();
        }
        sent.length = Math.min(sent.length, 20);
        drawLog();
    }

    async function start() {
        $('cam').hidden = false;
        try {
            stopScan = await startScanner(video, (c) => {
                const now = Date.now();
                if (c === lastScan.code && now - lastScan.at < 2500) return;
                lastScan = { code: c, at: now };
                send(c);
            });
            $('scan').textContent = '⏹ ปิดกล้อง';
        } catch (e) {
            $('cam').hidden = true;
            say('bad', 'เปิดกล้องไม่ได้: ' + e.message);
        }
    }
    function stop() {
        stopScan?.();
        stopScan = null;
        $('cam').hidden = true;
        $('scan').textContent = '📷 เปิดกล้องสแกน';
    }

    $('scan').addEventListener('click', () => (stopScan ? stop() : start()));
    $('form').addEventListener('submit', (e) => {
        e.preventDefault();
        send($('code').value);
        $('code').value = '';
    });

    // Tell the computer this phone is still here; stop the camera once the page is left.
    const ping = setInterval(async () => {
        if (!video.isConnected) {
            clearInterval(ping);
            stop();
            return;
        }
        try { await api.get(`/scan-links/${code}`); } catch (e) {
            if (e.status === 410) { clearInterval(ping); stop(); say('bad', e.message); }
        }
    }, 8000);
    window.addEventListener('hashchange', () => { if (!video.isConnected) stop(); }, { once: true });
}
