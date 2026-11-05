import { api } from './api.js';
import { money, count, escapeHtml, todayISO, compact, niceScale } from './util.js';
import { alertDialog } from './dialog.js';
import { skBlock } from './skeleton.js';
import { openProductModal, dailySeries, TREND_RANGES, addDays, thDay } from './product-modal.js';

// Sales trend of several products side by side: one line each on a shared day axis
// (7-day average by default, or the raw daily figure), with a summary table per product.

export const COMPARE_MAX = 10;
// Distinct hues that read on both the light and the dark theme.
const COLORS = ['#2f6fed', '#e8590c', '#12a150', '#c026d3', '#0e9fb8', '#c99a06', '#dc3545', '#6f4ee8', '#6aa30d', '#e0458f'];

export async function openTrendCompare(productIds, navigate) {
    const ids = [...new Set(productIds.map(Number))].slice(0, COMPARE_MAX);
    const box = document.createElement('div');
    box.className = 'modal-backdrop';
    box.innerHTML = '<div class="modal" role="dialog" aria-modal="true" style="width:1040px"><div class="modal-body">' + skBlock(6, 8) + '</div></div>';
    document.body.appendChild(box);
    let ro = null;
    const close = () => { box.remove(); document.removeEventListener('keydown', onKey); if (ro) ro.disconnect(); };
    const onKey = (e) => { if (e.key === 'Escape' && [...document.querySelectorAll('.modal-backdrop')].pop() === box) close(); };
    document.addEventListener('keydown', onKey);

    let items;
    try {
        const all = await Promise.all(ids.map((id) => api.get(`/inventory/${id}/movements`).then((r) => r.data)));
        items = all.map((d, i) => {
            const series = dailySeries(d) || [];
            return { id: d.product.id, p: d.product, color: COLORS[i % COLORS.length], byKey: new Map(series.map((b) => [b.key, b])), first: series[0]?.key || null, on: true };
        });
    } catch (e) {
        close();
        alertDialog(e.message, { tone: 'error' });
        return;
    }
    const today = todayISO();
    const earliest = items.reduce((m, it) => (it.first && it.first < m ? it.first : m), today);
    let range = 30;
    let metric = 'qty';
    let smooth = true;

    const days = () => {
        const start = range ? addDays(today, -(range - 1)) : earliest;
        const out = [];
        for (let k = start < earliest ? earliest : start; k <= today; k = addDays(k, 1)) out.push(k);
        return out;
    };
    const raw = (b) => (metric === 'qty' ? b.qty : b.amount);
    const avg = (b) => (metric === 'qty' ? b.avgQty : b.avgAmount);
    const pick = (b) => (b ? (smooth ? avg(b) : raw(b)) : null);
    const fmt = (v) => (metric === 'qty' ? `${(Math.round(v * 10) / 10).toLocaleString('th-TH')} ชิ้น` : `฿${money(v)}`);

    /** Last 7 days against the 7 before, per product. */
    function delta(it) {
        const sum = (a, b) => { let s = 0; for (let i = a; i < b; i++) { const x = it.byKey.get(addDays(today, -i)); s += x ? raw(x) : 0; } return s; };
        const last = sum(0, 7);
        const prev = sum(7, 14);
        if (!prev && !last) return '<span class="muted">-</span>';
        if (!prev) return '<span style="color:var(--ok);font-weight:600">▲ ใหม่</span>';
        const pct = ((last - prev) / prev) * 100;
        const up = pct >= 0;
        return `<span style="color:var(${up ? '--ok' : '--bad'});font-weight:600">${up ? '▲' : '▼'} ${Math.abs(pct).toFixed(1)}%</span>`;
    }

    function tableHtml(ks) {
        const rows = items.map((it) => {
            const bs = ks.map((k) => it.byKey.get(k)).filter(Boolean);
            const qty = bs.reduce((s, b) => s + b.qty, 0);
            const amount = bs.reduce((s, b) => s + b.amount, 0);
            const out = bs.filter((b) => b.balance <= 0).length;
            return { it, qty, amount, perDay: bs.length ? qty / bs.length : 0, out, daysOn: bs.length };
        }).sort((a, b) => (metric === 'qty' ? b.qty - a.qty : b.amount - a.amount));
        return `<div class="table-wrap" style="margin-top:12px"><table>
          <thead><tr><th>สินค้า</th><th class="num">ขาย (ชิ้น)</th><th class="num">ยอดเงิน</th><th class="num">เฉลี่ย/วัน</th>
            <th class="num" title="ยอดขาย 7 วันล่าสุด เทียบ 7 วันก่อนหน้า">7 วันล่าสุด</th><th class="num" title="วันที่คงเหลือ 0 ในช่วงที่เลือก">วันที่ของหมด</th><th class="num">คงเหลือ</th></tr></thead>
          <tbody>${rows.map(({ it, qty, amount, perDay, out, daysOn }) => `<tr style="${it.on ? '' : 'opacity:.5'}">
            <td><span class="tc-swatch" style="background:${it.color}"></span><button type="button" class="prod-link" data-open="${it.id}">${escapeHtml(it.p.name)}</button>
              ${it.p.consignor_id != null ? `<div class="muted" style="font-size:11.5px;margin-left:18px">ฝากขาย: ${escapeHtml(it.p.consignor_name || '')}</div>` : ''}</td>
            <td class="num"><b>${count(qty)}</b></td><td class="num">${money(amount)}</td>
            <td class="num">${daysOn ? perDay.toFixed(1) : '<span class="muted">-</span>'}</td>
            <td class="num">${delta(it)}</td>
            <td class="num">${out ? `<span style="color:var(--bad)">${count(out)} วัน</span>` : '<span class="muted">-</span>'}</td>
            <td class="num" style="${it.p.qty_stock <= 0 ? 'color:var(--bad)' : ''}">${count(it.p.qty_stock)}</td></tr>`).join('')}</tbody></table></div>`;
    }

    function draw() {
        const ks = days();
        box.querySelector('.modal').innerHTML = `
          <div class="modal-head"><h2>เปรียบเทียบแนวโน้มการขาย (${count(items.length)} รายการ)</h2><button type="button" class="modal-close" data-x aria-label="ปิด">✕</button></div>
          <div class="modal-body" style="padding-top:12px">
            <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
              <div class="tabs" role="group" aria-label="ช่วงเวลา">${TREND_RANGES.map(([n, l]) => `<button type="button" class="tab-btn ${n === range ? 'active' : ''}" data-range="${n}">${l}</button>`).join('')}</div>
              <div class="tabs" role="group" aria-label="เส้นกราฟ">${[[true, 'เฉลี่ย 7 วัน'], [false, 'รายวัน']].map(([v, l]) => `<button type="button" class="tab-btn ${v === smooth ? 'active' : ''}" data-smooth="${v ? 1 : 0}">${l}</button>`).join('')}</div>
              <div class="tabs" role="group" aria-label="หน่วย">${[['qty', 'ชิ้น'], ['amount', 'บาท']].map(([k, l]) => `<button type="button" class="tab-btn ${k === metric ? 'active' : ''}" data-metric="${k}">${l}</button>`).join('')}</div>
            </div>
            <div class="tc-chart" style="position:relative;margin-top:10px"></div>
            <div class="tc-legend">${items.map((it, i) => `<button type="button" class="tc-leg ${it.on ? '' : 'off'}" data-toggle="${i}" title="กดเพื่อซ่อน/แสดงเส้นนี้">
              <span class="tc-swatch" style="background:${it.color}"></span>${escapeHtml(it.p.name)}</button>`).join('')}</div>
            ${tableHtml(ks)}
            <div class="muted" style="font-size:12px;margin-top:8px">${smooth ? 'เส้นแสดงค่าเฉลี่ยย้อนหลัง 7 วัน ช่วยให้เห็นทิศทางชัดกว่ายอดรายวัน' : 'เส้นแสดงยอดขายจริงแต่ละวัน'} · ใช้วันที่ในเอกสารขาย
              · เส้นเริ่มตั้งแต่วันแรกที่สินค้ามีความเคลื่อนไหว · เลือกได้สูงสุด ${COMPARE_MAX} รายการ · กดชื่อสินค้าเพื่อดูบัญชีคุมสินค้า</div>
          </div>
          <div class="modal-foot"><button type="button" class="btn secondary" data-x>ปิด</button></div>`;
        box.querySelectorAll('[data-x]').forEach((b) => b.addEventListener('click', close));
        box.querySelectorAll('[data-range]').forEach((b) => b.addEventListener('click', () => { range = Number(b.dataset.range); draw(); }));
        box.querySelectorAll('[data-smooth]').forEach((b) => b.addEventListener('click', () => { smooth = b.dataset.smooth === '1'; draw(); }));
        box.querySelectorAll('[data-metric]').forEach((b) => b.addEventListener('click', () => { metric = b.dataset.metric; draw(); }));
        box.querySelectorAll('[data-toggle]').forEach((b) => b.addEventListener('click', () => { const it = items[Number(b.dataset.toggle)]; it.on = !it.on; draw(); }));
        box.querySelectorAll('[data-open]').forEach((b) => b.addEventListener('click', () => openProductModal(b.dataset.open, navigate && ((page) => { close(); navigate(page); }))));
        chart(ks);
    }

    /** One line per product on a shared day axis; hover shows every product's value that day. */
    function chart(ks) {
        const host = box.querySelector('.tc-chart');
        if (!host) return;
        const shown = items.filter((it) => it.on);
        const W = Math.max(host.clientWidth, 280);
        const H = 260;
        const m = { l: 44, r: 10, t: 12, b: 24 };
        const pw = W - m.l - m.r;
        const ph = H - m.t - m.b;
        let max = 0;
        for (const it of shown) for (const k of ks) { const v = pick(it.byKey.get(k)); if (v !== null && v > max) max = v; }
        const { top, step } = niceScale(metric === 'qty' ? Math.max(max, 4) : max);
        const n = ks.length;
        const x = (i) => m.l + (n > 1 ? (pw * i) / (n - 1) : pw / 2);
        const y = (v) => m.t + ph - (Math.max(v, 0) / top) * ph;
        const base = m.t + ph;
        const every = Math.max(1, Math.ceil(44 / (pw / Math.max(n - 1, 1))));

        const grid = [];
        for (let v = 0; v <= top + 1e-9; v += step) {
            grid.push(`<line x1="${m.l}" x2="${W - m.r}" y1="${y(v)}" y2="${y(v)}" stroke="var(--border)" stroke-width="1"/>
              <text x="${m.l - 6}" y="${y(v) + 4}" text-anchor="end" font-size="11" fill="var(--muted)">${compact(v)}</text>`);
        }
        let prevMonth = '';
        const labels = ks.map((k, i) => {
            if (i % every !== 0) return '';
            const mo = k.slice(0, 7);
            const txt = mo !== prevMonth ? thDay(k, { day: 'numeric', month: 'short' }) : thDay(k, { day: 'numeric' });
            prevMonth = mo;
            return `<text x="${x(i)}" y="${H - 7}" text-anchor="middle" font-size="11" fill="${k === today ? 'var(--text)' : 'var(--muted)'}" font-weight="${k === today ? 700 : 400}">${escapeHtml(txt)}</text>`;
        }).join('');
        // A product's line starts the day it first moved; earlier days have no point.
        const lines = shown.map((it) => {
            let d = '';
            let pen = false;
            ks.forEach((k, i) => {
                const v = pick(it.byKey.get(k));
                if (v === null) { pen = false; return; }
                d += `${pen ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)} `;
                pen = true;
            });
            const single = n === 1 && it.byKey.get(ks[0]) ? `<circle cx="${x(0)}" cy="${y(pick(it.byKey.get(ks[0])))}" r="3" fill="${it.color}"/>` : '';
            return `<path d="${d}" fill="none" stroke="${it.color}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>${single}`;
        }).join('');

        host.innerHTML = `
          <svg width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" role="img" aria-label="กราฟเปรียบเทียบยอดขายรายวัน" style="display:block;overflow:visible">
            ${grid.join('')}
            <line class="tc-cross" x1="0" x2="0" y1="${m.t}" y2="${base}" stroke="var(--muted)" stroke-width="1" stroke-dasharray="3 3" visibility="hidden"/>
            ${lines}
            <g class="tc-dots"></g>
            <line x1="${m.l}" x2="${W - m.r}" y1="${base}" y2="${base}" stroke="var(--border)" stroke-width="1"/>
            ${labels}
            <rect class="tc-hit" x="${m.l}" y="${m.t}" width="${pw}" height="${ph}" fill="transparent"/>
          </svg>
          ${!shown.length ? '<div class="muted" style="position:absolute;inset:0;display:grid;place-items:center;font-size:13px">กดชื่อสินค้าด้านล่างเพื่อแสดงเส้น</div>'
            : max === 0 ? '<div class="muted" style="position:absolute;inset:0;display:grid;place-items:center;font-size:13px;pointer-events:none">ไม่มียอดขายในช่วงนี้</div>' : ''}
          <div class="tc-tip" role="tooltip" style="position:absolute;pointer-events:none;display:none;background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:8px 11px;font-size:12.5px;box-shadow:0 8px 24px rgba(16,24,40,.18);white-space:nowrap;z-index:2"></div>`;

        const tip = host.querySelector('.tc-tip');
        const cross = host.querySelector('.tc-cross');
        const dots = host.querySelector('.tc-dots');
        const hit = host.querySelector('.tc-hit');
        const show = (i) => {
            const k = ks[i];
            cross.setAttribute('x1', x(i)); cross.setAttribute('x2', x(i)); cross.setAttribute('visibility', 'visible');
            const vals = shown.map((it) => ({ it, b: it.byKey.get(k) })).filter((r) => r.b);
            dots.innerHTML = vals.map(({ it, b }) => `<circle cx="${x(i)}" cy="${y(pick(b))}" r="3.5" fill="${it.color}" stroke="var(--surface)" stroke-width="1.5"/>`).join('');
            vals.sort((a, b) => pick(b.b) - pick(a.b));
            tip.innerHTML = `<div style="font-weight:700;margin-bottom:4px">${escapeHtml(thDay(k, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }))}</div>
              ${vals.length ? vals.map(({ it, b }) => `<div style="display:flex;justify-content:space-between;gap:14px;align-items:center">
                <span><span class="tc-swatch" style="background:${it.color}"></span>${escapeHtml(it.p.name.length > 28 ? `${it.p.name.slice(0, 27)}…` : it.p.name)}</span>
                <span><b>${fmt(pick(b))}</b>${smooth ? ` <span class="muted">(วันนี้ ${fmt(raw(b))})</span>` : ''}${b.balance <= 0 ? ' <span style="color:var(--bad)">หมด</span>' : ''}</span></div>`).join('')
                : '<div class="muted">ยังไม่มีสินค้าในวันนี้</div>'}`;
            tip.style.display = 'block';
            const left = x(i) + 14 + tip.offsetWidth > W ? x(i) - 14 - tip.offsetWidth : x(i) + 14;
            tip.style.left = `${Math.max(left, 0)}px`;
            tip.style.top = `${m.t}px`;
        };
        const at = (e) => {
            const r = hit.getBoundingClientRect();
            const i = n > 1 ? Math.round(((e.clientX - r.left) / r.width) * (n - 1)) : 0;
            show(Math.min(Math.max(i, 0), n - 1));
        };
        hit.addEventListener('mousemove', at);
        hit.addEventListener('click', at);
        hit.addEventListener('mouseleave', () => { tip.style.display = 'none'; cross.setAttribute('visibility', 'hidden'); dots.innerHTML = ''; });
    }

    draw();
    let lastW = 0;
    ro = new ResizeObserver(() => {
        const host = box.querySelector('.tc-chart');
        if (host && Math.abs(host.clientWidth - lastW) > 4) { lastW = host.clientWidth; chart(days()); }
    });
    ro.observe(box.querySelector('.modal'));
}
