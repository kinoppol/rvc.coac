import { api } from '../api.js';
import { escapeHtml } from '../util.js';
import { refreshAssistantModels } from '../assistant.js';

const ROLE_LABEL = { admin: 'ผู้ดูแลระบบ', manager: 'ผู้จัดการร้าน', advisor: 'ครูที่ปรึกษา', cashier: 'พนักงานขาย' };

/** Empty list = open to every role that has the ai.use permission. */
function rolesText(roles) {
    return roles && roles.length ? roles.map((r) => ROLE_LABEL[r] || r).join(', ') : 'ทุกบทบาท';
}

/** Admin page: configure AI providers (OpenAI-compatible APIs) and which models are enabled. */
export async function render(container) {
    let providers = [];
    let presets = [];
    let defaultModel = ''; // "<provider_id>:<model_id>", '' = cheapest (auto)
    let editing = null; // draft provider being edited (or null)
    let fetched = null; // models returned by the last connection test
    let status = null; // { type: 'ok'|'bad', text } shown inside the modal
    let listMessage = null; // shown above the provider list after the modal closes
    let modelFilter = '';
    let modalScroll = 0;

    async function load() {
        const res = await api.get('/ai/providers');
        providers = res.data;
        presets = res.presets;
        defaultModel = res.default_model || '';
        draw();
    }

    function presetOf(key) {
        return presets.find((p) => p.key === key) || presets[presets.length - 1];
    }

    function startEdit(p) {
        status = null;
        listMessage = null;
        fetched = null;
        modelFilter = '';
        if (p) {
            editing = {
                ...p, api_key: '', clear_api_key: false,
                headersText: Object.entries(p.extra_headers || {}).map(([k, v]) => `${k}: ${v}`).join('\n'),
                allowed_roles: [...(p.allowed_roles || [])],
                models: p.models.map((m) => ({ ...m })),
            };
        } else {
            const first = presets[0];
            editing = {
                id: null, name: first.name, preset: first.key, base_url: first.base_url, api_key: '',
                has_api_key: false, enabled: true, allowed_roles: [], sort_order: providers.length, headersText: '', models: [],
            };
        }
        draw();
    }

    function parseHeaders(text) {
        const out = {};
        String(text || '').split('\n').forEach((line) => {
            const i = line.indexOf(':');
            if (i > 0) out[line.slice(0, i).trim()] = line.slice(i + 1).trim();
        });
        return out;
    }

    function readForm() {
        const f = container.querySelector('#prov-form');
        if (!f) return;
        const fd = new FormData(f);
        editing.name = fd.get('name');
        editing.base_url = fd.get('base_url');
        editing.api_key = fd.get('api_key');
        editing.clear_api_key = fd.get('clear_api_key') === 'on';
        editing.enabled = fd.get('enabled') === 'on';
        editing.sort_order = Number(fd.get('sort_order') || 0);
        editing.headersText = fd.get('headers');
        editing.allowed_roles = fd.getAll('allowed_roles');
    }

    function payload() {
        return {
            name: editing.name, preset: editing.preset, base_url: editing.base_url, api_key: editing.api_key,
            clear_api_key: editing.clear_api_key, enabled: editing.enabled, sort_order: editing.sort_order,
            allowed_roles: editing.allowed_roles,
            extra_headers: parseHeaders(editing.headersText),
            models: editing.models.map((m) => ({
                model_id: m.model_id, display_name: m.display_name || '', enabled: m.enabled !== false,
                supports_tools: m.supports_tools !== false,
                supports_vision: !!m.supports_vision,
                price_in: m.price_in === '' || m.price_in == null ? null : Number(m.price_in),
                price_out: m.price_out === '' || m.price_out == null ? null : Number(m.price_out),
            })),
        };
    }

    function modelRows() {
        // Saved models first, then anything else the server reported.
        const saved = new Map(editing.models.map((m) => [m.model_id, m]));
        const info = new Map((fetched || []).map((m) => [m.id, m]));
        const rows = editing.models.map((m) => ({ ...m, saved: true, image_output: info.get(m.model_id)?.image_output }));
        (fetched || []).forEach((m) => {
            if (!saved.has(m.id)) rows.push({ model_id: m.id, display_name: '', supports_tools: m.supports_tools !== false, supports_vision: m.supports_vision, image_output: m.image_output, price_in: m.price_in, price_out: m.price_out, enabled: false, saved: false, name: m.name });
        });
        const q = modelFilter.trim().toLowerCase();
        return q ? rows.filter((r) => r.model_id.toLowerCase().includes(q) || (r.name || '').toLowerCase().includes(q)) : rows;
    }

    function drawEditor() {
        const e = editing;
        const rows = modelRows();
        const enabledCount = e.models.filter((m) => m.enabled !== false).length;
        return `
        <div class="modal-backdrop">
          <div class="modal" role="dialog" aria-modal="true" aria-labelledby="prov-modal-title">
          <div class="modal-head">
            <h2 id="prov-modal-title">${e.id ? 'แก้ไขผู้ให้บริการ' : 'เพิ่มผู้ให้บริการ AI'}</h2>
            <button type="button" class="modal-close" data-act="cancel" title="ปิด" aria-label="ปิด">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"></path></svg>
            </button>
          </div>
          <div class="modal-body">
          <form id="prov-form" autocomplete="off" style="display:flex;flex-direction:column;gap:14px">
            <div class="form-grid">
              <label class="field">ประเภท
                <select name="preset" id="preset-select">
                  ${presets.map((p) => `<option value="${p.key}" ${p.key === e.preset ? 'selected' : ''}>${escapeHtml(p.name)}</option>`).join('')}
                </select>
              </label>
              <label class="field">ชื่อที่แสดง <input name="name" required value="${escapeHtml(e.name)}"></label>
            </div>
            <label class="field">Base URL (OpenAI-compatible)
              <input name="base_url" required value="${escapeHtml(e.base_url)}" placeholder="https://example.com/v1">
            </label>
            <div class="form-grid">
              <label class="field">API Key
                <input name="api_key" type="password" value="${escapeHtml(e.api_key || '')}" placeholder="${e.has_api_key ? `บันทึกไว้แล้ว ${escapeHtml(e.api_key_hint)} (เว้นว่างเพื่อใช้ค่าเดิม)` : 'ไม่ต้องใส่หากเซิร์ฟเวอร์ไม่ต้องใช้'}">
              </label>
              <label class="field">ลำดับการแสดง <input name="sort_order" type="number" value="${e.sort_order}"></label>
            </div>
            <label class="field"><span>Header เพิ่มเติม <span class="muted" style="font-weight:400">(บรรทัดละ 1 รายการ เช่น HTTP-Referer: https://shop.example)</span></span>
              <textarea name="headers" rows="2">${escapeHtml(e.headersText)}</textarea>
            </label>
            <div class="field"><span>ผู้ใช้ที่เรียกใช้ได้ <span class="muted" style="font-weight:400">(ไม่ติ๊กเลย = ทุกบทบาทที่มีสิทธิ์ใช้ผู้ช่วย AI)</span></span>
              <div class="actions-row">
                ${Object.entries(ROLE_LABEL).map(([k, v]) => `<label style="display:flex;gap:6px;align-items:center;font-size:13px"><input type="checkbox" name="allowed_roles" value="${k}" ${(e.allowed_roles || []).includes(k) ? 'checked' : ''}> ${escapeHtml(v)}</label>`).join('')}
              </div>
            </div>
            <div class="actions-row">
              <label style="display:flex;gap:6px;align-items:center;font-size:13px"><input type="checkbox" name="enabled" ${e.enabled ? 'checked' : ''}> เปิดใช้งาน</label>
              ${e.has_api_key ? `<label style="display:flex;gap:6px;align-items:center;font-size:13px"><input type="checkbox" name="clear_api_key" ${e.clear_api_key ? 'checked' : ''}> ลบ API Key ที่บันทึกไว้</label>` : ''}
            </div>
            <div class="actions-row">
              <button type="button" class="btn secondary" data-act="test">ทดสอบการเชื่อมต่อ &amp; ดึงรายชื่อโมเดล</button>
              ${status ? `<span class="pill ${status.type}">${escapeHtml(status.text)}</span>` : ''}
            </div>
          </form>

          <div style="margin-top:18px">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
              <h2 style="font-size:14px">โมเดลที่เปิดให้ใช้งาน <span class="pill muted">${enabledCount} โมเดล</span></h2>
              <div class="search-box" style="min-width:200px;padding:6px 10px;margin-left:auto">
                <input id="model-filter" placeholder="ค้นหาโมเดล..." value="${escapeHtml(modelFilter)}">
              </div>
            </div>
            <div class="table-wrap" style="max-height:340px;border:1px solid var(--border);border-radius:10px">
              <table>
                <thead><tr><th style="width:40px">ใช้</th><th>Model ID</th><th>ชื่อที่แสดง</th><th title="ใช้ function calling เพื่อดึงข้อมูล/ทำรายการในระบบ">เรียกเครื่องมือ</th><th title="อ่านรูปภาพได้ (เช่น อ่านใบเสร็จ)">อ่านรูป</th><th title="ราคาของผู้ให้บริการ (USD ต่อ 1 ล้านโทเคน) ใช้เลือกโมเดลที่ถูกที่สุดในโหมดอัตโนมัติ">ราคา $/1M เข้า · ออก</th></tr></thead>
                <tbody>
                  ${rows.length ? rows.map((r) => `
                    <tr>
                      <td><input type="checkbox" data-model-toggle="${escapeHtml(r.model_id)}" ${r.saved && r.enabled !== false ? 'checked' : ''}></td>
                      <td style="font-family:ui-monospace,monospace;font-size:12.5px;word-break:break-all">${escapeHtml(r.model_id)}${r.name && r.name !== r.model_id ? `<div class="muted" style="font-family:inherit">${escapeHtml(r.name)}</div>` : ''}${r.image_output ? '<div class="pill warn" style="font-family:inherit;margin-top:3px" title="โมเดลนี้ออกแบบมาเพื่อสร้างรูป ราคาสูงและไม่เหมาะกับแชท/อ่านใบเสร็จ">โมเดลสร้างรูป — ไม่แนะนำ</div>' : ''}</td>
                      <td>${r.saved ? `<input data-model-name="${escapeHtml(r.model_id)}" value="${escapeHtml(r.display_name || '')}" placeholder="(ใช้ Model ID)" style="width:100%;border:1px solid var(--border);background:var(--surface2);color:inherit;border-radius:7px;padding:5px 8px">` : ''}</td>
                      <td>${r.saved ? `<input type="checkbox" data-model-tools="${escapeHtml(r.model_id)}" ${r.supports_tools !== false ? 'checked' : ''}>` : `<span class="muted" style="font-size:12px">${r.supports_tools === false ? 'ไม่รองรับ' : ''}</span>`}</td>
                      <td>${r.saved ? `<input type="checkbox" data-model-vision="${escapeHtml(r.model_id)}" ${r.supports_vision ? 'checked' : ''}>` : `<span class="muted" style="font-size:12px">${r.supports_vision === true ? '🖼 รองรับ' : ''}</span>`}</td>
                      <td style="white-space:nowrap">${r.saved
                        ? `<input data-model-price="${escapeHtml(r.model_id)}" data-kind="price_in" type="number" min="0" step="0.01" value="${r.price_in ?? ''}" placeholder="-" class="ai-price">
                           <input data-model-price="${escapeHtml(r.model_id)}" data-kind="price_out" type="number" min="0" step="0.01" value="${r.price_out ?? ''}" placeholder="-" class="ai-price">`
                        : `<span class="muted" style="font-size:12px">${r.price_in != null ? `${r.price_in} · ${r.price_out}` : ''}</span>`}</td>
                    </tr>`).join('') : `<tr><td colspan="6" class="empty">กด "ทดสอบการเชื่อมต่อ" เพื่อดึงรายชื่อโมเดล หรือเพิ่มชื่อโมเดลเองด้านล่าง</td></tr>`}
                </tbody>
              </table>
            </div>
            <div class="actions-row" style="margin-top:10px">
              <input id="manual-model" placeholder="เพิ่ม Model ID เอง เช่น gemini-2.5-flash" style="flex:1;min-width:200px;border:1px solid var(--border);background:var(--surface2);color:inherit;border-radius:9px;padding:9px 12px">
              <button type="button" class="btn secondary sm" data-act="add-model">เพิ่มโมเดล</button>
            </div>
          </div>

          </div>
          <div class="modal-foot">
            <div>${e.id ? '<button type="button" class="btn danger sm" data-act="delete">ลบผู้ให้บริการนี้</button>' : ''}</div>
            <div class="actions-row">
              <button type="button" class="btn secondary" data-act="cancel">ปิด</button>
              <button type="button" class="btn" data-act="save">บันทึก</button>
            </div>
          </div>
          </div>
        </div>`;
    }

    function draw() {
        const prevBody = container.querySelector('.modal-body');
        modalScroll = editing && prevBody ? prevBody.scrollTop : 0;
        container.innerHTML = `
        <div class="hint"><div class="dot">i</div><div>
          เชื่อมต่อผู้ช่วย AI กับผู้ให้บริการที่รองรับรูปแบบ OpenAI API ได้หลายราย เช่น OpenRouter, Google AI Studio, ThaiLLM หรือ LLM Server ของสถานศึกษาเอง
          (vLLM, Ollama, LM Studio) — หากเปิดหลายโมเดล ผู้ใช้จะเลือกโมเดลได้จากหน้าต่างสนทนา
          ผู้ช่วยจะดำเนินการได้เฉพาะสิ่งที่ผู้ใช้คนนั้นมีสิทธิ์ และการเพิ่ม/แก้ไขข้อมูลต้องให้ผู้ใช้กดยืนยันทุกครั้ง
        </div></div>
        <section class="card">
            <div class="card-head"><h2>ผู้ให้บริการ AI</h2>
              <div class="actions-row">
                ${listMessage ? `<span class="pill ok">${escapeHtml(listMessage)}</span>` : ''}
                <button type="button" class="btn sm" data-act="new">+ เพิ่มผู้ให้บริการ</button>
              </div>
            </div>
            ${providers.length ? `<div class="table-wrap"><table>
              <thead><tr><th>ชื่อ</th><th>Base URL</th><th class="num">โมเดล</th><th>ผู้ใช้ที่เรียกใช้ได้</th><th>สถานะ</th><th></th></tr></thead>
              <tbody>${providers.map((p) => `
                <tr>
                  <td style="font-weight:600">${escapeHtml(p.name)}<div class="muted" style="font-size:12px;font-weight:400">${escapeHtml(presetOf(p.preset)?.name || p.preset)}</div></td>
                  <td class="muted" style="font-size:12.5px;word-break:break-all">${escapeHtml(p.base_url)}</td>
                  <td class="num">${p.models.filter((m) => m.enabled).length} / ${p.models.length}</td>
                  <td style="font-size:12.5px">${escapeHtml(rolesText(p.allowed_roles))}</td>
                  <td>${p.enabled ? '<span class="pill ok">เปิด</span>' : '<span class="pill muted">ปิด</span>'}</td>
                  <td><button type="button" class="btn secondary sm" data-edit="${p.id}">แก้ไข</button></td>
                </tr>`).join('')}</tbody></table></div>`
            : '<div class="empty">ยังไม่มีผู้ให้บริการ — กด "เพิ่มผู้ให้บริการ" เพื่อเริ่มต้น</div>'}
        </section>
        ${drawDefault()}
        ${editing ? drawEditor() : ''}`;
        // Redraws rebuild the modal; keep its scroll position so toggling a model doesn't jump to the top.
        const body = container.querySelector('.modal-body');
        if (body) body.scrollTop = modalScroll;
        bind();
    }

    function drawDefault() {
        const options = providers.filter((p) => p.enabled).map((p) => {
            const list = p.models.filter((m) => m.enabled);
            return list.length ? `<optgroup label="${escapeHtml(p.name)}">${list.map((m) => {
                const v = `${p.id}:${m.model_id}`;
                return `<option value="${escapeHtml(v)}" ${v === defaultModel ? 'selected' : ''}>${escapeHtml(m.display_name || m.model_id)}${m.supports_vision ? ' 🖼' : ''}</option>`;
            }).join('')}</optgroup>` : '';
        }).join('');
        return `
        <section class="card">
            <div class="card-head"><h2>ผู้ช่วย AI เริ่มต้น</h2></div>
            <div style="padding:14px 16px;display:flex;flex-direction:column;gap:8px">
              <select id="default-model" style="max-width:480px">
                <option value="" ${defaultModel === '' ? 'selected' : ''}>อัตโนมัติ (เลือกโมเดลที่ถูกที่สุด)</option>
                ${options}
              </select>
              <div class="muted" style="font-size:12.5px">ใช้เป็นหลักเมื่อผู้ใช้ไม่ได้เลือกโมเดลเอง — หากโมเดลนี้ปิดใช้งาน ไม่ได้เปิดให้บทบาทของผู้ใช้ หรืออ่านรูปไม่ได้ขณะที่ส่งรูปมา ระบบจะเลือกโมเดลที่ถูกที่สุดแทน</div>
            </div>
        </section>`;
    }

    function findModel(id) {
        return editing.models.find((m) => m.model_id === id);
    }

    function bind() {
        container.querySelector('[data-act="new"]')?.addEventListener('click', () => startEdit(null));
        container.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => {
            startEdit(providers.find((p) => p.id === Number(b.dataset.edit)));
        }));
        container.querySelector('#default-model')?.addEventListener('change', async (ev) => {
            const prev = defaultModel;
            defaultModel = ev.target.value;
            try {
                await api.put('/ai/default-model', { default_model: defaultModel });
                listMessage = 'บันทึกผู้ช่วย AI เริ่มต้นแล้ว';
                refreshAssistantModels();
            } catch (e) {
                defaultModel = prev;
                alert(e.message || 'บันทึกไม่สำเร็จ');
            }
            draw();
        });
        if (!editing) return;

        container.querySelector('#preset-select').addEventListener('change', (ev) => {
            readForm();
            const old = presetOf(editing.preset);
            const next = presetOf(ev.target.value);
            // Only overwrite fields the admin hasn't customised.
            if (!editing.name || editing.name === old.name) editing.name = next.name;
            if (!editing.base_url || editing.base_url === old.base_url) editing.base_url = next.base_url;
            editing.preset = next.key;
            draw();
        });
        container.querySelector('#model-filter').addEventListener('input', (ev) => {
            readForm();
            modelFilter = ev.target.value;
            const pos = ev.target.selectionStart;
            draw();
            const inp = container.querySelector('#model-filter');
            inp.focus();
            inp.setSelectionRange(pos, pos);
        });
        container.querySelectorAll('[data-model-toggle]').forEach((cb) => cb.addEventListener('change', () => {
            readForm();
            const id = cb.dataset.modelToggle;
            const m = findModel(id);
            if (m) {
                m.enabled = cb.checked;
                // Unchecking a model that was only fetched (never saved) removes it again.
                if (!cb.checked && !providers.find((p) => p.id === editing.id)?.models.some((x) => x.model_id === id)) {
                    editing.models = editing.models.filter((x) => x.model_id !== id);
                }
            } else if (cb.checked) {
                const f = (fetched || []).find((x) => x.id === id);
                editing.models.push({ model_id: id, display_name: '', enabled: true, supports_tools: f?.supports_tools !== false, supports_vision: f?.supports_vision === true, price_in: f?.price_in ?? null, price_out: f?.price_out ?? null });
            }
            draw();
        }));
        container.querySelectorAll('[data-model-name]').forEach((inp) => inp.addEventListener('input', () => {
            const m = findModel(inp.dataset.modelName);
            if (m) m.display_name = inp.value;
        }));
        container.querySelectorAll('[data-model-tools]').forEach((cb) => cb.addEventListener('change', () => {
            const m = findModel(cb.dataset.modelTools);
            if (m) m.supports_tools = cb.checked;
        }));
        container.querySelectorAll('[data-model-price]').forEach((inp) => inp.addEventListener('input', () => {
            const m = findModel(inp.dataset.modelPrice);
            if (m) m[inp.dataset.kind] = inp.value === '' ? null : Number(inp.value);
        }));
        container.querySelectorAll('[data-model-vision]').forEach((cb) => cb.addEventListener('change', () => {
            const m = findModel(cb.dataset.modelVision);
            if (m) m.supports_vision = cb.checked;
        }));

        // The modal closes only via its close buttons (no backdrop click / Esc) so edits aren't lost by accident.
        container.querySelectorAll('[data-act="cancel"]').forEach((b) => b.addEventListener('click', () => { editing = null; draw(); }));
        container.querySelector('[data-act="add-model"]').addEventListener('click', () => {
            readForm();
            const id = container.querySelector('#manual-model').value.trim();
            if (id && !findModel(id)) editing.models.push({ model_id: id, display_name: '', enabled: true, supports_tools: true });
            draw();
        });

        container.querySelector('[data-act="test"]').addEventListener('click', async (ev) => {
            readForm();
            ev.target.disabled = true;
            ev.target.textContent = 'กำลังทดสอบ...';
            try {
                const res = await api.post('/ai/providers/test', {
                    provider_id: editing.id, base_url: editing.base_url, api_key: editing.api_key,
                    clear_api_key: editing.clear_api_key, extra_headers: parseHeaders(editing.headersText),
                });
                fetched = res.models;
                // Refresh known prices of saved models from the provider's list.
                editing.models.forEach((m) => {
                    const f = fetched.find((x) => x.id === m.model_id);
                    if (f && f.price_in != null) { m.price_in = f.price_in; m.price_out = f.price_out; }
                });
                status = { type: 'ok', text: `เชื่อมต่อสำเร็จ (${res.latency_ms} ms) พบ ${res.models.length} โมเดล` };
            } catch (err) {
                fetched = null;
                status = { type: 'bad', text: err.message };
            }
            draw();
        });

        container.querySelector('[data-act="save"]').addEventListener('click', async () => {
            readForm();
            try {
                if (editing.id) {
                    await api.put(`/ai/providers/${editing.id}`, payload());
                } else {
                    const res = await api.post('/ai/providers', payload());
                    editing.id = res.id;
                }
                // Saved: close the modal and confirm on the list. On error it stays open with the input intact.
                listMessage = `บันทึก "${editing.name}" แล้ว`;
                editing = null;
                status = null;
                await load();
                refreshAssistantModels();
            } catch (err) {
                status = { type: 'bad', text: err.message };
                draw();
            }
        });

        container.querySelector('[data-act="delete"]')?.addEventListener('click', async () => {
            if (!confirm(`ลบผู้ให้บริการ "${editing.name}" และโมเดลทั้งหมดของผู้ให้บริการนี้?`)) return;
            try {
                await api.del(`/ai/providers/${editing.id}`);
                listMessage = `ลบ "${editing.name}" แล้ว`;
                editing = null;
                await load();
                refreshAssistantModels();
            } catch (err) {
                status = { type: 'bad', text: err.message };
                draw();
            }
        });
    }

    await load();
}
