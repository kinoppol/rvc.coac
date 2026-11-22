import { api } from '../api.js';
import { money, count, escapeHtml, fmtDate, fmtDateTime } from '../util.js';
import { alertDialog } from '../dialog.js';

const DAYS = ['จันทร์', 'อังคาร', 'พุธ', 'พฤหัสฯ', 'ศุกร์', 'เสาร์', 'อาทิตย์'];
const KIND_TONE = { welcome: 'ok', earn: 'ok', survey: 'ok', redeem: 'warn', adjust: 'muted', refund: 'muted' };
const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

/** One member: who they are, their points and history, and how they buy (#/member?oa=RVC-ONE id). */
export async function render(container, { navigate, params = {} }) {
    const oa = Number(params.oa);
    let m = null;
    let tab = params.tab === 'buy' ? 'buy' : 'points';
    let ledgerPage = 1;

    async function load() {
        try {
            m = (await api.get(`/members/${oa}`)).data;
        } catch (e) {
            container.innerHTML = `<div class="card card-pad"><div class="error-box">${escapeHtml(e.message)}</div>
              <button type="button" class="btn secondary sm" data-back style="margin-top:8px">‹ กลับไปทะเบียนสมาชิก</button></div>`;
            return;
        }
        draw();
    }

    function draw() {
        container.innerHTML = `
          <div class="card"><div class="card-pad" style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start">
            <div style="flex:1;min-width:260px">
              <button type="button" class="link-btn" data-back>‹ ทะเบียนสมาชิก</button>
              <h2 style="margin:6px 0 4px;font-size:19px">${escapeHtml(m.name)}</h2>
              <div><span class="pill ${m.user_type === 'student' ? 'ok' : 'warn'}">${escapeHtml(m.type_label)}</span>
                <span class="muted" style="font-size:13px">${escapeHtml(m.department || '')}</span></div>
              <div style="font-size:13px;margin-top:8px;line-height:1.8">
                เลขบัตรประชาชน: <b>${escapeHtml(m.national_id || '-')}</b>
                ${m.student_code ? ` · รหัสนักศึกษา: <b>${escapeHtml(m.student_code)}</b>` : ''}<br>
                เบอร์โทรศัพท์: ${m.phones.length ? m.phones.map((p) => `<b>${escapeHtml(p)}</b>`).join(', ') : '<span class="muted">ไม่มีข้อมูลจาก RMS</span>'}<br>
                <span class="muted">เป็นสมาชิกตั้งแต่ ${escapeHtml(fmtDate(m.member_since))}${m.first_purchase_at ? ` · ซื้อครั้งแรก ${escapeHtml(fmtDate(m.first_purchase_at))}` : ''}</span>
              </div>
            </div>
            <div style="text-align:right;min-width:200px">
              <div class="muted" style="font-size:13px">แต้มคงเหลือ</div>
              <div style="font-size:34px;font-weight:700;color:var(--pri)">${count(m.points)}</div>
              ${m.held ? `<div class="muted" style="font-size:12.5px">จองไว้ในตะกร้า ${count(m.held)} · ใช้ได้ ${count(m.available)}</div>` : ''}
              ${m.can_manage ? '<button type="button" class="btn secondary sm" data-adjust style="margin-top:8px">ปรับแต้ม</button>' : ''}
            </div>
          </div></div>
          <div class="kpi-grid" style="margin-top:12px">
            <div class="kpi tone-sales"><div class="lbl">ซื้อสินค้า</div><div class="val">${count(m.bills)} บิล</div><div class="sub">${count(m.pieces)} ชิ้น · ล่าสุด ${m.last_purchase_at ? escapeHtml(fmtDateTime(m.last_purchase_at)) : '-'}</div></div>
            <div class="kpi tone-income"><div class="lbl">ยอดซื้อรวม</div><div class="val">฿${money(m.spend)}</div><div class="sub">เฉลี่ยบิลละ ฿${money(m.avg_bill)}</div></div>
            <div class="kpi tone-owed"><div class="lbl">แต้มที่ได้รับ</div><div class="val">${count((m.points_by_kind.earn || 0) + (m.points_by_kind.welcome || 0) + (m.points_by_kind.survey || 0))}</div>
              <div class="sub">จากการซื้อ ${count(m.points_by_kind.earn || 0)} · ซื้อครั้งแรก ${count(m.points_by_kind.welcome || 0)} · แบบสอบถาม ${count(m.points_by_kind.survey || 0)}</div></div>
            <div class="kpi tone-cost"><div class="lbl">แต้มที่ใช้เป็นส่วนลด</div><div class="val">${count(-(m.points_by_kind.redeem || 0))}</div>
              <div class="sub">ลดไป ฿${money(m.points_discount)} · ส่วนลดโปรโมชั่น ฿${money(m.promo_discount)}</div></div>
          </div>
          <div class="tabs" style="margin:14px 0 10px">
            <button type="button" class="tab-btn ${tab === 'points' ? 'active' : ''}" data-tab="points">ประวัติการสะสมและใช้แต้ม</button>
            <button type="button" class="tab-btn ${tab === 'buy' ? 'active' : ''}" data-tab="buy">ข้อมูลการซื้อ / พฤติกรรมผู้บริโภค</button>
          </div>
          <div data-pane class="card"><div class="card-pad muted">กำลังโหลด...</div></div>`;
        tab === 'points' ? loadLedger() : loadBuying();
    }

    async function loadLedger() {
        const pane = container.querySelector('[data-pane]');
        const r = await api.get(`/members/${oa}/points?page=${ledgerPage}`).catch((e) => ({ error: e.message }));
        if (r.error) { pane.innerHTML = `<div class="card-pad"><div class="error-box">${escapeHtml(r.error)}</div></div>`; return; }
        const d = r.data;
        const pages = Math.max(1, Math.ceil(d.total / d.per_page));
        pane.innerHTML = d.data.length ? `<div class="table-wrap"><table>
            <thead><tr><th>วันเวลา</th><th>รายการ</th><th>รายละเอียด</th><th class="num">แต้ม</th><th class="num">คงเหลือ</th><th>ผู้บันทึก</th></tr></thead>
            <tbody>${d.data.map((x) => `<tr>
              <td style="white-space:nowrap">${escapeHtml(fmtDateTime(x.at))}</td>
              <td><span class="pill ${KIND_TONE[x.kind] || ''}">${escapeHtml(x.kind_label)}</span></td>
              <td style="font-size:13px">${x.doc_no ? `บิล <b>${escapeHtml(x.doc_no)}</b> ` : ''}${escapeHtml(x.survey_title ? `แบบสอบถาม "${x.survey_title}"` : x.note || '')}</td>
              <td class="num" style="font-weight:700;color:${x.points < 0 ? 'var(--bad)' : 'var(--ok)'}">${x.points > 0 ? '+' : ''}${count(x.points)}</td>
              <td class="num">${count(x.balance_after)}</td>
              <td class="muted" style="font-size:12.5px">${escapeHtml(x.by || '')}</td></tr>`).join('')}</tbody></table></div>
          ${pages > 1 ? `<div class="card-pad" style="text-align:right"><button type="button" class="btn secondary sm" data-lpage="${d.page - 1}" ${d.page <= 1 ? 'disabled' : ''}>‹ ใหม่กว่า</button>
            <span class="muted" style="font-size:13px">หน้า ${d.page}/${pages}</span>
            <button type="button" class="btn secondary sm" data-lpage="${d.page + 1}" ${d.page >= pages ? 'disabled' : ''}>เก่ากว่า ›</button></div>` : ''}`
            : '<div class="card-pad empty">ยังไม่มีประวัติแต้ม — แต้มเริ่มสะสมเมื่อระบุสมาชิกเป็นผู้ซื้อที่หน้า POS</div>';
    }

    async function loadBuying() {
        const pane = container.querySelector('[data-pane]');
        const r = await api.get(`/members/${oa}/purchases`).catch((e) => ({ error: e.message }));
        if (r.error) { pane.innerHTML = `<div class="card-pad"><div class="error-box">${escapeHtml(r.error)}</div></div>`; return; }
        const d = r.data;
        if (!d.recent.length) { pane.innerHTML = '<div class="card-pad empty">ยังไม่มีข้อมูลการซื้อ</div>'; return; }
        const maxMonth = Math.max(1, ...d.months.map((x) => x.spend));
        const slots = {};
        d.slots.forEach((s) => { slots[`${s.weekday}-${s.hour}`] = s.bills; });
        const hours = d.slots.length ? [Math.min(...d.slots.map((s) => s.hour)), Math.max(...d.slots.map((s) => s.hour))] : [8, 17];
        const hourList = [];
        for (let h = Math.min(hours[0], 8); h <= Math.max(hours[1], 17); h++) hourList.push(h);
        const maxSlot = Math.max(1, ...d.slots.map((s) => s.bills));
        pane.innerHTML = `<div class="card-pad">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(340px,100%),1fr));gap:18px">
              <div><h3 style="margin:0 0 8px;font-size:14.5px">ยอดซื้อรายเดือน (12 เดือนล่าสุด)</h3>
                ${d.months.map((x) => `<div style="display:flex;align-items:center;gap:8px;font-size:12.5px;margin:3px 0">
                  <span style="width:64px">${MONTHS[Number(x.month.slice(5)) - 1]} ${Number(x.month.slice(0, 4)) + 543 - 2500}</span>
                  <span style="flex:1;background:var(--surface2);border-radius:4px;height:14px"><span style="display:block;height:14px;border-radius:4px;background:var(--pri);width:${(x.spend / maxMonth) * 100}%"></span></span>
                  <span style="width:110px;text-align:right">฿${money(x.spend)} <small class="muted">${x.bills} บิล</small></span></div>`).join('') || '<div class="muted">-</div>'}
                <div class="muted" style="font-size:12.5px;margin-top:8px">มาซื้อ ${count(d.visit_days)} วัน${d.avg_days_between !== null ? ` · กลับมาซื้อทุก ๆ ${d.avg_days_between} วันโดยเฉลี่ย` : ''}</div>
              </div>
              <div><h3 style="margin:0 0 8px;font-size:14.5px">ช่วงเวลาที่มาซื้อ (จำนวนบิล)</h3>
                <div style="overflow:auto"><table style="font-size:11px;border-collapse:collapse">
                  <tr><td></td>${hourList.map((h) => `<td style="text-align:center;padding:1px 2px" class="muted">${h}</td>`).join('')}</tr>
                  ${DAYS.map((day, w) => `<tr><td style="padding-right:6px;white-space:nowrap">${day}</td>${hourList.map((h) => {
                      const n = slots[`${w}-${h}`] || 0;
                      return `<td title="${day} ${h}:00 น. · ${n} บิล" style="width:20px;height:18px;border:1px solid var(--surface);background:${n ? `color-mix(in srgb, var(--pri) ${20 + (n / maxSlot) * 80}%, transparent)` : 'var(--surface2)'}"></td>`;
                  }).join('')}</tr>`).join('')}
                </table></div>
                <h3 style="margin:14px 0 6px;font-size:14.5px">วิธีชำระเงิน</h3>
                ${d.payments.map((p) => `<span class="pill muted" style="margin:2px">${escapeHtml(p.label)} ${p.bills} บิล · ฿${money(p.amount)}</span>`).join('') || '<span class="muted">-</span>'}
              </div>
            </div>
            <h3 style="margin:18px 0 6px;font-size:14.5px">สินค้าที่ซื้อบ่อย</h3>
            <div class="table-wrap"><table><thead><tr><th>สินค้า</th><th class="num">จำนวน</th><th class="num">ซื้อ (บิล)</th><th class="num">ยอดเงิน</th><th>ซื้อล่าสุด</th></tr></thead>
              <tbody>${d.top.map((t) => `<tr><td>${escapeHtml(t.name)}</td><td class="num">${count(t.qty)}</td><td class="num">${count(t.bills)}</td><td class="num">฿${money(t.spend)}</td><td>${escapeHtml(fmtDate(t.last_at))}</td></tr>`).join('')}</tbody></table></div>
            <h3 style="margin:18px 0 6px;font-size:14.5px">บิลล่าสุด</h3>
            <div class="table-wrap"><table><thead><tr><th>วันเวลา</th><th>เลขที่</th><th>สินค้า</th><th class="num">ยอดชำระ</th><th class="num">แต้ม</th></tr></thead>
              <tbody>${d.recent.map((b) => `<tr><td style="white-space:nowrap">${escapeHtml(fmtDateTime(b.at))}</td><td>${escapeHtml(b.doc_no)}</td>
                <td style="font-size:12.5px">${b.items.map((i) => `${escapeHtml(i.name)} ×${i.qty}`).join(', ')}</td>
                <td class="num">฿${money(b.total)}${b.points_discount > 0 ? `<div class="muted" style="font-size:11.5px">ใช้แต้มลด ฿${money(b.points_discount)}</div>` : ''}</td>
                <td class="num" style="white-space:nowrap">${b.points_earned ? `<span style="color:var(--ok)">+${b.points_earned}</span>` : ''} ${b.points_used ? `<span style="color:var(--bad)">−${b.points_used}</span>` : ''}</td></tr>`).join('')}</tbody></table></div>
          </div>`;
    }

    function adjust() {
        const box = document.createElement('div');
        box.className = 'modal-backdrop';
        box.innerHTML = `<div class="modal" role="dialog" aria-modal="true" style="width:440px">
            <div class="modal-head"><h2>ปรับแต้มสะสม</h2><button type="button" class="modal-close" data-x aria-label="ปิด">✕</button></div>
            <div class="modal-body">
              <div class="muted" style="font-size:13px;margin-bottom:8px">${escapeHtml(m.name)} · คงเหลือ ${count(m.points)} แต้ม (ใช้ได้ ${count(m.available)})</div>
              <label class="field"><span>จำนวนแต้ม (บวก = เพิ่ม, ลบ = หัก)</span><input type="number" step="1" data-pts placeholder="เช่น 10 หรือ -5"></label>
              <label class="field" style="margin-top:8px"><span>เหตุผล (บันทึกไว้ในประวัติ)</span><input type="text" maxlength="300" data-note></label>
              <div data-err></div>
            </div>
            <div class="modal-foot"><button type="button" class="btn secondary" data-x>ยกเลิก</button><button type="button" class="btn" data-ok>บันทึก</button></div></div>`;
        document.body.appendChild(box);
        box.querySelector('[data-pts]').focus();
        box.addEventListener('click', async (e) => {
            if (e.target.closest('[data-x]')) { box.remove(); return; }
            if (!e.target.closest('[data-ok]')) return;
            try {
                await api.post(`/members/${oa}/adjust`, { points: Number(box.querySelector('[data-pts]').value), note: box.querySelector('[data-note]').value });
                box.remove();
                ledgerPage = 1;
                await load();
            } catch (err) {
                box.querySelector('[data-err]').innerHTML = `<div class="error-box" style="margin-top:8px">${escapeHtml(err.message)}</div>`;
            }
        });
    }

    container.addEventListener('click', (e) => {
        if (e.target.closest('[data-back]')) { navigate('members'); return; }
        if (e.target.closest('[data-adjust]')) { adjust(); return; }
        const t = e.target.closest('[data-tab]');
        if (t && t.dataset.tab !== tab) {
            tab = t.dataset.tab;
            history.replaceState(null, '', `#/member?oa=${oa}${tab === 'buy' ? '&tab=buy' : ''}`);
            container.querySelectorAll('[data-tab]').forEach((b) => b.classList.toggle('active', b.dataset.tab === tab));
            container.querySelector('[data-pane]').innerHTML = '<div class="card-pad muted">กำลังโหลด...</div>';
            tab === 'points' ? loadLedger() : loadBuying();
            return;
        }
        const lp = e.target.closest('[data-lpage]');
        if (lp) { ledgerPage = Number(lp.dataset.lpage); loadLedger(); }
    });

    if (!oa) { alertDialog('ไม่พบสมาชิก'); navigate('members'); return; }
    await load();
}
