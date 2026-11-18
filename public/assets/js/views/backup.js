import { api, getCsrfToken } from '../api.js';
import { escapeHtml, count, fmtDate, fmtDateTime } from '../util.js';
import { confirmDialog, alertDialog, promptDialog, choiceDialog } from '../dialog.js';

// แบ็คอัพและกู้คืนข้อมูล (admins): images, the database and the other uploaded files, each for a
// period (today, yesterday, this / last week, this / last month) or all of it; download a backup
// to keep it off this server, put one back, or delete it. See BackupService.
// ชุดสำรองครบ: one .zip with two files inside — database.sql (the data as text) and files.zip
// (pictures and every other uploaded file). A backup file kept elsewhere is brought back by
// upload: the whole .zip, or one part on its own; it is then restored like any other.

const KIND_HINT = {
    bundle: 'ไฟล์ .zip ไฟล์เดียว ข้างในมี 2 ไฟล์: database.sql (ข้อมูลทั้งหมดของระบบเป็นไฟล์ข้อความ) และ files.zip (รูปภาพและไฟล์อื่นที่ไม่ใช่ข้อความทั้งหมดใน storage/uploads) — กู้คืนทั้งชุดหรือแยกส่วนได้',
    uploads: 'รูปภาพและไฟล์แนบที่นำเข้าจากไฟล์ .zip',
    images: 'รูปสินค้า รูปใบเสร็จ และรูปหลักฐานต่าง ๆ ใน storage/uploads (เลือกตามวันที่ไฟล์ถูกบันทึก)',
    database: 'ข้อมูลทั้งหมดของระบบ (ขาย สต๊อก ผู้ฝาก บัญชี ผู้ใช้ ...) — "ทั้งหมด" เก็บทุกตาราง, ช่วงเวลาเก็บเฉพาะรายการที่สร้าง/แก้ในช่วงนั้น',
    files: 'ไฟล์อื่นที่ไม่ใช่รูปใน storage/uploads เช่น ไฟล์ใบเสร็จแบบข้อความ / PDF (เลือกตามวันที่ไฟล์ถูกบันทึก)',
};
const KIND_ICON = { bundle: '📦', images: '🖼', database: '🗄', files: '📄', uploads: '🖼' };
const PART_LABEL = { database: 'ไฟล์ข้อมูล (database.sql)', files: 'รูปภาพ/ไฟล์ (files.zip)' };

const size = (b) => {
    if (b >= 1024 ** 3) return `${(b / 1024 ** 3).toLocaleString('th-TH', { maximumFractionDigits: 2 })} GB`;
    if (b >= 1024 ** 2) return `${(b / 1024 ** 2).toLocaleString('th-TH', { maximumFractionDigits: 1 })} MB`;
    return `${Math.max(1, Math.round(b / 1024)).toLocaleString('th-TH')} KB`;
};

export async function render(container) {
    let d = null;
    let error = null;
    let kind = 'bundle';
    let range = 'all';
    let busy = null; // what is running: 'create' | backup id
    let filter = 'all';
    let upload = null; // { name, done, total } while a backup file is being imported

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
    const rangeLabel = (b) => rangeOf(b.range)?.label || (b.range === 'custom' ? 'ช่วงที่กำหนด' : b.range);
    const partsOf = (b) => (b.kind === 'bundle' ? Object.keys(b.parts || { database: 1, files: 1 }) : []);
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
              <div class="bk-kinds">${(d.create_kinds || Object.keys(d.kinds)).map((k) => [k, d.kinds[k]]).map(([k, label]) => `
                <label class="bk-kind ${kind === k ? 'on' : ''}"><input type="radio" name="bk-kind" value="${k}" ${kind === k ? 'checked' : ''}>
                  <span><b>${KIND_ICON[k]} ${escapeHtml(label)}</b><span class="muted" style="display:block;font-size:12.5px">${escapeHtml(KIND_HINT[k])}</span></span></label>`).join('')}</div>
              <div style="font-weight:600;margin:14px 0 6px">ช่วงเวลาของข้อมูล</div>
              <div class="tabs" role="group" aria-label="ช่วงเวลา">${d.ranges.map((r) => `
                <button type="button" class="tab-btn ${range === r.key ? 'active' : ''}" data-range="${r.key}" title="${escapeHtml(rangeText(r))}">${escapeHtml(r.label)}</button>`).join('')}</div>
              <div class="muted" style="font-size:12.5px;margin-top:6px">${escapeHtml(rangeText(rangeOf(range)))}${(kind === 'database' || kind === 'bundle') && range !== 'all'
                  ? ' · เก็บรายการที่สร้างหรือแก้ไขในช่วงนี้ (ตารางที่ไม่มีวันที่ เช่น การตั้งค่า เก็บทั้งตาราง) — กู้คืนแล้วเขียนทับเฉพาะรายการเหล่านี้ ไม่ลบข้อมูลอื่น' : ''}</div>
              <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px">
                <button type="button" class="btn" id="bk-create" ${busy ? 'disabled' : ''}>${busy === 'create' ? 'กำลังแบ็คอัพ... (อาจใช้เวลาหลายนาที)' : `แบ็คอัพ${escapeHtml(d.kinds[kind])} · ${escapeHtml(rangeOf(range).label)}`}</button>
                ${d.disk_free != null ? `<span class="muted" style="font-size:12.5px">พื้นที่ดิสก์เหลือ ${size(d.disk_free)}</span>` : ''}
              </div>
            </section>

            <section class="card card-pad">
              <h2 style="margin-bottom:6px">นำเข้าไฟล์แบ็คอัพเพื่อกู้คืน</h2>
              <p class="muted" style="font-size:13px;margin:0 0 12px">เลือกไฟล์ที่ดาวน์โหลดเก็บไว้: <b>ชุดสำรองครบ (.zip)</b> ทั้งไฟล์ หรือแยกส่วน —
                <b>ไฟล์ข้อมูล</b> (database.sql หรือแบ็คอัพฐานข้อมูล .sql.gz) หรือ <b>รูปภาพ/ไฟล์</b> (files.zip หรือแบ็คอัพรูปภาพ/ไฟล์อื่น .zip)
                ระบบจะตรวจไฟล์แล้วเพิ่มเข้ารายการด้านล่าง จากนั้นเลือกกู้คืนได้ (ยังไม่เปลี่ยนข้อมูลจนกว่าจะยืนยันกู้คืน)</p>
              <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                <input type="file" id="bk-file" accept=".zip,.sql,.gz,application/zip,application/gzip,application/sql,text/plain" ${busy || upload ? 'disabled' : ''}>
                ${upload ? `<span style="font-size:13px">${upload.done >= upload.total ? 'กำลังตรวจไฟล์...' : `กำลังอัปโหลด ${escapeHtml(upload.name)} · ${Math.round((upload.done / upload.total) * 100)}% (${size(upload.done)} / ${size(upload.total)})`}</span>` : ''}
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
                  <td><b>${escapeHtml(rangeLabel(b))}</b><div class="muted" style="font-size:12px">${b.from ? escapeHtml(b.from === b.to ? fmtDate(b.from) : `${fmtDate(b.from)} – ${fmtDate(b.to)}`) : 'ข้อมูลทั้งหมด'}</div></td>
                  <td class="num">${b.kind === 'bundle' ? partsHtml(b) : b.items == null ? '-' : `${count(b.items)} ${b.kind === 'database' ? 'แถว' : 'ไฟล์'}`}${b.tables && b.kind !== 'bundle' ? `<div class="muted" style="font-size:12px">${count(b.tables)} ตาราง</div>` : ''}</td>
                  <td class="num">${size(b.size)}</td>
                  <td style="font-size:12.5px">${b.imported ? '<span class="pill muted">นำเข้า</span> ' : ''}${escapeHtml(b.created_by || '-')}${b.note ? `<div class="muted">${escapeHtml(b.note)}</div>` : ''}</td>
                  <td style="white-space:nowrap">
                    <a class="btn secondary sm" href="${api.downloadUrl(`/admin/backups/${encodeURIComponent(b.id)}/download`)}" download>ดาวน์โหลด${b.kind === 'bundle' ? 'ทั้งชุด' : ''}</a>
                    ${partsOf(b).map((pt) => `<a class="btn secondary sm" href="${api.downloadUrl(`/admin/backups/${encodeURIComponent(b.id)}/download?part=${pt}`)}" download title="ดาวน์โหลดเฉพาะ${PART_LABEL[pt]}">${pt === 'database' ? '.sql' : 'files.zip'}</a>`).join('')}
                    <button type="button" class="btn secondary sm" data-restore="${escapeHtml(b.id)}" ${busy ? 'disabled' : ''}>${busy === b.id ? 'กำลังกู้คืน...' : 'กู้คืน'}</button>
                    <button type="button" class="btn danger sm" data-delete="${escapeHtml(b.id)}" ${busy ? 'disabled' : ''}>ลบ</button>
                  </td></tr>`).join('')}</tbody>
              </table></div>` : '<div class="empty" style="padding:18px">ยังไม่มีแบ็คอัพ</div>'}
            </section>
          </div>`;
        bind();
    }

    /** A bundle's two parts: rows / tables of the data file, files in files.zip. */
    function partsHtml(b) {
        const pt = b.parts || {};
        const lines = [];
        if (pt.database) lines.push(`🗄 ${pt.database.rows != null ? `${count(pt.database.rows)} แถว` : 'ไฟล์ข้อมูล'}${pt.database.tables ? ` · ${count(pt.database.tables)} ตาราง` : ''}`);
        if (pt.files) lines.push(`🖼 ${pt.files.items != null ? `${count(pt.files.items)} ไฟล์` : 'รูปภาพ/ไฟล์'}`);
        return lines.map((l) => `<div style="white-space:nowrap;font-size:12.5px">${l}</div>`).join('') || '-';
    }

    function bind() {
        container.querySelectorAll('[name="bk-kind"]').forEach((r) => r.addEventListener('change', () => { kind = r.value; draw(); }));
        container.querySelectorAll('[data-range]').forEach((b) => b.addEventListener('click', () => { range = b.dataset.range; draw(); }));
        container.querySelectorAll('[data-filter]').forEach((b) => b.addEventListener('click', () => { filter = b.dataset.filter; draw(); }));
        container.querySelector('#bk-create').addEventListener('click', create);
        container.querySelector('#bk-file')?.addEventListener('change', (e) => { const f = e.target.files[0]; if (f) importFile(f); });
        container.querySelectorAll('[data-restore]').forEach((b) => b.addEventListener('click', () => restore(d.backups.find((x) => x.id === b.dataset.restore))));
        container.querySelectorAll('[data-delete]').forEach((b) => b.addEventListener('click', () => remove(d.backups.find((x) => x.id === b.dataset.delete))));
    }

    /** Uploads a backup file in pieces, has the server check it, then offers to restore it. */
    async function importFile(file) {
        if (!/\.(zip|sql|gz)$/i.test(file.name)) {
            alertDialog('รับเฉพาะไฟล์ .zip, .sql หรือ .sql.gz ที่ได้จากระบบนี้', { tone: 'error' });
            draw();
            return;
        }
        const CHUNK = d.chunk_size || 1048576;
        const id = [...crypto.getRandomValues(new Uint8Array(16))].map((x) => x.toString(16).padStart(2, '0')).join('');
        upload = { name: file.name, done: 0, total: file.size };
        draw();
        let m = null;
        try {
            for (let i = 0, off = 0; off < file.size; i++, off += CHUNK) {
                const res = await fetch(api.downloadUrl(`/admin/backups/import/chunk?id=${id}&index=${i}`), {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/octet-stream', 'X-CSRF-Token': getCsrfToken() || '' },
                    body: file.slice(off, off + CHUNK),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(data.error || `อัปโหลดไม่สำเร็จ (${res.status})`);
                upload.done = Math.min(file.size, off + CHUNK);
                draw();
            }
            m = (await api.post('/admin/backups/import/finish', { upload_id: id, size: file.size, name: file.name })).data;
        } catch (e) {
            upload = null;
            draw();
            alertDialog(e.message, { title: 'นำเข้าไฟล์ไม่สำเร็จ', tone: 'error' });
            return;
        }
        upload = null;
        await load();
        const what = m.kind === 'bundle'
            ? `ชุดสำรองครบ มี ${partsOf(m).map((pt) => PART_LABEL[pt]).join(' และ ')}`
            : m.kind === 'database' ? `ไฟล์ข้อมูล (ฐานข้อมูล${m.range === 'all' ? 'ทั้งหมด' : ` ${fmtDate(m.from)} – ${fmtDate(m.to)}`})` : `รูปภาพ/ไฟล์ ${count(m.items)} ไฟล์`;
        const now = await confirmDialog(`นำเข้า ${file.name} แล้ว: ${what} · ${size(m.size)}\nไฟล์อยู่ในรายการแบ็คอัพแล้ว ต้องการกู้คืนตอนนี้เลยหรือไม่?`, {
            title: 'นำเข้าไฟล์แบ็คอัพแล้ว', okText: 'กู้คืนตอนนี้', cancelText: 'ไว้ภายหลัง',
        });
        if (now) restore(d.backups.find((x) => x.id === m.id) || m);
    }

    async function create() {
        busy = 'create';
        draw();
        try {
            const m = (await api.post('/admin/backups', { kind, range })).data;
            await load();
    alertDialog(m.kind === 'bundle'
                ? `สร้างชุดสำรองครบ (${rangeOf(m.range).label}) แล้ว: ข้อมูล ${count(m.parts.database.rows)} แถว + รูปภาพ/ไฟล์ ${count(m.parts.files.items)} ไฟล์ · ${size(m.size)}`
                : `แบ็คอัพ${d.kinds[m.kind]} (${rangeOf(m.range).label}) แล้ว: ${count(m.items)} ${m.kind === 'database' ? 'แถว' : 'ไฟล์'} · ${size(m.size)}`, { title: 'แบ็คอัพเสร็จแล้ว' });
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        } finally {
            busy = null;
            draw();
        }
    }

    async function restore(b) {
        if (!b) return;
        // A bundle: both parts, or one of them.
        let parts = null;
        if (b.kind === 'bundle') {
            const have = partsOf(b);
            const choices = [
                ...(have.length > 1 ? [{ key: 'both', label: 'ทั้งชุด: ข้อมูล + รูปภาพ/ไฟล์' }] : []),
                ...have.map((pt) => ({ key: pt, label: `เฉพาะ${PART_LABEL[pt]}` })),
            ];
            const pick = await choiceDialog(`${b.note || b.id}\nเลือกส่วนที่จะกู้คืน`, choices, { title: 'กู้คืนชุดสำรองครบ' });
            if (!pick) return;
            parts = pick === 'both' ? have : [pick];
        }
        const hasDb = b.kind === 'database' || (parts || []).includes('database');
        const hasFiles = b.kind !== 'database' && (b.kind !== 'bundle' || parts.includes('files'));
        const full = hasDb && b.range === 'all';
        const period = b.from ? (b.from === b.to ? fmtDate(b.from) : `${fmtDate(b.from)} – ${fmtDate(b.to)}`) : '';
        const dbWhat = full
            ? `ฐานข้อมูลทั้งหมดจะถูกแทนที่ด้วยข้อมูล ณ ${fmtDateTime(b.created_at)}${b.imported ? ' (ไฟล์ที่นำเข้า)' : ''} — รายการที่บันทึกหลังจากนั้น (ขาย รับเข้า บัญชี ...) จะหายไปจากระบบ`
            : `รายการที่สร้าง/แก้ไขช่วง ${rangeLabel(b)} (${period}) จะถูกเขียนทับด้วยค่าในแบ็คอัพ ข้อมูลอื่นไม่ถูกลบ`;
        const what = [
            hasDb ? `${dbWhat} · ระบบจะแบ็คอัพฐานข้อมูลทั้งหมดปัจจุบันให้อัตโนมัติก่อน เพื่อย้อนกลับได้` : '',
            hasFiles ? `รูปภาพ/ไฟล์ในแบ็คอัพจะถูกเขียนกลับไปที่ storage/uploads${b.kind !== 'bundle' && b.items != null ? ` (${count(b.items)} ไฟล์)` : ''}` : '',
        ].filter(Boolean).join('\n');
        let overwrite = false;
        if (hasFiles) {
            const ok = await confirmDialog(`${what}\n\nไฟล์ที่ยังมีอยู่ในระบบ: ข้ามไป (ปลอดภัย) หรือเขียนทับด้วยไฟล์ในแบ็คอัพ?`, {
                title: 'กู้คืนไฟล์', okText: 'เขียนทับไฟล์ที่ต่างกันด้วย', cancelText: 'กู้คืนเฉพาะไฟล์ที่หายไป',
            });
            overwrite = ok;
        }
        const typed = await promptDialog(`${what}\n\nพิมพ์คำว่า "กู้คืน" เพื่อยืนยัน`, {
            title: `กู้คืน${d.kinds[b.kind] || ''} · ${rangeLabel(b)}`, okText: 'กู้คืน', placeholder: 'กู้คืน', required: true, danger: full,
        });
        if (typed == null) return;
        busy = b.id;
        draw();
        try {
            const r = (await api.post(`/admin/backups/${encodeURIComponent(b.id)}/restore`, { confirm: typed, overwrite, ...(parts ? { parts } : {}) })).data;
            await load();
            const dbText = (n) => `กู้คืนฐานข้อมูลแล้ว (${count(n)} คำสั่ง) · แบ็คอัพก่อนกู้คืนเก็บไว้แล้ว (${size(r.safety.size)})`;
            const fileText = (x) => `กู้คืนรูปภาพ/ไฟล์แล้ว ${count(x.items)} ไฟล์${x.skipped ? ` · ข้าม ${count(x.skipped)} ไฟล์ (มีอยู่แล้วหรือไม่ปลอดภัย)` : ''}`;
            alertDialog(b.kind === 'bundle'
                ? [r.parts.database ? dbText(r.parts.database.items) : '', r.parts.files ? fileText(r.parts.files) : '', hasDb ? 'เปิดหน้าใหม่เพื่อดูข้อมูลล่าสุด' : ''].filter(Boolean).join('\n')
                : b.kind === 'database' ? `${dbText(r.items)} · เปิดหน้าใหม่เพื่อดูข้อมูลล่าสุด` : fileText(r), { title: 'กู้คืนเสร็จแล้ว' });
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        } finally {
            busy = null;
            draw();
        }
    }

    async function remove(b) {
        if (!b) return;
        const ok = await confirmDialog(`ลบแบ็คอัพ${d.kinds[b.kind] || ''} (${rangeLabel(b)}) ที่สร้างเมื่อ ${fmtDateTime(b.created_at)} ขนาด ${size(b.size)} ถาวร? กู้คืนจากไฟล์นี้ไม่ได้อีก`, {
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
