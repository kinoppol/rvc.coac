import { api } from '../api.js';
import { money, count, escapeHtml, fmtDate } from '../util.js';

export async function render(container, { navigate, permissions }) {
    let showForm = false;
    let showImport = false;
    let q = '';
    let notice = null;
    const imp = { q: '', type: '', rows: null, selected: new Set(), error: null, busy: false };

    async function load() {
        const res = await api.get('/consignors' + (q ? `?q=${encodeURIComponent(q)}` : ''));
        draw(res.data);
    }

    function draw(rows) {
        container.innerHTML = `
        <div class="actions-row" style="justify-content:space-between">
          <div class="actions-row">
            <div class="search-box" style="max-width:320px">
              🔍 <input id="q" placeholder="ค้นหาชื่อ / รหัสผู้ฝาก / เบอร์โทร" value="${escapeHtml(q)}">
            </div>
            <span class="muted" style="font-size:13px">พบ ${count(rows.length)} ราย</span>
          </div>
          ${permissions.includes('consignors.create') ? `<div class="actions-row">
            <button type="button" class="btn secondary" id="toggle-import">ดึงจากรายชื่อบุคลากร / นักเรียน</button>
            <button type="button" class="btn" id="toggle-form">+ สมัครผู้ฝากขายใหม่</button>
          </div>` : ''}
        </div>

        ${notice ? (notice.ok
            ? `<div style="background:var(--ok-bg);color:var(--ok);border-radius:9px;padding:10px 14px;font-size:13.5px;margin-bottom:14px">${escapeHtml(notice.text)}</div>`
            : `<div class="error-box" style="margin-bottom:14px">${escapeHtml(notice.text)}</div>`) : ''}
        ${showImport ? importHtml() : ''}
        ${showForm ? formHtml() : ''}

        <section class="card">
          <div class="table-wrap"><table>
            <thead><tr>
              <th>รหัส</th><th>ชื่อผู้ฝากขาย</th><th>ประเภท</th>
              <th class="num">รายการ</th><th class="num">ขายสะสม</th><th class="num">เงินคงเหลือ</th><th>สถานะ</th><th></th>
            </tr></thead>
            <tbody>
              ${rows.map((c) => `
                <tr>
                  <td style="font-family:ui-monospace,Menlo,monospace" class="muted">${escapeHtml(c.code)}</td>
                  <td><div style="font-weight:600">${escapeHtml(c.name)}</div><div class="muted" style="font-size:12px">${escapeHtml(c.phone || '')} · สมัคร ${fmtDate(c.created_at)}</div></td>
                  <td class="muted">${escapeHtml(c.type)}</td>
                  <td class="num">${count(c.item_count)}</td>
                  <td class="num">${money(c.sold_total)}</td>
                  <td class="num" style="font-weight:600">${money(c.balance)}</td>
                  <td><span class="pill ${c.status === 'active' ? 'ok' : 'warn'}">${c.status === 'active' ? 'ปกติ' : 'รอตรวจสอบเอกสาร'}</span></td>
                  <td style="text-align:right"><button type="button" class="btn secondary sm" data-passbook="${c.id}">สมุดบัญชี</button></td>
                </tr>
              `).join('') || `<tr><td colspan="8" class="empty">ยังไม่มีผู้ฝากขาย</td></tr>`}
            </tbody>
          </table></div>
        </section>
      `;

        container.querySelector('#q').addEventListener('input', (e) => {
            q = e.target.value;
            clearTimeout(container._t);
            container._t = setTimeout(load, 250);
        });
        const toggleBtn = container.querySelector('#toggle-form');
        if (toggleBtn) toggleBtn.addEventListener('click', () => { showForm = !showForm; showImport = false; notice = null; load(); });
        const importBtn = container.querySelector('#toggle-import');
        if (importBtn) importBtn.addEventListener('click', () => {
            showImport = !showImport;
            showForm = false;
            notice = null;
            if (showImport && imp.rows === null) searchDirectory();
            else load();
        });
        container.querySelectorAll('[data-passbook]').forEach((btn) => {
            btn.addEventListener('click', () => navigate('passbook', { id: btn.dataset.passbook }));
        });
        if (showForm) wireForm();
        if (showImport) wireImport();
    }

    const TYPE_LABEL = { staff: 'ครู / บุคลากร', student: 'นักเรียน / นักศึกษา' };

    function importHtml() {
        const rows = imp.rows || [];
        const pickable = rows.filter((r) => !r.consignor_code);
        const allPicked = pickable.length > 0 && pickable.every((r) => imp.selected.has(Number(r.oa_user_id)));
        return `
        <section class="card" style="border-color:var(--priBorder)">
          <div class="card-pad" style="background:var(--soft);border-bottom:1px solid var(--priBorder)">
            <h2>ดึงรายชื่อบุคลากร / นักเรียน มาเป็นผู้ฝากขาย</h2>
            <p class="muted" style="margin:3px 0 0;font-size:12.5px">รายชื่อมาจาก RVC-ONE ผู้ที่ถูกเลือกจะได้รหัสผู้ฝากขาย และเข้าสู่ระบบผ่าน RVC-ONE เพื่อดูสมุดบัญชีและแจ้งฝากสินค้าได้เอง</p>
          </div>
          <div class="card-pad" style="display:flex;flex-direction:column;gap:12px">
            <form id="dir-search" class="actions-row">
              <div class="search-box" style="max-width:320px">
                🔍 <input name="q" placeholder="ค้นหาชื่อ แผนก อีเมล หรือ RVC-ONE ID" value="${escapeHtml(imp.q)}">
              </div>
              <select name="type" style="width:auto">
                <option value="">ทุกประเภท</option>
                <option value="staff" ${imp.type === 'staff' ? 'selected' : ''}>ครู / บุคลากร</option>
                <option value="student" ${imp.type === 'student' ? 'selected' : ''}>นักเรียน / นักศึกษา</option>
              </select>
              <button type="submit" class="btn sm">ค้นหา</button>
            </form>
            ${imp.error ? `<div class="error-box">${escapeHtml(imp.error)}</div>` : ''}
            ${imp.rows === null ? '<div class="empty">กำลังโหลด…</div>' : rows.length ? `
            <div class="table-wrap" style="max-height:420px;overflow:auto"><table>
              <thead><tr>
                <th style="width:36px"><input type="checkbox" id="dir-all" ${allPicked ? 'checked' : ''} ${pickable.length ? '' : 'disabled'}></th>
                <th>ชื่อ-นามสกุล</th><th>ประเภท</th><th>แผนก / ชั้นปี</th><th>สถานะ</th>
              </tr></thead>
              <tbody>
                ${rows.map((r) => `
                  <tr>
                    <td><input type="checkbox" data-pick="${r.oa_user_id}" ${r.consignor_code ? 'disabled' : ''} ${imp.selected.has(Number(r.oa_user_id)) ? 'checked' : ''}></td>
                    <td><div style="font-weight:600">${escapeHtml(`${r.first_name} ${r.last_name}`)}</div><div class="muted" style="font-size:12px">RVC-ONE ID ${r.oa_user_id}${r.username ? ` · ${escapeHtml(r.username)}` : ''}</div></td>
                    <td class="muted">${escapeHtml(TYPE_LABEL[r.user_type] || r.user_type || '-')}</td>
                    <td class="muted">${escapeHtml(r.department || '-')}</td>
                    <td>${r.consignor_code ? `<span class="pill ok">เป็นผู้ฝากขายแล้ว (${escapeHtml(r.consignor_code)})</span>` : '<span class="pill muted">ยังไม่เป็นผู้ฝากขาย</span>'}</td>
                  </tr>`).join('')}
              </tbody>
            </table></div>
            ${rows.length >= 100 ? '<div class="muted" style="font-size:12.5px">แสดง 100 รายชื่อแรก — พิมพ์ค้นหาให้แคบลงเพื่อหาคนที่ต้องการ</div>' : ''}`
            : '<div class="empty">ไม่พบรายชื่อ — หากยังไม่มีข้อมูล ให้ผู้ดูแลระบบซิงค์รายชื่อจาก RVC-ONE ก่อน</div>'}
            <div class="actions-row">
              <button type="button" class="btn secondary" id="cancel-import">ปิด</button>
              <button type="button" class="btn" id="do-import" ${imp.selected.size && !imp.busy ? '' : 'disabled'}>${imp.busy ? 'กำลังเพิ่ม…' : `เพิ่มเป็นผู้ฝากขาย (${count(imp.selected.size)} คน)`}</button>
            </div>
          </div>
        </section>`;
    }

    async function searchDirectory() {
        imp.error = null;
        try {
            const params = new URLSearchParams();
            if (imp.q) params.set('q', imp.q);
            if (imp.type) params.set('type', imp.type);
            imp.rows = (await api.get('/consignors/directory?' + params)).data;
        } catch (err) {
            imp.rows = [];
            imp.error = err.message;
        }
        await load();
    }

    function wireImport() {
        container.querySelector('#cancel-import').addEventListener('click', () => { showImport = false; load(); });
        container.querySelector('#dir-search').addEventListener('submit', (e) => {
            e.preventDefault();
            const fd = new FormData(e.target);
            imp.q = String(fd.get('q') || '').trim();
            imp.type = String(fd.get('type') || '');
            searchDirectory();
        });
        const refreshButton = () => {
            const btn = container.querySelector('#do-import');
            btn.disabled = !imp.selected.size;
            btn.textContent = `เพิ่มเป็นผู้ฝากขาย (${count(imp.selected.size)} คน)`;
        };
        const pick = (cb) => {
            const id = Number(cb.dataset.pick);
            if (cb.checked) imp.selected.add(id); else imp.selected.delete(id);
        };
        container.querySelectorAll('[data-pick]').forEach((cb) => cb.addEventListener('change', () => { pick(cb); refreshButton(); }));
        container.querySelector('#dir-all')?.addEventListener('change', (e) => {
            container.querySelectorAll('[data-pick]:not(:disabled)').forEach((cb) => { cb.checked = e.target.checked; pick(cb); });
            refreshButton();
        });
        container.querySelector('#do-import').addEventListener('click', async () => {
            imp.busy = true;
            await load();
            try {
                const res = await api.post('/consignors/from-oa', { oa_user_ids: [...imp.selected] });
                const created = res.data.filter((r) => r.status === 'created');
                const existed = res.data.filter((r) => r.status === 'exists');
                const failed = res.data.filter((r) => r.status === 'error');
                const parts = [`เพิ่มผู้ฝากขายแล้ว ${count(created.length)} คน`];
                if (existed.length) parts.push(`เป็นผู้ฝากขายอยู่แล้ว ${count(existed.length)} คน`);
                if (failed.length) parts.push('ไม่สำเร็จ: ' + failed.map((r) => `${r.name || 'ID ' + r.oa_user_id} (${r.error})`).join(', '));
                notice = { ok: !failed.length, text: parts.join(' · ') };
                imp.selected.clear();
                imp.busy = false;
                await searchDirectory();
            } catch (err) {
                imp.busy = false;
                imp.error = err.message;
                await load();
            }
        });
    }

    function formHtml() {
        return `
        <section class="card" style="border-color:var(--priBorder)">
          <div class="card-pad" style="background:var(--soft);border-bottom:1px solid var(--priBorder)">
            <h2>ใบสมัครเป็นผู้ฝากขาย</h2>
            <p class="muted" style="margin:3px 0 0;font-size:12.5px">กรอกช่องที่มีเครื่องหมาย <span style="color:var(--bad)">*</span> ให้ครบ ระบบจะออกรหัสผู้ฝากขายให้อัตโนมัติ</p>
          </div>
          <form id="consignor-form" class="card-pad">
            <div id="form-error"></div>
            <div class="form-grid" style="margin-bottom:16px">
              <label class="field">ชื่อ-นามสกุล *<input name="name" required placeholder="เช่น นางสาวกมลวรรณ ศรีสุข"></label>
              <label class="field">ประเภทผู้ฝาก *
                <select name="type" required>
                  <option>นักเรียน / นักศึกษา</option><option>ครู / บุคลากร</option>
                  <option>ผู้ปกครอง</option><option>ชุมชน / ร้านค้าภายนอก</option>
                </select>
              </label>
              <label class="field">แผนก / ชั้นปี <input name="department" placeholder="เช่น ปวช.2 การบัญชี"></label>
              <label class="field">เบอร์โทรศัพท์ *<input name="phone" required placeholder="08X-XXX-XXXX"></label>
              <label class="field">เลขบัตรประชาชน <input name="id_card" placeholder="X-XXXX-XXXXX-XX-X"></label>
              <label class="field">ช่องทางรับเงิน *
                <select name="pay_channel" required>
                  <option>เงินสดที่เคาน์เตอร์</option><option>พร้อมเพย์ (เบอร์โทร)</option><option>โอนธนาคาร</option>
                </select>
              </label>
              <label class="field">เลขบัญชี / พร้อมเพย์ <input name="pay_account" placeholder="กรอกเมื่อเลือกรับเงินแบบโอน"></label>
              <label class="field">ครูที่ปรึกษา / ผู้รับรอง <input name="advisor_teacher" placeholder="กรณีผู้ฝากเป็นนักเรียน"></label>
            </div>
            <div class="actions-row">
              <button type="button" class="btn secondary" id="cancel-form">ยกเลิก</button>
              <button type="submit" class="btn">บันทึกและออกรหัสผู้ฝาก</button>
            </div>
          </form>
        </section>`;
    }

    function wireForm() {
        container.querySelector('#cancel-form').addEventListener('click', () => { showForm = false; load(); });
        container.querySelector('#consignor-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(e.target);
            const body = Object.fromEntries(fd.entries());
            try {
                await api.post('/consignors', body);
                showForm = false;
                await load();
            } catch (err) {
                container.querySelector('#form-error').innerHTML = `<div class="error-box" style="margin-bottom:14px">${escapeHtml(err.message)}</div>`;
            }
        });
    }

    await load();
}
