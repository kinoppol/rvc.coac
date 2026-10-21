import { api } from '../api.js';
import { escapeHtml } from '../util.js';
import { fileToDataUrl } from '../images.js';

// Fallback decoder for browsers without the BarcodeDetector API (iOS Safari, Firefox).
const ZXING_URL = 'https://cdn.jsdelivr.net/npm/@zxing/browser@0.1.5/+esm';
const FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'qr_code'];

/** Starts the rear camera in `video` and calls onCode(text) for every barcode read; resolves to a stop() function. */
async function startScanner(video, onCode) {
    if (!navigator.mediaDevices?.getUserMedia) {
        throw new Error('เบราว์เซอร์นี้เปิดกล้องไม่ได้ (ต้องเปิดผ่าน https)');
    }
    if ('BarcodeDetector' in window) {
        const supported = await window.BarcodeDetector.getSupportedFormats().catch(() => []);
        const formats = FORMATS.filter((f) => supported.includes(f));
        if (formats.length) {
            const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: { ideal: 1280 } }, audio: false });
            video.srcObject = stream;
            await video.play();
            const detector = new window.BarcodeDetector({ formats });
            let running = true;
            const loop = async () => {
                if (!running) return;
                try {
                    const codes = await detector.detect(video);
                    if (codes.length) onCode(codes[0].rawValue);
                } catch { /* frame not ready */ }
                setTimeout(loop, 180);
            };
            loop();
            return () => {
                running = false;
                stream.getTracks().forEach((t) => t.stop());
                video.srcObject = null;
            };
        }
    }
    const { BrowserMultiFormatReader } = await import(ZXING_URL);
    const reader = new BrowserMultiFormatReader();
    const controls = await reader.decodeFromConstraints(
        { video: { facingMode: 'environment' }, audio: false }, video,
        (result) => { if (result) onCode(result.getText()); },
    );
    return () => controls.stop();
}

let audioCtx = null;
function beep(ok = true) {
    try {
        audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.frequency.value = ok ? 1250 : 320;
        gain.gain.value = 0.08;
        osc.connect(gain).connect(audioCtx.destination);
        osc.start();
        osc.stop(audioCtx.currentTime + (ok ? 0.09 : 0.25));
    } catch { /* no audio */ }
    navigator.vibrate?.(ok ? 40 : [60, 40, 60]);
}

/**
 * Full-screen counting mode for phones: scan or key in a barcode, then key in
 * the counted quantity on a large keypad. Saves each product immediately.
 */
export function openMobileCount({ stockCount, items, onClose }) {
    const counted = new Map(items.map((it) => [Number(it.product_id), it.counted_qty === null ? null : Number(it.counted_qty)]));
    let total = items.length;
    let product = null;     // productInfo from the API
    let packSize = 1;       // pieces per unit keyed in (a scanned pack barcode)
    let mode = 'code';      // keypad target: 'code' | 'qty'
    let input = '';
    let message = null;     // { kind: 'bad'|'ok'|'info', text, linkCode? }
    let busy = false;
    let linkFor = null;     // product waiting for a new barcode to be scanned/typed
    let stopScan = null;
    let lastScan = { code: '', at: 0 };
    let photoCache = 0;
    const log = [];

    const root = document.createElement('div');
    root.className = 'mc';
    root.innerHTML = `
      <header class="mc-head">
        <button type="button" class="mc-icon" data-act="close" aria-label="ปิด">✕</button>
        <div class="mc-title"><b>${escapeHtml(stockCount.doc_no)}</b><span data-el="progress"></span></div>
        <button type="button" class="mc-icon" data-act="search" aria-label="ค้นหาสินค้า">🔍</button>
      </header>
      <div class="mc-cam" data-el="cam" hidden>
        <video playsinline muted></video><div class="mc-aim"></div>
      </div>
      <div class="mc-scroll">
        <div data-el="card"></div>
        <div class="mc-display">
          <div class="mc-display-lbl" data-el="lbl"></div>
          <div class="mc-display-val" data-el="val"></div>
        </div>
      </div>
      <div class="mc-pad" data-el="pad">
        ${['1', '2', '3', '4', '5', '6', '7', '8', '9', 'C', '0', '⌫'].map((k) => `<button type="button" class="mc-key${/\d/.test(k) ? '' : ' fn'}" data-key="${k}">${k}</button>`).join('')}
      </div>
      <div class="mc-actions" data-el="actions"></div>
      <input type="file" accept="image/*" capture="environment" data-el="photo" hidden>
      <div class="mc-sheet" data-el="sheet" hidden></div>`;
    document.body.appendChild(root);
    document.body.classList.add('mc-open');

    const $ = (name) => root.querySelector(`[data-el="${name}"]`);
    const video = root.querySelector('video');

    function countedN() {
        return [...counted.values()].filter((v) => v !== null).length;
    }

    function photoUrl(p) {
        return p.image_token ? api.downloadUrl(`/products/${p.id}/photo?v=${p.image_token}${photoCache}`) : null;
    }

    function draw() {
        $('progress').textContent = `นับแล้ว ${countedN()}/${total}`;
        const cam = $('cam');
        root.querySelector('[data-act="scan"]')?.classList.toggle('on', !cam.hidden);

        let card = '';
        if (product) {
            const sys = product.item ? Number(product.item.system_qty) : Number(product.qty_stock);
            const got = counted.get(Number(product.id));
            const img = photoUrl(product);
            card = `
              <div class="mc-card">
                <button type="button" class="mc-photo" data-act="photo" aria-label="ถ่ายภาพสินค้า">
                  ${img ? `<img src="${img}" alt="">` : '<span>📷<br>ถ่ายภาพ</span>'}
                </button>
                <div class="mc-info">
                  <div class="mc-name">${escapeHtml(product.name)}</div>
                  <div class="mc-sub">${escapeHtml(product.consignor_name)}${product.item ? '' : ' · <b>ไม่อยู่ในรอบนับ (จะเพิ่มให้)</b>'}</div>
                  <div class="mc-nums">
                    <span>ในระบบ <b>${sys}</b></span>
                    <span>นับแล้ว <b>${got ?? '–'}</b></span>
                    ${got !== null && got !== undefined ? `<span class="${got - sys ? 'bad' : 'ok'}">${got - sys === 0 ? 'ตรง' : (got - sys > 0 ? '+' : '') + (got - sys)}</span>` : ''}
                  </div>
                  <div class="mc-codes">
                    ${product.barcode ? `<span class="mc-chip">${escapeHtml(product.barcode)}</span>` : ''}
                    ${product.pack_barcodes.map((b) => `<span class="mc-chip">${escapeHtml(b.barcode)} ×${b.pack_size}</span>`).join('')}
                    <button type="button" class="mc-chip add${linkFor ? ' on' : ''}" data-act="addcode">${linkFor ? 'รอสแกนบาร์โค้ด… (ยกเลิก)' : '+ บาร์โค้ด'}</button>
                  </div>
                </div>
              </div>`;
        }
        if (message) {
            card += `<div class="mc-msg ${message.kind}">${escapeHtml(message.text)}
              ${message.linkCode ? `<button type="button" class="btn" data-act="link">ค้นหาสินค้าเพื่อผูกบาร์โค้ดนี้</button>` : ''}</div>`;
        }
        if (!product && !message) {
            card = `<div class="mc-msg info">สแกนบาร์โค้ด หรือกดรหัสสินค้าแล้วกด «ค้นหา»<br>สินค้าไม่มีบาร์โค้ด ใช้ปุ่ม 🔍 ค้นหาด้วยชื่อ</div>`;
        }
        if (log.length) {
            card += `<div class="mc-log">${log.slice(0, 5).map((l) => `<div><span>${escapeHtml(l.name)}</span><b>${l.qty}</b></div>`).join('')}</div>`;
        }
        $('card').innerHTML = card;

        $('lbl').textContent = mode === 'qty'
            ? (packSize > 1 ? `จำนวนที่นับ (แพ็ค × ${packSize} ชิ้น)` : 'จำนวนที่นับ (ชิ้น)')
            : (linkFor ? `บาร์โค้ดใหม่ของ ${linkFor.name}` : 'รหัสสินค้า / บาร์โค้ด');
        $('val').innerHTML = input
            ? escapeHtml(input) + (mode === 'qty' && packSize > 1 ? ` <small>= ${Number(input) * packSize} ชิ้น</small>` : '')
            : '<span class="mc-ph">–</span>';
        $('val').classList.toggle('qty', mode === 'qty');

        const scanBtn = `<button type="button" class="mc-btn ghost${cam.hidden ? '' : ' on'}" data-act="scan">${cam.hidden ? '📷 สแกน' : '⏹ ปิดกล้อง'}</button>`;
        $('actions').innerHTML = mode === 'qty'
            ? `<button type="button" class="mc-btn ghost" data-act="cancel">ยกเลิก</button>
               <button type="button" class="mc-btn alt" data-act="add" ${input && !busy ? '' : 'disabled'}>+ บวกเพิ่ม</button>
               <button type="button" class="mc-btn" data-act="set" ${input && !busy ? '' : 'disabled'}>✓ บันทึก</button>`
            : `${scanBtn}<button type="button" class="mc-btn" data-act="find" ${input && !busy ? '' : 'disabled'}>${linkFor ? 'ผูกบาร์โค้ด' : 'ค้นหา'}</button>`;
    }

    function selectProduct(p, size = 1) {
        product = p;
        packSize = size;
        mode = 'qty';
        input = '';
        message = null;
        draw();
    }

    async function handleCode(code) {
        code = String(code).trim();
        if (!code || busy) return;
        if (linkFor) {
            await linkBarcode(linkFor, code, null);
            return;
        }
        busy = true;
        try {
            const res = await api.get(`/stockcounts/${stockCount.id}/lookup?code=${encodeURIComponent(code)}`);
            beep(true);
            selectProduct(res.data, res.data.scanned_pack_size || 1);
        } catch (e) {
            beep(false);
            product = null;
            mode = 'code';
            input = '';
            message = { kind: 'bad', text: e.message, linkCode: code };
        } finally {
            busy = false;
            draw();
        }
    }

    async function saveQty(addTo) {
        if (!product || !input || busy) return;
        const pieces = Number(input) * packSize;
        const prev = counted.get(Number(product.id)) ?? 0;
        const value = addTo ? prev + pieces : pieces;
        busy = true;
        draw();
        try {
            const res = await api.post(`/stockcounts/${stockCount.id}/count`, { product_id: product.id, counted_qty: value });
            if (!counted.has(Number(product.id))) total++;
            counted.set(Number(product.id), value);
            log.unshift({ name: product.name, qty: addTo ? `${prev} + ${pieces} = ${value}` : value });
            beep(true);
            product = res.data;
            mode = 'code';
            input = '';
            packSize = 1;
            message = { kind: 'ok', text: `บันทึก ${product.name} = ${value} ชิ้น` };
        } catch (e) {
            beep(false);
            message = { kind: 'bad', text: e.message };
        } finally {
            busy = false;
            draw();
        }
    }

    /** Links `code` to product p; asks piece vs pack first unless packSize is given. */
    async function linkBarcode(p, code, size) {
        if (size === null) {
            openLinkSheet(p, code);
            return;
        }
        busy = true;
        try {
            const res = await api.post(`/stockcounts/${stockCount.id}/barcode`, { product_id: p.id, barcode: code, pack_size: size });
            beep(true);
            linkFor = null;
            closeSheet();
            selectProduct(res.data, size);
            message = { kind: 'ok', text: `ผูกบาร์โค้ด ${code} ${size > 1 ? `(แพ็ค ${size} ชิ้น) ` : ''}กับ ${p.name} แล้ว — กรอกจำนวนที่นับได้เลย` };
        } catch (e) {
            beep(false);
            message = { kind: 'bad', text: e.message };
            closeSheet();
        } finally {
            busy = false;
            draw();
        }
    }

    // ---- bottom sheets: product search and piece/pack choice ----
    const sheet = $('sheet');
    function closeSheet() {
        sheet.hidden = true;
        sheet.innerHTML = '';
    }

    function openSearch(linkCode) {
        sheet.hidden = false;
        sheet.innerHTML = `
          <div class="mc-sheet-box">
            <div class="mc-sheet-head">
              <b>${linkCode ? `เลือกสินค้าที่จะผูกบาร์โค้ด ${escapeHtml(linkCode)}` : 'ค้นหาสินค้า'}</b>
              <button type="button" class="mc-icon" data-sheet="close" aria-label="ปิด">✕</button>
            </div>
            <input type="search" class="mc-search" placeholder="พิมพ์ชื่อสินค้า หรือบาร์โค้ด" enterkeyhint="search" autocomplete="off">
            <div class="mc-results"><div class="muted" style="padding:12px">พิมพ์อย่างน้อย 1 ตัวอักษร</div></div>
          </div>`;
        const box = sheet.querySelector('.mc-results');
        const field = sheet.querySelector('.mc-search');
        let found = [];
        let timer = null;
        let seq = 0;
        field.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(async () => {
                const q = field.value.trim();
                if (!q) return;
                const my = ++seq;
                try {
                    const res = await api.get(`/stockcounts/${stockCount.id}/search?q=${encodeURIComponent(q)}`);
                    if (my !== seq) return;
                    found = res.data;
                    box.innerHTML = found.length ? found.map((p, i) => `
                      <button type="button" class="mc-result" data-pick="${i}">
                        ${p.image_token ? `<img src="${photoUrl(p)}" alt="">` : '<span class="mc-noimg">📦</span>'}
                        <span class="mc-result-main"><b>${escapeHtml(p.name)}</b>
                          <small>${escapeHtml(p.barcode || 'ไม่มีบาร์โค้ด')} · ${escapeHtml(p.consignor_name)}</small></span>
                        <span class="mc-result-qty">${p.item ? Number(p.item.system_qty) : Number(p.qty_stock)}<small>${counted.get(Number(p.id)) != null ? `นับ ${counted.get(Number(p.id))}` : 'ในระบบ'}</small></span>
                      </button>`).join('') : '<div class="muted" style="padding:12px">ไม่พบสินค้า</div>';
                } catch (e) {
                    box.innerHTML = `<div class="error-box">${escapeHtml(e.message)}</div>`;
                }
            }, 250);
        });
        box.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-pick]');
            if (!btn) return;
            const p = found[Number(btn.dataset.pick)];
            if (linkCode) {
                openLinkSheet(p, linkCode);
            } else {
                closeSheet();
                selectProduct(p, 1);
            }
        });
        setTimeout(() => field.focus(), 50);
    }

    function openLinkSheet(p, code) {
        const storeGoods = p.consignor_id === null;
        sheet.hidden = false;
        sheet.innerHTML = `
          <div class="mc-sheet-box">
            <div class="mc-sheet-head">
              <b>ผูกบาร์โค้ด ${escapeHtml(code)}</b>
              <button type="button" class="mc-icon" data-sheet="close" aria-label="ปิด">✕</button>
            </div>
            <p style="margin:4px 0 12px">กับสินค้า <b>${escapeHtml(p.name)}</b></p>
            <button type="button" class="mc-btn wide" data-size="1">บาร์โค้ดชิ้น (ขายที่ POS)</button>
            ${p.barcode && p.barcode !== code ? `<p class="muted" style="font-size:13px;margin:6px 0 0">แทนที่บาร์โค้ดชิ้นเดิม ${escapeHtml(p.barcode)}</p>` : ''}
            ${storeGoods ? `
              <div class="mc-pack-row">
                <button type="button" class="mc-btn alt wide" data-size="pack">บาร์โค้ดแพ็ค</button>
                <label>แพ็คละ <input type="number" inputmode="numeric" min="2" max="1000" class="mc-pack-size" placeholder="24"> ชิ้น</label>
              </div>` : ''}
          </div>`;
        sheet.querySelectorAll('[data-size]').forEach((b) => b.addEventListener('click', () => {
            let size = 1;
            if (b.dataset.size === 'pack') {
                size = Number(sheet.querySelector('.mc-pack-size').value);
                if (!(size >= 2 && size <= 1000)) {
                    sheet.querySelector('.mc-pack-size').focus();
                    return;
                }
            }
            linkBarcode(p, code, size);
        }));
    }

    sheet.addEventListener('click', (e) => {
        if (e.target === sheet || e.target.closest('[data-sheet="close"]')) closeSheet();
    });

    // ---- camera ----
    async function toggleScan() {
        const cam = $('cam');
        if (stopScan) {
            stopScan();
            stopScan = null;
            cam.hidden = true;
            draw();
            return;
        }
        cam.hidden = false;
        draw();
        try {
            stopScan = await startScanner(video, (code) => {
                const now = Date.now();
                if (code === lastScan.code && now - lastScan.at < 2500) return;
                lastScan = { code, at: now };
                if (mode === 'qty' && !linkFor) {
                    // A new scan while keying a quantity starts over with the new product.
                    mode = 'code';
                }
                handleCode(code);
            });
        } catch (e) {
            cam.hidden = true;
            message = { kind: 'bad', text: 'เปิดกล้องไม่ได้: ' + (e.message || e.name) };
            draw();
        }
    }

    // ---- photo ----
    $('photo').addEventListener('change', async (e) => {
        const file = e.target.files[0];
        e.target.value = '';
        if (!file || !product) return;
        const p = product;
        message = { kind: 'info', text: 'กำลังอัปโหลดรูป…' };
        draw();
        try {
            const dataUrl = await fileToDataUrl(file);
            const res = await api.post(`/products/${p.id}/photo`, { data_url: dataUrl });
            p.image_token = res.data.image_token;
            photoCache++;
            message = { kind: 'ok', text: `บันทึกรูป ${p.name} แล้ว` };
        } catch (err) {
            message = { kind: 'bad', text: err.message };
        }
        draw();
    });

    // ---- input ----
    function press(key) {
        if (busy) return;
        if (key === 'C') input = '';
        else if (key === '⌫') input = input.slice(0, -1);
        else if (/^\d$/.test(key)) {
            if (mode === 'qty' && input.length >= 6) return;
            if (mode === 'code' && input.length >= 32) return;
            input = mode === 'qty' && input === '0' ? key : input + key;
        }
        if (mode === 'code' && message?.kind !== 'info') message = null;
        draw();
    }

    function close() {
        stopScan?.();
        document.removeEventListener('keydown', onKey);
        document.body.classList.remove('mc-open');
        root.remove();
        onClose?.();
    }

    root.addEventListener('click', (e) => {
        const key = e.target.closest('[data-key]');
        if (key) {
            press(key.dataset.key);
            return;
        }
        const act = e.target.closest('[data-act]')?.dataset.act;
        if (!act) return;
        if (act === 'close') close();
        else if (act === 'search') openSearch(null);
        else if (act === 'scan') toggleScan();
        else if (act === 'find') handleCode(input);
        else if (act === 'set') saveQty(false);
        else if (act === 'add') saveQty(true);
        else if (act === 'photo') $('photo').click();
        else if (act === 'link') openSearch(message.linkCode);
        else if (act === 'cancel') {
            mode = 'code';
            input = '';
            packSize = 1;
            draw();
        } else if (act === 'addcode') {
            linkFor = linkFor ? null : product;
            mode = 'code';
            input = '';
            message = linkFor ? { kind: 'info', text: 'สแกน หรือกดรหัสบาร์โค้ดใหม่ของสินค้านี้ แล้วกด «ผูกบาร์โค้ด»' } : null;
            draw();
        }
    });

    // Hardware (Bluetooth/USB) scanners type the code then Enter.
    function onKey(e) {
        if (!sheet.hidden || e.target.closest?.('input, textarea')) return;
        if (/^\d$/.test(e.key)) press(e.key);
        else if (/^[A-Za-z\-]$/.test(e.key) && mode === 'code') {
            input += e.key;
            draw();
        } else if (e.key === 'Backspace') press('⌫');
        else if (e.key === 'Escape') close();
        else if (e.key === 'Enter') {
            e.preventDefault();
            if (mode === 'qty') saveQty(false);
            else handleCode(input);
        } else return;
        e.preventDefault();
    }
    document.addEventListener('keydown', onKey);

    draw();
}
