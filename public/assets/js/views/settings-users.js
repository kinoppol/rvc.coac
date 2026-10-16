import { api } from '../api.js';
import { escapeHtml, fmtDateTime } from '../util.js';

const ROLE_LABEL = { admin: 'ผู้ดูแลระบบ', manager: 'ผู้จัดการร้าน', advisor: 'ครูที่ปรึกษา', cashier: 'พนักงานขาย (นักเรียน)' };
const TYPE_LABEL = { staff: 'ครู/บุคลากร', student: 'นักเรียน' };

function roleSelect(name, value) {
    return `<select data-${name}>${Object.entries(ROLE_LABEL).map(([k, v]) =>
        `<option value="${k}" ${k === value ? 'selected' : ''}>${escapeHtml(v)}</option>`).join('')}</select>`;
}

/** Users + RVC-ONE access card on the settings page (admins only). */
export async function renderUsersPanel(el, { user: me }) {
    let message = null;
    let searchQ = '';
    let results = [];

    async function load() {
        const [users, oa] = await Promise.all([api.get('/users'), api.get('/oa/status')]);
        draw(users.data, oa);
    }

    function syncLine(oa) {
        const last = oa.runs[0];
        if (!last) return 'ยังไม่เคยซิงค์';
        const when = fmtDateTime(last.finished_at || last.started_at);
        if (last.status === 'failed') return `<span class="pill bad">ล้มเหลว</span> ${when} — ${escapeHtml(last.error || '')}`;
        if (last.status === 'running') return `<span class="pill warn">กำลังซิงค์</span> เริ่ม ${when}`;
        return `<span class="pill ok">สำเร็จ</span> ${when} (${last.mode === 'full' ? 'ทั้งหมด' : 'เฉพาะที่เปลี่ยน'}: ใหม่ ${last.added}, อัปเดต ${last.updated}, ถูกระงับ ${last.suspended})`;
    }

    function draw(users, oa) {
        el.innerHTML = `
        <section class="card">
          <div class="card-head"><h2>ผู้ใช้งานระบบ และ RVC-ONE</h2></div>
          <div class="card-pad" style="display:flex;flex-direction:column;gap:12px;font-size:13px">
            ${message ? `<div class="${message.ok ? 'pill ok' : 'error-box'}" style="align-self:flex-start">${escapeHtml(message.text)}</div>` : ''}
            ${oa.secret_configured ? '' : `<div class="error-box">ยังไม่ได้ตั้งค่า client_secret ของ RVC-ONE (env OA_CLIENT_SECRET หรือ config/config.php → oa.client_secret) จึงยังซิงค์รายชื่อไม่ได้ — การเข้าสู่ระบบผ่าน RVC-ONE ใช้งานได้ตามปกติ</div>`}
            <div>รายชื่อจาก RVC-ONE: <b>${oa.directory_count}</b> คน · ซิงค์ล่าสุด: ${syncLine(oa)}</div>
            <div class="actions-row">
              <button class="btn sm" data-sync="0">ซิงค์รายชื่อตอนนี้</button>
              <button class="btn sm secondary" data-sync="1">ซิงค์ทั้งหมดใหม่</button>
            </div>
            <div class="muted" style="font-size:12px">URL เริ่ม SSO ของแอป (กรอกที่หน้าแก้ไขแอปใน RVC-ONE): <code>${escapeHtml(oa.sso_start_url)}</code></div>
          </div>
          <div class="table-wrap"><table>
            <thead><tr><th>ชื่อ</th><th>ชื่อผู้ใช้</th><th>RVC-ONE</th><th>บทบาท</th><th>สถานะ</th><th>เข้าใช้ล่าสุด</th></tr></thead>
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
                  <td>${suspended ? '<span class="pill bad">ถูกระงับที่ RVC-ONE</span>'
                      : self ? '<span class="pill ok">ใช้งาน</span>'
                      : `<button class="pill ${Number(u.active) ? 'ok' : 'muted'}" style="border:0;cursor:pointer" data-toggle>${Number(u.active) ? 'ใช้งาน' : 'ปิดอยู่'}</button>`}</td>
                  <td class="muted" style="font-size:12.5px">${u.last_login_at ? fmtDateTime(u.last_login_at) : '-'}</td>
                </tr>`;
              }).join('')}
            </tbody>
          </table></div>
          <div class="card-pad" style="display:flex;flex-direction:column;gap:10px">
            <h3 style="margin:0;font-size:14px">เพิ่มสิทธิ์ให้ผู้ใช้จาก RVC-ONE</h3>
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
            </tbody></table></div>` : (searchQ ? '<div class="empty">ไม่พบผู้ใช้ — ลองซิงค์รายชื่อก่อน</div>' : '')}
          </div>
        </section>`;
        bind();
    }

    async function act(fn, okText) {
        try {
            await fn();
            message = okText ? { ok: true, text: okText } : null;
        } catch (err) {
            message = { ok: false, text: err.message };
        }
        await load();
    }

    function bind() {
        el.querySelectorAll('[data-sync]').forEach((btn) => btn.addEventListener('click', () => {
            btn.disabled = true;
            btn.textContent = 'กำลังซิงค์...';
            act(() => api.post('/oa/sync', { full: btn.dataset.sync === '1' }), 'ซิงค์รายชื่อเรียบร้อย');
        }));
        el.querySelectorAll('tr[data-id]').forEach((tr) => {
            const id = tr.dataset.id;
            tr.querySelector('[data-role]')?.addEventListener('change', (e) =>
                act(() => api.put(`/users/${id}`, { role: e.target.value }), 'เปลี่ยนบทบาทแล้ว'));
            tr.querySelector('[data-toggle]')?.addEventListener('click', (e) =>
                act(() => api.put(`/users/${id}`, { active: e.target.textContent.trim() !== 'ใช้งาน' })));
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
