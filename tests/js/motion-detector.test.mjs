// Unit tests for public/js/motion-detector.js: `npm run test:js`
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { toGrayscale, changedRatio, sensitivityToRatio, MotionDetector } from '../../public/js/motion-detector.js';

const frame = (value, size = 100) => new Uint8Array(size).fill(value);
const partlyChanged = (base, changed, value, size = 100) => {
    const f = frame(base, size);
    f.fill(value, 0, changed);
    return f;
};

test('toGrayscale converts RGBA to luma', () => {
    const rgba = new Uint8ClampedArray([255, 255, 255, 255, 0, 0, 0, 255, 255, 0, 0, 255]);
    const gray = toGrayscale(rgba);
    assert.equal(gray.length, 3);
    assert.ok(gray[0] >= 254);
    assert.equal(gray[1], 0);
    assert.ok(gray[2] > 70 && gray[2] < 80); // red is dark-ish in luma
});

test('changedRatio counts pixels above the threshold only', () => {
    assert.equal(changedRatio(null, frame(10)), 0);
    assert.equal(changedRatio(frame(10), frame(30), 28), 0); // below threshold: noise
    assert.equal(changedRatio(frame(10), partlyChanged(10, 25, 200), 28), 0.25);
    assert.equal(changedRatio(frame(10, 50), frame(10, 100)), 0); // size mismatch (resize)
});

test('sensitivity maps 1..10 to a decreasing ratio and clamps', () => {
    assert.ok(Math.abs(sensitivityToRatio(1) - 0.12) < 1e-9);
    assert.ok(Math.abs(sensitivityToRatio(10) - 0.005) < 1e-9);
    assert.ok(sensitivityToRatio(3) > sensitivityToRatio(7));
    assert.equal(sensitivityToRatio(99), sensitivityToRatio(10));
    assert.equal(sensitivityToRatio('x'), sensitivityToRatio(6));
});

test('detector needs consecutive changed frames to start and calm time to stop', () => {
    const changes = [];
    const d = new MotionDetector({ sensitivity: 6, startFrames: 2, stopAfterMs: 3000, onChange: (active) => changes.push(active) });

    // (each frame is compared with the previous one, so a static frame = no change)
    assert.equal(d.feed(frame(10), 0), null);       // baseline
    assert.equal(d.feed(frame(200), 200), null);    // 1 changed frame: not yet
    assert.equal(d.feed(frame(200), 400), null);    // static again: streak resets
    assert.equal(d.feed(frame(10), 600), null);     // change #1
    assert.equal(d.feed(frame(200), 800), true);    // change #2 in a row -> start
    assert.deepEqual(changes, [true]);

    assert.equal(d.feed(frame(200), 2000), null);   // calm but < 3 s since last motion
    assert.equal(d.feed(frame(200), 3800), false);  // >= 3 s calm -> stop
    assert.deepEqual(changes, [true, false]);
});

test('a single flicker frame never triggers motion', () => {
    const changes = [];
    const d = new MotionDetector({ startFrames: 2, onChange: (a) => changes.push(a) });
    d.feed(frame(10), 0);
    d.feed(partlyChanged(10, 80, 250), 200); // flash
    d.feed(partlyChanged(10, 80, 250), 400); // same bright frame again: no change vs previous
    d.feed(partlyChanged(10, 80, 250), 600);
    assert.deepEqual(changes, []);
});

test('lower sensitivity ignores small changes', () => {
    const low = new MotionDetector({ sensitivity: 1 });
    const high = new MotionDetector({ sensitivity: 10 });
    for (const d of [low, high]) {
        d.feed(frame(10), 0);
        d.feed(partlyChanged(10, 3, 200), 200);
        d.feed(frame(10), 400);
    }
    assert.equal(low.active, false);
    assert.equal(high.active, true);
});

test('stop() reports the end of an active motion', () => {
    const changes = [];
    const d = new MotionDetector({ onChange: (a) => changes.push(a) });
    d.feed(frame(10), 0);
    d.feed(frame(200), 200);
    d.feed(frame(10), 400);
    assert.equal(d.active, true);
    d.stop();
    assert.deepEqual(changes, [true, false]);
});
