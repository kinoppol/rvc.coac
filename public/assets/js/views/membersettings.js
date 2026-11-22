import { api } from '../api.js';
import { money, escapeHtml } from '../util.js';

/** ตั้งค่าแต้มสะสม (admin / manager): earning rate and the welcome points of a first purchase. */
export async function render(container, { navigate }) {
    let s;
    try {
        s = (await api.get('/members/settings')).data;
    } catch (e) {
        container.innerHTML = `<div class="card card-pad"><div class="error-box">${escapeHtml(e.message)}</div></div>`;
        return;
    }
    const ro = !s.can_manage;
    container.innerHTML = `
      <div class="card" style="max-width:720px"><div class="card-pad">
        <h2 style="margin:0 0 4px;font-size:17px">ตั้งค่าแต้มสะสมของสมาชิก</h2>
        <div class="muted" style="font-size:13px;margin-bottom:12px">นักเรียน นักศึกษา และบุคลากรทุกคนในทะเบียน RVC-ONE เป็นสมาชิกโดยไม่ต้องสมัคร
          ระบุสมาชิกที่หน้า POS ด้วยเลขบัตรประชาชน รหัสนักศึกษา หรือเบอร์โทรศัพท์ · ไม่ระบุ = ลูกค้าทั่วไป ไม่สะสมแต้ม</div>
        <label class="pos-switch"><input type="checkbox" data-enabled ${s.enabled ? 'checked' : ''} ${ro ? 'disabled' : ''}><span aria-hidden="true"></span> เปิดการสะสมแต้ม</label>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-top:12px">
          <label class="field"><span>ยอดชำระกี่บาท ได้ 1 แต้ม</span><input type="number" min="1" step="0.01" data-baht value="${s.baht_per_point}" ${ro ? 'disabled' : ''}></label>
          <label class="field"><span>แต้มเริ่มต้นเมื่อซื้อครั้งแรก</span><input type="number" min="0" step="1" data-welcome value="${s.welcome_points}" ${ro ? 'disabled' : ''}></label>
        </div>
        <div class="hint" style="margin-top:12px"><div class="dot">i</div><div data-example></div></div>
        <div class="muted" style="font-size:12.5px;margin-top:8px">· คิดจากยอดที่ลูกค้าชำระจริงทั้งบิล (หลังหักส่วนลดโปรโมชั่นและส่วนลดจากแต้ม) เศษที่ไม่ถึง 1 แต้มตัดทิ้ง
          · สินค้าที่ใช้แต้มลดราคาได้ กำหนดที่เมนู <a href="#/promos?tab=points">โปรโมชั่น / ใช้แต้ม</a> แถบ "ใช้แต้มเป็นส่วนลด"
          · แต้มที่กด "ใช้แต้ม" ในตะกร้าจะถูกจองไว้ทันที และคืนให้เมื่อเอาออก ล้างตะกร้า หรือไม่ได้ชำระภายใน ${s.hold_minutes} นาที</div>
        <div data-msg></div>
        ${ro ? '<div class="muted" style="margin-top:10px">เฉพาะผู้ดูแลระบบหรือผู้จัดการร้านเท่านั้นที่แก้ไขได้</div>' : '<button type="button" class="btn" data-save style="margin-top:12px">บันทึก</button>'}
      </div></div>`;
    const example = () => {
        const baht = Number(container.querySelector('[data-baht]').value) || 0;
        const w = Number(container.querySelector('[data-welcome]').value) || 0;
        container.querySelector('[data-example]').innerHTML = baht > 0
            ? `ตัวอย่าง: ซื้อ ฿${money(baht * 3 + baht * 0.6)} ได้ <b>3 แต้ม</b> (เศษ ฿${money(baht * 0.6)} ตัดทิ้ง)${w ? ` · ซื้อครั้งแรกได้เพิ่ม <b>${w} แต้ม</b>` : ''}`
            : 'ใส่ยอดชำระต่อ 1 แต้ม';
    };
    example();
    container.addEventListener('input', example);
    container.querySelector('[data-save]')?.addEventListener('click', async () => {
        const msg = container.querySelector('[data-msg]');
        try {
            await api.put('/members/settings', {
                enabled: container.querySelector('[data-enabled]').checked,
                baht_per_point: Number(container.querySelector('[data-baht]').value),
                welcome_points: Number(container.querySelector('[data-welcome]').value),
            });
            msg.innerHTML = '<div class="pos-msg ok" style="margin-top:10px">บันทึกแล้ว</div>';
        } catch (e) {
            msg.innerHTML = `<div class="error-box" style="margin-top:10px">${escapeHtml(e.message)}</div>`;
        }
    });
}
