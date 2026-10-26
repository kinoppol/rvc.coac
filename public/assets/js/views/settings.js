import { api } from '../api.js';
import { money, escapeHtml, fmtDateTime } from '../util.js';
import { renderUsersPanel, renderOaPanel } from './settings-users.js';
import { renderPerfPanel } from './settings-perf.js';
import { skPage } from '../skeleton.js';

const WEEKDAYS = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];

/** Saved holidays (store_holidays JSON); ones over a month past no longer matter and are dropped on the next save. */
function holidays(s) {
    let list = [];
    try { list = JSON.parse(s.store_holidays || '[]'); } catch (e) { list = []; }
    const cutoff = new Date(Date.now() - 31 * 864e5).toISOString().slice(0, 10);
    return list.filter((h) => h.date >= cutoff);
}

function holidayRow(h) {
    return `<div class="holiday-row" data-holiday>
      <input type="date" data-hd value="${escapeHtml(h.date)}" aria-label="วันที่">
      <input data-hn value="${escapeHtml(h.name)}" placeholder="ชื่อวันหยุด เช่น วันปิยมหาราช" maxlength="100" aria-label="ชื่อวันหยุด">
      <button type="button" class="btn secondary sm" data-holiday-del title="ลบ" aria-label="ลบวันหยุดนี้">✕</button>
    </div>`;
}
const PAYMENT_LABEL ={ cash: 'เงินสด', transfer: 'โอนเงินเข้าบัญชี', qr: 'สแกน QR-Code', card: 'บัตรเครดิต' };

const ROLE_LABEL = { admin: 'ผู้ดูแลระบบ', manager: 'ผู้จัดการร้าน', advisor: 'ครูที่ปรึกษา', cashier: 'พนักงานขาย (นักเรียน)', closer: 'ผู้สรุปบัญชีรายวัน' };

const TABS = [
    { key: 'fees', label: 'ค่าฝากขาย' },
    { key: 'pos', label: 'หน้าขายสินค้า (POS)' },
    { key: 'users', label: 'ผู้ใช้งาน', edit: true },
    { key: 'rvcone', label: 'RVC-ONE', edit: true },
    { key: 'time', label: 'เขตเวลา (Time zone)' },
    { key: 'roles', label: 'สิทธิ์ตามบทบาท' },
    { key: 'perf', label: 'ประสิทธิภาพ', edit: true },
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
        pane.innerHTML = skPage(key === 'users' || key === 'perf' ? 'list' : 'form');
        container.replaceChildren(pane);
        if (key === 'users') return renderUsersPanel(pane, { user });
        if (key === 'rvcone') return renderOaPanel(pane);
        if (key === 'perf') return renderPerfPanel(pane);
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

    function drawPos({ settings: s }) {
        const on = (s.pos_payment_methods || '').split(',');
        const POS_ROLES = ['admin', 'manager', 'advisor', 'cashier'];
        const roleBoxes = (name, value) => {
            const picked = (value || '').split(',');
            return `<div style="display:flex;flex-wrap:wrap;gap:8px 18px;margin-top:10px">${POS_ROLES.map((r) => `
              <label style="display:flex;align-items:center;gap:8px;font-size:14px"><input type="checkbox" name="${name}" value="${r}" ${picked.includes(r) ? 'checked' : ''} style="width:20px;height:20px"> ${escapeHtml(ROLE_LABEL[r])}</label>`).join('')}</div>`;
        };
        pane.innerHTML = `
          <div style="max-width:720px">
            <form id="pos-form">
              <fieldset ${canEdit ? '' : 'disabled'} style="border:0;padding:0;margin:0">
                <section class="card card-pad">
                  <h2 style="margin-bottom:6px">วิธีรับชำระเงินที่หน้าขายสินค้า (POS)</h2>
                  <p class="muted" style="font-size:13px;margin:0 0 14px">เลือกวิธีที่ร้านรับชำระได้ พนักงานขายจะเห็นเฉพาะวิธีที่เปิดไว้ตอนกดชำระเงิน</p>
                  <div style="display:flex;flex-direction:column;gap:10px">
                    ${Object.entries(PAYMENT_LABEL).map(([k, label]) => `
                      <label style="display:flex;align-items:center;gap:10px;font-size:14px"><input type="checkbox" name="method" value="${k}" ${on.includes(k) ? 'checked' : ''} style="width:20px;height:20px"> ${escapeHtml(label)}</label>`).join('')}
                  </div>
                </section>
                <section class="card card-pad" style="margin-top:16px">
                  <h2 style="margin-bottom:6px">เวลาเปิด-ปิดร้าน</h2>
                  <p class="muted" style="font-size:13px;margin:0 0 14px">ขายนอกเวลาได้ตามปกติ แต่รายการที่ขายหลังเวลาปิดร้าน (หรือในวันที่ร้านปิด) จะลงบัญชีเป็นยอดขายของวันเปิดร้านถัดไป
                    ยอดสรุปของวันที่ปิดไปแล้วจึงไม่เปลี่ยน เวลาขายจริงยังถูกบันทึกไว้ ดูได้ที่ รายงาน &gt; ขายนอกเวลาทำการ เพื่อตรวจสอบกับการนับสต๊อก
                    เว้นว่างทั้งสองช่อง = ไม่กำหนดเวลา</p>
                  <div class="form-grid">
                    <label class="field">เวลาเปิดร้าน <input type="time" name="store_open_time" value="${escapeHtml(s.store_open_time || '')}"></label>
                    <label class="field">เวลาปิดร้าน <input type="time" name="store_close_time" value="${escapeHtml(s.store_close_time || '')}"></label>
                  </div>
                  <div style="margin-top:14px;font-weight:600;font-size:14px">วันที่ร้านเปิด <span class="muted" style="font-size:12.5px;font-weight:400">(วันที่ไม่ได้เลือก = ร้านปิด)</span></div>
                  <div style="display:flex;flex-wrap:wrap;gap:8px 18px;margin-top:10px">
                    ${WEEKDAYS.map((d, i) => `
                      <label style="display:flex;align-items:center;gap:8px;font-size:14px"><input type="checkbox" name="open_day" value="${i}" ${(s.store_closed_days || '').split(',').includes(String(i)) ? '' : 'checked'} style="width:20px;height:20px"> ${d}</label>`).join('')}
                  </div>
                  <div style="margin-top:18px;font-weight:600;font-size:14px">วันหยุดนักขัตฤกษ์ / วันหยุดพิเศษ</div>
                  <p class="muted" style="font-size:12.5px;margin:4px 0 8px">ร้านปิดในวันที่กำหนด แม้จะตรงกับวันที่ร้านเปิด — ยอดขายของวันนั้นลงบัญชีเป็นวันเปิดร้านถัดไป</p>
                  <div class="holiday-list" id="holiday-list">${holidays(s).map(holidayRow).join('')}</div>
                  <button type="button" class="btn secondary sm" id="holiday-add" style="margin-top:8px">+ เพิ่มวันหยุด</button>
                </section>
                <section class="card card-pad" style="margin-top:16px">
                  <h2 style="margin-bottom:6px">สิทธิ์ใช้สวิตช์ในหน้าขายสินค้า</h2>
                  <p class="muted" style="font-size:13px;margin:0 0 4px">สวิตช์เหล่านี้ปิดไว้เป็นปกติ ผู้ใช้บทบาทที่เลือกจะเปิดใช้เองได้ในหน้าขายเมื่อจำเป็น</p>
                  <div style="margin-top:14px;font-weight:600;font-size:14px">ชำระแบบผสม <span class="muted" style="font-size:12.5px;font-weight:400">(เช่น เงินสดบางส่วน + โอนเงินส่วนที่เหลือ) — สวิตช์อยู่ในหน้าต่างชำระเงิน</span></div>
                  ${roleBoxes('mixed_roles', s.pos_mixed_roles)}
                  <hr style="border:0;border-top:1px solid var(--border);margin:16px 0">
                  <div style="font-weight:600;font-size:14px">ขายสินค้าที่มีจำนวนคงเหลือต่ำกว่า 1 (0 หรือติดลบ)</div>
                  <p class="muted" style="font-size:12.5px;margin:4px 0 0">ใช้เมื่อสต๊อกในระบบคลาดเคลื่อน หรือมีของเข้าร้านแล้วแต่ยังไม่ได้บันทึกรับเข้าระบบ
                    เมื่อผู้ใช้เปิดสวิตช์ สินค้าเหล่านี้จะค้นหาเจอ (สีจาง) และขายได้แม้สต๊อกจะติดลบ ควรตรวจนับหรือบันทึกรับสินค้าให้ตรงภายหลัง</p>
                  <label style="display:flex;align-items:center;gap:10px;font-size:14px;margin-top:12px"><input type="checkbox" name="short_always" value="1" ${s.pos_short_stock_always === '1' ? 'checked' : ''} style="width:20px;height:20px">
                    <span><b>เปิดขายทั้งระบบ</b> — ผู้ใช้ทุกคนขายได้ทันที ไม่ต้องเปิดสวิตช์ในหน้าขาย</span></label>
                  <div class="muted" style="font-size:12.5px;margin-top:10px">ถ้าไม่เปิดขายทั้งระบบ บทบาทที่เลือกด้านล่างเปิดสวิตช์เองได้ในหน้าขาย</div>
                  ${roleBoxes('short_roles', s.pos_short_stock_roles)}
                </section>
                <section class="card card-pad" style="margin-top:16px">
                  <h2 style="margin-bottom:6px">แก้ไขการขายย้อนหลังโดยพนักงานขาย</h2>
                  <p class="muted" style="font-size:13px;margin:0 0 14px">พนักงานดูการขายของตัวเองย้อนหลังได้ที่เมนู ประวัติการขายของฉัน
                    เมื่อเปิดไว้ พนักงานขอแก้ไขการขาย (จำนวน นำสินค้าออก เพิ่มสินค้า วิธีชำระเงิน) ได้ภายในเวลาที่กำหนดหลังขายเสร็จ
                    ระบบบันทึกคำขอไว้ และจะปรับรายการขาย สต๊อก และยอดเงินเมื่อผู้จัดการหรือผู้ดูแลระบบอนุมัติแล้วเท่านั้น</p>
                  <label style="display:flex;align-items:center;gap:10px;font-size:14px"><input type="checkbox" name="sale_edit_enabled" value="1" ${s.sale_edit_enabled === '1' ? 'checked' : ''} style="width:20px;height:20px"> เปิดให้พนักงานขอแก้ไขการขายได้</label>
                  <label class="field" style="margin-top:12px;max-width:260px">แก้ไขได้ภายใน (นาที หลังขายเสร็จ)
                    <input type="number" name="sale_edit_minutes" min="1" max="1440" step="1" value="${escapeHtml(s.sale_edit_minutes || '5')}"></label>
                </section>
                ${canEdit ? `<div style="margin-top:16px">${saved ? '<span class="pill ok" style="margin-right:10px">บันทึกแล้ว</span>' : ''}${error ? `<span class="error-box">${escapeHtml(error)}</span>` : ''}<button type="submit" class="btn">บันทึกการตั้งค่า</button></div>` : ''}
              </fieldset>
            </form>
          </div>`;
        if (!canEdit) return;
        pane.querySelector('#holiday-add').addEventListener('click', () => {
            const list = pane.querySelector('#holiday-list');
            list.insertAdjacentHTML('beforeend', holidayRow({ date: '', name: '' }));
            list.lastElementChild.querySelector('[data-hd]').focus();
        });
        pane.querySelector('#holiday-list').addEventListener('click', (e) => {
            e.target.closest('[data-holiday-del]')?.closest('[data-holiday]').remove();
        });
        pane.querySelector('#pos-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(e.target);
            try {
                await api.put('/settings', { pos_payment_methods: fd.getAll('method').join(','), pos_mixed_roles: fd.getAll('mixed_roles').join(','), pos_short_stock_roles: fd.getAll('short_roles').join(','),
                    pos_short_stock_always: fd.get('short_always') === '1' ? '1' : '0',
                    sale_edit_enabled: fd.get('sale_edit_enabled') === '1' ? '1' : '0', sale_edit_minutes: fd.get('sale_edit_minutes'),
                    store_open_time: fd.get('store_open_time'), store_close_time: fd.get('store_close_time'),
                    store_closed_days: WEEKDAYS.map((d, i) => String(i)).filter((i) => !fd.getAll('open_day').includes(i)).join(','),
                    store_holidays: [...pane.querySelectorAll('[data-holiday]')]
                        .map((row) => ({ date: row.querySelector('[data-hd]').value, name: row.querySelector('[data-hn]').value.trim() }))
                        .filter((h) => h.date) });
                saved = true;
                error = null;
                await load();
            } catch (err) {
                saved = null;
                error = err.message;
                drawPos(data);
            }
        });
    }

    function draw({ settings: s, role_permissions, audit_log }) {
        if (tab === 'time') return drawTime(data);
        if (tab === 'pos') return drawPos(data);
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
