import { api } from '../api.js';
import { escapeHtml } from '../util.js';
import { fileToDataUrl, thumbDataUrl } from '../images.js';
import { play, soundButtonLabel, openSoundPanel } from '../sound.js';
import { startScanner } from '../scanner.js';

/**
 * Why `code` is not a valid EAN-13 (13 digits, the last one the check digit of the other 12), or '' when it is.
 * A 12-digit UPC-A is the same code without its leading 0, so it is checked as such.
 */
function ean13Problem(code) {
    if (!/^\d+$/.test(code)) return 'มีตัวอักษรหรือสัญลักษณ์ปนอยู่ (EAN-13 เป็นตัวเลขล้วน)';
    if (code.length === 12) code = `0${code}`;
    if (code.length !== 13) return `มี ${code.length} หลัก (EAN-13 ต้องมี 13 หลัก)`;
    const sum = [...code.slice(0, 12)].reduce((s, d, i) => s + Number(d) * (i % 2 ? 3 : 1), 0);
    return (10 - (sum % 10)) % 10 === Number(code[12]) ? '' : 'เลขตรวจสอบ (หลักสุดท้าย) ไม่ถูกต้อง อาจสแกนอ่านผิด';
}

// Odd barcodes the counter already accepted (e.g. the store's own printed codes): not asked again on this device.
const ACCEPTED_KEY = 'count.acceptedCodes';
function loadAccepted() {
    try { return new Set(JSON.parse(localStorage.getItem(ACCEPTED_KEY) || '[]')); } catch { return new Set(); }
}
function saveAccepted(set) {
    try { localStorage.setItem(ACCEPTED_KEY, JSON.stringify([...set].slice(-500))); } catch { /* private mode */ }
}

// Keypad height, smaller keys leave more of the screen for the product card. Kept per device.
const PAD_KEY = 'count.padSize';
const PAD_SIZES = [['', 'ปกติ'], ['s', 'เล็ก'], ['xs', 'เล็กมาก']];
function loadPadSize() {
    try { const v = localStorage.getItem(PAD_KEY) || ''; return PAD_SIZES.some(([k]) => k === v) ? v : ''; } catch { return ''; }
}

/** Counting photos: shrunk to fit a common computer screen full size and still read clearly. */
export const COUNT_PHOTO_SIZE = 1600;

/** A sound for the event (see sound.js) and a short buzz on phones. */
function beep(kind = 'scan') {
    play(kind);
    navigator.vibrate?.(kind === 'error' || kind === 'warn' ? [60, 40, 60] : 40);
}

/**
 * Full-screen counting mode for phones: scan or key in a barcode, then key in
 * the counted quantity on a large keypad. Saves each product immediately.
 */
export function openMobileCount({ stockCount, items, onClose }) {
    const counted = new Map(items.map((it) => [Number(it.product_id), it.counted_qty === null ? null : Number(it.counted_qty)]));
    let total = items.length;
    let product = null;     // productInfo from the API
    let mode = 'code';      // keypad target: 'code' | 'qty'
    let input = '';         // barcode being keyed in (mode 'code')
    // Quantity (mode 'qty'): counted in pieces, in packs, or packs + loose pieces.
    let unit = 'piece';     // 'piece' | 'pack' | 'mix'
    let lastUnit = 'piece'; // the counter's last choice, kept for the next product
    let q = { size: '', packs: '', pieces: '' };
    let field = 'pieces';   // which part of q the keypad types into
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
        <button type="button" class="mc-scan" data-act="scan">📷 สแกน</button>
        ${document.fullscreenEnabled ? '<button type="button" class="mc-icon" data-act="fullscreen" aria-label="เต็มหน้าจอ">⛶</button>' : ''}
        <button type="button" class="mc-icon snd-btn" data-act="sound" aria-label="ระดับเสียง" title="ระดับเสียง">${soundButtonLabel()}</button>
        <button type="button" class="mc-icon" data-act="padsize" aria-label="ขนาดปุ่มตัวเลข" title="ขนาดปุ่มตัวเลข">⌨</button>
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
      <input type="file" accept="image/*" capture="environment" data-el="countphoto" hidden>
      <div class="mc-sheet" data-el="sheet" hidden></div>`;
    let padSize = loadPadSize();
    if (padSize) root.classList.add(`pad-${padSize}`);
    document.body.appendChild(root);
    // Hide the page underneath (a wide table there makes phones zoom out). Fullscreen
    // is opt-in (⛶): Chrome shows an exit hint over the bottom buttons when it starts.
    const scrollY = window.scrollY;
    document.body.classList.add('mc-open');

    const $ = (name) => root.querySelector(`[data-el="${name}"]`);
    const video = root.querySelector('video');

    function countedN() {
        return [...counted.values()].filter((v) => v !== null).length;
    }

    function photoUrl(p) {
        return p.image_token ? api.downloadUrl(`/products/${p.id}/photo?size=thumb&v=${p.image_token}${photoCache}`) : null;
    }

    /** Pack sizes known from the product's pack barcodes, smallest first. */
    function packSizes(p) {
        return [...new Set((p?.pack_barcodes || []).map((b) => Number(b.pack_size)))].filter((n) => n > 1).sort((a, b) => a - b);
    }

    /** Pieces counted from q, or null while it is incomplete. */
    function qtyTotal() {
        const size = Number(q.size);
        const packs = Number(q.packs || 0);
        const pieces = Number(q.pieces || 0);
        if (unit === 'piece') return q.pieces === '' ? null : pieces;
        if (unit === 'pack') return q.packs === '' || !(size > 1) ? null : packs * size;
        if (q.packs === '' && q.pieces === '') return null;
        if (packs > 0 && !(size > 1)) return null;
        return packs * size + pieces;
    }

    function setUnit(u) {
        unit = lastUnit = u;
        field = u === 'piece' ? 'pieces' : (Number(q.size) > 1 ? 'packs' : 'size');
    }

    function resetQty() {
        q = { size: '', packs: '', pieces: '' };
        unit = 'piece';
        field = 'pieces';
    }

    function qtyHtml() {
        const units = [['piece', 'นับเป็นชิ้น'], ['pack', 'นับเป็นแพ็ค'], ['mix', 'แพ็ค + ชิ้น']];
        const lbl = `<div class="mc-units">${units.map(([u, l]) => `<button type="button" class="mc-unit${unit === u ? ' on' : ''}" data-unit="${u}">${l}</button>`).join('')}</div>`;
        if (unit === 'piece') {
            return { lbl, val: q.pieces ? escapeHtml(q.pieces) : '<span class="mc-ph">–</span>' };
        }
        const f = (k, label) => `<button type="button" class="mc-field${field === k ? ' on' : ''}" data-field="${k}"><span>${label}</span><b>${q[k] === '' ? '–' : escapeHtml(q[k])}</b></button>`;
        const sizes = packSizes(product);
        const total = qtyTotal();
        return {
            lbl,
            val: `<div class="mc-fields">${f('size', 'ชิ้น/แพ็ค')}<i>×</i>${f('packs', 'จำนวนแพ็ค')}${unit === 'mix' ? `<i>+</i>${f('pieces', 'ชิ้นเศษ')}` : ''}</div>
              <div class="mc-total">
                <span>${sizes.map((n) => `<button type="button" class="mc-chip${Number(q.size) === n ? ' on' : ''}" data-psize="${n}">แพ็ค ${n}</button>`).join(' ')}</span>
                <span>รวม <b>${total ?? '–'}</b> ชิ้น</span>
              </div>`,
        };
    }

    function draw() {
        $('progress').textContent = `นับแล้ว ${countedN()}/${total}`;
        const cam = $('cam');
        const scan = root.querySelector('.mc-scan');
        scan.classList.toggle('on', !cam.hidden);
        scan.textContent = cam.hidden ? '📷 สแกน' : '⏹ ปิดกล้อง';
        resumeScanSoon();

        let card = '';
        if (product) {
            const sys = product.item ? Number(product.item.system_qty) : Number(product.qty_stock);
            const got = counted.get(Number(product.id));
            const img = photoUrl(product);
            card = `
              <div class="mc-card">
                <button type="button" class="mc-photo" data-act="photo" aria-label="ถ่ายภาพสินค้า">
                  ${img ? `<img src="${img}" alt=""><span class="mc-photo-re">📷</span>` : '<span>📷<br>ถ่ายภาพ<br>สินค้า</span>'}
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
                    ${product.pack_barcodes.map((b) => `<span class="mc-chip">${b.barcode ? escapeHtml(b.barcode) : 'ไม่มีบาร์โค้ด'} ×${b.pack_size}</span>`).join('')}
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

        if (mode === 'qty') {
            const { lbl, val } = qtyHtml();
            $('lbl').innerHTML = lbl;
            $('val').innerHTML = val;
        } else {
            $('lbl').textContent = linkFor ? `บาร์โค้ดใหม่ของ ${linkFor.name}` : 'รหัสสินค้า / บาร์โค้ด';
            $('val').innerHTML = input ? escapeHtml(input) : '<span class="mc-ph">–</span>';
        }
        $('val').classList.toggle('qty', mode === 'qty');
        $('val').classList.toggle('split', mode === 'qty' && unit !== 'piece');

        const ready = qtyTotal() !== null && !busy;
        $('actions').innerHTML = mode === 'qty'
            ? `<button type="button" class="mc-btn ghost" data-act="cancel">ยกเลิก</button>
               <button type="button" class="mc-btn alt" data-act="add" ${ready ? '' : 'disabled'}>+ บวกเพิ่ม</button>
               <button type="button" class="mc-btn" data-act="set" ${ready ? '' : 'disabled'}>✓ บันทึก</button>`
            : `<button type="button" class="mc-btn" data-act="find" ${input && !busy ? '' : 'disabled'}>${linkFor ? 'ผูกบาร์โค้ด' : 'ค้นหา'}</button>`;
    }

    /** Product found: key in its count. A scanned pack barcode starts in packs of that size. */
    function selectProduct(p, size = 1) {
        product = p;
        mode = 'qty';
        input = '';
        q = { size: String(size > 1 ? size : (packSizes(p)[0] || '')), packs: '', pieces: '' };
        if (size > 1) {
            unit = lastUnit === 'mix' ? 'mix' : 'pack';
            field = 'packs';
        } else {
            setUnit(lastUnit);
        }
        message = null;
        draw();
    }

    async function handleCode(code, accepted = false) {
        code = String(code).trim();
        if (!code || busy) return;
        // Guard against misreads: a code that is not a valid EAN-13 needs the counter's OK first.
        const problem = ean13Problem(code);
        if (problem && !accepted && !acceptedCodes.has(code)) {
            beep('warn');
            openOddCodeSheet(code, problem);
            return;
        }
        if (linkFor) {
            await linkBarcode(linkFor, code, null);
            return;
        }
        busy = true;
        try {
            const res = await api.get(`/stockcounts/${stockCount.id}/lookup?code=${encodeURIComponent(code)}`);
            beep(res.data.scanned_pack_size > 1 ? 'pack' : 'scan');
            selectProduct(res.data, res.data.scanned_pack_size || 1);
        } catch (e) {
            beep('error');
            product = null;
            mode = 'code';
            input = '';
            message = { kind: 'bad', text: e.message, linkCode: e.status === 404 ? code : null };
        } finally {
            busy = false;
            draw();
        }
        // Unknown barcode: go straight to picking the product it belongs to.
        if (message?.linkCode) openSearch(message.linkCode);
    }

    /**
     * Opens the camera for a photo of what was counted (required with every save); resolves to
     * a JPEG shrunk to COUNT_PHOTO_SIZE, or null when the counter backs out of the camera.
     */
    function takeCountPhoto() {
        return new Promise((resolve) => {
            const inp = $('countphoto');
            let done = false;
            const finish = async (file) => {
                if (done) return;
                done = true;
                window.removeEventListener('focus', onFocus);
                resolve(file ? await thumbDataUrl(await fileToDataUrl(file), COUNT_PHOTO_SIZE) : null);
            };
            // Browsers without the cancel event: back from the camera with no file means cancelled.
            const onFocus = () => setTimeout(() => { if (!inp.files.length) finish(null); }, 1500);
            inp.onchange = () => {
                const f = inp.files[0];
                inp.value = '';
                finish(f || null);
            };
            inp.oncancel = () => finish(null);
            window.addEventListener('focus', onFocus);
            inp.click();
        });
    }

    async function saveQty(addTo) {
        const pieces = qtyTotal();
        if (!product || pieces === null || busy) return;
        const how = unit === 'piece' ? '' : `(${Number(q.packs || 0)}×${q.size}${unit === 'mix' && q.pieces ? `+${Number(q.pieces)}` : ''}) `;
        const prev = counted.get(Number(product.id)) ?? 0;
        const value = addTo ? prev + pieces : pieces;
        // The camera opens straight from the tap (browsers allow it only then).
        const shot = takeCountPhoto();
        busy = true;
        message = { kind: 'info', text: `ถ่ายรูป ${product.name} ที่นับไว้เป็นหลักฐาน…` };
        draw();
        const photo = await shot;
        if (!photo) {
            busy = false;
            beep('warn');
            message = { kind: 'bad', text: 'ต้องถ่ายรูปสินค้าที่นับก่อนบันทึก — กดบันทึกอีกครั้งเพื่อถ่ายรูป' };
            draw();
            return;
        }
        try {
            const res = await api.post(`/stockcounts/${stockCount.id}/count`, {
                product_id: product.id, counted_qty: value, photo,
                photo_note: addTo ? `บวกเพิ่ม ${how}${pieces} → ${value}` : `${how}${value}`,
            });
            if (!counted.has(Number(product.id))) total++;
            counted.set(Number(product.id), value);
            log.unshift({ name: product.name, qty: how + (addTo ? `${prev} + ${pieces} = ${value}` : value) });
            beep('saved');
            product = res.data;
            mode = 'code';
            input = '';
            resetQty();
            message = { kind: 'ok', text: `บันทึก ${product.name} = ${value} ชิ้น` };
        } catch (e) {
            beep('error');
            message = { kind: 'bad', text: e.message };
        } finally {
            busy = false;
            draw();
        }
    }

    /** Links `code` to product p; asks piece vs pack first unless size is given. */
    async function linkBarcode(p, code, size) {
        if (size === null) {
            openLinkSheet(p, code);
            return;
        }
        busy = true;
        try {
            const res = await api.post(`/stockcounts/${stockCount.id}/barcode`, { product_id: p.id, barcode: code, pack_size: size });
            beep('saved');
            linkFor = null;
            closeSheet();
            selectProduct(res.data, size);
            message = { kind: 'ok', text: `ผูกบาร์โค้ด ${code} ${size > 1 ? `(แพ็ค ${size} ชิ้น) ` : ''}กับ ${p.name} แล้ว — กรอกจำนวนที่นับได้เลย` };
        } catch (e) {
            beep('error');
            message = { kind: 'bad', text: e.message };
            closeSheet();
        } finally {
            busy = false;
            draw();
        }
    }

    // ---- pages: product search and piece/pack choice. They replace the keypad screen
    // (one layer, scrolls as a whole) rather than sliding over it. ----
    const sheet = $('sheet');
    let redrawResults = null;   // re-renders the open search list (after a photo is added)
    function showSheet(html) {
        stopCamera();   // reopens on return to the barcode screen
        sheet.hidden = false;
        sheet.innerHTML = html;
        sheet.scrollTop = 0;
        root.classList.add('sheet-open');
    }
    function closeSheet() {
        sheet.hidden = true;
        sheet.innerHTML = '';
        redrawResults = null;
        root.classList.remove('sheet-open');
        draw();
    }
    const backBtn = '<button type="button" class="mc-back" data-sheet="close">← กลับ</button>';

    function openSearch(linkCode) {
        showSheet(`
          <div class="mc-sheet-box">
            <div class="mc-sheet-head">
              ${backBtn}
              <b>${linkCode ? `เลือกสินค้าที่จะผูกบาร์โค้ด ${escapeHtml(linkCode)}` : 'ค้นหาสินค้า'}</b>
            </div>
            ${linkCode ? `<div class="mc-msg bad" style="margin-bottom:8px">ไม่พบบาร์โค้ดนี้ในฐานข้อมูล — ค้นหาชื่อสินค้าแล้วแตะเพื่อจับคู่</div>` : ''}
            <input type="search" class="mc-search" placeholder="พิมพ์ชื่อสินค้า หรือบาร์โค้ด" enterkeyhint="search" autocomplete="off">
            <div class="mc-results"><div class="muted" style="padding:12px">พิมพ์อย่างน้อย 1 ตัวอักษร</div></div>
          </div>`);
        const box = sheet.querySelector('.mc-results');
        const field = sheet.querySelector('.mc-search');
        let found = [];
        let timer = null;
        let seq = 0;
        function drawResults() {
            box.innerHTML = found.length ? found.map((p, i) => `
              <div class="mc-result">
                <button type="button" class="mc-result-photo" data-shot="${i}" aria-label="ถ่ายภาพสินค้า">
                  ${p.image_token ? `<img src="${photoUrl(p)}" alt="">` : '<span class="mc-noimg">📷</span>'}
                </button>
                <button type="button" class="mc-result-pick" data-pick="${i}">
                  <span class="mc-result-main"><b>${escapeHtml(p.name)}</b>
                    <small>${escapeHtml(p.barcode || 'ไม่มีบาร์โค้ด')} · ${escapeHtml(p.consignor_name)}</small></span>
                  <span class="mc-result-qty">${p.item ? Number(p.item.system_qty) : Number(p.qty_stock)}<small>${counted.get(Number(p.id)) != null ? `นับ ${counted.get(Number(p.id))}` : 'ในระบบ'}</small></span>
                </button>
              </div>`).join('') : '<div class="muted" style="padding:12px">ไม่พบสินค้า</div>';
        }
        redrawResults = drawResults;
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
                    drawResults();
                } catch (e) {
                    box.innerHTML = `<div class="error-box">${escapeHtml(e.message)}</div>`;
                }
            }, 250);
        });
        box.addEventListener('click', (e) => {
            const shot = e.target.closest('[data-shot]');
            if (shot) {
                takePhoto(found[Number(shot.dataset.shot)]);
                return;
            }
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

    const acceptedCodes = loadAccepted();

    function openOddCodeSheet(code, problem) {
        showSheet(`
          <div class="mc-sheet-box">
            <div class="mc-sheet-head">${backBtn}<b>⚠️ บาร์โค้ดผิดปกติ</b></div>
            <p style="margin:4px 0 6px;font-size:20px;font-family:ui-monospace,monospace;word-break:break-all">${escapeHtml(code)}</p>
            <p style="margin:0 0 6px">ไม่ใช่บาร์โค้ด EAN-13 ที่ถูกต้อง: ${escapeHtml(problem)}</p>
            <p class="muted" style="font-size:13px;margin:0 0 14px">ตรวจดูบาร์โค้ดบนสินค้าอีกครั้ง ถ้าเป็นบาร์โค้ดที่ร้านหรือผู้ฝากพิมพ์เอง กดยอมรับได้</p>
            <button type="button" class="mc-btn wide" data-odd="rescan">สแกนใหม่</button>
            <button type="button" class="mc-btn alt wide" data-odd="accept" style="margin-top:10px">ยอมรับบาร์โค้ดนี้</button>
          </div>`);
        sheet.querySelectorAll('[data-odd]').forEach((b) => b.addEventListener('click', () => {
            closeSheet();
            if (b.dataset.odd !== 'accept') return;
            acceptedCodes.add(code);
            saveAccepted(acceptedCodes);
            handleCode(code, true);
        }));
    }

    function openLinkSheet(p, code) {
        const storeGoods = p.consignor_id === null;
        showSheet(`
          <div class="mc-sheet-box">
            <div class="mc-sheet-head">
              ${backBtn}
              <b>ผูกบาร์โค้ด ${escapeHtml(code)}</b>
            </div>
            <p style="margin:4px 0 12px">กับสินค้า <b>${escapeHtml(p.name)}</b></p>
            <button type="button" class="mc-btn wide" data-size="1">บาร์โค้ดชิ้น (ขายที่ POS)</button>
            ${p.barcode && p.barcode !== code ? `<p class="muted" style="font-size:13px;margin:6px 0 0">แทนที่บาร์โค้ดชิ้นเดิม ${escapeHtml(p.barcode)}</p>` : ''}
            ${storeGoods ? `
              <div class="mc-pack-row">
                <label>แพ็คละ <input type="number" inputmode="numeric" min="2" max="1000" class="mc-pack-size" placeholder="24"> ชิ้น</label>
                <button type="button" class="mc-btn alt wide" data-size="pack">บาร์โค้ดแพ็ค</button>
              </div>` : ''}
          </div>`);
        // Keep the pack field and its button above the on-screen keyboard.
        sheet.querySelector('.mc-pack-size')?.addEventListener('focus', (e) => {
            setTimeout(() => e.target.closest('.mc-pack-row').scrollIntoView({ block: 'end', behavior: 'smooth' }), 300);
        });
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
        if (e.target.closest('[data-sheet="close"]')) closeSheet();
    });

    // ---- camera: closes as soon as a barcode is read, and opens again by itself
    // once that product is saved / linked / cancelled (while scanning is switched on) ----
    let scanWanted = false;
    let scanStarting = false;

    function stopCamera() {
        stopScan?.();
        stopScan = null;
        $('cam').hidden = true;
    }

    async function startCamera() {
        if (stopScan || scanStarting) return;
        scanStarting = true;
        $('cam').hidden = false;
        draw();
        try {
            const stop = await startScanner(video, (code) => {
                if (!stopScan || !sheet.hidden) return;
                const now = Date.now();
                if (code === lastScan.code && now - lastScan.at < 2500) return;
                lastScan = { code, at: now };
                stopCamera();
                if (mode === 'qty' && !linkFor) {
                    // A new scan while keying a quantity starts over with the new product.
                    mode = 'code';
                }
                draw();
                handleCode(code);
            });
            if (!scanWanted || !sheet.hidden) stop();   // switched off / moved on while starting
            else stopScan = stop;
        } catch (e) {
            scanWanted = false;
            message = { kind: 'bad', text: 'เปิดกล้องไม่ได้: ' + (e.message || e.name) };
        } finally {
            scanStarting = false;
            if (!stopScan) $('cam').hidden = true;
            draw();
        }
    }

    function toggleScan() {
        if (!$('cam').hidden) {
            scanWanted = false;
            stopCamera();
            draw();
        } else {
            scanWanted = true;
            startCamera();
        }
    }

    /** Back on the barcode screen with nothing pending: reopen the camera. */
    function resumeScanSoon() {
        if (!scanWanted || stopScan || scanStarting) return;
        setTimeout(() => {
            if (scanWanted && !stopScan && !scanStarting && !busy && sheet.hidden && mode === 'code') startCamera();
        }, 0);
    }

    // ---- photo: saved on the product record (products.image_token) ----
    let photoFor = null;
    function takePhoto(p) {
        photoFor = p;
        $('photo').click();
    }
    $('photo').addEventListener('change', async (e) => {
        const file = e.target.files[0];
        e.target.value = '';
        const p = photoFor;
        photoFor = null;
        if (!file || !p) return;
        message = { kind: 'info', text: `กำลังอัปโหลดรูป ${p.name}…` };
        draw();
        try {
            const dataUrl = await fileToDataUrl(file);
            const res = await api.post(`/products/${p.id}/photo`, { data_url: dataUrl, thumb_data_url: await thumbDataUrl(dataUrl) });
            p.image_token = res.data.image_token;
            if (product && Number(product.id) === Number(p.id)) product.image_token = p.image_token;
            photoCache++;
            beep('upload');
            message = { kind: 'ok', text: `บันทึกรูป ${p.name} แล้ว` };
        } catch (err) {
            beep('error');
            message = { kind: 'bad', text: 'บันทึกรูปไม่สำเร็จ: ' + err.message };
        }
        draw();
        redrawResults?.();
    });

    // ---- input ----
    function press(key) {
        if (busy) return;
        if (mode === 'qty') {
            let v = q[field];
            if (key === 'C') v = '';
            else if (key === '⌫') v = v.slice(0, -1);
            else if (/^\d$/.test(key)) {
                if (v.length >= (field === 'size' ? 4 : 6)) return;
                v = v === '0' ? key : v + key;
            }
            q[field] = v;
            draw();
            return;
        }
        if (key === 'C') input = '';
        else if (key === '⌫') input = input.slice(0, -1);
        else if (/^\d$/.test(key)) {
            if (input.length >= 32) return;
            input += key;
        }
        if (message?.kind !== 'info') message = null;
        draw();
    }

    function close() {
        scanWanted = false;
        stopCamera();
        document.removeEventListener('keydown', onKey);
        if (document.fullscreenElement === root) document.exitFullscreen().catch(() => {});
        document.body.classList.remove('mc-open');
        root.remove();
        window.scrollTo(0, scrollY);
        onClose?.();
    }

    root.addEventListener('click', (e) => {
        const key = e.target.closest('[data-key]');
        if (key) {
            press(key.dataset.key);
            return;
        }
        const pick = e.target.closest('[data-unit], [data-field], [data-psize]');
        if (pick && mode === 'qty') {
            if (pick.dataset.unit) setUnit(pick.dataset.unit);
            else if (pick.dataset.field) field = pick.dataset.field;
            else {
                q.size = pick.dataset.psize;
                field = 'packs';
            }
            draw();
            return;
        }
        const act = e.target.closest('[data-act]')?.dataset.act;
        if (!act) return;
        if (act === 'close') close();
        else if (act === 'search') openSearch(null);
        else if (act === 'sound') {
            const b = root.querySelector('[data-act="sound"]');
            openSoundPanel(b, () => { b.innerHTML = soundButtonLabel(); });
        }
        else if (act === 'padsize') {
            if (padSize) root.classList.remove(`pad-${padSize}`);
            const i = PAD_SIZES.findIndex(([k]) => k === padSize);
            padSize = PAD_SIZES[(i + 1) % PAD_SIZES.length][0];
            if (padSize) root.classList.add(`pad-${padSize}`);
            try { localStorage.setItem(PAD_KEY, padSize); } catch { /* private mode */ }
            message = { kind: 'info', text: `ปุ่มตัวเลขขนาด${PAD_SIZES.find(([k]) => k === padSize)[1]} (กด ⌨ เพื่อเปลี่ยน)` };
            draw();
        }
        else if (act === 'fullscreen') {
            if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
            else root.requestFullscreen({ navigationUI: 'hide' }).catch(() => {});
        }
        else if (act === 'scan') toggleScan();
        else if (act === 'find') handleCode(input);
        else if (act === 'set') saveQty(false);
        else if (act === 'add') saveQty(true);
        else if (act === 'photo') takePhoto(product);
        else if (act === 'link') openSearch(message.linkCode);
        else if (act === 'cancel') {
            mode = 'code';
            input = '';
            resetQty();
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
