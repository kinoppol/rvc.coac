import { escapeHtml } from './util.js';

// In-app replacements for window.alert / confirm / prompt: styled like the
// other modals, usable by touch, and they return Promises instead of blocking.

let openCount = 0;

/** True while one of these dialogs is on screen (views that grab focus should wait). */
export function isDialogOpen() {
    return openCount > 0;
}

const TONE_ICON = { info: 'i', warn: '?', danger: '!', error: '!' };

function open({ title, message, okText, cancelText, tone, input }) {
    return new Promise((resolve) => {
        const prev = document.activeElement;
        const el = document.createElement('div');
        el.className = 'modal-backdrop app-dialog';
        const field = input
            ? (input.multiline
                ? `<textarea class="app-dialog-input" data-input rows="3" placeholder="${escapeHtml(input.placeholder || '')}">${escapeHtml(input.value || '')}</textarea>`
                : `<input class="app-dialog-input" data-input value="${escapeHtml(input.value || '')}" placeholder="${escapeHtml(input.placeholder || '')}" autocomplete="off">`)
              + '<div class="app-dialog-error" data-error hidden></div>'
            : '';
        el.innerHTML = `
          <div class="modal app-dialog-box" role="${cancelText ? 'alertdialog' : 'dialog'}" aria-modal="true" aria-labelledby="app-dialog-title">
            <div class="modal-body app-dialog-body">
              <div class="app-dialog-icon ${tone}" aria-hidden="true">${TONE_ICON[tone] || 'i'}</div>
              <div class="app-dialog-text">
                <h2 id="app-dialog-title">${escapeHtml(title)}</h2>
                ${message ? `<p>${escapeHtml(message)}</p>` : ''}
                ${field}
              </div>
            </div>
            <div class="modal-foot app-dialog-foot">
              ${cancelText ? `<button type="button" class="btn secondary" data-cancel>${escapeHtml(cancelText)}</button>` : ''}
              <button type="button" class="btn ${tone === 'danger' ? 'danger' : ''}" data-ok>${escapeHtml(okText)}</button>
            </div>
          </div>`;
        // A view in element full screen (e.g. counting on a phone) only shows its own subtree.
        (document.fullscreenElement || document.body).appendChild(el);
        openCount++;

        const inputEl = el.querySelector('[data-input]');
        const okBtn = el.querySelector('[data-ok]');

        function finish(value) {
            el.remove();
            openCount--;
            document.removeEventListener('keydown', onKey, true);
            if (prev && prev.isConnected) prev.focus?.({ preventScroll: true });
            resolve(value);
        }
        function ok() {
            if (!inputEl) return finish(true);
            if (input.required && !inputEl.value.trim()) {
                const err = el.querySelector('[data-error]');
                err.textContent = input.required === true ? 'กรุณากรอกข้อมูล' : input.required;
                err.hidden = false;
                inputEl.focus();
                return;
            }
            finish(inputEl.value);
        }
        const cancel = () => finish(input ? null : cancelText ? false : true);

        // Captured before any view handler, so keys typed here never reach the page underneath.
        function onKey(e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                cancel();
            } else if (e.key === 'Enter' && !(e.target.tagName === 'TEXTAREA' && !e.ctrlKey && !e.metaKey)) {
                e.preventDefault();
                e.stopPropagation();
                if (e.target.matches?.('[data-cancel]')) cancel();
                else ok();
            } else if (e.key === 'Tab') {
                const items = [...el.querySelectorAll('button, input, textarea')];
                const i = items.indexOf(document.activeElement);
                const next = items[(i + (e.shiftKey ? -1 : 1) + items.length) % items.length];
                e.preventDefault();
                next?.focus();
            } else if (!el.contains(e.target)) {
                e.stopPropagation();
            }
        }
        document.addEventListener('keydown', onKey, true);
        el.addEventListener('click', (e) => {
            if (e.target === el) cancel();
            else if (e.target.closest('[data-ok]')) ok();
            else if (e.target.closest('[data-cancel]')) cancel();
        });
        el.addEventListener('input', () => { const err = el.querySelector('[data-error]'); if (err) err.hidden = true; });

        if (inputEl) {
            inputEl.focus();
            inputEl.select?.();
        } else {
            okBtn.focus();
        }
    });
}

/** Shows a message; resolves when it is closed. */
export function alertDialog(message, { title = 'แจ้งเตือน', okText = 'ตกลง', tone = 'info' } = {}) {
    return open({ title, message, okText, tone }).then(() => undefined);
}

/** Resolves to true when the user confirms. danger: true styles the confirm button red. */
export function confirmDialog(message, { title = 'ยืนยันการทำรายการ', okText = 'ยืนยัน', cancelText = 'ยกเลิก', danger = false } = {}) {
    return open({ title, message, okText, cancelText, tone: danger ? 'danger' : 'warn' });
}

/**
 * Asks for a line of text; resolves to the text, or null when cancelled.
 * required: true (or an error message) keeps the dialog open until something is typed.
 */
export function promptDialog(message, {
    title = 'กรอกข้อมูล', okText = 'ตกลง', cancelText = 'ยกเลิก', value = '', placeholder = '',
    required = false, multiline = false, danger = false,
} = {}) {
    return open({ title, message, okText, cancelText, tone: danger ? 'danger' : 'info', input: { value, placeholder, required, multiline } });
}
