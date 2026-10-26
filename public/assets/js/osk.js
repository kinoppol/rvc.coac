// On-screen keyboard drawn by the page, for tablets where the system keyboard is kept
// closed (so a barcode scanner can type without it popping up and covering the screen).
// Thai (Kedmanee) and English layouts; the input keeps focus, so a scanner still works
// while it is open. Height is published as --osk-h so pages can make room for it.

const LANG_KEY = 'osk.lang';
const DIGITS = '1234567890'.split('');
const LAYOUTS = {
    th: {
        label: 'ไทย',
        rows: [
            ['ๅ/-ภถุึคตจขช', '+๑๒๓๔ู฿๕๖๗๘๙'],
            ['ๆไำพะัีรนยบล', '๐"ฎฑธํ๊ณฯญฐ,'],
            ['ฟหกดเ้่าสวง', 'ฤฆฏโฌ็๋ษศซ.'],
            ['ผปแอิืทมใฝ', '()ฉฮฺ์?ฒฬฦ'],
        ],
    },
    en: {
        label: 'ABC',
        rows: [
            ['qwertyuiop', 'QWERTYUIOP'],
            ['asdfghjkl', 'ASDFGHJKL'],
            ['zxcvbnm-.', 'ZXCVBNM_,'],
        ],
    },
};

let box = null;
let target = null;
let opts = {};
let shift = false;
let repeat = null;
let lang = (() => { try { return localStorage.getItem(LANG_KEY) === 'en' ? 'en' : 'th'; } catch (e) { return 'th'; } })();

export const isKeyboardOpen = () => !!box && !box.hidden;

/** Opens the keyboard typing into `input`. opts.onClose runs when it closes. */
export function openKeyboard(input, options = {}) {
    target = input;
    opts = options;
    if (!box) build();
    draw();
    box.hidden = false;
    document.body.classList.add('osk-open');
    sizeChanged();
}

export function closeKeyboard() {
    if (!isKeyboardOpen()) return;
    box.hidden = true;
    stopRepeat();
    document.body.classList.remove('osk-open');
    sizeChanged();
    const done = opts.onClose;
    target = null;
    opts = {};
    done?.();
}

export function toggleKeyboard(input, options) {
    if (isKeyboardOpen()) closeKeyboard();
    else openKeyboard(input, options);
}

function sizeChanged() {
    document.documentElement.style.setProperty('--osk-h', isKeyboardOpen() ? `${box.offsetHeight}px` : '0px');
    window.dispatchEvent(new Event('resize'));
}

function build() {
    box = document.createElement('div');
    box.className = 'osk';
    box.hidden = true;
    box.setAttribute('role', 'group');
    box.setAttribute('aria-label', 'แป้นพิมพ์บนจอ');
    // Keys act on pointerdown and cancel it, so the input never loses focus.
    box.addEventListener('pointerdown', (e) => {
        e.preventDefault();
        const k = e.target.closest('[data-k]');
        if (!k) return;
        k.classList.add('down');
        press(k.dataset.k, k.dataset.ch);
        if (k.dataset.k === 'bs') repeat = setTimeout(function again() { press('bs'); repeat = setTimeout(again, 60); }, 450);
    });
    const up = () => { stopRepeat(); box.querySelectorAll('.down').forEach((k) => k.classList.remove('down')); };
    box.addEventListener('pointerup', up);
    box.addEventListener('pointerleave', up);
    box.addEventListener('pointercancel', up);
    box.addEventListener('contextmenu', (e) => e.preventDefault());
    // Typing into another field (e.g. a quantity box) hands over to that field's own keyboard.
    document.addEventListener('focusin', (e) => {
        if (isKeyboardOpen() && e.target !== target && e.target.matches?.('input, textarea, select')) closeKeyboard();
    });
    document.body.appendChild(box);
}

function stopRepeat() {
    clearTimeout(repeat);
    repeat = null;
}

// Thai vowel and tone marks sit on a dotted circle so they read clearly on the key.
const key = (ch) => {
    const v = ch === '"' ? '&quot;' : ch;
    return `<button type="button" tabindex="-1" data-k="ch" data-ch="${v}">${/\p{M}/u.test(ch) ? `◌${v}` : v}</button>`;
};

function draw() {
    const layout = LAYOUTS[lang];
    const rows = layout.rows.map(([lo, hi]) => [...(shift ? hi : lo)]);
    const last = rows.length - 1;
    box.innerHTML = `
      <div class="osk-row">${DIGITS.map(key).join('')}<button type="button" tabindex="-1" data-k="bs" class="osk-wide" aria-label="ลบ">⌫</button></div>
      ${rows.map((r, i) => `<div class="osk-row">
        ${i === last ? `<button type="button" tabindex="-1" data-k="shift" class="osk-wide${shift ? ' on' : ''}" aria-label="Shift">⇧</button>` : ''}
        ${r.map(key).join('')}
        ${i === last ? `<button type="button" tabindex="-1" data-k="shift" class="osk-wide${shift ? ' on' : ''}" aria-label="Shift">⇧</button>` : ''}
      </div>`).join('')}
      <div class="osk-row">
        <button type="button" tabindex="-1" data-k="lang" class="osk-wide">${lang === 'th' ? LAYOUTS.en.label : LAYOUTS.th.label}</button>
        <button type="button" tabindex="-1" data-k="clear" class="osk-wide">ล้าง</button>
        <button type="button" tabindex="-1" data-k="ch" data-ch=" " class="osk-space" aria-label="เว้นวรรค">เว้นวรรค</button>
        <button type="button" tabindex="-1" data-k="enter" class="osk-wide osk-enter">ตกลง ↵</button>
        <button type="button" tabindex="-1" data-k="close" class="osk-wide" aria-label="ปิดแป้นพิมพ์">ซ่อน ▾</button>
      </div>`;
}

function emitInput() {
    target.dispatchEvent(new Event('input', { bubbles: true }));
}

function press(k, ch) {
    if (!target || !target.isConnected) { closeKeyboard(); return; }
    if (document.activeElement !== target) target.focus({ preventScroll: true });
    const start = target.selectionStart ?? target.value.length;
    const end = target.selectionEnd ?? target.value.length;
    if (k === 'ch') {
        target.setRangeText(ch, start, end, 'end');
        emitInput();
        if (shift) { shift = false; draw(); }
    } else if (k === 'bs') {
        if (start !== end) target.setRangeText('', start, end, 'end');
        else if (start > 0) target.setRangeText('', start - 1, start, 'end');
        else return;
        emitInput();
    } else if (k === 'clear') {
        if (!target.value) return;
        target.value = '';
        emitInput();
    } else if (k === 'shift') {
        shift = !shift;
        draw();
    } else if (k === 'lang') {
        lang = lang === 'th' ? 'en' : 'th';
        shift = false;
        try { localStorage.setItem(LANG_KEY, lang); } catch (e) { /* private mode */ }
        draw();
        sizeChanged();
    } else if (k === 'enter') {
        target.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', code: 'Enter', bubbles: true, cancelable: true }));
    } else if (k === 'close') {
        closeKeyboard();
    }
}
