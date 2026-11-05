import { api, getCsrfToken } from '../api.js';
import { escapeHtml, fmtDateTime, money } from '../util.js';
import * as dlg from '../dialog.js';

// อุปกรณ์ POS (Android): pairs the Android POS app like a TV with a phone — the app shows a
// 6-character code, the admin types it here and picks the sellers who use the device (each
// switches to their profile on it, with a PIN when one is set here) and how often it syncs
// in the background (the app can change that too). Also lists paired devices (rename,
// sellers, PINs, sync level, revoke — with search and filters) and the sales a device sent
// that the server could not book (retry, or settle by hand). See PosDeviceService.
// The app's .apk is uploaded here (in 1 MB pieces, past PHP's upload limit) and anyone can
// download it from api/app/pos.apk — a link and QR-Code to open on the phone. See AppReleaseService.
// The page itself only shows the download / upload / pair buttons; the forms open in modals
// that close with their close buttons only (not a tap outside or Esc), so nothing typed is lost.

const QR_LIB = 'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/+esm';
const ROLE_LABEL = { admin: 'ผู้ดูแลระบบ', manager: 'ผู้จัดการร้าน', advisor: 'ครูที่ปรึกษา', cashier: 'พนักงานขาย', closer: 'ผู้สรุปบัญชีรายวัน' };
const STATUS_FILTERS = [
    ['', 'ทุกสถานะ'],
    ['active', 'ใช้งานอยู่ (ไม่รวมที่ยกเลิก)'],
    ['online', 'ออนไลน์'],
    ['offline', 'ออฟไลน์'],
    ['issue', 'มีรายการค้าง / ไม่สำเร็จ'],
    ['outdated', 'แอปยังไม่ใช่เวอร์ชันล่าสุด'],
    ['revoked', 'ยกเลิกแล้ว'],
];
const SORTS = [['seen', 'เห็นล่าสุด'], ['sync', 'ส่งยอดขายล่าสุด'], ['paired', 'จับคู่ล่าสุด'], ['name', 'ชื่ออุปกรณ์']];

// The dialogs on this page close with their buttons only.
const alertDialog = (m, o = {}) => dlg.alertDialog(m, { ...o, strict: true });
const confirmDialog = (m, o = {}) => dlg.confirmDialog(m, { ...o, strict: true });
const promptDialog = (m, o = {}) => dlg.promptDialog(m, { ...o, strict: true });

/**
 * A modal that closes only with its ✕ / ปิด buttons. body/foot: trusted markup.
 * canClose(): false keeps it open (e.g. while uploading). Returns {el, close}.
 */
function openModal({ title, body, foot = '', width = 640, canClose = () => true, onClose = () => {} }) {
    const el = document.createElement('div');
    el.className = 'modal-backdrop';
    el.innerHTML = `
      <div class="modal" style="width:${Number(width)}px" role="dialog" aria-modal="true" aria-label="${escapeHtml(title)}">
        <div class="modal-head"><h2>${escapeHtml(title)}</h2><button type="button" class="modal-close" data-x aria-label="ปิด" title="ปิด">✕</button></div>
        <div class="modal-body">${body}</div>
        <div class="modal-foot"><button type="button" class="btn secondary" data-x>ปิด</button><div style="display:flex;gap:8px;flex-wrap:wrap;margin-left:auto">${foot}</div></div>
      </div>`;
    (document.fullscreenElement || document.body).appendChild(el);
    const close = (force = false) => {
        if (!el.isConnected || (!force && !canClose())) return;
        el.remove();
        onClose();
    };
    el.querySelectorAll('[data-x]').forEach((b) => b.addEventListener('click', () => close()));
    return { el, close };
}

async function drawQr(box, text) {
    if (!box) return;
    try {
        const { default: qrcode } = await import(QR_LIB);
        const qr = qrcode(0, 'M');
        qr.addData(text);
        qr.make();
        box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 1, scalable: true });
    } catch (e) {
        box.innerHTML = '<div class="muted" style="font-size:12px;color:#555">โหลดตัวสร้าง QR-Code ไม่ได้</div>';
    }
}
const qrBox = (attr) => `<div ${attr} style="width:150px;height:150px;margin:0 auto;background:#fff;border-radius:8px;padding:6px"></div>`;

export async function render(container, { params = {} } = {}) {
    let devices = [];
    let sellers = [];
    let failed = [];
    let levels = []; // background sync levels: {key, label, description}
    let defaultLevel = 'medium';
    let error = null;
    let loaded = false; // the pairing form needs the sellers list
    let form = { code: (params.code || '').toUpperCase(), name: '', user_ids: [], sync_level: null };
    let release = null; // the current .apk: {version, size, sha256, note, uploaded_at, uploaded_by}
    let filt = { q: '', status: '', seller: '', version: '', sort: 'seen' };
    // The address the app connects to: this page's, without the hash.
    const serverUrl = `${location.origin}${location.pathname.replace(/[^/]*$/, '')}`;
    const apkUrl = `${serverUrl}api/app/pos.apk`;
    const fileSize = (n) => (n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.ceil(n / 1024)} KB`);

    async function load() {
        api.get('/app/pos.json').then((r) => { release = r.data; drawApp(); drawList(); }).catch(() => {});
        try {
            const res = await api.get('/devices');
            devices = res.data;
            sellers = res.sellers;
            failed = res.failed;
            levels = res.sync_levels || [];
            defaultLevel = res.sync_default || 'medium';
            loaded = true;
            error = null;
        } catch (e) {
            error = e.message;
        }
        draw();
    }

    const online = (d) => d.last_seen_at && Date.now() - Date.parse(d.last_seen_at.replace(' ', 'T')) < (d.online_seconds || 300) * 1000;
    const ts = (s) => (s ? Date.parse(s.replace(' ', 'T')) || 0 : 0);
    const roleLabel = (r) => ROLE_LABEL[r] || r;
    /** Checkboxes of the accounts that may sell; $keep: sellers already on the device (listed even if they no longer could be added). */
    function sellerChecks(checked, keep = []) {
        const list = [...sellers];
        keep.forEach((u) => { if (!list.some((x) => x.id === u.id)) list.push(u); });
        return `<div class="seller-checks" style="display:flex;flex-wrap:wrap;gap:6px 18px">${list.map((u) => `
            <label style="display:flex;gap:6px;align-items:center;font-weight:400;cursor:pointer">
              <input type="checkbox" data-seller value="${u.id}" ${checked.includes(u.id) ? 'checked' : ''}>
              ${escapeHtml(u.full_name)} <span class="muted" style="font-size:12px">(${escapeHtml(roleLabel(u.role))})</span></label>`).join('')}</div>`;
    }
    const levelOptions = (selected) => levels.map((l) => `<option value="${l.key}" ${l.key === selected ? 'selected' : ''}>${escapeHtml(l.label)}${l.key === defaultLevel ? ' (ค่าเริ่มต้น)' : ''} — ${escapeHtml(l.description)}</option>`).join('');
    const checkedIn = (root) => [...root.querySelectorAll('[data-seller]:checked')].map((c) => Number(c.value));
    const options = (list, selected) => list.map(([v, l]) => `<option value="${escapeHtml(v)}" ${v === selected ? 'selected' : ''}>${escapeHtml(l)}</option>`).join('');

    // ---- device list: search, filters, sort ----

    function matches(d) {
        const revoked = d.status === 'revoked';
        switch (filt.status) {
            case 'active': if (revoked) return false; break;
            case 'online': if (revoked || !online(d)) return false; break;
            case 'offline': if (revoked || online(d)) return false; break;
            case 'revoked': if (!revoked) return false; break;
            case 'issue': if (!(d.pending_count || d.failed_count)) return false; break;
            case 'outdated': if (revoked || !release || d.app_version === release.version) return false; break;
            default:
        }
        if (filt.seller && !d.users.some((u) => String(u.id) === filt.seller)) return false;
        if (filt.version && (d.app_version || '') !== filt.version) return false;
        const q = filt.q.trim().toLowerCase();
        if (q) {
            const hay = [d.name, d.model, d.platform, d.app_version && `v${d.app_version}`, d.paired_by_name, d.sync?.label,
                ...d.users.map((u) => u.full_name)].filter(Boolean).join(' ').toLowerCase();
            if (!q.split(/\s+/).every((w) => hay.includes(w))) return false;
        }
        return true;
    }

    function sorted(list) {
        const by = {
            seen: (a, b) => ts(b.last_seen_at) - ts(a.last_seen_at),
            sync: (a, b) => ts(b.last_sync_at) - ts(a.last_sync_at),
            paired: (a, b) => ts(b.paired_at) - ts(a.paired_at),
            name: (a, b) => a.name.localeCompare(b.name, 'th'),
        }[filt.sort] || (() => 0);
        // Revoked devices go last unless they are what is being looked for.
        return [...list].sort((a, b) => (a.status === 'revoked') - (b.status === 'revoked') || by(a, b));
    }

    function deviceRow(d) {
        const revoked = d.status === 'revoked';
        const outdated = !revoked && release && d.app_version && d.app_version !== release.version;
        return `<tr class="${revoked ? 'muted' : ''}">
            <td><b>${escapeHtml(d.name)}</b><div class="muted" style="font-size:12px">${escapeHtml([d.model, d.platform].filter(Boolean).join(' · '))}${d.app_version ? ` · <span ${outdated ? 'style="color:var(--warn);font-weight:600" title="ยังไม่ใช่เวอร์ชันล่าสุด"' : ''}>v${escapeHtml(d.app_version)}</span>` : ''}</div></td>
            <td>${d.users.length ? d.users.map((u) => `<div>${escapeHtml(u.full_name)}${u.has_pin ? ' <span title="ตั้ง PIN แล้ว">🔒</span>' : ''}${u.id === d.current_user_id && !revoked ? ' <span class="pill ok" style="font-size:11px">ใช้งานล่าสุด</span>' : ''}</div>`).join('') : '—'}</td>
            <td>${revoked ? '<span class="pill">ยกเลิกแล้ว</span>' : online(d) ? '<span class="pill ok">ออนไลน์</span>' : '<span class="pill warn">ออฟไลน์</span>'}
              <div class="muted" style="font-size:12px">${d.last_seen_at ? `เห็นล่าสุด ${escapeHtml(fmtDateTime(d.last_seen_at))}` : 'ยังไม่เคยเชื่อมต่อ'}</div></td>
            <td>${d.last_sync_at ? escapeHtml(fmtDateTime(d.last_sync_at)) : '—'}
              <div class="muted" style="font-size:12px">ส่งแล้ว ${d.synced_count} รายการ${d.pending_count ? ` · <b style="color:var(--warn)">ค้างในเครื่อง ${d.pending_count}</b>` : ''}${d.failed_count ? ` · <b style="color:var(--danger)">ไม่สำเร็จ ${d.failed_count}</b>` : ''}</div>
              <div class="muted" style="font-size:12px">ความถี่: <b>${escapeHtml(d.sync.label)}</b>${d.sync.changed_by === 'app' ? ' (ตั้งจากแอป)' : ''}</div></td>
            <td>${escapeHtml(d.paired_at ? fmtDateTime(d.paired_at) : '')}<div class="muted" style="font-size:12px">${escapeHtml(d.paired_by_name || '')}</div></td>
            <td style="white-space:nowrap">${revoked ? '' : `
              <button type="button" class="btn secondary sm" data-edit="${d.id}">แก้ไข</button>
              <button type="button" class="btn danger sm" data-revoke="${d.id}">ยกเลิกการจับคู่</button>`}</td>
          </tr>`;
    }

    function listToolbar() {
        const sellerOpts = [];
        devices.forEach((d) => d.users.forEach((u) => { if (!sellerOpts.some(([id]) => id === String(u.id))) sellerOpts.push([String(u.id), u.full_name]); }));
        sellerOpts.sort((a, b) => a[1].localeCompare(b[1], 'th'));
        const versions = [...new Set(devices.map((d) => d.app_version).filter(Boolean))]
            .sort((a, b) => b.localeCompare(a, undefined, { numeric: true }))
            .map((v) => [v, `v${v}${release && v === release.version ? ' (ล่าสุด)' : ''}`]);
        return `
          <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:4px 0 12px">
            <input type="search" class="search-input" data-filt="q" placeholder="🔍 ค้นหาชื่ออุปกรณ์ รุ่น พนักงานขาย เวอร์ชัน…" value="${escapeHtml(filt.q)}" style="flex:1 1 260px;min-height:40px;padding:8px 12px">
            <select data-filt="status" aria-label="สถานะ">${options(STATUS_FILTERS, filt.status)}</select>
            <select data-filt="seller" aria-label="พนักงานขาย">${options([['', 'พนักงานขายทุกคน'], ...sellerOpts], filt.seller)}</select>
            <select data-filt="version" aria-label="เวอร์ชันแอป">${options([['', 'ทุกเวอร์ชัน'], ...versions], filt.version)}</select>
            <select data-filt="sort" aria-label="เรียงตาม">${options(SORTS.map(([v, l]) => [v, `เรียง: ${l}`]), filt.sort)}</select>
            <button type="button" class="btn secondary sm" data-filt-reset>ล้างตัวกรอง</button>
          </div>
          <div class="muted" style="font-size:12.5px;margin-bottom:6px" data-list-count></div>`;
    }

    /** Redraws only the rows, so the search box keeps focus while typing. */
    function drawList() {
        const body = container.querySelector('#d-list');
        if (!body) return;
        const shown = sorted(devices.filter(matches));
        body.innerHTML = shown.length ? shown.map(deviceRow).join('')
            : `<tr><td colspan="6"><div class="empty">${devices.length ? 'ไม่พบอุปกรณ์ที่ตรงกับตัวกรอง' : 'ยังไม่มีอุปกรณ์ที่จับคู่'}</div></td></tr>`;
        const active = devices.filter((d) => d.status !== 'revoked');
        const on = active.filter(online).length;
        const filtered = filt.q.trim() || filt.status || filt.seller || filt.version;
        container.querySelector('[data-list-count]').innerHTML = `แสดง <b>${shown.length}</b> จาก ${devices.length} เครื่อง`
            + ` · ใช้งานอยู่ ${active.length} (ออนไลน์ ${on} · ออฟไลน์ ${active.length - on})`;
        container.querySelector('[data-filt-reset]').hidden = !filtered;
    }

    function failedRow(s) {
        const p = s.payload || {};
        const items = (p.items || []).length;
        return `<tr>
            <td>${escapeHtml(fmtDateTime(s.sold_at))}<div class="muted" style="font-size:12px">${escapeHtml(s.device_name)} · ${escapeHtml(s.user_name || '')}</div></td>
            <td>${items} รายการ${p.total_value != null ? ` · ฿${money(p.total_value)}` : ''}</td>
            <td style="color:var(--danger)">${escapeHtml(s.error || '')}<div class="muted" style="font-size:12px">ลอง ${s.attempts} ครั้ง</div></td>
            <td style="white-space:nowrap">
              <button type="button" class="btn secondary sm" data-detail="${s.id}">ดูรายการ</button>
              <button type="button" class="btn sm" data-retry="${s.id}">บันทึกอีกครั้ง</button>
              <button type="button" class="btn secondary sm" data-resolve="${s.id}">จัดการแล้ว</button>
            </td>
          </tr>`;
    }

    // ---- top bar: download / upload / pair ----

    function drawApp() {
        const box = container.querySelector('#d-app');
        if (!box) return;
        box.innerHTML = `
          <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between">
            <div style="min-width:0">
              <h2 style="margin:0">แอป Android POS</h2>
              <div class="muted" style="font-size:13px;margin-top:2px">${release
                ? `เวอร์ชันล่าสุด <b>${escapeHtml(release.version)}</b> · ${fileSize(release.size)} · อัปโหลด ${escapeHtml(fmtDateTime(release.uploaded_at))} โดย ${escapeHtml(release.uploaded_by || '-')}`
                : 'ยังไม่มีไฟล์ติดตั้งแอป — กด "อัปโหลดแอปเวอร์ชันใหม่"'}</div>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:8px">
              ${release ? `<a class="btn secondary" href="${escapeHtml(apkUrl)}" download>⬇ ดาวน์โหลดแอป (.apk)</a>` : ''}
              <button type="button" class="btn secondary" data-open-upload>⬆ อัปโหลดแอปเวอร์ชันใหม่</button>
              <button type="button" class="btn" data-open-pair ${loaded ? '' : 'disabled'}>＋ จับคู่อุปกรณ์</button>
            </div>
          </div>`;
        box.querySelector('[data-open-upload]').addEventListener('click', openUpload);
        box.querySelector('[data-open-pair]').addEventListener('click', openPair);
    }

    function draw() {
        container.innerHTML = `
        <section class="card card-pad" id="d-app"></section>
        ${error ? `<div class="error-box">${escapeHtml(error)}</div>` : ''}

        ${failed.length ? `
        <section class="card card-pad" id="d-failed">
          <h2 style="color:var(--danger)">รายการขายจากอุปกรณ์ที่บันทึกไม่สำเร็จ (${failed.length})</h2>
          <p class="muted" style="font-size:13px;margin:4px 0 10px">ขายไปแล้วตอนออฟไลน์ แต่เซิร์ฟเวอร์บันทึกไม่ได้ (เช่น สินค้าถูกรวมหรือลบไปแล้ว) แก้สาเหตุแล้วกด "บันทึกอีกครั้ง" หรือบันทึกด้วยวิธีอื่นแล้วกด "จัดการแล้ว"</p>
          <div class="table-wrap"><table>
            <thead><tr><th>เวลาขาย / อุปกรณ์</th><th>รายการ</th><th>สาเหตุ</th><th></th></tr></thead>
            <tbody>${failed.map(failedRow).join('')}</tbody>
          </table></div>
        </section>` : ''}

        <section class="card card-pad" id="d-devices">
          <h2>อุปกรณ์ที่จับคู่แล้ว</h2>
          ${listToolbar()}
          <div class="table-wrap"><table>
            <thead><tr><th>อุปกรณ์</th><th>พนักงานขาย</th><th>สถานะ</th><th>ส่งยอดขายล่าสุด</th><th>จับคู่เมื่อ</th><th></th></tr></thead>
            <tbody id="d-list"></tbody>
          </table></div>
        </section>`;

        drawApp();
        drawList();

        const list = container.querySelector('#d-devices');
        list.querySelectorAll('[data-filt]').forEach((el) => el.addEventListener(el.tagName === 'INPUT' ? 'input' : 'change', () => {
            filt[el.dataset.filt] = el.value;
            drawList();
        }));
        list.querySelector('[data-filt-reset]').addEventListener('click', () => {
            filt = { ...filt, q: '', status: '', seller: '', version: '' };
            list.querySelectorAll('[data-filt]').forEach((el) => { if (el.dataset.filt !== 'sort') el.value = ''; });
            drawList();
        });
        list.addEventListener('click', (e) => {
            const b = e.target.closest('button');
            if (b?.dataset.edit) openEdit(Number(b.dataset.edit));
            else if (b?.dataset.revoke) revoke(Number(b.dataset.revoke));
        });
        container.querySelector('#d-failed')?.addEventListener('click', (e) => {
            const b = e.target.closest('button');
            if (b?.dataset.detail) detail(Number(b.dataset.detail));
            else if (b?.dataset.retry) retry(Number(b.dataset.retry));
            else if (b?.dataset.resolve) resolve(Number(b.dataset.resolve));
        });
    }

    // ---- upload modal ----

    function openUpload() {
        const state = { file: null, busy: false };
        const m = openModal({
            title: 'อัปโหลดแอปเวอร์ชันใหม่ (.apk)',
            width: 560,
            canClose: () => !state.busy,
            body: `
              ${release ? `<p class="muted" style="font-size:13px;margin:0 0 12px">เวอร์ชันปัจจุบัน <b>${escapeHtml(release.version)}</b>${release.note ? ` — ${escapeHtml(release.note)}` : ''}<br>
                <span style="font-size:11.5px;word-break:break-all">SHA-256 ${escapeHtml(release.sha256)}</span></p>` : ''}
              <div class="form-grid">
                <label class="field" style="grid-column:1/-1">ไฟล์ .apk * <input type="file" data-u="file" accept=".apk,application/vnd.android.package-archive"></label>
                <label class="field">เวอร์ชัน * <input data-u="version" maxlength="30" placeholder="เช่น 1.0.1"></label>
                <label class="field">หมายเหตุ <input data-u="note" maxlength="500" placeholder="เช่น แก้การพิมพ์ใบเสร็จ"></label>
              </div>
              <p class="muted" style="font-size:12.5px;margin:10px 0 0">อุปกรณ์ติดตั้งเวอร์ชันใหม่ทับได้เลย ข้อมูลและการจับคู่ในเครื่องยังอยู่</p>
              <div data-u="progress" class="muted" style="font-size:13px;margin-top:10px"></div>
              <div data-u="error" class="error-box" style="margin-top:10px" hidden></div>`,
            foot: '<button type="button" class="btn" data-u="go">อัปโหลด</button>',
        });
        const $ = (k) => m.el.querySelector(`[data-u="${k}"]`);
        const fail = (msg) => { $('error').textContent = msg; $('error').hidden = false; };
        const setBusy = (b) => {
            state.busy = b;
            ['file', 'version', 'note', 'go'].forEach((k) => { $(k).disabled = b; });
            m.el.querySelectorAll('[data-x]').forEach((x) => { x.disabled = b; });
            $('go').textContent = b ? 'กำลังอัปโหลด…' : 'อัปโหลด';
        };
        $('file').addEventListener('change', (e) => {
            state.file = e.target.files[0] || null;
            // app-release-1.2.0.apk → 1.2.0
            const v = state.file?.name.match(/(\d+\.\d+(?:\.\d+)?)/);
            if (v && !$('version').value.trim()) $('version').value = v[1];
        });
        m.el.addEventListener('input', () => { $('error').hidden = true; });
        $('go').addEventListener('click', async () => {
            if (state.busy) return;
            const file = state.file;
            const version = $('version').value.trim();
            if (!file) return fail('กรุณาเลือกไฟล์ .apk');
            if (!/\.apk$/i.test(file.name)) return fail('ต้องเป็นไฟล์ .apk');
            if (!version) return fail('กรุณาระบุเวอร์ชันของแอป');
            $('error').hidden = true;
            setBusy(true);
            const progress = (text) => { $('progress').textContent = text; };
            const id = [...crypto.getRandomValues(new Uint8Array(16))].map((b) => b.toString(16).padStart(2, '0')).join('');
            const CHUNK = 1048576; // AppReleaseService::CHUNK
            try {
                for (let i = 0, off = 0; off < file.size; i++, off += CHUNK) {
                    const res = await fetch(`./api/devices/app/chunk?id=${id}&index=${i}`, {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/octet-stream', 'X-CSRF-Token': getCsrfToken() || '' },
                        body: file.slice(off, off + CHUNK),
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) throw new Error(data.error || `อัปโหลดไม่สำเร็จ (${res.status})`);
                    const done = Math.min(file.size, off + CHUNK);
                    progress(`${Math.round((done / file.size) * 100)}% · ${fileSize(done)} / ${fileSize(file.size)}`);
                }
                progress('กำลังตรวจไฟล์…');
                release = (await api.post('/devices/app/finish', { upload_id: id, size: file.size, version, note: $('note').value })).data;
                setBusy(false);
                m.close(true);
                drawApp();
                drawList();
                await alertDialog(`อัปโหลดแอปเวอร์ชัน ${release.version} แล้ว ดาวน์โหลดได้จากปุ่ม "ดาวน์โหลดแอป" หรือ QR-Code ในหน้าจับคู่อุปกรณ์`, { title: 'อัปโหลดสำเร็จ' });
            } catch (e) {
                setBusy(false);
                progress('');
                fail(e.message);
            }
        });
    }

    // ---- pair modal ----

    function openPair() {
        let busy = false;
        const m = openModal({
            title: 'จับคู่อุปกรณ์ POS (Android)',
            width: 760,
            canClose: () => !busy,
            body: `
              <p class="muted" style="font-size:13px;margin:0 0 12px">1) ติดตั้งแอปบนอุปกรณ์ (สแกน QR-Code ซ้ายด้วยกล้องของเครื่อง — ครั้งแรกเครื่องจะถามให้ "อนุญาตให้ติดตั้งแอปที่ไม่รู้จัก" ให้กดอนุญาต)
                2) เปิดแอปแล้วใส่ที่อยู่เซิร์ฟเวอร์ (หรือสแกน QR-Code ขวา) 3) แอปจะแสดงรหัสจับคู่ 6 ตัว กรอกที่นี่ภายใน 10 นาที แล้วเลือกพนักงานขายที่ใช้อุปกรณ์นี้ (ได้หลายคน)
                — แต่ละคนสลับโปรไฟล์ของตนบนอุปกรณ์เพื่อขาย ยอดขายบันทึกเป็นของผู้ที่ขายจริง (ตั้ง PIN ได้ที่ปุ่ม "แก้ไข" หลังจับคู่)</p>
              <div style="display:flex;gap:16px;flex-wrap:wrap;justify-content:center;margin-bottom:14px">
                <div style="text-align:center;flex:0 0 170px">${release ? qrBox('data-p="apk-qr"') : '<div class="muted" style="width:150px;height:150px;margin:0 auto;display:grid;place-items:center;font-size:12px;border:1px dashed var(--border);border-radius:8px">ยังไม่มีไฟล์แอป</div>'}
                  <div class="muted" style="font-size:12px;margin-top:6px">① ดาวน์โหลดแอป${release ? ` v${escapeHtml(release.version)}` : ''}</div></div>
                <div style="text-align:center;flex:0 0 170px">${qrBox('data-p="srv-qr"')}
                  <div class="muted" style="font-size:12px;margin-top:6px">② ที่อยู่เซิร์ฟเวอร์<br><code style="word-break:break-all">${escapeHtml(serverUrl)}</code></div></div>
              </div>
              <div class="form-grid">
                <label class="field">รหัสจับคู่ที่แสดงบนอุปกรณ์ *
                  <input data-p="code" maxlength="7" autocomplete="off" spellcheck="false" placeholder="เช่น K7M-Q2X" value="${escapeHtml(form.code)}"
                    style="font-size:22px;letter-spacing:4px;text-transform:uppercase;font-family:ui-monospace,monospace"></label>
                <label class="field">ชื่ออุปกรณ์ <input data-p="name" maxlength="100" placeholder="เช่น แท็บเล็ตหน้าร้าน 1 (เว้นว่าง = ชื่อที่แอปส่งมา)" value="${escapeHtml(form.name)}"></label>
                <div class="field" style="grid-column:1/-1" data-p="users">พนักงานขายที่ใช้อุปกรณ์นี้ * <span class="muted" style="font-size:12px">(คนแรกที่เลือกเป็นโปรไฟล์เริ่มต้น)</span>
                  ${sellerChecks(form.user_ids)}</div>
                <label class="field" style="grid-column:1/-1">ความถี่ในการส่งข้อมูลเบื้องหลัง
                  <select data-p="sync">${levelOptions(form.sync_level || defaultLevel)}</select>
                  <span class="muted" style="font-size:12px">ส่งน้อยลงช่วยให้เครื่องทำงานลื่นขึ้นและประหยัดเน็ต แต่ยอดขายจะเข้าระบบช้าลง — เปลี่ยนภายหลังได้ทั้งที่หน้านี้และในแอป</span></label>
              </div>
              <div data-p="error" class="error-box" style="margin-top:10px" hidden></div>`,
            foot: '<button type="button" class="btn" data-p="go">จับคู่อุปกรณ์</button>',
        });
        const $ = (k) => m.el.querySelector(`[data-p="${k}"]`);
        const fail = (msg) => { $('error').textContent = msg; $('error').hidden = false; };
        if (release) drawQr($('apk-qr'), apkUrl);
        drawQr($('srv-qr'), serverUrl);
        $('code').addEventListener('input', (e) => { form.code = e.target.value.toUpperCase(); });
        $('name').addEventListener('input', (e) => { form.name = e.target.value; });
        $('sync').addEventListener('change', (e) => { form.sync_level = e.target.value; });
        $('users').querySelectorAll('[data-seller]').forEach((c) => c.addEventListener('change', () => {
            const id = Number(c.value);
            form.user_ids = c.checked ? [...form.user_ids.filter((x) => x !== id), id] : form.user_ids.filter((x) => x !== id);
        }));
        m.el.addEventListener('input', () => { $('error').hidden = true; });
        m.el.addEventListener('change', () => { $('error').hidden = true; });

        async function pair() {
            if (busy) return;
            if (form.code.replace(/[^A-Z0-9]/g, '').length !== 6) { fail('กรุณากรอกรหัสจับคู่ 6 ตัวที่แสดงบนอุปกรณ์'); return $('code').focus(); }
            if (!form.user_ids.length) return fail('กรุณาเลือกพนักงานขายที่ใช้อุปกรณ์นี้อย่างน้อย 1 คน');
            busy = true;
            $('go').disabled = true;
            $('go').textContent = 'กำลังจับคู่…';
            m.el.querySelectorAll('[data-x]').forEach((x) => { x.disabled = true; });
            try {
                const d = (await api.post('/devices/pair', { code: form.code, name: form.name, user_ids: form.user_ids, sync_level: form.sync_level || defaultLevel })).data;
                form = { code: '', name: '', user_ids: [], sync_level: null };
                busy = false;
                m.close(true);
                await load();
                await alertDialog(`จับคู่ "${d.name}" แล้ว อุปกรณ์จะเข้าสู่หน้าขายสินค้าและเริ่มโหลดข้อมูลสินค้าภายในไม่กี่วินาที`, { title: 'จับคู่สำเร็จ' });
            } catch (e) {
                busy = false;
                $('go').disabled = false;
                $('go').textContent = 'จับคู่อุปกรณ์';
                m.el.querySelectorAll('[data-x]').forEach((x) => { x.disabled = false; });
                fail(e.message);
            }
        }
        $('go').addEventListener('click', pair);
        $('code').addEventListener('keydown', (e) => { if (e.key === 'Enter') pair(); });
        if (form.code) $('users').querySelector('[data-seller]')?.focus();
        else $('code').focus();
    }

    // ---- edit modal ----

    function openEdit(id) {
        const d = devices.find((x) => x.id === id);
        if (!d) return;
        const pinButtons = (dev) => dev.users.map((u) => `
            <button type="button" class="btn secondary sm" data-pin="${u.id}" style="margin:2px">${u.has_pin ? '🔒' : '🔓'} ${escapeHtml(u.full_name)}</button>`).join('');
        const m = openModal({
            title: `แก้ไขอุปกรณ์ "${d.name}"`,
            width: 640,
            body: `
              <p class="muted" style="font-size:12.5px;margin:0 0 12px">${escapeHtml([d.model, d.platform, d.app_version && `v${d.app_version}`].filter(Boolean).join(' · '))}</p>
              <div class="form-grid">
                <label class="field" style="grid-column:1/-1">ชื่ออุปกรณ์ <input data-e="name" maxlength="100" value="${escapeHtml(d.name)}"></label>
                <div class="field" style="grid-column:1/-1">พนักงานขายที่ใช้อุปกรณ์นี้ *
                  ${sellerChecks(d.users.map((u) => u.id), d.users)}
                  <span class="muted" style="font-size:12px">ผู้ที่เอาออกจะสลับมาใช้โปรไฟล์ของตนบนอุปกรณ์นี้ไม่ได้อีก (ยอดที่ขายตอนออฟไลน์ไว้ก่อนหน้ายังส่งเข้าระบบได้)</span></div>
                ${d.users.length ? `<div class="field" style="grid-column:1/-1">PIN สำหรับสลับโปรไฟล์ <span class="muted" style="font-size:12px">(กดชื่อเพื่อตั้ง / เปลี่ยน / ยกเลิก — มีผลทันที)</span>
                  <div data-e="pins">${pinButtons(d)}</div></div>` : ''}
                <label class="field" style="grid-column:1/-1">ความถี่ในการส่งข้อมูลเบื้องหลัง <select data-e="sync">${levelOptions(d.sync.level)}</select></label>
              </div>`,
            foot: '<button type="button" class="btn" data-e="save">บันทึก</button>',
        });
        const $ = (k) => m.el.querySelector(`[data-e="${k}"]`);
        $('pins')?.addEventListener('click', async (e) => {
            const b = e.target.closest('[data-pin]');
            if (!b) return;
            if (await setPin(id, Number(b.dataset.pin))) {
                const fresh = devices.find((x) => x.id === id);
                if (fresh && $('pins')) $('pins').innerHTML = pinButtons(fresh);
            }
        });
        $('save').addEventListener('click', async () => {
            const userIds = checkedIn(m.el);
            if (!userIds.length) return alertDialog('กรุณาเลือกพนักงานขายอย่างน้อย 1 คน', { tone: 'danger' });
            $('save').disabled = true;
            try {
                await api.put(`/devices/${id}`, { name: $('name').value, user_ids: userIds, sync_level: $('sync').value });
                m.close(true);
                await load();
            } catch (e) {
                $('save').disabled = false;
                alertDialog(e.message, { tone: 'danger' });
            }
        });
    }

    /** Resolves true when the PIN changed. */
    async function setPin(deviceId, userId) {
        const u = devices.find((x) => x.id === deviceId)?.users.find((x) => x.id === userId);
        if (!u) return false;
        const pin = await promptDialog(`PIN ตัวเลข 4–6 หลักที่ ${u.full_name} ต้องกรอกเมื่อสลับมาใช้โปรไฟล์ของตนบนอุปกรณ์นี้${u.has_pin ? ' — เว้นว่างแล้วกดตกลงเพื่อยกเลิก PIN' : ''}`,
            { title: u.has_pin ? 'เปลี่ยน PIN' : 'ตั้ง PIN', placeholder: 'เช่น 4821' });
        if (pin === null || (pin.trim() === '' && !u.has_pin)) return false;
        try {
            await api.put(`/devices/${deviceId}/users/${userId}/pin`, { pin: pin.trim() });
            await load();
            await alertDialog(pin.trim() ? `ตั้ง PIN ของ ${u.full_name} แล้ว อุปกรณ์จะได้รับเมื่อเชื่อมต่อครั้งถัดไป` : `ยกเลิก PIN ของ ${u.full_name} แล้ว`, { title: 'บันทึกแล้ว' });
            return true;
        } catch (e) { alertDialog(e.message, { tone: 'danger' }); return false; }
    }

    async function revoke(id) {
        const d = devices.find((x) => x.id === id);
        const warn = d.pending_count ? `<p style="color:var(--danger)"><b>อุปกรณ์นี้ยังมีรายการขายที่ยังไม่ได้ส่ง ${d.pending_count} รายการ</b> — ถ้ายกเลิกตอนนี้ รายการเหล่านั้นจะส่งเข้าระบบไม่ได้ ควรให้อุปกรณ์ต่ออินเทอร์เน็ตและส่งยอดก่อน</p>` : '';
        if (!(await confirmDialog(`ยกเลิกการจับคู่ "${d.name}"? อุปกรณ์จะใช้ขายสินค้าและส่งข้อมูลเข้าระบบไม่ได้ทันที (รายการที่ส่งมาแล้วยังอยู่ครบ) ถ้าจะใช้อีกต้องจับคู่ใหม่`, { title: 'ยกเลิกการจับคู่', okText: 'ยกเลิกการจับคู่', danger: true, html: warn }))) return;
        try {
            await api.post(`/devices/${id}/revoke`);
            await load();
        } catch (e) { alertDialog(e.message, { tone: 'danger' }); }
    }

    function detail(id) {
        const s = failed.find((x) => x.id === id);
        const p = s.payload || {};
        const html = `<div class="table-wrap" style="margin-top:8px"><table>
            <thead><tr><th>รหัสสินค้า</th><th>จำนวน (ชิ้น)</th><th>แพ็ค</th><th>ราคา/ชิ้น</th></tr></thead>
            <tbody>${(p.items || []).map((i) => `<tr><td>${escapeHtml(String(i.product_id))}${i.name ? ` ${escapeHtml(i.name)}` : ''}</td><td>${escapeHtml(String(i.qty))}</td><td>${i.pack_size > 1 ? escapeHtml(String(i.pack_size)) : ''}</td><td>${i.unit_price != null ? money(i.unit_price) : ''}</td></tr>`).join('')}</tbody>
          </table></div>
          <p style="margin-top:8px">ชำระ: ${(p.payments || []).map((x) => `${escapeHtml(x.method)} ฿${money(x.amount)}`).join(', ') || '—'}</p>
          <p class="muted" style="font-size:12px">รหัสรายการบนอุปกรณ์ ${escapeHtml(s.client_uuid)} · ได้รับเมื่อ ${escapeHtml(fmtDateTime(s.received_at))}</p>`;
        confirmDialog(`ขายเมื่อ ${fmtDateTime(s.sold_at)} จาก ${s.device_name}`, { title: 'รายละเอียดรายการขาย', okText: 'ปิด', cancelText: '', html, width: 560 });
    }

    async function retry(id) {
        try {
            const r = (await api.post(`/device-sales/${id}/retry`)).data;
            await load();
            await alertDialog(`บันทึกแล้ว เลขที่ ${r.sale?.doc_no || ''}${r.warnings?.length ? `\n\nหมายเหตุ: ${r.warnings.join(' · ')}` : ''}`, { title: 'บันทึกสำเร็จ' });
        } catch (e) { alertDialog(e.message, { tone: 'danger' }); }
    }

    async function resolve(id) {
        const note = await promptDialog('รายการนี้จะไม่ถูกบันทึกเป็นยอดขายจากอุปกรณ์อีก ระบุว่าจัดการอย่างไร (เช่น บันทึกการขายด้วยมือแล้ว เลขที่ ...)', { title: 'จัดการรายการแล้ว', required: 'กรุณาระบุเหตุผล', multiline: true });
        if (note === null) return;
        try {
            await api.post(`/device-sales/${id}/resolve`, { note });
            await load();
        } catch (e) { alertDialog(e.message, { tone: 'danger' }); }
    }

    draw();
    await load();
    // A link with ?code=… (e.g. from the app's QR) opens the pairing form with the code filled in.
    if (form.code) openPair();
}
