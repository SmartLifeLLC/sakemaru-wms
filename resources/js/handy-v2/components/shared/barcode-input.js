/**
 * Barcode scanner input handler.
 * Detects hardware scanner input by checking keystroke timing (< 30ms between chars).
 * Emits 'barcode-scanned' custom event on Enter key after fast input.
 */
export function createBarcodeHandler(onScan) {
    let buffer = '';
    let lastKeyTime = 0;
    const SCAN_THRESHOLD_MS = 30;

    return {
        handleKeyDown(event) {
            const now = Date.now();

            if (event.key === 'Enter') {
                if (buffer.length >= 4) {
                    // Likely a barcode scan
                    onScan(buffer);
                }
                buffer = '';
                lastKeyTime = 0;
                return;
            }

            // Single printable character
            if (event.key.length === 1) {
                if (now - lastKeyTime < SCAN_THRESHOLD_MS || buffer.length === 0) {
                    buffer += event.key;
                } else {
                    // Too slow, reset buffer
                    buffer = event.key;
                }
                lastKeyTime = now;
            }
        },

        reset() {
            buffer = '';
            lastKeyTime = 0;
        },
    };
}
