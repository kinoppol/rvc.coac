import { api } from '../api.js';
import { escapeHtml, fmtDateTime, qs } from '../util.js';
import { confirmDialog, alertDialog, promptDialog } from '../dialog.js';

// ตรวจสอบความปลอดภัย (admins): scans for web shells (e.g. php2shell), PHP files where the
// system keeps none, changed files, risky PHP settings and folders open to the web, and
// opens / closes the installer's repair mode. A flagged file can be looked into and moved to
// quarantine (no longer run or served, nothing lost), then put back or deleted for good.
// See SecurityScanService and QuarantineService.

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
        const acts = it.path ? `<span class="sec-acts">
            <button type="button" class="btn secondary sm" data-view="${escapeHtml(it.path)}">ดูไฟล์</button>
            <button type="button" class="btn danger sm" data-quarantine="${escapeHtml(it.path)}">กักกัน</button>
          </span>` : '';
        return `<li class="sec-item">
            <div class="sec-row"><div><span class="sec-file">${where}</span>${it.rule ? ` <span class="pill ${lv}">${escapeHtml(it.rule)}</span>` : ''}</div>${acts}</div>
            ${it.code ? `<code class="sec-code">${escapeHtml(it.code)}</code>` : ''}
            ${it.note || it.modified ? `<div class="muted sec-note">${escapeHtml(it.note || 'แก้ไขล่าสุด ' + it.modified)}</div>` : ''}
          </li>`;
    }

    function checkHtml(c) {
        const [cls, label] = STATUS[c.status] || STATUS.info;
        const items = c.items || [];
        const paths = [...new Set(items.filter((it) => it.path).map((it) => it.path))];
        return `<div class="sec-check">
            <div class="sec-head">
              <span class="pill ${cls}">${label}</span>
              <b>${escapeHtml(c.title)}</b>
              ${c.status === 'bad' && paths.length > 1 ? `<button type="button" class="btn danger sm" data-quarantine-all="${escapeHtml(JSON.stringify(paths))}">กักกันทั้งหมด ${paths.length} ไฟล์</button>` : ''}
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
        ${report ? quarantineHtml() : ''}
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
        container.querySelectorAll('[data-view]').forEach((b) => b.addEventListener('click', () => viewFile(b.dataset.view)));
        container.querySelectorAll('[data-quarantine]').forEach((b) => b.addEventListener('click', () => quarantine([b.dataset.quarantine])));
        container.querySelectorAll('[data-quarantine-all]').forEach((b) => b.addEventListener('click', () => quarantine(JSON.parse(b.dataset.quarantineAll))));
        container.querySelectorAll('[data-q-view]').forEach((b) => b.addEventListener('click', () => viewQuarantined(b.dataset.qView)));
        container.querySelectorAll('[data-q-restore]').forEach((b) => b.addEventListener('click', () => restore(b.dataset.qRestore)));
        container.querySelectorAll('[data-q-delete]').forEach((b) => b.addEventListener('click', () => destroy(b.dataset.qDelete)));
    }

    // ---- quarantine ----

    const isSystem = (p) => /^(src|vendor|migrations|bin)\/|^public\/(index|install)\.php$|^index\.php$|^public\/assets\/|^public\/app\.html$|(^|\/)\.htaccess$/.test(p);
    const size = (n) => n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : n >= 1024 ? `${Math.round(n / 1024)} KB` : `${n} B`;

    function quarantineHtml() {
        const rows = report.quarantine || [];
        return `<section class="card card-pad">
          <h2>พื้นที่กักกันไฟล์ ${rows.length ? `<span class="pill warn">${rows.length} ไฟล์</span>` : ''}</h2>
          <p class="muted" style="font-size:13px;margin:4px 0 10px">ไฟล์ที่กักกันถูกย้ายออกจากตำแหน่งเดิมไปเก็บใน storage/quarantine/ โดยไม่มีนามสกุล — รันหรือเปิดจากเว็บไม่ได้ แต่ยังเปิดดูเนื้อหา คืนไฟล์ หรือลบถาวรได้</p>
          ${rows.length ? `<div class="table-wrap"><table>
            <thead><tr><th>ไฟล์ (ตำแหน่งเดิม)</th><th>ขนาด</th><th>กักกันเมื่อ</th><th>เหตุผล</th><th></th></tr></thead>
            <tbody>${rows.map((r) => `<tr>
              <td><span class="sec-file">${escapeHtml(r.path)}</span>${r.system ? ' <span class="pill warn">ไฟล์ระบบ</span>' : ''}${r.present ? '' : ' <span class="pill bad">ไฟล์หาย</span>'}
                <div class="muted sec-note">แก้ไขล่าสุดก่อนกักกัน ${escapeHtml(fmtDateTime(r.modified))} · SHA-256 ${escapeHtml(String(r.sha256).slice(0, 16))}…</div></td>
              <td>${size(r.size)}</td>
              <td>${escapeHtml(fmtDateTime(r.at))}</td>
              <td>${escapeHtml(r.reason || '-')}</td>
              <td style="white-space:nowrap">
                <button type="button" class="btn secondary sm" data-q-view="${escapeHtml(r.id)}" ${r.present ? '' : 'disabled'}>ดูไฟล์</button>
                <button type="button" class="btn secondary sm" data-q-restore="${escapeHtml(r.id)}" ${r.present ? '' : 'disabled'}>คืนไฟล์</button>
                <button type="button" class="btn danger sm" data-q-delete="${escapeHtml(r.id)}">ลบถาวร</button>
              </td>
            </tr>`).join('')}</tbody>
          </table></div>` : '<p class="muted">ยังไม่มีไฟล์ที่กักกัน</p>'}
        </section>`;
    }

    /** Safe look inside a file: facts, matched patterns, code near PHP tags, the picture or the text. */
    function fileHtml(d) {
        const q = d.quarantine;
        return `<div class="sec-view">
          <div class="sec-facts">
            <div><span class="muted">ไฟล์</span> <span class="sec-file">${escapeHtml(d.path)}</span>${d.system ? ' <span class="pill warn">ไฟล์ระบบ</span>' : ''}</div>
            <div><span class="muted">ขนาด</span> ${size(d.size)} · <span class="muted">ชนิด</span> ${escapeHtml(d.mime || '-')}${d.image_size ? ` (${escapeHtml(d.image_size)})` : ''} · <span class="muted">แก้ไขล่าสุด</span> ${escapeHtml(fmtDateTime(d.modified))}</div>
            <div><span class="muted">SHA-256</span> <code style="word-break:break-all">${escapeHtml(d.sha256)}</code></div>
            ${q ? `<div><span class="muted">กักกันเมื่อ</span> ${escapeHtml(fmtDateTime(q.at))} · <span class="muted">เหตุผล</span> ${escapeHtml(q.reason || '-')}</div>` : ''}
          </div>
          ${d.findings.length ? `<h4>รูปแบบที่ตรงกับ web shell</h4><ul class="sec-list">${d.findings.map((f) => `<li class="sec-item"><b>บรรทัด ${f.line}</b> <span class="pill ${f.level === 'bad' ? 'bad' : 'warn'}">${escapeHtml(f.rule)}</span><code class="sec-code">${escapeHtml(f.code)}</code></li>`).join('')}</ul>` : ''}
          ${d.snippets.length ? `<h4>โค้ด PHP ที่แฝงอยู่ (ตำแหน่งไบต์)</h4>${d.snippets.map((s) => `<div class="muted sec-note">ไบต์ที่ ${s.offset.toLocaleString()}</div><pre class="sec-pre">${escapeHtml(s.text)}</pre>`).join('')}` : ''}
          ${d.image ? `<h4>ภาพ</h4><img class="sec-img" src="${escapeHtml(d.image)}" alt="">` : ''}
          ${d.text !== null ? `<h4>เนื้อหาไฟล์${d.text_cut ? ' (แสดงบางส่วน)' : ''}</h4><pre class="sec-pre sec-text">${escapeHtml(d.text)}</pre>` : (!d.image && !d.snippets.length ? '<p class="muted">ไฟล์ไบนารี แสดงเนื้อหาไม่ได้</p>' : '')}
        </div>`;
    }

    async function viewFile(path) {
        let d;
        try { d = (await api.get('/admin/security/inspect' + qs({ path }))).data; }
        catch (e) { return alertDialog(e.message, { tone: 'error' }); }
        const go = await confirmDialog('', { title: 'ตรวจดูไฟล์', html: fileHtml(d), width: 900, okText: 'กักกันไฟล์นี้', cancelText: 'ปิด', danger: true });
        if (go) await quarantine([path]);
    }

    async function viewQuarantined(id) {
        let d;
        try { d = (await api.get('/admin/security/quarantine/' + encodeURIComponent(id))).data; }
        catch (e) { return alertDialog(e.message, { tone: 'error' }); }
        await confirmDialog('', { title: 'ไฟล์ในพื้นที่กักกัน', html: fileHtml(d), width: 900, okText: 'ปิด', cancelText: '' });
    }

    async function quarantine(paths) {
        const sys = paths.filter(isSystem);
        const reason = await promptDialog(
            `ย้าย ${paths.length} ไฟล์ไปพื้นที่กักกัน ไฟล์จะรันหรือเปิดจากเว็บไม่ได้ทันที (คืนไฟล์ได้ภายหลัง)`
            + (sys.length ? `\n\n⚠ มีไฟล์ของระบบ ${sys.length} ไฟล์ (${sys.slice(0, 3).join(', ')}${sys.length > 3 ? ' …' : ''}) — ถ้าเป็นไฟล์จริงของระบบที่ถูกแก้ ระบบส่วนนั้นจะใช้งานไม่ได้จนกว่าจะคืนไฟล์หรือดึงไฟล์ที่ถูกต้องจาก Git มาวางใหม่` : ''),
            { title: 'กักกันไฟล์?', okText: 'กักกัน', danger: true, placeholder: 'เหตุผล (ไม่บังคับ) เช่น พบ eval(base64_decode) ไม่ใช่ไฟล์ของระบบ', multiline: true }
        );
        if (reason === null) return;
        try {
            const r = (await api.post('/admin/security/quarantine', { paths, reason })).data;
            if (r.failed.length) {
                await alertDialog(`กักกันแล้ว ${r.moved.length} ไฟล์ · ไม่สำเร็จ ${r.failed.length} ไฟล์\n` + r.failed.map((f) => `${f.path}: ${f.error}`).join('\n'), { title: 'กักกันไฟล์', tone: 'error' });
            }
        } catch (e) { return alertDialog(e.message, { tone: 'error' }); }
        await scan();
    }

    async function restore(id) {
        const r = (report.quarantine || []).find((x) => x.id === id);
        if (!(await confirmDialog(`คืนไฟล์ ${r?.path || ''} กลับตำแหน่งเดิม — ถ้าเป็น web shell ไฟล์จะกลับมารันได้อีก คืนเฉพาะเมื่อตรวจแล้วว่าเป็นไฟล์ปกติ`, { title: 'คืนไฟล์?', okText: 'คืนไฟล์' }))) return;
        try { await api.post(`/admin/security/quarantine/${encodeURIComponent(id)}/restore`); }
        catch (e) { return alertDialog(e.message, { tone: 'error' }); }
        await scan();
    }

    async function destroy(id) {
        const r = (report.quarantine || []).find((x) => x.id === id);
        if (!(await confirmDialog(`ลบไฟล์ ${r?.path || ''} ออกจากเซิร์ฟเวอร์ถาวร กู้คืนไม่ได้ (ถ้าต้องเก็บเป็นหลักฐาน ให้ดาวน์โหลด/สำรองจากเซิร์ฟเวอร์ก่อน)`, { title: 'ลบถาวร?', okText: 'ลบถาวร', danger: true }))) return;
        try { await api.post(`/admin/security/quarantine/${encodeURIComponent(id)}/delete`); }
        catch (e) { return alertDialog(e.message, { tone: 'error' }); }
        await scan();
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
