// Keeps tables and lists where the reader left them when a page or dialog redraws itself
// (pages rebuild their HTML after a save, so a scrolled table would jump back to the top and
// the row just changed, or the next one to work on, is hard to find again).
//
// - Scroll boxes (.table-wrap, .modal-body, [data-keep-scroll]): the position is remembered by
//   where the box sits in the page; when a redraw puts a box back at the same place within a
//   short time, it gets its position back.
// - The page itself: when a redraw briefly makes the page shorter (a loading state), the browser
//   pulls the page up; once the content is back, the page returns to where the reader was.
// Moving to another page (hash change) forgets everything. A dialog that is closed forgets its own.

const BOXES = '.table-wrap, .modal-body, [data-keep-scroll]';
const WINDOW_MS = 15000; // a redraw after a save normally lands well within this
const USER_MS = 700; // a scroll this soon after the reader's own input is theirs

const saved = new Map(); // key => { top, left, gone, rows }
// Rows in a box: a redraw of the same list keeps about as many; a different filter or day does not.
const rowsOf = (el) => el.querySelectorAll('tr, li, .row').length;
let lastInput = 0;
let pageY = window.scrollY; // where the reader put the page
let clampedTo = null; // the page position lost when the page got shorter

/** Where a box sits: its path of tag / id / first class, with the position among alike siblings. */
function keyOf(el) {
    const parts = [];
    for (let n = el; n && n !== document.body && n.nodeType === 1; n = n.parentElement) {
        const cls = n.classList[0] || '';
        let i = 0;
        for (let s = n.previousElementSibling; s; s = s.previousElementSibling) {
            if (s.tagName === n.tagName && (s.classList[0] || '') === cls) i++;
        }
        parts.push(`${n.tagName}${n.id ? `#${n.id}` : ''}${cls ? `.${cls}` : ''}:${i}`);
    }
    return `${location.hash}|${parts.reverse().join('>')}`;
}

function remember(el) {
    if (!el.isConnected) return;
    const key = keyOf(el);
    el.__ksKey = key;
    if (el.scrollTop > 0 || el.scrollLeft > 0) saved.set(key, { top: el.scrollTop, left: el.scrollLeft, gone: 0 });
    else saved.delete(key);
}

function restore(el) {
    const s = saved.get(keyOf(el));
    if (!s || !s.gone || Date.now() - s.gone > WINDOW_MS) return;
    const rows = rowsOf(el);
    if (rows && s.rows && Math.abs(rows - s.rows) > Math.max(2, s.rows * 0.1)) return; // another list now
    el.scrollTop = s.top;
    el.scrollLeft = s.left;
    el.__ksKey = keyOf(el);
    if (Math.abs(el.scrollTop - s.top) <= 1) s.gone = 0; // in place again (else retry when more content arrives)
}

function boxesIn(node) {
    if (node.nodeType !== 1) return [];
    const list = [...node.querySelectorAll(BOXES)];
    if (node.matches(BOXES)) list.unshift(node);
    return list;
}

export function initKeepScroll() {
    for (const ev of ['wheel', 'touchstart', 'touchmove', 'keydown', 'pointerdown']) {
        window.addEventListener(ev, () => { lastInput = Date.now(); }, { capture: true, passive: true });
    }

    document.addEventListener('scroll', (e) => {
        const t = e.target;
        if (t === document || t === document.documentElement || t === document.body) {
            const y = window.scrollY;
            if (Date.now() - lastInput < USER_MS) {
                pageY = y;
                clampedTo = null;
            } else if (y < pageY && y >= document.documentElement.scrollHeight - window.innerHeight - 1) {
                clampedTo = pageY; // pulled up because the page got shorter, not by the reader
            } else {
                pageY = y;
            }
            return;
        }
        if (t.nodeType === 1 && t.matches(BOXES)) remember(t);
    }, true);

    window.addEventListener('hashchange', () => { saved.clear(); clampedTo = null; pageY = 0; });

    new MutationObserver((records) => {
        const now = Date.now();
        for (const r of records) {
            for (const n of r.removedNodes) {
                if (n.nodeType !== 1) continue;
                // A dialog closed: forget it. A redraw: keep the position for the box that comes back.
                const closing = n.matches('.modal-backdrop') || !!n.querySelector?.('.modal-backdrop');
                for (const b of boxesIn(n)) {
                    if (!b.__ksKey || !saved.has(b.__ksKey)) continue;
                    if (closing && (b.closest('.modal-backdrop') || n.matches('.modal-backdrop'))) saved.delete(b.__ksKey);
                    else Object.assign(saved.get(b.__ksKey), { gone: now, rows: rowsOf(b) });
                }
            }
        }
        for (const r of records) {
            for (const n of r.addedNodes) boxesIn(n).forEach(restore);
            // Rows arriving into a box that is still waiting for its position.
            if (r.target.nodeType === 1) {
                const box = r.target.closest?.(BOXES);
                if (box) restore(box);
            }
        }
        if (clampedTo !== null && document.documentElement.scrollHeight - window.innerHeight >= clampedTo - 1) {
            const y = clampedTo;
            clampedTo = null;
            window.scrollTo(window.scrollX, y);
            pageY = y;
        }
    }).observe(document.body, { childList: true, subtree: true });
}
