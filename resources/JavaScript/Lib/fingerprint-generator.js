// fingerprint-generator.js
/**
 * @class BrowserFingerprint
 * @description Generates a basic browser fingerprint based on various browser and device properties.
 * Intended for documenting cookie consent decisions, not for pervasive tracking.
 */
class BrowserFingerprint {
    constructor() {
        this.data = {};
    }

    /**
     * @private
     * @returns {string} The user agent string.
     */
    _getUserAgent() {
        return navigator.userAgent || 'unknown';
    }

    /**
     * @private
     * @returns {string} The screen resolution in 'WxH' format.
     */
    _getScreenResolution() {
        return `${screen.width}x${screen.height}`;
    }

    /**
     * @private
     * @returns {number} The color depth of the screen.
     */
    _getColorDepth() {
        return screen.colorDepth || 'unknown';
    }

    /**
     * @private
     * @returns {string} The current time zone of the user's system.
     */
    _getTimeZone() {
        try {
            return Intl.DateTimeFormat().resolvedOptions().timeZone;
        } catch (e) {
            return 'unknown';
        }
    }

    /**
     * @private
     * @returns {string} A hash generated from a canvas rendering.
     * This is a strong fingerprinting component.
     */
    _getCanvasFingerprint() {
        try {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            canvas.width = 200;
            canvas.height = 20;
            ctx.textBaseline = "top";
            ctx.font = "14px 'Arial'";
            ctx.textBaseline = "alphabetic";
            ctx.fillStyle = "#f60";
            ctx.fillRect(125, 1, 62, 20);
            ctx.fillStyle = "#069";
            ctx.fillText("FP Test", 2, 15); // Use a simple, consistent text
            return canvas.toDataURL(); // Returns base64 encoded image data
        } catch (e) {
            return 'canvas_error';
        }
    }

    /**
     * @private
     * @returns {string} A hash generated from WebGL renderer information.
     * Another strong fingerprinting component.
     */
    _getWebGLFingerprint() {
        try {
            const canvas = document.createElement('canvas');
            const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
            if (gl) {
                const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
                const vendor = gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL) || 'unknown';
                const renderer = gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL) || 'unknown';
                return `${vendor}|${renderer}`;
            }
            return 'webgl_not_supported';
        } catch (e) {
            return 'webgl_error';
        }
    }

    /**
     * Gathers all fingerprint components.
     * @returns {object} An object containing all collected fingerprint data.
     */
    collect() {
        this.data = {
            userAgent: this._getUserAgent(),
            screenResolution: this._getScreenResolution(),
            colorDepth: this._getColorDepth(),
            timeZone: this._getTimeZone(),
            canvasHash: this._getCanvasFingerprint(),
            webGLInfo: this._getWebGLFingerprint(),
        };
        return this.data;
    }

    /**
     * Simple synchronous hash function (cyrb53)
     * Modified to return a 16-char hex string (64-bit) instead of a 53-bit number
     * @private
     */
    _cyrb53(str, seed = 0) {
        let h1 = 0xdeadbeef ^ seed, h2 = 0x41c6ce57 ^ seed;
        for (let i = 0, ch; i < str.length; i++) {
            ch = str.charCodeAt(i);
            h1 = Math.imul(h1 ^ ch, 2654435761);
            h2 = Math.imul(h2 ^ ch, 1597334677);
        }
        h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507) ^ Math.imul(h2 ^ (h2 >>> 13), 3266489909);
        h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507) ^ Math.imul(h1 ^ (h1 >>> 13), 3266489909);
        
        // Combine h2 and h1 to a 64-bit hex string (16 characters)
        return (h2 >>> 0).toString(16).padStart(8, '0') + (h1 >>> 0).toString(16).padStart(8, '0');
    }

    /**
     * Generates a unique hash from the collected fingerprint data synchronously.
     * @returns {string} The hash of the fingerprint data (64 chars).
     */
    generateHash() {
        this.collect();

        const fingerprintString = JSON.stringify(this.data);
        
        // Generate a longer hash by concatenating multiple passes with different seeds
        // Each pass gives 16 chars. 2 passes = 32 chars
        return this._cyrb53(fingerprintString, 1) + this._cyrb53(fingerprintString, 2);
    }
}

export default BrowserFingerprint;