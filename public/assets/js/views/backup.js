import { api } from '../api.js';
import { escapeHtml, count, fmtDate, fmtDateTime } from '../util.js';
import { confirmDialog, alertDialog, promptDialog } from '../dialog.js';

// แบ็คอัพและกู้คืนข้อมูล (admins): images, the database and the other uploaded files, each for a
// period (today, yesterday, this / last week, this / last month) or all of it; download a backup
// to keep it off this server, put one back, or delete it. See BackupService.

const KIND_HINT = {
    images: 'รูปสินค้า รูปใบเสร็จ และรูปหลักฐานต่าง ๆ ใน storage/uploads (เลือกตามวันที่ไฟล์ถูกบันทึก)',
    database: 'ข้อมูลทั้งหมดของระบบ (ขาย สต๊อก ผู้ฝาก บัญชี ผู้ใช้ ...) — "ทั้งหมด" เก็บทุกตาราง, ช่วงเวลาเก็บเฉพาะรายการที่สร้าง/แก้ในช่วงนั้น',
    files: 'ไฟล์อื่นที่ไม่ใช่รูปใน storage/uploads เช่น ไฟล์ใบเสร็จแบบข้อความ / PDF (เลือกตามวันที่ไฟล์ถูกบันทึก)',
};
const KIND_ICON = { images: '🖼', database: '🗄', files: '📄' };

const size = (b) => {
    if (b >= 1024 ** 3) return `${(b / 1024 ** 3).toLocaleString('th-TH', { maximumFractionDigits: 2 })} GB`;
    if (b >= 1024 ** 2) return `${(b / 1024 ** 2).toLocaleString('th-TH', { maximumFractionDigits: 1 })} MB`;
    return `${Math.max(1, Math.round(b / 1024)).toLocaleString('th-TH')} KB`;
};

export async function render(container) {
    let d = null;
    let error = null;
    let kind = 'database';
    let range = 'all';
    let busy = null; // what is running: 'create' | backup id
    let filter = 'all';

    async function load() {
        try {
            d = (await api.get('/admin/backups')).data;
            error = null;
        } catch (e) {
            error = e.message;
        }
        draw();
    }

    const rangeOf = (key) => d.ranges.find((r) => r.key === key);
    const rangeText = (r) => (r.from ? (r.from === r.to ? fmtDate(r.from) : `${fmtDate(r.from)} – ${fmtDate(r.to)}`) : 'ข้อมูลทั้งหมด');

    function draw() {
        if (error) { container.innerHTML = `<div class="error-box">${escapeHtml(error)}</div>`; return; }
        const list = d.backups.filter((b) => filter === 'all' || b.kind === filter);
        const total = d.backups.reduce((s, b) => s + b.size, 0);
        container.innerHTML = `
          <div style="max-width:1100px;display:flex;flex-direction:column;gap:16px">
            <section class="card card-pad">
              <h2 style="margin-bottom:6px">สร้างแบ็คอัพ</h2>
              <p class="muted" style="font-size:13px;margin:0 0 14px">แบ็คอัพเก็บไว้ที่ storage/backups บนเซิร์ฟเวอร์นี้ — ควรดาวน์โหลดไปเก็บที่อื่นด้วย (เช่น คอมพิวเตอร์หรือไดรฟ์ออนไลน์ของวิทยาลัย) เผื่อเครื่องเซิร์ฟเวอร์เสีย</p>
              <div style="font-weight:600;margin-bottom:6px">ข้อมูลที่จะแบ็คอัพ</div>
              <div class="bk-kinds">${Object.entries(d.kinds).map(([k, label]) => `
                <label class="bk-kind ${kind === k ? 'on' : ''}"><input type="radio" name="bk-kind" value="${k}" ${kind === k ? 'checked' : ''}>
                  <span><b>${KIND_ICON[k]} ${escapeHtml(label)}</b><span class="muted" style="display:block;font-size:12.5px">${escapeHtml(KIND_HINT[k])}</span></span></label>`).join('')}</div>
              <div style="font-weight:600;margin:14px 0 6px">ช่วงเวลาของข้อมูล</div>
              <div class="tabs" role="group" aria-label="ช่วงเวลา">${d.ranges.map((r) => `
                <button type="button" class="tab-btn ${range === r.key ? 'active' : ''}" data-range="${r.key}" title="${escapeHtml(rangeText(r))}">${escapeHtml(r.label)}</button>`).join('')}</div>
              <div class="muted" style="font-size:12.5px;margin-top:6px">${escapeHtml(rangeText(rangeOf(range)))}${kind === 'database' && range !== 'all'
                  ? ' · เก็บรายการที่สร้างหรือแก้ไขในช่วงนี้ (ตารางที่ไม่มีวันที่ เช่น การตั้งค่า เก็บทั้งตาราง) — กู้คืนแล้วเขียนทับเฉพาะรายการเหล่านี้ ไม่ลบข้อมูลอื่น' : ''}</div>
              <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px">
                <button type="button" class="btn" id="bk-create" ${busy ? 'disabled' : ''}>${busy === 'create' ? 'กำลังแบ็คอัพ... (อาจใช้เวลาหลายนาที)' : `แบ็คอัพ${escapeHtml(d.kinds[kind])} · ${escapeHtml(rangeOf(range).label)}`}</button>
                ${d.disk_free != null ? `<span class="muted" style="font-size:12.5px">พื้นที่ดิสก์เหลือ ${size(d.disk_free)}</span>` : ''}
              </div>
            </section>

            <section class="card">
              <div class="card-head" style="flex-wrap:wrap;gap:10px">
                <h2>แบ็คอัพที่มี (${count(d.backups.length)} ไฟล์ · ${size(total)})</h2>
                <div class="tabs" role="group" aria-label="กรองประเภท">
                  ${[['all', 'ทั้งหมด'], ...Object.entries(d.kinds)].map(([k, l]) => `<button type="button" class="tab-btn ${filter === k ? 'active' : ''}" data-filter="${k}">${escapeHtml(l)} (${count(d.backups.filter((b) => k === 'all' || b.kind === k).length)})</button>`).join('')}
                </div>
              </div>
              ${list.length ? `<div class="table-wrap"><table>
                <thead><tr><th>สร้างเมื่อ</th><th>ประเภท</th><th>ช่วงข้อมูล</th><th class="num">รายการ</th><th class="num">ขนาด</th><th>โดย / หมายเหตุ</th><th></th></tr></thead>
                <tbody>${list.map((b) => `<tr>
                  <td style="white-space:nowrap">${escapeHtml(fmtDateTime(b.created_at))}</td>
                  <td style="white-space:nowrap">${KIND_ICON[b.kind] || ''} ${escapeHtml(d.kinds[b.kind] || b.kind)}</td>
                  <td><b>${escapeHtml(rangeOf(b.range)?.label || b.range)}</b><div class="muted" style="font-size:12px">${b.from ? escapeHtml(b.from === b.to ? fmtDate(b.from) : `${fmtDate(b.from)} – ${fmtDate(b.to)}`) : 'ข้อมูลทั้งหมด'}</div></td>
                  <td class="num">${count(b.items)} ${b.kind === 'database' ? 'แถว' : 'ไฟล์'}${b.tables ? `<div class="muted" style="font-size:12px">${count(b.tables)} ตาราง</div>` : ''}</td>
                  <td class="num">${size(b.size)}</td>
                  <td style="font-size:12.5px">${escapeHtml(b.created_by || '-')}${b.note ? `<div class="muted">${escapeHtml(b.note)}</div>` : ''}</td>
                  <td style="white-space:nowrap">
                    <a class="btn secondary sm" href="${api.downloadUrl(`/admin/backups/${encodeURIComponent(b.id)}/download`)}" download>ดาวน์โหลด</a>
                    <button type="button" class="btn secondary sm" data-restore="${escapeHtml(b.id)}" ${busy ? 'disabled' : ''}>${busy === b.id ? 'กำลังกู้คืน...' : 'กู้คืน'}</button>
                    <button type="button" class="btn danger sm" data-delete="${escapeHtml(b.id)}" ${busy ? 'disabled' : ''}>ลบ</button>
                  </td></tr>`).join('')}</tbody>
              </table></div>` : '<div class="empty" style="padding:18px">ยังไม่มีแบ็คอัพ</div>'}
            </section>
          </div>`;
        bind();
    }

    function bind() {
        container.querySelectorAll('[name="bk-kind"]').forEach((r) => r.addEventListener('change', () => { kind = r.value; draw(); }));
        container.querySelectorAll('[data-range]').forEach((b) => b.addEventListener('click', () => { range = b.dataset.range; draw(); }));
        container.querySelectorAll('[data-filter]').forEach((b) => b.addEventListener('click', () => { filter = b.dataset.filter; draw(); }));
        container.querySelector('#bk-create').addEventListener('click', create);
        container.querySelectorAll('[data-restore]').forEach((b) => b.addEventListener('click', () => restore(d.backups.find((x) => x.id === b.dataset.restore))));
        container.querySelectorAll('[data-delete]').forEach((b) => b.addEventListener('click', () => remove(d.backups.find((x) => x.id === b.dataset.delete))));
    }

    async function create() {
        busy = 'create';
        draw();
        try {
            const m = (await api.post('/admin/backups', { kind, range })).data;
            await load();
            alertDialog(`แบ็คอัพ${d.kinds[m.kind]} (${rangeOf(m.range).label}) แล้ว: ${count(m.items)} ${m.kind === 'database' ? 'แถว' : 'ไฟล์'} · ${size(m.size)}`, { title: 'แบ็คอัพเสร็จแล้ว' });
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        } finally {
            busy = null;
            draw();
        }
    }

    async function restore(b) {
        if (!b) return;
        const full = b.kind === 'database' && b.range === 'all';
        const what = b.kind === 'database'
            ? (full
                ? `ฐานข้อมูลทั้งหมดจะถูกแทนที่ด้วยข้อมูล ณ ${fmtDateTime(b.created_at)} — รายการที่บันทึกหลังจากนั้น (ขาย รับเข้า บัญชี ...) จะหายไปจากระบบ`
                : `รายการที่สร้าง/แก้ไขช่วง ${rangeOf(b.range)?.label} (${b.from === b.to ? fmtDate(b.from) : `${fmtDate(b.from)} – ${fmtDate(b.to)}`}) จะถูกเขียนทับด้วยค่าในแบ็คอัพ ข้อมูลอื่นไม่ถูกลบ`)
                + ' · ระบบจะแบ็คอัพฐานข้อมูลทั้งหมดปัจจุบันให้อัตโนมัติก่อน เพื่อย้อนกลับได้'
            : `ไฟล์ในแบ็คอัพจะถูกเขียนกลับไปที่ storage/uploads (${count(b.items)} ไฟล์)`;
        let overwrite = false;
        if (b.kind !== 'database') {
            const ok = await confirmDialog(`${what}\n\nไฟล์ที่ยังมีอยู่ในระบบ: ข้ามไป (ปลอดภัย) หรือเขียนทับด้วยไฟล์ในแบ็คอัพ?`, {
                title: 'กู้คืนไฟล์', okText: 'เขียนทับไฟล์ที่ต่างกันด้วย', cancelText: 'กู้คืนเฉพาะไฟล์ที่หายไป',
            });
            overwrite = ok;
        }
        const typed = await promptDialog(`${what}\n\nพิมพ์คำว่า "กู้คืน" เพื่อยืนยัน`, {
            title: `กู้คืน${d.kinds[b.kind]} · ${rangeOf(b.range)?.label || ''}`, okText: 'กู้คืน', placeholder: 'กู้คืน', required: true, danger: full,
        });
        if (typed == null) return;
        busy = b.id;
        draw();
        try {
            const r = (await api.post(`/admin/backups/${encodeURIComponent(b.id)}/restore`, { confirm: typed, overwrite })).data;
            await load();
            alertDialog(b.kind === 'database'
                ? `กู้คืนฐานข้อมูลแล้ว (${count(r.items)} คำสั่ง) · แบ็คอัพก่อนกู้คืนเก็บไว้แล้ว (${size(r.safety.size)}) · เปิดหน้าใหม่เพื่อดูข้อมูลล่าสุด`
                : `กู้คืนแล้ว ${count(r.items)} ไฟล์${r.skipped ? ` · ข้าม ${count(r.skipped)} ไฟล์ (มีอยู่แล้วหรือไม่ปลอดภัย)` : ''}`, { title: 'กู้คืนเสร็จแล้ว' });
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        } finally {
            busy = null;
            draw();
        }
    }

    async function remove(b) {
        if (!b) return;
        const ok = await confirmDialog(`ลบแบ็คอัพ${d.kinds[b.kind]} (${rangeOf(b.range)?.label}) ที่สร้างเมื่อ ${fmtDateTime(b.created_at)} ขนาด ${size(b.size)} ถาวร? กู้คืนจากไฟล์นี้ไม่ได้อีก`, {
            title: 'ลบแบ็คอัพ', okText: 'ลบถาวร', danger: true,
        });
        if (!ok) return;
        try {
            await api.post(`/admin/backups/${encodeURIComponent(b.id)}/delete`);
            await load();
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
    }

    container.innerHTML = '<div class="muted">กำลังโหลด...</div>';
    await load();
}
