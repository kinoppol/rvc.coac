import { api } from '../api.js';
import { money, count, escapeHtml, fmtDate } from '../util.js';

export async function render(container, { navigate, permissions }) {
    let showForm = false;
    let q = '';

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
          ${permissions.includes('consignors.create') ? `<button type="button" class="btn" id="toggle-form">+ สมัครผู้ฝากขายใหม่</button>` : ''}
        </div>

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
        if (toggleBtn) toggleBtn.addEventListener('click', () => { showForm = !showForm; load(); });
        container.querySelectorAll('[data-passbook]').forEach((btn) => {
            btn.addEventListener('click', () => navigate('passbook', { id: btn.dataset.passbook }));
        });
        if (showForm) wireForm();
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
