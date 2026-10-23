import { api } from '../api.js';
import { escapeHtml, fmtDateTime } from '../util.js';

/** A short name for the browser/device that asked to sign in, from its User-Agent. */
function deviceName(agent) {
    const a = String(agent || '');
    const os = /Windows/.test(a) ? 'Windows' : /Android/.test(a) ? 'Android' : /iPhone|iPad/.test(a) ? 'iOS' : /Mac OS/.test(a) ? 'macOS' : /Linux/.test(a) ? 'Linux' : '';
    const browser = /Edg\//.test(a) ? 'Edge' : /Chrome\//.test(a) ? 'Chrome' : /Firefox\//.test(a) ? 'Firefox' : /Safari\//.test(a) ? 'Safari' : '';
    return [browser, os].filter(Boolean).join(' บน ') || 'ไม่ทราบ';
}

/**
 * Opened on a signed-in phone by scanning the sign-in QR-Code of another screen (the POS):
 * shows which screen asked, and signs it in as this user once approved.
 */
export async function render(container, { user, params = {}, navigate }) {
    const code = String(params.c || '');
    let info = null;
    let error = null;
    let done = null; // 'approved' | 'declined'
    let busy = false;

    try {
        info = (await api.get(`/auth/qr/info?c=${encodeURIComponent(code)}`)).data;
    } catch (e) {
        error = e.message;
    }

    function draw() {
        container.innerHTML = `
        <section class="card card-pad" style="max-width:460px;margin:0 auto;display:flex;flex-direction:column;gap:14px;text-align:center">
          <div style="font-size:40px">${done === 'approved' ? '✅' : done === 'declined' ? '✋' : error ? '⚠️' : '🔐'}</div>
          ${done === 'approved' ? `<h2 style="margin:0">อนุมัติแล้ว</h2>
              <div class="muted">เครื่องที่แสดง QR-Code จะเข้าสู่ระบบเป็น <b>${escapeHtml(user.full_name)}</b> ภายในไม่กี่วินาที</div>
              <div class="muted" style="font-size:12.5px">อย่าลืมออกจากระบบที่เครื่องนั้นเมื่อใช้งานเสร็จ</div>`
            : done === 'declined' ? `<h2 style="margin:0">ไม่อนุมัติ</h2><div class="muted">เครื่องนั้นจะไม่ได้เข้าสู่ระบบ</div>`
            : error ? `<h2 style="margin:0">ใช้ QR-Code นี้ไม่ได้</h2><div class="error-box">${escapeHtml(error)}</div>`
            : `<h2 style="margin:0">ลงชื่อเข้าใช้เครื่องอื่นด้วยบัญชีของคุณ?</h2>
              <div>เครื่องที่แสดง QR-Code จะเข้าสู่ระบบเป็น <b>${escapeHtml(user.full_name)}</b> และทำรายการในชื่อของคุณ</div>
              <div class="muted" style="font-size:12.5px;text-align:left;background:var(--surface2);border-radius:10px;padding:10px 12px;line-height:1.7">
                เครื่องที่ขอ: <b>${escapeHtml(deviceName(info.requester_agent))}</b><br>
                IP: ${escapeHtml(info.requester_ip || '-')}<br>
                สร้าง QR เมื่อ: ${escapeHtml(fmtDateTime(info.created_at))} · หมดอายุ: ${escapeHtml(fmtDateTime(info.expires_at))}
              </div>
              <div class="muted" style="font-size:12.5px">อนุมัติเฉพาะเมื่อคุณเพิ่งสแกน QR-Code จากหน้าจอที่อยู่ตรงหน้าคุณเอง</div>
              <div class="actions-row" style="justify-content:center">
                <button type="button" class="btn secondary" data-qr="decline" ${busy ? 'disabled' : ''}>ไม่อนุมัติ</button>
                <button type="button" class="btn" data-qr="approve" ${busy ? 'disabled' : ''}>${busy ? 'กำลังอนุมัติ...' : 'อนุมัติให้เข้าสู่ระบบ'}</button>
              </div>`}
          ${done || error ? '<button type="button" class="btn secondary" data-qr="home">กลับหน้าหลัก</button>' : ''}
        </section>`;
        container.querySelector('[data-qr="approve"]')?.addEventListener('click', approve);
        container.querySelector('[data-qr="decline"]')?.addEventListener('click', () => { done = 'declined'; draw(); });
        container.querySelector('[data-qr="home"]')?.addEventListener('click', () => navigate(''));
    }

    async function approve() {
        busy = true;
        draw();
        try {
            await api.post('/auth/qr/approve', { code });
            done = 'approved';
        } catch (e) {
            error = e.message;
        }
        busy = false;
        draw();
    }

    draw();
}
