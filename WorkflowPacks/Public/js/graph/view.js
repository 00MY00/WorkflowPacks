/*
 * Workflow Packs — diagram: SVG drawing and interactions (draw.io-like).
 *
 *   background drag = move around · wheel = zoom at the pointer · block drag = move (all selected blocks; snapped to
 *   10 px, Alt = free) · Shift + click = add to the selection · click on a link = its rule in that mailbox ·
 *   drag from the ● handle of a rule to a mailbox = apply the rule to that mailbox (any other target is refused) ·
 *   links read as a flow: mailbox -> rule -> destination (folder, person, tag, other mailbox) ·
 *   double click = open (mailbox: show only its rules; rule: edit)
 *
 * Detail level follows the zoom (far: names only). The view never talks to the server: it calls the callbacks given
 * by app.js (onMoved, onSelect, onEdge, onConnect, onOpen, onView).
 */
(function (WPGraph) {
    'use strict';

    var NS = 'http://www.w3.org/2000/svg';
    var L = WPGraph.layout;

    function el(name, attrs, parent) {
        var node = document.createElementNS(NS, name);
        Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
        if (parent) { parent.appendChild(node); }
        return node;
    }

    function cut(text, max) {
        text = String(text || '');
        return text.length > max ? text.slice(0, max - 1) + '…' : text;
    }

    function View(svg, model, opts) {
        this.svg = svg;
        this.model = model;
        this.opts = opts;
        this.t = { x: 40, y: 40, k: 1 };
        this.edgeStyle = 'orthogonal';
        this.showPacks = true;
        this.showDests = true;
        this.focus = null;      // null or { keys: [...] }: only these blocks are drawn
        this.selected = {};
        this.selectedEdge = null;
        this.mode = null;
        this.build();
        this.bind();
    }

    View.prototype.build = function () {
        var defs = el('defs', {}, this.svg);
        WPGraph.icons.define(defs);
        var pattern = el('pattern', { id: 'wpg-grid', width: 20, height: 20, patternUnits: 'userSpaceOnUse' }, defs);
        el('path', { d: 'M 20 0 L 0 0 0 20', class: 'wpg-grid-line' }, pattern);
        var marker = el('marker', { id: 'wpg-arrow', viewBox: '0 0 10 10', refX: 9, refY: 5, markerWidth: 7, markerHeight: 7, orient: 'auto-start-reverse' }, defs);
        el('path', { d: 'M 0 0 L 10 5 L 0 10 z', class: 'wpg-arrow' }, marker);
        this.grid = el('rect', { class: 'wpg-grid', x: -50000, y: -50000, width: 100000, height: 100000, fill: 'url(#wpg-grid)' });
        this.vp = el('g', { class: 'wpg-vp' }, this.svg);
        this.vp.appendChild(this.grid);
        this.edgeLayer = el('g', { class: 'wpg-edges' }, this.vp);
        this.nodeLayer = el('g', { class: 'wpg-nodes' }, this.vp);
        this.ghost = el('path', { class: 'wpg-ghost', d: '' }, this.vp);
        this.setTransform();
    };

    // ------------------------------------------------------------------------------------------------ drawing

    View.prototype.visible = function (node) {
        if (!node || (!this.showPacks && node.kind === 'pack') || (!this.showDests && node.kind === 'dest')) {
            return false;
        }
        return !this.focus || this.focus.keys.indexOf(node.key) >= 0;
    };

    View.prototype.render = function () {
        this.nodeLayer.textContent = '';
        this.model.list(this.visible.bind(this)).forEach(this.drawNode, this);
        this.drawEdges();
        this.highlight();
    };

    View.prototype.drawNode = function (n) {
        var p = this.model.pos[n.key] || [0, 0];
        var s = L.size(n);
        var cls = 'wpg-node kind-' + n.kind + (n.origin ? ' origin-' + n.origin : '') + (n.dest ? ' dest-' + n.dest : '');
        var g = el('g', { class: cls, 'data-key': n.key, transform: 'translate(' + p[0] + ',' + p[1] + ')' }, this.nodeLayer);
        el('title', {}, g).textContent = n.label + (n.sub ? ' — ' + n.sub : '');
        el('rect', { class: 'wpg-box', width: s[0], height: s[1], rx: n.kind === 'mailbox' ? 12 : 8 }, g);
        el('rect', { class: 'wpg-stripe', width: 6, height: s[1], rx: 3 }, g);
        el('rect', { class: 'wpg-pill', x: 14, y: s[1] / 2 - 17, width: 34, height: 34, rx: 10 }, g);
        el('use', { class: 'wpg-icon', href: '#wpg-i-' + WPGraph.icons.of(n), x: 20, y: s[1] / 2 - 11, width: 22, height: 22 }, g);
        // whole text, wrapped on as many lines as needed (textfit.js); vertically centered in the block
        var lines1 = n.box ? n.box.t1 : [cut(n.label, 26)];
        var lines2 = n.box ? n.box.t2 : (n.sub ? [cut(n.sub, 30)] : []);
        var top = s[1] / 2 - (lines1.length * 17 + lines2.length * 15) / 2;
        var t1 = el('text', { class: 'wpg-t1' }, g);
        lines1.forEach(function (line, i) { el('tspan', { x: 58, y: top + 13 + i * 17 }, t1).textContent = line; });
        if (lines2.length) {
            var t2 = el('text', { class: 'wpg-t2' }, g);
            lines2.forEach(function (line, i) { el('tspan', { x: 58, y: top + lines1.length * 17 + 12 + i * 15 }, t2).textContent = line; });
        }
        if (n.warn) {
            var warning = el('use', { class: 'wpg-warn', href: '#wpg-i-warn', x: s[0] - 24, y: 6, width: 16, height: 16 }, g);
            el('title', {}, warning).textContent = n.warn;
        }
        // resize grip (bottom right corner): drag to enlarge the block, double click for the automatic size
        var grip = el('path', { class: 'wpg-grip', d: 'M' + (s[0] - 3) + ',' + (s[1] - 15) + 'L' + (s[0] - 3) + ',' + (s[1] - 3) + 'L' + (s[0] - 15) + ',' + (s[1] - 3) + 'Z' }, g);
        el('title', {}, grip).textContent = this.opts.texts.resize_hint || '';
        if (n.kind === 'rule') {
            // state: active everywhere (full green), nowhere (empty grey), in some mailboxes only (half)
            var st = this.ruleState(n.key);
            if (st.total) {
                var state = el('g', { class: 'wpg-state ' + (st.active === st.total ? 'is-on' : (st.active ? 'is-mixed' : 'is-off')) }, g);
                el('circle', { cx: s[0] - 14, cy: 14, r: 6 }, state);
                if (st.active && st.active < st.total) {
                    el('path', { d: 'M' + (s[0] - 14) + ',8 A6,6 0 0 1 ' + (s[0] - 14) + ',20 Z', class: 'wpg-state-half' }, state);
                }
                el('title', {}, state).textContent = (this.opts.texts.state_summary || ':active / :total')
                    .replace(':active', st.active).replace(':total', st.total);
            }
            if ((n.sensitive || []).length) {
                var warn = el('use', { class: 'wpg-warn', href: '#wpg-i-warn', x: s[0] - 44, y: 6, width: 16, height: 16 }, g);
                el('title', {}, warn).textContent = n.sensitive.map(function (k) { return this.opts.texts['sensitive_' + k] || k; }, this).join(', ');
            }
            // handle on the side of the mailboxes: drag it to a mailbox to apply the rule there
            var handle = el('circle', { class: 'wpg-handle', cx: 0, cy: s[1] / 2, r: 8 }, g);
            el('title', {}, handle).textContent = this.opts.texts.connect_hint;
        }
    };

    /** Rule enabled in how many of its mailboxes (rules deleted from Workflows are not counted). */
    View.prototype.ruleState = function (key) {
        var out = { active: 0, total: 0 };
        this.model.edges.forEach(function (e) {
            if (e.kind === 'applies' && e.from === key && e.workflow_id) {
                out.total++;
                if (e.active) { out.active++; }
            }
        });
        return out;
    };

    /** Anchor points + path of a link, in the chosen style (orthogonal like draw.io, curved, straight). */
    View.prototype.path = function (a, b) {
        var sa = L.size(a), sb = L.size(b), pa = this.model.pos[a.key], pb = this.model.pos[b.key];
        var ca = [pa[0] + sa[0] / 2, pa[1] + sa[1] / 2], cb = [pb[0] + sb[0] / 2, pb[1] + sb[1] / 2];
        var dir = cb[0] >= ca[0] ? 1 : -1;
        var s = [ca[0] + dir * sa[0] / 2, ca[1]], t = [cb[0] - dir * sb[0] / 2, cb[1]];
        if (Math.abs(cb[0] - ca[0]) < (sa[0] + sb[0]) / 2) {
            // blocks above each other: vertical link
            var down = cb[1] >= ca[1] ? 1 : -1;
            s = [ca[0], ca[1] + down * sa[1] / 2];
            t = [cb[0], cb[1] - down * sb[1] / 2];
            if (this.edgeStyle === 'straight') { return 'M' + s + 'L' + t; }
            if (this.edgeStyle === 'curved') {
                var dy = Math.max(30, Math.abs(t[1] - s[1]) / 2) * down;
                return 'M' + s + 'C' + [s[0], s[1] + dy] + ' ' + [t[0], t[1] - dy] + ' ' + t;
            }
            var my = (s[1] + t[1]) / 2;
            return 'M' + s + 'V' + my + 'H' + t[0] + 'V' + t[1];
        }
        if (this.edgeStyle === 'straight') { return 'M' + s + 'L' + t; }
        if (this.edgeStyle === 'curved') {
            var dx = Math.max(40, Math.abs(t[0] - s[0]) / 2) * dir;
            return 'M' + s + 'C' + [s[0] + dx, s[1]] + ' ' + [t[0] - dx, t[1]] + ' ' + t;
        }
        var mx = (s[0] + t[0]) / 2;
        return 'M' + s + 'H' + mx + 'V' + t[1] + 'H' + t[0];
    };

    View.prototype.drawEdges = function () {
        this.edgeLayer.textContent = '';
        this.model.edges.forEach(function (e, i) {
            // a rule applies to a mailbox: drawn as the flow "mailbox -> rule"
            var flip = e.kind === 'applies';
            var a = this.model.nodes[flip ? e.to : e.from], b = this.model.nodes[flip ? e.from : e.to];
            if (!this.visible(a) || !this.visible(b) || !this.model.pos[a.key] || !this.model.pos[b.key]) {
                return;
            }
            var d = this.path(a, b);
            var cls = 'wpg-edge kind-' + e.kind + (e.kind === 'applies' ? ' state-' + e.state + (e.active ? ' is-active' : ' is-inactive') : '');
            var g = el('g', { class: cls, 'data-edge': i, 'data-from': e.from, 'data-to': e.to }, this.edgeLayer);
            el('path', { class: 'wpg-line', d: d, 'marker-end': e.kind === 'contains' ? '' : 'url(#wpg-arrow)' }, g);
            if (e.kind === 'sends') {
                el('title', {}, g).textContent = this.opts.texts.edge_sends + ' (' + e.action + ')';
            }
            if (e.kind === 'applies') {
                var hit = el('path', { class: 'wpg-edge-hit', d: d, 'data-edge': i }, g);
                el('title', {}, hit).textContent = this.opts.texts['edge_' + e.state] + ' · ' + this.opts.texts[e.active ? 'active' : 'inactive'];
            }
        }, this);
    };

    /** Selected blocks + their links and neighbors stand out, the rest is dimmed (network view). */
    View.prototype.highlight = function () {
        var keys = Object.keys(this.selected);
        var near = {};
        keys.forEach(function (k) {
            near[k] = true;
            this.model.neighbors(k).forEach(function (n) { near[n] = true; });
            // e-mail folders: the whole path of a rule ("[WP] Invoices" -> "Suppliers"), and the rules of a folder
            var node = this.model.nodes[k];
            this.model.list(function (n) { return (n.filed_by || []).indexOf(k) >= 0; }).forEach(function (n) { near[n.key] = true; });
            ((node && node.filed_by) || []).forEach(function (r) { near[r] = true; });
        }, this);
        var dim = keys.length > 0;
        Array.prototype.forEach.call(this.nodeLayer.children, function (g) {
            var k = g.getAttribute('data-key');
            g.classList.toggle('selected', !!this.selected[k]);
            g.classList.toggle('dim', dim && !near[k]);
        }, this);
        Array.prototype.forEach.call(this.edgeLayer.children, function (g) {
            var from = g.getAttribute('data-from'), to = g.getAttribute('data-to');
            var on = keys.indexOf(from) >= 0 || keys.indexOf(to) >= 0
                || (g.classList.contains('kind-folder') && near[from] && near[to]);
            g.classList.toggle('dim', dim && !on);
            g.classList.toggle('lit', on);
            g.classList.toggle('selected', this.selectedEdge !== null && String(this.selectedEdge) === g.getAttribute('data-edge'));
        }, this);
    };

    View.prototype.moveNodes = function (keys) {
        keys.forEach(function (k) {
            var g = this.nodeLayer.querySelector('[data-key="' + CSS.escape(k) + '"]');
            var p = this.model.pos[k];
            if (g && p) { g.setAttribute('transform', 'translate(' + p[0] + ',' + p[1] + ')'); }
        }, this);
        this.drawEdges();
        this.highlight();
    };

    // ---------------------------------------------------------------------------------------- view / zoom

    View.prototype.setTransform = function () {
        this.vp.setAttribute('transform', 'translate(' + this.t.x + ',' + this.t.y + ') scale(' + this.t.k + ')');
        // semantic zoom: mid = short labels, far (macro view) = only mailboxes, packs and destinations are named
        this.svg.classList.toggle('zoom-mid', this.t.k < 0.7);
        this.svg.classList.toggle('zoom-far', this.t.k < 0.38);
        this.svg.classList.toggle('zoom-near', this.t.k >= 1.2);
        if (this.opts.onView) { this.opts.onView(this.t); }
    };

    View.prototype.world = function (clientX, clientY) {
        var r = this.svg.getBoundingClientRect();
        return [(clientX - r.left - this.t.x) / this.t.k, (clientY - r.top - this.t.y) / this.t.k];
    };

    View.prototype.zoomAt = function (factor, clientX, clientY) {
        var r = this.svg.getBoundingClientRect();
        var cx = clientX === undefined ? r.width / 2 : clientX - r.left;
        var cy = clientY === undefined ? r.height / 2 : clientY - r.top;
        var k = Math.min(2.5, Math.max(0.12, this.t.k * factor));
        this.t.x = cx - (cx - this.t.x) * (k / this.t.k);
        this.t.y = cy - (cy - this.t.y) * (k / this.t.k);
        this.t.k = k;
        this.setTransform();
    };

    /** Zoom so that the given blocks (all visible ones by default) fill the view: "overall view" / "zoom on a part". */
    View.prototype.fit = function (keys) {
        var nodes = (keys || this.model.list(this.visible.bind(this)).map(function (n) { return n.key; }))
            .map(function (k) { return this.model.nodes[k]; }, this).filter(Boolean);
        var box = L.bounds(this.model.pos, nodes);
        var r = this.svg.getBoundingClientRect();
        var pad = 60;
        var k = Math.min((r.width - pad * 2) / Math.max(1, box[2] - box[0]), (r.height - pad * 2) / Math.max(1, box[3] - box[1]), 1.4);
        this.t.k = Math.max(0.12, k);
        this.t.x = (r.width - (box[2] - box[0]) * this.t.k) / 2 - box[0] * this.t.k;
        this.t.y = (r.height - (box[3] - box[1]) * this.t.k) / 2 - box[1] * this.t.k;
        this.setTransform();
    };

    View.prototype.centerOn = function (x, y) {
        var r = this.svg.getBoundingClientRect();
        this.t.x = r.width / 2 - x * this.t.k;
        this.t.y = r.height / 2 - y * this.t.k;
        this.setTransform();
    };

    View.prototype.select = function (keys, edgeIndex) {
        this.selected = {};
        (keys || []).forEach(function (k) { this.selected[k] = true; }, this);
        this.selectedEdge = edgeIndex === undefined ? null : edgeIndex;
        this.highlight();
    };

    // ---------------------------------------------------------------------------------------- interactions

    View.prototype.bind = function () {
        var self = this;
        this.svg.addEventListener('pointerdown', function (e) { self.down(e); });
        this.svg.addEventListener('pointermove', function (e) { self.move(e); });
        this.svg.addEventListener('pointerup', function (e) { self.up(e); });
        this.svg.addEventListener('pointercancel', function () { self.cancel(); });
        this.svg.addEventListener('wheel', function (e) {
            e.preventDefault();
            self.zoomAt(Math.exp(-e.deltaY * 0.0015), e.clientX, e.clientY);
        }, { passive: false });
    };

    View.prototype.down = function (e) {
        if (e.button !== 0) { return; }
        var handle = e.target.closest('.wpg-handle');
        var grip = e.target.closest('.wpg-grip');
        var node = e.target.closest('.wpg-node');
        var edge = e.target.closest('.wpg-edge-hit');
        var w = this.world(e.clientX, e.clientY);
        this.svg.setPointerCapture(e.pointerId);
        if (handle && node) {
            this.mode = { type: 'connect', from: node.getAttribute('data-key') };
            this.svg.classList.add('connecting');
            return;
        }
        if (grip && node) {
            var rk = node.getAttribute('data-key');
            this.mode = { type: 'resize', key: rk, start: w, origin: L.size(this.model.nodes[rk]), moved: false, size: null };
            return;
        }
        if (node) {
            var key = node.getAttribute('data-key');
            if (e.shiftKey) {
                if (this.selected[key]) { delete this.selected[key]; } else { this.selected[key] = true; }
            } else if (!this.selected[key]) {
                this.selected = {};
                this.selected[key] = true;
            }
            this.selectedEdge = null;
            this.highlight();
            var keys = Object.keys(this.selected);
            this.mode = { type: 'drag', key: key, start: w, origin: this.model.snapshot(keys), keys: keys, moved: false };
            return;
        }
        if (edge) {
            this.mode = { type: 'edge', index: parseInt(edge.getAttribute('data-edge'), 10) };
            return;
        }
        if (e.shiftKey) {
            // Shift + drag on the background: selection rectangle (adds to the current selection)
            this.mode = { type: 'lasso', start: w, rect: el('rect', { class: 'wpg-lasso', x: w[0], y: w[1], width: 0, height: 0 }, this.vp) };
            return;
        }
        this.mode = { type: 'pan', start: [e.clientX, e.clientY], t0: { x: this.t.x, y: this.t.y }, moved: false };
        this.svg.classList.add('panning');
    };

    View.prototype.move = function (e) {
        var m = this.mode;
        if (!m) { return; }
        if (m.type === 'pan') {
            var dx = e.clientX - m.start[0], dy = e.clientY - m.start[1];
            m.moved = m.moved || Math.abs(dx) + Math.abs(dy) > 3;
            this.t.x = m.t0.x + dx;
            this.t.y = m.t0.y + dy;
            this.setTransform();
        } else if (m.type === 'drag') {
            var w = this.world(e.clientX, e.clientY);
            var ox = w[0] - m.start[0], oy = w[1] - m.start[1];
            if (!m.moved && Math.abs(ox) + Math.abs(oy) < 3) { return; }
            m.moved = true;
            var snap = e.altKey ? 1 : 10;
            m.keys.forEach(function (k) {
                var o = m.origin[k] || [0, 0];
                this.model.pos[k] = [Math.round((o[0] + ox) / snap) * snap, Math.round((o[1] + oy) / snap) * snap];
            }, this);
            this.moveNodes(m.keys);
        } else if (m.type === 'lasso') {
            var q = this.world(e.clientX, e.clientY);
            m.box = [Math.min(m.start[0], q[0]), Math.min(m.start[1], q[1]), Math.abs(q[0] - m.start[0]), Math.abs(q[1] - m.start[1])];
            m.rect.setAttribute('x', m.box[0]);
            m.rect.setAttribute('y', m.box[1]);
            m.rect.setAttribute('width', m.box[2]);
            m.rect.setAttribute('height', m.box[3]);
        } else if (m.type === 'resize') {
            var r = this.world(e.clientX, e.clientY);
            var rx = r[0] - m.start[0], ry = r[1] - m.start[1];
            if (!m.moved && Math.abs(rx) + Math.abs(ry) < 3) { return; }
            m.moved = true;
            m.size = [Math.round((m.origin[0] + rx) / 10) * 10, Math.round((m.origin[1] + ry) / 10) * 10];
            if (this.opts.onResize) { this.opts.onResize(m.key, m.size, false); }
        } else if (m.type === 'connect') {
            var from = this.model.nodes[m.from], p = this.model.pos[m.from], s = L.size(from);
            var to = this.world(e.clientX, e.clientY);
            this.ghost.setAttribute('d', 'M' + [p[0], p[1] + s[1] / 2] + 'L' + to);
            var target = this.targetAt(e.clientX, e.clientY);
            var ok = target && this.canConnect(m.from, target);
            this.ghost.classList.toggle('bad', !!target && !ok);
            Array.prototype.forEach.call(this.nodeLayer.querySelectorAll('.drop-ok, .drop-bad'), function (g) { g.classList.remove('drop-ok', 'drop-bad'); });
            if (target) {
                var g = this.nodeLayer.querySelector('[data-key="' + CSS.escape(target) + '"]');
                if (g) { g.classList.add(ok ? 'drop-ok' : 'drop-bad'); }
            }
        }
    };

    View.prototype.up = function (e) {
        var m = this.mode;
        this.mode = null;
        this.svg.classList.remove('panning', 'connecting');
        if (!m) { return; }
        if (m.type === 'pan' && !m.moved) {
            this.select([]);
            if (this.opts.onSelect) { this.opts.onSelect(null); }
        } else if (m.type === 'drag') {
            if (m.moved && this.opts.onMoved) {
                this.opts.onMoved(m.origin, this.model.snapshot(m.keys));
            } else if (!m.moved) {
                // double click detected here: with the pointer capture, the browser's dblclick targets the canvas
                var now = Date.now();
                var again = this.lastClick && this.lastClick.key === m.key && now - this.lastClick.at < 400;
                this.lastClick = again ? null : { key: m.key, at: now };
                if (again && this.opts.onOpen) {
                    this.opts.onOpen(m.key);
                } else if (this.opts.onSelect) {
                    this.opts.onSelect(m.key);
                }
            }
        } else if (m.type === 'lasso') {
            m.rect.parentNode.removeChild(m.rect);
            if (m.box && m.box[2] > 3 && m.box[3] > 3) {
                // every visible block touching the rectangle joins the selection
                this.model.list(this.visible.bind(this)).forEach(function (n) {
                    var p = this.model.pos[n.key], s = L.size(n);
                    if (p && p[0] < m.box[0] + m.box[2] && p[0] + s[0] > m.box[0] && p[1] < m.box[1] + m.box[3] && p[1] + s[1] > m.box[1]) {
                        this.selected[n.key] = true;
                    }
                }, this);
                this.selectedEdge = null;
                this.highlight();
                if (this.opts.onSelect) { this.opts.onSelect(Object.keys(this.selected)[0] || null); }
            }
        } else if (m.type === 'resize') {
            if (m.moved) {
                if (this.opts.onResize) { this.opts.onResize(m.key, m.size, true); }
            } else {
                // double click on the grip: back to the automatic size
                var now2 = Date.now();
                var twice = this.lastGrip && this.lastGrip.key === m.key && now2 - this.lastGrip.at < 400;
                this.lastGrip = twice ? null : { key: m.key, at: now2 };
                if (twice && this.opts.onResize) { this.opts.onResize(m.key, null, true); }
            }
        } else if (m.type === 'edge') {
            this.select([], m.index);
            if (this.opts.onEdge) { this.opts.onEdge(this.model.edges[m.index]); }
        } else if (m.type === 'connect') {
            this.ghost.setAttribute('d', '');
            Array.prototype.forEach.call(this.nodeLayer.querySelectorAll('.drop-ok, .drop-bad'), function (g) { g.classList.remove('drop-ok', 'drop-bad'); });
            var target = this.targetAt(e.clientX, e.clientY);
            if (target && this.canConnect(m.from, target) && this.opts.onConnect) {
                this.opts.onConnect(m.from, target);
            } else if (target && target !== m.from && this.opts.onRefused) {
                this.opts.onRefused(m.from, target);
            }
        }
    };

    View.prototype.cancel = function () {
        this.mode = null;
        this.ghost.setAttribute('d', '');
        this.svg.classList.remove('panning', 'connecting');
    };

    View.prototype.targetAt = function (x, y) {
        var hit = document.elementFromPoint(x, y);
        var g = hit && hit.closest ? hit.closest('.wpg-node') : null;
        return g ? g.getAttribute('data-key') : null;
    };

    /** Only "rule -> mailbox not linked yet" is allowed: what Workflows can represent (one rule = one mailbox). */
    View.prototype.canConnect = function (from, to) {
        var a = this.model.nodes[from], b = this.model.nodes[to];
        return !!a && !!b && a.kind === 'rule' && b.kind === 'mailbox' && !this.model.linked(from, to);
    };

    WPGraph.View = View;
})(window.WPGraph = window.WPGraph || {});
