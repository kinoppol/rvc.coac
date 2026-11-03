import { api, getCsrfToken } from '../api.js';
import { escapeHtml, fmtDateTime, money } from '../util.js';
import { confirmDialog, alertDialog, promptDialog } from '../dialog.js';

// อุปกรณ์ POS (Android): pairs the Android POS app like a TV with a phone — the app shows a
// 6-character code, the admin types it here and picks the account the device sells as.
// Also lists paired devices (rename, change account, revoke) and the sales a device sent
// that the server could not book (retry, or settle by hand). See PosDeviceService.
// The app's .apk is uploaded here (in 1 MB pieces, past PHP's upload limit) and anyone can
// download it from api/app/pos.apk — a link and QR-Code to open on the phone. See AppReleaseService.

const QR_LIB = 'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/+esm';
const ROLE_LABEL = { admin: 'ผู้ดูแลระบบ', manager: 'ผู้จัดการร้าน', advisor: 'ครูที่ปรึกษา', cashier: 'พนักงานขาย', closer: 'ผู้สรุปบัญชีรายวัน' };

export async function render(container, { params = {} } = {}) {
    let devices = [];
    let sellers = [];
    let failed = [];
    let error = null;
    let busy = false;
    let editing = null; // id of the device row being edited
    let form = { code: (params.code || '').toUpperCase(), name: '', user_id: '' };
    let release = null; // the current .apk: {version, size, sha256, note, uploaded_at, uploaded_by}
    let apk = { file: null, version: '', note: '', busy: false, error: null };
    // The address the app connects to: this page's, without the hash.
    const serverUrl = `${location.origin}${location.pathname.replace(/[^/]*$/, '')}`;
    const apkUrl = `${serverUrl}api/app/pos.apk`;
    const fileSize = (n) => (n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.ceil(n / 1024)} KB`);

    async function load() {
        api.get('/app/pos.json').then((r) => { release = r.data; drawApp(); }).catch(() => {});
        try {
            const res = await api.get('/devices');
            devices = res.data;
            sellers = res.sellers;
            failed = res.failed;
            error = null;
        } catch (e) {
            error = e.message;
        }
        draw();
    }

    const online = (d) => d.last_seen_at && Date.now() - Date.parse(d.last_seen_at.replace(' ', 'T')) < 5 * 60 * 1000;
    const sellerOptions = (selected) => sellers.map((u) => `<option value="${u.id}" ${String(u.id) === String(selected) ? 'selected' : ''}>${escapeHtml(u.full_name)} (${escapeHtml(ROLE_LABEL[u.role] || u.role)})</option>`).join('');

    function deviceRow(d) {
        const revoked = d.status === 'revoked';
        if (editing === d.id) {
            return `<tr data-edit-row="${d.id}">
              <td><input data-f="name" maxlength="100" value="${escapeHtml(d.name)}"></td>
              <td colspan="4"><select data-f="user">${sellerOptions(d.user_id)}</select>
                <div class="muted" style="font-size:12px">รายการที่ขายต่อจากนี้จะบันทึกเป็นของบัญชีที่เลือก (รายการที่ขายไปแล้วไม่เปลี่ยน)</div></td>
              <td style="white-space:nowrap"><button type="button" class="btn sm" data-save="${d.id}">บันทึก</button>
                <button type="button" class="btn secondary sm" data-cancel-edit>ยกเลิก</button></td>
            </tr>`;
        }
        return `<tr class="${revoked ? 'muted' : ''}">
            <td><b>${escapeHtml(d.name)}</b><div class="muted" style="font-size:12px">${escapeHtml([d.model, d.platform, d.app_version && `v${d.app_version}`].filter(Boolean).join(' · '))}</div></td>
            <td>${escapeHtml(d.user_name || '—')}</td>
            <td>${revoked ? '<span class="pill">ยกเลิกแล้ว</span>' : online(d) ? '<span class="pill ok">ออนไลน์</span>' : '<span class="pill warn">ออฟไลน์</span>'}
              <div class="muted" style="font-size:12px">${d.last_seen_at ? `เห็นล่าสุด ${escapeHtml(fmtDateTime(d.last_seen_at))}` : 'ยังไม่เคยเชื่อมต่อ'}</div></td>
            <td>${d.last_sync_at ? escapeHtml(fmtDateTime(d.last_sync_at)) : '—'}
              <div class="muted" style="font-size:12px">ส่งแล้ว ${d.synced_count} รายการ${d.pending_count ? ` · <b style="color:var(--warn)">ค้างในเครื่อง ${d.pending_count}</b>` : ''}${d.failed_count ? ` · <b style="color:var(--danger)">ไม่สำเร็จ ${d.failed_count}</b>` : ''}</div></td>
            <td>${escapeHtml(d.paired_at ? fmtDateTime(d.paired_at) : '')}<div class="muted" style="font-size:12px">${escapeHtml(d.paired_by_name || '')}</div></td>
            <td style="white-space:nowrap">${revoked ? '' : `
              <button type="button" class="btn secondary sm" data-edit="${d.id}">แก้ไข</button>
              <button type="button" class="btn danger sm" data-revoke="${d.id}">ยกเลิกการจับคู่</button>`}</td>
          </tr>`;
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

    function appHtml() {
        const dis = apk.busy ? 'disabled' : '';
        return `
          <h2>แอป Android POS (ไฟล์ติดตั้ง .apk)</h2>
          ${release ? `
            <p style="margin:6px 0 10px">เวอร์ชัน <b>${escapeHtml(release.version)}</b> · ${fileSize(release.size)} · อัปโหลด ${escapeHtml(fmtDateTime(release.uploaded_at))} โดย ${escapeHtml(release.uploaded_by || '-')}
              ${release.note ? `<br><span class="muted" style="font-size:13px">${escapeHtml(release.note)}</span>` : ''}</p>
            <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start">
              <div style="flex:1 1 360px">
                <a class="btn" href="${escapeHtml(apkUrl)}" download>⬇ ดาวน์โหลดแอป (.apk)</a>
                <p class="muted" style="font-size:12.5px;margin:10px 0 0">สแกน QR-Code ด้วยกล้องของเครื่อง Android หรือเปิดลิงก์ <code style="word-break:break-all">${escapeHtml(apkUrl)}</code>
                  แล้วแตะไฟล์ที่ดาวน์โหลดเพื่อติดตั้ง — ครั้งแรกเครื่องจะถามให้ "อนุญาตให้ติดตั้งแอปที่ไม่รู้จัก" สำหรับเบราว์เซอร์ที่ใช้ดาวน์โหลด ให้กดอนุญาต
                  · ติดตั้งเวอร์ชันใหม่ทับได้เลย ข้อมูลและการจับคู่ในเครื่องยังอยู่</p>
                <p class="muted" style="font-size:11.5px;margin:6px 0 0;word-break:break-all">SHA-256 ${escapeHtml(release.sha256)}</p>
              </div>
              <div style="text-align:center;flex:0 0 180px">
                <div id="d-apk-qr" style="width:160px;height:160px;margin:0 auto;background:#fff;border-radius:8px;padding:6px"></div>
                <div class="muted" style="font-size:12px;margin-top:6px">สแกนเพื่อดาวน์โหลดแอป</div>
              </div>
            </div>` : '<p class="muted" style="margin:6px 0 10px">ยังไม่มีไฟล์ติดตั้งแอป — อัปโหลดไฟล์ .apk ด้านล่าง แล้วปุ่มดาวน์โหลดและ QR-Code จะแสดงที่นี่</p>'}
          <details ${release ? '' : 'open'} style="margin-top:14px">
            <summary style="cursor:pointer;font-weight:600">${release ? 'อัปโหลดเวอร์ชันใหม่' : 'อัปโหลดไฟล์ติดตั้ง (.apk)'}</summary>
            <div class="form-grid" style="margin-top:10px">
              <label class="field" style="grid-column:1/-1">ไฟล์ .apk * <input type="file" id="apk-file" accept=".apk,application/vnd.android.package-archive" ${dis}></label>
              <label class="field">เวอร์ชัน * <input id="apk-version" maxlength="30" placeholder="เช่น 1.0.1" value="${escapeHtml(apk.version)}" ${dis}></label>
              <label class="field">หมายเหตุ <input id="apk-note" maxlength="500" placeholder="เช่น แก้การพิมพ์ใบเสร็จ" value="${escapeHtml(apk.note)}" ${dis}></label>
              <div class="actions-row" style="grid-column:1/-1;align-items:center">
                <button type="button" class="btn" id="apk-upload" ${dis}>${apk.busy ? 'กำลังอัปโหลด…' : 'อัปโหลด'}</button>
                <span id="apk-progress" class="muted" style="font-size:13px"></span>
              </div>
            </div>
            ${apk.error ? `<div class="error-box" style="margin-top:10px">${escapeHtml(apk.error)}</div>` : ''}
          </details>`;
    }

    /** Redraws only the app section, so the pairing form keeps what was typed. */
    function drawApp() {
        const box = container.querySelector('#d-app');
        if (!box) return;
        box.innerHTML = appHtml();
        box.querySelector('#apk-file').addEventListener('change', (e) => {
            apk.file = e.target.files[0] || null;
            // app-release-1.2.0.apk → 1.2.0
            const v = apk.file?.name.match(/(\d+\.\d+(?:\.\d+)?)/);
            if (v && !apk.version) box.querySelector('#apk-version').value = apk.version = v[1];
        });
        box.querySelector('#apk-version').addEventListener('input', (e) => { apk.version = e.target.value; });
        box.querySelector('#apk-note').addEventListener('input', (e) => { apk.note = e.target.value; });
        box.querySelector('#apk-upload').addEventListener('click', uploadApk);
        if (release) drawQr('#d-apk-qr', apkUrl);
    }

    async function uploadApk() {
        if (apk.busy) return;
        const file = apk.file;
        if (!file) { apk.error = 'กรุณาเลือกไฟล์ .apk'; return drawApp(); }
        if (!/\.apk$/i.test(file.name)) { apk.error = 'ต้องเป็นไฟล์ .apk'; return drawApp(); }
        if (!apk.version.trim()) { apk.error = 'กรุณาระบุเวอร์ชันของแอป'; return drawApp(); }
        apk.busy = true; apk.error = null; drawApp();
        const progress = (text) => { const el = container.querySelector('#apk-progress'); if (el) el.textContent = text; };
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
            release = (await api.post('/devices/app/finish', { upload_id: id, size: file.size, version: apk.version.trim(), note: apk.note })).data;
            apk = { file: null, version: '', note: '', busy: false, error: null };
            drawApp();
            await alertDialog(`อัปโหลดแอปเวอร์ชัน ${release.version} แล้ว ดาวน์โหลดได้จากปุ่มและ QR-Code ในหน้านี้`, { title: 'อัปโหลดสำเร็จ' });
        } catch (e) {
            apk.busy = false;
            apk.error = e.message;
            drawApp();
        }
    }

    function draw() {
        const appBox = container.querySelector('#d-app'); // kept as is: a chosen file or an upload in progress
        container.innerHTML = `
        <section class="card card-pad" id="d-app"></section>

        <section class="card card-pad">
          <h2>จับคู่อุปกรณ์ POS (Android)</h2>
          <p class="muted" style="font-size:13px;margin:4px 0 12px">เปิดแอป POS บนอุปกรณ์ ใส่ที่อยู่เซิร์ฟเวอร์ (หรือสแกน QR-Code ด้านขวา) แล้วแอปจะแสดงรหัสจับคู่ 6 ตัว กรอกรหัสนั้นที่นี่ภายใน 10 นาที
            แล้วเลือกบัญชีที่อุปกรณ์ใช้ขายสินค้า — ยอดขายจากอุปกรณ์จะบันทึกเป็นของบัญชีนี้ และใช้สิทธิ์ตามบทบาทของบัญชีนี้</p>
          <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start">
            <div class="form-grid" style="flex:1 1 360px">
              <label class="field">รหัสจับคู่ที่แสดงบนอุปกรณ์ *
                <input id="d-code" maxlength="7" autocomplete="off" spellcheck="false" placeholder="เช่น K7M-Q2X" value="${escapeHtml(form.code)}"
                  style="font-size:22px;letter-spacing:4px;text-transform:uppercase;font-family:ui-monospace,monospace"></label>
              <label class="field">ชื่ออุปกรณ์ <input id="d-name" maxlength="100" placeholder="เช่น แท็บเล็ตหน้าร้าน 1 (เว้นว่าง = ชื่อที่แอปส่งมา)" value="${escapeHtml(form.name)}"></label>
              <label class="field" style="grid-column:1/-1">ขายสินค้าในนามบัญชี *
                <select id="d-user"><option value="">— เลือกบัญชีผู้ขาย —</option>${sellerOptions(form.user_id)}</select></label>
              <div class="actions-row" style="grid-column:1/-1"><button type="button" class="btn" id="d-pair" ${busy ? 'disabled' : ''}>${busy ? 'กำลังจับคู่…' : 'จับคู่อุปกรณ์'}</button></div>
            </div>
            <div style="text-align:center;flex:0 0 180px">
              <div id="d-qr" style="width:160px;height:160px;margin:0 auto;background:#fff;border-radius:8px;padding:6px"></div>
              <div class="muted" style="font-size:12px;margin-top:6px">ที่อยู่เซิร์ฟเวอร์สำหรับแอป<br><code style="word-break:break-all">${escapeHtml(serverUrl)}</code></div>
            </div>
          </div>
          ${error ? `<div class="error-box" style="margin-top:12px">${escapeHtml(error)}</div>` : ''}
        </section>

        ${failed.length ? `
        <section class="card card-pad">
          <h2 style="color:var(--danger)">รายการขายจากอุปกรณ์ที่บันทึกไม่สำเร็จ (${failed.length})</h2>
          <p class="muted" style="font-size:13px;margin:4px 0 10px">ขายไปแล้วตอนออฟไลน์ แต่เซิร์ฟเวอร์บันทึกไม่ได้ (เช่น สินค้าถูกรวมหรือลบไปแล้ว) แก้สาเหตุแล้วกด "บันทึกอีกครั้ง" หรือบันทึกด้วยวิธีอื่นแล้วกด "จัดการแล้ว"</p>
          <div class="table-wrap"><table>
            <thead><tr><th>เวลาขาย / อุปกรณ์</th><th>รายการ</th><th>สาเหตุ</th><th></th></tr></thead>
            <tbody>${failed.map(failedRow).join('')}</tbody>
          </table></div>
        </section>` : ''}

        <section class="card card-pad">
          <h2>อุปกรณ์ที่จับคู่แล้ว</h2>
          ${devices.length ? `<div class="table-wrap"><table>
            <thead><tr><th>อุปกรณ์</th><th>ขายในนาม</th><th>สถานะ</th><th>ส่งยอดขายล่าสุด</th><th>จับคู่เมื่อ</th><th></th></tr></thead>
            <tbody>${devices.map(deviceRow).join('')}</tbody>
          </table></div>` : '<div class="empty">ยังไม่มีอุปกรณ์ที่จับคู่</div>'}
        </section>`;

        const code = container.querySelector('#d-code');
        code.addEventListener('input', () => {
            form.code = code.value.toUpperCase();
        });
        container.querySelector('#d-name').addEventListener('input', (e) => { form.name = e.target.value; });
        container.querySelector('#d-user').addEventListener('change', (e) => { form.user_id = e.target.value; });
        container.querySelector('#d-pair').addEventListener('click', pair);
        code.addEventListener('keydown', (e) => { if (e.key === 'Enter') pair(); });
        container.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => { editing = Number(b.dataset.edit); draw(); }));
        container.querySelectorAll('[data-save]').forEach((b) => b.addEventListener('click', () => saveEdit(Number(b.dataset.save))));
        container.querySelector('[data-cancel-edit]')?.addEventListener('click', () => { editing = null; draw(); });
        container.querySelectorAll('[data-revoke]').forEach((b) => b.addEventListener('click', () => revoke(Number(b.dataset.revoke))));
        container.querySelectorAll('[data-detail]').forEach((b) => b.addEventListener('click', () => detail(Number(b.dataset.detail))));
        container.querySelectorAll('[data-retry]').forEach((b) => b.addEventListener('click', () => retry(Number(b.dataset.retry))));
        container.querySelectorAll('[data-resolve]').forEach((b) => b.addEventListener('click', () => resolve(Number(b.dataset.resolve))));
        drawQr('#d-qr', serverUrl);
        if (appBox) container.querySelector('#d-app').replaceWith(appBox);
        else drawApp();
    }

    async function drawQr(selector, text) {
        const box = container.querySelector(selector);
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

    async function pair() {
        if (busy) return;
        if (form.code.replace(/[^A-Z0-9]/g, '').length !== 6) { error = 'กรุณากรอกรหัสจับคู่ 6 ตัวที่แสดงบนอุปกรณ์'; return draw(); }
        if (!form.user_id) { error = 'กรุณาเลือกบัญชีที่อุปกรณ์ใช้ขายสินค้า'; return draw(); }
        busy = true; error = null; draw();
        try {
            const d = (await api.post('/devices/pair', { code: form.code, name: form.name, user_id: Number(form.user_id) })).data;
            form = { code: '', name: '', user_id: '' };
            busy = false;
            await load();
            await alertDialog(`จับคู่ "${d.name}" แล้ว อุปกรณ์จะเข้าสู่หน้าขายสินค้าและเริ่มโหลดข้อมูลสินค้าภายในไม่กี่วินาที`, { title: 'จับคู่สำเร็จ' });
        } catch (e) {
            busy = false;
            error = e.message;
            draw();
        }
    }

    async function saveEdit(id) {
        const row = container.querySelector(`[data-edit-row="${id}"]`);
        try {
            await api.put(`/devices/${id}`, {
                name: row.querySelector('[data-f="name"]').value,
                user_id: Number(row.querySelector('[data-f="user"]').value),
            });
            editing = null;
            await load();
        } catch (e) { alertDialog(e.message, { tone: 'danger' }); }
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
    if (form.code) container.querySelector('#d-user')?.focus();
}
