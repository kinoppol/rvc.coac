import { escapeHtml } from './util.js';

/** Small, safe Markdown subset (input is escaped first). */
export function markdown(src) {
    const blocks = [];
    let text = escapeHtml(src).replace(/```[\w-]*\n?([\s\S]*?)```/g, (_, code) => {
        blocks.push(`<pre><code>${code.replace(/\n$/, '')}</code></pre>`);
        return `\u0000${blocks.length - 1}\u0000`;
    });
    const inline = (s) => s
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[\s(])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>');

    const lines = text.split('\n');
    const out = [];
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        if (/^\s*\|.*\|\s*$/.test(line) && i + 1 < lines.length && /^\s*\|[\s:|-]+\|\s*$/.test(lines[i + 1])) {
            const cells = (l) => l.trim().replace(/^\||\|$/g, '').split('|').map((c) => inline(c.trim()));
            const head = cells(line);
            i += 2;
            const rows = [];
            while (i < lines.length && /^\s*\|.*\|\s*$/.test(lines[i])) rows.push(cells(lines[i++]));
            i--;
            out.push(`<div class="ai-table"><table><thead><tr>${head.map((c) => `<th>${c}</th>`).join('')}</tr></thead><tbody>${rows.map((r) => `<tr>${r.map((c) => `<td>${c}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`);
        } else if (/^\s*([-*•]|\d+\.)\s+/.test(line)) {
            const ordered = /^\s*\d+\./.test(line);
            const items = [];
            while (i < lines.length && /^\s*([-*•]|\d+\.)\s+/.test(lines[i])) items.push(inline(lines[i++].replace(/^\s*([-*•]|\d+\.)\s+/, '')));
            i--;
            out.push(`<${ordered ? 'ol' : 'ul'}>${items.map((it) => `<li>${it}</li>`).join('')}</${ordered ? 'ol' : 'ul'}>`);
        } else if (/^#{1,4}\s+/.test(line)) {
            out.push(`<div class="ai-h">${inline(line.replace(/^#{1,4}\s+/, ''))}</div>`);
        } else if (line.trim() === '') {
            out.push('');
        } else {
            out.push(`<p>${inline(line)}</p>`);
        }
    }
    return out.join('').replace(/\u0000(\d+)\u0000/g, (_, n) => blocks[Number(n)]);
}
