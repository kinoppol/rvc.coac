import { api } from '../api.js';
import { money, count, escapeHtml, fmtDateTime, qs, todayISO } from '../util.js';

/** AI usage log and estimated cost, summarised per day / week / month. */
const PERIOD_LABEL = { day: 'รายวัน', week: 'รายสัปดาห์', month: 'รายเดือน' };
const CATEGORY_PILL = { general: 'muted', markdown: 'warn', receipt: 'ok' };

function thDate(s, opts) {
    const d = new Date(String(s) + 'T00:00:00');
    return isNaN(d.getTime()) ? s : d.toLocaleDateString('th-TH', opts);
}

function periodLabel(period, row) {
    if (period === 'month') return thDate(row.period_start, { month: 'long', year: 'numeric' });
    if (period === 'week') {
        return `${thDate(row.period_start, { day: 'numeric', month: 'short' })} – ${thDate(row.period_end, { day: 'numeric', month: 'short', year: 'numeric' })}`;
    }
    return thDate(row.period_start, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
}

export async function render(container) {
    const f = { period: 'day', from: '', to: '', user_id: '', category: '', q: '' };
    let data = null;
    let logs = { rows: [], total: 0, page: 1, per_page: 50 };
    let rateMsg = null;
    let expanded = null; // log id whose full prompt is shown

    async function load() {
        data = await api.get('/ai/usage' + qs({ period: f.period, from: f.from, to: f.to, user_id: f.user_id, category: f.category }));
        f.from = data.filters.from;
        f.to = data.filters.to;
        await loadLogs(1);
    }

    async function loadLogs(page) {
        logs = await api.get('/ai/usage/logs' + qs({ from: f.from, to: f.to, user_id: f.user_id, category: f.category, q: f.q, page }));
        draw();
    }

    function kpi(label, t, sub) {
        return `<div class="kpi"><div class="lbl">${escapeHtml(label)}</div>
          <div class="val">฿ ${money(t.cost)}</div>
          <div class="sub">${count(t.requests)} รายการ${sub ? ` · ${escapeHtml(sub)}` : ''}</div></div>`;
    }

    function draw() {
        const { current, totals, summary, by_user: byUser, users, rates, category_labels: labels } = data;
        const maxCost = Math.max(1, ...summary.map((r) => r.cost));
        const pages = Math.max(1, Math.ceil(logs.total / logs.per_page));

        container.innerHTML = `
        <div class="kpi-grid">
          ${kpi('ค่าใช้จ่ายวันนี้', current.today)}
          ${kpi('สัปดาห์นี้', current.week, `${thDate(current.week.from, { day: 'numeric', month: 'short' })} – ${thDate(current.week.to, { day: 'numeric', month: 'short' })}`)}
          ${kpi('เดือนนี้', current.month, thDate(todayISO(), { month: 'long', year: 'numeric' }))}
        </div>

        <section class="card card-pad">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px">
            <h2>อัตราค่าใช้จ่ายโดยประมาณ (ต่อรายการ)</h2>
            ${rateMsg ? `<span class="pill ${rateMsg.type}">${escapeHtml(rateMsg.text)}</span>` : ''}
          </div>
          <form id="rate-form">
            <fieldset ${data.can_edit_rates ? '' : 'disabled'} style="border:0;padding:0;margin:0">
              <div class="form-grid">
                <label class="field">${escapeHtml(labels.general)} (บาท) <input name="ai_cost_general" type="number" min="0" step="0.25" value="${rates.general}"></label>
                <label class="field">${escapeHtml(labels.markdown)} (บาท) <input name="ai_cost_markdown" type="number" min="0" step="0.25" value="${rates.markdown}"></label>
                <label class="field">${escapeHtml(labels.receipt)} (บาท) <input name="ai_cost_receipt" type="number" min="0" step="0.25" value="${rates.receipt}"></label>
                <label class="field">นับเป็น Markdown เมื่อข้อความยาวตั้งแต่ (ตัวอักษร) <input name="ai_markdown_min_chars" type="number" min="0" step="100" value="${rates.markdown_min_chars}"></label>
                <label class="field">หรือมีตารางตั้งแต่ (แถว) <input name="ai_markdown_min_table_rows" type="number" min="0" value="${rates.markdown_min_table_rows}"></label>
              </div>
              <p class="muted" style="font-size:12.5px;margin:10px 0 0">
                <b>ใบเสร็จ</b> = ส่งรูปให้ AI อ่าน (ในแชท หรือปุ่ม "อ่านใบเสร็จด้วย AI") ·
                <b>Markdown</b> = แนบไฟล์ .md / .txt หรือวางข้อความยาว/ตารางใหญ่ตามเกณฑ์ ·
                <b>ทั่วไป</b> = รายการอื่น ๆ รวมถึงการกดยืนยัน/ยกเลิก — รายการที่ผิดพลาดไม่คิดเงิน
                และการเปลี่ยนอัตรามีผลกับรายการใหม่เท่านั้น
              </p>
              ${data.can_edit_rates ? '<div style="margin-top:12px"><button type="submit" class="btn sm">บันทึกอัตรา</button></div>' : ''}
            </fieldset>
          </form>
        </section>

        <section class="card card-pad">
          <div class="actions-row" style="margin-bottom:14px">
            <div class="tabs">${Object.entries(PERIOD_LABEL).map(([k, v]) => `<button type="button" class="tab-btn ${f.period === k ? 'active' : ''}" data-period="${k}">${v}</button>`).join('')}</div>
            <label class="pc-summary-field">ตั้งแต่ <input type="date" id="f-from" value="${escapeHtml(f.from)}" class="pc-select"></label>
            <label class="pc-summary-field">ถึง <input type="date" id="f-to" value="${escapeHtml(f.to)}" class="pc-select"></label>
            <select id="f-user" class="pc-select"><option value="">ผู้ใช้ทั้งหมด</option>
              ${users.map((u) => `<option value="${u.id}" ${String(u.id) === String(f.user_id) ? 'selected' : ''}>${escapeHtml(u.full_name)}</option>`).join('')}</select>
            <select id="f-cat" class="pc-select"><option value="">ทุกประเภท</option>
              ${Object.entries(labels).map(([k, v]) => `<option value="${k}" ${f.category === k ? 'selected' : ''}>${escapeHtml(v)}</option>`).join('')}</select>
            <a class="btn secondary sm" style="margin-left:auto" href="${escapeHtml(api.downloadUrl('/ai/usage/export' + qs({ from: f.from, to: f.to, user_id: f.user_id, category: f.category, q: f.q })))}">ส่งออก Excel (CSV)</a>
          </div>

          <div class="stat-row" style="margin-bottom:14px;font-size:13.5px">
            <div><div class="muted">รวมช่วงที่เลือก</div><b style="font-size:20px">฿ ${money(totals.cost)}</b></div>
            <div><div class="muted">จำนวนรายการ</div><b style="font-size:20px">${count(totals.requests)}</b></div>
            <div><div class="muted">ทั่วไป / Markdown / ใบเสร็จ</div><b>${count(totals.general_count)} / ${count(totals.markdown_count)} / ${count(totals.receipt_count)}</b></div>
            <div><div class="muted">ผิดพลาด (ไม่คิดเงิน)</div><b>${count(totals.error_count)}</b></div>
          </div>

          <div class="table-wrap" style="border:1px solid var(--border);border-radius:10px"><table>
            <thead><tr><th>ช่วงเวลา (${PERIOD_LABEL[f.period]})</th><th class="num">รายการ</th><th class="num">ทั่วไป</th><th class="num">Markdown</th><th class="num">ใบเสร็จ</th><th class="num">ผิดพลาด</th><th class="num">ค่าใช้จ่าย</th><th style="width:22%"></th></tr></thead>
            <tbody>
              ${summary.map((r) => `
                <tr><td style="white-space:nowrap">${escapeHtml(periodLabel(f.period, r))}</td>
                  <td class="num">${count(r.requests)}</td><td class="num">${count(r.general_count)}</td><td class="num">${count(r.markdown_count)}</td>
                  <td class="num">${count(r.receipt_count)}</td><td class="num">${count(r.error_count)}</td>
                  <td class="num" style="font-weight:600">${money(r.cost)}</td>
                  <td><div class="bar-track"><div class="bar-fill" style="width:${(r.cost / maxCost * 100).toFixed(1)}%"></div></div></td></tr>`).join('')
                || '<tr><td colspan="8" class="empty">ยังไม่มีการใช้งานในช่วงนี้</td></tr>'}
            </tbody>
          </table></div>
        </section>

        <section class="card">
          <div class="card-head"><h2>สรุปตามผู้ใช้</h2></div>
          <div class="table-wrap"><table>
            <thead><tr><th>ผู้ใช้</th><th class="num">รายการ</th><th class="num">ทั่วไป</th><th class="num">Markdown</th><th class="num">ใบเสร็จ</th><th class="num">ค่าใช้จ่าย</th></tr></thead>
            <tbody>${byUser.map((u) => `
              <tr><td>${escapeHtml(u.full_name)} <span class="muted" style="font-size:12px">${escapeHtml(u.username)}</span></td>
                <td class="num">${count(u.requests)}</td><td class="num">${count(u.general_count)}</td><td class="num">${count(u.markdown_count)}</td>
                <td class="num">${count(u.receipt_count)}</td><td class="num" style="font-weight:600">${money(u.cost)}</td></tr>`).join('')
              || '<tr><td colspan="6" class="empty">ยังไม่มีข้อมูล</td></tr>'}</tbody>
          </table></div>
        </section>

        <section class="card">
          <div class="card-head">
            <h2>บันทึกการใช้งาน (${count(logs.total)} รายการ)</h2>
            <div class="search-box" style="min-width:220px;padding:6px 10px"><input id="f-q" placeholder="ค้นหาคำสั่ง..." value="${escapeHtml(f.q)}"></div>
          </div>
          <div class="table-wrap"><table>
            <thead><tr><th>เวลา</th><th>ผู้ใช้</th><th>คำสั่ง</th><th>ประเภท</th><th>โมเดล</th><th class="num">ค่าใช้จ่าย</th><th>สถานะ</th></tr></thead>
            <tbody>${logs.rows.map((r) => `
              <tr>
                <td style="white-space:nowrap;font-size:12.5px">${fmtDateTime(r.created_at)}</td>
                <td style="white-space:nowrap">${escapeHtml(r.full_name)}</td>
                <td style="max-width:420px">
                  <div class="usage-prompt ${expanded === r.id ? 'open' : ''}" data-expand="${r.id}" title="คลิกเพื่อดูทั้งหมด">${escapeHtml(r.prompt || '-')}</div>
                  ${r.tool_calls ? `<div class="muted" style="font-size:11.5px">🔧 ${escapeHtml(r.tool_calls)}</div>` : ''}
                </td>
                <td><span class="pill ${CATEGORY_PILL[r.category] || 'muted'}">${escapeHtml(labels[r.category] || r.category)}</span>${Number(r.image_count) ? `<div class="muted" style="font-size:11.5px">🖼 ${r.image_count} รูป</div>` : ''}</td>
                <td class="muted" style="font-size:12px;word-break:break-all">${escapeHtml(r.model_name || '')}<div>${escapeHtml(r.provider_name || '')}</div></td>
                <td class="num">${money(r.cost)}</td>
                <td>${r.status === 'ok' ? '<span class="pill ok">สำเร็จ</span>' : `<span class="pill bad" title="${escapeHtml(r.error_message || '')}">ผิดพลาด</span>`}
                  <div class="muted" style="font-size:11px">${(Number(r.duration_ms) / 1000).toFixed(1)} วิ</div>
                  ${r.status !== 'ok' && r.error_message ? `<div style="font-size:11px;color:var(--bad);max-width:220px;word-break:break-word">${escapeHtml(r.error_message)}</div>` : ''}</td>
              </tr>`).join('') || '<tr><td colspan="7" class="empty">ไม่พบรายการ</td></tr>'}</tbody>
          </table></div>
          ${pages > 1 ? `<div class="actions-row" style="padding:12px 18px;justify-content:flex-end">
            <button type="button" class="btn secondary sm" data-page="${logs.page - 1}" ${logs.page <= 1 ? 'disabled' : ''}>ก่อนหน้า</button>
            <span class="muted" style="font-size:13px">หน้า ${logs.page} / ${pages}</span>
            <button type="button" class="btn secondary sm" data-page="${logs.page + 1}" ${logs.page >= pages ? 'disabled' : ''}>ถัดไป</button>
          </div>` : ''}
        </section>`;
        bind();
    }

    function bind() {
        container.querySelectorAll('[data-period]').forEach((b) => b.addEventListener('click', async () => {
            f.period = b.dataset.period;
            f.from = '';
            f.to = ''; // each period has its own default look-back
            await load();
        }));
        const onFilter = async () => {
            f.from = container.querySelector('#f-from').value;
            f.to = container.querySelector('#f-to').value;
            f.user_id = container.querySelector('#f-user').value;
            f.category = container.querySelector('#f-cat').value;
            await load();
        };
        ['#f-from', '#f-to', '#f-user', '#f-cat'].forEach((sel) => container.querySelector(sel).addEventListener('change', onFilter));

        const q = container.querySelector('#f-q');
        q.addEventListener('input', () => {
            clearTimeout(container._qt);
            container._qt = setTimeout(async () => {
                f.q = q.value.trim();
                await loadLogs(1);
                const box = container.querySelector('#f-q');
                box.focus();
                box.setSelectionRange(box.value.length, box.value.length);
            }, 300);
        });
        container.querySelectorAll('[data-page]').forEach((b) => b.addEventListener('click', () => loadLogs(Number(b.dataset.page))));
        container.querySelectorAll('[data-expand]').forEach((el) => el.addEventListener('click', () => {
            expanded = expanded === Number(el.dataset.expand) ? null : Number(el.dataset.expand);
            draw();
        }));

        container.querySelector('#rate-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const res = await api.put('/ai/usage/rates', Object.fromEntries(new FormData(e.target).entries()));
                data.rates = res.rates;
                rateMsg = { type: 'ok', text: 'บันทึกอัตราแล้ว (มีผลกับรายการใหม่)' };
            } catch (err) {
                rateMsg = { type: 'bad', text: err.message };
            }
            draw();
        });
    }

    await load();
}
