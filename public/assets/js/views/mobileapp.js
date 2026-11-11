import { api, getCsrfToken } from '../api.js';
import { escapeHtml, fmtDateTime } from '../util.js';
import { alertDialog, confirmDialog } from '../dialog.js';

// แอปบนมือถือ: the Android app for everyone who is not selling (MobileController). Every signed-in
// user pairs their own phone or tablet here: the app shows a 6-character code (and a QR-Code that
// opens this page with the code filled in), the user confirms, and the device works as them — there
// is no choosing of users. Lists their paired devices to unpair. The app file is downloaded from
// api/app/mobile.apk; whoever manages POS devices uploads new versions here.

const QR_LIB = 'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/+esm';
const fileSize = (n) => (n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.ceil(n / 1024)} KB`);

async function drawQr(box, text) {
    if (!box) return;
    try {
        const { default: qrcode } = await import(QR_LIB);
        const qr = qrcode(0, 'M');
        qr.addData(text);
        qr.make();
        box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
        const svg = box.querySelector('svg');
        if (svg) { svg.style.width = '100%'; svg.style.height = 'auto'; svg.style.background = '#fff'; }
    } catch (e) {
        box.textContent = '';
    }
}

/** The upload form for a new version of the mobile app; also opened from the อุปกรณ์ POS page. */
export function openMobileUpload(onDone = () => {}) {
    const el = document.createElement('div');
    el.className = 'modal-backdrop';
    el.innerHTML = `
      <div class="modal" style="width:540px" role="dialog" aria-modal="true" aria-label="อัปโหลดแอป">
        <div class="modal-head"><h2>อัปโหลดแอปบนมือถือเวอร์ชันใหม่ (.apk)</h2><button type="button" class="modal-close" data-x aria-label="ปิด">✕</button></div>
        <div class="modal-body">
          <div class="form-grid">
            <label class="field" style="grid-column:1/-1">ไฟล์ .apk * <input type="file" data-u="file" accept=".apk,application/vnd.android.package-archive"></label>
            <label class="field">เวอร์ชัน * <input data-u="version" maxlength="30" placeholder="เช่น 1.0.0"></label>
            <label class="field">หมายเหตุ <input data-u="note" maxlength="500"></label>
          </div>
          <p class="muted" style="font-size:12.5px;margin:10px 0 0">แยกจากแอป POS: ไฟล์นี้คือแอปสำหรับผู้ใช้ที่ไม่ใช่พนักงานขาย</p>
          <div data-u="progress" class="muted" style="font-size:13px;margin-top:10px"></div>
          <div data-u="error" class="error-box" style="margin-top:10px" hidden></div>
        </div>
        <div class="modal-foot"><button type="button" class="btn secondary" data-x>ปิด</button>
          <button type="button" class="btn" data-u="go" style="margin-left:auto">อัปโหลด</button></div>
      </div>`;
    document.body.appendChild(el);
    let uploading = false;
    let file = null;
    const $ = (k) => el.querySelector(`[data-u="${k}"]`);
    const fail = (m) => { $('error').textContent = m; $('error').hidden = false; };
    el.querySelectorAll('[data-x]').forEach((b) => b.addEventListener('click', () => { if (!uploading) el.remove(); }));
    $('file').addEventListener('change', (e) => {
        file = e.target.files[0] || null;
        const v = file?.name.match(/(\d+\.\d+(?:\.\d+)?)/);
        if (v && !$('version').value.trim()) $('version').value = v[1];
    });
    $('go').addEventListener('click', async () => {
        if (uploading) return;
        const version = $('version').value.trim();
        if (!file || !/\.apk$/i.test(file.name)) return fail('กรุณาเลือกไฟล์ .apk');
        if (!version) return fail('กรุณาระบุเวอร์ชันของแอป');
        $('error').hidden = true;
        uploading = true;
        $('go').disabled = true;
        $('go').textContent = 'กำลังอัปโหลด…';
        const id = [...crypto.getRandomValues(new Uint8Array(16))].map((b) => b.toString(16).padStart(2, '0')).join('');
        const CHUNK = 1048576; // AppReleaseService::CHUNK
        try {
            for (let i = 0, off = 0; off < file.size; i++, off += CHUNK) {
                const res = await fetch(`./api/devices/app/chunk?kind=mobile&id=${id}&index=${i}`, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/octet-stream', 'X-CSRF-Token': getCsrfToken() || '' },
                    body: file.slice(off, off + CHUNK),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(data.error || `อัปโหลดไม่สำเร็จ (${res.status})`);
                const done = Math.min(file.size, off + CHUNK);
                $('progress').textContent = `${Math.round((done / file.size) * 100)}% · ${fileSize(done)} / ${fileSize(file.size)}`;
            }
            $('progress').textContent = 'กำลังตรวจไฟล์…';
            const release = (await api.post('/devices/app/finish?kind=mobile', { upload_id: id, size: file.size, version, note: $('note').value })).data;
            uploading = false;
            el.remove();
            onDone(release);
            await alertDialog(`อัปโหลดแอปบนมือถือเวอร์ชัน ${release.version} แล้ว`, { title: 'อัปโหลดสำเร็จ' });
        } catch (e) {
            uploading = false;
            $('go').disabled = false;
            $('go').textContent = 'อัปโหลด';
            $('progress').textContent = '';
            fail(e.message);
        }
    });
}

export async function render(container, { user, permissions, params = {} }) {
    const canUpload = permissions.includes('devices.manage');
    const serverUrl = `${location.origin}${location.pathname.replace(/[^/]*$/, '')}`;
    const apkUrl = `${serverUrl}api/app/mobile.apk`;
    let devices = [];
    let notMigrated = null;
    let release = null;
    let code = (params.code || '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6);
    let name = '';
    let busy = false;
    let error = null;

    async function load() {
        try {
            const res = await api.get('/my/mobile-devices');
            devices = res.data || [];
            notMigrated = res.not_migrated || null;
        } catch (e) {
            error = e.message;
        }
        try { release = (await api.get('/app/mobile.json')).data; } catch (e) { release = null; }
        draw();
    }

    function deviceRow(d) {
        return `<tr>
          <td><div style="font-weight:600">${escapeHtml(d.name)}</div>
            <div class="muted" style="font-size:11.5px">${escapeHtml([d.model, d.platform, d.app_version && `แอป v${d.app_version}`].filter(Boolean).join(' · '))}</div></td>
          <td class="muted" style="font-size:12.5px">${d.paired_at ? fmtDateTime(d.paired_at) : '-'}</td>
          <td class="muted" style="font-size:12.5px">${d.last_sync_at ? fmtDateTime(d.last_sync_at) : 'ยังไม่ได้โหลดข้อมูล'}</td>
          <td style="text-align:right"><button type="button" class="btn sm secondary" data-revoke="${d.id}">ยกเลิกการจับคู่</button></td>
        </tr>`;
    }

    function draw() {
        container.innerHTML = `
        ${notMigrated ? `<div class="error-box">${escapeHtml(notMigrated)}</div>` : ''}
        ${error ? `<div class="error-box">${escapeHtml(error)}</div>` : ''}
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;align-items:start">
          <section class="card card-pad">
            <h2 style="margin:0 0 6px;font-size:16px">จับคู่แอปกับบัญชีของฉัน</h2>
            <p class="muted" style="font-size:13px;margin:0 0 12px">แอปจะใช้งานในชื่อ <b>${escapeHtml(user.full_name)}</b> ตามสิทธิ์ของบัญชีนี้
              เปิดแอปแล้วกรอกรหัส 6 ตัวที่แอปแสดง หรือสแกน QR-Code ในแอปด้วยมือถือที่ลงชื่อเข้าใช้อยู่ รหัสใช้ได้ 10 นาที</p>
            <div class="form-grid">
              <label class="field">รหัสจับคู่ *
                <input id="ma-code" maxlength="6" autocomplete="off" autocapitalize="characters" value="${escapeHtml(code)}"
                  style="font-family:ui-monospace,monospace;font-size:22px;letter-spacing:6px;text-transform:uppercase" placeholder="ABC123"></label>
              <label class="field">ชื่ออุปกรณ์ <input id="ma-name" maxlength="100" value="${escapeHtml(name)}" placeholder="เช่น มือถือของฉัน (ไม่ใส่ก็ได้)"></label>
            </div>
            <div style="display:flex;justify-content:flex-end;margin-top:12px">
              <button type="button" class="btn" id="ma-pair" ${busy || notMigrated ? 'disabled' : ''}>${busy ? 'กำลังจับคู่…' : 'จับคู่กับบัญชีของฉัน'}</button>
            </div>
          </section>
          <section class="card card-pad">
            <h2 style="margin:0 0 6px;font-size:16px">ดาวน์โหลดแอป (Android 13 ขึ้นไป)</h2>
            ${release ? `
              <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
                <div id="ma-qr" style="width:132px;flex:none"></div>
                <div style="flex:1;min-width:160px">
                  <div style="font-weight:600">เวอร์ชัน ${escapeHtml(release.version)} · ${fileSize(release.size)}</div>
                  ${release.note ? `<div class="muted" style="font-size:12.5px">${escapeHtml(release.note)}</div>` : ''}
                  <div class="muted" style="font-size:12px;margin:4px 0 10px">สแกน QR-Code ด้วยกล้องของมือถือ หรือกดดาวน์โหลดบนมือถือ ครั้งแรกเครื่องจะถามให้อนุญาตติดตั้งแอปจากแหล่งนี้</div>
                  <a class="btn secondary" href="${escapeHtml(apkUrl)}" download>ดาวน์โหลดแอป</a>
                </div>
              </div>` : '<p class="muted" style="font-size:13px;margin:0">ยังไม่มีไฟล์ติดตั้งแอป</p>'}
            ${canUpload ? '<div style="margin-top:12px"><button type="button" class="btn sm secondary" id="ma-upload">อัปโหลดแอปเวอร์ชันใหม่ (.apk)</button></div>' : ''}
          </section>
        </div>
        <section class="card">
          <div class="card-head"><h2>อุปกรณ์ของฉัน</h2></div>
          <div class="table-wrap"><table>
            <thead><tr><th>อุปกรณ์</th><th>จับคู่เมื่อ</th><th>โหลดข้อมูลล่าสุด</th><th></th></tr></thead>
            <tbody>${devices.map(deviceRow).join('') || '<tr><td colspan="4" class="empty">ยังไม่มีอุปกรณ์ที่จับคู่</td></tr>'}</tbody>
          </table></div>
        </section>`;

        if (release) drawQr(container.querySelector('#ma-qr'), apkUrl);
        const codeEl = container.querySelector('#ma-code');
        codeEl.addEventListener('input', (e) => {
            code = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6);
            if (e.target.value !== code) e.target.value = code;
        });
        codeEl.addEventListener('keydown', (e) => { if (e.key === 'Enter') pair(); });
        container.querySelector('#ma-name').addEventListener('input', (e) => { name = e.target.value; });
        container.querySelector('#ma-pair').addEventListener('click', pair);
        container.querySelector('#ma-upload')?.addEventListener('click', () => openMobileUpload((r) => { release = r; draw(); }));
        container.querySelectorAll('[data-revoke]').forEach((b) => b.addEventListener('click', () => revoke(Number(b.dataset.revoke))));
        if (!code) codeEl.focus();
    }

    async function pair() {
        if (busy) return;
        if (code.length !== 6) {
            await alertDialog('กรุณากรอกรหัสจับคู่ 6 ตัวที่แอปแสดง', { tone: 'error' });
            return;
        }
        busy = true;
        draw();
        try {
            const d = (await api.post('/my/mobile-devices/pair', { code, name: name.trim() || null })).data;
            busy = false;
            code = '';
            name = '';
            // Leave the code out of the address so a reload does not try it again.
            if (params.code) history.replaceState(null, '', '#/mobileapp');
            await load();
            await alertDialog(`จับคู่ "${d.name}" กับบัญชีของคุณแล้ว แอปจะเริ่มโหลดข้อมูลเองในไม่กี่วินาที`, { title: 'จับคู่สำเร็จ' });
        } catch (e) {
            busy = false;
            draw();
            await alertDialog(e.message, { tone: 'error' });
        }
    }

    async function revoke(id) {
        const d = devices.find((x) => x.id === id);
        if (!d) return;
        const ok = await confirmDialog(`ยกเลิกการจับคู่ "${d.name}"? แอปในเครื่องนั้นจะลบข้อมูลที่เก็บไว้เมื่อเชื่อมต่อครั้งถัดไป และต้องจับคู่ใหม่ก่อนใช้งาน`,
            { title: 'ยกเลิกการจับคู่?', okText: 'ยกเลิกการจับคู่', danger: true });
        if (!ok) return;
        try {
            await api.post(`/my/mobile-devices/${id}/revoke`);
            await load();
        } catch (e) {
            await alertDialog(e.message, { tone: 'error' });
        }
    }

    await load();
}
