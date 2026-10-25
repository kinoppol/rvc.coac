// Sound effects for the POS and the phone counting mode: one volume setting (kept in this
// browser) and a different sound for each kind of event, so staff can tell them apart
// without looking (a product scanned, a whole pack, a mistake, a photo uploaded, a sale done).

const KEY = 'sound.level';
const MAX = 5;
const DEFAULT = 3;
const GAIN = [0, 0.02, 0.05, 0.09, 0.15, 0.25]; // per level; 0 = muted

// Notes: [frequency Hz, length s, wave, start offset s]
const SOUNDS = {
    scan: { label: 'สแกน/เพิ่มสินค้า', notes: [[1250, 0.08, 'square', 0]] },
    pack: { label: 'เพิ่มยกแพ็ค', notes: [[1250, 0.06, 'square', 0], [1660, 0.08, 'square', 0.08]] },
    saved: { label: 'บันทึกจำนวนนับ', notes: [[880, 0.08, 'sine', 0], [1320, 0.12, 'sine', 0.09]] },
    upload: { label: 'อัปโหลดรูปเสร็จ', notes: [[660, 0.07, 'sine', 0], [880, 0.07, 'sine', 0.08], [1175, 0.14, 'sine', 0.16]] },
    pay: { label: 'ขายสำเร็จ', notes: [[1047, 0.1, 'sine', 0], [1319, 0.1, 'sine', 0.11], [1568, 0.22, 'sine', 0.22]] },
    remove: { label: 'เอาออก/พักตะกร้า', notes: [[620, 0.06, 'triangle', 0], [420, 0.09, 'triangle', 0.07]] },
    warn: { label: 'เตือน (เช่น สต๊อกติดลบ)', notes: [[880, 0.1, 'triangle', 0], [660, 0.14, 'triangle', 0.12]] },
    error: { label: 'ผิดพลาด / ไม่พบสินค้า', notes: [[330, 0.12, 'sawtooth', 0], [250, 0.2, 'sawtooth', 0.13]] },
};

let ctx = null;

export function getLevel() {
    try {
        const raw = localStorage.getItem(KEY);
        const n = Number(raw);
        return raw !== null && Number.isInteger(n) && n >= 0 && n <= MAX ? n : DEFAULT;
    } catch (e) {
        return DEFAULT;
    }
}

export function setLevel(n) {
    const level = Math.max(0, Math.min(MAX, Math.round(n)));
    try { localStorage.setItem(KEY, String(level)); } catch (e) { /* private mode */ }
    return level;
}

/** Plays the sound for an event ('scan', 'pack', 'saved', 'upload', 'pay', 'remove', 'warn', 'error'). */
export function play(name) {
    const level = getLevel();
    const sound = SOUNDS[name] || SOUNDS.scan;
    if (!level) return;
    try {
        ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
        if (ctx.state === 'suspended') ctx.resume();
        const t0 = ctx.currentTime + 0.01;
        for (const [freq, len, type, at] of sound.notes) {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = type;
            osc.frequency.value = freq;
            // Short attack and fade, so notes do not click.
            const t = t0 + at;
            gain.gain.setValueAtTime(0.0001, t);
            gain.gain.exponentialRampToValueAtTime(GAIN[level], t + 0.008);
            gain.gain.exponentialRampToValueAtTime(0.0001, t + len);
            osc.connect(gain).connect(ctx.destination);
            osc.start(t);
            osc.stop(t + len + 0.02);
        }
    } catch (e) { /* no audio */ }
}

const icon = (level) => (level === 0 ? '🔇' : level <= 2 ? '🔉' : '🔊');

/** Button label for the current level, e.g. "🔊 3". */
export function soundButtonLabel() {
    const level = getLevel();
    return `${icon(level)}<span class="snd-btn-lv">${level || 'ปิด'}</span>`;
}

/**
 * Small panel under a button: volume − / + (0 = off … 5) and a try-out button per sound.
 * onChange runs after the level changes (to refresh the button).
 */
export function openSoundPanel(anchor, onChange) {
    document.querySelector('.snd-panel')?.remove();
    const panel = document.createElement('div');
    panel.className = 'snd-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'ระดับเสียง');
    const draw = () => {
        const level = getLevel();
        panel.innerHTML = `
          <div class="snd-row">
            <button type="button" class="snd-step" data-snd="-1" aria-label="ลดเสียง" ${level === 0 ? 'disabled' : ''}>−</button>
            <div class="snd-level" aria-live="polite">${icon(level)} <b>${level ? `ระดับ ${level}/${MAX}` : 'ปิดเสียง'}</b>
              <div class="snd-bars">${Array.from({ length: MAX }, (_, i) => `<span class="${i < level ? 'on' : ''}"></span>`).join('')}</div></div>
            <button type="button" class="snd-step" data-snd="1" aria-label="เพิ่มเสียง" ${level === MAX ? 'disabled' : ''}>+</button>
          </div>
          <div class="snd-list">${Object.entries(SOUNDS).map(([k, s]) => `<button type="button" data-snd-try="${k}" title="ฟังเสียงนี้">▶ ${s.label}</button>`).join('')}</div>`;
    };
    draw();
    document.body.appendChild(panel);
    const r = anchor.getBoundingClientRect();
    panel.style.top = `${Math.round(r.bottom + 6)}px`;
    panel.style.left = `${Math.round(Math.max(8, Math.min(window.innerWidth - panel.offsetWidth - 8, r.right - panel.offsetWidth)))}px`;
    const close = (e) => {
        if (e && (panel.contains(e.target) || anchor.contains(e.target))) return;
        panel.remove();
        document.removeEventListener('pointerdown', close, true);
        document.removeEventListener('keydown', onKey, true);
    };
    const onKey = (e) => { if (e.key === 'Escape') close(); };
    panel.addEventListener('click', (e) => {
        const step = e.target.closest('[data-snd]');
        const tryIt = e.target.closest('[data-snd-try]');
        if (step) {
            setLevel(getLevel() + Number(step.dataset.snd));
            draw();
            play('scan');
            onChange?.();
        } else if (tryIt) {
            play(tryIt.dataset.sndTry);
        }
    });
    setTimeout(() => {
        document.addEventListener('pointerdown', close, true);
        document.addEventListener('keydown', onKey, true);
    });
    return close;
}
