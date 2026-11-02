import { api } from '../api.js';
import { escapeHtml, fmtDateTime } from '../util.js';
import { confirmDialog, alertDialog } from '../dialog.js';

// ตรวจสอบความปลอดภัย (admins): scans for web shells (e.g. php2shell), PHP files where the
// system keeps none, changed files, risky PHP settings and folders open to the web, and
// opens / closes the installer's repair mode. See SecurityScanService.

const STATUS = {
    bad: ['bad', '✖ อันตราย'],
    warn: ['warn', '⚠ ควรแก้'],
    ok: ['ok', '✔ ผ่าน'],
    info: ['', 'ℹ ข้อมูล'],
};

export async function render(container) {
    let report = null;
    let error = null;
    let busy = false;

    async function scan() {
        busy = true; error = null; draw();
        try { report = (await api.get('/admin/security/scan')).data; }
        catch (e) { error = e.message; }
        busy = false; draw();
    }

    function itemHtml(it) {
        const where = it.line ? `${escapeHtml(it.file)}:${it.line}` : escapeHtml(it.file);
        const lv = it.level === 'bad' ? 'bad' : it.level === 'warn' ? 'warn' : it.level === 'ok' ? 'ok' : '';
        return `<li class="sec-item">
            <div><span class="sec-file">${where}</span>${it.rule ? ` <span class="pill ${lv}">${escapeHtml(it.rule)}</span>` : ''}</div>
            ${it.code ? `<code class="sec-code">${escapeHtml(it.code)}</code>` : ''}
            ${it.note || it.modified ? `<div class="muted sec-note">${escapeHtml(it.note || 'แก้ไขล่าสุด ' + it.modified)}</div>` : ''}
          </li>`;
    }

    function checkHtml(c) {
        const [cls, label] = STATUS[c.status] || STATUS.info;
        const items = c.items || [];
        return `<div class="sec-check">
            <div class="sec-head">
              <span class="pill ${cls}">${label}</span>
              <b>${escapeHtml(c.title)}</b>
            </div>
            ${c.detail ? `<p class="muted sec-detail">${escapeHtml(c.detail)}</p>` : ''}
            ${items.length ? `<details ${c.status === 'bad' ? 'open' : ''}><summary>ดูรายการ (${items.length})</summary><ul class="sec-list">${items.map(itemHtml).join('')}</ul></details>` : ''}
          </div>`;
    }

    function repairHtml() {
        const sec = report?.sections.find((s) => s.key === 'repair');
        const until = sec?.repair_until;
        return `<section class="card card-pad">
          <div class="actions-row" style="justify-content:space-between">
            <div>
              <h2>โหมดซ่อมแซมของตัวติดตั้ง (install.php)</h2>
              <p class="muted" style="font-size:13px;margin:4px 0 0">${until
                ? `<b style="color:var(--warn)">เปิดอยู่ถึง ${escapeHtml(fmtDateTime(until))}</b> — ใช้ <a href="./install.php?step=requirements&repair=1" target="_blank" rel="noopener">install.php</a> ตั้งรหัสผ่านผู้ดูแลระบบหรือแก้การเชื่อมต่อฐานข้อมูลได้ ปิดทันทีเมื่อเสร็จ`
                : 'ปิดอยู่ — ไม่มีใครใช้ install.php ตั้งรหัสผ่านผู้ดูแลระบบหรือแก้การตั้งค่าฐานข้อมูลได้'}</p>
            </div>
            ${until
                ? '<button type="button" class="btn" id="btn-repair-close">ปิดโหมดซ่อมแซม</button>'
                : '<button type="button" class="btn secondary" id="btn-repair-open">เปิดโหมดซ่อมแซม 30 นาที</button>'}
          </div>
        </section>`;
    }

    function draw() {
        const c = report?.counts;
        const integrity = report?.sections.find((s) => s.key === 'integrity');
        container.innerHTML = `
        <section class="card card-pad">
          <div class="actions-row" style="justify-content:space-between">
            <div>
              <h2>ตรวจสอบความปลอดภัยของระบบ</h2>
              <p class="muted" style="font-size:13px;margin:4px 0 0">ค้นหา web shell (เช่น php2shell) ไฟล์ PHP ที่ถูกวางเพิ่ม ไฟล์ระบบที่ถูกแก้ การตั้งค่า PHP ที่เสี่ยง และโฟลเดอร์ภายในที่เปิดจากเว็บได้ — อ่านอย่างเดียว ไม่แก้ไขหรือลบไฟล์ใด</p>
            </div>
            <div class="actions-row">
              <button type="button" class="btn secondary" id="btn-baseline" ${busy || !report ? 'disabled' : ''} title="จดลายนิ้วมือไฟล์ทั้งหมดตอนนี้ไว้เทียบในการสแกนครั้งต่อไป">บันทึกฐานเทียบไฟล์</button>
              <button type="button" class="btn" id="btn-scan" ${busy ? 'disabled' : ''}>${busy ? 'กำลังสแกน…' : 'สแกนอีกครั้ง'}</button>
            </div>
          </div>
          ${error ? `<div class="error-box" style="margin-top:14px">${escapeHtml(error)}</div>` : ''}
          ${report ? `
            <div class="sec-summary">
              <span class="pill bad">อันตราย ${c.bad}</span>
              <span class="pill warn">ควรแก้ ${c.warn}</span>
              <span class="pill ok">ผ่าน ${c.ok}</span>
              <span class="muted">สแกน ${report.file_count.toLocaleString()} ไฟล์ · ${report.seconds} วินาที · ${escapeHtml(fmtDateTime(report.scanned_at))}
              ${integrity?.baseline ? ` · ฐานเทียบ ${escapeHtml(fmtDateTime(integrity.baseline.saved_at))}` : ''}</span>
            </div>` : ''}
        </section>
        ${report ? repairHtml() : ''}
        ${report ? report.sections.map((s) => `
          <section class="card card-pad">
            <h2 style="margin-bottom:10px">${escapeHtml(s.title)}</h2>
            ${s.checks.map(checkHtml).join('')}
          </section>`).join('') : (busy ? '<section class="card card-pad muted">กำลังสแกนไฟล์ทั้งหมด อาจใช้เวลาสักครู่…</section>' : '')}
        `;

        container.querySelector('#btn-scan').addEventListener('click', scan);
        container.querySelector('#btn-baseline').addEventListener('click', async () => {
            if (!(await confirmDialog('ใช้ไฟล์ทั้งหมดตอนนี้เป็นฐานเทียบ การสแกนครั้งต่อไปจะแจ้งไฟล์ที่ถูกแก้ เพิ่ม หรือลบหลังจากนี้ ทำเมื่อมั่นใจว่าระบบสะอาด (เช่น ไม่พบรายการอันตราย หรือหลังอัปเดตระบบจาก Git)', { title: 'บันทึกฐานเทียบไฟล์?', okText: 'บันทึก' }))) return;
            try {
                const r = (await api.post('/admin/security/baseline')).data;
                await alertDialog(`บันทึกฐานเทียบ ${r.file_count.toLocaleString()} ไฟล์แล้ว`, { title: 'บันทึกแล้ว' });
                await scan();
            } catch (e) { error = e.message; draw(); }
        });
        container.querySelector('#btn-repair-open')?.addEventListener('click', async () => {
            if (!(await confirmDialog('ระหว่างเปิดโหมดซ่อมแซม ใครก็ตามที่เปิด install.php ได้จะตั้งรหัสผ่านผู้ดูแลระบบและแก้การเชื่อมต่อฐานข้อมูลได้ เปิดเฉพาะตอนจะซ่อมแซม แล้วปิดทันทีเมื่อเสร็จ (ปิดเองอัตโนมัติใน 30 นาที)', { title: 'เปิดโหมดซ่อมแซม?', okText: 'เปิด 30 นาที', danger: true }))) return;
            await setRepair(true);
        });
        container.querySelector('#btn-repair-close')?.addEventListener('click', () => setRepair(false));
    }

    async function setRepair(open) {
        try {
            const r = (await api.post('/admin/security/repair', { open })).data;
            const sec = report.sections.find((s) => s.key === 'repair');
            if (sec) sec.repair_until = r.repair_until;
            draw();
        } catch (e) { error = e.message; draw(); }
    }

    draw();
    await scan();
}
