import { money, count, escapeHtml, todayISO, compact, niceScale } from '../util.js';

// Sales charts of the consignor's own passbook: a trend of columns (one per period of the
// chosen tab, stackable by product) and a donut of what sold in one period. Every figure comes
// from the same summarize() as the tables below them, so the two always agree.

const METRICS = [['qty', 'จำนวนชิ้น'], ['value', 'จำนวนเงิน']];
const TREND_TITLE = { day: 'ยอดขายรายวัน', week: 'ยอดขายรายสัปดาห์', month: 'ยอดขายรายเดือน' };
const MAX_BARS = { day: 31, week: 16, month: 12 };
const LABEL_WIDTH = { day: 34, week: 44, month: 50 };
const COLORS = 8; // --sv-1 … --sv-8 in app.css; past that the smallest sellers fold into "อื่น ๆ"

const asDate = (d) => new Date(`${String(d).slice(0, 10)}T00:00:00Z`);
const thai = (d, opts) => d.toLocaleDateString('th-TH', { timeZone: 'UTC', ...opts });
const dayKey = (d) => d.toISOString().slice(0, 10);

function nextKey(unit, key) {
    if (unit === 'month') {
        const [y, m] = key.split('-').map(Number);
        return `${m === 12 ? y + 1 : y}-${String(m === 12 ? 1 : m + 1).padStart(2, '0')}`;
    }
    const d = asDate(key);
    d.setUTCDate(d.getUTCDate() + (unit === 'week' ? 7 : 1));
    return dayKey(d);
}

/** Text under a column; the month shows only where it changes, so day numbers stay short. */
function axisLabel(unit, key, withMonth) {
    if (unit === 'month') return thai(asDate(`${key}-01`), { month: 'short', year: '2-digit' });
    return thai(asDate(key), withMonth || unit === 'week' ? { day: 'numeric', month: 'short' } : { day: 'numeric' });
}

function shortLabel(unit, key) {
    if (unit === 'month') return thai(asDate(`${key}-01`), { month: 'short', year: 'numeric' });
    return thai(asDate(key), { day: 'numeric', month: 'short', year: 'numeric' });
}

/**
 * One color per product, fixed for the whole page: ranked by all-time sales, so changing the
 * tab, the period or the unit never repaints a product.
 */
function buildSeries(sales) {
    const totals = new Map();
    for (const s of sales) {
        const t = totals.get(s.product_id) || { id: s.product_id, name: s.name, value: 0 };
        t.value += Number(s.value);
        totals.set(s.product_id, t);
    }
    const ranked = [...totals.values()].sort((a, b) => b.value - a.value || String(a.name).localeCompare(String(b.name), 'th'));
    const named = ranked.length > COLORS ? ranked.slice(0, COLORS - 1) : ranked;
    const list = named.map((t, i) => ({ key: String(t.id), name: t.name, color: `var(--sv-${i + 1})` }));
    const other = { key: 'other', name: 'อื่น ๆ', color: 'var(--sv-other)' };
    if (ranked.length > named.length) list.push(other);
    const byProduct = new Map(ranked.map((t, i) => [t.id, i < named.length ? list[i] : other]));
    return { list, byProduct };
}

/** Column with a rounded data-end and a square foot on the baseline. */
function capPath(x, top, w, h, r) {
    return `M${x},${top + h} V${top + r} Q${x},${top} ${x + r},${top} H${x + w - r} Q${x + w},${top} ${x + w},${top + r} V${top + h} Z`;
}

function arcPath(cx, cy, r, from, to) {
    const p = (a) => `${(cx + r * Math.cos(a)).toFixed(2)},${(cy + r * Math.sin(a)).toFixed(2)}`;
    return `M${p(from)} A${r},${r} 0 ${to - from > Math.PI ? 1 : 0} 1 ${p(to)}`;
}

export function mountSalesCharts({ controls, host, sales, summarize, periodKey, periodLabel, onSelect }) {
    const { list: seriesList, byProduct } = buildSeries(sales);
    let view = 'month';
    let metric = 'qty';
    let split = true;
    let selected = null;
    let buckets = [];
    let end = null; // Where the visible columns stop (exclusive); null until first drawn.
    let lastWidth = 0;

    const unit = () => (view === 'all' ? 'month' : view);
    const measure = (x) => Math.max(Number(x[metric]) || 0, 0);
    const fmt = (v) => (metric === 'qty' ? `${count(v)} ชิ้น` : `฿${money(v)}`);
    const axisFmt = (v, top) => (top >= 10000 ? compact(v) : count(v));

    if (!sales.length) {
        controls.innerHTML = '';
        host.innerHTML = '<div class="empty" style="margin-top:12px">ยังไม่มียอดขาย แผนภูมิจะแสดงเมื่อขายสินค้าของคุณได้</div>';
        return { setView() {}, selected: () => null };
    }

    /** Every period from the first sale up to today, empty ones included: a gap in sales is part of the trend. */
    function buildBuckets() {
        const u = unit();
        const known = new Map(summarize(u, sales, []).map((p) => [p.key, p]));
        const keys = [...known.keys()].sort();
        const today = periodKey(u, todayISO());
        const last = keys[keys.length - 1] > today ? keys[keys.length - 1] : today;
        const out = [];
        for (let k = keys[0]; k <= last; k = nextKey(u, k)) {
            out.push(known.get(k) || { key: k, products: new Map(), others: [], qty: 0, value: 0, fee: 0 });
        }
        return out;
    }

    /** A period's sales per color, in color order. */
    function mix(period) {
        const rows = new Map();
        for (const [id, p] of period.products) {
            const s = byProduct.get(id);
            const r = rows.get(s.key) || { s, qty: 0, value: 0, names: [] };
            r.qty += p.qty;
            r.value += p.value;
            r.names.push(p.name);
            rows.set(s.key, r);
        }
        return seriesList.filter((s) => rows.has(s.key)).map((s) => rows.get(s.key));
    }

    const seriesName = (r) => (r.s.key === 'other' ? `อื่น ๆ (${count(r.names.length)} รายการ)` : r.s.name);
    const canSplit = () => seriesList.length > 1;
    const stacked = () => split && canSplit();

    function latestWithSales(from = 0, to = buckets.length) {
        for (let i = to - 1; i >= from; i--) if (buckets[i].products.size) return buckets[i].key;
        return null;
    }

    function drawControls() {
        controls.innerHTML = `
          <div class="tabs" role="group" aria-label="หน่วยของแผนภูมิ">
            ${METRICS.map(([k, l]) => `<button type="button" class="tab-btn ${k === metric ? 'active' : ''}" data-metric="${k}" aria-pressed="${k === metric}">${l}</button>`).join('')}
          </div>
          ${canSplit() ? `<button type="button" class="tab-btn ${split ? 'active' : ''}" data-split aria-pressed="${split}" title="แสดงแต่ละแท่งแยกสีตามสินค้า">แยกสีตามสินค้า</button>` : ''}`;
    }

    function drawFrame() {
        host.innerHTML = `
          <div class="sv-grid">
            <div class="sv-panel sv-trend"><div class="sv-head"></div><div class="sv-plot"></div><div class="sv-legend"></div></div>
            <div class="sv-panel sv-share"></div>
          </div>`;
    }

    function drawAll() {
        drawControls();
        drawFrame();
        drawTrend();
        drawShare();
    }

    function drawTrend(refocus = null) {
        const plot = host.querySelector('.sv-plot');
        const u = unit();
        const W = Math.max(plot.clientWidth, 220);
        lastWidth = host.clientWidth;
        const H = 240;
        const m = { l: 44, r: 12, t: 24, b: 26 };
        const pw = W - m.l - m.r;
        const ph = H - m.t - m.b;
        const size = Math.min(MAX_BARS[u], Math.max(4, Math.floor(pw / 18)));
        if (end === null) {
            const at = buckets.findIndex((b) => b.key === selected);
            end = at >= 0 && at < buckets.length - size ? at + 1 : buckets.length;
        }
        end = Math.min(buckets.length, Math.max(end, Math.min(size, buckets.length)));
        const start = Math.max(0, end - size);
        const shown = buckets.slice(start, end);
        const paged = buckets.length > size;

        host.querySelector('.sv-head').innerHTML = `
          <div style="min-width:0">
            <div class="sv-title">${TREND_TITLE[u]}</div>
            <div class="muted sv-sub">${view === 'all' ? 'ทุกช่วงเวลา แสดงทีละเดือน' : 'กดที่แท่งเพื่อดูสัดส่วนสินค้าและตารางของช่วงนั้น'}</div>
          </div>
          ${paged ? `<div class="sv-nav">
            <span class="muted sv-sub">${escapeHtml(shortLabel(u, shown[0].key))} – ${escapeHtml(shortLabel(u, shown[shown.length - 1].key))}</span>
            <button type="button" class="btn secondary sm" data-page="-1" aria-label="ช่วงก่อนหน้า" title="ช่วงก่อนหน้า" ${start === 0 ? 'disabled' : ''}>←</button>
            <button type="button" class="btn secondary sm" data-page="1" aria-label="ช่วงถัดไป" title="ช่วงถัดไป" ${end === buckets.length ? 'disabled' : ''}>→</button>
          </div>` : ''}`;

        const max = Math.max(0, ...shown.map(measure));
        let { top, step } = niceScale(metric === 'qty' ? Math.max(max, 4) : max);
        if (metric === 'qty' && !Number.isInteger(step)) { // A piece count has no half steps.
            step = Math.ceil(step);
            top = Math.ceil(Math.max(max, 4) / step) * step;
        }
        const band = pw / shown.length;
        const bw = Math.max(4, Math.min(24, band * 0.62, band - 2));
        const y = (v) => m.t + ph - (v / top) * ph;
        const base = m.t + ph;
        const cx = (i) => m.l + band * i + band / 2;

        const grid = [];
        for (let v = 0; v <= top + 1e-9; v += step) {
            grid.push(`<line x1="${m.l}" x2="${W - m.r}" y1="${y(v)}" y2="${y(v)}" stroke="var(--border)" stroke-width="1"/>
              <text class="sv-tick" x="${m.l - 8}" y="${y(v) + 4}" text-anchor="end">${axisFmt(v, top)}</text>`);
        }

        const every = Math.max(1, Math.ceil(LABEL_WIDTH[u] / band));
        const labelled = shown.map((_, i) => (shown.length - 1 - i) % every === 0);
        let monthShown = '';
        const labels = shown.map((b, i) => {
            if (!labelled[i]) return '';
            const mo = b.key.slice(0, 7);
            const text = axisLabel(u, b.key, mo !== monthShown);
            monthShown = mo;
            const sel = b.key === selected;
            return `<text class="sv-tick" x="${cx(i)}" y="${H - 8}" text-anchor="middle" ${sel ? 'style="fill:var(--text);font-weight:700"' : ''}>${escapeHtml(text)}</text>`;
        }).join('');

        const bars = shown.map((b, i) => {
            const total = measure(b);
            if (!total) return '';
            const x = cx(i) - bw / 2;
            const parts = stacked()
                ? mix(b).map((r) => ({ color: r.s.color, v: measure(r) })).filter((r) => r.v > 0)
                : [{ color: 'var(--sv-1)', v: total }];
            let floor = base;
            return parts.map((r, j) => {
                const h = (r.v / top) * ph;
                const yt = floor - h;
                floor = yt;
                if (j === parts.length - 1) { // The top part carries the rounded end.
                    const seen = Math.max(h, 1);
                    return `<path d="${capPath(x, yt + h - seen, bw, seen, Math.min(4, bw / 2, seen))}" fill="${r.color}"/>`;
                }
                return `<rect x="${x}" y="${(yt + Math.min(2, h / 2)).toFixed(2)}" width="${bw}" height="${Math.max(h - 2, 1).toFixed(2)}" fill="${r.color}"/>`;
            }).join('');
        }).join('');

        const peak = max > 0 ? shown.findIndex((b) => measure(b) === max) : -1;
        const peakAnchor = peak < 0 ? '' : cx(peak) < m.l + 24 ? 'start' : cx(peak) > W - m.r - 24 ? 'end' : 'middle';
        const peakText = peak < 0 ? '' : metric === 'qty' ? count(max) : (max >= 10000 ? compact(max) : count(Math.round(max)));
        const selIndex = shown.findIndex((b) => b.key === selected);
        const canPick = view !== 'all';

        plot.innerHTML = `
          <svg width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" role="group" aria-label="${escapeHtml(TREND_TITLE[u])} (${METRICS.find(([k]) => k === metric)[1]})" style="display:block">
            ${selIndex >= 0 ? `<rect x="${m.l + band * selIndex}" y="${m.t - 2}" width="${band}" height="${H - m.t + 2}" rx="6" fill="var(--soft)"/>` : ''}
            <rect class="sv-hover" x="0" y="${m.t - 2}" width="0" height="${H - m.t + 2}" rx="6" fill="var(--hover)"/>
            ${grid.join('')}
            ${bars}
            <line x1="${m.l}" x2="${W - m.r}" y1="${base}" y2="${base}" stroke="var(--muted)" stroke-opacity=".5" stroke-width="1"/>
            ${labels}
            ${peak >= 0 ? `<text x="${cx(peak)}" y="${y(max) - 7}" text-anchor="${peakAnchor}" font-size="11" font-weight="600" fill="var(--text)">${peakText}</text>` : ''}
            ${shown.map((b, i) => `<rect class="sv-hit ${canPick ? 'pick' : ''}" data-i="${i}" x="${m.l + band * i}" y="${m.t - 2}" width="${band}" height="${H - m.t + 2}" fill="transparent" tabindex="0"
                ${canPick ? 'role="button"' : ''} aria-label="${escapeHtml(periodLabel(u, b.key))}: ${count(b.qty)} ชิ้น ฿${money(b.value)}"/>`).join('')}
          </svg>
          ${max === 0 ? '<div class="muted sv-none">ไม่มียอดขายในช่วงนี้</div>' : ''}
          <div class="sv-tip" role="tooltip"></div>`;

        const inView = new Set();
        shown.forEach((b) => mix(b).forEach((r) => { if (measure(r) > 0) inView.add(r.s.key); }));
        host.querySelector('.sv-legend').innerHTML = stacked()
            ? seriesList.filter((s) => inView.has(s.key)).map((s) => `<span class="sv-key" title="${escapeHtml(s.name)}"><i style="background:${s.color}"></i><span>${escapeHtml(s.name)}</span></span>`).join('')
            : '';

        const tip = plot.querySelector('.sv-tip');
        const hover = plot.querySelector('.sv-hover');
        const show = (i) => {
            const b = shown[i];
            const rows = mix(b).filter((r) => measure(r) > 0).sort((p, q) => measure(q) - measure(p));
            tip.innerHTML = `<div class="sv-tip-head">${escapeHtml(periodLabel(u, b.key))}</div>
              ${rows.length ? rows.map((r) => `<div class="sv-tip-row">${stacked() ? `<i style="background:${r.s.color}"></i>` : ''}<span>${escapeHtml(seriesName(r))}</span><b>${fmt(measure(r))}</b></div>`).join('')
                  : '<div class="muted">ไม่มียอดขาย</div>'}
              ${rows.length ? `<div class="muted sv-tip-foot">รวม ${count(b.qty)} ชิ้น · ฿${money(b.value)}</div>` : ''}`;
            tip.style.display = 'block';
            hover.setAttribute('x', m.l + band * i);
            hover.setAttribute('width', b.key === selected ? 0 : band);
            tip.style.left = `${Math.min(Math.max(cx(i) - tip.offsetWidth / 2, 0), Math.max(W - tip.offsetWidth, 0))}px`;
            tip.style.top = `${Math.max(y(measure(b)) - tip.offsetHeight - 10, 0)}px`;
        };
        const hide = () => { tip.style.display = 'none'; hover.setAttribute('width', 0); };
        plot.querySelectorAll('.sv-hit').forEach((n) => {
            const i = Number(n.dataset.i);
            n.addEventListener('pointerenter', (e) => { if (e.pointerType === 'mouse') show(i); });
            n.addEventListener('pointerleave', (e) => { if (e.pointerType === 'mouse') hide(); });
            n.addEventListener('focus', () => show(i));
            n.addEventListener('blur', hide);
            n.addEventListener('click', () => pick(i));
            n.addEventListener('keydown', (e) => {
                if (e.key !== 'Enter' && e.key !== ' ') return;
                e.preventDefault();
                pick(i);
            });
        });
        const pick = (i) => {
            const key = shown[i].key;
            if (!canPick || key === selected) { show(i); return; }
            selected = key;
            onSelect?.(key);
            drawTrend(i);
            drawShare();
        };
        host.querySelectorAll('[data-page]').forEach((b) => b.addEventListener('click', () => {
            const dir = Number(b.dataset.page);
            end = Math.min(buckets.length, Math.max(Math.min(size, buckets.length), end + dir * size));
            const from = Math.max(0, end - size);
            if (view !== 'all' && !buckets.slice(from, end).some((p) => p.key === selected)) {
                selected = latestWithSales(from, end) || buckets[end - 1].key;
                onSelect?.(selected);
                drawShare();
            }
            drawTrend();
            host.querySelector(`[data-page="${dir}"]`)?.focus({ preventScroll: true });
        }));
        if (refocus !== null) plot.querySelector(`.sv-hit[data-i="${refocus}"]`)?.focus({ preventScroll: true });
    }

    function drawShare() {
        const panel = host.querySelector('.sv-share');
        const period = view === 'all'
            ? summarize('all', sales, [])[0]
            : buckets.find((b) => b.key === selected);
        const rows = period ? mix(period).filter((r) => measure(r) > 0) : [];
        const sum = rows.reduce((s, r) => s + measure(r), 0);
        const pct = (r) => `${((measure(r) / sum) * 100).toFixed(1)}%`;
        const C = 90;
        const R = 70;
        const gap = 2 / R; // 2px of bare surface between neighbours.
        const hitId = (r) => escapeHtml(r.s.key);
        let angle = -Math.PI / 2;
        const segments = rows.map((r) => {
            const sweep = (measure(r) / sum) * Math.PI * 2;
            const from = angle + gap / 2;
            const to = angle + Math.max(sweep - gap / 2, gap);
            const mid = angle + sweep / 2;
            r.mx = C + R * Math.cos(mid);
            r.my = C + R * Math.sin(mid);
            angle += sweep;
            const label = `${escapeHtml(seriesName(r))}: ${fmt(measure(r))} (${pct(r)})`;
            const common = `class="sv-seg" data-s="${hitId(r)}" fill="none" stroke="${r.s.color}" stroke-width="24" tabindex="0" role="img" aria-label="${label}"`;
            return rows.length === 1
                ? `<circle ${common} cx="${C}" cy="${C}" r="${R}"/>`
                : `<path ${common} d="${arcPath(C, C, R, from, to)}"/>`;
        }).join('');
        const centerValue = metric === 'qty' ? count(period ? period.qty : 0) : money(period ? period.value : 0);
        const centerSize = centerValue.length <= 8 ? 21 : centerValue.length <= 11 ? 17 : 14;
        const ranked = [...rows].sort((a, b) => measure(b) - measure(a));

        panel.innerHTML = `
          <div class="sv-head"><div style="min-width:0">
            <div class="sv-title">สัดส่วนสินค้าที่ขายได้</div>
            <div class="muted sv-sub">${period ? escapeHtml(periodLabel(view, period.key)) : ''}</div>
          </div></div>
          <div class="sv-donut-body">
            <div class="sv-donut">
              <svg viewBox="0 0 180 180" width="170" height="170" role="group" aria-label="สัดส่วนสินค้าที่ขายได้ ${period ? escapeHtml(periodLabel(view, period.key)) : ''}" style="display:block">
                ${rows.length ? segments : `<circle cx="${C}" cy="${C}" r="${R}" fill="none" stroke="var(--border)" stroke-width="24"/>`}
                <text x="${C}" y="${C + 2}" text-anchor="middle" font-size="${centerSize}" font-weight="700" fill="var(--text)">${centerValue}</text>
                <text x="${C}" y="${C + 20}" text-anchor="middle" font-size="12" fill="var(--muted)">${metric === 'qty' ? 'ชิ้น' : 'บาท'}</text>
              </svg>
              <div class="sv-tip" role="tooltip"></div>
            </div>
            ${ranked.length ? `<ul class="sv-rows">${ranked.map((r) => `
              <li data-s="${hitId(r)}" title="${escapeHtml(r.s.key === 'other' ? r.names.join(', ') : r.s.name)}">
                <i style="background:${r.s.color}"></i>
                <span class="sv-row-name">${escapeHtml(seriesName(r))}</span>
                <span class="sv-row-val">${fmt(measure(r))}</span>
                <span class="sv-row-pct muted">${pct(r)}</span>
              </li>`).join('')}</ul>` : '<div class="muted sv-empty-note">ไม่มียอดขายในช่วงนี้</div>'}
          </div>`;

        const tip = panel.querySelector('.sv-tip');
        const fig = panel.querySelector('.sv-donut');
        const emphasize = (key) => {
            panel.querySelectorAll('[data-s]').forEach((n) => n.classList.toggle('sv-dim', key !== null && n.dataset.s !== key));
        };
        const showSeg = (key) => {
            const r = rows.find((x) => x.s.key === key);
            if (!r) return;
            emphasize(key);
            const names = r.s.key === 'other' ? `<div class="muted sv-tip-foot">${escapeHtml(r.names.slice(0, 5).join(', '))}${r.names.length > 5 ? ' …' : ''}</div>` : '';
            tip.innerHTML = `<div class="sv-tip-row"><i style="background:${r.s.color}"></i><span>${escapeHtml(seriesName(r))}</span></div>
              <div><b>${fmt(measure(r))}</b> <span class="muted">${pct(r)}</span></div>${names}`;
            tip.style.display = 'block';
            const scale = fig.clientWidth / 180;
            tip.style.left = `${Math.min(Math.max(r.mx * scale - tip.offsetWidth / 2, 0), Math.max(fig.clientWidth - tip.offsetWidth, 0))}px`;
            tip.style.top = `${Math.max(r.my * scale - tip.offsetHeight - 16, 0)}px`;
        };
        const hideSeg = () => { emphasize(null); tip.style.display = 'none'; };
        panel.querySelectorAll('.sv-seg').forEach((n) => {
            n.addEventListener('pointerenter', (e) => { if (e.pointerType === 'mouse') showSeg(n.dataset.s); });
            n.addEventListener('pointerleave', (e) => { if (e.pointerType === 'mouse') hideSeg(); });
            n.addEventListener('focus', () => showSeg(n.dataset.s));
            n.addEventListener('blur', hideSeg);
            n.addEventListener('click', () => showSeg(n.dataset.s));
        });
        panel.querySelectorAll('.sv-rows li').forEach((n) => {
            n.addEventListener('pointerenter', (e) => { if (e.pointerType === 'mouse') emphasize(n.dataset.s); });
            n.addEventListener('pointerleave', (e) => { if (e.pointerType === 'mouse') emphasize(null); });
        });
    }

    controls.addEventListener('click', (e) => {
        const m = e.target.closest('[data-metric]');
        const s = e.target.closest('[data-split]');
        if (m) metric = m.dataset.metric;
        else if (s) split = !split;
        else return;
        const again = m ? `[data-metric="${metric}"]` : '[data-split]';
        drawAll();
        controls.querySelector(again)?.focus({ preventScroll: true });
    });

    // Redraw at the new width when the layout changes (sidebar, window, phone rotation).
    new ResizeObserver(() => {
        if (buckets.length && Math.abs(host.clientWidth - lastWidth) > 4) drawAll();
    }).observe(host);

    return {
        setView(next) {
            view = next;
            buckets = buildBuckets();
            selected = view === 'all' ? null : latestWithSales();
            end = null;
            drawAll();
        },
        selected: () => selected,
    };
}
