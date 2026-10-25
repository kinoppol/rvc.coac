// Reading barcodes with the phone's rear camera (stock count, inventory, phone-as-scanner).

// Fallback decoder for browsers without the BarcodeDetector API (iOS Safari, Firefox).
const ZXING_URL = 'https://cdn.jsdelivr.net/npm/@zxing/browser@0.1.5/+esm';
const FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'qr_code'];

/** Starts the rear camera in `video` and calls onCode(text) for every barcode read; resolves to a stop() function. */
export async function startScanner(video, onCode) {
    if (!navigator.mediaDevices?.getUserMedia) {
        throw new Error('เบราว์เซอร์นี้เปิดกล้องไม่ได้ (ต้องเปิดผ่าน https)');
    }
    if ('BarcodeDetector' in window) {
        const supported = await window.BarcodeDetector.getSupportedFormats().catch(() => []);
        const formats = FORMATS.filter((f) => supported.includes(f));
        if (formats.length) {
            const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: { ideal: 1280 } }, audio: false });
            video.srcObject = stream;
            await video.play();
            const detector = new window.BarcodeDetector({ formats });
            let running = true;
            const loop = async () => {
                if (!running) return;
                try {
                    const codes = await detector.detect(video);
                    if (codes.length) onCode(codes[0].rawValue);
                } catch { /* frame not ready */ }
                setTimeout(loop, 180);
            };
            loop();
            return () => {
                running = false;
                stream.getTracks().forEach((t) => t.stop());
                video.srcObject = null;
            };
        }
    }
    const { BrowserMultiFormatReader } = await import(ZXING_URL);
    const reader = new BrowserMultiFormatReader();
    const controls = await reader.decodeFromConstraints(
        { video: { facingMode: 'environment' }, audio: false }, video,
        (result) => { if (result) onCode(result.getText()); },
    );
    return () => controls.stop();
}
