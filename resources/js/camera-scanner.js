import { BrowserQRCodeReader } from '@zxing/browser';

/**
 * Starts a continuous QR-code camera scan on the given video element.
 * Uses facingMode: ideal 'environment' — back camera on mobile, webcam on desktop.
 * Returns a controls object with a stop() method.
 */
export async function startQrCamera(videoElement, onCode) {
    const reader = new BrowserQRCodeReader();
    return reader.decodeFromConstraints(
        { video: { facingMode: { ideal: 'environment' } } },
        videoElement,
        (result, err) => {
            if (!result) return;
            onCode(result.getText());
        }
    );
}
