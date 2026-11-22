import { api } from '../api.js';
import { money, count, escapeHtml, todayISO, periodRange, DATE_PERIODS, qs, fmtDate } from '../util.js';

const KIND_ORDER = ['earn', 'welcome', 'survey', 'redeem', 'adjust', 'refund'];

/** สถิติการใช้แต้มสะสม: points given and spent in a period, members' share of sales, and what points were spent on. */
export async function render(container, { navigate, params = {} }) {
    let period = params.period || 'month';
    let from = params.from || '';
    let to = params.to || '';

    container.innerHTML = `
      <div class="card"><div class="card-pad" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <div class="tabs">${DATE_PERIODS.map(([k, l]) => `<button type="button" class="tab-btn" data-period="${k}">${l}</button>`).join('')}</div>
        <span data-custom style="display:flex;gap:6px;align-items:flex-end">
          <label class="field"><span>ตั้งแต่</span><input type="date" data-from></label>
          <label class="field"><span>ถึง</span><input type="date" data-to></label></span>
      </div></div>
      <div data-body style="margin-top:12px"></div>`;

    function range() {
        if (period === 'custom' && from && to) return [from, to];
        return periodRange(period, todayISO());
    }

    async function load() {
        const [a, b] = range();
        container.querySelectorAll('[data-period]').forEach((x) => x.classList.toggle('active', x.dataset.period === period));
        container.querySelector('[data-custom]').hidden = period !== 'custom';
        container.querySelector('[data-from]').value = a;
        container.querySelector('[data-to]').value = b;
        history.replaceState(null, '', `#/memberstats${qs({ period, from: period === 'custom' ? a : '', to: period === 'custom' ? b : '' })}`);
        const body = container.querySelector('[data-body]');
        body.innerHTML = '<div class="card card-pad muted">กำลังโหลด...</div>';
        let d;
        try {
            d = (await api.get(`/members/stats?from=${a}&to=${b}`)).data;
        } catch (e) {
            body.innerHTML = `<div class="card card-pad"><div class="error-box">${escapeHtml(e.message)}</div></div>`;
            return;
        }
        const k = (x) => d.kinds[x] || { points: 0, count: 0, members: 0 };
        const given = k('earn').points + k('welcome').points + k('survey').points;
        const share = d.sales.value ? (d.sales.member_value / d.sales.value) * 100 : 0;
        const maxDay = Math.max(1, ...d.daily.map((x) => Math.max(x.given, x.used)));
        body.innerHTML = `
          <div class="kpi-grid">
            <div class="kpi tone-income"><div class="lbl">แต้มที่ให้สมาชิก</div><div class="val">${count(given)}</div>
              <div class="sub">ซื้อสินค้า ${count(k('earn').points)} · ซื้อครั้งแรก ${count(k('welcome').points)} (${count(k('welcome').count)} คน) · แบบสอบถาม ${count(k('survey').points)}</div></div>
            <div class="kpi tone-cost"><div class="lbl">แต้มที่ใช้เป็นส่วนลด</div><div class="val">${count(-k('redeem').points)}</div>
              <div class="sub">${count(d.sales.redeem_bills)} บิล · ${count(k('redeem').members)} คน · ลดไป ฿${money(d.sales.points_discount)}</div></div>
            <div class="kpi tone-sales"><div class="lbl">ยอดขายที่ระบุสมาชิก</div><div class="val">${share.toFixed(1)}%</div>
              <div class="sub">฿${money(d.sales.member_value)} จาก ฿${money(d.sales.value)} · ${count(d.sales.member_bills)}/${count(d.sales.bills)} บิล · ${count(d.sales.buyers)} คน</div></div>
            <div class="kpi tone-owed"><div class="lbl">แต้มคงค้างทั้งระบบ (ตอนนี้)</div><div class="val">${count(d.outstanding.points)}</div>
              <div class="sub">${count(d.outstanding.members)} คนมีแต้ม · จองในตะกร้าอยู่ ${count(d.held.points)} แต้ม</div></div>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(420px,100%),1fr));gap:12px;margin-top:12px">
            <div class="card"><div class="card-pad"><h3 style="margin:0 0 8px;font-size:14.5px">แต้มรายวัน <small class="muted">(<span style="color:var(--ok)">■</span> ให้ <span style="color:var(--bad)">■</span> ใช้)</small></h3>
              ${d.daily.map((x) => `<div style="display:flex;align-items:center;gap:8px;font-size:12px;margin:2px 0">
                <span style="width:80px">${escapeHtml(fmtDate(x.day))}</span>
                <span style="flex:1"><span style="display:block;height:7px;border-radius:3px;background:var(--ok);width:${(x.given / maxDay) * 100}%"></span>
                  <span style="display:block;height:7px;border-radius:3px;background:var(--bad);margin-top:1px;width:${(x.used / maxDay) * 100}%"></span></span>
                <span style="width:90px;text-align:right">+${count(x.given)} / −${count(x.used)}</span></div>`).join('') || '<div class="muted">ไม่มีข้อมูลในช่วงนี้</div>'}
            </div></div>
            <div class="card"><div class="card-pad"><h3 style="margin:0 0 8px;font-size:14.5px">สินค้าที่ใช้แต้มลดราคา</h3>
              ${d.redeemed.length ? `<div class="table-wrap"><table><thead><tr><th>สินค้า</th><th class="num">ครั้ง</th><th class="num">ชิ้น</th><th class="num">แต้ม</th><th class="num">ส่วนลด</th></tr></thead>
                <tbody>${d.redeemed.map((r) => `<tr><td>${escapeHtml(r.name)}</td><td class="num">${count(r.times)}</td><td class="num">${count(r.qty)}</td><td class="num">${count(r.points)}</td><td class="num">฿${money(r.discount)}</td></tr>`).join('')}</tbody></table></div>`
                : '<div class="muted">ยังไม่มีการใช้แต้มในช่วงนี้</div>'}
              <h3 style="margin:14px 0 6px;font-size:14.5px">ยอดซื้อตามประเภทสมาชิก</h3>
              ${d.by_type.map((t) => `<div style="font-size:13px">${t.type === 'student' ? 'นักเรียน/นักศึกษา' : 'ครูและบุคลากร'}: <b>฿${money(t.spend)}</b> · ${count(t.bills)} บิล · ${count(t.buyers)} คน</div>`).join('') || '<div class="muted">-</div>'}
              ${d.surveys.length ? `<h3 style="margin:14px 0 6px;font-size:14.5px">แบบสอบถาม</h3>${d.surveys.map((s) => `<div style="font-size:13px">${escapeHtml(s.title)}: ${count(s.responses)} คนตอบ · ให้ ${count(s.points)} แต้ม</div>`).join('')}` : ''}
            </div></div>
          </div>
          <div class="card" style="margin-top:12px"><div class="card-pad"><h3 style="margin:0 0 8px;font-size:14.5px">สมาชิกที่ซื้อมากที่สุดในช่วงนี้</h3>
            ${d.top_members.length ? `<div class="table-wrap"><table><thead><tr><th>สมาชิก</th><th>ประเภท / แผนก</th><th class="num">บิล</th><th class="num">ยอดซื้อ</th><th class="num">ได้แต้ม</th><th class="num">ใช้แต้ม</th></tr></thead>
              <tbody>${d.top_members.map((t) => `<tr data-oa="${t.oa_user_id}" style="cursor:pointer"><td><b>${escapeHtml(t.name)}</b></td><td>${escapeHtml(t.type_label)} <span class="muted" style="font-size:12px">${escapeHtml(t.department || '')}</span></td>
                <td class="num">${count(t.bills)}</td><td class="num">฿${money(t.spend)}</td><td class="num">${count(t.earned)}</td><td class="num">${count(t.used)}</td></tr>`).join('')}</tbody></table></div>`
              : '<div class="muted">ยังไม่มีการขายที่ระบุสมาชิกในช่วงนี้</div>'}
          </div></div>`;
    }

    container.addEventListener('click', (e) => {
        const p = e.target.closest('[data-period]');
        if (p) { period = p.dataset.period; load(); return; }
        const row = e.target.closest('[data-oa]');
        if (row && row.dataset.oa !== 'null') navigate('member', { oa: row.dataset.oa, tab: 'buy' });
    });
    container.addEventListener('change', (e) => {
        if (e.target.matches('[data-from], [data-to]')) {
            from = container.querySelector('[data-from]').value;
            to = container.querySelector('[data-to]').value;
            if (from && to) load();
        }
    });
    await load();
}
