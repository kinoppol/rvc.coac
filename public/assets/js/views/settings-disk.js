import { api } from '../api.js';
import { escapeHtml, count, fmtDate, fmtDateTime } from '../util.js';

const GB = 1024 ** 3;
const size = (b) => {
    const n = Math.abs(b);
    if (n >= GB) return `${(b / GB).toLocaleString('th-TH', { maximumFractionDigits: 2 })} GB`;
    if (n >= 1024 ** 2) return `${(b / 1024 ** 2).toLocaleString('th-TH', { maximumFractionDigits: 1 })} MB`;
    return `${Math.round(b / 1024).toLocaleString('th-TH')} KB`;
};
const signed = (b) => (b > 0 ? `+${size(b)}` : b < 0 ? `−${size(-b)}` : '0');
const systemBytes = (r) => r.uploads_bytes + r.logs_bytes + r.other_storage_bytes + r.db_bytes + r.app_bytes;

/** Settings > พื้นที่ดิสก์ (admin): disk space now, this system's share, the daily readings and the growth. */
export async function renderDiskPanel(el) {
    let d = null;
    let error = null;
    let busy = false;
    let msg = null;

    async function load() {
        try {
            d = (await api.get('/admin/disk')).data;
            error = null;
        } catch (e) {
            error = e.message;
        }
        draw();
    }

    function draw() {
        if (error) { el.innerHTML = `<div class="error-box">${escapeHtml(error)}</div>`; return; }
        const n = d.now;
        const used = n.disk_total - n.disk_free;
        const pct = n.disk_total ? (used / n.disk_total) * 100 : 0;
        const tone = pct >= 90 ? 'var(--bad)' : pct >= 75 ? 'var(--warn)' : 'var(--ok)';
        el.innerHTML = `
          <div style="max-width:1100px;display:flex;flex-direction:column;gap:16px">
            <section class="card card-pad">
              <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
                <h2 style="margin:0">พื้นที่ดิสก์ของเซิร์ฟเวอร์</h2>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                  <button type="button" class="btn secondary sm" id="dk-reload">↻ ตรวจใหม่</button>
                  ${d.recording ? `<button type="button" class="btn sm" id="dk-record" ${busy ? 'disabled' : ''}>${busy ? 'กำลังบันทึก...' : 'บันทึกค่าของวันนี้'}</button>` : ''}
                </div>
              </div>
              <div class="kpi-grid" style="margin-top:14px">
                <div class="kpi"><div class="lbl">ขนาดดิสก์ทั้งหมด</div><div class="val">${size(n.disk_total)}</div><div class="sub">ไดรฟ์ที่ติดตั้งระบบ</div></div>
                <div class="kpi"><div class="lbl">ใช้ไปแล้ว</div><div class="val" style="color:${tone}">${size(used)}</div><div class="sub">${pct.toFixed(1)}% ของดิสก์ (รวมโปรแกรมอื่นบนเครื่อง)</div></div>
                <div class="kpi"><div class="lbl">พื้นที่เหลือ</div><div class="val">${size(n.disk_free)}</div><div class="sub">${(100 - pct).toFixed(1)}% ของดิสก์</div></div>
                <div class="kpi"><div class="lbl">ข้อมูลของระบบนี้</div><div class="val">${size(systemBytes(n))}</div><div class="sub">ไฟล์อัปโหลด ฐานข้อมูล ล็อก และตัวโปรแกรม</div></div>
              </div>
              <div class="disk-bar" title="ใช้ไป ${pct.toFixed(1)}%"><span style="width:${Math.min(100, pct).toFixed(2)}%;background:${tone}"></span></div>
              ${pct >= 90 ? '<div class="error-box" style="margin-top:10px">พื้นที่ดิสก์ใกล้เต็ม (ใช้ไปเกิน 90%) ควรจัดหาพื้นที่เพิ่มหรือย้ายไฟล์ที่ไม่ใช้ออกโดยเร็ว</div>'
                : pct >= 75 ? '<div class="hint" style="margin-top:10px"><div class="dot">i</div><div>ใช้พื้นที่ดิสก์ไปเกิน 75% แล้ว ควรเริ่มวางแผนจัดหาพื้นที่เพิ่ม</div></div>' : ''}
              <div class="table-wrap" style="margin-top:14px"><table>
                <thead><tr><th>ข้อมูลของระบบ</th><th class="num">ขนาด</th><th>รายละเอียด</th></tr></thead>
                <tbody>
                  <tr><td>ไฟล์อัปโหลด</td><td class="num">${size(n.uploads_bytes)}</td><td class="muted" style="font-size:12.5px">${count(n.uploads_files)} ไฟล์ · รูปสินค้า ใบเสร็จ หลักฐานการซื้อ (storage/uploads)</td></tr>
                  <tr><td>ฐานข้อมูล</td><td class="num">${size(n.db_bytes)}</td><td class="muted" style="font-size:12.5px">ตารางและดัชนีของระบบใน MySQL</td></tr>
                  <tr><td>ล็อกการทำงาน</td><td class="num">${size(n.logs_bytes)}</td><td class="muted" style="font-size:12.5px">storage/logs (เวลาตอบสนองเก็บ 14 วัน)</td></tr>
                  <tr><td>ไฟล์ชั่วคราว / กักกัน</td><td class="num">${size(n.other_storage_bytes)}</td><td class="muted" style="font-size:12.5px">storage อื่น ๆ (cache, ไฟล์ที่กักกัน)</td></tr>
                  <tr><td>ตัวโปรแกรม</td><td class="num">${size(n.app_bytes)}</td><td class="muted" style="font-size:12.5px">โค้ดระบบและไลบรารี (ไม่รวม .git)</td></tr>
                </tbody>
              </table></div>
              ${msg ? `<div class="${msg.ok ? 'pill ok' : 'error-box'}" style="margin-top:10px;display:inline-block">${escapeHtml(msg.text)}</div>` : ''}
            </section>
            ${growthHtml()}
            ${historyHtml()}
          </div>`;
        el.querySelector('#dk-reload').addEventListener('click', () => { msg = null; el.innerHTML = '<div class="muted">กำลังตรวจพื้นที่ดิสก์...</div>'; load(); });
        el.querySelector('#dk-record')?.addEventListener('click', async () => {
            busy = true;
            draw();
            try {
                await api.post('/admin/disk/record');
                msg = { ok: true, text: 'บันทึกค่าของวันนี้แล้ว' };
            } catch (e) {
                msg = { ok: false, text: e.message };
            }
            busy = false;
            await load();
        });
    }

    function growthHtml() {
        if (!d.recording) {
            return `<section class="card card-pad"><div class="error-box">ยังไม่ได้บันทึกอัตราการใช้ดิสก์รายวัน: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0060) ก่อน</div></section>`;
        }
        const rows = [['7 วันล่าสุด', d.forecast.week], ['30 วันล่าสุด', d.forecast.month], ['90 วันล่าสุด', d.forecast.quarter]];
        const best = d.forecast.month.days ? d.forecast.month : d.forecast.week.days ? d.forecast.week : d.forecast.quarter;
        const left = best.days_left;
        return `
          <section class="card card-pad">
            <h2 style="margin-bottom:6px">อัตราการใช้ดิสก์</h2>
            <p class="muted" style="font-size:13px;margin:0 0 12px">ระบบบันทึกค่าวันละครั้งเองหลังมีผู้ใช้เข้าระบบครั้งแรกของวัน (ตั้งงานตามเวลาด้วย bin/disk-usage.php ได้ เพื่อให้มีค่าแม้วันที่ไม่มีใครใช้งาน)</p>
            ${best.days ? `<div class="${left !== null && left < 90 ? 'error-box' : 'hint'}" style="margin-bottom:12px">${left !== null && left < 90 ? '' : '<div class="dot">i</div>'}<div>
                ${left === null ? 'พื้นที่ที่ใช้ไม่ได้เพิ่มขึ้นในช่วงนี้ ยังไม่ต้องจัดหาพื้นที่เพิ่ม'
                    : `ถ้าใช้ต่อในอัตรานี้ (${signed(Math.max(best.used_per_day, best.system_per_day))}/วัน) พื้นที่ที่เหลือจะพอใช้อีกประมาณ <b>${count(left)} วัน</b> (${left >= 365 ? `ราว ${(left / 365).toFixed(1)} ปี` : `ราว ${(left / 30).toFixed(1)} เดือน`})${left < 90 ? ' — ควรจัดหาพื้นที่เพิ่ม' : ''}`}</div></div>`
                : '<div class="muted" style="margin-bottom:12px">ต้องมีค่าที่บันทึกอย่างน้อย 2 วันจึงคำนวณอัตราการใช้ได้</div>'}
            <div class="table-wrap"><table>
              <thead><tr><th>ช่วง</th><th class="num">ข้อมูลระบบเพิ่ม/วัน</th><th class="num">ดิสก์ทั้งเครื่องเพิ่ม/วัน</th><th class="num">พอใช้อีก</th></tr></thead>
              <tbody>${rows.map(([label, f]) => `<tr><td>${label}${f.days ? ` <span class="muted" style="font-size:12px">(${f.days} วัน)</span>` : ''}</td>
                <td class="num">${f.days ? signed(f.system_per_day) : '—'}</td><td class="num">${f.days ? signed(f.used_per_day) : '—'}</td>
                <td class="num">${!f.days ? '—' : f.days_left === null ? 'ไม่เพิ่มขึ้น' : `${count(f.days_left)} วัน`}</td></tr>`).join('')}</tbody>
            </table></div>
          </section>`;
    }

    function historyHtml() {
        if (!d.recording || !d.history.length) return '';
        const h = d.history;
        return `
          <section class="card">
            <div class="card-head"><h2>บันทึกรายวัน (${count(h.length)} วัน)</h2></div>
            <div style="padding:12px 18px 0">${chartHtml([...h].reverse().slice(-90))}</div>
            <div class="table-wrap" style="max-height:460px"><table>
              <thead><tr><th>วันที่</th><th class="num">ใช้ไป</th><th class="num">เหลือ</th><th class="num">ข้อมูลระบบ</th><th class="num">เปลี่ยนจากวันก่อน</th>
                <th class="num">ไฟล์อัปโหลด</th><th class="num">ฐานข้อมูล</th><th>บันทึกเมื่อ</th></tr></thead>
              <tbody>${h.map((r, i) => {
                const prev = h[i + 1];
                const diff = prev ? systemBytes(r) - systemBytes(prev) : null;
                return `<tr><td style="white-space:nowrap">${escapeHtml(fmtDate(r.day))}</td>
                  <td class="num">${size(r.disk_total - r.disk_free)} <span class="muted" style="font-size:12px">(${((r.disk_total - r.disk_free) / r.disk_total * 100).toFixed(1)}%)</span></td>
                  <td class="num">${size(r.disk_free)}</td><td class="num">${size(systemBytes(r))}</td>
                  <td class="num">${diff === null ? '—' : signed(diff)}</td>
                  <td class="num">${size(r.uploads_bytes)} <span class="muted" style="font-size:12px">${count(r.uploads_files)} ไฟล์</span></td>
                  <td class="num">${size(r.db_bytes)}</td>
                  <td class="muted" style="font-size:12px;white-space:nowrap">${escapeHtml(fmtDateTime(r.recorded_at))}</td></tr>`;
              }).join('')}</tbody>
            </table></div>
          </section>`;
    }

    /** Used share of the disk a day (bars), oldest on the left. */
    function chartHtml(rows) {
        if (rows.length < 2) return '';
        const w = 100 / rows.length;
        return `<div class="disk-chart" role="img" aria-label="สัดส่วนพื้นที่ดิสก์ที่ใช้รายวัน">
            ${rows.map((r) => {
                const pct = (r.disk_total - r.disk_free) / r.disk_total * 100;
                const tone = pct >= 90 ? 'var(--bad)' : pct >= 75 ? 'var(--warn)' : 'var(--pri)';
                return `<span style="width:${w}%;height:${pct.toFixed(1)}%;background:${tone}" title="${escapeHtml(fmtDate(r.day))}: ใช้ไป ${pct.toFixed(1)}% · เหลือ ${size(r.disk_free)}"></span>`;
            }).join('')}
          </div>
          <div class="muted" style="display:flex;justify-content:space-between;font-size:11.5px;margin:4px 0 12px"><span>${escapeHtml(fmtDate(rows[0].day))}</span><span>สัดส่วนที่ใช้ไปของดิสก์ (0–100%)</span><span>${escapeHtml(fmtDate(rows[rows.length - 1].day))}</span></div>`;
    }

    el.innerHTML = '<div class="muted">กำลังตรวจพื้นที่ดิสก์...</div>';
    await load();
}
