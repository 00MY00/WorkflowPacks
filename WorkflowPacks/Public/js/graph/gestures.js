/*
 * Workflow Packs — diagram: arithmetic of the touch gestures (pure functions, no DOM: tested with Node,
 * tests/js/gestures.test.js). view.js applies them.
 *
 *   pinch     two fingers: the point of the diagram under the middle of the fingers stays under it while they
 *             move apart (zoom in), together (zoom out) or along (move around)
 *   velocity  speed of a finger when it leaves the screen (last 100 ms), for the glide after a fast move
 *   glide     one frame of that glide: the view keeps moving, slower and slower
 *   isTap / isDoubleTap   small enough and quick enough to be a tap, the second tap of a double tap
 */
(function (root) {
    'use strict';

    var ZOOM_MIN = 0.12;
    var ZOOM_MAX = 2.5;
    /** a finger moving less than this (px) is still a tap or a long press */
    var TAP_SLOP = 10;
    var LONG_PRESS = 500;       // ms
    var DOUBLE_TAP = 320;       // ms between two taps
    var DOUBLE_TAP_SLOP = 30;   // px between two taps

    function clamp(k) {
        return Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, k));
    }

    function distance(a, b) {
        return Math.sqrt((a[0] - b[0]) * (a[0] - b[0]) + (a[1] - b[1]) * (a[1] - b[1]));
    }

    function middle(a, b) {
        return [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2];
    }

    /**
     * New view transform {x, y, k} for two fingers that went from (a0, b0) to (a, b), starting from the transform t0.
     * Points in the coordinates of the canvas (pixels from its top left corner).
     */
    function pinch(t0, a0, b0, a, b) {
        var d0 = Math.max(1, distance(a0, b0));
        var m0 = middle(a0, b0), m = middle(a, b);
        var k = clamp(t0.k * distance(a, b) / d0);
        // the point of the diagram that was under the middle of the fingers
        var wx = (m0[0] - t0.x) / t0.k, wy = (m0[1] - t0.y) / t0.k;
        return { x: m[0] - wx * k, y: m[1] - wy * k, k: k };
    }

    /** Speed (px per ms, [vx, vy]) from the positions of the finger [{x, y, at}], over its last 100 ms. */
    function velocity(samples, now) {
        var recent = (samples || []).filter(function (s) { return now - s.at <= 100; });
        if (recent.length < 2) {
            return [0, 0];
        }
        var first = recent[0], last = recent[recent.length - 1];
        var dt = Math.max(1, last.at - first.at);
        return [(last.x - first.x) / dt, (last.y - first.y) / dt];
    }

    /**
     * One frame of the glide: how far to move ([dx, dy] px) and the speed left, or null when it has stopped. Slower
     * by 5 % every 16 ms (one frame at 60 images per second), whatever the real frame time.
     */
    function glide(v, dt) {
        var speed = Math.sqrt(v[0] * v[0] + v[1] * v[1]);
        if (speed < 0.02) {
            return null;
        }
        var f = Math.pow(0.95, dt / 16);
        return { dx: v[0] * dt, dy: v[1] * dt, v: [v[0] * f, v[1] * f] };
    }

    /** Is the glide worth starting (a flick, not the end of a slow move)? */
    function flick(v) {
        return Math.sqrt(v[0] * v[0] + v[1] * v[1]) > 0.35;
    }

    function isTap(start, end) {
        return distance(start, end) <= TAP_SLOP;
    }

    /** Second tap of a double tap: close in time and place to the previous one ({x, y, at, target}). */
    function isDoubleTap(previous, tap) {
        return !!previous && tap.at - previous.at <= DOUBLE_TAP && previous.target === tap.target
            && distance([previous.x, previous.y], [tap.x, tap.y]) <= DOUBLE_TAP_SLOP;
    }

    var api = { ZOOM_MIN: ZOOM_MIN, ZOOM_MAX: ZOOM_MAX, TAP_SLOP: TAP_SLOP, LONG_PRESS: LONG_PRESS, DOUBLE_TAP: DOUBLE_TAP,
        clamp: clamp, distance: distance, middle: middle, pinch: pinch, velocity: velocity, glide: glide, flick: flick,
        isTap: isTap, isDoubleTap: isDoubleTap };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        root.WPGraph = root.WPGraph || {};
        root.WPGraph.gestures = api;
    }
})(typeof window !== 'undefined' ? window : this);
