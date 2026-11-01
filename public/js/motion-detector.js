// Frame-differencing motion detector for the laptop camera mode.
// The pure helpers and MotionDetector.feed() run without a DOM, so they are
// unit-tested with `node --test` (tests/js).

/** Converts RGBA pixels to 8-bit luma (BT.601). */
export function toGrayscale(rgba, out = new Uint8Array(rgba.length / 4)) {
    for (let i = 0, p = 0; p < out.length; i += 4, p++) {
        out[p] = (rgba[i] * 77 + rgba[i + 1] * 150 + rgba[i + 2] * 29) >> 8;
    }
    return out;
}

/** Share of pixels whose brightness changed by more than `pixelThreshold`. */
export function changedRatio(prev, curr, pixelThreshold = 28) {
    if (!prev || prev.length !== curr.length || curr.length === 0) {
        return 0;
    }
    let changed = 0;
    for (let i = 0; i < curr.length; i++) {
        if (Math.abs(curr[i] - prev[i]) > pixelThreshold) {
            changed++;
        }
    }
    return changed / curr.length;
}

/** Maps the 1 (least) .. 10 (most sensitive) slider to a changed-pixel ratio. */
export function sensitivityToRatio(level) {
    const clamped = Math.min(10, Math.max(1, Number(level) || 6));
    // 1 -> 12% of the frame must change, 10 -> 0.5%
    return 0.12 * Math.pow(0.005 / 0.12, (clamped - 1) / 9);
}

export class MotionDetector {
    constructor({
        sampleWidth = 96,
        intervalMs = 200,
        pixelThreshold = 28,
        sensitivity = 6,
        startFrames = 2,
        stopAfterMs = 3000,
        onChange = () => {},
        onSample = () => {},
        now = () => performance.now(),
    } = {}) {
        Object.assign(this, { sampleWidth, intervalMs, pixelThreshold, startFrames, stopAfterMs, onChange, onSample, now });
        this.setSensitivity(sensitivity);
        this.reset();
    }

    setSensitivity(level) {
        this.minRatio = sensitivityToRatio(level);
    }

    reset() {
        this.prev = null;
        this.active = false;
        this.hits = 0;
        this.lastMotionAt = 0;
    }

    /**
     * Feeds one grayscale frame. Returns true/false when the motion state flips,
     * otherwise null. Requires `startFrames` consecutive changed frames to start
     * and `stopAfterMs` of calm to stop, which filters out noise and flicker.
     */
    feed(gray, t = this.now()) {
        const ratio = changedRatio(this.prev, gray, this.pixelThreshold);
        this.prev = gray;
        this.onSample(ratio);

        if (ratio >= this.minRatio) {
            this.hits++;
            this.lastMotionAt = t;
            if (!this.active && this.hits >= this.startFrames) {
                this.active = true;
                this.onChange(true, ratio);
                return true;
            }
        } else {
            this.hits = 0;
            if (this.active && t - this.lastMotionAt >= this.stopAfterMs) {
                this.active = false;
                this.onChange(false, ratio);
                return false;
            }
        }
        return null;
    }

    /** Starts sampling a <video> element (browser only). */
    start(video) {
        this.stop();
        this.reset();
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d', { willReadFrequently: true });

        this.timer = setInterval(() => {
            if (video.readyState < 2 || !video.videoWidth) {
                return;
            }
            canvas.width = this.sampleWidth;
            canvas.height = Math.round(this.sampleWidth * video.videoHeight / video.videoWidth);
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            const { data } = ctx.getImageData(0, 0, canvas.width, canvas.height);
            this.feed(toGrayscale(data));
        }, this.intervalMs);
    }

    stop() {
        clearInterval(this.timer);
        this.timer = null;
        if (this.active) {
            this.active = false;
            this.onChange(false, 0);
        }
    }
}
