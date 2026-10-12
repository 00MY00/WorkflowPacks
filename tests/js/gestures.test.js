// Tests of the touch gestures of the diagram (pure functions).  node tests/js/gestures.test.js
const assert = require('assert');
const G = require('../../WorkflowPacks/Public/js/graph/gestures.js');

let n = 0;
function test(name, fn) { fn(); n++; console.log('PASS ' + name); }
const close = (a, b, msg) => assert.ok(Math.abs(a - b) < 1e-6, (msg || '') + ' ' + a + ' != ' + b);

test('pinch: fingers apart = zoom in, the point under the fingers stays under them', () => {
    const t0 = { x: 40, y: 20, k: 1 };
    const t = G.pinch(t0, [100, 100], [200, 100], [50, 100], [250, 100]);
    close(t.k, 2, 'twice as far apart: twice as big');
    // the diagram point under the middle (150, 100) before: ((150-40)/1, (100-20)/1) = (110, 80)
    close(t.x + 110 * t.k, 150);
    close(t.y + 80 * t.k, 100);
});

test('pinch: fingers together = zoom out, moved along = move around, zoom kept in its limits', () => {
    const t0 = { x: 0, y: 0, k: 1 };
    close(G.pinch(t0, [0, 0], [100, 0], [25, 0], [75, 0]).k, 0.5);
    const moved = G.pinch(t0, [0, 0], [100, 0], [30, 40], [130, 40]);
    close(moved.k, 1);
    close(moved.x, 30);
    close(moved.y, 40);
    close(G.pinch(t0, [0, 0], [10, 0], [0, 0], [1000, 0]).k, G.ZOOM_MAX);
    close(G.pinch(t0, [0, 0], [1000, 0], [0, 0], [1, 0]).k, G.ZOOM_MIN);
    assert.ok(isFinite(G.pinch(t0, [5, 5], [5, 5], [5, 5], [6, 6]).k), 'both fingers on the same point');
});

test('velocity and glide: a flick keeps the view moving, slower and slower, then stops', () => {
    const v = G.velocity([{ x: 0, y: 0, at: 0 }, { x: 50, y: 0, at: 900 }, { x: 110, y: 20, at: 950 }, { x: 170, y: 40, at: 1000 }], 1000);
    close(v[0], 1.2);
    close(v[1], 0.4);
    assert.deepStrictEqual(G.velocity([{ x: 0, y: 0, at: 0 }], 10), [0, 0], 'one position: no speed');
    assert.ok(G.flick(v));
    assert.ok(!G.flick([0.1, 0.1]), 'end of a slow move: no glide');
    let speed = v, total = 0, frames = 0;
    for (let s = G.glide(speed, 16); s; s = G.glide(speed, 16)) {
        total += s.dx;
        speed = s.v;
        frames++;
    }
    assert.ok(frames > 10 && frames < 200, 'stops after a while (' + frames + ' frames)');
    assert.ok(total > 300 && total < 500, 'goes on a bit (' + Math.round(total) + ' px)');
    // the same glide whatever the frame time (30 or 60 images per second)
    const at30 = G.glide(v, 32), at60 = G.glide(G.glide(v, 16).v, 16);
    close(at30.v[0], at60.v[0]);
});

test('taps: small move only, double tap = same target, close in time and place', () => {
    assert.ok(G.isTap([0, 0], [6, 6]));
    assert.ok(!G.isTap([0, 0], [20, 0]));
    const first = { x: 100, y: 100, at: 1000, target: 'wf:1' };
    assert.ok(G.isDoubleTap(first, { x: 110, y: 95, at: 1250, target: 'wf:1' }));
    assert.ok(!G.isDoubleTap(first, { x: 110, y: 95, at: 1500, target: 'wf:1' }), 'too slow');
    assert.ok(!G.isDoubleTap(first, { x: 200, y: 100, at: 1100, target: 'wf:1' }), 'too far');
    assert.ok(!G.isDoubleTap(first, { x: 100, y: 100, at: 1100, target: 'wf:2' }), 'another block');
    assert.ok(!G.isDoubleTap(null, first));
});

console.log(n + ' tests OK');
