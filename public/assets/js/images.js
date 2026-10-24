import { api } from './api.js';

// Receipts are long and narrow: cap width for legibility and height so very
// long strips stay under provider image limits.
const MAX_W = 1400;
const MAX_H = 4000;

/** Downscales a photo in the browser and returns a JPEG data URL. */
export function fileToDataUrl(file) {
    return new Promise((resolve, reject) => {
        if (!file.type.startsWith('image/')) {
            reject(new Error(`ไฟล์ ${file.name} ไม่ใช่รูปภาพ`));
            return;
        }
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => {
            const scale = Math.min(1, MAX_W / img.naturalWidth, MAX_H / img.naturalHeight);
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(img.naturalWidth * scale);
            canvas.height = Math.round(img.naturalHeight * scale);
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#fff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            URL.revokeObjectURL(url);
            resolve(canvas.toDataURL('image/jpeg', 0.85));
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error(`เปิดรูป ${file.name} ไม่ได้`));
        };
        img.src = url;
    });
}

/** Product-photo thumbnail size; matches AttachmentService::THUMB_SIZE on the server. */
export const THUMB_SIZE = 320;

/**
 * Shrinks an already loaded image (an <img> element, or a data URL) to a small
 * JPEG data URL for lists; resolves to null when the image cannot be read.
 */
export async function thumbDataUrl(source, max = THUMB_SIZE) {
    try {
        let img = source;
        if (typeof source === 'string') {
            img = new Image();
            img.src = source;
            await img.decode();
        }
        const scale = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        return canvas.toDataURL('image/jpeg', 0.72);
    } catch (e) {
        return null;
    }
}

/** Uploads one image file; resolves to { token, preview } (preview is a local data URL). */
export async function uploadImage(file) {
    const dataUrl = await fileToDataUrl(file);
    const res = await api.post('/attachments', { data_url: dataUrl });
    return { token: res.data.token, preview: dataUrl };
}

/** Receipt files kept as text evidence (a Markdown summary of a purchase). */
export const TEXT_FILE_EXT = /\.(md|markdown|txt)$/i;

export function isTextFile(file) {
    return TEXT_FILE_EXT.test(file.name) || file.type === 'text/markdown' || file.type === 'text/plain';
}

/** Uploads one .md / .txt file; resolves to { token, mime, name, text }. */
export async function uploadTextFile(file) {
    const text = await file.text();
    const res = await api.post('/attachments', { text, name: file.name });
    return { token: res.data.token, mime: res.data.mime, name: res.data.name, text };
}

export function attachmentUrl(token) {
    return api.downloadUrl(`/attachments/${token}`);
}

/** Image files from a paste event (e.g. a screenshot copied to the clipboard). */
export function imagesFromClipboard(event) {
    return [...(event.clipboardData?.files || [])].filter((f) => f.type.startsWith('image/'));
}
