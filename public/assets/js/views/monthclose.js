import { api } from '../api.js';
import { money, count, escapeHtml, fmtDateTime } from '../util.js';
import { alertDialog, promptDialog } from '../dialog.js';

const TH_MONTHS = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
const monthLabel = (ym) => { const [y, m] = ym.split('-').map(Number); return `${TH_MONTHS[m - 1]} ${y + 543}`; };

/**
 * ปิดบัญชีประจำเดือน: one month's income and expenses, losses and mistakes (damaged goods, goods
 * lost / found in counts, sale bills corrected, money over / short), and the store goods left at
 * cost carried to the next month. Closing keeps the figures; records stay editable and the page
 * shows which figures changed since the month was closed.
 */
export async function render(container) {
    let months = [];
    let ready = true;
    let canClose = false;
    let month = null;
    let data = null;
    let error = null;
    let busy = false;

    const fmt = (f) => (f.unit === 'money' ? `฿ ${money(f.value)}` : count(f.value));
    const fmtVal = (unit, v) => (unit === 'money' ? `฿ ${money(v)}` : count(v));

    async function loadMonths() {
        const res = await api.get('/monthly-closings');
        months = res.data;
        ready = res.ready;
        canClose = res.can_close;
        // Start on the latest month that has ended (the one to close), else this month.
        if (!month) month = (months.find((m) => m.ended) || months[0])?.month || null;
    }

    async function loadMonth() {
        data = null;
        draw();
        try {
            data = (await api.get(`/monthly-closings/${month}`)).data;
            error = null;
        } catch (e) {
            error = e.message;
        }
        draw();
    }

    function sectionHtml(title, rows) {
        return `
          <h3 style="margin:16px 0 8px;font-size:14.5px">${escapeHtml(title)}</h3>
          <div class="table-wrap" style="border:1px solid var(--border);border-radius:10px"><table>
            <tbody>${rows.map(([k, f]) => {
                const total = k === 'total_income' || k === 'loss_total' || k === 'net_profit' || k === 'net_after_loss' || k === 'closing_stock';
                const bad = (f.unit === 'money' && f.value < 0 && k !== 'stock_fix' && k !== 'sale_edit_diff' && k !== 'closing_stock_change')
                    || (k === 'negative_items' && f.value > 0) || (k === 'consigned_left' && f.value > 0);
                const kept = data.kept?.[k];
                const changed = kept && Math.abs(Number(kept.value) - Number(f.value)) >= 0.005;
                return `<tr${total ? ' style="font-weight:700"' : ''}>
                  <td>${escapeHtml(f.label)}${f.note ? `<div class="muted" style="font-size:12px;font-weight:400">${escapeHtml(f.note)}</div>` : ''}</td>
                  <td class="num" style="white-space:nowrap;${bad ? 'color:var(--bad)' : ''}">${fmt(f)}
                    ${changed ? `<div class="pill warn" style="font-size:11px;font-weight:400" title="ยอดตอนปิดบัญชี">ตอนปิด ${fmtVal(f.unit, kept.value)}</div>` : ''}</td>
                </tr>`;
            }).join('')}</tbody>
          </table></div>`;
    }

    function figuresHtml() {
        if (!data) return '<div class="card-pad muted">กำลังคำนวณ...</div>';
        const groups = new Map();
        Object.entries(data.figures).forEach(([k, f]) => {
            if (!groups.has(f.section)) groups.set(f.section, []);
            groups.get(f.section).push([k, f]);
        });
        const ended = months.find((m) => m.month === month)?.ended;
        const c = data.closing;
        return `
          ${c ? `<div class="hint" style="margin-top:4px"><div class="dot">✓</div><div>ปิดบัญชีแล้วเมื่อ ${escapeHtml(fmtDateTime(c.closed_at))} โดย ${escapeHtml(c.closer || '-')}${c.note ? ` · หมายเหตุ: ${escapeHtml(c.note)}` : ''}</div></div>`
              : `<div class="hint" style="margin-top:4px"><div class="dot">i</div><div>${ended ? 'เดือนนี้สิ้นสุดแล้ว ยังไม่ได้ปิดบัญชี' : 'เดือนนี้ยังไม่สิ้นสุด ตัวเลขยังเปลี่ยนได้ — ปิดบัญชีได้ตั้งแต่วันที่ 1 ของเดือนถัดไป'}</div></div>`}
          ${data.changed.length ? `
            <div class="error-box" style="margin-top:10px">
              <b>ยอดเปลี่ยนจากตอนปิดบัญชี ${data.changed.length} รายการ</b> — มีการบันทึกหรือแก้ไขรายการของเดือนนี้หลังปิดบัญชี ตรวจสอบแล้วกด "ปิดบัญชีใหม่" เพื่อเก็บยอดล่าสุด
              <ul style="margin:6px 0 0;padding-left:18px">${data.changed.map((x) => `<li>${escapeHtml(x.label)}: ตอนปิด ${fmtVal(x.unit, x.closed)} → ตอนนี้ ${fmtVal(x.unit, x.now)}</li>`).join('')}</ul>
            </div>` : ''}
          ${[...groups.entries()].map(([t, rows]) => sectionHtml(t, rows)).join('')}
          <div class="muted" style="font-size:12px;margin-top:10px">ต้นทุนสินค้าหาย/เกินจากการตรวจนับและสินค้าคงเหลือ คิดตามต้นทุนต่อชิ้นปัจจุบัน · สินค้าคงเหลือสิ้นเดือนคำนวณจากเอกสารในบัตรสินค้าถึงวันสิ้นเดือน เฉพาะสินค้าของร้าน (สินค้าฝากขายไม่นับเป็นทุน และคืนผู้ฝากทั้งหมด) · ยอดยกมาต้นเดือนใช้ยอดที่ปิดบัญชีเดือนก่อน ถ้ายังไม่ได้ปิดจะคำนวณให้ · ปิดบัญชีแล้วยังแก้รายการย้อนหลังได้ หน้านี้จะแจ้งรายการที่ยอดเปลี่ยน</div>`;
    }

    function draw() {
        const m = months.find((x) => x.month === month);
        container.innerHTML = `
          ${!ready ? '<div class="error-box">ยังปิดบัญชีประจำเดือนไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0085) ก่อน — ดูตัวเลขได้ตามปกติ</div>' : ''}
          ${error ? `<div class="error-box">${escapeHtml(error)}</div>` : ''}
          <section class="card card-pad">
            <div class="actions-row" style="justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
              <label class="field" style="flex-direction:row;align-items:center;gap:8px;margin:0"><span>เดือน</span>
                <select id="mc-month">${months.map((x) => `<option value="${x.month}" ${x.month === month ? 'selected' : ''}>${monthLabel(x.month)}${x.closed ? ' ✓ ปิดแล้ว' : x.ended ? ' — ยังไม่ปิด' : ' (เดือนปัจจุบัน)'}</option>`).join('')}</select></label>
              <div class="actions-row" style="gap:6px">
                <button type="button" class="btn secondary" id="mc-print" ${data ? '' : 'disabled'}>พิมพ์</button>
                ${canClose && ready && m?.ended ? `<button type="button" class="btn" id="mc-close" ${busy || !data ? 'disabled' : ''}>${m.closed ? 'ปิดบัญชีใหม่ (เก็บยอดล่าสุด)' : `ปิดบัญชี ${monthLabel(month)}`}</button>` : ''}
              </div>
            </div>
            <h2 style="margin:14px 0 0">สรุปยอดปิดบัญชีประจำเดือน ${month ? monthLabel(month) : ''}</h2>
            ${month ? figuresHtml() : '<div class="empty">ยังไม่มีข้อมูล</div>'}
          </section>
          ${months.some((x) => x.closed) ? `
          <section class="card">
            <div class="card-pad"><b>เดือนที่ปิดบัญชีแล้ว</b></div>
            <div class="table-wrap"><table>
              <thead><tr><th>เดือน</th><th class="num">กำไร (ขาดทุน)</th><th class="num">สินค้าคงเหลือยกไป</th><th>ปิดเมื่อ / โดย</th></tr></thead>
              <tbody>${months.filter((x) => x.closed).map((x) => `<tr data-mc-pick="${x.month}" style="cursor:pointer">
                <td>${monthLabel(x.month)}</td><td class="num" style="${x.net_profit < 0 ? 'color:var(--bad)' : ''}">${money(x.net_profit)}</td>
                <td class="num">${money(x.closing_stock_value)}</td><td class="muted">${escapeHtml(fmtDateTime(x.closed_at))} · ${escapeHtml(x.closer || '-')}</td></tr>`).join('')}</tbody>
            </table></div>
          </section>` : ''}`;
        container.querySelector('#mc-month')?.addEventListener('change', (e) => { month = e.target.value; loadMonth(); });
        container.querySelectorAll('[data-mc-pick]').forEach((tr) => tr.addEventListener('click', () => { month = tr.dataset.mcPick; loadMonth(); }));
        container.querySelector('#mc-close')?.addEventListener('click', closeMonth);
        container.querySelector('#mc-print')?.addEventListener('click', () => window.print());
    }

    async function closeMonth() {
        const again = months.find((x) => x.month === month)?.closed;
        const f = data.figures;
        const note = await promptDialog(
            `${monthLabel(month)}: กำไร (ขาดทุน) ฿${money(f.net_profit.value)} · ความสูญเสียสุทธิ ฿${money(f.loss_total.value)} · สินค้าของร้านคงเหลือยกไป ฿${money(f.closing_stock.value)}\n\n`
            + 'ระบบจะเก็บตัวเลขชุดนี้ไว้ และใช้สินค้าคงเหลือเป็นยอดยกมาของเดือนถัดไป รายการย้อนหลังยังแก้ไขได้ (หน้านี้จะแจ้งถ้ายอดเปลี่ยน)',
            { title: again ? `ปิดบัญชี ${monthLabel(month)} ใหม่?` : `ปิดบัญชี ${monthLabel(month)}?`, okText: again ? 'ปิดบัญชีใหม่' : 'ปิดบัญชี', placeholder: 'หมายเหตุ (ถ้ามี)', multiline: true }
        );
        if (note === null) return;
        busy = true;
        draw();
        try {
            data = (await api.post(`/monthly-closings/${month}/close`, { note })).data;
            await loadMonths();
            alertDialog(`เก็บยอดปิดบัญชี ${monthLabel(month)} แล้ว`, { title: 'ปิดบัญชีแล้ว' });
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
        busy = false;
        draw();
    }

    try {
        await loadMonths();
    } catch (e) {
        error = e.message;
    }
    if (month) await loadMonth(); else draw();
}
