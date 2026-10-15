import { api } from '../api.js';
import { escapeHtml, fmtDateTime } from '../util.js';

export async function render(container) {
    let error = null;

    async function load() {
        const res = await api.get('/admin/migrations');
        draw(res.data);
    }

    function draw(rows) {
        const pending = rows.filter((r) => !r.applied).length;
        container.innerHTML = `
        <section class="card card-pad">
          <div class="actions-row" style="justify-content:space-between;margin-bottom:14px">
            <div>
              <h2>สถานะโครงสร้างฐานข้อมูล</h2>
              <p class="muted" style="font-size:13px;margin:4px 0 0">${pending === 0 ? 'อัปเดตล่าสุดแล้ว ไม่มีรายการค้าง' : `มี ${pending} รายการที่ยังไม่ได้รัน`}</p>
            </div>
            <div class="actions-row">
              <button type="button" class="btn secondary" id="btn-rollback" ${rows.some((r) => r.applied) ? '' : 'disabled'}>ย้อนกลับชุดล่าสุด</button>
              <button type="button" class="btn" id="btn-run" ${pending ? '' : 'disabled'}>รัน migration ที่ค้างอยู่</button>
            </div>
          </div>
          ${error ? `<div class="error-box" style="margin-bottom:14px">${escapeHtml(error)}</div>` : ''}
          <div class="table-wrap"><table>
            <thead><tr><th>Migration</th><th>สถานะ</th><th>Batch</th><th>วันที่รัน</th></tr></thead>
            <tbody>
              ${rows.map((r) => `
                <tr>
                  <td style="font-family:ui-monospace,Menlo,monospace;font-size:12.5px">${escapeHtml(r.name)}</td>
                  <td><span class="pill ${r.applied ? 'ok' : 'warn'}">${r.applied ? 'รันแล้ว' : 'รอดำเนินการ'}</span></td>
                  <td>${r.batch ?? '-'}</td>
                  <td class="muted">${r.run_at ? fmtDateTime(r.run_at) : '-'}</td>
                </tr>
              `).join('')}
            </tbody>
          </table></div>
        </section>`;

        container.querySelector('#btn-run').addEventListener('click', async () => {
            try { await api.post('/admin/migrations/run'); error = null; await load(); }
            catch (e) { error = e.message; draw(rows); }
        });
        container.querySelector('#btn-rollback').addEventListener('click', async () => {
            if (!confirm('ย้อนกลับ migration ชุดล่าสุด? การกระทำนี้จะรันคำสั่ง down() ของแต่ละไฟล์ในชุดนั้น')) return;
            try { await api.post('/admin/migrations/rollback'); error = null; await load(); }
            catch (e) { error = e.message; draw(rows); }
        });
    }

    await load();
}
