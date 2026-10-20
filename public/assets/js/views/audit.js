import { api } from '../api.js';
import { escapeHtml, fmtDateTime } from '../util.js';

export async function render(container) {
    let table = '';
    let from = '';
    let to = '';

    async function load() {
        const params = new URLSearchParams();
        if (table) params.set('table', table);
        if (from) params.set('from', from);
        if (to) params.set('to', to);
        const res = await api.get(`/admin/audit-logs?${params.toString()}`);
        draw(res.data);
    }

    function draw(rows) {
        container.innerHTML = `
        <section class="card card-pad">
          <div class="actions-row">
            <select id="f-table">
              <option value="">ทุกตาราง</option>
              ${['products', 'consignors', 'receive_documents', 'return_documents', 'sales', 'withdrawals', 'stock_counts'].map((t) => `<option value="${t}" ${table === t ? 'selected' : ''}>${t}</option>`).join('')}
            </select>
            <label class="field" style="flex-direction:row;align-items:center;gap:6px">ตั้งแต่ <input type="date" id="f-from" value="${from}" style="padding:6px 8px"></label>
            <label class="field" style="flex-direction:row;align-items:center;gap:6px">ถึง <input type="date" id="f-to" value="${to}" style="padding:6px 8px"></label>
          </div>
        </section>
        <section class="card">
          <div class="table-wrap"><table>
            <thead><tr><th>เวลา</th><th>ตาราง</th><th>รหัส</th><th>การกระทำ</th><th>ผู้ทำรายการ</th><th>IP</th></tr></thead>
            <tbody>
              ${rows.map((r) => `
                <tr>
                  <td class="muted">${fmtDateTime(r.created_at)}</td>
                  <td style="font-family:ui-monospace,Menlo,monospace;font-size:12.5px">${escapeHtml(r.table_name)}</td>
                  <td class="num">${r.record_id}</td>
                  <td><span class="pill ${r.action === 'delete' || r.action === 'cancel' ? 'bad' : r.action === 'update' ? 'warn' : 'ok'}">${escapeHtml(r.action)}</span></td>
                  <td>${escapeHtml(r.user_name || '-')}</td>
                  <td class="muted" style="font-size:12px">${escapeHtml(r.ip_address || '')}</td>
                </tr>
              `).join('') || `<tr><td colspan="6" class="empty">ไม่มีข้อมูล</td></tr>`}
            </tbody>
          </table></div>
        </section>`;

        container.querySelector('#f-table').addEventListener('change', (e) => { table = e.target.value; load(); });
        container.querySelector('#f-from').addEventListener('change', (e) => { from = e.target.value; load(); });
        container.querySelector('#f-to').addEventListener('change', (e) => { to = e.target.value; load(); });
    }

    await load();
}
