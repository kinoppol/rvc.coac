// An upload under way, shown so it plainly keeps working: a spinning ring, a bar whose stripes keep
// moving (also between two chunks, while the percentage stands still) and the amount sent.
// Used by the .apk uploads (views/devices.js, views/mobileapp.js).

const STYLE_ID = 'upload-progress-style';

function ensureStyle() {
    if (document.getElementById(STYLE_ID)) return;
    const s = document.createElement('style');
    s.id = STYLE_ID;
    s.textContent = `
      .up-wrap{margin-top:12px}
      .up-row{display:flex;align-items:center;gap:10px;font-size:13px}
      .up-spin{width:18px;height:18px;flex:none;border-radius:50%;border:3px solid color-mix(in srgb,var(--primary,#1d5fd1) 25%,transparent);border-top-color:var(--primary,#1d5fd1);animation:up-rot .8s linear infinite}
      .up-bar{position:relative;height:10px;border-radius:99px;overflow:hidden;background:color-mix(in srgb,var(--primary,#1d5fd1) 14%,transparent);margin-top:8px}
      .up-fill{position:absolute;inset:0 auto 0 0;width:0;border-radius:99px;transition:width .35s ease;
        background:var(--primary,#1d5fd1) repeating-linear-gradient(45deg,rgba(255,255,255,.28) 0 10px,transparent 10px 20px);
        background-size:28px 28px;animation:up-stripes .7s linear infinite}
      .up-bar.up-indet .up-fill{width:35%;animation:up-stripes .7s linear infinite,up-slide 1.1s ease-in-out infinite alternate}
      .up-btn-spin{display:inline-block;width:13px;height:13px;margin-right:6px;vertical-align:-2px;border-radius:50%;border:2px solid rgba(255,255,255,.45);border-top-color:#fff;animation:up-rot .8s linear infinite}
      @keyframes up-rot{to{transform:rotate(360deg)}}
      @keyframes up-stripes{to{background-position:28px 0}}
      @keyframes up-slide{from{left:0}to{left:65%}}
      @media (prefers-reduced-motion:reduce){.up-spin,.up-fill,.up-btn-spin{animation-duration:2.4s}}
    `;
    document.head.appendChild(s);
}

const fmtSize = (n) => (n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`);

/**
 * Takes over [box] (an empty element) and [button] (the upload button) while uploading.
 * Returns { sent(done, total), checking(), stop() }.
 */
export function uploadProgress(box, button) {
    ensureStyle();
    const label = button ? button.textContent : '';
    if (button) button.innerHTML = '<span class="up-btn-spin" aria-hidden="true"></span>กำลังอัปโหลด…';
    box.innerHTML = `<div class="up-wrap" role="status" aria-live="polite">
        <div class="up-row"><span class="up-spin" aria-hidden="true"></span><span data-up-text>กำลังเริ่มอัปโหลด…</span></div>
        <div class="up-bar up-indet"><div class="up-fill"></div></div></div>`;
    const text = box.querySelector('[data-up-text]');
    const bar = box.querySelector('.up-bar');
    const fill = box.querySelector('.up-fill');
    return {
        sent(done, total) {
            const pct = total ? Math.round((done / total) * 100) : 0;
            bar.classList.remove('up-indet');
            fill.style.width = `${Math.max(2, pct)}%`;
            text.textContent = `กำลังอัปโหลด ${pct}% · ${fmtSize(done)} / ${fmtSize(total)}`;
        },
        checking() {
            bar.classList.add('up-indet');
            fill.style.width = '';
            text.textContent = 'อัปโหลดครบแล้ว กำลังตรวจไฟล์…';
        },
        stop() {
            box.innerHTML = '';
            if (button) button.textContent = label;
        },
    };
}
