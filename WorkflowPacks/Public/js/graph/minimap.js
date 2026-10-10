/*
 * Workflow Packs — diagram: overall (macro) view. Every block as a small rectangle, the visible area as a frame;
 * click or drag in it to move the main view there.
 */
(function (WPGraph) {
    'use strict';

    var NS = 'http://www.w3.org/2000/svg';
    var L = WPGraph.layout;

    function Minimap(svg, view) {
        this.svg = svg;
        this.view = view;
        this.scale = 1;
        this.box = [0, 0, 1, 1];
        this.pending = false;
        var self = this;
        var dragging = false;
        svg.addEventListener('pointerdown', function (e) { dragging = true; svg.setPointerCapture(e.pointerId); self.jump(e); });
        svg.addEventListener('pointermove', function (e) { if (dragging) { self.jump(e); } });
        svg.addEventListener('pointerup', function () { dragging = false; });
    }

    /** Redraw at most once per frame (called on every move of the main view). */
    Minimap.prototype.update = function () {
        if (this.pending) { return; }
        this.pending = true;
        var self = this;
        window.requestAnimationFrame(function () { self.pending = false; self.draw(); });
    };

    Minimap.prototype.draw = function () {
        var model = this.view.model;
        var nodes = model.list(this.view.visible.bind(this.view));
        var box = L.bounds(model.pos, nodes);
        var w = this.svg.clientWidth || 200, h = this.svg.clientHeight || 140, pad = 10;
        var scale = Math.min((w - pad * 2) / Math.max(1, box[2] - box[0]), (h - pad * 2) / Math.max(1, box[3] - box[1]));
        this.scale = scale;
        this.box = box;
        this.svg.textContent = '';
        var g = document.createElementNS(NS, 'g');
        g.setAttribute('transform', 'translate(' + (pad - box[0] * scale) + ',' + (pad - box[1] * scale) + ') scale(' + scale + ')');
        this.svg.appendChild(g);
        nodes.forEach(function (n) {
            var p = model.pos[n.key];
            if (!p) { return; }
            var s = L.size(n);
            var r = document.createElementNS(NS, 'rect');
            r.setAttribute('x', p[0]);
            r.setAttribute('y', p[1]);
            r.setAttribute('width', s[0]);
            r.setAttribute('height', s[1]);
            r.setAttribute('class', 'wpg-mini kind-' + n.kind + (this.view.selected[n.key] ? ' selected' : ''));
            g.appendChild(r);
        }, this);
        // visible area of the main view
        var vr = this.view.svg.getBoundingClientRect(), t = this.view.t;
        var frame = document.createElementNS(NS, 'rect');
        frame.setAttribute('class', 'wpg-mini-frame');
        frame.setAttribute('x', -t.x / t.k);
        frame.setAttribute('y', -t.y / t.k);
        frame.setAttribute('width', vr.width / t.k);
        frame.setAttribute('height', vr.height / t.k);
        g.appendChild(frame);
    };

    Minimap.prototype.jump = function (e) {
        var r = this.svg.getBoundingClientRect(), pad = 10;
        var x = (e.clientX - r.left - pad) / this.scale + this.box[0];
        var y = (e.clientY - r.top - pad) / this.scale + this.box[1];
        this.view.centerOn(x, y);
    };

    WPGraph.Minimap = Minimap;
})(window.WPGraph = window.WPGraph || {});
