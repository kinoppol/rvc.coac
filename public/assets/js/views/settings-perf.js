import { api } from '../api.js';
import { escapeHtml, count, fmtDate } from '../util.js';
import { confirmDialog } from '../dialog.js';

const MODES = {
    development: ['กำลังพัฒนาระบบ', 'เบราว์เซอร์ถามเซิร์ฟเวอร์ทุกครั้งว่าไฟล์ JS/CSS เปลี่ยนหรือไม่ — แก้ไฟล์แล้วเห็นผลทันที แต่เปิดหน้าช้ากว่าเมื่อเซิร์ฟเวอร์ตอบช้า'],
    production: ['ทำงานปกติ', 'ไฟล์ JS/CSS ถูกเก็บไว้ในเบราว์เซอร์และ Cloudflare โดยไม่ต้องถามเซิร์ฟเวอร์ซ้ำ — เมื่ออัปเดตระบบ ไฟล์ที่เปลี่ยนจะได้เวอร์ชันใหม่เองอัตโนมัติ'],
};

const sec = (ms) => (ms < 1000 ? `${Math.round(ms)} ms` : `${(ms / 1000).toLocaleString('th-TH', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} วิ`);

/** Settings > ประสิทธิภาพ (admin): asset caching mode, request timings, product thumbnails. */
export async function renderPerfPanel(el) {
    let mode = 'development';
    let perf = null;
    let date = '';
    let routeFilter = '';
    let onlyFlagged = true;
    let thumbs = null; // { running, checked, total, had, made, missing[], failed[], gd, error }
    let modeMsg = null;

    try {
        mode = (await api.get('/settings')).settings.asset_mode || 'development';
    } catch (e) { /* keep default */ }
    await loadPerf();
    draw();

    async function loadPerf() {
        try {
            const res = await api.get(`/admin/perf${date ? `?date=${date}` : ''}`);
            perf = res;
            date = res.data.date;
        } catch (e) {
            perf = { error: e.message };
        }
    }

    function draw() {
        el.innerHTML = `
          <div style="max-width:1100px;display:flex;flex-direction:column;gap:16px">
            ${modeHtml()}
            ${perfHtml()}
            ${thumbsHtml()}
          </div>`;
        bind();
    }

    function modeHtml() {
        return `
          <section class="card card-pad">
            <h2 style="margin-bottom:6px">โหมดการทำงานของไฟล์ระบบ (JS / CSS)</h2>
            <p class="muted" style="font-size:13px;margin:0 0 12px">มีผลกับการโหลดหน้าเว็บของผู้ใช้ทุกคน เปลี่ยนแล้วมีผลเมื่อผู้ใช้เปิดหน้าใหม่</p>
            <div style="display:flex;flex-direction:column;gap:10px">
              ${Object.entries(MODES).map(([k, [label, hint]]) => `
                <label class="perf-mode ${mode === k ? 'on' : ''}">
                  <input type="radio" name="asset_mode" value="${k}" ${mode === k ? 'checked' : ''}>
                  <span><b>${label}</b><span class="muted" style="display:block;font-size:12.5px">${hint}</span></span>
                </label>`).join('')}
            </div>
            ${modeMsg ? `<div class="${modeMsg.ok ? 'pill ok' : 'error-box'}" style="margin-top:10px;display:inline-block">${escapeHtml(modeMsg.text)}</div>` : ''}
          </section>`;
    }

    function perfHtml() {
        if (perf?.error) return `<section class="card card-pad"><div class="error-box">${escapeHtml(perf.error)}</div></section>`;
        const d = perf.data;
        const routes = d.routes.filter((r) => !routeFilter || r.route === routeFilter);
        const rows = d.slow.filter((r) => (!onlyFlagged || r.reasons.length) && (!routeFilter || r.route === routeFilter));
        const flagged = d.slow.filter((r) => r.reasons.length).length;
        return `
          <section class="card">
            <div class="card-head" style="flex-wrap:wrap;gap:10px">
              <h2>เวลาตอบสนองของเซิร์ฟเวอร์</h2>
              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <select class="pc-select" id="perf-date">${(perf.days.length ? perf.days : [d.date]).map((x) => `<option value="${x}" ${x === d.date ? 'selected' : ''}>${escapeHtml(fmtDate(x))}</option>`).join('')}</select>
                <button type="button" class="btn secondary sm" id="perf-reload">↻ โหลดใหม่</button>
              </div>
            </div>
            <div style="padding:12px 18px 0">
              <div class="perf-stats">
                <div><span class="muted">คำขอทั้งหมด</span><b>${count(d.total)}</b></div>
                <div><span class="muted">ช้ากว่า ${sec(perf.list_from_ms)}</span><b>${count(d.slow.length)}</b></div>
                <div class="${flagged ? 'bad' : ''}"><span class="muted">ช้าผิดปกติ</span><b>${count(flagged)}</b></div>
              </div>
              <p class="muted" style="font-size:12.5px;margin:10px 0 0">นับเฉพาะคำขอที่ผ่าน PHP (หน้าเว็บ ข้อมูล รูปสินค้า) ไม่รวมไฟล์ JS/CSS ที่เว็บเซิร์ฟเวอร์ส่งเอง
                · <b>ช้าผิดปกติ</b> = ช้ากว่าเวลาปกติ (ค่ากลาง) ของคำขอแบบเดียวกันตั้งแต่ 3 เท่า, รอคิว/เริ่มระบบนานเกิน 0.5 วินาที, เกิน 10 วินาที หรือเซิร์ฟเวอร์ผิดพลาด</p>
            </div>
            <h3 style="margin:16px 18px 8px;font-size:14px">แยกตามประเภทคำขอ ${routeFilter ? `<button type="button" class="btn secondary sm" data-route-clear style="margin-left:8px">แสดงทั้งหมด</button>` : '<span class="muted" style="font-weight:400;font-size:12.5px">(กดเพื่อกรองรายการด้านล่าง)</span>'}</h3>
            <div class="table-wrap" style="max-height:300px"><table>
              <thead><tr><th>คำขอ</th><th class="num">จำนวน</th><th class="num">ปกติ (ค่ากลาง)</th><th class="num">90% ไม่เกิน</th><th class="num">ช้าสุด</th><th class="num">ช้าผิดปกติ</th></tr></thead>
              <tbody>${routes.slice(0, 60).map((r) => `
                <tr class="drill-row ${r.slow ? 'perf-bad' : ''}" data-route="${escapeHtml(r.route)}">
                  <td style="font-family:monospace;font-size:12.5px">${escapeHtml(r.route)}</td>
                  <td class="num">${count(r.count)}</td><td class="num">${sec(r.median)}</td><td class="num">${sec(r.p90)}</td>
                  <td class="num">${sec(r.max)}</td><td class="num">${r.slow ? `<b>${count(r.slow)}</b>` : '-'}</td>
                </tr>`).join('') || '<tr><td colspan="6" class="empty">ยังไม่มีข้อมูลของวันนี้</td></tr>'}</tbody>
            </table></div>
            <div style="display:flex;align-items:center;gap:12px;margin:16px 18px 8px;flex-wrap:wrap">
              <h3 style="margin:0;font-size:14px">คำขอที่ใช้เวลาเกิน ${sec(perf.list_from_ms)} (ล่าสุดก่อน)</h3>
              <label style="display:flex;gap:6px;align-items:center;font-size:13px"><input type="checkbox" id="perf-flagged" ${onlyFlagged ? 'checked' : ''}> เฉพาะที่ช้าผิดปกติ</label>
            </div>
            <div class="table-wrap" style="max-height:420px"><table>
              <thead><tr><th>เวลา</th><th>คำขอ</th><th class="num">ใช้เวลา</th><th class="num">ปกติ</th><th class="num">รอคิว</th><th>สถานะ</th><th>ผู้ใช้</th><th>ข้อสังเกต</th></tr></thead>
              <tbody>${rows.map((r) => `
                <tr class="${r.reasons.length ? 'perf-bad' : ''}">
                  <td>${escapeHtml(r.time)}</td>
                  <td style="font-family:monospace;font-size:12px;word-break:break-all">${escapeHtml(r.method)} ${escapeHtml(r.path)}</td>
                  <td class="num"><b>${sec(r.ms)}</b></td><td class="num muted">${sec(r.normal_ms)}</td>
                  <td class="num ${r.session_ms >= 500 ? '' : 'muted'}">${sec(r.session_ms)}</td>
                  <td>${r.status}</td><td class="muted" style="font-size:12.5px">${escapeHtml(r.user || '-')}</td>
                  <td>${r.reasons.map((x) => `<span class="pill bad" style="margin:1px">${escapeHtml(x)}</span>`).join('') || '<span class="muted" style="font-size:12px">ช้าตามปกติของคำขอนี้</span>'}</td>
                </tr>`).join('') || `<tr><td colspan="8" class="empty">${onlyFlagged ? 'ไม่มีคำขอที่ช้าผิดปกติ' : 'ไม่มีคำขอที่ช้า'}</td></tr>`}</tbody>
            </table></div>
          </section>`;
    }

    function thumbsHtml() {
        const t = thumbs;
        const pct = t && t.total ? Math.round((t.checked / t.total) * 100) : 0;
        return `
          <section class="card card-pad">
            <h2 style="margin-bottom:6px">รูปย่อสินค้า</h2>
            <p class="muted" style="font-size:13px;margin:0 0 12px">หน้าขายสินค้าใช้รูปย่อขนาดเล็กเพื่อให้โหลดเร็ว ระบบสร้างรูปย่อทุกครั้งที่อัปโหลดรูปใหม่
              ปุ่มนี้ตรวจสินค้าที่มีรูปทุกรายการ: ไฟล์รูปยังอยู่ครบ และสร้างรูปย่อให้รูปที่ยังไม่มี</p>
            <button type="button" class="btn" id="thumb-run" ${t?.running ? 'disabled' : ''}>${t?.running ? `กำลังตรวจ… ${pct}%` : '🔍 ตรวจสอบรูปสินค้าทั้งหมด'}</button>
            ${t ? `
              <div style="margin-top:12px">
                <div class="perf-bar"><span style="width:${pct}%"></span></div>
                <div style="font-size:13px;margin-top:8px">ตรวจแล้ว ${count(t.checked)} / ${count(t.total)} รูป · มีรูปย่ออยู่แล้ว ${count(t.had)} · สร้างใหม่ ${count(t.made)}
                  ${t.missing.length ? ` · <b style="color:var(--bad)">ไม่พบไฟล์รูป ${count(t.missing.length)}</b>` : ''}${t.failed.length ? ` · <b style="color:var(--warn)">สร้างไม่ได้ ${count(t.failed.length)}</b>` : ''}</div>
                ${t.gd === false ? '<div class="muted" style="font-size:12.5px;margin-top:6px">เซิร์ฟเวอร์ไม่มีส่วนขยาย GD จึงสร้างรูปย่อเองไม่ได้ — รูปย่อจะถูกสร้างจากเบราว์เซอร์เมื่อเปิดหน้าขายสินค้าครั้งแรก</div>' : ''}
                ${t.missing.length ? `<div style="font-size:12.5px;margin-top:6px"><b>ไม่พบไฟล์รูป</b> (ถ่ายรูปใหม่ที่หน้าตรวจนับสต๊อก): ${t.missing.map(escapeHtml).join(', ')}</div>` : ''}
                ${t.failed.length ? `<div style="font-size:12.5px;margin-top:6px"><b>สร้างรูปย่อไม่ได้:</b> ${t.failed.map(escapeHtml).join(', ')}</div>` : ''}
                ${t.error ? `<div class="error-box" style="margin-top:8px">${escapeHtml(t.error)}</div>` : ''}
                ${!t.running && !t.error ? '<div class="pill ok" style="margin-top:8px;display:inline-block">ตรวจครบแล้ว</div>' : ''}
              </div>` : ''}
          </section>`;
    }

    function bind() {
        el.querySelectorAll('[name=asset_mode]').forEach((r) => r.addEventListener('change', () => setMode(r.value)));
        el.querySelector('#perf-date')?.addEventListener('change', async (e) => { date = e.target.value; routeFilter = ''; await loadPerf(); draw(); });
        el.querySelector('#perf-reload')?.addEventListener('click', async () => { await loadPerf(); draw(); });
        el.querySelector('#perf-flagged')?.addEventListener('change', (e) => { onlyFlagged = e.target.checked; draw(); });
        el.querySelectorAll('[data-route]').forEach((tr) => tr.addEventListener('click', () => { routeFilter = tr.dataset.route; onlyFlagged = false; draw(); }));
        el.querySelector('[data-route-clear]')?.addEventListener('click', () => { routeFilter = ''; draw(); });
        el.querySelector('#thumb-run')?.addEventListener('click', runThumbs);
    }

    async function setMode(next) {
        if (next === mode) return;
        if (!(await confirmDialog(`เปลี่ยนเป็นโหมด «${MODES[next][0]}»? มีผลกับผู้ใช้ทุกคนเมื่อเปิดหน้าใหม่`, { title: 'เปลี่ยนโหมดการทำงาน', okText: 'เปลี่ยนโหมด' }))) {
            draw();
            return;
        }
        try {
            await api.put('/settings', { asset_mode: next });
            mode = next;
            modeMsg = { ok: true, text: `เปลี่ยนเป็นโหมด «${MODES[next][0]}» แล้ว — โหลดหน้านี้ใหม่เพื่อใช้โหมดใหม่` };
        } catch (e) {
            modeMsg = { ok: false, text: e.message };
        }
        draw();
    }

    async function runThumbs() {
        thumbs = { running: true, checked: 0, total: 0, had: 0, made: 0, missing: [], failed: [], gd: null, error: null };
        draw();
        let after = 0;
        try {
            while (after !== null) {
                const r = (await api.post('/admin/thumbnails', { after_id: after })).data;
                if (!el.isConnected) return;
                Object.assign(thumbs, {
                    total: r.total, gd: r.gd, checked: thumbs.checked + r.checked, had: thumbs.had + r.had, made: thumbs.made + r.made,
                    missing: [...thumbs.missing, ...r.missing], failed: [...thumbs.failed, ...r.failed],
                });
                after = r.next_id;
                draw();
            }
        } catch (e) {
            thumbs.error = e.message;
        }
        thumbs.running = false;
        draw();
    }
}
