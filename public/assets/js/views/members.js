import { api } from '../api.js';
import { money, count, escapeHtml, fmtDate, qs } from '../util.js';

/**
 * ทะเบียนสมาชิก: every student and staff member of RVC-ONE is a member (no sign-up). The list shows
 * their points and spending; a row opens the member page (#/member?oa=). Found by name, national ID,
 * student code or any phone number from RMS.
 */
export async function render(container, { navigate, params = {} }) {
    const f = { q: params.q || '', type: params.type || '', only: params.only || '', sort: params.sort || 'recent', page: Number(params.page) || 1 };
    let res = null;
    let timer;

    container.innerHTML = `
      <div data-summary></div>
      <div class="card" style="margin-top:12px">
        <div class="card-pad" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
          <label class="field" style="flex:1;min-width:240px"><span>ค้นหา</span>
            <input type="search" data-f="q" value="${escapeHtml(f.q)}" placeholder="ชื่อ เลขบัตรประชาชน รหัสนักศึกษา หรือเบอร์โทรศัพท์" autocomplete="off"></label>
          <label class="field"><span>ประเภท</span><select data-f="type">
            <option value="">ทั้งหมด</option><option value="student">นักเรียน/นักศึกษา</option><option value="staff">ครูและบุคลากร</option></select></label>
          <label class="field"><span>แสดง</span><select data-f="only">
            <option value="">ทุกคน</option><option value="buyers">เคยซื้อสินค้า</option><option value="points">มีแต้มคงเหลือ</option></select></label>
          <label class="field"><span>เรียงตาม</span><select data-f="sort">
            <option value="recent">ซื้อล่าสุด</option><option value="points">แต้มมากสุด</option><option value="spend">ยอดซื้อมากสุด</option>
            <option value="bills">ซื้อบ่อยสุด</option><option value="name">ชื่อ</option></select></label>
        </div>
        <div data-list></div>
      </div>`;
    container.querySelectorAll('select[data-f]').forEach((s) => { s.value = f[s.dataset.f]; });

    function sync() {
        const p = { ...f, page: f.page > 1 ? f.page : '' };
        history.replaceState(null, '', `#/members${qs(p)}`);
    }

    async function load() {
        const box = container.querySelector('[data-list]');
        box.innerHTML = '<div class="card-pad muted">กำลังโหลด...</div>';
        try {
            res = await api.get(`/members${qs(f)}`);
        } catch (e) {
            box.innerHTML = `<div class="card-pad"><div class="error-box">${escapeHtml(e.message)}</div></div>`;
            return;
        }
        if (!res.ready) {
            container.innerHTML = '<div class="card card-pad"><div class="error-box">ยังใช้ระบบสมาชิกไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0088) ที่เมนู ระบบ ก่อน</div></div>';
            return;
        }
        draw();
    }

    function draw() {
        const s = res.summary;
        const set = res.settings;
        container.querySelector('[data-summary]').innerHTML = `
          <div class="kpi-grid">
            <div class="kpi tone-sales"><div class="lbl">สมาชิกทั้งหมด (จาก RVC-ONE)</div><div class="val">${count(s.people)}</div>
              <div class="sub">นักเรียน/นักศึกษา ${count(s.students)} · บุคลากร ${count(s.people - s.students)}</div></div>
            <div class="kpi tone-income"><div class="lbl">สมาชิกที่เคยซื้อสินค้า</div><div class="val">${count(s.buyers)}</div>
              <div class="sub">ไม่ระบุสมาชิก = ลูกค้าทั่วไป (ไม่สะสมแต้ม)</div></div>
            <div class="kpi tone-owed"><div class="lbl">แต้มคงเหลือรวม</div><div class="val">${count(s.points)}</div>
              <div class="sub">${set.enabled ? `สะสม 1 แต้มทุก ฿${money(set.baht_per_point)} · ซื้อครั้งแรกรับ ${count(set.welcome_points)} แต้ม` : 'ปิดการสะสมแต้มอยู่'}</div></div>
            <div class="kpi tone-stock"><div class="lbl">ข้อมูลอ้างอิงจาก RMS</div><div class="val">${count(s.with_id)}</div>
              <div class="sub">มีเลขบัตรประชาชน · มีเบอร์โทร ${count(s.with_phone)} คน</div></div>
          </div>
          ${s.people && !s.with_id ? `<div class="hint" style="margin-top:12px"><div class="dot">!</div><div>ยังไม่มีเลขบัตรประชาชน/เบอร์โทรจาก RVC-ONE — ให้ผู้ดูแล RVC-ONE อนุญาตข้อมูล
            "เลขประจำตัวประชาชน" "รหัสนักศึกษา" และ "เบอร์โทรศัพท์" ให้แอปนี้ แล้วซิงค์ผู้ใช้แบบทั้งหมด (ตั้งค่า → RVC-ONE) จึงจะค้นหาสมาชิกด้วยข้อมูลเหล่านี้ได้ (ค้นด้วยชื่อได้เสมอ)</div></div>` : ''}`;
        const pages = Math.max(1, Math.ceil(res.total / res.per_page));
        container.querySelector('[data-list]').innerHTML = res.data.length ? `
          <div class="table-wrap"><table>
            <thead><tr><th>สมาชิก</th><th>ประเภท / แผนก</th><th>รหัสนักศึกษา</th><th class="num">แต้มคงเหลือ</th><th class="num">ซื้อ (บิล)</th><th class="num">ยอดซื้อรวม</th><th>ซื้อล่าสุด</th></tr></thead>
            <tbody>${res.data.map((m) => `<tr class="row-link" data-oa="${m.oa_user_id}" style="cursor:pointer">
              <td><b>${escapeHtml(m.name)}</b>${m.national_id_masked ? `<div class="muted" style="font-size:12px">${escapeHtml(m.national_id_masked)}</div>` : ''}</td>
              <td><span class="pill ${m.user_type === 'student' ? 'ok' : 'warn'}">${escapeHtml(m.type_label)}</span><div class="muted" style="font-size:12px">${escapeHtml(m.department || '')}</div></td>
              <td>${escapeHtml(m.student_code || '-')}</td>
              <td class="num"><b>${count(m.points)}</b></td>
              <td class="num">${count(m.bills)}</td>
              <td class="num">฿${money(m.spend)}</td>
              <td>${m.last_purchase_at ? escapeHtml(fmtDate(m.last_purchase_at)) : '<span class="muted">ยังไม่เคยซื้อ</span>'}</td>
            </tr>`).join('')}</tbody></table></div>
          <div class="card-pad" style="display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap">
            <span class="muted" style="font-size:13px">ทั้งหมด ${count(res.total)} คน · หน้า ${res.page}/${pages}</span>
            <span><button type="button" class="btn secondary sm" data-page="${res.page - 1}" ${res.page <= 1 ? 'disabled' : ''}>‹ ก่อนหน้า</button>
              <button type="button" class="btn secondary sm" data-page="${res.page + 1}" ${res.page >= pages ? 'disabled' : ''}>ถัดไป ›</button></span>
          </div>` : '<div class="card-pad empty">ไม่พบสมาชิกตามเงื่อนไข</div>';
    }

    container.addEventListener('input', (e) => {
        if (e.target.dataset.f !== 'q') return;
        clearTimeout(timer);
        timer = setTimeout(() => { f.q = e.target.value.trim(); f.page = 1; sync(); load(); }, 300);
    });
    container.addEventListener('change', (e) => {
        const k = e.target.dataset.f;
        if (!k || k === 'q') return;
        f[k] = e.target.value;
        f.page = 1;
        sync();
        load();
    });
    container.addEventListener('click', (e) => {
        const pg = e.target.closest('[data-page]');
        if (pg) { f.page = Number(pg.dataset.page); sync(); load(); return; }
        const row = e.target.closest('[data-oa]');
        if (row) navigate('member', { oa: row.dataset.oa });
    });

    await load();
}
