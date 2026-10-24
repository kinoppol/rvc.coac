import { api } from './api.js';
import { escapeHtml, money } from './util.js';
import { uploadImage, attachmentUrl, imagesFromClipboard } from './images.js';
import { markdown } from './markdown.js';

const PURCHASE_DRAFT_KEY = 'purchase.draft'; // read by views/purchase.js
const MAX_PHOTOS = 10;
const MAX_DOCS = 5;
const MAX_DOC_BYTES = 512 * 1024;
const DOC_EXT = /\.(md|markdown|txt)$/i;

/**
 * Floating AI assistant. The conversation lives on the client (OpenAI-style
 * messages) and is sent to /api/ai/chat each turn; the server runs the tools
 * the user is allowed to use and returns data-changing calls as
 * pending_actions for the user to approve here.
 */

const TOOL_LABEL = {
    navigate_to: 'เปิดหน้าจอ',
    get_dashboard_summary: 'ดูภาพรวมวันนี้',
    search_consignors: 'ค้นหาผู้ฝากขาย',
    get_consignor_passbook: 'ดูสมุดบัญชีผู้ฝาก',
    search_products: 'ค้นหาสินค้า',
    list_withdrawals: 'ดูรายการเบิกเงิน',
    get_report: 'ดึงรายงาน',
    create_consignor: 'ลงทะเบียนผู้ฝากขาย',
    create_withdrawal: 'เบิกเงิน',
    approve_withdrawal: 'อนุมัติเบิกเงิน',
    create_purchase: 'บันทึกใบซื้อสินค้าเข้าร้าน',
};

const SUGGESTIONS = [
    'สรุปยอดขายวันนี้ให้หน่อย',
    'มีคำขอเบิกเงินรออนุมัติไหม',
    'ผู้ฝากขายรายไหนมียอดคงเหลือมากที่สุด',
    'สินค้าใกล้หมดอายุมีอะไรบ้าง',
];

const ICON_SPARK = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 4.6L18.5 9.5l-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"></path><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"></path></svg>';
const ICON_CLOSE = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"></path></svg>';
const ICON_MIC = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="12" rx="3"></rect><path d="M5 10a7 7 0 0 0 14 0M12 17v4M8 21h8"></path></svg>';
const ICON_SEND = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"></path><path d="M22 2 15 22l-4-9-9-4z"></path></svg>';
const ICON_CLIP = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="m21.4 11.1-9.2 9.2a6 6 0 0 1-8.5-8.5l9.2-9.2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"></path></svg>';
const ICON_TRASH = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"></path></svg>';

let ctx = null; // { user, navigate, el, state }

function storageKey(suffix) {
    return `ai.${ctx.user.id}.${suffix}`;
}
function load(store, key, fallback) {
    try {
        const v = store.getItem(storageKey(key));
        return v ? JSON.parse(v) : fallback;
    } catch (e) { return fallback; }
}
function save(store, key, value) {
    try { store.setItem(storageKey(key), JSON.stringify(value)); } catch (e) { /* ignore */ }
}

export async function mountAssistant({ user, navigate }) {
    unmountAssistant();
    const el = document.createElement('div');
    el.className = 'ai-root';
    document.body.appendChild(el);
    ctx = {
        user, navigate, el,
        state: {
            open: false, models: [], defaultId: null, modelId: null, loading: false, error: null,
            messages: [], pending: [], decisions: {}, listening: false, canManage: false,
            photos: [], documents: [], uploading: false, canPurchase: false, notice: null,
        },
    };
    ctx.state.messages = load(sessionStorage, 'messages', []);
    ctx.state.pending = load(sessionStorage, 'pending', []);
    await refreshAssistantModels();
}

export function unmountAssistant() {
    if (!ctx) return;
    stopListening();
    stopWaiting();
    ctx.el.remove();
    ctx = null;
}

export async function refreshAssistantModels() {
    if (!ctx) return;
    const s = ctx.state;
    try {
        const me = await api.get('/auth/me');
        s.canManage = (me.permissions || []).includes('ai.manage');
        s.canPurchase = (me.permissions || []).includes('purchase.create');
        const res = await api.get('/ai/models');
        s.models = res.data;
        s.defaultId = res.default_id ?? null;
    } catch (e) {
        s.models = [];
        s.defaultId = null;
    }
    // 'auto' (default) lets the server use the admin's default model, else the cheapest that can handle each message.
    const saved = load(localStorage, 'modelChoice', 'auto');
    s.modelId = saved === 'auto' || s.models.some((m) => m.id === saved) ? saved : 'auto';
    render();
}

// ---------------- Rendering ----------------

function messageHtml(m) {
    if (m.role === 'user') {
        const thumbs = (m.attachments || []).map((t, i) => `
          <a href="${escapeHtml(attachmentUrl(t))}" target="_blank" rel="noopener" class="ai-thumb"><img src="${escapeHtml(attachmentUrl(t))}" alt="รูปที่ ${i + 1}"></a>`).join('');
        const docs = (m.documents || []).map((d) => `<span class="ai-doc">📄 ${escapeHtml(d.name)}</span>`).join('');
        return `<div class="ai-msg user">${thumbs ? `<div class="ai-thumbs">${thumbs}</div>` : ''}${docs ? `<div class="ai-thumbs">${docs}</div>` : ''}${m.content ? `<div class="ai-bubble">${escapeHtml(m.content).replace(/\n/g, '<br>')}</div>` : ''}</div>`;
    }
    if (m.role !== 'assistant') return '';
    const chips = (m.tool_calls || []).map((c) => `<span class="ai-chip">🔧 ${escapeHtml(TOOL_LABEL[c.function.name] || c.function.name)}</span>`).join('');
    const body = m.content ? `<div class="ai-bubble">${markdown(m.content)}</div>` : '';
    if (!chips && !body) return '';
    const tag = m.model && body ? `<div class="ai-model-tag">ตอบโดย ${escapeHtml(m.model)}</div>` : '';
    return `<div class="ai-msg bot">${chips ? `<div class="ai-chips">${chips}</div>` : ''}${body}${tag}</div>`;
}

function pendingHtml() {
    const s = ctx.state;
    if (!s.pending.length) return '';
    return `<div class="ai-pending">
      <div class="ai-pending-title">ผู้ช่วยขออนุญาตดำเนินการ</div>
      ${s.pending.map((p) => {
          const d = s.decisions[p.id];
          return `<div class="ai-action">
            <div class="ai-action-name">${escapeHtml(TOOL_LABEL[p.name] || p.name)}</div>
            <div class="ai-action-sum">${escapeHtml(p.summary)}</div>
            ${detailsHtml(p.details)}
            <div class="ai-action-btns">
              ${d === undefined ? `
                <button type="button" class="btn sm" data-approve="${escapeHtml(p.id)}" ${s.loading ? 'disabled' : ''}>ยืนยัน</button>
                ${p.editable ? `<button type="button" class="btn secondary sm" data-edit-form="${escapeHtml(p.id)}" ${s.loading ? 'disabled' : ''}>แก้ไขในฟอร์ม</button>` : ''}
                <button type="button" class="btn secondary sm" data-reject="${escapeHtml(p.id)}" ${s.loading ? 'disabled' : ''}>ยกเลิก</button>`
              : `<span class="pill ${d === true ? 'ok' : 'muted'}">${d === true ? 'ยืนยันแล้ว' : d === 'edit' ? 'เปิดในฟอร์มแล้ว' : 'ยกเลิกแล้ว'}</span>`}
            </div>
          </div>`;
      }).join('')}
    </div>`;
}

function detailsHtml(det) {
    if (!det || det.type !== 'purchase') return '';
    const diff = det.total > 0 ? Math.round((det.subtotal - det.discount - det.total) * 100) / 100 : 0;
    return `
      <div class="ai-table ai-detail"><table>
        <thead><tr><th>สินค้า</th><th>จำนวน</th><th>ทุน/ชิ้น</th><th>ขาย/ชิ้น</th></tr></thead>
        <tbody>${det.rows.map((r) => `<tr>
          <td>${escapeHtml(r.name)}<div class="${r.item_type === 'supply' ? 'ai-supply' : r.status === 'สินค้าใหม่' ? 'ai-new' : 'ai-restock'}">${escapeHtml(r.status)}</div></td>
          <td class="num">${r.pack_size > 1 ? `${r.qty}×${r.pack_size}<div class="ai-restock">= ${r.units} ชิ้น</div>` : r.qty}</td>
          <td class="num">${money(r.piece_cost)}</td><td class="num">${r.sale_price == null ? '—' : money(r.sale_price)}</td></tr>`).join('')}
        </tbody></table></div>
      <div class="ai-detail-sum">
        รวม ฿${money(det.subtotal)}${det.discount ? ` · ส่วนลด ฿${money(det.discount)}` : ''}${det.total ? ` · ใบเสร็จ ฿${money(det.total)}` : ''}
        ${det.photo_count ? ` · แนบรูป ${det.photo_count} รูป` : ''}
        ${diff ? `<div class="ai-detail-warn">⚠ ยอดไม่ตรงกับใบเสร็จ ต่าง ฿${money(diff)}</div>` : ''}
        ${(det.warnings || []).map((w) => `<div class="ai-detail-warn">⚠ ${escapeHtml(w)}</div>`).join('')}
      </div>`;
}

function currentModel() {
    const s = ctx.state;
    if (s.modelId === 'auto') {
        // Auto routes photo messages to a vision model when one exists.
        return { auto: true, supports_vision: s.models.some((m) => m.supports_vision) };
    }
    return s.models.find((m) => m.id === s.modelId) || null;
}

function render() {
    if (!ctx) return;
    const s = ctx.state;
    const el = ctx.el;
    const hasModels = s.models.length > 0;

    if (!hasModels && !s.canManage) {
        el.innerHTML = '';
        return;
    }

    const prevInput = el.querySelector('#ai-input');
    const draft = prevInput ? prevInput.value : '';
    const prevScroll = el.querySelector('.ai-log');
    const atBottom = !prevScroll || prevScroll.scrollHeight - prevScroll.scrollTop - prevScroll.clientHeight < 40;

    const speechSupported = !!(window.SpeechRecognition || window.webkitSpeechRecognition);
    const visible = s.messages.map(messageHtml).join('');

    el.innerHTML = `
      <button type="button" class="ai-fab ${s.open ? 'open' : ''}" id="ai-fab" aria-label="ผู้ช่วย AI" title="ผู้ช่วย AI">${s.open ? ICON_CLOSE : ICON_SPARK}</button>
      ${s.open ? `
      <div class="ai-panel" role="dialog" aria-label="ผู้ช่วย AI">
        <div class="ai-head">
          <div class="ai-head-icon">${ICON_SPARK}</div>
          <div style="flex:1;min-width:0">
            <div class="ai-title">ผู้ช่วย AI</div>
            ${s.models.length > 0 ? `
              <select id="ai-model" class="ai-model" title="เลือกโมเดล">
                ${groupedModelOptions(s)}
              </select>` : `<div class="ai-sub">${hasModels ? escapeHtml(`${s.models[0].label} · ${s.models[0].provider_name}`) : 'ยังไม่ได้ตั้งค่า'}</div>`}
          </div>
          <button type="button" class="ai-icon-btn" id="ai-clear" title="เริ่มบทสนทนาใหม่">${ICON_TRASH}</button>
          <button type="button" class="ai-icon-btn" id="ai-close" title="ปิด">${ICON_CLOSE}</button>
        </div>
        <div class="ai-log">
          ${!hasModels ? `<div class="ai-empty">ยังไม่มีโมเดล AI ที่เปิดใช้งาน<br><a href="#/ai" id="ai-goto-settings">ไปที่ตั้งค่าผู้ช่วย AI</a></div>`
            : visible ? visible : `
            <div class="ai-empty">
              สวัสดีคุณ${escapeHtml(ctx.user.full_name)} 👋<br>ถามข้อมูลหรือสั่งงานในระบบได้เลย ผู้ช่วยทำได้ตามสิทธิ์ของคุณ
              <div class="ai-suggest">${SUGGESTIONS.map((q) => `<button type="button" data-suggest="${escapeHtml(q)}">${escapeHtml(q)}</button>`).join('')}</div>
            </div>`}
          ${pendingHtml()}
          ${s.loading ? `<div class="ai-msg bot">
            ${s.progress?.done.length ? `<div class="ai-bubble ai-progress">${s.progress.done.map((d) => `<div>${d.icon} ${escapeHtml(d.text)}</div>`).join('')}</div>` : ''}
            <div class="ai-bubble ai-thinking">
            <div class="ai-typing"><span></span><span></span><span></span></div>
            <span class="ai-status" id="ai-status">${escapeHtml(statusText())}</span>
            <span class="ai-elapsed" id="ai-elapsed">${elapsedText()}</span></div></div>` : ''}
          ${s.error ? `<div class="error-box" style="font-size:12.5px">${escapeHtml(s.error)}</div>` : ''}
        </div>
        ${s.notice ? `<div class="ai-notice">${escapeHtml(s.notice)}</div>` : ''}
        ${s.photos.length || s.documents.length || s.uploading ? `
        <div class="ai-photo-strip">
          ${s.documents.map((d, i) => `
            <div class="ai-doc">📄 ${escapeHtml(d.name)} <span class="muted">${Math.max(1, Math.round(d.size / 1024))} KB</span>
              <button type="button" data-undoc="${i}" title="เอาไฟล์ออก">✕</button></div>`).join('')}
          ${s.photos.map((p, i) => `
            <div class="ai-photo"><img src="${escapeHtml(p.preview)}" alt="รูปที่ ${i + 1}"><span>${i + 1}</span>
              <button type="button" data-unphoto="${i}" title="เอารูปออก">✕</button></div>`).join('')}
          ${s.uploading ? '<div class="ai-photo ai-photo-loading">กำลังอัปโหลด...</div>' : ''}
          ${s.photos.length > 1 ? '<div class="ai-photo-hint">หลายรูป = ใบเสร็จใบเดียวกัน เรียงจากบนลงล่าง</div>' : ''}
        </div>` : ''}
        <form class="ai-input-row" id="ai-form">
          ${hasModels ? `
            <label class="ai-icon-btn" title="แนบรูปใบเสร็จ หรือไฟล์ .md (ได้หลายไฟล์)">
              ${ICON_CLIP}<input type="file" id="ai-file" accept="image/*,.md,.markdown,.txt,text/markdown,text/plain" multiple hidden>
            </label>` : ''}
          ${speechSupported ? `<button type="button" class="ai-icon-btn ai-mic ${s.listening ? 'on' : ''}" id="ai-mic" title="${s.listening ? 'หยุดฟัง' : 'พูดเพื่อพิมพ์ (ภาษาไทย)'}" ${hasModels ? '' : 'disabled'}>${ICON_MIC}</button>` : ''}
          <textarea id="ai-input" rows="1" placeholder="${s.listening ? 'กำลังฟัง... พูดได้เลย' : 'พิมพ์ข้อความ...'}" ${hasModels ? '' : 'disabled'}></textarea>
          <button type="submit" class="ai-send" id="ai-send" title="ส่ง" ${hasModels && !s.loading && !s.uploading ? '' : 'disabled'}>${ICON_SEND}</button>
        </form>
      </div>` : ''}`;

    const input = el.querySelector('#ai-input');
    if (input) {
        input.value = draft;
        autosize(input);
    }
    const log = el.querySelector('.ai-log');
    if (log && atBottom) log.scrollTop = log.scrollHeight;
    bind();
}

function groupedModelOptions(s) {
    const def = s.models.find((m) => m.id === s.defaultId);
    const auto = `<option value="auto" ${s.modelId === 'auto' ? 'selected' : ''}>${def ? `ค่าเริ่มต้น (${escapeHtml(def.label)})` : 'อัตโนมัติ (ประหยัด)'}</option>`;
    const groups = {};
    s.models.forEach((m) => { (groups[m.provider_name] ||= []).push(m); });
    return auto + Object.entries(groups).map(([provider, list]) => `
      <optgroup label="${escapeHtml(provider)}">
        ${list.map((m) => `<option value="${m.id}" ${m.id === s.modelId ? 'selected' : ''}>${escapeHtml(m.label)}${m.supports_vision ? ' 🖼' : ''}</option>`).join('')}
      </optgroup>`).join('');
}

function autosize(t) {
    t.style.height = 'auto';
    t.style.height = Math.min(t.scrollHeight, 120) + 'px';
}

function bind() {
    const el = ctx.el;
    const s = ctx.state;
    el.querySelector('#ai-fab').addEventListener('click', () => {
        s.open = !s.open;
        if (!s.open) stopListening();
        render();
        if (s.open) el.querySelector('#ai-input')?.focus();
    });
    if (!s.open) return;

    el.querySelector('#ai-close').addEventListener('click', () => { s.open = false; stopListening(); render(); });
    el.querySelector('#ai-clear').addEventListener('click', () => {
        s.messages = []; s.pending = []; s.decisions = {}; s.error = null;
        persist();
        render();
    });
    el.querySelector('#ai-goto-settings')?.addEventListener('click', () => { s.open = false; render(); });
    el.querySelector('#ai-model')?.addEventListener('change', (e) => {
        s.modelId = e.target.value === 'auto' ? 'auto' : Number(e.target.value);
        save(localStorage, 'modelChoice', s.modelId);
        s.notice = s.photos.length && !currentModel()?.supports_vision
            ? 'โมเดลนี้อ่านรูปไม่ได้ — รูปที่แนบไว้จะส่งไม่ได้ กรุณาเลือกโมเดลที่มี 🖼'
            : null;
        render();
    });
    const fileInput = el.querySelector('#ai-file');
    fileInput?.addEventListener('change', () => addFiles([...fileInput.files]));
    el.querySelectorAll('[data-undoc]').forEach((b) => b.addEventListener('click', () => {
        s.documents.splice(Number(b.dataset.undoc), 1);
        render();
    }));
    el.querySelectorAll('[data-unphoto]').forEach((b) => b.addEventListener('click', () => {
        s.photos.splice(Number(b.dataset.unphoto), 1);
        render();
    }));
    el.querySelectorAll('[data-edit-form]').forEach((b) => b.addEventListener('click', () => editInForm(b.dataset.editForm)));
    el.querySelectorAll('[data-suggest]').forEach((b) => b.addEventListener('click', () => send(b.dataset.suggest)));
    el.querySelectorAll('[data-approve]').forEach((b) => b.addEventListener('click', () => decide(b.dataset.approve, true)));
    el.querySelectorAll('[data-reject]').forEach((b) => b.addEventListener('click', () => decide(b.dataset.reject, false)));

    const input = el.querySelector('#ai-input');
    input.addEventListener('input', () => autosize(input));
    input.addEventListener('paste', (e) => {
        const files = imagesFromClipboard(e);
        if (files.length) {
            e.preventDefault();
            addFiles(files);
        }
    });
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            el.querySelector('#ai-form').requestSubmit();
        } else if (e.key === 'Escape') {
            s.open = false; stopListening(); render();
        }
    });
    el.querySelector('#ai-form').addEventListener('submit', (e) => {
        e.preventDefault();
        const text = input.value.trim();
        if ((!text && !s.photos.length && !s.documents.length) || s.loading || s.uploading) return;
        if (s.photos.length && !currentModel()?.supports_vision) {
            s.error = 'โมเดลที่เลือกอ่านรูปไม่ได้ กรุณาเลือกโมเดลที่มี 🖼 ก่อนส่ง';
            render();
            return;
        }
        input.value = '';
        send(text);
    });
    el.querySelector('#ai-mic')?.addEventListener('click', () => {
        if (s.listening) {
            stopListening();
            render();
        } else {
            startListening();
        }
    });
}

// ---------------- Waiting indicator ----------------

// Rotating "what's happening" lines while the server works. Reading a receipt
// (photos or an attached file) takes much longer, so it gets its own script.
const STATUS_LINES = {
    general: ['กำลังคิด...', 'กำลังค้นข้อมูลในระบบ...', 'กำลังเรียบเรียงคำตอบ...'],
    receipt: [
        'กำลังอ่านใบเสร็จ...', 'กำลังแยกรายการสินค้า...', 'กำลังตรวจจำนวนและราคา...',
        'กำลังจับคู่กับสินค้าในสต๊อก...', 'กำลังคำนวณต้นทุนต่อชิ้น...', 'กำลังเตรียมร่างใบซื้อ...',
        'ใกล้เสร็จแล้ว...', 'อีกนิดเดียว รายการเยอะหน่อย...',
    ],
};
const STATUS_EVERY_MS = 3500;

function waitKind() {
    const last = ctx.state.messages[ctx.state.messages.length - 1];
    return last?.role === 'user' && (last.attachments?.length || last.documents?.length) ? 'receipt' : 'general';
}

function statusText() {
    if (ctx.state.progress) return ctx.state.progress.text; // real step of a .md import
    const w = ctx.state.wait;
    if (!w) return '';
    const lines = STATUS_LINES[w.kind];
    const step = Math.floor((Date.now() - w.started) / STATUS_EVERY_MS);
    // Walk through the lines once, then keep alternating between the last two.
    return lines[step < lines.length ? step : lines.length - 2 + ((step - lines.length) % 2)];
}

function elapsedText() {
    const w = ctx.state.wait;
    const sec = w ? Math.floor((Date.now() - w.started) / 1000) : 0;
    return sec >= 5 ? `${sec} วิ` : '';
}

function startWaiting() {
    stopWaiting();
    const s = ctx.state;
    s.wait = { kind: waitKind(), started: Date.now(), timer: null };
    // Update the text in place: a full render() would reset scroll and focus.
    s.wait.timer = setInterval(() => {
        const status = ctx?.el.querySelector('#ai-status');
        if (!status) return;
        const text = statusText();
        if (status.textContent !== text) {
            status.classList.remove('ai-status-in');
            void status.offsetWidth; // restart the fade-in
            status.textContent = text;
            status.classList.add('ai-status-in');
        }
        const elapsed = ctx.el.querySelector('#ai-elapsed');
        if (elapsed) elapsed.textContent = elapsedText();
    }, 500);
}

function stopWaiting() {
    const s = ctx?.state;
    if (s?.wait) {
        clearInterval(s.wait.timer);
        s.wait = null;
    }
}

function persist() {
    save(sessionStorage, 'messages', ctx.state.messages);
    save(sessionStorage, 'pending', ctx.state.pending);
}

// ---------------- Conversation ----------------

async function send(text) {
    stopListening();
    const s = ctx.state;
    // Typing a new message while actions await approval counts as declining them.
    s.pending = [];
    s.decisions = {};
    s.notice = null;
    const msg = { role: 'user', content: text };
    if (s.photos.length) {
        msg.attachments = s.photos.map((p) => p.token);
        if (!text) msg.content = s.canPurchase ? 'นำเข้าข้อมูลการซื้อสินค้าเข้าร้านจากใบเสร็จนี้' : 'อ่านข้อมูลจากรูปนี้';
        s.photos = [];
    }
    if (s.documents.length) {
        msg.documents = s.documents.map(({ name, content }) => ({ name, content }));
        if (!msg.content) msg.content = 'สรุป/นำเข้าข้อมูลจากไฟล์ที่แนบ';
        s.documents = [];
    }
    s.messages.push(msg);
    if (msg.documents && s.canPurchase && await importReceipts(msg)) return;
    await callServer({});
}

// ---------------- Step-by-step .md receipt import ----------------

/**
 * Reads receipt tables in attached .md files a few rows per request, so small
 * or free models don't time out on a long receipt, and reports each step as it
 * really happens. Returns false when no file has an item table (normal chat then).
 */
async function importReceipts(msg) {
    const s = ctx.state;
    const mine = ctx;
    s.loading = true;
    s.error = null;
    s.progress = { text: 'กำลังแยกตารางรายการสินค้าในไฟล์...', done: [] };
    startWaiting();
    render();
    const step = (text) => { s.progress.text = text; render(); };
    const done = (text, icon = '✓') => { s.progress.done.push({ icon, text }); render(); };
    const finish = () => {
        s.loading = false;
        s.progress = null;
        stopWaiting();
        persist();
        render();
    };

    try {
        const plans = [];
        for (const doc of msg.documents) {
            const plan = await api.post('/ai/receipt/plan', { content: doc.content, file_name: doc.name });
            if (ctx !== mine) return true;
            if (plan.rows.length) plans.push({ doc, plan });
        }
        if (!plans.length) {
            finish();
            return false;
        }

        const receipts = [];
        for (const { doc, plan } of plans) {
            const total = plan.rows.length;
            const prefix = plans.length > 1 ? `${doc.name}: ` : '';
            done(`${prefix}พบรายการสินค้า ${total} บรรทัด จะอ่านทีละ ${plan.chunk_size} บรรทัด`);
            const items = [];
            const warnings = [];
            if (plan.attachment_error) {
                warnings.push(`เก็บไฟล์ ${doc.name} เป็นหลักฐานไม่สำเร็จ (${plan.attachment_error}) — แนบเองได้ภายหลังที่หน้ารายละเอียดใบซื้อ`);
                done(`${prefix}เก็บไฟล์เป็นหลักฐานไม่สำเร็จ`, '⚠');
            }
            for (let i = 0; i < total; i += plan.chunk_size) {
                const got = await readRows(plan, i, Math.min(i + plan.chunk_size, total), total, prefix, step, done, warnings);
                if (ctx !== mine) return true;
                items.push(...got);
            }
            step(`${prefix}กำลังสรุปยอดทั้งหมด (ร้านค้า เลขที่ใบเสร็จ ส่วนลด ยอดสุทธิ)...`);
            const sum = await api.post('/ai/receipt/summary', {
                model: s.modelId, header: plan.header, footer: plan.footer, file_name: doc.name, item_count: items.length,
            });
            if (ctx !== mine) return true;
            if (sum.error) warnings.push(`อ่านยอดสรุปจากไฟล์ไม่สำเร็จ (${sum.error}) กรุณาตรวจชื่อร้านและยอดสุทธิเอง`);
            done(sum.summary
                ? `${prefix}สรุปยอด: ${sum.summary.supplier_name || 'ไม่ระบุร้าน'} สุทธิ ฿${money(sum.summary.total)}`
                : `${prefix}อ่านยอดสรุปไม่สำเร็จ`, sum.summary ? '✓' : '⚠');
            receipts.push({ file_name: doc.name, attachment: plan.attachment, items, summary: sum.summary, warnings });
            doc.imported = true;
        }

        step('กำลังจับคู่สินค้ากับสต๊อกและเตรียมร่างใบซื้อ...');
        const res = await api.post('/ai/receipt/draft', { receipts, model_label: modelLabel() });
        if (ctx !== mine) return true;
        s.messages.push(res.message);
        s.pending = res.pending_actions || [];
    } catch (err) {
        if (ctx !== mine) return true;
        s.error = `${err.message}${s.progress?.text ? ` (ขณะ${s.progress.text.replace(/^กำลัง/, '').replace(/\.\.\.$/, '')})` : ''}`;
    }
    finish();
    return true;
}

/** Reads rows [from, to); a failed request is retried in halves, down to one row. */
async function readRows(plan, from, to, total, prefix, step, done, warnings) {
    const label = to - from > 1 ? `รายการที่ ${from + 1}–${to}` : `รายการที่ ${from + 1}`;
    step(`${prefix}กำลังอ่าน${label} จาก ${total}...`);
    try {
        const res = await api.post('/ai/receipt/items', { model: ctx.state.modelId, columns: plan.columns, rows: plan.rows.slice(from, to) });
        if (res.fallback) {
            warnings.push(`รายการที่ ${from + 1}: AI อ่านไม่ได้ ระบบแยกข้อมูลจากตารางให้แทน กรุณาตรวจ`);
            done(`${prefix}${label}: AI อ่านไม่ได้ ใช้ข้อมูลจากตารางโดยตรง`, '⚠');
        } else {
            done(`${prefix}อ่าน${label} แล้ว`);
        }
        return res.items;
    } catch (err) {
        if (to - from <= 1) throw err;
        const mid = from + Math.ceil((to - from) / 2);
        done(`${prefix}${label} ไม่สำเร็จ (${err.message}) — แบ่งอ่านทีละน้อยลง`, '↻');
        return [
            ...await readRows(plan, from, mid, total, prefix, step, done, warnings),
            ...await readRows(plan, mid, to, total, prefix, step, done, warnings),
        ];
    }
}

function modelLabel() {
    const m = currentModel();
    const def = ctx.state.models.find((x) => x.id === ctx.state.defaultId);
    return m?.auto ? (def ? def.label : 'อัตโนมัติ') : m?.label || '';
}

/** Splits picked files: .md/.txt are read as text, images are uploaded. */
async function addFiles(files) {
    const s = ctx.state;
    const docs = files.filter((f) => DOC_EXT.test(f.name) || f.type === 'text/markdown' || f.type === 'text/plain');
    const images = files.filter((f) => f.type.startsWith('image/'));
    const others = files.filter((f) => !docs.includes(f) && !images.includes(f));
    s.error = others.length ? `ไม่รองรับไฟล์ ${others.map((f) => f.name).join(', ')} (แนบได้เฉพาะรูปภาพ และไฟล์ .md / .txt)` : null;
    for (const f of docs) {
        if (s.documents.length >= MAX_DOCS) {
            s.error = `แนบไฟล์ได้สูงสุด ${MAX_DOCS} ไฟล์ต่อข้อความ`;
            break;
        }
        if (f.size > MAX_DOC_BYTES) {
            s.error = `ไฟล์ ${f.name} ใหญ่เกิน ${MAX_DOC_BYTES / 1024} KB`;
            continue;
        }
        try {
            s.documents.push({ name: f.name, size: f.size, content: await f.text() });
        } catch (e) {
            s.error = `อ่านไฟล์ ${f.name} ไม่ได้`;
        }
    }
    render();
    if (images.length) await addPhotos(images);
}

async function addPhotos(files) {
    const s = ctx.state;
    const room = MAX_PHOTOS - s.photos.length;
    if (!files.length) return;
    if (room <= 0) {
        s.error = `แนบได้สูงสุด ${MAX_PHOTOS} รูปต่อข้อความ`;
        render();
        return;
    }
    // Photos need a vision model: switch to one automatically, or explain how to enable one.
    if (!currentModel()?.supports_vision) {
        const vision = s.models.find((m) => m.supports_vision);
        if (!vision) {
            s.error = s.canManage
                ? 'ยังไม่มีโมเดลที่อ่านรูปได้ — เปิดที่หน้า "ตั้งค่าผู้ช่วย AI" แล้วติ๊กช่อง "อ่านรูป" ของโมเดลที่รองรับรูปภาพ (เช่น Gemini)'
                : 'ยังไม่มีโมเดลที่อ่านรูปได้ กรุณาแจ้งผู้ดูแลระบบให้เปิดใช้โมเดลที่อ่านรูปภาพได้';
            render();
            return;
        }
        s.modelId = vision.id;
        save(localStorage, 'modelChoice', s.modelId);
        s.notice = `สลับไปใช้โมเดล ${vision.label} ที่อ่านรูปได้แล้ว`;
    }
    s.uploading = true;
    s.error = null;
    render();
    const mine = ctx;
    try {
        for (const f of files.slice(0, room)) {
            const photo = await uploadImage(f);
            if (ctx !== mine) return;
            s.photos.push(photo);
            render();
        }
        if (files.length > room) s.error = `แนบได้สูงสุด ${MAX_PHOTOS} รูปต่อข้อความ`;
    } catch (e) {
        s.error = e.message;
    }
    s.uploading = false;
    render();
}

/** Hands the AI's purchase draft to the purchase page for review instead of saving it here. */
function editInForm(id) {
    const p = ctx.state.pending.find((x) => x.id === id);
    if (!p?.draft) return;
    try {
        sessionStorage.setItem(PURCHASE_DRAFT_KEY, JSON.stringify(p.draft));
    } catch (e) {
        ctx.state.error = 'เปิดฟอร์มไม่ได้: เบราว์เซอร์ไม่อนุญาตให้เก็บข้อมูลชั่วคราว';
        render();
        return;
    }
    ctx.navigate('purchase');
    decide(id, 'edit');
}

async function decide(id, approved) {
    const s = ctx.state;
    s.decisions[id] = approved;
    if (s.pending.every((p) => s.decisions[p.id] !== undefined)) {
        const approvals = { ...s.decisions };
        s.pending = [];
        s.decisions = {};
        await callServer(approvals);
    } else {
        render();
    }
}

async function callServer(approvals) {
    const s = ctx.state;
    const mine = ctx;
    s.loading = true;
    s.error = null;
    startWaiting();
    render();
    try {
        const res = await api.post('/ai/chat', { model: s.modelId, messages: s.messages, approvals });
        if (ctx !== mine) return;
        s.messages = res.messages;
        s.pending = res.pending_actions || [];
        runClientActions(res.client_actions);
    } catch (err) {
        if (ctx !== mine) return;
        s.error = err.message;
    }
    s.loading = false;
    stopWaiting();
    persist();
    render();
    ctx?.el.querySelector('#ai-input')?.focus();
}

function runClientActions(actions) {
    (actions || []).forEach((a) => {
        if (a.type === 'navigate') ctx.navigate(a.page, a.params || undefined);
    });
}

// ---------------- Speech-to-text (browser Web Speech API) ----------------

let recognition = null;

function startListening() {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR || !ctx) return;
    const input = ctx.el.querySelector('#ai-input');
    const base = input.value ? input.value.replace(/\s*$/, ' ') : '';

    recognition = new SR();
    recognition.lang = 'th-TH';
    recognition.interimResults = true;
    recognition.continuous = true;

    recognition.onresult = (e) => {
        let finalText = '';
        let interim = '';
        for (let i = 0; i < e.results.length; i++) {
            const r = e.results[i];
            if (r.isFinal) finalText += r[0].transcript;
            else interim += r[0].transcript;
        }
        const box = ctx?.el.querySelector('#ai-input');
        if (box) {
            box.value = base + finalText + interim;
            autosize(box);
        }
    };
    recognition.onerror = (e) => {
        if (!ctx) return;
        if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
            ctx.state.error = 'เบราว์เซอร์ไม่อนุญาตให้ใช้ไมโครโฟน กรุณาอนุญาตการเข้าถึงไมโครโฟน';
        } else if (e.error !== 'no-speech' && e.error !== 'aborted') {
            ctx.state.error = 'แปลงเสียงเป็นข้อความไม่สำเร็จ: ' + e.error;
        }
    };
    recognition.onend = () => {
        recognition = null;
        if (ctx && ctx.state.listening) {
            ctx.state.listening = false;
            render();
        }
    };
    try {
        recognition.start();
        ctx.state.listening = true;
        ctx.state.error = null;
        render();
        ctx.el.querySelector('#ai-input')?.focus();
    } catch (e) {
        recognition = null;
    }
}

function stopListening() {
    if (ctx) ctx.state.listening = false;
    if (recognition) {
        try { recognition.stop(); } catch (e) { /* ignore */ }
    }
}
