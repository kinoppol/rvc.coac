import { api } from '../api.js';
import { count, escapeHtml, fmtDateTime } from '../util.js';
import { alertDialog, confirmDialog } from '../dialog.js';

const STATUS = { on: ['ok', 'เปิดรับคำตอบ'], upcoming: ['warn', 'ยังไม่ถึงวันเริ่ม'], ended: ['', 'หมดเขตแล้ว'], off: ['', 'ปิดอยู่'] };

function inputValue(v) {
    return v ? v.slice(0, 16).replace(' ', 'T') : '';
}

/**
 * แบบสอบถามรับแต้ม: short questionnaires a member answers at the POS (the cashier names the buyer and
 * hands them the screen); once answered they get the survey's points. One answer per member.
 */
export async function render(container) {
    let list = [];
    let canManage = false;
    let kinds = {};

    async function load() {
        const res = await api.get('/surveys');
        if (!res.ready) {
            container.innerHTML = '<div class="card card-pad"><div class="error-box">ยังใช้แบบสอบถามไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0088) ก่อน</div></div>';
            return;
        }
        list = res.data;
        canManage = res.can_manage;
        kinds = res.kinds;
        draw();
    }

    function draw() {
        container.innerHTML = `
          <div class="card"><div class="card-pad" style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap">
            <div style="flex:1;min-width:260px"><h2 style="margin:0 0 4px;font-size:17px">แบบสอบถามรับแต้มพิเศษ</h2>
              <div class="muted" style="font-size:13px">ทำแบบสอบถามสั้น ๆ ให้สมาชิกตอบที่หน้า POS: ระบุสมาชิกแล้วกดปุ่ม "📝 แบบสอบถาม" ให้ลูกค้าตอบบนจอ
                ตอบครบได้แต้มทันทีตามที่กำหนด · สมาชิก 1 คนตอบได้ 1 ครั้งต่อแบบสอบถาม · ดูสรุปคำตอบได้ที่ปุ่ม "ผลสำรวจ"</div></div>
            ${canManage ? '<button type="button" class="btn" data-new>+ สร้างแบบสอบถาม</button>' : ''}
          </div></div>
          <div class="card" style="margin-top:12px">${list.length ? `<div class="table-wrap"><table>
            <thead><tr><th>แบบสอบถาม</th><th class="num">คำถาม</th><th class="num">แต้มที่ได้</th><th>ช่วงเวลา</th><th class="num">ตอบแล้ว</th><th></th></tr></thead>
            <tbody>${list.map((s) => {
                const [tone, label] = STATUS[s.status] || STATUS.off;
                return `<tr><td><b>${escapeHtml(s.title)}</b> <span class="pill ${tone}">${label}</span>
                    ${s.description ? `<div class="muted" style="font-size:12px">${escapeHtml(s.description)}</div>` : ''}</td>
                  <td class="num">${s.questions.length}</td><td class="num"><b>+${count(s.points)}</b></td>
                  <td style="font-size:13px;white-space:nowrap">${s.starts_on ? escapeHtml(fmtDateTime(s.starts_on)) : 'ทันที'}<br>ถึง ${s.ends_on ? escapeHtml(fmtDateTime(s.ends_on)) : 'ไม่กำหนด'}</td>
                  <td class="num">${count(s.responses)} คน<br><span class="muted" style="font-size:12px">ให้ ${count(s.points_given)} แต้ม</span></td>
                  <td style="white-space:nowrap"><button type="button" class="btn secondary sm" data-results="${s.id}">ผลสำรวจ</button>
                    ${canManage ? `<button type="button" class="btn secondary sm" data-edit="${s.id}">แก้ไข</button>
                    <button type="button" class="btn secondary sm" data-toggle="${s.id}">${s.active ? 'ปิด' : 'เปิด'}</button>
                    ${s.responses ? '' : `<button type="button" class="btn secondary sm" data-del="${s.id}" style="color:var(--bad)">ลบ</button>`}` : ''}</td></tr>`;
            }).join('')}</tbody></table></div>` : `<div class="card-pad empty">ยังไม่มีแบบสอบถาม${canManage ? ' — กด "+ สร้างแบบสอบถาม" เพื่อเริ่ม' : ''}</div>`}</div>`;
    }

    container.addEventListener('click', async (e) => {
        const t = e.target.closest('button');
        if (!t) return;
        if (t.dataset.new !== undefined) return editor(null);
        const id = Number(t.dataset.edit || t.dataset.toggle || t.dataset.del || t.dataset.results);
        const s = list.find((x) => x.id === id);
        if (!s) return;
        try {
            if (t.dataset.edit) editor(s);
            else if (t.dataset.results) results(s);
            else if (t.dataset.toggle) { await api.post(`/surveys/${id}/active`, { active: !s.active }); await load(); }
            else if (t.dataset.del && await confirmDialog(`ลบแบบสอบถาม "${s.title}"?`, { title: 'ลบแบบสอบถาม', okText: 'ลบ', danger: true })) {
                await api.post(`/surveys/${id}/delete`, {});
                await load();
            }
        } catch (err) {
            alertDialog(err.message, { tone: 'error' });
        }
    });

    function modal(width) {
        const box = document.createElement('div');
        box.className = 'modal-backdrop';
        document.body.appendChild(box);
        const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); close(); } };
        const close = () => { box.remove(); document.removeEventListener('keydown', onKey, true); };
        document.addEventListener('keydown', onKey, true);
        box.dataset.width = width;
        return [box, close];
    }

    function editor(src) {
        const f = src ? {
            title: src.title, description: src.description || '', points: src.points, starts_on: inputValue(src.starts_on), ends_on: inputValue(src.ends_on), active: src.active,
            questions: src.questions.map((q) => ({ kind: q.kind, label: q.label, options: [...q.options], required: q.required })),
        } : {
            title: '', description: '', points: 5, starts_on: '', ends_on: '', active: true,
            questions: [{ kind: 'rating', label: 'ความพึงพอใจต่อร้านค้าสหการ', options: [], required: true }],
        };
        const locked = src && src.responses > 0;
        let error = null;
        let busy = false;
        const [box, close] = modal(760);

        function draw() {
            const scrolled = box.querySelector('.modal-body')?.scrollTop || 0;
            box.innerHTML = `<div class="modal" role="dialog" aria-modal="true" style="width:760px">
              <div class="modal-head"><h2>${src ? 'แก้ไขแบบสอบถาม' : 'สร้างแบบสอบถามรับแต้ม'}</h2><button type="button" class="modal-close" data-close aria-label="ปิด">✕</button></div>
              <div class="modal-body" style="padding-top:12px">
                <label class="field"><span>ชื่อแบบสอบถาม</span><input type="text" data-f="title" maxlength="200" value="${escapeHtml(f.title)}" placeholder="เช่น ความพึงพอใจต่อสินค้าและบริการ"></label>
                <label class="field" style="margin-top:8px"><span>คำอธิบาย (แสดงให้ผู้ตอบเห็น)</span><input type="text" data-f="description" maxlength="1000" value="${escapeHtml(f.description)}"></label>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;margin-top:8px">
                  <label class="field"><span>แต้มที่ได้เมื่อตอบครบ</span><input type="number" min="0" step="1" data-f="points" value="${escapeHtml(String(f.points))}"></label>
                  <label class="field"><span>เริ่ม (เว้นว่าง = ทันที)</span><input type="datetime-local" data-f="starts_on" value="${escapeHtml(f.starts_on)}"></label>
                  <label class="field"><span>สิ้นสุด (เว้นว่าง = ไม่กำหนด)</span><input type="datetime-local" data-f="ends_on" value="${escapeHtml(f.ends_on)}"></label>
                </div>
                <h3 style="margin:16px 0 4px;font-size:14.5px">คำถาม</h3>
                ${locked ? '<div class="muted" style="font-size:12.5px">มีผู้ตอบแล้ว: แก้ไขข้อความได้ แต่เพิ่ม/ลบคำถามไม่ได้</div>' : ''}
                ${f.questions.map((q, i) => `
                  <div style="border:1px solid var(--border);border-radius:10px;padding:10px;margin-top:8px">
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
                      <label class="field" style="flex:1;min-width:220px"><span>ข้อ ${i + 1}</span><input type="text" data-q="${i}" data-k="label" maxlength="500" value="${escapeHtml(q.label)}"></label>
                      <label class="field"><span>รูปแบบ</span><select data-q="${i}" data-k="kind" ${locked ? 'disabled' : ''}>${Object.entries(kinds).map(([k, l]) => `<option value="${k}" ${q.kind === k ? 'selected' : ''}>${escapeHtml(l)}</option>`).join('')}</select></label>
                      <label class="pos-switch"><input type="checkbox" data-q="${i}" data-k="required" ${q.required ? 'checked' : ''}><span aria-hidden="true"></span> ต้องตอบ</label>
                      ${!locked && f.questions.length > 1 ? `<button type="button" class="btn secondary sm" data-qdel="${i}" style="color:var(--bad)">ลบ</button>` : ''}
                    </div>
                    ${q.kind === 'choice' || q.kind === 'multi' ? `<label class="field" style="margin-top:6px"><span>ตัวเลือก (บรรทัดละ 1 ข้อ)</span>
                      <textarea rows="3" data-q="${i}" data-k="options">${escapeHtml(q.options.join('\n'))}</textarea></label>` : ''}
                  </div>`).join('')}
                ${locked ? '' : '<button type="button" class="btn secondary sm" data-qadd style="margin-top:8px">+ เพิ่มคำถาม</button>'}
                <label class="pos-switch" style="margin-top:12px"><input type="checkbox" data-f="active" ${f.active ? 'checked' : ''}><span aria-hidden="true"></span> เปิดรับคำตอบ</label>
                ${error ? `<div class="error-box" style="margin-top:8px">${escapeHtml(error)}</div>` : ''}
              </div>
              <div class="modal-foot"><button type="button" class="btn secondary" data-close>ยกเลิก</button>
                <button type="button" class="btn" data-save ${busy ? 'disabled' : ''}>${busy ? 'กำลังบันทึก...' : 'บันทึก'}</button></div></div>`;
            box.querySelector('.modal-body').scrollTop = scrolled;
        }

        box.addEventListener('input', (e) => {
            const t = e.target;
            if (t.dataset.f && t.type !== 'checkbox') f[t.dataset.f] = t.value;
            if (t.dataset.q !== undefined && t.dataset.k === 'label') f.questions[Number(t.dataset.q)].label = t.value;
            if (t.dataset.q !== undefined && t.dataset.k === 'options') f.questions[Number(t.dataset.q)].options = t.value.split('\n');
        });
        box.addEventListener('change', (e) => {
            const t = e.target;
            if (t.dataset.f === 'active') f.active = t.checked;
            if (t.dataset.k === 'required') f.questions[Number(t.dataset.q)].required = t.checked;
            if (t.dataset.k === 'kind') { f.questions[Number(t.dataset.q)].kind = t.value; draw(); }
        });
        box.addEventListener('click', async (e) => {
            const t = e.target.closest('button');
            if (!t) return;
            if (t.dataset.close !== undefined) return close();
            if (t.dataset.qadd !== undefined) { f.questions.push({ kind: 'choice', label: '', options: ['', ''], required: true }); draw(); return; }
            if (t.dataset.qdel !== undefined) { f.questions.splice(Number(t.dataset.qdel), 1); draw(); return; }
            if (t.dataset.save === undefined || busy) return;
            busy = true;
            error = null;
            draw();
            const body = { ...f, points: Number(f.points), questions: f.questions.map((q) => ({ ...q, options: q.options.map((o) => o.trim()).filter(Boolean) })) };
            try {
                if (src) await api.put(`/surveys/${src.id}`, body);
                else await api.post('/surveys', body);
                close();
                await load();
            } catch (err) {
                busy = false;
                error = err.message;
                draw();
            }
        });
        draw();
    }

    async function results(s) {
        const [box, close] = modal(760);
        box.innerHTML = '<div class="modal" style="width:760px"><div class="modal-body muted">กำลังโหลด...</div></div>';
        let r;
        try {
            r = (await api.get(`/surveys/${s.id}/results`)).data;
        } catch (err) {
            close();
            alertDialog(err.message, { tone: 'error' });
            return;
        }
        const bar = (n, total) => `<span style="display:inline-block;vertical-align:middle;width:160px;height:10px;background:var(--surface2);border-radius:4px"><span style="display:block;height:10px;border-radius:4px;background:var(--pri);width:${total ? (n / total) * 100 : 0}%"></span></span>`;
        box.innerHTML = `<div class="modal" role="dialog" aria-modal="true" style="width:760px">
          <div class="modal-head"><h2>ผลสำรวจ: ${escapeHtml(r.title)}</h2><button type="button" class="modal-close" data-close aria-label="ปิด">✕</button></div>
          <div class="modal-body">
            <div class="muted" style="font-size:13px">ผู้ตอบ ${count(r.responses)} คน · นักเรียน/นักศึกษา ${count(r.by_type.student)} · บุคลากร ${count(r.by_type.staff)}</div>
            ${r.questions.map((q, i) => `<div style="border-top:1px solid var(--border);margin-top:12px;padding-top:10px">
              <b>${i + 1}. ${escapeHtml(q.label)}</b> <span class="muted" style="font-size:12px">(ตอบ ${count(q.answered)} คน)</span>
              ${q.kind === 'rating' ? `<div style="margin-top:4px">คะแนนเฉลี่ย <b style="font-size:18px">${q.average ?? '-'}</b> / 5</div>
                ${[5, 4, 3, 2, 1].map((n) => `<div style="font-size:12.5px">${'★'.repeat(n)}${'☆'.repeat(5 - n)} ${bar(q.rating_counts[n] || 0, q.answered)} ${count(q.rating_counts[n] || 0)}</div>`).join('')}`
                : q.kind === 'text' ? `<div style="max-height:200px;overflow:auto;margin-top:4px">${q.texts.map((x) => `<div style="font-size:13px;padding:3px 0;border-bottom:1px dashed var(--border)">${escapeHtml(x.text)}</div>`).join('') || '<span class="muted">-</span>'}</div>`
                : Object.entries(q.counts).map(([o, n]) => `<div style="font-size:12.5px;margin-top:3px">${bar(n, q.answered)} <b>${count(n)}</b> ${escapeHtml(o)}</div>`).join('')}
            </div>`).join('')}
          </div>
          <div class="modal-foot"><button type="button" class="btn secondary" data-close>ปิด</button></div></div>`;
        box.addEventListener('click', (e) => { if (e.target.closest('[data-close]')) close(); });
    }

    await load();
}
