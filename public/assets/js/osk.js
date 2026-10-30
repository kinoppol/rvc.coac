// On-screen keyboard drawn by the page, for tablets where the system keyboard is kept
// closed (so a barcode scanner can type without it popping up and covering the screen).
// Thai (Kedmanee) and English layouts, plus a small number pad for number fields; the
// input keeps focus, so a scanner still works while it is open. Height is published as
// --osk-h so pages can make room for it.
//
// Device setting (remembered per browser, any user can change it): "use the page's
// keyboard" — every text and number field then opens this keyboard and the device's
// own keyboard stays closed. Fields marked data-osk="manual" (the POS search box)
// open it themselves; data-osk="off" never opens it.

const LANG_KEY = 'osk.lang';
const ENABLED_KEY = 'osk.enabled';
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
const TEXT_TYPES = new Set(['', 'text', 'search', 'email', 'url', 'tel', 'password', 'number']);

let box = null;
let target = null;
let opts = {};
let startValue = '';
let shift = false;
let repeat = null;
let lang = (() => { try { return localStorage.getItem(LANG_KEY) === 'en' ? 'en' : 'th'; } catch (e) { return 'th'; } })();
let enabled = (() => { try { return localStorage.getItem(ENABLED_KEY) === '1'; } catch (e) { return false; } })();

export const isKeyboardOpen = () => !!box && !box.hidden;
export const keyboardEnabled = () => enabled;

/** Opens the keyboard typing into `input`. opts.numeric shows the number pad; opts.onClose runs when it closes. */
export function openKeyboard(input, options = {}) {
    if (target && target !== input) changed();
    if (target !== input) startValue = input.value;
    target = input;
    opts = options;
    if (!box) build();
    draw();
    box.hidden = false;
    document.body.classList.add('osk-open');
    sizeChanged();
    requestAnimationFrame(reveal);
}

export function closeKeyboard() {
    if (!isKeyboardOpen()) return;
    box.hidden = true;
    stopRepeat();
    document.body.classList.remove('osk-open');
    changed();
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

/** Turns the page keyboard on for every field on this device (and the device's own keyboard off), or back. */
export function setKeyboardEnabled(on) {
    enabled = !!on;
    try { localStorage.setItem(ENABLED_KEY, enabled ? '1' : '0'); } catch (e) { /* private mode */ }
    if (enabled) {
        const a = document.activeElement;
        if (eligible(a)) { a.blur(); }
    } else {
        if (opts.global) closeKeyboard();
        document.querySelectorAll('[data-osk-im]').forEach(restore);
    }
}

/** Installs the device-wide handlers once, at start-up. */
export function initKeyboard() {
    // Before the tap focuses the field: tell the browser not to raise its own keyboard.
    document.addEventListener('pointerdown', (e) => {
        if (!enabled) return;
        const f = e.target.closest?.('input, textarea');
        if (!eligible(f)) return;
        suppress(f);
        // Tapping the field again after hiding the keyboard brings it back.
        if (document.activeElement === f && !isKeyboardOpen()) openFor(f);
    }, true);
    // A tap opens it even where the focus event does not come (the field was already focused).
    document.addEventListener('click', (e) => {
        if (!enabled) return;
        const f = e.target.closest?.('input, textarea');
        if (eligible(f) && (f !== target || !isKeyboardOpen())) openFor(f);
    });
    document.addEventListener('focusin', (e) => {
        const f = e.target;
        if (enabled && eligible(f)) {
            suppress(f);
            if (f !== target || !isKeyboardOpen()) openFor(f);
            return;
        }
        // Typing into another field (e.g. a quantity box) hands over to that field's own keyboard.
        if (isKeyboardOpen() && f !== target && f.matches?.('input, textarea, select')) closeKeyboard();
    });
    document.addEventListener('focusout', () => {
        if (!opts.global) return;
        setTimeout(() => {
            const a = document.activeElement;
            if (opts.global && isKeyboardOpen() && !eligible(a)) closeKeyboard();
        }, 0);
    });
}

function eligible(el) {
    if (!el || !el.matches || el.disabled || el.readOnly) return false;
    if (el.dataset.osk === 'manual' || el.dataset.osk === 'off') return false;
    // Phone stock counting (.mc) covers the page and brings its own number pad.
    if (el.closest('.mc')) return false;
    if (el.matches('textarea')) return true;
    if (!el.matches('input')) return false;
    // A number field swapped to text (see suppress) still counts.
    const type = (el.dataset.oskType || el.getAttribute('type') || '').toLowerCase();
    return TEXT_TYPES.has(type);
}

function isNumeric(el) {
    const im = el.dataset.oskIm !== undefined ? el.dataset.oskIm : el.getAttribute('inputmode');
    return el.dataset.oskType === 'number' || el.type === 'number' || im === 'numeric' || im === 'decimal';
}

function openFor(f) {
    openKeyboard(f, { global: true, numeric: isNumeric(f) });
}

// Keeps the device keyboard down for this field. A number field becomes a text field while
// the page keyboard types into it: browsers wipe "12." from a number field mid-typing.
function suppress(f) {
    if (f.dataset.oskIm === undefined) f.dataset.oskIm = f.getAttribute('inputmode') ?? '';
    f.setAttribute('inputmode', 'none');
    if (f.type === 'number') {
        f.dataset.oskType = 'number';
        f.type = 'text';
    }
}

function restore(f) {
    if (f.dataset.oskIm) f.setAttribute('inputmode', f.dataset.oskIm);
    else f.removeAttribute('inputmode');
    delete f.dataset.oskIm;
    if (f.dataset.oskType) {
        f.type = f.dataset.oskType;
        delete f.dataset.oskType;
    }
}

function sizeChanged() {
    document.documentElement.style.setProperty('--osk-h', isKeyboardOpen() ? `${box.offsetHeight}px` : '0px');
    window.dispatchEvent(new Event('resize'));
}

// The field being typed into stays in sight above the keyboard.
function reveal() {
    if (!target || !isKeyboardOpen()) return;
    const r = target.getBoundingClientRect();
    const bottom = window.innerHeight - box.offsetHeight - 8;
    if (r.bottom > bottom || r.top < 0) target.scrollIntoView({ block: 'center', behavior: 'smooth' });
}

// A typed value is only "changed" (for forms that recalculate on change) once the keyboard leaves the field.
function changed() {
    if (target && target.isConnected && target.value !== startValue) {
        target.dispatchEvent(new Event('change', { bubbles: true }));
    }
    startValue = target ? target.value : '';
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
const fn = (k, label, cls = '', aria = '') =>
    `<button type="button" tabindex="-1" data-k="${k}" class="osk-wide ${cls}"${aria ? ` aria-label="${aria}"` : ''}>${label}</button>`;

function draw() {
    box.classList.toggle('osk-num', !!opts.numeric);
    if (opts.numeric) {
        box.innerHTML = `
          <div class="osk-pad">
            ${['7', '8', '9'].map(key).join('')}${fn('bs', '⌫', '', 'ลบ')}
            ${['4', '5', '6'].map(key).join('')}${fn('clear', 'ล้าง')}
            ${['1', '2', '3'].map(key).join('')}${fn('close', 'ซ่อน ▾', '', 'ปิดแป้นพิมพ์')}
            ${key('0').replace('<button', '<button style="grid-column: span 2"')}${key('.')}${fn('enter', 'ตกลง', 'osk-enter')}
          </div>`;
        return;
    }
    const layout = LAYOUTS[lang];
    const rows = layout.rows.map(([lo, hi]) => [...(shift ? hi : lo)]);
    const last = rows.length - 1;
    const shiftKey = fn('shift', '⇧', shift ? 'on' : '', 'Shift');
    box.innerHTML = `
      <div class="osk-row">${DIGITS.map(key).join('')}${fn('bs', '⌫', '', 'ลบ')}</div>
      ${rows.map((r, i) => `<div class="osk-row">
        ${i === last ? shiftKey : ''}${r.map(key).join('')}${i === last ? shiftKey : ''}
      </div>`).join('')}
      <div class="osk-row">
        ${fn('lang', lang === 'th' ? LAYOUTS.en.label : LAYOUTS.th.label)}
        ${fn('clear', 'ล้าง')}
        <button type="button" tabindex="-1" data-k="ch" data-ch=" " class="osk-space" aria-label="เว้นวรรค">เว้นวรรค</button>
        ${fn('enter', 'ตกลง ↵', 'osk-enter')}
        ${fn('close', 'ซ่อน ▾', '', 'ปิดแป้นพิมพ์')}
      </div>`;
}

function emitInput() {
    target.dispatchEvent(new Event('input', { bubbles: true }));
}

// email fields have no cursor position to read; type at the end there.
function caret(el) {
    try { return el.selectionStart == null ? null : [el.selectionStart, el.selectionEnd]; } catch (e) { return null; }
}

function insert(ch) {
    const c = caret(target);
    if (c) target.setRangeText(ch, c[0], c[1], 'end');
    else target.value += ch;
}

function backspace() {
    const c = caret(target);
    if (!c) {
        if (!target.value) return false;
        target.value = target.value.slice(0, -1);
    } else if (c[0] !== c[1]) target.setRangeText('', c[0], c[1], 'end');
    else if (c[0] > 0) target.setRangeText('', c[0] - 1, c[0], 'end');
    else return false;
    return true;
}

// "ตกลง" on an ordinary form field goes on to the next field, or puts the keyboard away after the last.
function nextField() {
    const scope = target.closest('form, .modal, .card') || document.getElementById('view') || document.body;
    const fields = [...scope.querySelectorAll('input, textarea')].filter((f) => eligible(f) && f.offsetParent !== null);
    const next = fields[fields.indexOf(target) + 1];
    if (next) { next.focus(); return; }
    const t = target;
    closeKeyboard();
    t.blur();
}

function press(k, ch) {
    if (!target || !target.isConnected) { closeKeyboard(); return; }
    if (document.activeElement !== target) target.focus({ preventScroll: true });
    if (k === 'ch') {
        insert(ch);
        emitInput();
        if (shift) { shift = false; draw(); }
    } else if (k === 'bs') {
        if (backspace()) emitInput();
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
        if (target.matches('textarea')) { insert('\n'); emitInput(); return; }
        const handled = !target.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', code: 'Enter', bubbles: true, cancelable: true }));
        if (!handled && opts.global) nextField();
    } else if (k === 'close') {
        closeKeyboard();
    }
}
