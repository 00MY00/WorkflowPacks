/*
 * Workflow Packs — diagram: size of a block from its text (pure functions, tested with Node: tests/js/textfit.test.js).
 *
 * A block is as wide as its title needs (between the default width of its kind and MAX_AUTO); longer texts wrap on
 * several lines and the block grows in height: no text is ever cut. A size chosen by hand (resize grip) is kept, as
 * long as it holds the text: the height never goes below what the wrapped text needs.
 *
 * measure(text, 't1' | 't2') -> width in pixels (canvas in the browser, an estimate in the tests).
 */
(function (root) {
    'use strict';

    var TEXT_X = 58;        // text starts after the icon
    var PAD_RIGHT = 16;
    var LINE1 = 17;         // title line height
    var LINE2 = 15;         // second line height
    var PAD_Y = 12;
    var MAX_AUTO = 380;     // automatic width, at most (longer titles wrap)
    var MIN_W = 160;        // hand-made sizes, at least
    var MAX_W = 900;
    var MAX_H = 600;

    /** Lines of `text` no wider than `width` (words kept whole when possible; a too long word is cut). */
    function wrap(text, width, measure, kind) {
        var words = String(text || '').split(/\s+/).filter(Boolean);
        var lines = [];
        var line = '';
        words.forEach(function (word) {
            var candidate = line ? line + ' ' + word : word;
            if (measure(candidate, kind) <= width) {
                line = candidate;
                return;
            }
            if (line) {
                lines.push(line);
            }
            // a single word wider than the block: cut it
            line = '';
            var part = '';
            Array.prototype.forEach.call(word, function (ch) {
                if (part && measure(part + ch, kind) > width) {
                    lines.push(part);
                    part = '';
                }
                part += ch;
            });
            line = part;
        });
        if (line) {
            lines.push(line);
        }
        return lines.length ? lines : [''];
    }

    /**
     * @param base   default [width, height] of the kind of block
     * @param manual [width, height] chosen by hand, or null
     * @return { w, h, t1: [lines], t2: [lines] }
     */
    function fit(node, base, measure, manual, extraRight) {
        var right = PAD_RIGHT + (extraRight || 0);
        var need = Math.max(measure(node.label || '', 't1'), node.sub ? measure(node.sub, 't2') : 0) + TEXT_X + right;
        var w = manual ? clamp(manual[0], MIN_W, MAX_W) : clamp(Math.ceil(need), base[0], Math.max(base[0], MAX_AUTO));
        var avail = w - TEXT_X - right;
        var t1 = wrap(node.label || '', avail, measure, 't1');
        var t2 = node.sub ? wrap(node.sub, avail, measure, 't2') : [];
        var textH = PAD_Y * 2 + t1.length * LINE1 + t2.length * LINE2;
        var h = Math.max(base[1], textH);
        if (manual) {
            h = clamp(Math.max(h, manual[1]), h, MAX_H);
        }
        return { w: Math.round(w), h: Math.round(h), t1: t1, t2: t2 };
    }

    function clamp(v, min, max) {
        return Math.min(max, Math.max(min, v));
    }

    var api = { wrap: wrap, fit: fit, TEXT_X: TEXT_X, LINE1: LINE1, LINE2: LINE2, MIN_W: MIN_W, MAX_W: MAX_W, MAX_H: MAX_H };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        root.WPGraph = root.WPGraph || {};
        root.WPGraph.textfit = api;
    }
})(typeof window !== 'undefined' ? window : this);
