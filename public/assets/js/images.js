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

/** Uploads one image file; resolves to { token, preview } (preview is a local data URL). */
export async function uploadImage(file) {
    const dataUrl = await fileToDataUrl(file);
    const res = await api.post('/attachments', { data_url: dataUrl });
    return { token: res.data.token, preview: dataUrl };
}

export function attachmentUrl(token) {
    return api.downloadUrl(`/attachments/${token}`);
}

/** Image files from a paste event (e.g. a screenshot copied to the clipboard). */
export function imagesFromClipboard(event) {
    return [...(event.clipboardData?.files || [])].filter((f) => f.type.startsWith('image/'));
}
