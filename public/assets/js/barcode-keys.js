// Barcode scanners type like a US keyboard. With the Thai layout switched on, the same keys come out as
// Thai letters (8859… → คคถต…). Read the physical key (KeyboardEvent.code) instead, so a scan is right
// whatever language the keyboard is in.
//  - Barcode fields ([data-barcode]): every key is typed as on a US keyboard, and Thai text that still
//    gets in (paste, phone keyboards) is converted back.
//  - Other text fields (search boxes that take a name or a barcode): only a scanner's burst of keys is
//    converted, so people can still type Thai names.

const CODE_CHARS = {
    Backquote: '`~', Digit1: '1!', Digit2: '2@', Digit3: '3#', Digit4: '4$', Digit5: '5%', Digit6: '6^',
    Digit7: '7&', Digit8: '8*', Digit9: '9(', Digit0: '0)', Minus: '-_', Equal: '=+',
    KeyQ: 'qQ', KeyW: 'wW', KeyE: 'eE', KeyR: 'rR', KeyT: 'tT', KeyY: 'yY', KeyU: 'uU', KeyI: 'iI', KeyO: 'oO',
    KeyP: 'pP', BracketLeft: '[{', BracketRight: ']}', Backslash: '\\|',
    KeyA: 'aA', KeyS: 'sS', KeyD: 'dD', KeyF: 'fF', KeyG: 'gG', KeyH: 'hH', KeyJ: 'jJ', KeyK: 'kK', KeyL: 'lL',
    Semicolon: ';:', Quote: '\'"',
    KeyZ: 'zZ', KeyX: 'xX', KeyC: 'cC', KeyV: 'vV', KeyB: 'bB', KeyN: 'nN', KeyM: 'mM',
    Comma: ',<', Period: '.>', Slash: '/?',
};

// Thai Kedmanee layout, key for key with the US layout (for text that arrives without key codes).
const US = '`1234567890-=qwertyuiop[]\\asdfghjkl;\'zxcvbnm,./' + '~!@#$%^&*()_+QWERTYUIOP{}|ASDFGHJKL:"ZXCVBNM<>?';
const TH = '_ๅ/-ภถุึคตจขชๆไำพะัีรนยบลฃฟหกดเ้่าสวงผปแอิืทมใฝ' + '%+๑๒๓๔ู฿๕๖๗๘๙๐"ฎฑธํ๊ณฯญฐ,ฅฤฆฏโฌ็๋ษศซ.()ฉฮฺ์?ฒฬฦ';
const TH_TO_US = new Map([...TH].map((ch, i) => [ch, US[i]]));
const THAI = /[฀-๿]/;

const BURST_GAP_MS = 50;   // scanners send keys a few ms apart; people type far slower
const BURST_MIN = 4;
const bursts = new WeakMap();

/** Text typed with the Thai layout, as it would read typed on the same keys with the US layout. */
export function thaiKeysToLatin(text) {
    if (!THAI.test(text)) return text;
    return [...text].map((ch) => TH_TO_US.get(ch) ?? ch).join('');
}

function latinFor(e) {
    const pair = CODE_CHARS[e.code];
    if (!pair) return null;
    // Caps Lock flips letters only, as on a real keyboard.
    const caps = e.getModifierState?.('CapsLock') && /^Key/.test(e.code);
    return pair[e.shiftKey !== caps ? 1 : 0];
}

function textField(el) {
    return el instanceof HTMLInputElement && ['text', 'search', 'tel', ''].includes(el.getAttribute('type') || '')
        && !el.readOnly && !el.disabled;
}

function emitInput(el) {
    el.dispatchEvent(new Event('input', { bubbles: true }));
}

function replaceSelection(el, text) {
    try {
        el.setRangeText(text, el.selectionStart, el.selectionEnd, 'end');
    } catch (err) {
        el.value += text;
    }
}

// A scanner burst typed in Thai into an ordinary field: swap its characters for the barcode.
function fixBurst(el) {
    const b = bursts.get(el);
    if (!b) return;
    clearTimeout(b.timer);
    bursts.delete(el);
    if (b.keys.length < BURST_MIN || !THAI.test(b.keys) || !el.value.endsWith(b.keys)) return;
    el.value = el.value.slice(0, -b.keys.length) + b.latin;
    emitInput(el);
}

function onKeydown(e) {
    const el = e.target;
    if (!textField(el) || e.isComposing || e.ctrlKey || e.metaKey || e.altKey) return;

    if (e.key === 'Enter') {
        // Scanners end with Enter: put the barcode right before the page reads the field.
        const b = bursts.get(el);
        if (b && e.timeStamp - b.last < BURST_GAP_MS * 3) fixBurst(el);
        return;
    }
    if (e.key.length !== 1) return;
    const latin = latinFor(e);
    if (latin == null) return;

    if (el.matches('[data-barcode]')) {
        if (latin === e.key) return;
        e.preventDefault();
        replaceSelection(el, latin);
        emitInput(el);
        return;
    }

    let b = bursts.get(el);
    if (!b || e.timeStamp - b.last > BURST_GAP_MS) {
        if (b) clearTimeout(b.timer);
        b = { keys: '', latin: '' };
        bursts.set(el, b);
    }
    b.keys += e.key;
    b.latin += latin;
    b.last = e.timeStamp;
    clearTimeout(b.timer);
    // Scanners set to send no Enter: fix the burst once the keys stop.
    b.timer = setTimeout(() => fixBurst(el), BURST_GAP_MS * 3);
}

function onInput(e) {
    const el = e.target;
    if (!textField(el) || !el.matches('[data-barcode]') || !THAI.test(el.value)) return;
    const pos = el.selectionStart;
    el.value = thaiKeysToLatin(el.value);
    try { el.setSelectionRange(pos, pos); } catch (err) { /* not focused */ }
}

export function initBarcodeKeys() {
    document.addEventListener('keydown', onKeydown, true);
    document.addEventListener('input', onInput, true);
}
