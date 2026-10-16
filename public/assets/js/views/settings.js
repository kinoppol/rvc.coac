import { api } from '../api.js';
import { money, escapeHtml, fmtDateTime } from '../util.js';
import { renderUsersPanel } from './settings-users.js';

const ROLE_LABEL = { admin: 'ผู้ดูแลระบบ', manager: 'ผู้จัดการร้าน', advisor: 'ครูที่ปรึกษา', cashier: 'พนักงานขาย (นักเรียน)' };

export async function render(page, { user, permissions }) {
    const canEdit = permissions.includes('settings.edit');
    page.innerHTML = '<div></div><div style="margin-top:16px"></div>';
    const container = page.firstElementChild;
    let saved = null;
    let error = null;

    async function load() {
        const res = await api.get('/settings');
        draw(res);
    }

    function draw({ settings: s, role_permissions, audit_log }) {
        const byRole = {};
        role_permissions.forEach((rp) => { (byRole[rp.role] ||= []).push(rp.permission_key); });

        container.innerHTML = `
        <div class="two-col">
          <div style="display:flex;flex-direction:column;gap:16px">
            <form id="settings-form">
              <fieldset ${canEdit ? '' : 'disabled'} style="border:0;padding:0;margin:0;display:flex;flex-direction:column;gap:16px">
                <section class="card card-pad">
                  <h2 style="margin-bottom:14px">ค่าฝากขายสินค้า</h2>
                  <div class="actions-row" style="margin-bottom:12px">
                    <button type="button" class="tab-btn ${s.fee_mode === 'fixed' ? 'active' : ''}" data-set="fee_mode" data-val="fixed">คิดเป็นบาทต่อชิ้น</button>
                    <button type="button" class="tab-btn ${s.fee_mode === 'percent' ? 'active' : ''}" data-set="fee_mode" data-val="percent">คิดเป็นร้อยละของราคา</button>
                  </div>
                  <div class="form-grid">
                    <label class="field">ค่าฝากขาย (บาท/ชิ้น) <input name="fee_fixed" type="number" step="0.25" value="${s.fee_fixed}"></label>
                    <label class="field">ค่าฝากขาย (% ของราคา) <input name="fee_percent" type="number" step="0.5" value="${s.fee_percent}"></label>
                    <label class="field">การปัดเศษราคาขาย
                      <select name="rounding_mode">
                        <option value="whole_baht" ${s.rounding_mode === 'whole_baht' ? 'selected' : ''}>ปัดขึ้นเป็นบาทถ้วน (แนะนำ)</option>
                        <option value="quarter_baht" ${s.rounding_mode === 'quarter_baht' ? 'selected' : ''}>ปัดขึ้นเป็น 0.25 บาท</option>
                        <option value="none" ${s.rounding_mode === 'none' ? 'selected' : ''}>ไม่ปัด เก็บระดับสตางค์</option>
                      </select>
                    </label>
                    <label class="field">ส่วนต่างจากการปัดเศษเข้าใคร
                      <select name="rounding_beneficiary">
                        <option value="store" ${s.rounding_beneficiary === 'store' ? 'selected' : ''}>เข้าร้านค้าสหการ</option>
                        <option value="consignor" ${s.rounding_beneficiary === 'consignor' ? 'selected' : ''}>เข้าผู้ฝากขาย</option>
                      </select>
                    </label>
                  </div>
                </section>

                <section class="card card-pad">
                  <h2 style="margin-bottom:14px">ค่าทำข้อมูลรายการสินค้า</h2>
                  <div class="form-grid">
                    <label class="field">ค่าทำข้อมูล (บาท/รายการ) <input name="listing_fee" type="number" step="0.5" value="${s.listing_fee}"></label>
                    <label class="field">ยกเว้นให้กี่รายการแรก <input name="free_listings" type="number" value="${s.free_listings}"></label>
                    <label class="field">คืนค่าทำข้อมูลเมื่อขายได้เกิน (ชิ้น) <input name="waive_qty" type="number" value="${s.waive_qty}"></label>
                  </div>
                  <p class="muted" style="font-size:12px;margin-top:10px">การเปลี่ยนค่าที่นี่จะมีผลกับสินค้าที่รับฝากใหม่เท่านั้น ไม่กระทบสินค้าที่รับฝากไปแล้ว</p>
                </section>

                ${canEdit ? `<div>${saved ? '<span class="pill ok" style="margin-right:10px">บันทึกแล้ว</span>' : ''}${error ? `<span class="error-box">${escapeHtml(error)}</span>` : ''}<button type="submit" class="btn">บันทึกการตั้งค่า</button></div>` : ''}
              </fieldset>
            </form>

            <section class="card">
              <div class="card-head"><h2>สิทธิ์การใช้งานตามบทบาท</h2></div>
              <div class="table-wrap"><table>
                <thead><tr><th>บทบาท</th><th>สิ่งที่ทำได้</th></tr></thead>
                <tbody>
                  ${Object.entries(byRole).map(([role, perms]) => `
                    <tr><td style="white-space:nowrap;font-weight:600">${escapeHtml(ROLE_LABEL[role] || role)}</td>
                        <td class="muted" style="font-size:12.5px">${perms.map(escapeHtml).join(', ')}</td></tr>
                  `).join('')}
                </tbody>
              </table></div>
            </section>
          </div>

          <section class="card card-pad">
            <h2 style="margin-bottom:12px">การเปลี่ยนแปลงค่าระบบ</h2>
            <div style="display:flex;flex-direction:column;gap:10px">
              ${audit_log.map((a) => `
                <div style="border-bottom:1px solid var(--border);padding-bottom:8px;font-size:12.5px">
                  <div><b>${escapeHtml(a.setting_key)}</b>: ${escapeHtml(a.old_value ?? '-')} → ${escapeHtml(a.new_value ?? '-')}</div>
                  <div class="muted">${escapeHtml(a.user_name || '')} · ${fmtDateTime(a.changed_at)}</div>
                </div>
              `).join('') || `<div class="empty">ยังไม่มีการเปลี่ยนแปลง</div>`}
            </div>
          </section>
        </div>`;

        if (canEdit) {
            container.querySelectorAll('[data-set]').forEach((btn) => btn.addEventListener('click', () => {
                s[btn.dataset.set] = btn.dataset.val;
                draw({ settings: s, role_permissions, audit_log });
            }));
            container.querySelector('#settings-form').addEventListener('submit', async (e) => {
                e.preventDefault();
                const fd = new FormData(e.target);
                const body = Object.fromEntries(fd.entries());
                body.fee_mode = s.fee_mode;
                try {
                    await api.put('/settings', body);
                    saved = true;
                    error = null;
                    await load();
                } catch (err) {
                    error = err.message;
                    draw({ settings: s, role_permissions, audit_log });
                }
            });
        }
    }

    await load();
    if (canEdit) {
        await renderUsersPanel(page.lastElementChild, { user });
    }
}
