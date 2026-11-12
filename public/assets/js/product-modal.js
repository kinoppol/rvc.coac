import { api } from './api.js';
import { money, count, escapeHtml, fmtDate, fmtDateTime, todayISO, compact, niceScale } from './util.js';
import { alertDialog } from './dialog.js';
import { skBlock } from './skeleton.js';
import { openDetailModal } from './detail-modal.js';

// Product card: basic details and every document that moved its stock (purchase, consignment
// receipt, sale, return to the consignor, stock count) with the balance after each, up to now.

const KIND = {
    opening: ['ยอดยกมา', 'muted'],
    purchase: ['ซื้อเข้า', 'ok'],
    receive: ['รับฝาก', 'ok'],
    sale: ['ขาย', 'bad'],
    pos: ['ขาย (นำเข้า POS)', 'bad'],
    return: ['คืนผู้ฝาก', 'warn'],
    count: ['ตรวจนับ', 'warn'],
    adjust: ['ปรับยอด', 'muted'],
    split: ['แยกสินค้า', 'warn'],
    damage: ['สินค้าเสียหาย', 'bad'],
};
const GROUPS = [
    ['all', 'ทั้งหมด', () => true],
    ['in', 'รับเข้า', (r) => ['purchase', 'receive', 'opening'].includes(r.kind)],
    ['out', 'ขาย / คืน', (r) => ['sale', 'pos', 'return'].includes(r.kind)],
    ['count', 'ตรวจนับ / ปรับยอด', (r) => ['count', 'adjust'].includes(r.kind)],
    ['split', 'แยกสินค้า', (r) => r.kind === 'split'],
    ['damage', 'สินค้าเสียหาย', (r) => r.kind === 'damage'],
];

// ---- Daily sales trend (pieces or baht per day, with a 7-day average) ----

export const TREND_RANGES = [[14, '14 วัน'], [30, '30 วัน'], [90, '90 วัน'], [0, 'ทั้งหมด']];
export const addDays = (ymd, n) => { const x = new Date(`${ymd}T00:00:00Z`); x.setUTCDate(x.getUTCDate() + n); return x.toISOString().slice(0, 10); };
export const thDay = (ymd, opts) => new Date(`${ymd}T00:00:00Z`).toLocaleDateString('th-TH', { timeZone: 'UTC', ...opts });

/** One entry per day from the first stock movement to today: net pieces sold, baht, bills, closing balance. */
export function dailySeries(d) {
    const rows = d.movements.filter((r) => r.date);
    if (!rows.some((r) => r.kind === 'sale' || r.kind === 'pos')) return null;
    const today = todayISO();
    const first = rows.reduce((m, r) => (r.date < m ? r.date : m), today);
    const day = new Map();
    const close = new Map();
    for (const r of rows) {
        close.set(r.date, r.balance); // movements run in document-date order: the last one is the day's close
        if (r.kind !== 'sale' && r.kind !== 'pos') continue;
        const e = day.get(r.date) || { qty: 0, amount: 0, bills: new Set() };
        const q = -Number(r.qty); // a refund line comes back as a negative sale
        e.qty += q;
        e.amount += q * Number(r.sale_price || 0);
        if (r.doc_no) e.bills.add(r.doc_no);
        day.set(r.date, e);
    }
    const priceAt = new Map();
    for (const c of d.price_changes || []) {
        if (c.old_price === null || c.new_price === null || Math.abs(c.old_price - c.new_price) < 0.005) continue;
        const k = String(c.at).slice(0, 10);
        priceAt.set(k, [...(priceAt.get(k) || []), c]);
    }
    const out = [];
    let bal = 0;
    for (let k = first; k <= today; k = addDays(k, 1)) {
        if (close.has(k)) bal = close.get(k);
        const e = day.get(k);
        out.push({
            key: k, qty: e ? e.qty : 0, amount: e ? Math.round(e.amount * 100) / 100 : 0, bills: e ? e.bills.size : 0,
            balance: bal, prices: priceAt.get(k) || [],
        });
    }
    // Trailing 7-day average (shorter at the very start, before 7 days exist).
    out.forEach((b, i) => {
        const w = out.slice(Math.max(0, i - 6), i + 1);
        b.avgQty = w.reduce((s, x) => s + x.qty, 0) / w.length;
        b.avgAmount = w.reduce((s, x) => s + x.amount, 0) / w.length;
    });
    return out;
}

function info(label, value) {
    return `<div class="pm-info"><div class="muted">${escapeHtml(label)}</div><div>${value}</div></div>`;
}

export async function openProductModal(productId, navigate) {
    const box = document.createElement('div');
    box.className = 'modal-backdrop';
    box.innerHTML = '<div class="modal" role="dialog" aria-modal="true" style="width:960px"><div class="modal-body">' + skBlock(6, 8) + '</div></div>';
    document.body.appendChild(box);
    let ro = null;
    const close = () => { box.remove(); document.removeEventListener('keydown', onKey); if (ro) ro.disconnect(); };
    const onKey = (e) => { if (e.key === 'Escape' && [...document.querySelectorAll('.modal-backdrop')].pop() === box) close(); };
    document.addEventListener('keydown', onKey);

    let d;
    try {
        d = (await api.get(`/inventory/${productId}/movements`)).data;
    } catch (e) {
        close();
        alertDialog(e.message, { tone: 'error' });
        return;
    }
    const p = d.product;
    const t = d.totals;
    let group = 'all';
    let newestFirst = false; // a stock card reads oldest first, in document-date order

    function tableHtml() {
        const fn = GROUPS.find(([k]) => k === group)[2];
        const rows = d.movements.filter(fn);
        if (newestFirst) rows.reverse();
        if (!rows.length) return '<div class="empty" style="padding:14px">ไม่มีรายการ</div>';
        return `<div class="table-wrap" style="max-height:420px;border:1px solid var(--border);border-radius:10px"><table>
          <thead><tr><th>วันที่เอกสาร</th><th>รายการ</th><th>เลขที่เอกสาร</th><th>คู่ค้า / หมายเหตุ</th>
            <th class="num">รับ</th><th class="num">จ่าย</th><th class="num">คงเหลือ</th><th class="num">ทุน/ชิ้น</th><th class="num">มูลค่าทุน</th></tr></thead>
          <tbody>${rows.map((r) => {
              const [label, tone] = KIND[r.kind] || [r.kind, 'muted'];
              const detail = [r.party, r.note].filter(Boolean).map(escapeHtml).join('<br>');
              return `<tr>
                <td style="white-space:nowrap">${r.date ? escapeHtml(fmtDate(r.date)) : '<span class="muted">-</span>'}
                  ${r.late ? `<div class="pm-late" title="บันทึกเข้าระบบหลังวันที่ในเอกสาร">บันทึกล่าช้า ${escapeHtml(fmtDateTime(r.at))}</div>`
                      : r.at ? `<div class="muted" style="font-size:11.5px">บันทึก ${escapeHtml(fmtDateTime(r.at))}</div>` : ''}</td>
                <td><span class="pill ${tone}">${label}</span></td>
                <td style="white-space:nowrap">${r.doc_no ? `<button type="button" class="prod-link" data-doc="${escapeHtml(r.kind)}|${escapeHtml(r.doc_no)}" title="เปิดดูเอกสาร">${escapeHtml(r.doc_no)}</button>` : '<span class="muted">-</span>'}
                  ${r.user_name ? `<div class="muted" style="font-size:11.5px">${escapeHtml(r.user_name)}</div>` : ''}</td>
                <td style="font-size:12.5px">${detail || '<span class="muted">-</span>'}</td>
                <td class="num pm-in">${r.qty_in ? count(r.qty_in) : ''}</td>
                <td class="num pm-out">${r.qty_out ? count(r.qty_out) : ''}</td>
                <td class="num" style="font-weight:600${r.balance < 0 ? ';color:var(--bad)' : ''}">${count(r.balance)}</td>
                <td class="num">${r.unit_cost !== null ? money(r.unit_cost) : '<span class="muted">-</span>'}</td>
                <td class="num">${money(Math.abs(r.value))}</td>
              </tr>`;
          }).join('')}</tbody></table></div>`;
    }

    /** Opening + received − sold − returned ± adjustments = closing, in pieces and at cost. */
    function summaryHtml() {
        const line = (label, v, sign, cls = '') => `<tr class="${cls}"><td>${label}</td>
            <td class="num">${sign}${count(Math.abs(v.qty))}</td><td class="num">${sign}${money(Math.abs(v.value))}</td></tr>`;
        const adj = t.adjusted;
        const adjSign = adj.qty < 0 || (adj.qty === 0 && adj.value < 0) ? '−' : '+';
        return `<table class="pm-sum">
          <thead><tr><th>สรุปบัญชีคุมสินค้า</th><th class="num">จำนวน (ชิ้น)</th><th class="num">มูลค่าทุน (บาท)</th></tr></thead>
          <tbody>
            ${line('ยอดยกมา (ไม่มีเอกสารรับเข้า)', t.opening, '')}
            ${line('บวก รับเข้า (ซื้อ / รับฝาก)', t.received, '+')}
            ${line('หัก ขายออก (ต้นทุนขาย)', t.sold, '−')}
            ${t.returned.qty ? line('หัก คืนผู้ฝาก', t.returned, '−') : ''}
            ${t.damaged && t.damaged.qty ? line('หัก สินค้าเสียหาย (ตัดสต๊อก)', t.damaged, '−') : ''}
            ${t.split && (t.split.qty || t.split.value) ? line(t.split.qty < 0 ? 'หัก แยกออกเป็นสินค้าอื่น' : 'บวก แยกมาจากสินค้าแพ็ค', t.split, t.split.qty < 0 ? '−' : '+') : ''}
            ${line('ปรับปรุงจากการตรวจนับ / ส่วนต่าง', adj, adjSign)}
            ${line('คงเหลือยกไป', t.closing, '', 'pm-sum-total')}
          </tbody></table>
          <div class="muted" style="font-size:11.5px;margin-top:4px">มูลค่าคงเหลือคิดจากต้นทุนเฉลี่ยปัจจุบัน ฿${money(p.cost_price)}/ชิ้น · ต้นทุนขายตามที่บันทึกไว้ในแต่ละบิล</div>`;
    }

    const series = dailySeries(d);
    let trendRange = 30;
    let trendMetric = 'qty';

    /** Last 7 days against the 7 before: the headline of the trend. */
    function trendDelta() {
        if (series.length < 14) return '';
        const v = (b) => (trendMetric === 'qty' ? b.qty : b.amount);
        const last = series.slice(-7).reduce((s, b) => s + v(b), 0);
        const prev = series.slice(-14, -7).reduce((s, b) => s + v(b), 0);
        const fmt = (x) => (trendMetric === 'qty' ? `${(x / 7).toFixed(1)} ชิ้น/วัน` : `฿${money(x / 7)}/วัน`);
        if (!prev && !last) return '<span class="muted">14 วันล่าสุดไม่มียอดขาย</span>';
        if (!prev) return `<span style="color:var(--ok);font-weight:600">▲ 7 วันล่าสุด ${fmt(last)}</span> <span class="muted">(7 วันก่อนหน้าไม่มียอดขาย)</span>`;
        const pct = ((last - prev) / prev) * 100;
        const up = pct >= 0;
        return `<span style="color:var(${up ? '--ok' : '--bad'});font-weight:600">${up ? '▲ เพิ่มขึ้น' : '▼ ลดลง'} ${Math.abs(pct).toFixed(1)}%</span>
          <span class="muted">7 วันล่าสุดเฉลี่ย ${fmt(last)} เทียบ 7 วันก่อนหน้า ${fmt(prev)}</span>`;
    }

    function trendHtml() {
        if (!series) return '';
        const shown = trendRange ? series.slice(-trendRange) : series;
        const qty = shown.reduce((s, b) => s + b.qty, 0);
        const amount = shown.reduce((s, b) => s + b.amount, 0);
        return `<section class="pm-trend">
          <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:flex-start">
            <div>
              <div style="font-weight:600;font-size:14px">แนวโน้มการขายรายวัน</div>
              <div class="muted" style="font-size:12.5px;margin-top:2px">${count(shown.length)} วัน: ขาย ${count(qty)} ชิ้น · ฿${money(amount)} · เฉลี่ย ${(qty / shown.length).toFixed(1)} ชิ้น/วัน</div>
              <div style="font-size:12.5px;margin-top:2px">${trendDelta()}</div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <div class="tabs" role="group" aria-label="ช่วงเวลา">${TREND_RANGES.map(([n, l]) => `<button type="button" class="tab-btn ${n === trendRange ? 'active' : ''}" data-trange="${n}">${l}</button>`).join('')}</div>
              <div class="tabs" role="group" aria-label="หน่วย">${[['qty', 'ชิ้น'], ['amount', 'บาท']].map(([k, l]) => `<button type="button" class="tab-btn ${k === trendMetric ? 'active' : ''}" data-tmetric="${k}">${l}</button>`).join('')}</div>
            </div>
          </div>
          <div class="pm-trend-chart" style="position:relative;margin-top:8px"></div>
          <div class="pm-trend-legend muted">
            <span><i style="background:var(--pri)"></i>ยอดขายต่อวัน</span>
            <span><i style="height:2px;background:var(--warn)"></i>เฉลี่ย 7 วัน</span>
            <span><i style="background:var(--bad);opacity:.18"></i>สินค้าหมด (คงเหลือ 0)</span>
            <span><i style="width:0;border-left:1.5px dashed var(--muted)"></i>ปรับราคาขาย</span>
          </div>
        </section>`;
    }

    /** Columns per day, a 7-day average line, out-of-stock days shaded and price changes marked. */
    function trendChart() {
        const host = box.querySelector('.pm-trend-chart');
        if (!host) return;
        const bs = trendRange ? series.slice(-trendRange) : series;
        const val = (b) => (trendMetric === 'qty' ? b.qty : b.amount);
        const avg = (b) => (trendMetric === 'qty' ? b.avgQty : b.avgAmount);
        const W = Math.max(host.clientWidth, 280);
        const H = 190;
        const m = { l: 40, r: 8, t: 16, b: 24 };
        const pw = W - m.l - m.r;
        const ph = H - m.t - m.b;
        const max = Math.max(0, ...bs.map((b) => Math.max(val(b), avg(b))));
        const { top, step } = niceScale(trendMetric === 'qty' ? Math.max(max, 4) : max);
        const band = pw / bs.length;
        const bw = Math.max(1, Math.min(18, band * 0.62, band - 1));
        const y = (v) => m.t + ph - (Math.max(v, 0) / top) * ph;
        const base = m.t + ph;
        const today = todayISO();
        const every = Math.max(1, Math.ceil(44 / band));
        const fmtV = (v) => (trendMetric === 'qty' ? `${v.toFixed(1)} ชิ้น` : `฿${money(v)}`);

        const grid = [];
        for (let v = 0; v <= top + 1e-9; v += step) {
            grid.push(`<line x1="${m.l}" x2="${W - m.r}" y1="${y(v)}" y2="${y(v)}" stroke="var(--border)" stroke-width="1"/>
              <text x="${m.l - 6}" y="${y(v) + 4}" text-anchor="end" font-size="11" fill="var(--muted)">${compact(v)}</text>`);
        }
        let prevMonth = '';
        const cols = bs.map((b, i) => {
            const x0 = m.l + band * i;
            const cx = x0 + band / 2;
            const out = b.balance <= 0 ? `<rect x="${x0}" y="${m.t}" width="${band}" height="${ph}" fill="var(--bad)" opacity=".08"/>` : '';
            const price = b.prices.length ? `<line x1="${cx}" x2="${cx}" y1="${m.t}" y2="${base}" stroke="var(--muted)" stroke-width="1.5" stroke-dasharray="3 3"/>
              <text x="${cx}" y="${m.t - 4}" text-anchor="middle" font-size="10.5" font-weight="600" fill="var(--muted)">฿</text>` : '';
            let col = '';
            const v = val(b);
            if (v > 0) {
                const h = Math.max(1, base - y(v));
                const r = Math.min(3, bw / 2, h);
                const xl = cx - bw / 2;
                const yt = base - h;
                col = `<path fill="var(--pri)" d="M${xl},${base} V${yt + r} Q${xl},${yt} ${xl + r},${yt} H${xl + bw - r} Q${xl + bw},${yt} ${xl + bw},${yt + r} V${base} Z"/>`;
            }
            let label = '';
            if (i % every === 0) {
                const mo = b.key.slice(0, 7);
                const txt = mo !== prevMonth ? thDay(b.key, { day: 'numeric', month: 'short' }) : thDay(b.key, { day: 'numeric' });
                prevMonth = mo;
                label = `<text x="${cx}" y="${H - 7}" text-anchor="middle" font-size="11" fill="${b.key === today ? 'var(--text)' : 'var(--muted)'}" font-weight="${b.key === today ? 700 : 400}">${escapeHtml(txt)}</text>`;
            }
            return `${out}${price}${col}${label}`;
        }).join('');
        const line = bs.map((b, i) => `${i ? 'L' : 'M'}${(m.l + band * i + band / 2).toFixed(1)},${y(avg(b)).toFixed(1)}`).join(' ');
        const hits = bs.map((b, i) => `<rect data-ti="${i}" x="${m.l + band * i}" y="${m.t}" width="${band}" height="${ph}" fill="transparent"/>`).join('');

        host.innerHTML = `
          <svg width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" role="img" aria-label="กราฟยอดขายรายวันของ ${escapeHtml(p.name)}" style="display:block;overflow:visible">
            ${grid.join('')}
            <rect class="pt-hover" x="0" y="${m.t}" width="0" height="${ph}" fill="var(--hover)" pointer-events="none"/>
            ${cols}
            ${bs.length > 1 ? `<path d="${line}" fill="none" stroke="var(--warn)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" pointer-events="none"/>` : ''}
            <line x1="${m.l}" x2="${W - m.r}" y1="${base}" y2="${base}" stroke="var(--border)" stroke-width="1"/>
            ${hits}
          </svg>
          <div class="pt-tip" role="tooltip" style="position:absolute;pointer-events:none;display:none;background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:8px 11px;font-size:12.5px;box-shadow:0 8px 24px rgba(16,24,40,.18);white-space:nowrap;z-index:2"></div>`;

        const tip = host.querySelector('.pt-tip');
        const hover = host.querySelector('.pt-hover');
        const kv = (k, v) => `<div style="display:flex;justify-content:space-between;gap:16px"><span class="muted">${k}</span><span>${v}</span></div>`;
        const show = (i) => {
            const b = bs[i];
            hover.setAttribute('x', m.l + band * i);
            hover.setAttribute('width', band);
            tip.innerHTML = `<div style="font-weight:700;margin-bottom:3px">${escapeHtml(thDay(b.key, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }))}</div>
              ${kv('ขาย', `<b>${count(b.qty)} ชิ้น</b>`)}
              ${kv('ยอดเงิน', `฿${money(b.amount)}`)}
              ${b.bills ? kv('บิล', count(b.bills)) : ''}
              ${kv('เฉลี่ย 7 วัน', fmtV(avg(b)))}
              ${kv('คงเหลือสิ้นวัน', b.balance <= 0 ? '<span style="color:var(--bad);font-weight:600">หมด</span>' : `${count(b.balance)} ชิ้น`)}
              ${b.prices.map((c) => `<div style="margin-top:3px">ปรับราคา ฿${money(c.old_price)} → <b>฿${money(c.new_price)}</b></div>`).join('')}`;
            tip.style.display = 'block';
            const cx = m.l + band * i + band / 2;
            tip.style.left = `${Math.min(Math.max(cx - tip.offsetWidth / 2, 0), W - tip.offsetWidth)}px`;
            tip.style.top = `${Math.max(y(Math.max(val(b), avg(b))) - tip.offsetHeight - 10, 0)}px`;
        };
        host.querySelectorAll('[data-ti]').forEach((n) => {
            n.addEventListener('mouseenter', () => show(Number(n.dataset.ti)));
            n.addEventListener('click', () => show(Number(n.dataset.ti)));
        });
        host.querySelector('svg').addEventListener('mouseleave', () => { tip.style.display = 'none'; hover.setAttribute('width', 0); });
    }

    /** Range / unit buttons redraw only this section, so the stock table below keeps its scroll. */
    function bindTrend() {
        box.querySelectorAll('[data-trange]').forEach((b) => b.addEventListener('click', () => { trendRange = Number(b.dataset.trange); redrawTrend(); }));
        box.querySelectorAll('[data-tmetric]').forEach((b) => b.addEventListener('click', () => { trendMetric = b.dataset.tmetric; redrawTrend(); }));
        trendChart();
    }
    function redrawTrend() {
        const sec = box.querySelector('.pm-trend');
        if (!sec) return;
        sec.outerHTML = trendHtml();
        bindTrend();
    }
    if (series) {
        // Redraw at the new width when the window changes size.
        let lastW = 0;
        ro = new ResizeObserver(() => {
            const host = box.querySelector('.pm-trend-chart');
            if (host && Math.abs(host.clientWidth - lastW) > 4) { lastW = host.clientWidth; trendChart(); }
        });
        ro.observe(box.querySelector('.modal'));
    }

    /** Older photos kept at a merge of duplicates / when a new photo was taken; any can be put back. */
    function photoBackupsHtml() {
        const list = d.photo_backups || [];
        if (!list.length) return '';
        return `
            <details style="margin-top:12px">
              <summary style="cursor:pointer;font-weight:600;font-size:14px">รูปสำรอง (${count(list.length)})</summary>
              <div class="pm-backups">${list.map((b) => `
                <div class="pm-backup">
                  <img src="${api.downloadUrl(`/products/${p.id}/photo?size=thumb&token=${encodeURIComponent(b.image_token)}`)}" alt="" loading="lazy">
                  <div class="muted" style="font-size:11.5px">${escapeHtml(fmtDateTime(b.taken_at || b.created_at))}</div>
                  ${b.note ? `<div class="muted" style="font-size:11.5px">${escapeHtml(b.note)}</div>` : ''}
                  <button type="button" class="btn secondary sm" data-restore-photo="${escapeHtml(b.image_token)}">ใช้รูปนี้</button>
                </div>`).join('')}</div>
              <div class="muted" style="font-size:12px;margin-top:6px">รูปเก่าไม่ถูกลบ · กด "ใช้รูปนี้" แล้วรูปปัจจุบันจะกลายเป็นรูปสำรองแทน</div>
            </details>`;
    }

    /**
     * What each supplier charged for this product (store goods): latest cost per piece compared
     * with the cheapest, lowest / highest / average paid, then every purchase. Names merged as
     * aliases of one supplier count as that supplier.
     */
    function supplierPricesHtml() {
        const list = d.purchase_prices || [];
        if (p.consignor_id != null) return '';
        if (!list.length) {
            return '<div class="muted" style="font-size:12.5px;margin-top:12px">ราคาซื้อจากผู้ขาย: ยังไม่มีใบซื้อของสินค้านี้</div>';
        }
        const by = new Map();
        list.forEach((r) => { // newest first: the first row of each supplier is its latest price
            if (!by.has(r.supplier)) by.set(r.supplier, { name: r.supplier, last: r, rows: [], names: new Set() });
            const g = by.get(r.supplier);
            g.rows.push(r);
            if (r.typed_name && r.typed_name !== r.supplier) g.names.add(r.typed_name);
        });
        const sups = [...by.values()].map((g) => {
            const units = g.rows.reduce((t, r) => t + r.units, 0);
            const paid = g.rows.reduce((t, r) => t + r.units * r.piece_cost, 0);
            const costs = g.rows.map((r) => r.piece_cost);
            return { ...g, units, avg: units ? paid / units : 0, min: Math.min(...costs), max: Math.max(...costs) };
        }).sort((a, b) => a.last.piece_cost - b.last.piece_cost);
        const cheapest = sups[0].last.piece_cost;
        const diff = (v) => {
            const x = Math.round((v - cheapest) * 100) / 100;
            if (sups.length < 2) return '';
            if (x < 0.005) return '<span class="pill ok">ถูกสุด</span>';
            return `<span style="color:var(--bad);font-weight:600;white-space:nowrap">+฿${money(x)}${cheapest > 0 ? ` (+${Math.round((x / cheapest) * 1000) / 10}%)` : ''}</span>`;
        };
        const pack = (r) => (r.pack_size > 1 ? `<div class="muted" style="font-size:11.5px">แพ็ค ${count(r.pack_size)} ชิ้น ฿${money(r.unit_cost)}</div>` : '');
        return `
            <section style="margin-top:14px">
              <h3 style="margin:0 0 6px;font-size:14px">ราคาซื้อจากผู้ขายแต่ละราย <span class="muted" style="font-weight:400;font-size:12px">(ต้นทุนต่อชิ้น เรียงจากราคาล่าสุดที่ถูกที่สุด)</span></h3>
              <div class="table-wrap" style="border:1px solid var(--border);border-radius:10px"><table>
                <thead><tr><th>ผู้ขาย</th><th class="num">ราคาล่าสุด/ชิ้น</th><th>เทียบรายที่ถูกสุด</th><th class="num">ต่ำสุด</th><th class="num">สูงสุด</th><th class="num">เฉลี่ย</th><th class="num">ซื้อ</th><th>ซื้อล่าสุด</th></tr></thead>
                <tbody>${sups.map((g) => `<tr>
                  <td><b>${escapeHtml(g.name)}</b>${g.names.size ? `<div class="muted" style="font-size:11.5px">ในใบเสร็จ: ${[...g.names].map(escapeHtml).join(', ')}</div>` : ''}</td>
                  <td class="num" style="font-weight:600">฿${money(g.last.piece_cost)}${pack(g.last)}</td>
                  <td>${diff(g.last.piece_cost)}</td>
                  <td class="num">${money(g.min)}</td>
                  <td class="num">${money(g.max)}</td>
                  <td class="num">${money(g.avg)}</td>
                  <td class="num" style="white-space:nowrap">${count(g.rows.length)} ครั้ง<div class="muted" style="font-size:11.5px">${count(g.units)} ชิ้น</div></td>
                  <td style="white-space:nowrap">${escapeHtml(fmtDate(g.last.date))}<div><button type="button" class="prod-link" data-doc="purchase|${escapeHtml(g.last.doc_no)}" style="font-size:11.5px">${escapeHtml(g.last.doc_no)}</button></div></td>
                </tr>`).join('')}</tbody>
              </table></div>
              <details style="margin-top:8px">
                <summary style="cursor:pointer;font-size:13px">ประวัติการซื้อทั้งหมด (${count(list.length)})</summary>
                <div class="table-wrap" style="margin-top:6px;max-height:300px;border:1px solid var(--border);border-radius:10px"><table>
                  <thead><tr><th>วันที่</th><th>เลขที่</th><th>ผู้ขาย</th><th class="num">จำนวน</th><th class="num">ทุน/หน่วยซื้อ</th><th class="num">ทุน/ชิ้น</th></tr></thead>
                  <tbody>${list.map((r) => `<tr>
                    <td style="white-space:nowrap">${escapeHtml(fmtDate(r.date))}</td>
                    <td><button type="button" class="prod-link" data-doc="purchase|${escapeHtml(r.doc_no)}">${escapeHtml(r.doc_no)}</button></td>
                    <td>${escapeHtml(r.supplier)}${r.typed_name && r.typed_name !== r.supplier ? `<div class="muted" style="font-size:11.5px">ในใบเสร็จ: ${escapeHtml(r.typed_name)}</div>` : ''}</td>
                    <td class="num">${count(r.qty)}${r.pack_size > 1 ? ` × ${count(r.pack_size)}` : ''}</td>
                    <td class="num">${money(r.unit_cost)}</td>
                    <td class="num">${money(r.piece_cost)}</td>
                  </tr>`).join('')}</tbody>
                </table></div>
              </details>
            </section>`;
    }

    /** When the shelf price changed; bills sold before keep the price they were sold at. */
    function priceChangesHtml() {
        const list = d.price_changes || [];
        if (!list.length) return '';
        const pair = (a, b, tag = false) => (a !== null && b !== null && Math.abs(a - b) >= 0.005 ? `฿${money(a)} → <b>฿${money(b)}</b>${tag && b < a ? ' <span class="pill ok">ลดราคา</span>' : ''}` : '');
        return `
            <details style="margin-top:12px">
              <summary style="cursor:pointer;font-weight:600;font-size:14px">ประวัติการปรับราคา (${count(list.length)})</summary>
              <div class="table-wrap" style="margin-top:8px"><table>
                <thead><tr><th>เมื่อ</th><th>ราคาขาย</th><th>${p.consignor_id != null ? 'ผู้ฝากได้' : 'ต้นทุน'}</th><th>โดย</th><th>สาเหตุ</th></tr></thead>
                <tbody>${list.map((c) => `<tr><td style="font-size:12.5px;white-space:nowrap">${escapeHtml(fmtDateTime(c.at))}</td>
                  <td>${pair(c.old_price, c.new_price, true) || '<span class="muted">-</span>'}${c.promo ? `<div><span class="pill promo">ราคาโปร${c.regular_price ? ` · ปกติ ฿${money(c.regular_price)}` : ''}</span></div>` : ''}</td>
                  <td>${pair(c.old_cost, c.new_cost) || '<span class="muted">-</span>'}</td>
                  <td style="font-size:12.5px">${escapeHtml(c.user_name || '-')}</td>
                  <td style="font-size:12.5px;max-width:260px">${c.source ? `<b>${escapeHtml(c.source)}</b>` : '<span class="muted">ไม่ทราบ (บันทึกก่อนมีการเก็บสาเหตุ)</span>'}${c.reason ? `<div class="muted">${escapeHtml(c.reason)}</div>` : ''}</td></tr>`).join('')}</tbody>
              </table></div>
              <div class="muted" style="font-size:12px;margin-top:6px">บิลที่ขายก่อนปรับราคายังคงราคาที่ขายจริง (ดูได้ในรายการขายด้านล่าง)</div>
            </details>`;
    }

    function draw() {
        const photo = p.image_token
            ? `<img class="pm-photo" src="${api.downloadUrl(`/products/${p.id}/photo?size=thumb&v=${encodeURIComponent(p.image_token)}`)}" alt="">`
            : '<div class="pm-photo pm-nophoto" aria-hidden="true">📷</div>';
        box.querySelector('.modal').innerHTML = `
          <div class="modal-head"><h2>${escapeHtml(p.name)}</h2><button type="button" class="modal-close" data-x aria-label="ปิด">✕</button></div>
          <div class="modal-body" style="padding-top:12px">
            <div class="pm-top">
              ${photo}
              <div class="pm-grid">
                ${info('เจ้าของสินค้า', p.consignor_id != null ? `ฝากขาย: ${escapeHtml(p.consignor_code ? `${p.consignor_code} - ` : '')}${escapeHtml(p.consignor_name || '')}` : 'สินค้าของร้าน')}
                ${info('บาร์โค้ดชิ้น', p.barcode ? escapeHtml(p.barcode) : '<span class="muted">-</span>')}
                ${info('บาร์โค้ดแพ็ค', p.pack_barcodes.length ? p.pack_barcodes.map((b) => `${b.barcode ? escapeHtml(b.barcode) : 'ไม่มีบาร์โค้ด'} <span class="pill muted">×${b.pack_size}</span>${b.sale_price != null ? ` <span class="pill ok" title="ราคาขายยกแพ็ค">ยกแพ็ค ฿${money(b.sale_price)}</span>` : ''}`).join('<br>') : '<span class="muted">-</span>')}
                ${info(p.consignor_id != null ? 'ผู้ฝากได้/ชิ้น' : 'ต้นทุน/ชิ้น (เฉลี่ย)', `฿ ${money(p.cost_price)}`)}
                ${info('ราคาขาย', `฿ ${money(p.sale_price)} <span class="muted">(กำไร ${p.margin_percent}%)</span>`)}
                ${info('มูลค่าทุนคงเหลือ', `฿ ${money(p.qty_stock * p.cost_price)}`)}
                ${info('เพิ่มเข้าระบบ', escapeHtml(fmtDate(p.created_at)))}
                ${info('ขายล่าสุด', p.last_sale ? escapeHtml(fmtDate(p.last_sale)) : '<span class="muted">-</span>')}
                ${p.expiry_date ? info('วันหมดอายุ', escapeHtml(fmtDate(p.expiry_date))) : ''}
              </div>
            </div>
            ${photoBackupsHtml()}
            ${supplierPricesHtml()}
            ${summaryHtml()}
            ${trendHtml()}
            ${priceChangesHtml()}
            <div class="actions-row" style="justify-content:space-between;margin:14px 0 8px">
              <div class="tabs" role="group" aria-label="กรองความเคลื่อนไหว">
                ${GROUPS.filter(([k, , fn]) => !['split', 'damage'].includes(k) || d.movements.some(fn)).map(([k, l, fn]) => `<button type="button" class="tab-btn ${k === group ? 'active' : ''}" data-group="${k}">${l} (${count(d.movements.filter(fn).length)})</button>`).join('')}
              </div>
              <button type="button" class="btn secondary sm" data-order>${newestFirst ? 'ล่าสุดก่อน ▼' : 'เก่าสุดก่อน ▲'}</button>
            </div>
            ${tableHtml()}
            <div class="muted" style="font-size:12px;margin-top:8px">เรียงตามวันที่ในเอกสาร (วันเดียวกัน: รับก่อนจ่าย แล้วปรับปรุงตรวจนับท้ายวัน) · รายการที่บันทึกล่าช้ายังอยู่ในวันที่ของเอกสาร · ตรวจนับ (อนุมัติแล้ว) ตั้งยอดคงเหลือเท่ากับจำนวนที่นับได้ · ใบซื้อ/การนำเข้าที่ถูกยกเลิกไม่แสดง</div>
          </div>
          <div class="modal-foot"><button type="button" class="btn secondary" data-x>ปิด</button></div>`;
        box.querySelectorAll('[data-x]').forEach((b) => b.addEventListener('click', close));
        if (series) bindTrend();
        box.querySelectorAll('[data-group]').forEach((b) => b.addEventListener('click', () => { group = b.dataset.group; draw(); }));
        box.querySelectorAll('[data-doc]').forEach((b) => b.addEventListener('click', () => {
            const [kind, docNo] = b.dataset.doc.split('|');
            openDetailModal(
                async () => (await api.get(`/inventory/document?kind=${encodeURIComponent(kind)}&doc_no=${encodeURIComponent(docNo)}`)).data,
                navigate && ((page) => { close(); navigate(page); }),
            );
        }));
        box.querySelector('[data-order]').addEventListener('click', () => { newestFirst = !newestFirst; draw(); });
        box.querySelectorAll('[data-restore-photo]').forEach((b) => b.addEventListener('click', async () => {
            b.disabled = true;
            try {
                await api.post(`/inventory/${p.id}/photo/restore`, { token: b.dataset.restorePhoto });
                const fresh = (await api.get(`/inventory/${productId}/movements`)).data;
                p.image_token = fresh.product.image_token;
                d.photo_backups = fresh.photo_backups;
                draw();
            } catch (e) {
                b.disabled = false;
                alertDialog(e.message, { tone: 'error' });
            }
        }));
    }
    draw();
}

const ISSUE = {
    duplicate: ['รับเข้าซ้ำ', 'bad'],
    negative: ['ขายเกินรับเข้า', 'bad'],
    reordered: ['บันทึกสลับลำดับ', 'warn'],
    gap: ['ยอดไม่ตรงเอกสาร', 'warn'],
};

/** Admin: products whose stock may be off from documents keyed in late or out of order. */
export async function openOrderCheckModal(navigate) {
    const box = document.createElement('div');
    box.className = 'modal-backdrop';
    box.innerHTML = '<div class="modal" role="dialog" aria-modal="true" style="width:960px"><div class="modal-body">' + skBlock(4, 8) + '</div></div>';
    document.body.appendChild(box);
    const close = () => { box.remove(); document.removeEventListener('keydown', onKey); };
    const onKey = (e) => { if (e.key === 'Escape' && [...document.querySelectorAll('.modal-backdrop')].pop() === box) close(); };
    document.addEventListener('keydown', onKey);

    let rows;
    try {
        rows = (await api.get('/inventory/order-check')).data;
    } catch (e) {
        close();
        alertDialog(e.message, { tone: 'error' });
        return;
    }
    let only = 'all';

    function draw() {
        const shown = only === 'all' ? rows : rows.filter((r) => r.issues.some((i) => i.code === only));
        box.querySelector('.modal').innerHTML = `
          <div class="modal-head"><h2>ตรวจสอบข้อมูลที่บันทึกล่าช้า / สลับลำดับ</h2><button type="button" class="modal-close" data-x aria-label="ปิด">✕</button></div>
          <div class="modal-body" style="padding-top:12px">
            <div class="hint"><div class="dot">i</div><div>ไล่ยอดคงเหลือของสินค้าของร้านทุกตัวตามวันที่ในเอกสาร เทียบกับลำดับที่บันทึกเข้าระบบ
              กดชื่อสินค้าเพื่อดูบัญชีคุมสินค้าและเปิดเอกสารที่เกี่ยวข้อง · แก้ยอดให้ตรงของจริงด้วยการตรวจนับสต๊อก (สินค้าที่ขายหมดแล้ว กดปุ่ม หมด (0) ในหน้าตรวจนับ)</div></div>
            <div class="tabs" role="group" aria-label="กรองปัญหา" style="margin:12px 0 8px">
              <button type="button" class="tab-btn ${only === 'all' ? 'active' : ''}" data-only="all">ทั้งหมด (${count(rows.length)})</button>
              ${Object.entries(ISSUE).map(([k, [l]]) => `<button type="button" class="tab-btn ${only === k ? 'active' : ''}" data-only="${k}">${l} (${count(rows.filter((r) => r.issues.some((i) => i.code === k)).length)})</button>`).join('')}
            </div>
            ${shown.length ? `<div class="table-wrap" style="max-height:480px;border:1px solid var(--border);border-radius:10px"><table>
              <thead><tr><th>สินค้า</th><th class="num">คงเหลือในระบบ</th><th class="num">ตามเอกสาร</th><th class="num">บันทึกล่าช้า</th><th>สิ่งที่พบ</th></tr></thead>
              <tbody>${shown.map((r) => `<tr>
                <td><button type="button" class="prod-link" data-product="${r.id}">${escapeHtml(r.name)}</button></td>
                <td class="num">${count(r.qty_stock)}</td>
                <td class="num">${count(r.doc_balance)}</td>
                <td class="num">${r.late_count ? count(r.late_count) : '<span class="muted">-</span>'}</td>
                <td style="font-size:12.5px">${r.issues.map((i) => `<div style="margin:2px 0"><span class="pill ${ISSUE[i.code][1]}">${ISSUE[i.code][0]}</span> ${escapeHtml(i.text)}</div>`).join('')}</td>
              </tr>`).join('')}</tbody></table></div>`
            : `<div class="empty" style="padding:14px">${rows.length ? 'ไม่มีสินค้าในกลุ่มนี้' : 'ไม่พบสินค้าที่ข้อมูลสลับลำดับหรือไม่ตรงเอกสาร'}</div>`}
          </div>
          <div class="modal-foot"><button type="button" class="btn secondary" data-x>ปิด</button></div>`;
        box.querySelectorAll('[data-x]').forEach((b) => b.addEventListener('click', close));
        box.querySelectorAll('[data-only]').forEach((b) => b.addEventListener('click', () => { only = b.dataset.only; draw(); }));
        box.querySelectorAll('[data-product]').forEach((b) => b.addEventListener('click', () => openProductModal(b.dataset.product, navigate && ((page) => { close(); navigate(page); }))));
    }
    draw();
}
