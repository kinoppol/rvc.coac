import { api } from './api.js';
import { money, count, escapeHtml } from './util.js';
import { fileToDataUrl, thumbDataUrl } from './images.js';
import { play } from './sound.js';
import { startScanner } from './scanner.js';

function beep(kind = 'scan') {
    play(kind);
    navigator.vibrate?.(kind === 'error' || kind === 'warn' ? [60, 40, 60] : 40);
}

/**
 * Full-screen data mode for phones, opened from the inventory page: scan (or type) a
 * barcode to see the product — name, stock, price, barcodes, photo — take its photo,
 * or give a barcode the system does not know yet to the product it belongs to.
 * @param {{ canLink: boolean, canPhoto: boolean, onClose?: () => void }} opts
 */
export function openInventoryMobile({ canLink, canPhoto, onClose }) {
    let product = null;   // the product on screen (from /inventory/lookup)
    let scannedPack = 1;  // pack size of the barcode that found it
    let unknown = null;   // a barcode no product has
    let linkFor = null;   // product waiting for its next scanned barcode
    let message = null;   // { kind, text }
    let stopScan = null;
    let lastScan = { code: '', at: 0 };
    let photoCache = 0;
    const log = [];

    const root = document.createElement('div');
    root.className = 'mc';
    root.innerHTML = `
      <header class="mc-head">
        <button type="button" class="mc-icon" data-act="close" aria-label="ปิด">✕</button>
        <div class="mc-title"><b>เก็บข้อมูลสินค้า</b><span>สแกนบาร์โค้ดเพื่อดู / ผูกบาร์โค้ด / ถ่ายรูป</span></div>
        <button type="button" class="mc-scan" data-act="scan">📷 สแกน</button>
        <button type="button" class="mc-icon" data-act="search" aria-label="ค้นหาสินค้า">🔍</button>
      </header>
      <div class="mc-cam" data-el="cam" hidden><video playsinline muted></video><div class="mc-aim"></div></div>
      <div class="mc-scroll">
        <form data-el="form" style="display:flex;gap:6px">
          <input class="mc-search" data-el="code" style="flex:1;min-width:0" placeholder="พิมพ์หรือสแกนบาร์โค้ด" inputmode="numeric" autocomplete="off">
          <button type="submit" class="mc-btn" style="flex:0 0 auto;padding:0 18px;min-height:48px">ค้นหา</button>
        </form>
        <div data-el="msg"></div>
        <div data-el="card"></div>
        <div class="mc-log" data-el="log"></div>
      </div>
      <input type="file" accept="image/*" capture="environment" data-el="photo" hidden>
      <div class="mc-sheet" data-el="sheet" hidden></div>`;
    document.body.appendChild(root);
    const scrollY = window.scrollY;
    document.body.classList.add('mc-open');
    const $ = (name) => root.querySelector(`[data-el="${name}"]`);
    const video = root.querySelector('video');
    const sheet = $('sheet');

    const photoUrl = (p) => (p.image_token ? api.downloadUrl(`/products/${p.id}/photo?size=thumb&v=${p.image_token}${photoCache}`) : null);

    function draw() {
        const scan = root.querySelector('.mc-scan');
        scan.classList.toggle('on', !!stopScan);
        scan.textContent = stopScan ? '⏹ ปิดกล้อง' : '📷 สแกน';
        $('msg').innerHTML = message ? `<div class="mc-msg ${message.kind}">${escapeHtml(message.text)}</div>` : '';
        $('card').innerHTML = linkFor ? `
            <div class="mc-msg info"><b>สแกนบาร์โค้ดใหม่ให้ «${escapeHtml(linkFor.name)}»</b>
              <span>เปิดกล้องแล้วสแกน หรือพิมพ์บาร์โค้ดในช่องด้านบน</span>
              <button type="button" class="btn secondary sm" data-act="unlink">ยกเลิก</button></div>`
            : unknown ? `
            <div class="mc-msg bad"><b>ไม่พบสินค้าที่มีบาร์โค้ด ${escapeHtml(unknown)}</b>
              ${canLink ? '<span>ถ้าเป็นสินค้าที่มีในระบบแต่ยังไม่มีบาร์โค้ดนี้ ค้นหาแล้วผูกบาร์โค้ดให้ได้เลย</span><button type="button" class="btn sm" data-act="find-for-code">ค้นหาสินค้าเพื่อผูกบาร์โค้ดนี้</button>' : '<span>แจ้งผู้จัดการให้เพิ่มบาร์โค้ดนี้ให้สินค้า</span>'}</div>`
            : product ? cardHtml(product) : '<div class="mc-msg info">กด 📷 สแกน แล้วส่องบาร์โค้ดบนสินค้า หรือกด 🔍 ค้นหาจากชื่อ</div>';
        $('log').innerHTML = log.length ? `<div style="font-weight:600;margin:6px 2px">สแกนล่าสุด</div>${log.map((l) => `<div><span>${escapeHtml(l.code)} · ${escapeHtml(l.text)}</span></div>`).join('')}` : '';
    }

    function cardHtml(p) {
        const url = photoUrl(p);
        return `
          <div class="mc-card">
            <button type="button" class="mc-photo" data-act="photo" ${canPhoto ? '' : 'disabled'} aria-label="ถ่ายรูปสินค้า">
              ${url ? `<img src="${escapeHtml(url)}" alt="">${canPhoto ? '<span class="mc-photo-re">📷</span>' : ''}` : (canPhoto ? '📷 ถ่ายรูป' : 'ไม่มีรูป')}</button>
            <div class="mc-info">
              <div class="mc-name">${escapeHtml(p.name)}</div>
              <div class="mc-sub">${escapeHtml(p.consignor_name)}${scannedPack > 1 ? ` · สแกนบาร์โค้ดแพ็ค ×${scannedPack}` : ''}</div>
              <div class="mc-nums"><span>คงเหลือ <b>${count(p.qty_stock)}</b></span><span>ราคา <b>฿${money(p.sale_price)}</b></span></div>
              <div class="mc-codes">
                ${p.barcode ? `<span class="mc-chip">${escapeHtml(p.barcode)}</span>` : '<span class="mc-chip">ไม่มีบาร์โค้ดชิ้น</span>'}
                ${p.pack_barcodes.map((b) => `<span class="mc-chip">${b.barcode ? escapeHtml(b.barcode) : 'ไม่มีบาร์โค้ด'} ×${b.pack_size}</span>`).join('')}
                ${canLink ? '<button type="button" class="mc-chip add" data-act="link">+ ผูกบาร์โค้ดเพิ่ม</button>' : ''}
              </div>
            </div>
          </div>`;
    }

    function addLog(code, text) {
        log.unshift({ code, text });
        log.length = Math.min(log.length, 15);
    }

    async function handleCode(raw) {
        const code = String(raw).trim();
        if (!code) return;
        if (linkFor) {
            openLinkSheet(linkFor, code);
            return;
        }
        message = null;
        try {
            const res = await api.get(`/inventory/lookup?code=${encodeURIComponent(code)}`);
            product = res.data;
            scannedPack = res.data.scanned_pack_size || 1;
            unknown = null;
            beep(scannedPack > 1 ? 'pack' : 'scan');
            addLog(code, product.name);
        } catch (e) {
            if (e.status !== 404) {
                beep('error');
                message = { kind: 'bad', text: e.message };
            } else {
                beep('warn');
                product = null;
                unknown = code;
                addLog(code, 'ไม่พบในระบบ');
            }
        }
        draw();
    }

    // ---- camera: stays on while scanning is switched on; the same code is read once every few seconds ----
    async function startCamera() {
        $('cam').hidden = false;
        try {
            stopScan = await startScanner(video, (code) => {
                const now = Date.now();
                if (code === lastScan.code && now - lastScan.at < 2500) return;
                lastScan = { code, at: now };
                if (!sheet.hidden) return;
                handleCode(code);
            });
        } catch (e) {
            $('cam').hidden = true;
            stopScan = null;
            message = { kind: 'bad', text: 'เปิดกล้องไม่ได้: ' + e.message };
        }
        draw();
    }
    function stopCamera() {
        stopScan?.();
        stopScan = null;
        $('cam').hidden = true;
    }

    // ---- sheets: product search, linking a barcode ----
    const backBtn = '<button type="button" class="mc-back" data-sheet-close>‹ กลับ</button>';
    function showSheet(html) {
        sheet.innerHTML = html;
        sheet.hidden = false;
        root.classList.add('sheet-open');
        sheet.querySelectorAll('[data-sheet-close]').forEach((b) => b.addEventListener('click', closeSheet));
    }
    function closeSheet() {
        sheet.hidden = true;
        sheet.innerHTML = '';
        root.classList.remove('sheet-open');
        draw();
    }

    /** Search by name; with forCode, the product picked gets that barcode. */
    function openSearch(forCode = null) {
        showSheet(`
          <div class="mc-sheet-box">
            <div class="mc-sheet-head">${backBtn}<b>${forCode ? `ผูกบาร์โค้ด ${escapeHtml(forCode)} กับสินค้า` : 'ค้นหาสินค้า'}</b></div>
            <input class="mc-search" data-el="q" placeholder="พิมพ์ชื่อสินค้า" autocomplete="off">
            <div class="mc-results" data-el="results"></div>
          </div>`);
        const field = sheet.querySelector('[data-el="q"]');
        const box = sheet.querySelector('[data-el="results"]');
        let found = [];
        let t = null;
        field.addEventListener('input', () => {
            clearTimeout(t);
            t = setTimeout(async () => {
                const q = field.value.trim();
                if (!q) { box.innerHTML = ''; return; }
                try {
                    found = (await api.get(`/inventory/search?q=${encodeURIComponent(q)}`)).data;
                    box.innerHTML = found.map((p, i) => `
                      <div class="mc-result"><button type="button" class="mc-result-pick" data-pick="${i}">
                        <span class="mc-result-main"><b>${escapeHtml(p.name)}</b>
                          <small>${escapeHtml(p.barcode || 'ไม่มีบาร์โค้ด')} · ${escapeHtml(p.consignor_name)}</small></span>
                        <span class="mc-result-qty">${count(p.qty_stock)}<small>คงเหลือ</small></span></button></div>`).join('')
                        || '<div class="muted" style="padding:10px 4px">ไม่พบสินค้า</div>';
                } catch (e) {
                    box.innerHTML = `<div class="error-box">${escapeHtml(e.message)}</div>`;
                }
            }, 250);
        });
        box.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-pick]');
            if (!btn) return;
            const p = found[Number(btn.dataset.pick)];
            if (forCode) {
                openLinkSheet(p, forCode);
            } else {
                product = p;
                scannedPack = 1;
                unknown = null;
                closeSheet();
            }
        });
        setTimeout(() => field.focus(), 50);
    }

    /** Give `code` to product `p`: as its piece barcode, or (store goods) as a pack barcode. */
    function openLinkSheet(p, code) {
        const store = p.consignor_id == null;
        showSheet(`
          <div class="mc-sheet-box">
            <div class="mc-sheet-head">${backBtn}<b>ผูกบาร์โค้ด</b></div>
            <p style="margin:0 0 4px;font-size:20px;font-family:ui-monospace,monospace;word-break:break-all">${escapeHtml(code)}</p>
            <p style="margin:0 0 10px">กับสินค้า <b>${escapeHtml(p.name)}</b></p>
            <div class="mc-pack-row">
              <label><input type="radio" name="lk" value="1" checked style="width:22px;height:22px"> บาร์โค้ดชิ้น${p.barcode ? ` <span class="muted" style="font-size:13px">(แทนที่ ${escapeHtml(p.barcode)})</span>` : ''}</label>
              ${store ? `<label><input type="radio" name="lk" value="pack" style="width:22px;height:22px"> บาร์โค้ดแพ็ค
                <input type="number" class="mc-pack-size" data-el="size" min="2" step="1" placeholder="ชิ้น/แพ็ค" inputmode="numeric"></label>` : ''}
            </div>
            <div class="error-box" data-el="lerr" hidden style="margin-top:10px"></div>
            <button type="button" class="mc-btn wide" data-el="lsave" style="margin-top:14px">บันทึกบาร์โค้ด</button>
          </div>`);
        const size = sheet.querySelector('[data-el="size"]');
        size?.addEventListener('focus', () => { sheet.querySelector('input[name="lk"][value="pack"]').checked = true; });
        sheet.querySelector('[data-el="lsave"]').addEventListener('click', async (ev) => {
            const pack = sheet.querySelector('input[name="lk"]:checked').value === 'pack';
            const n = pack ? Number(size.value) : 1;
            const err = sheet.querySelector('[data-el="lerr"]');
            if (pack && !(n >= 2)) { err.hidden = false; err.textContent = 'กรุณาระบุจำนวนชิ้นต่อแพ็ค (2 ขึ้นไป)'; return; }
            ev.target.disabled = true;
            try {
                const res = await api.post(`/inventory/${p.id}/assign-barcode`, { barcode: code, pack_size: n });
                product = res.data;
                scannedPack = n;
                unknown = null;
                linkFor = null;
                beep('count');
                message = { kind: 'ok', text: `ผูกบาร์โค้ด ${code} กับ ${product.name}${n > 1 ? ` (แพ็ค ${n} ชิ้น)` : ''} แล้ว` };
                addLog(code, `ผูกกับ ${product.name}`);
                closeSheet();
            } catch (e) {
                beep('error');
                ev.target.disabled = false;
                err.hidden = false;
                err.textContent = e.message;
            }
        });
    }

    // ---- photo: saved on the product record ----
    $('photo').addEventListener('change', async (e) => {
        const file = e.target.files[0];
        e.target.value = '';
        const p = product;
        if (!file || !p) return;
        message = { kind: 'info', text: `กำลังอัปโหลดรูป ${p.name}…` };
        draw();
        try {
            const dataUrl = await fileToDataUrl(file);
            const res = await api.post(`/products/${p.id}/photo`, { data_url: dataUrl, thumb_data_url: await thumbDataUrl(dataUrl) });
            p.image_token = res.data.image_token;
            photoCache++;
            beep('upload');
            message = { kind: 'ok', text: `บันทึกรูป ${p.name} แล้ว` };
        } catch (err) {
            beep('error');
            message = { kind: 'bad', text: 'บันทึกรูปไม่สำเร็จ: ' + err.message };
        }
        draw();
    });

    function close() {
        stopCamera();
        root.remove();
        document.body.classList.remove('mc-open');
        window.scrollTo(0, scrollY);
        onClose?.();
    }

    $('form').addEventListener('submit', (e) => {
        e.preventDefault();
        const v = $('code').value;
        $('code').value = '';
        handleCode(v);
    });
    root.addEventListener('click', (e) => {
        const act = e.target.closest('[data-act]')?.dataset.act;
        if (!act) return;
        if (act === 'close') close();
        else if (act === 'scan') { if (stopScan) { stopCamera(); draw(); } else startCamera(); }
        else if (act === 'search') openSearch();
        else if (act === 'photo' && canPhoto && product) $('photo').click();
        else if (act === 'link' && product) { linkFor = product; message = null; draw(); if (!stopScan) startCamera(); }
        else if (act === 'unlink') { linkFor = null; draw(); }
        else if (act === 'find-for-code' && unknown) openSearch(unknown);
    });
    draw();
}
