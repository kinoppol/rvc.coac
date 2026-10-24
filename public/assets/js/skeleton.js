// Skeleton screens: grey shimmering blocks in the shape of the page that is loading,
// shown while its data comes from the server (styles: .sk in app.css).

const LABEL = '<span class="sr-only">กำลังโหลดข้อมูล…</span>';
const line = (w, h = 13) => `<span class="sk sk-line" style="width:${w};height:${h}px"></span>`;
// Varied widths so the rows read as text, not a grid of identical bars.
const WIDTHS = ['72%', '48%', '60%', '84%', '40%', '66%', '54%', '78%'];

/** Table rows (<tr>) for a tbody that is loading. */
export function skRows(cols = 5, rows = 8) {
    return Array.from({ length: rows }, (_, r) => `<tr class="sk-row">${Array.from({ length: cols }, (_, c) =>
        `<td>${line(c === 0 ? WIDTHS[r % WIDTHS.length] : `${40 + ((r + c) * 13) % 45}%`)}</td>`).join('')}</tr>`).join('');
}

/** A whole table: header bars plus rows. */
export function skTable(cols = 5, rows = 8) {
    return `<div class="table-wrap"><table class="sk-table" aria-hidden="true">
      <thead><tr>${Array.from({ length: cols }, (_, c) => `<th>${line(c === 0 ? '90px' : '56px', 11)}</th>`).join('')}</tr></thead>
      <tbody>${skRows(cols, rows)}</tbody></table></div>`;
}

/** Stat / KPI cards. */
export function skStats(n = 4) {
    return `<div class="kpi-grid">${Array.from({ length: n }, () => `<div class="kpi sk-card">
      ${line('58%', 12)}<div style="margin:12px 0 10px">${line('64%', 26)}</div>${line('42%', 11)}</div>`).join('')}</div>`;
}

/** Column chart. */
export function skChart(height = 240) {
    const bars = [38, 62, 45, 80, 55, 70, 30, 66, 50, 74, 42, 58];
    return `<div class="sk-chart" style="height:${height}px" aria-hidden="true">${bars.map((h) => `<span class="sk" style="height:${h}%"></span>`).join('')}</div>`;
}

/** A card with a title bar and a table. */
export function skTableCard(cols = 5, rows = 8) {
    return `<section class="card sk-card"><div class="card-head">${line('160px', 15)}${line('90px', 28)}</div>${skTable(cols, rows)}</section>`;
}

/** Filter / search bar above a list. */
function skToolbar(n = 3) {
    return `<div class="sk-toolbar">${line('260px', 38)}${Array.from({ length: n - 1 }, () => line('130px', 38)).join('')}</div>`;
}

/** Form card: label + input pairs. */
function skForm(fields = 6) {
    return `<section class="card card-pad sk-card">${line('180px', 16)}
      <div class="sk-form">${Array.from({ length: fields }, () => `<div>${line('40%', 11)}<div style="margin-top:7px">${line('100%', 38)}</div></div>`).join('')}</div></section>`;
}

const PAGES = {
    dashboard: () => `${skStats(7)}
      <section class="card card-pad sk-card">${line('180px', 16)}<div style="margin:10px 0 16px">${line('140px', 24)}</div>${skChart()}</section>
      <div class="two-col">${skTableCard(3, 5)}${skTableCard(3, 5)}</div>`,
    list: () => `${skToolbar()}${skTableCard(6, 10)}`,
    report: () => `${skToolbar(3)}${skStats(4)}${skTableCard(6, 10)}`,
    form: () => `${skForm()}${skTableCard(5, 6)}`,
    pos: () => `<div class="two-col">${skTableCard(3, 10)}${skForm(3)}</div>`,
};

/** Placeholder for a whole page: 'dashboard' | 'list' | 'report' | 'form' | 'pos'. */
export function skPage(kind = 'list') {
    return `<div class="sk-page" aria-busy="true">${LABEL}${(PAGES[kind] || PAGES.list)()}</div>`;
}

/** Placeholder inside a card or dialog that is loading a table. */
export function skBlock(cols = 5, rows = 6) {
    return `<div class="sk-block" aria-busy="true">${LABEL}${skTable(cols, rows)}</div>`;
}
