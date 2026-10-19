import { api } from '../api.js';
import { money, escapeHtml, fmtDateTime } from '../util.js';
import { renderUsersPanel, renderOaPanel } from './settings-users.js';

const ROLE_LABEL = { admin: 'ผู้ดูแลระบบ', manager: 'ผู้จัดการร้าน', advisor: 'ครูที่ปรึกษา', cashier: 'พนักงานขาย (นักเรียน)' };

const TABS = [
    { key: 'fees', label: 'ค่าฝากขาย' },
    { key: 'users', label: 'ผู้ใช้งาน', edit: true },
    { key: 'rvcone', label: 'RVC-ONE', edit: true },
    { key: 'time', label: 'เขตเวลา (Time zone)' },
    { key: 'roles', label: 'สิทธิ์ตามบทบาท' },
    { key: 'history', label: 'ประวัติการเปลี่ยนแปลง' },
];

export async function render(page, { user, permissions, params = {} }) {
    const canEdit = permissions.includes('settings.edit');
    const tabs = TABS.filter((t) => !t.edit || canEdit);
    let tab = tabs.some((t) => t.key === params.tab) ? params.tab : 'fees';
    page.innerHTML = `<div class="tabs" style="margin-bottom:16px">${tabs.map((t) =>
        `<button type="button" class="tab-btn" data-tab="${t.key}">${escapeHtml(t.label)}</button>`).join('')}</div><div></div>`;
    const container = page.lastElementChild;
    let saved = null;
    let error = null;
    let data = null;
    let pane = null;

    async function show(key) {
        tab = key;
        page.querySelectorAll('[data-tab]').forEach((b) => b.classList.toggle('active', b.dataset.tab === key));
        // Keep the tab in the URL without triggering the router's hashchange reload.
        history.replaceState(null, '', key === 'fees' ? '#/settings' : `#/settings?tab=${key}`);
        saved = null;
        error = null;
        // A fresh pane per visit, so a slow load from a tab left behind lands in a detached node.
        pane = document.createElement('div');
        pane.innerHTML = '<div class="empty">กำลังโหลด...</div>';
        container.replaceChildren(pane);
        if (key === 'users') return renderUsersPanel(pane, { user });
        if (key === 'rvcone') return renderOaPanel(pane);
        await load();
    }
    page.querySelectorAll('[data-tab]').forEach((b) => b.addEventListener('click', () => show(b.dataset.tab)));

    async function load() {
        const target = pane;
        const res = await api.get('/settings');
        if (target !== pane) return;
        data = res;
        draw(data);
    }

    /** Current wall-clock time in a time zone, e.g. «20 ต.ค. 2569 14:05 (UTC+07:00)». */
    function clockIn(tz) {
        try {
            const d = new Date();
            const text = d.toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short', timeZone: tz });
            const off = new Intl.DateTimeFormat('en', { timeZone: tz, timeZoneName: 'longOffset' }).formatToParts(d)
                .find((p) => p.type === 'timeZoneName')?.value.replace('GMT', 'UTC') || '';
            return `${text}${off ? ` (${off === 'UTC' ? 'UTC+00:00' : off})` : ''}`;
        } catch (e) {
            return '-';
        }
    }

    function drawTime({ settings: s, timezones, timezone_default: def, timezone_current: cur }) {
        const chosen = s.timezone || '';
        pane.innerHTML = `
          <div style="max-width:720px">
            <form id="tz-form">
              <fieldset ${canEdit ? '' : 'disabled'} style="border:0;padding:0;margin:0">
                <section class="card card-pad">
                  <h2 style="margin-bottom:6px">เขตเวลาของระบบ (Time zone)</h2>
                  <p class="muted" style="font-size:13px;margin:0 0 14px">ใช้กำหนดวันและเวลาที่บันทึกลงเอกสารทุกชนิด (ขาย รับฝาก ซื้อสินค้า ฯลฯ) การตัดยอดรายงานรายวัน และวันที่ «วันนี้» ที่แสดงในระบบ</p>
                  <div class="form-grid">
                    <label class="field">เขตเวลา
                      <select name="timezone" id="tz-select">
                        <option value="" ${chosen === '' ? 'selected' : ''}>ตามไฟล์ตั้งค่าระบบ (${escapeHtml(def)})</option>
                        ${timezones.map((z) => `<option value="${escapeHtml(z)}" ${z === chosen ? 'selected' : ''}>${escapeHtml(z)}</option>`).join('')}
                      </select>
                    </label>
                    <div class="field">เวลาปัจจุบันตามเขตเวลาที่เลือก<div id="tz-clock" style="font-weight:600;padding:8px 0">${escapeHtml(clockIn(chosen || def))}</div></div>
                  </div>
                  <p class="muted" style="font-size:12.5px;margin:10px 0 0">ขณะนี้ระบบใช้ <b>${escapeHtml(cur)}</b> ·
                    การเปลี่ยนเขตเวลามีผลกับรายการที่บันทึกหลังจากนี้ ข้อมูลเดิมจะไม่ถูกแปลงเวลา</p>
                </section>
                ${canEdit ? `<div style="margin-top:16px">${saved ? '<span class="pill ok" style="margin-right:10px">บันทึกแล้ว</span>' : ''}${error ? `<span class="error-box">${escapeHtml(error)}</span>` : ''}<button type="submit" class="btn">บันทึกเขตเวลา</button></div>` : ''}
              </fieldset>
            </form>
          </div>`;
        const sel = pane.querySelector('#tz-select');
        sel.addEventListener('change', () => { pane.querySelector('#tz-clock').textContent = clockIn(sel.value || def); });
        if (!canEdit) return;
        pane.querySelector('#tz-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                await api.put('/settings', { timezone: sel.value });
                // Reload so the top bar, «today» defaults and every view pick up the new zone.
                location.reload();
            } catch (err) {
                error = err.message;
                drawTime(data);
            }
        });
    }

    function draw({ settings: s, role_permissions, audit_log }) {
        if (tab === 'time') return drawTime(data);
        const byRole = {};
        role_permissions.forEach((rp) => { (byRole[rp.role] ||= []).push(rp.permission_key); });

        if (tab === 'roles') {
            pane.innerHTML = `
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
            </section>`;
            return;
        }
        if (tab === 'history') {
            pane.innerHTML = `
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
          </section>`;
            return;
        }
        pane.innerHTML = `
          <div style="max-width:920px">
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
          </div>`;

        if (canEdit) {
            pane.querySelectorAll('[data-set]').forEach((btn) => btn.addEventListener('click', () => {
                s[btn.dataset.set] = btn.dataset.val;
                draw(data);
            }));
            pane.querySelector('#settings-form').addEventListener('submit', async (e) => {
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
                    draw(data);
                }
            });
        }
    }

    await show(tab);
}
