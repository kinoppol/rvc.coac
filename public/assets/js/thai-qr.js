// Thai QR Payment with the amount: from a PromptPay number, or from the text of the store's own
// merchant QR-Code. The app has the same rules in domain/PromptPay.kt; the server only checks the target.
import { money, escapeHtml } from './util.js';

const QR_LIB = 'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/+esm';
let qrcode = null;
let loading = null;

/** Loads the QR generator once; false when it cannot be fetched (no internet). */
export function loadThaiQr() {
    loading ||= import(QR_LIB).then((m) => { qrcode = m.default; return true; }).catch(() => { loading = null; return false; });
    return loading;
}

const field = (tag, value) => `${tag}${String(value.length).padStart(2, '0')}${value}`;

function crc16(text) {
    let crc = 0xFFFF;
    for (let i = 0; i < text.length; i++) {
        crc ^= text.charCodeAt(i) << 8;
        for (let b = 0; b < 8; b++) crc = crc & 0x8000 ? ((crc << 1) ^ 0x1021) & 0xFFFF : (crc << 1) & 0xFFFF;
    }
    return crc.toString(16).toUpperCase().padStart(4, '0');
}

function fields(text) {
    const out = [];
    for (let i = 0; i < text.length;) {
        const len = Number(text.substr(i + 2, 2));
        out.push([text.substr(i, 2), text.substr(i + 4, len)]);
        i += 4 + len;
    }
    return out;
}

/** The QR-Code's text. amount > 0 is fixed in it, so the customer's bank app fills it in. */
export function thaiQrPayload(target, amount) {
    const fixed = amount > 0 ? (Math.round(amount * 100) / 100).toFixed(2) : null;
    let rows;
    if (target.startsWith('000201')) {
        // The store's merchant QR: everything stays, only the amount (and "one-time" marker) is added.
        rows = fields(target).filter(([t]) => t !== '54' && t !== '63').map(([t, v]) => [t, t === '01' && fixed ? '12' : v]);
    } else {
        const id = target.replace(/\D/g, '');
        const account = id.length === 10 ? ['01', `0066${id.slice(1)}`] : id.length === 13 ? ['02', id] : ['03', id];
        rows = [['00', '01'], ['01', fixed ? '12' : '11'],
            ['29', field('00', 'A000000677010111') + field(account[0], account[1])], ['53', '764'], ['58', 'TH']];
    }
    if (fixed) {
        const at = rows.findIndex(([t]) => t > '54');
        rows.splice(at < 0 ? rows.length : at, 0, ['54', fixed]);
    }
    const body = `${rows.map(([t, v]) => field(t, v)).join('')}6304`;
    return body + crc16(body);
}

/**
 * The card shown to the customer, like the store's Thai QR sticker.
 * cfg: {target, title, account_name, merchant_code}; call loadThaiQr() first.
 */
export function thaiQrCard(cfg, amount) {
    const svg = qrcode ? (() => {
        const code = qrcode(0, 'M');
        code.addData(thaiQrPayload(cfg.target, amount));
        code.make();
        return code.createSvgTag({ cellSize: 5, margin: 2, scalable: true });
    })() : '<div class="muted" style="font-size:12px">กำลังโหลดตัวสร้าง QR-Code (ต้องต่ออินเทอร์เน็ต)</div>';
    const digits = cfg.target.startsWith('000201') ? null : cfg.target;
    return `<div class="thai-qr">
      <div class="thai-qr-head">THAI QR PAYMENT</div>
      <div class="thai-qr-body">
        <div class="thai-qr-code">${svg}</div>
        <div class="thai-qr-amount">฿${money(amount)}</div>
        ${cfg.title ? `<div class="thai-qr-title">${escapeHtml(cfg.title)}</div>` : ''}
        ${cfg.account_name ? `<div>ชื่อบัญชี : ${escapeHtml(cfg.account_name)}</div>` : ''}
        ${cfg.merchant_code ? `<div class="muted">รหัสร้านค้า : ${escapeHtml(cfg.merchant_code)}</div>` : ''}
        ${digits ? `<div class="muted">พร้อมเพย์ : ${escapeHtml(digits)}</div>` : ''}
      </div>
    </div>`;
}
