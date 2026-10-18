import { api } from '../api.js';
import { escapeHtml, fmtDateTime } from '../util.js';

const ROLE_LABEL = { admin: 'ผู้ดูแลระบบ', manager: 'ผู้จัดการร้าน', advisor: 'ครูที่ปรึกษา', cashier: 'พนักงานขาย (นักเรียน)', consignor: 'ผู้ฝากขายเท่านั้น' };
const METHOD_LABEL = { both: 'รหัสผ่าน และ RVC-ONE', sso: 'เฉพาะ RVC-ONE', password: 'เฉพาะรหัสผ่าน' };
const SYNC_TYPE_LABEL = { '': 'ครูและนักเรียนนักศึกษา', staff: 'เฉพาะครู/บุคลากร', student: 'เฉพาะนักเรียนนักศึกษา' };
const METHOD_HINT = {
    both: 'ผู้ใช้เลือกได้ว่าจะเข้าด้วยชื่อผู้ใช้/รหัสผ่าน หรือผ่าน RVC-ONE',
    sso: 'ผู้ใช้ทั่วไปเข้าได้ผ่าน RVC-ONE เท่านั้น — ผู้ดูแลระบบยังใช้รหัสผ่านได้จากลิงก์ «ผู้ดูแลระบบ: ลงชื่อเข้าใช้ด้วยรหัสผ่าน» ในหน้าเข้าสู่ระบบ (สำหรับกรณี RVC-ONE ล่ม)',
    password: 'ปิดการเข้าสู่ระบบผ่าน RVC-ONE ทุกคนต้องใช้ชื่อผู้ใช้และรหัสผ่าน',
};
const TYPE_LABEL = { staff: 'ครู/บุคลากร', student: 'นักเรียน' };

function roleSelect(name, value) {
    return `<select class="pc-select" data-${name}>${Object.entries(ROLE_LABEL).map(([k, v]) =>
        `<option value="${k}" ${k === value ? 'selected' : ''}>${escapeHtml(v)}</option>`).join('')}</select>`;
}

function messageBox(message) {
    return message ? `<div class="${message.ok ? 'pill ok' : 'error-box'}" style="align-self:flex-start">${escapeHtml(message.text)}</div>` : '';
}

/** Runs an action, then reloads the panel with a success or error message. */
function makeAct(setMessage, reload) {
    return async (fn, okText) => {
        try {
            await fn();
            setMessage(okText ? { ok: true, text: okText } : null);
        } catch (err) {
            setMessage({ ok: false, text: err.message });
        }
        await reload();
    };
}

/** "ผู้ใช้งาน" tab: local accounts + granting access to RVC-ONE users (admins only). */
export async function renderUsersPanel(el, { user: me }) {
    let message = null;
    let searchQ = '';
    let results = [];
    const act = makeAct((m) => { message = m; }, load);

    async function load() {
        draw((await api.get('/users')).data);
    }

    function draw(users) {
        el.innerHTML = `
        <section class="card">
          <div class="card-head"><h2>ผู้ใช้งานระบบ</h2></div>
          ${message ? `<div class="card-pad" style="display:flex;padding-bottom:0">${messageBox(message)}</div>` : ''}
          <div class="table-wrap"><table>
            <thead><tr><th>ชื่อ</th><th>ชื่อผู้ใช้</th><th>RVC-ONE</th><th>บทบาท</th><th>ผู้ฝากขาย</th><th>สถานะ</th><th>เข้าใช้ล่าสุด</th><th></th></tr></thead>
            <tbody>
              ${users.map((u) => {
                  const self = Number(u.id) === Number(me.id);
                  const suspended = u.oa_status === 'suspended';
                  return `
                <tr data-id="${u.id}">
                  <td>${escapeHtml(u.full_name)}</td>
                  <td class="muted">${escapeHtml(u.username)}</td>
                  <td style="font-size:12.5px">${u.oa_user_id
                      ? `ID ${u.oa_user_id}${u.oa_department ? ` · ${escapeHtml(u.oa_department)}` : ''} <button class="btn sm secondary" data-unlink>ยกเลิกผูก</button>`
                      : `<button class="btn sm secondary" data-link>ผูกบัญชี</button>`}</td>
                  <td>${self ? escapeHtml(ROLE_LABEL[u.role] || u.role) : roleSelect('role', u.role)}</td>
                  <td style="font-size:12.5px;white-space:nowrap">${u.consignor_code
                      ? `${escapeHtml(u.consignor_code)}${u.role === 'consignor' ? '' : ' <button class="btn sm secondary" data-unconsign>ยกเลิก</button>'}`
                      : '<button class="btn sm secondary" data-consign>ตั้งเป็นผู้ฝากขาย</button>'}</td>
                  <td>${suspended ? '<span class="pill bad">ถูกระงับที่ RVC-ONE</span>'
                      : self ? '<span class="pill ok">ใช้งาน</span>'
                      : `<button class="pill ${Number(u.active) ? 'ok' : 'muted'}" style="border:0;cursor:pointer" data-toggle>${Number(u.active) ? 'ใช้งาน' : 'ปิดอยู่'}</button>`}</td>
                  <td class="muted" style="font-size:12.5px">${u.last_login_at ? fmtDateTime(u.last_login_at) : '-'}</td>
                  <td>${!self && me.role === 'admin' && Number(u.active) && !suspended
                      ? '<button class="btn sm secondary" data-impersonate>สวมสิทธิ์</button>' : ''}</td>
                </tr>`;
              }).join('')}
            </tbody>
          </table></div>
        </section>
        <section class="card" style="margin-top:16px">
          <div class="card-head"><h2>เพิ่มสิทธิ์ให้ผู้ใช้จาก RVC-ONE</h2></div>
          <div class="card-pad" style="display:flex;flex-direction:column;gap:10px">
            <form id="oa-search" class="search-box" style="max-width:420px">
              <input name="q" placeholder="ค้นหาชื่อ แผนก อีเมล หรือ RVC-ONE ID" value="${escapeHtml(searchQ)}">
              <button class="btn sm" type="submit">ค้นหา</button>
            </form>
            ${results.length ? `<div class="table-wrap"><table><tbody>
              ${results.map((r) => `
                <tr data-oa="${r.oa_user_id}">
                  <td>${escapeHtml(`${r.first_name} ${r.last_name}`)}<div class="muted" style="font-size:12px">ID ${r.oa_user_id} · ${escapeHtml(TYPE_LABEL[r.user_type] || r.user_type || '')} ${escapeHtml(r.department || '')}</div></td>
                  <td>${r.local_user_id ? `<span class="pill ok">มีสิทธิ์แล้ว (${escapeHtml(ROLE_LABEL[r.local_role] || r.local_role)})</span>`
                      : `${roleSelect('grant-role', 'cashier')} <button class="btn sm" data-grant>ให้สิทธิ์</button>`}</td>
                </tr>`).join('')}
            </tbody></table></div>` : (searchQ ? '<div class="empty">ไม่พบผู้ใช้ — ลองซิงค์รายชื่อในแท็บ RVC-ONE ก่อน</div>' : '')}
          </div>
        </section>`;
        bind();
    }

    function bind() {
        el.querySelectorAll('tr[data-id]').forEach((tr) => {
            const id = tr.dataset.id;
            tr.querySelector('[data-role]')?.addEventListener('change', (e) =>
                act(() => api.put(`/users/${id}`, { role: e.target.value }), 'เปลี่ยนบทบาทแล้ว'));
            tr.querySelector('[data-toggle]')?.addEventListener('click', (e) =>
                act(() => api.put(`/users/${id}`, { active: e.target.textContent.trim() !== 'ใช้งาน' })));
            tr.querySelector('[data-impersonate]')?.addEventListener('click', async () => {
                const name = tr.children[0].textContent.trim();
                if (!confirm(`สวมสิทธิ์เป็น ${name}? จะใช้งานระบบด้วยสิทธิ์ของผู้ใช้นี้ จนกว่าจะกด «กลับเป็นผู้ดูแลระบบ»`)) return;
                try {
                    await api.post(`/users/${id}/impersonate`);
                    location.hash = '';
                    location.reload();
                } catch (err) {
                    message = { ok: false, text: err.message };
                    await load();
                }
            });
            tr.querySelector('[data-consign]')?.addEventListener('click', () => {
                const code = prompt('ผูกกับรหัสผู้ฝากขายที่มีอยู่แล้ว (เช่น C-012)\nหรือเว้นว่างเพื่อสร้างผู้ฝากขายใหม่จากข้อมูลผู้ใช้นี้');
                if (code === null) return;
                act(() => api.put(`/users/${id}`, { consignor: code.trim() || 'new' }),
                    'ตั้งเป็นผู้ฝากขายแล้ว ผู้ใช้สลับไปเมนูผู้ฝากขายได้จากมุมขวาบน');
            });
            tr.querySelector('[data-unconsign]')?.addEventListener('click', () => {
                if (confirm('ยกเลิกการเป็นผู้ฝากขายของผู้ใช้นี้? (ข้อมูลผู้ฝากขายและสมุดบัญชียังอยู่ครบ เพียงไม่ผูกกับบัญชีนี้)')) {
                    act(() => api.put(`/users/${id}`, { consignor: null }), 'ยกเลิกแล้ว');
                }
            });
            tr.querySelector('[data-unlink]')?.addEventListener('click', () => {
                if (confirm('ยกเลิกการผูกบัญชี RVC-ONE? ผู้ใช้นี้จะเข้าสู่ระบบผ่าน RVC-ONE ไม่ได้อีก')) {
                    act(() => api.put(`/users/${id}`, { oa_user_id: null }), 'ยกเลิกการผูกแล้ว');
                }
            });
            tr.querySelector('[data-link]')?.addEventListener('click', () => {
                const oaId = prompt('กรอก RVC-ONE ID ของผู้ใช้นี้ (ดูได้จากผลค้นหาด้านล่าง)');
                if (oaId && oaId.trim()) act(() => api.put(`/users/${id}`, { oa_user_id: oaId.trim() }), 'ผูกบัญชีแล้ว');
            });
        });
        el.querySelector('#oa-search').addEventListener('submit', async (e) => {
            e.preventDefault();
            searchQ = new FormData(e.target).get('q').trim();
            try {
                results = searchQ ? (await api.get(`/oa/users?q=${encodeURIComponent(searchQ)}`)).data : [];
            } catch (err) {
                message = { ok: false, text: err.message };
            }
            await load();
        });
        el.querySelectorAll('tr[data-oa]').forEach((tr) => tr.querySelector('[data-grant]')?.addEventListener('click', () => {
            const role = tr.querySelector('[data-grant-role]').value;
            act(async () => {
                await api.post('/users/from-oa', { oa_user_id: Number(tr.dataset.oa), role });
                results = (await api.get(`/oa/users?q=${encodeURIComponent(searchQ)}`)).data;
            }, 'เพิ่มสิทธิ์แล้ว ผู้ใช้เข้าสู่ระบบผ่าน RVC-ONE ได้ทันที');
        }));
    }

    await load();
}

/** "RVC-ONE" tab: client secret, allowed login methods and user-directory sync (admins only). */
export async function renderOaPanel(el) {
    let message = null;
    const act = makeAct((m) => { message = m; }, load);

    async function load() {
        const [oa, m] = await Promise.all([api.get('/oa/status'), api.get('/auth/methods')]);
        draw(oa, m.login_methods);
    }

    function syncLine(oa) {
        const last = oa.runs[0];
        if (!last) return 'ยังไม่เคยซิงค์';
        const when = fmtDateTime(last.finished_at || last.started_at);
        if (last.status === 'failed') return `<span class="pill bad">ล้มเหลว</span> ${when} — ${escapeHtml(last.error || '')}`;
        if (last.status === 'running') return `<span class="pill warn">กำลังซิงค์</span> เริ่ม ${when}`;
        return `<span class="pill ok">สำเร็จ</span> ${when} (${last.mode === 'full' ? 'ทั้งหมด' : 'เฉพาะที่เปลี่ยน'}: ใหม่ ${last.added}, อัปเดต ${last.updated}, ถูกระงับ ${last.suspended})`;
    }

    function secretForm(oa) {
        const fixed = oa.secret_source === 'env' || oa.secret_source === 'config';
        const where = oa.secret_source === 'env' ? 'env OA_CLIENT_SECRET' : 'config/config.php → oa.client_secret';
        const state = oa.secret_source === 'none' ? '<span class="pill bad">ยังไม่ตั้งค่า</span>'
            : fixed ? `<span class="pill ok">ตั้งค่าไว้ใน ${escapeHtml(where)}</span>`
            : '<span class="pill ok">ตั้งค่าแล้ว</span>';
        return `
            <form id="oa-secret" style="display:flex;flex-direction:column;gap:6px">
              <div>Client ID: <code>${escapeHtml(oa.client_id || '')}</code> · Client Secret: ${state}</div>
              ${fixed ? `<div class="muted" style="font-size:12px">ค่านี้ถูกกำหนดไว้ฝั่งเซิร์ฟเวอร์ จึงแก้จากหน้านี้ไม่ได้ — ต้องแก้ที่ ${escapeHtml(where)}</div>` : `
              <div class="actions-row">
                <input class="pc-select" name="client_secret" type="password" autocomplete="off" style="width:320px"
                  placeholder="${oa.secret_source === 'db' ? 'วาง Secret ใหม่เพื่อเปลี่ยน' : 'วาง Client Secret จาก RVC-ONE'}">
                <button class="btn sm" type="submit">บันทึก</button>
                ${oa.secret_source === 'db' ? '<button class="btn sm secondary" type="button" data-clear-secret>ลบ Secret</button>' : ''}
              </div>
              <div class="muted" style="font-size:12px">เก็บแบบเข้ารหัสในฐานข้อมูล และจะไม่แสดงค่าอีก · ถ้ากด «สร้าง Secret ใหม่» ใน RVC-ONE ต้องนำค่าใหม่มาใส่ที่นี่ด้วย</div>`}
            </form>`;
    }

    function draw(oa, methods) {
        el.innerHTML = `
        <div style="display:flex;flex-direction:column;gap:16px">
          ${message || !oa.secret_configured ? `<div style="display:flex;flex-direction:column;gap:8px">
            ${messageBox(message)}
            ${oa.secret_configured ? '' : `<div class="error-box">ยังไม่ได้ตั้งค่า Client Secret ของ RVC-ONE จึงยังซิงค์รายชื่อไม่ได้ — คัดลอกจากหน้าแก้ไขแอปใน RVC-ONE (แท็บ «การเชื่อมต่อ SSO») มาใส่ด้านล่าง · การเข้าสู่ระบบผ่าน RVC-ONE ใช้งานได้ตามปกติ</div>`}
          </div>` : ''}
          <section class="card card-pad" style="display:flex;flex-direction:column;gap:10px;font-size:13px">
            <h2>การเชื่อมต่อ RVC-ONE</h2>
            ${secretForm(oa)}
            <div class="muted" style="font-size:12px">URL เริ่ม SSO ของแอป (กรอกที่หน้าแก้ไขแอปใน RVC-ONE): <code>${escapeHtml(oa.sso_start_url)}</code></div>
          </section>
          <section class="card card-pad" style="display:flex;flex-direction:column;gap:10px;font-size:13px">
            <h2>วิธีเข้าสู่ระบบที่อนุญาต</h2>
            <div class="actions-row">${Object.entries(METHOD_LABEL).map(([k, v]) =>
                `<button type="button" class="tab-btn ${k === methods ? 'active' : ''}" data-method="${k}">${escapeHtml(v)}</button>`).join('')}</div>
            <div class="muted" style="font-size:12px">${escapeHtml(METHOD_HINT[methods] || '')}</div>
          </section>
          <section class="card card-pad" style="display:flex;flex-direction:column;gap:10px;font-size:13px">
            <h2>ซิงค์รายชื่อผู้ใช้</h2>
            <div>รายชื่อจาก RVC-ONE: <b>${oa.directory_count}</b> คน
              (ครู/บุคลากร ${(oa.directory_by_type || {}).staff || 0} · นักเรียนนักศึกษา ${(oa.directory_by_type || {}).student || 0})
              · ซิงค์ล่าสุด: ${syncLine(oa)}</div>
            <div class="actions-row">
              <span>ประเภทที่ซิงค์:</span>
              ${Object.entries(SYNC_TYPE_LABEL).map(([k, v]) =>
                  `<button type="button" class="tab-btn ${k === (oa.sync_type || '') ? 'active' : ''}" data-sync-type="${k}">${escapeHtml(v)}</button>`).join('')}
            </div>
            <div class="muted" style="font-size:12px">เมื่อเปลี่ยนประเภท การซิงค์ครั้งถัดไปจะดึงรายชื่อทั้งหมดของประเภทนั้นใหม่ · ตั้ง cron ให้รัน <code>php bin/oa-sync.php</code> ทุก 30 นาที เพื่อดึงเฉพาะที่เปลี่ยน</div>
            <div class="actions-row">
              <button class="btn sm" data-sync="0">ซิงค์รายชื่อตอนนี้</button>
              <button class="btn sm secondary" data-sync="1">ซิงค์ทั้งหมดใหม่</button>
            </div>
          </section>
        </div>`;
        bind();
    }

    function bind() {
        el.querySelector('#oa-secret').addEventListener('submit', (e) => {
            e.preventDefault();
            const v = (new FormData(e.target).get('client_secret') || '').trim();
            if (!v) return;
            act(() => api.put('/oa/secret', { client_secret: v }), 'บันทึก Client Secret แล้ว — กด «ซิงค์รายชื่อตอนนี้» เพื่อทดสอบ');
        });
        el.querySelector('[data-clear-secret]')?.addEventListener('click', () => {
            if (confirm('ลบ Client Secret? จะซิงค์รายชื่อจาก RVC-ONE ไม่ได้จนกว่าจะใส่ใหม่')) {
                act(() => api.put('/oa/secret', { client_secret: '', clear: true }), 'ลบ Client Secret แล้ว');
            }
        });
        el.querySelectorAll('[data-method]').forEach((btn) => btn.addEventListener('click', () => {
            const v = btn.dataset.method;
            if (v === 'password' && !confirm('ปิดการเข้าสู่ระบบผ่าน RVC-ONE? ผู้ใช้ที่ได้สิทธิ์จาก RVC-ONE (ไม่มีรหัสผ่าน) จะเข้าระบบไม่ได้')) return;
            act(() => api.put('/settings', { login_methods: v }), 'บันทึกวิธีเข้าสู่ระบบแล้ว');
        }));
        el.querySelectorAll('[data-sync-type]').forEach((btn) => btn.addEventListener('click', () =>
            act(() => api.put('/oa/sync-type', { sync_type: btn.dataset.syncType }),
                'บันทึกแล้ว — กด «ซิงค์รายชื่อตอนนี้» เพื่อดึงรายชื่อ')));
        el.querySelectorAll('[data-sync]').forEach((btn) => btn.addEventListener('click', () => {
            btn.disabled = true;
            btn.textContent = 'กำลังซิงค์...';
            act(() => api.post('/oa/sync', { full: btn.dataset.sync === '1' }), 'ซิงค์รายชื่อเรียบร้อย');
        }));
    }

    await load();
}
