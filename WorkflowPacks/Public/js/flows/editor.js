/*
 * Workflow Packs — editor of the flows of the internal engine (Workflow Packs › Flows). Each block is an object:
 * drag it, pull one of its outputs onto another block to link them, "+" on an output adds the next block. The side
 * panel is built from the catalog of the server (fields, outputs, variables: no type of block is written here). The
 * draft is saved as it goes; "Publish" freezes a version (checked on the server); "Simulate" plays a sample e-mail
 * through the flow (nothing written, nothing sent). Left button = move around, right button = choose blocks (one or
 * several, rectangle), fingers on phones and tablets, "Enlarge" for the whole page (details in bind()).
 */
(function (root) {
    'use strict';

    var NS = 'http://www.w3.org/2000/svg';
    var W = 230, H = 62, PORT_GAP = 24, GRID = 10;
    var MAX_EXAMPLES = 50;   // Blocks/Conditions/Similar::MAX_EXAMPLES
    var MIN_K = 0.08, MAX_K = 2, PAD = 24, LABEL = 30, SHELF_GAP = 70, SHELF_WIDTH = 2600;
    var OPS = {
        text: ['contains', 'not_contains', 'equal', 'not_equal', 'starts', 'ends', 'regex'],
        choice: ['is', 'is_not'],
        bool: ['yes', 'no'],
        number: ['gte', 'lte', 'eq']
    };
    var ICONS = { trigger: 'inbox', condition: 'branch', wait: 'hourglass', action: 'zap', util: 'dots',
        'action.status': 'closed', 'action.assign': 'person', 'action.note': 'note', 'action.imap_folder': 'folder' };

    /**
     * Variables a block can use: made by the blocks before it, on every path (partial: false) or some (partial: true).
     * Same fixed point as Services/Engine/Definition::variablesBefore, so the editor offers what publishing accepts.
     */
    function variablesBefore(draft, id, types) {
        var blocks = {}, preds = {}, made = {}, all = {}, any = {};
        (draft.blocks || []).forEach(function (b) {
            blocks[b.id] = b;
            made[b.id] = (types[b.type] && types[b.type].variables) || {};
            all[b.id] = null;
            any[b.id] = [];
        });
        (draft.links || []).forEach(function (l) {
            if (blocks[l.from] && blocks[l.to]) { (preds[l.to] = preds[l.to] || []).push(l.from); }
        });
        var union = function (a, b) { return a.concat(b.filter(function (x) { return a.indexOf(x) < 0; })); };
        for (var round = 0, changed = true; changed && round < 400; round++) {
            changed = false;
            Object.keys(blocks).forEach(function (bid) {
                var inAll = null, inAny = [];
                (preds[bid] || []).forEach(function (p) {
                    var out = Object.keys(made[p]);
                    if (all[p] !== null) {
                        var mine = union(all[p], out);
                        inAll = inAll === null ? mine : inAll.filter(function (x) { return mine.indexOf(x) >= 0; });
                    }
                    inAny = union(union(inAny, any[p]), out);
                });
                if (inAll === null && !(preds[bid] || []).length) { inAll = []; }
                if (inAll !== null && (all[bid] === null || inAll.length !== all[bid].length)) { all[bid] = inAll; changed = true; }
                if (inAny.length !== any[bid].length) { any[bid] = inAny; changed = true; }
            });
        }
        var defs = {};
        Object.keys(made).forEach(function (bid) { Object.keys(made[bid]).forEach(function (k) { defs[k] = made[bid][k]; }); });
        var out = {};
        (any[id] || []).forEach(function (k) {
            var d = defs[k] || { type: 'text' };
            out[k] = { type: d.type, values: d.values || null, partial: (all[id] || []).indexOf(k) < 0 };
        });
        return out;
    }

    // ------------------------------------------------------------------------------------------------- helpers

    function svg(tag, attrs, parent) {
        var n = document.createElementNS(NS, tag);
        Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
        if (parent) { parent.appendChild(n); }
        return n;
    }

    function h(tag, attrs, children) {
        var n = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') { n.textContent = attrs[k]; } else if (k === 'on') {
                Object.keys(attrs.on).forEach(function (ev) { n.addEventListener(ev, attrs.on[ev]); });
            } else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) { n.setAttribute(k, attrs[k] === true ? '' : attrs[k]); }
        });
        (children || []).forEach(function (c) { if (c) { n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
        return n;
    }

    function fill(text, params) {
        return String(text || '').replace(/:([a-z]+)/g, function (m, k) { return params && params[k] !== undefined ? params[k] : m; });
    }

    function cut(text, max) {
        text = String(text || '');
        return text.length > max ? text.slice(0, max - 1) + '…' : text;
    }

    function clone(o) { return JSON.parse(JSON.stringify(o)); }

    /**
     * Two fingers: the zoom follows their spacing and the point of the diagram under their middle at the start stays
     * under their middle. v0 = view at the start, m0/m = middles (px in the canvas), d0/d = spacings.
     */
    function pinchView(v0, m0, d0, m, d) {
        var k = Math.min(MAX_K, Math.max(MIN_K, v0.k * d / Math.max(1, d0)));
        var wx = (m0[0] - v0.x) / v0.k, wy = (m0[1] - v0.y) / v0.k;
        return { x: m[0] - wx * k, y: m[1] - wy * k, k: k };
    }

    /**
     * Flows side by side on the grid, in rows (like books on shelves): each box {id, x, y, w, h} (where its blocks are)
     * gets the shift [dx, dy] that puts it in its place. A row ends past maxWidth; gap between flows and between rows.
     */
    function shelf(boxes, maxWidth, gap) {
        var out = {}, x = 0, y = 0, row = 0;
        boxes.forEach(function (b) {
            if (x > 0 && x + b.w > maxWidth) {
                x = 0;
                y += row + gap;
                row = 0;
            }
            out[b.id] = [x - b.x, y - b.y];
            x += b.w + gap;
            row = Math.max(row, b.h);
        });
        return out;
    }

    /** Settings of this viewer kept in the browser (what is shown, sides folded); nothing when the browser refuses. */
    function pref(key, value) {
        try {
            var all = JSON.parse(root.localStorage.getItem('wpf.view') || '{}') || {};
            if (value === undefined) { return all[key]; }
            all[key] = value;
            root.localStorage.setItem('wpf.view', JSON.stringify(all));
        } catch (e) { /* private window, storage blocked */ }
        return undefined;
    }

    /** Phones and tablets held upright: the sides open over the grid (flows.css, same width). */
    function narrow() {
        return !!(root.matchMedia && root.matchMedia('(max-width: 1023px)').matches);
    }

    /** Ids of the blocks touching a rectangle drawn between two points of the diagram (any corner first). */
    function inRect(blocks, sizeOf, a, b) {
        var x1 = Math.min(a[0], b[0]), x2 = Math.max(a[0], b[0]), y1 = Math.min(a[1], b[1]), y2 = Math.max(a[1], b[1]);
        return blocks.filter(function (bl) {
            var s = sizeOf(bl);
            return bl.x < x2 && bl.x + s[0] > x1 && bl.y < y2 && bl.y + s[1] > y1;
        }).map(function (bl) { return bl.id; });
    }

    // -------------------------------------------------------------------------------------------------- editor

    function Editor(rootEl, cfg) {
        this.root = rootEl;
        this.cfg = cfg;
        this.t = cfg.texts;
        this.bt = cfg.blockText;
        this.types = {};
        var self = this;
        cfg.catalog.forEach(function (c) { self.types[c.type] = c; });
        this.view = { x: 40, y: 40, k: 1 };
        this.sel = null;
        this.flows = [];
        this.flow = null;
        this.checked = {};   // ids ticked in the list of the flows
        this.offsets = {};   // flow id -> [dx, dy]: where it sits on the grid
        this.show = ['all', 'checked', 'open'].indexOf(pref('show')) >= 0 ? pref('show') : 'all';
        this.q = function (s) { return rootEl.querySelector(s); };
        this.svg = this.q('.wpf-canvas');
        this.vp = this.q('.wpf-vp');
        this.act = this.q('.wpf-act');
        this.others = this.q('.wpf-others');
        this.panel = this.q('.wpf-panel');
        var defs = svg('defs', {}, this.svg);
        if (root.WPGraph && root.WPGraph.icons) { root.WPGraph.icons.define(defs); }
        this.bind();
        // the grid first: on a phone both sides start folded (they open over the grid), elsewhere as left last time
        this.q('.wpf-show').value = this.show;
        this.side('list', narrow() ? false : pref('list') !== false, true);
        this.side('panel', narrow() ? false : pref('panel') !== false, true);
        this.load(cfg.open || undefined);
    }

    Editor.prototype.request = function (url, data) {
        return fetch(url, {
            method: data === undefined ? 'GET' : 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.cfg.token },
            body: data === undefined ? undefined : JSON.stringify(data)
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, data: d }; });
        });
    };

    Editor.prototype.url = function (name) {
        return this.cfg.urls[name].replace('__ID__', this.flow ? this.flow.id : 0);
    };

    Editor.prototype.status = function (text, cls) {
        var s = this.q('.wpf-status');
        s.textContent = text || '';
        s.className = 'wpf-status' + (cls ? ' is-' + cls : '');
    };

    Editor.prototype.load = function (selectId) {
        var self = this;
        return this.request(this.cfg.urls.data).then(function (res) {
            self.flows = (res.data && res.data.flows) || [];
            self.flow = null;   // fresh from the server: the copy open before must not replace it
            var pick = self.flows.filter(function (f) { return f.id === selectId; })[0] || self.flows[0] || null;
            self.open(pick);
        });
    };

    Editor.prototype.open = function (flow) {
        // the flow left keeps its changes (drawn on the grid, opened again as it is)
        var left = this.flow;
        if (left) { this.flows = this.flows.map(function (f) { return f.id === left.id ? left : f; }); }
        this.flow = flow ? clone(flow) : null;
        this.sel = null;
        this.sim = null;
        var empty = !flow;
        this.q('.wpf-empty').hidden = !empty;
        Array.prototype.forEach.call(this.root.querySelectorAll('.wpf-toolbar [data-act]:not([data-act="new"]):not([data-act="fullscreen"]), .wpf-settings input, .wpf-settings [data-act]'), function (n) {
            n.disabled = empty;
        });
        if (flow) {
            this.q('.wpf-name').value = flow.name;
            this.q('.wpf-once').checked = flow.once;
            this.q('.wpf-active').checked = flow.active;
            this.boxes();
        }
        this.state();
        this.relayout();
        this.focus();
        this.showPanel();
        this.selbar();
    };

    /**
     * The list of every flow: the one open highlighted (its name, version and state as they are in the editor), a box to
     * tick each one, "All", and the deletion of the ticked ones.
     */
    Editor.prototype.renderList = function () {
        var self = this, t = this.t, ul = this.q('.wpf-list-items');
        var ids = this.flows.map(function (f) { return f.id; });
        Object.keys(this.checked).forEach(function (id) { if (ids.indexOf(parseInt(id, 10)) < 0) { delete self.checked[id]; } });
        ul.textContent = '';
        this.flows.forEach(function (f) {
            var current = !!self.flow && self.flow.id === f.id, src = current ? self.flow : f;
            var box = h('input', { type: 'checkbox', 'aria-label': src.name, checked: !!self.checked[f.id], on: { change: function () {
                if (this.checked) { self.checked[f.id] = true; } else { delete self.checked[f.id]; }
                self.listTools();
                if (self.show === 'checked') { self.relayout(); }
            } } });
            var note = (src.version ? fill(t.published, { n: src.version }) : t.unpublished) + ' · ' + (src.active ? t.active : t.inactive);
            ul.appendChild(h('li', { class: 'wpf-list-item' + (current ? ' is-current' : '') }, [box,
                h('button', { type: 'button', class: 'wpf-list-open', 'aria-current': current ? 'true' : null, on: { click: function () {
                    if (current) { return; }
                    self.flush().then(function () { self.open(self.flows.filter(function (x) { return x.id === f.id; })[0]); });
                } } }, [h('span', { class: 'wpf-list-name', text: src.name }), h('span', { class: 'wpf-list-note', text: note })])]));
        });
        this.q('.wpf-list-count').textContent = '(' + this.flows.length + ')';
        this.q('.wpf-settings-name').textContent = this.flow ? this.flow.name : '';
        this.listTools();
    };

    /** "All" ticked, half ticked or not; the delete button says how many. */
    Editor.prototype.listTools = function () {
        var n = Object.keys(this.checked).length, all = this.q('.wpf-list-all'), del = this.q('[data-act="delete-many"]');
        all.checked = n > 0 && n === this.flows.length;
        all.indeterminate = n > 0 && n < this.flows.length;
        all.disabled = !this.flows.length;
        del.disabled = !n;
        del.textContent = n ? fill(this.t.delete_many, { count: n }) : this.t.delete_many_none;
        // the ticked flows are the ones handled: "Publish (n)", switch on / off
        var pub = this.q('[data-act="publish"]');
        pub.textContent = n ? fill(this.t.publish_n, { count: n }) : this.t.publish;
        pub.title = n ? this.t.publish_n_help : '';
        // the actions on the ticked flows only show when some are ticked (the list keeps its room)
        Array.prototype.forEach.call(this.root.querySelectorAll('[data-act="bulk-on"], [data-act="bulk-off"], [data-act="delete-many"]'), function (b) {
            b.disabled = !n;
            b.hidden = !n;
        });
    };

    /** The ticked flows published, switched on or off together; the ones refused are named. */
    Editor.prototype.bulk = function (action) {
        var self = this, ids = Object.keys(this.checked).map(function (id) { return parseInt(id, 10); });
        if (!ids.length) { return; }
        var keep = this.flow ? this.flow.id : undefined;
        this.flush().then(function () { return self.request(self.cfg.urls.bulk, { action: action, ids: ids }); }).then(function (res) {
            var d = res.data || {};
            return self.load(keep).then(function () {
                var text = fill(self.t['bulk_' + action], { count: d.done || 0 });
                if (d.refused && d.refused.length) { text += ' ' + fill(self.t.bulk_refused, { list: d.refused.slice(0, 8).join(' · ') }); }
                self.status(text, res.ok && !(d.refused && d.refused.length) ? 'ok' : 'warn');
            });
        });
    };

    /** The ticked flows deleted together, after a confirmation; the flow open stays open when it is not one of them. */
    Editor.prototype.destroyMany = function () {
        var self = this, ids = Object.keys(this.checked).map(function (id) { return parseInt(id, 10); });
        if (!ids.length || !window.confirm(fill(this.t.delete_many_confirm, { count: ids.length }))) { return; }
        var keep = this.flow && ids.indexOf(this.flow.id) < 0 ? this.flow.id : undefined;
        this.flush().then(function () { return self.request(self.cfg.urls.destroy_many, { ids: ids }); }).then(function (res) {
            self.checked = {};
            return self.load(keep).then(function () {
                self.status(fill(self.t.deleted_many, { count: (res.data && res.data.deleted) || 0 }), res.ok ? 'ok' : 'error');
            });
        });
    };

    /** Version and problems in the toolbar. */
    Editor.prototype.state = function () {
        this.renderList();
        if (!this.flow) { this.status(''); return; }
        var f = this.flow, t = this.t;
        var parts = [f.version ? fill(t.published, { n: f.version }) : t.unpublished];
        if (f.dirty) { parts.push(t.changed); }
        if (f.waiting) { parts.push(fill(t.waiting, { n: f.waiting })); }
        parts.push(f.problems && f.problems.length ? fill(t.problems, { count: f.problems.length }) : t.no_problem);
        this.status(parts.join(' · '), f.problems && f.problems.length ? 'warn' : '');
    };

    Editor.prototype.boxes = function () {
        var self = this, list = this.q('.wpf-boxes-list'), ids = this.flow.mailbox_ids;
        list.textContent = '';
        this.cfg.mailboxes.forEach(function (m) {
            var box = h('input', { type: 'checkbox', value: m.id, checked: ids.indexOf(m.id) >= 0, on: { change: function () {
                self.flow.mailbox_ids = Array.prototype.map.call(list.querySelectorAll('input:checked'), function (i) { return parseInt(i.value, 10); });
                self.boxLabel();
                self.save();
            } } });
            list.appendChild(h('label', { class: 'wpf-check' }, [box, ' ' + m.name]));
        });
        this.boxLabel();
    };

    Editor.prototype.boxLabel = function () {
        var ids = this.flow.mailbox_ids, names = this.cfg.mailboxes.filter(function (m) { return ids.indexOf(m.id) >= 0; }).map(function (m) { return m.name; });
        this.q('.wpf-boxes-label').textContent = !names.length ? this.t.all_mailboxes : names.slice(0, 2).join(', ') + (names.length > 2 ? ' +' + (names.length - 2) : '');
    };

    // --------------------------------------------------------------------------------------------- drawing

    Editor.prototype.block = function (id) {
        return this.flow ? this.flow.draft.blocks.filter(function (b) { return b.id === id; })[0] : null;
    };

    Editor.prototype.outputsOf = function (b) {
        return (this.types[b.type] && this.types[b.type].outputs) || [{ key: 'next', label: '' }];
    };

    Editor.prototype.size = function (b) {
        return [W, Math.max(H, 18 + this.outputsOf(b).length * PORT_GAP)];
    };

    Editor.prototype.portY = function (b, port) {
        var outs = this.outputsOf(b).map(function (o) { return o.key; });
        var i = Math.max(0, outs.indexOf(port));
        return outs.length === 1 ? this.size(b)[1] / 2 : 20 + i * PORT_GAP;
    };

    Editor.prototype.problemsOf = function (id) {
        return ((this.flow && this.flow.problems) || []).filter(function (p) { return p.block === id; });
    };

    /** Where the blocks of a draft are, with room for the frame and the name of the flow above. */
    Editor.prototype.box = function (draft) {
        var self = this, b = [Infinity, Infinity, -Infinity, -Infinity];
        (draft.blocks || []).forEach(function (bl) {
            var sz = self.size(bl);
            b = [Math.min(b[0], bl.x || 0), Math.min(b[1], bl.y || 0), Math.max(b[2], (bl.x || 0) + sz[0] + 40), Math.max(b[3], (bl.y || 0) + sz[1])];
        });
        if (b[0] === Infinity) { b = [0, 0, W, H]; }
        return { x: b[0] - PAD, y: b[1] - PAD - LABEL, w: b[2] - b[0] + PAD * 2, h: b[3] - b[1] + PAD * 2 + LABEL };
    };

    /** The flows shown: all, the ticked ones or the open one only; the open one always, as it is being edited. */
    Editor.prototype.visible = function () {
        var self = this, open = this.flow ? this.flow.id : null;
        return this.flows.filter(function (f) {
            return f.id === open || self.show === 'all' || (self.show === 'checked' && self.checked[f.id]);
        }).map(function (f) { return f.id === open ? self.flow : f; });
    };

    /** Each flow shown gets its place on the grid; the open one stays where it is on the screen. */
    Editor.prototype.relayout = function () {
        var self = this, before = this.offset(), list = this.visible();
        var boxes = list.map(function (f) { var b = self.box(f.draft || {}); b.id = f.id; return b; });
        // rows about as wide as the grid is wide compared to its height: "Fit all" fills the screen
        var r = this.svg.getBoundingClientRect(), aspect = r.width && r.height ? r.width / r.height : 1.6;
        var area = boxes.reduce(function (m, b) { return m + (b.w + SHELF_GAP) * (b.h + SHELF_GAP); }, 0);
        var widest = boxes.reduce(function (m, b) { return Math.max(m, b.w); }, 0);
        this.offsets = shelf(boxes, Math.max(widest, Math.min(SHELF_WIDTH * 3, Math.sqrt(area * aspect))), SHELF_GAP);
        var after = this.offset();
        this.view.x += (before[0] - after[0]) * this.view.k;
        this.view.y += (before[1] - after[1]) * this.view.k;
        // the other flows: drawn once here (not at each move of the open one)
        this.others.textContent = '';
        list.forEach(function (f) {
            if (self.flow && f.id === self.flow.id) { return; }
            var o = self.offsets[f.id] || [0, 0];
            var g = svg('g', { class: 'wpf-other', 'data-flow': f.id, transform: 'translate(' + o[0] + ',' + o[1] + ')' }, self.others);
            self.frame(g, f, false);
            self.drawFlow(f, svg('g', {}, g), svg('g', {}, g), false);
        });
        this.render();
    };

    Editor.prototype.offset = function () {
        return (this.flow && this.offsets[this.flow.id]) || [0, 0];
    };

    /** The frame of a flow and its name above it (a click on the name of another flow opens it). */
    Editor.prototype.frame = function (parent, f, open) {
        var b = this.box(f.draft || {});
        svg('rect', { class: 'wpf-frame' + (open ? ' is-open' : ''), x: b.x, y: b.y, width: b.w, height: b.h, rx: 16 }, parent);
        var label = svg('text', { class: 'wpf-frame-name', x: b.x + 14, y: b.y + 22 }, parent);
        label.textContent = cut(f.name, 60) + (f.active ? '  ●' : '');
    };

    Editor.prototype.render = function () {
        var frame = this.q('.wpf-frames'), links = this.q('.wpf-links'), blocks = this.q('.wpf-blocks');
        frame.textContent = '';
        links.textContent = '';
        blocks.textContent = '';
        var o = this.offset();
        this.act.setAttribute('transform', 'translate(' + o[0] + ',' + o[1] + ')');
        this.setTransform();
        if (!this.flow) { return; }
        this.frame(frame, this.flow, true);
        this.drawFlow(this.flow, links, blocks, true);
    };

    /** The blocks and links of a flow; live = the open one (selection, simulation, problems, ports to pull). */
    Editor.prototype.drawFlow = function (f, links, blocks, live) {
        var self = this, draft = f.draft || { blocks: [], links: [] }, picked = live ? this.picked() : [];
        var byId = {};
        (draft.blocks || []).forEach(function (b) { byId[b.id] = b; });
        (draft.links || []).forEach(function (l, i) {
            var a = byId[l.from], b = byId[l.to];
            if (!a || !b) { return; }
            var s = [a.x + W, a.y + self.portY(a, l.port)], e = [b.x, b.y + self.size(b)[1] / 2];
            var dx = Math.max(40, Math.abs(e[0] - s[0]) / 2);
            var d = 'M' + s + 'C' + [s[0] + dx, s[1]] + ' ' + [e[0] - dx, e[1]] + ' ' + e;
            var sel = live && self.sel && self.sel.link === i;
            var g = svg('g', live ? { class: 'wpf-link port-' + l.port + (sel ? ' is-selected' : '') + (self.sim && self.sim.links[i] ? ' is-sim' : ''), 'data-link': i }
                : { class: 'wpf-link port-' + l.port }, links);
            if (live) { svg('path', { class: 'wpf-link-hit', d: d }, g); }
            svg('path', { class: 'wpf-link-line', d: d }, g);
            if (l.port !== 'next') {
                var t = svg('text', { class: 'wpf-link-label', x: (s[0] + e[0]) / 2, y: (s[1] + e[1]) / 2 - 4 }, g);
                t.textContent = self.bt.outputs[l.port] || l.port;
            }
        });
        (draft.blocks || []).forEach(function (b) {
            var type = self.types[b.type] || { family: 'util', name: b.type, outputs: [] };
            var size = self.size(b);
            var cls = 'wpf-block fam-' + type.family;
            if (live) {
                cls += (picked.indexOf(b.id) >= 0 ? ' is-selected' : '') + (self.problemsOf(b.id).length ? ' has-problem' : '')
                    + (self.sim && self.sim.on === b.id ? ' is-sim' : '') + (self.sim && self.sim.seen[b.id] ? ' was-sim' : '');
            }
            var g = svg('g', { class: cls, transform: 'translate(' + b.x + ',' + b.y + ')', 'data-block': b.id }, blocks);
            svg('rect', { class: 'wpf-card', width: size[0], height: size[1], rx: 10 }, g);
            svg('rect', { class: 'wpf-stripe', width: 5, height: size[1], rx: 2 }, g);
            var use = svg('use', { href: '#wpg-i-' + (ICONS[b.type] || ICONS[type.family] || 'dots'), x: 14, y: size[1] / 2 - 11, width: 22, height: 22, class: 'wpf-icon' }, g);
            use.setAttributeNS('http://www.w3.org/1999/xlink', 'href', use.getAttribute('href'));
            var name = svg('text', { class: 'wpf-kind', x: 46, y: 22 }, g);
            name.textContent = cut(type.name, 26);
            var title = svg('text', { class: 'wpf-title', x: 46, y: 41 }, g);
            title.textContent = cut((f.titles || {})[b.id] || type.name, 24);
            if (type.family !== 'trigger') {
                svg('circle', { class: 'wpf-in', cx: 0, cy: size[1] / 2, r: 5 }, g);
            }
            self.outputsOf(b).forEach(function (o) {
                var py = self.portY(b, o.key);
                if (self.outputsOf(b).length > 1) {
                    var lt = svg('text', { class: 'wpf-port-label', x: W - 12, y: py + 4 }, g);
                    lt.textContent = o.label;
                }
                if (!live) {
                    svg('circle', { class: 'wpf-port port-' + o.key, cx: W, cy: py, r: 7 }, g);
                    return;
                }
                // invisible larger circles: easier to catch, with a finger too
                var port = svg('g', { 'data-port': o.key }, g);
                svg('circle', { class: 'wpf-hit', cx: W, cy: py, r: 12 }, port);
                svg('circle', { class: 'wpf-port port-' + o.key, cx: W, cy: py, r: 7 }, port);
                var plus = svg('g', { class: 'wpf-plus', 'data-plus': o.key, transform: 'translate(' + (W + 22) + ',' + py + ')' }, g);
                svg('circle', { class: 'wpf-hit', r: 12 }, plus);
                svg('circle', { r: 9 }, plus);
                svg('path', { d: 'M-4 0H4M0 -4V4' }, plus);
            });
        });
    };

    Editor.prototype.setTransform = function () {
        this.vp.setAttribute('transform', 'translate(' + this.view.x + ',' + this.view.y + ') scale(' + this.view.k + ')');
    };

    /** The view on a rectangle of the grid (zoom from MIN_K to 1.2). */
    Editor.prototype.look = function (x, y, w, h) {
        var r = this.svg.getBoundingClientRect(), pad = 30;
        if (!r.width || !r.height) { return; }
        var k = Math.min(1.2, (r.width - pad * 2) / Math.max(1, w), (r.height - pad * 2) / Math.max(1, h));
        this.view.k = Math.max(MIN_K, k);
        this.view.x = (r.width - w * this.view.k) / 2 - x * this.view.k;
        this.view.y = (r.height - h * this.view.k) / 2 - y * this.view.k;
        this.setTransform();
    };

    /** "Fit all": every flow shown. */
    Editor.prototype.fit = function () {
        var self = this, b = [Infinity, Infinity, -Infinity, -Infinity];
        this.visible().forEach(function (f) {
            var x = self.box(f.draft || {}), o = self.offsets[f.id] || [0, 0];
            b = [Math.min(b[0], x.x + o[0]), Math.min(b[1], x.y + o[1]), Math.max(b[2], x.x + o[0] + x.w), Math.max(b[3], x.y + o[1] + x.h)];
        });
        if (b[0] !== Infinity) { this.look(b[0], b[1], b[2] - b[0], b[3] - b[1]); }
    };

    /** The open flow in the middle of the grid. */
    Editor.prototype.focus = function () {
        if (!this.flow) { return; }
        var x = this.box(this.flow.draft), o = this.offset();
        this.look(x.x + o[0], x.y + o[1], x.w, x.h);
    };

    /** A point of the screen, in the coordinates of the open flow. */
    Editor.prototype.world = function (e) {
        var r = this.svg.getBoundingClientRect(), o = this.offset();
        return [(e.clientX - r.left - this.view.x) / this.view.k - o[0], (e.clientY - r.top - this.view.y) / this.view.k - o[1]];
    };

    /** A side folded or unfolded (kept for next time, except on a phone where they open over the grid). */
    Editor.prototype.side = function (name, on, quiet) {
        this.root.classList.toggle(name + '-off', !on);
        var edge = this.q('.wpf-edge-' + name);
        if (edge) { edge.setAttribute('aria-expanded', on ? 'true' : 'false'); }
        if (!quiet && !narrow()) { pref(name, on); }
        this.attention();
    };

    /** The settings folded while a block is chosen: their button on the grid says so. */
    Editor.prototype.attention = function () {
        var edge = this.q('.wpf-edge-panel');
        if (edge) { edge.classList.toggle('is-attn', !!(this.sel && this.root.classList.contains('panel-off'))); }
    };

    // ------------------------------------------------------------------------------------------ interactions

    Editor.prototype.bind = function () {
        var self = this, mode = null;
        this.q('.wpf-list-all').addEventListener('change', function () {
            var on = this.checked;
            self.checked = {};
            if (on) { self.flows.forEach(function (f) { self.checked[f.id] = true; }); }
            self.renderList();
            if (self.show === 'checked') { self.relayout(); }
        });
        this.q('.wpf-show').addEventListener('change', function () {
            self.show = this.value;
            pref('show', self.show);
            self.relayout();
            self.fit();
        });
        this.q('.wpf-name').addEventListener('input', function () { self.flow.name = this.value; self.save(); self.renderList(); });
        this.q('.wpf-once').addEventListener('change', function () { self.flow.once = this.checked; self.save(); });
        this.q('.wpf-active').addEventListener('change', function () { self.toggle(this.checked); });
        this.root.addEventListener('click', function (e) {
            var act = e.target.closest && e.target.closest('[data-act]');
            if (!act || act.disabled) { return; }
            var name = act.getAttribute('data-act');
            if (name === 'new') { self.create(); }
            if (name === 'publish') { if (Object.keys(self.checked).length) { self.bulk('publish'); } else { self.publish(); } }
            if (name === 'bulk-on') { self.bulk('activate'); }
            if (name === 'delete-sel') { self.removeSelection(); }
            if (name === 'clear-sel') { self.select(null); }
            if (name === 'settings-sel') { self.side('panel', true); self.selbar(); }
            if (name === 'bulk-off') { self.bulk('deactivate'); }
            if (name === 'simulate') { self.showSim(); }
            if (name === 'fit') { self.relayout(); self.fit(); }
            if (name === 'toggle-list') { self.side('list', self.root.classList.contains('list-off')); }
            if (name === 'toggle-panel') { self.side('panel', self.root.classList.contains('panel-off')); }
            if (name === 'fullscreen') { self.fullPage(); }
            if (name === 'add') { self.menu(act, null, null); }
            if (name === 'delete-flow') { self.destroy(); }
            if (name === 'delete-many') { self.destroyMany(); }
            if (name === 'reapply' && self.flow) { self.flush().then(function () { window.location.href = self.url('reapply'); }); }
        });
        document.addEventListener('keydown', function (e) {
            var typing = /INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName);
            if ((e.key === 'Delete' || e.key === 'Backspace') && self.sel && !typing) {
                e.preventDefault();
                self.removeSelection();
            }
            if ((e.ctrlKey || e.metaKey) && String(e.key).toLowerCase() === 'a' && self.flow && !typing) {
                e.preventDefault();
                self.pick(self.flow.draft.blocks.map(function (b) { return b.id; }));
            }
            // Escape: first the menu, then the selection, then the full page
            if (e.key === 'Escape') {
                if (self.openMenu) { self.closeMenu(); } else if (self.sel && !typing) { self.select(null); } else if (self.root.classList.contains('is-full')) { self.fullPage(false); }
            }
        });
        this.svg.addEventListener('wheel', function (e) {
            e.preventDefault();
            var r = self.svg.getBoundingClientRect(), mx = e.clientX - r.left, my = e.clientY - r.top;
            var k = Math.min(MAX_K, Math.max(MIN_K, self.view.k * (e.deltaY < 0 ? 1.1 : 0.9)));
            self.view.x = mx - (mx - self.view.x) * k / self.view.k;
            self.view.y = my - (my - self.view.y) * k / self.view.k;
            self.view.k = k;
            self.setTransform();
        }, { passive: false });
        /*
         * Mouse: left button = move around (background) or move the block(s) · right button = choose blocks: a click
         * adds or removes one, a drag on the background draws a rectangle (Shift / Ctrl: added to the selection) ·
         * middle button = move around · Shift / Ctrl + left click = add or remove a block.
         * Finger: one = move around or move the block(s) · two = zoom and move · long press = choose: on a block it is
         * added or removed, on the background then slide = rectangle · tap on the background = nothing selected.
         */
        this.svg.addEventListener('contextmenu', function (e) { e.preventDefault(); });
        var touches = {}, press = null;
        var dropPress = function () { if (press) { clearTimeout(press); press = null; } };
        var local = function (x, y) { var r = self.svg.getBoundingClientRect(); return [x - r.left, y - r.top]; };
        var fingers = function () { var ids = Object.keys(touches); return ids.length >= 2 ? [touches[ids[0]], touches[ids[1]]] : null; };
        var middle = function (p) { return local((p[0][0] + p[1][0]) / 2, (p[0][1] + p[1][1]) / 2); };
        var spacing = function (p) { return Math.sqrt(Math.pow(p[0][0] - p[1][0], 2) + Math.pow(p[0][1] - p[1][1], 2)); };
        var far = function (e, m) { return Math.abs(e.clientX - m.sx) + Math.abs(e.clientY - m.sy) > (e.pointerType === 'touch' ? 8 : 4); };
        var pan = function (e) { return { type: 'pan', sx: e.clientX, sy: e.clientY, vx: self.view.x, vy: self.view.y, moved: false }; };
        var drag = function (e, id) {
            var ids = self.picked().indexOf(id) >= 0 ? self.picked() : [id];
            return { type: 'drag', id: id, sx: e.clientX, sy: e.clientY, start: self.world(e), moved: false,
                from: ids.map(function (k) { var b = self.block(k); return [b, b.x, b.y]; }).filter(function (f) { return f[0]; }) };
        };
        var lasso = function (e, add) {
            var w = self.world(e);
            return { type: 'lasso', sx: e.clientX, sy: e.clientY, start: w, moved: false, base: add ? self.picked() : [],
                rect: svg('rect', { class: 'wpf-lasso', x: w[0], y: w[1], width: 0, height: 0 }, self.act) };
        };
        var drop = function (m) {
            [m && m.rect, m && m.temp].forEach(function (n) { if (n && n.parentNode) { n.parentNode.removeChild(n); } });
        };
        this.svg.addEventListener('pointerdown', function (e) {
            if (!self.flow) { return; }
            self.closeMenu();
            var touch = e.pointerType === 'touch';
            try { self.svg.setPointerCapture(e.pointerId); } catch (err) { /* old browser */ }
            if (touch) {
                touches[e.pointerId] = [e.clientX, e.clientY];
                var p = fingers();
                if (p) {
                    // second finger: zoom and move with both; what the first one was doing stops there
                    dropPress();
                    if (mode && mode.type === 'drag' && mode.moved) { self.save(); }
                    drop(mode);
                    mode = { type: 'pinch', v0: { x: self.view.x, y: self.view.y, k: self.view.k }, m0: middle(p), d0: spacing(p) };
                    return;
                }
            }
            // another flow on the grid: the view moves as on the background; a click (no move) opens it
            var otherEl = e.target.closest('.wpf-other');
            if (otherEl) {
                var inner = e.target.closest('[data-block]');
                mode = e.button === 0 || e.button === 1 || touch ? pan(e) : null;
                if (mode) {
                    mode.other = parseInt(otherEl.getAttribute('data-flow'), 10);
                    mode.otherBlock = inner ? inner.getAttribute('data-block') : null;
                }
                return;
            }
            var plus = e.target.closest('[data-plus]'), port = e.target.closest('[data-port]');
            var blockEl = e.target.closest('[data-block]'), linkEl = e.target.closest('[data-link]');
            var id = blockEl ? blockEl.getAttribute('data-block') : null, extra = e.shiftKey || e.ctrlKey || e.metaKey;
            var before = self.picked();
            if (e.button === 1) {
                mode = pan(e);
            } else if (e.button === 2) {
                if (id) {
                    self.togglePick(id);
                    mode = null;
                } else if (linkEl) {
                    self.select({ link: parseInt(linkEl.getAttribute('data-link'), 10) });
                    mode = null;
                } else {
                    mode = lasso(e, extra);
                }
            } else if (plus) {
                mode = { type: 'plus', from: id, port: plus.getAttribute('data-plus') };
            } else if (port) {
                mode = { type: 'link', from: id, port: port.getAttribute('data-port'), temp: svg('path', { class: 'wpf-temp' }, self.q('.wpf-links')) };
            } else if (id && extra) {
                self.togglePick(id);
                mode = null;
            } else if (id) {
                if (before.indexOf(id) < 0) { self.select({ block: id }); }
                mode = drag(e, id);
            } else if (linkEl) {
                self.select({ link: parseInt(linkEl.getAttribute('data-link'), 10) });
                mode = null;
            } else {
                mode = pan(e);
            }
            if (touch && mode && (mode.type === 'pan' || mode.type === 'drag')) {
                var at = { clientX: e.clientX, clientY: e.clientY }, started = mode;
                press = setTimeout(function () {
                    press = null;
                    if (mode !== started || mode.moved) { return; }
                    if (started.type === 'drag') {
                        // long press on a block: added to (or removed from) the blocks chosen before
                        var ids = before.slice(), i = ids.indexOf(id);
                        if (i >= 0) { ids.splice(i, 1); } else { ids.push(id); }
                        mode = null;
                        self.pick(ids);
                    } else {
                        mode = lasso(at, false);
                    }
                    if (navigator.vibrate) { try { navigator.vibrate(15); } catch (err) { /* not allowed */ } }
                }, 500);
            }
        });
        this.svg.addEventListener('pointermove', function (e) {
            if (touches[e.pointerId]) { touches[e.pointerId] = [e.clientX, e.clientY]; }
            if (!mode) { return; }
            if (mode.type === 'pinch') {
                var p = fingers();
                if (p) {
                    self.view = pinchView(mode.v0, mode.m0, mode.d0, middle(p), spacing(p));
                    self.setTransform();
                }
                return;
            }
            var w = self.world(e);
            if (mode.sx !== undefined && !mode.moved && far(e, mode)) {
                mode.moved = true;
                dropPress();
            }
            if (mode.type === 'drag' && mode.moved) {
                var dx = Math.round((w[0] - mode.start[0]) / GRID) * GRID, dy = Math.round((w[1] - mode.start[1]) / GRID) * GRID;
                mode.from.forEach(function (f) { f[0].x = f[1] + dx; f[0].y = f[2] + dy; });
                self.render();
            } else if (mode.type === 'pan') {
                self.view.x = mode.vx + e.clientX - mode.sx;
                self.view.y = mode.vy + e.clientY - mode.sy;
                self.setTransform();
            } else if (mode.type === 'lasso') {
                mode.rect.setAttribute('x', Math.min(mode.start[0], w[0]));
                mode.rect.setAttribute('y', Math.min(mode.start[1], w[1]));
                mode.rect.setAttribute('width', Math.abs(w[0] - mode.start[0]));
                mode.rect.setAttribute('height', Math.abs(w[1] - mode.start[1]));
            } else if (mode.type === 'link') {
                var a = self.block(mode.from), s = [a.x + W, a.y + self.portY(a, mode.port)];
                mode.temp.setAttribute('d', 'M' + s + 'L' + w);
            }
        });
        var end = function (e) {
            delete touches[e.pointerId];
            dropPress();
            if (!mode) { return; }
            if (mode.type === 'pinch') {
                // the finger left does nothing until it is lifted too
                if (!fingers()) { mode = Object.keys(touches).length ? { type: 'idle' } : null; }
                return;
            }
            var m = mode;
            mode = null;
            drop(m);
            if (m.type === 'drag' && m.moved) { self.save(); }
            // a click on one of several chosen blocks: that one only
            if (m.type === 'drag' && !m.moved && m.from.length > 1) { self.select({ block: m.id }); }
            if (m.type === 'pan' && !m.moved && m.other) {
                self.openOther(m.other, m.otherBlock);
            } else if (m.type === 'pan' && !m.moved) {
                self.select(null);
            }
            if (m.type === 'lasso') {
                if (m.moved) {
                    self.pick(m.base.concat(inRect(self.flow.draft.blocks, self.size.bind(self), m.start, self.world(e))));
                } else {
                    self.select(null);
                }
            }
            if (m.type === 'plus') { self.menu(null, m.from, m.port, e); }
            if (m.type === 'link') {
                var under = document.elementFromPoint(e.clientX, e.clientY);
                var target = under && under.closest ? under.closest('[data-block]') : null;
                if (target) { self.link(m.from, m.port, target.getAttribute('data-block')); }
            }
        };
        this.svg.addEventListener('pointerup', end);
        this.svg.addEventListener('pointercancel', function (e) {
            delete touches[e.pointerId];
            dropPress();
            drop(mode);
            mode = null;
            self.render();
        });
        window.addEventListener('resize', function () { self.setTransform(); });
    };

    /** Blocks chosen: none, one (its settings in the panel) or several (moved and deleted together). */
    Editor.prototype.picked = function () {
        var s = this.sel;
        return !s ? [] : s.blocks ? s.blocks.slice() : s.block ? [s.block] : [];
    };

    Editor.prototype.pick = function (ids) {
        ids = ids.filter(function (id, i) { return ids.indexOf(id) === i; });
        this.select(!ids.length ? null : ids.length === 1 ? { block: ids[0] } : { blocks: ids });
    };

    Editor.prototype.togglePick = function (id) {
        var ids = this.picked(), i = ids.indexOf(id);
        if (i >= 0) { ids.splice(i, 1); } else { ids.push(id); }
        this.pick(ids);
    };

    /** "Enlarge": the editor over the whole page; "Reduce" (or Escape) back. */
    Editor.prototype.fullPage = function (on) {
        var full = on === undefined ? !this.root.classList.contains('is-full') : !!on;
        this.root.classList.toggle('is-full', full);
        document.body.classList.toggle('wpf-full-open', full);
        var button = this.q('[data-act="fullscreen"]');
        if (button) {
            button.textContent = full ? this.t.reduce : this.t.enlarge;
            button.setAttribute('aria-pressed', full ? 'true' : 'false');
        }
        var self = this;
        window.requestAnimationFrame(function () {
            var ids = self.picked();
            if (ids.length === 1) { self.reveal(self.block(ids[0])); } else { self.focus(); }
        });
    };

    Editor.prototype.select = function (sel) {
        this.sel = sel;
        this.render();
        this.showPanel();
        this.attention();
        this.selbar();
    };

    /**
     * A bar over the grid while something is chosen: delete it, open its settings. With a finger (a long press chooses)
     * this is how a block is deleted; with a mouse, Del does the same.
     */
    Editor.prototype.selbar = function () {
        var bar = this.q('.wpf-selbar'), t = this.t, sel = this.sel;
        if (!sel || this.sim) { bar.hidden = true; return; }
        var self = this, n = sel.link !== undefined ? 1 : this.picked().length;
        var deletable = sel.link !== undefined || this.picked().some(function (id) { var b = self.block(id); return b && (self.types[b.type] || {}).family !== 'trigger'; });
        bar.hidden = false;
        bar.querySelector('.wpf-selbar-count').textContent = sel.link !== undefined ? t.sel_link : fill(t.sel_blocks, { count: n });
        bar.querySelector('[data-act="delete-sel"]').disabled = !deletable;
        bar.querySelector('[data-act="settings-sel"]').hidden = !this.root.classList.contains('panel-off');
    };

    /** A flow clicked on the grid: opened where it is, the block clicked chosen. */
    Editor.prototype.openOther = function (id, blockId) {
        var self = this, flow = this.flows.filter(function (f) { return f.id === id; })[0];
        if (!flow) { return; }
        var keep = { x: this.view.x, y: this.view.y, k: this.view.k };
        this.flush().then(function () {
            self.open(flow);
            // no jump: the grid stays as it was, the flow clicked is now the one being edited
            self.view = keep;
            self.setTransform();
            if (blockId && self.block(blockId)) { self.select({ block: blockId }); }
        });
    };

    Editor.prototype.link = function (from, port, to) {
        var target = this.block(to);
        if (!target || from === to || (this.types[target.type] || {}).family === 'trigger') { return; }
        var links = this.flow.draft.links.filter(function (l) { return !(l.from === from && l.port === port); });
        links.push({ from: from, port: port, to: to });
        this.flow.draft.links = links;
        this.render();
        this.save();
    };

    Editor.prototype.removeSelection = function () {
        var sel = this.sel, draft = this.flow.draft, self = this;
        if (!sel) { return; }
        if (sel.link !== undefined) {
            draft.links.splice(sel.link, 1);
        } else {
            // the chosen blocks, except the trigger (the starting point stays)
            var ids = this.picked().filter(function (id) {
                var b = self.block(id);
                return b && (self.types[b.type] || {}).family !== 'trigger';
            });
            if (!ids.length) { this.status(this.t.trigger_fixed, 'warn'); return; }
            draft.blocks = draft.blocks.filter(function (x) { return ids.indexOf(x.id) < 0; });
            draft.links = draft.links.filter(function (l) { return ids.indexOf(l.from) < 0 && ids.indexOf(l.to) < 0; });
        }
        this.select(null);
        this.save();
    };

    /** Menu of the blocks to add (after an output, or free), grouped by family. */
    Editor.prototype.menu = function (button, from, port, e) {
        var self = this;
        this.closeMenu();
        var groups = {};
        this.cfg.catalog.forEach(function (c) {
            if (c.family !== 'trigger') { (groups[c.family] = groups[c.family] || []).push(c); }
        });
        var menu = h('div', { class: 'wpf-menu', role: 'menu' });
        if (from) {
            menu.appendChild(h('div', { class: 'wpf-menu-head', text: fill(this.t.add_after, { port: this.bt.outputs[port] || port }) }));
        }
        Object.keys(groups).forEach(function (family) {
            menu.appendChild(h('div', { class: 'wpf-menu-group', text: self.bt.families[family] || family }));
            groups[family].forEach(function (c) {
                menu.appendChild(h('button', { type: 'button', class: 'wpf-menu-item', title: c.help, on: { click: function () {
                    // a finger: the click the browser sends after the tap lands on the menu just opened under it
                    if (Date.now() - self.menuAt < 450) { return; }
                    self.closeMenu();
                    self.add(c.type, from, port);
                } } }, [c.name]));
            });
        });
        document.body.appendChild(menu);
        this.menuAt = Date.now();
        var r = button ? button.getBoundingClientRect() : { left: e.clientX, bottom: e.clientY };
        var mr = menu.getBoundingClientRect();
        menu.style.left = Math.max(8, Math.min(r.left, window.innerWidth - mr.width - 8)) + 'px';
        menu.style.top = Math.max(8, Math.min(r.bottom + 4, window.innerHeight - mr.height - 8)) + 'px';
        this.openMenu = menu;
    };

    Editor.prototype.closeMenu = function () {
        if (this.openMenu && this.openMenu.parentNode) { this.openMenu.parentNode.removeChild(this.openMenu); }
        this.openMenu = null;
    };

    /** New block, with the defaults of its fields; after an output: on its right, linked, on a free place. */
    Editor.prototype.add = function (type, from, port) {
        var c = this.types[type], draft = this.flow.draft, self = this;
        var n = 1;
        draft.blocks.forEach(function (b) { var m = /^b(\d+)$/.exec(b.id); if (m) { n = Math.max(n, parseInt(m[1], 10) + 1); } });
        var params = {};
        Object.keys(c.fields || {}).forEach(function (k) { if (c.fields[k].default !== undefined) { params[k] = clone(c.fields[k].default); } });
        var src = from ? this.block(from) : null;
        var pos = src ? [src.x + W + 80, src.y + (this.portY(src, port) - this.size(src)[1] / 2)] : this.centre();
        var b = { id: 'b' + n, type: type, params: params, x: Math.round(pos[0] / GRID) * GRID, y: Math.round(pos[1] / GRID) * GRID };
        var size = this.size(b);
        for (var i = 0; i < 50 && draft.blocks.some(function (o) {
            var s = self.size(o);
            return b.x < o.x + s[0] + 30 && o.x < b.x + size[0] + 30 && b.y < o.y + s[1] + 20 && o.y < b.y + size[1] + 20;
        }); i++) { b.y += size[1] + 24; }
        draft.blocks.push(b);
        if (src) { draft.links = draft.links.filter(function (l) { return !(l.from === from && l.port === port); }).concat([{ from: from, port: port, to: b.id }]); }
        this.select({ block: b.id });
        this.reveal(b);
        this.save();
    };

    /** The block (and its "+") brought into view when it is outside: the view moves, the zoom stays. */
    Editor.prototype.reveal = function (b) {
        var r = this.svg.getBoundingClientRect(), k = this.view.k, s = this.size(b), o = this.offset();
        var x = b.x + o[0], y = b.y + o[1];
        var left = x * k + this.view.x, top = y * k + this.view.y, right = (x + s[0] + 40) * k + this.view.x, bottom = (y + s[1]) * k + this.view.y;
        if (left < 10 || top < 10 || right > r.width - 10 || bottom > r.height - 10) {
            this.view.x = r.width / 2 - (x + s[0] / 2) * k;
            this.view.y = r.height / 2 - (y + s[1] / 2) * k;
            this.setTransform();
        }
    };

    Editor.prototype.centre = function () {
        var r = this.svg.getBoundingClientRect();
        var o = this.offset();
        return [(r.width / 2 - this.view.x) / this.view.k - W / 2 - o[0], (r.height / 2 - this.view.y) / this.view.k - H / 2 - o[1]];
    };

    // ------------------------------------------------------------------------------------------ side panel

    Editor.prototype.showPanel = function () {
        var p = this.panel, self = this, t = this.t;
        p.textContent = '';
        // nothing chosen, no simulation: hidden on a phone in full page (flows.css), the diagram gets the room
        p.classList.toggle('is-idle', !this.sim && !this.sel);
        if (!this.flow) { return; }
        if (this.sim) { return this.simPanel(); }
        if (this.sel && this.sel.link !== undefined) {
            p.appendChild(h('button', { type: 'button', class: 'btn btn-default btn-sm', text: t.delete_link, on: { click: function () { self.removeSelection(); } } }));
            return;
        }
        if (this.sel && this.sel.blocks) {
            p.appendChild(h('h4', { text: fill(t.selected, { count: this.sel.blocks.length }) }));
            p.appendChild(h('p', { class: 'wpf-muted', text: t.selected_hint }));
            p.appendChild(h('button', { type: 'button', class: 'btn btn-default btn-sm wpf-del', text: t.delete_blocks, on: { click: function () { self.removeSelection(); } } }));
            return;
        }
        var b = this.sel && this.block(this.sel.block);
        if (!b) {
            p.appendChild(h('p', { class: 'wpf-muted', text: t.select_hint }));
            var ticket = h('input', { type: 'number', min: 1, class: 'form-control input-sm', placeholder: t.path_ticket, 'aria-label': t.path_ticket });
            p.appendChild(h('h4', { text: t.path_title }));
            p.appendChild(h('div', { class: 'wpf-inline' }, [ticket, h('button', { type: 'button', class: 'btn btn-default btn-sm', text: t.path_show, on: { click: function () {
                if (ticket.value) { self.showPath(ticket.value); }
            } } })]));
            var problems = this.flow.problems || [];
            if (problems.length) {
                p.appendChild(h('h4', { text: fill(t.problems, { count: problems.length }) }));
                problems.forEach(function (pr) {
                    var bl = pr.block && self.block(pr.block);
                    p.appendChild(h('button', { type: 'button', class: 'wpf-problem', on: { click: function () { if (bl) { self.select({ block: bl.id }); } } } },
                        [(bl ? ((self.flow.titles || {})[bl.id] || bl.type) + ' — ' : '') + pr.message]));
                });
            }
            // how to use the grid, folded (it was a paragraph under the diagram)
            p.appendChild(h('details', { class: 'wpf-help' }, [h('summary', { text: t.help_title }), h('p', { text: t.intro }), h('p', { text: t.help })]));
            return;
        }
        var c = this.types[b.type] || { name: b.type, fields: {}, help: '' };
        p.appendChild(h('h4', { text: c.name }));
        p.appendChild(h('p', { class: 'wpf-muted', text: c.help }));
        Object.keys(c.fields || {}).forEach(function (key) {
            var input = self.field(b, key, c.fields[key]);
            if (input) {
                p.appendChild(h('label', { class: 'wpf-field' }, [h('span', { text: t['field_' + key] || key }), input]));
            }
        });
        this.problemsOf(b.id).forEach(function (pr) { p.appendChild(h('p', { class: 'wpf-problem-text', text: pr.message })); });
        if (c.family !== 'trigger') {
            p.appendChild(h('button', { type: 'button', class: 'btn btn-default btn-sm wpf-del', text: t.delete_block, on: { click: function () { self.removeSelection(); } } }));
        }
    };

    /** One field of a block, from its description; a change is saved and the panel follows (operator after variable…). */
    Editor.prototype.field = function (b, key, def) {
        var self = this, t = this.t, bt = this.bt, v = b.params[key];
        var set = function (value, again) {
            b.params[key] = value;
            if (key === 'var') { delete b.params.operator; delete b.params.value; }
            if (key === 'operator' && OPS.bool.indexOf(value) >= 0) { delete b.params.value; }
            self.save();
            if (again) { self.showPanel(); }
        };
        var select = function (options, current, again) {
            var s = h('select', { class: 'form-control input-sm', on: { change: function () { set(this.value === '' ? undefined : this.value, again); } } },
                [h('option', { value: '', text: t.choose })]);
            options.forEach(function (o) { s.appendChild(h('option', { value: o[0], text: o[1], selected: String(current) === String(o[0]) })); });
            return s;
        };
        var vars = def.type === 'variable' || def.type === 'operator' || def.type === 'value' ? variablesBefore(this.flow.draft, b.id, this.types) : {};
        var varDef = vars[b.params.var] || null;
        var label = function (k) { return bt.vars[k] || k; };
        var valueLabel = function (k, val) {
            var group = k.split('.').pop();
            return (bt.values[group] && bt.values[group][val]) || val;
        };
        switch (def.type) {
            case 'choice':
                return select((def.values || []).map(function (x) { return [x, valueLabel(key, x)]; }), v, false);
            case 'user':
                return select([['-1', bt.values.nobody]].concat(this.cfg.users.map(function (u) { return [u.id, u.name]; })), v, false);
            case 'duration':
                var d = v || { number: 1, metric: 'h' };
                var num = h('input', { type: 'number', min: 1, max: 100000, class: 'form-control input-sm', value: d.number, on: { input: function () {
                    set({ number: Math.max(1, parseInt(this.value, 10) || 1), metric: (b.params[key] || d).metric });
                } } });
                var unit = h('select', { class: 'form-control input-sm', on: { change: function () {
                    set({ number: (b.params[key] || d).number, metric: this.value });
                } } }, ['i', 'h', 'd'].map(function (u) { return h('option', { value: u, text: t['unit_' + u], selected: d.metric === u }); }));
                return h('span', { class: 'wpf-inline' }, [num, unit]);
            case 'mailbox':
                // the address where the e-mail is filed: by default the mailbox where it arrived
                var mbs = this.cfg.mailboxes.map(function (m) { return [m.id, m.name + (m.email ? ' — ' + m.email : '') + (m.imap ? '' : ' (' + t.folders_no_imap_short + ')')]; });
                var ms = h('select', { class: 'form-control input-sm', on: { change: function () { set(this.value === '' ? undefined : parseInt(this.value, 10), true); } } },
                    [h('option', { value: '', text: t.mailbox_arrived })]);
                mbs.forEach(function (o) { ms.appendChild(h('option', { value: o[0], text: o[1], selected: String(v) === String(o[0]) })); });
                return ms;
            case 'imap_folder':
                return this.folderField(b, key, v, set);
            case 'tickets':
                return this.ticketsField(b, key, v, set);
            case 'textarea':
                return h('textarea', { class: 'form-control input-sm', rows: 4, on: { input: function () { set(this.value); } } }, [v || '']);
            case 'variable':
                return select(Object.keys(vars).map(function (k) { return [k, label(k) + (vars[k].partial ? ' (' + t.partial + ')' : '')]; }), v, true);
            case 'operator':
                return varDef ? select((OPS[varDef.type] || OPS.text).map(function (o) { return [o, bt.ops[o] || o]; }), v, true) : null;
            case 'value':
                if (!varDef || OPS.bool.indexOf(b.params.operator) >= 0) { return null; }
                if (varDef.type === 'choice' && varDef.values) {
                    return select(varDef.values.map(function (x) { return [x, valueLabel(b.params.var, x)]; }), v, false);
                }
                return h('input', { type: varDef.type === 'number' ? 'number' : 'text', class: 'form-control input-sm', value: v || '', on: { input: function () { set(this.value); } } });
            default:
                return h('input', { type: 'text', class: 'form-control input-sm', value: v || '', on: { input: function () { set(this.value); } } });
        }
    };

    /**
     * Folder of "file into a folder": its name after the prefix of the module; the folders of the module already on the
     * address (a tap chooses one); a new name is created with the prefix the first time an e-mail is filed there.
     */
    Editor.prototype.folderField = function (b, key, v, set) {
        var self = this, t = this.t, imap = this.cfg.imap || {};
        var input = h('input', { type: 'text', class: 'form-control input-sm', value: v || '', placeholder: t.folder_placeholder,
            on: { input: function () { set(this.value); } } });
        var box = h('div', { class: 'wpf-folder' }, [h('span', { class: 'wpf-inline' }, [h('span', { class: 'wpf-prefix', text: imap.prefix || '' }), input])]);
        if (!imap.enabled) {
            box.appendChild(h('p', { class: 'wpf-problem-text' }, [t.folders_off + ' ', h('a', { href: imap.settings, text: t.folders_settings })]));
            return box;
        }
        // which address: the one chosen, else the mailbox of the flow (the first one with IMAP when several or all)
        var mine = this.flow.mailbox_ids.length ? this.cfg.mailboxes.filter(function (m) { return self.flow.mailbox_ids.indexOf(m.id) >= 0; }) : this.cfg.mailboxes;
        var mb = this.cfg.mailboxes.filter(function (m) { return m.id === b.params.mailbox; })[0] || mine.filter(function (m) { return m.imap; })[0];
        var note = h('p', { class: 'wpf-muted wpf-folder-note' });
        var chips = h('div', { class: 'wpf-chips' });
        box.appendChild(note);
        box.appendChild(chips);
        box.appendChild(h('p', { class: 'wpf-muted' }, [fill(t.folder_new_help, { prefix: imap.prefix || '' }) + ' ',
            h('a', { href: imap.settings, target: '_blank', rel: 'noopener', text: t.folder_prefix_change })]));
        if (!mb) {
            note.textContent = t.folders_no_imap;
            return box;
        }
        note.textContent = fill(t.folders_loading, { mailbox: mb.name });
        this.folderCache = this.folderCache || {};
        this.folderCache[mb.id] = this.folderCache[mb.id] || this.request(this.cfg.urls.imap_folders + '?mailbox_id=' + mb.id);
        this.folderCache[mb.id].then(function (res) {
            var d = res.data || {};
            if (!d.ok) {
                note.textContent = fill(t.folders_error, { mailbox: mb.name, error: d.error || '' });
                delete self.folderCache[mb.id];
                return;
            }
            note.textContent = fill(d.folders.length ? t.folders_of : t.folders_none, { mailbox: mb.name, prefix: imap.prefix || '' });
            d.folders.slice(0, 60).forEach(function (f) {
                chips.appendChild(h('button', { type: 'button', class: 'wpf-chip' + (f === input.value ? ' is-on' : ''), text: f, on: { click: function () {
                    input.value = f;
                    set(f);
                    Array.prototype.forEach.call(chips.children, function (c) { c.classList.toggle('is-on', c.textContent === f); });
                } } }));
            });
        });
        return box;
    };

    /** Ids of a "tickets" value written either way ("12, 45" or [12, 45]). */
    function ticketIds(v) {
        var list = Array.isArray(v) ? v : String(v || '').split(/[^0-9]+/);
        var out = [];
        list.forEach(function (x) { var n = parseInt(x, 10); if (n > 0 && out.indexOf(n) < 0) { out.push(n); } });
        return out;
    }

    /**
     * Examples of "Looks like": the tickets chosen (subject, sender; × removes one), a search among the tickets there
     * (subject, sender or number, in the mailboxes of the flow) to add more, and "Test" on the last tickets with the
     * threshold and the weights of the block. Nothing to publish first: the draft is enough.
     */
    Editor.prototype.ticketsField = function (b, key, v, set) {
        var self = this, t = this.t, ids = ticketIds(v);
        var box = h('div', { class: 'wpf-tickets' });
        var chosen = h('div', { class: 'wpf-chips' });
        var results = h('div', { class: 'wpf-ticket-results' });
        var test = h('div', { class: 'wpf-ticket-test' });
        var rows = {};
        var save = function () { set(ids.slice()); };
        var drawChosen = function () {
            chosen.textContent = '';
            if (!ids.length) { chosen.appendChild(h('p', { class: 'wpf-muted', text: t.examples_none })); }
            ids.forEach(function (id) {
                var tk = rows[id];
                chosen.appendChild(h('span', { class: 'wpf-chip is-on', title: tk ? tk.from : '' }, [
                    (tk ? '#' + tk.number + ' ' + (tk.subject || '—') : '#' + id) + ' ',
                    h('button', { type: 'button', class: 'wpf-chip-x', 'aria-label': t.examples_remove, on: { click: function () {
                        ids = ids.filter(function (x) { return x !== id; });
                        save();
                        drawChosen();
                        redraw();
                    } } }, ['×'])]));
            });
        };
        /**
         * A list of tickets to tick (a tap on the line ticks it), "All", "Add (n)": the ticked ones become examples
         * together (50 at most). recognised: the threshold of a test, "Tick the recognised ones" ticks those above it.
         */
        var picker = function (list, recognised) {
            var wrap = h('div', { class: 'wpf-pick' }), picked = {}, boxes = [];
            var free = list.filter(function (tk) { return ids.indexOf(tk.id) < 0; });
            var note = h('p', { class: 'wpf-problem-text' });
            var upd = function () {
                var n = Object.keys(picked).length;
                add.disabled = !n;
                add.textContent = n ? fill(t.examples_add_n, { count: n }) : t.examples_add_none;
                all.checked = n > 0 && n === free.length;
                all.indeterminate = n > 0 && n < free.length;
                boxes.forEach(function (b) { if (!b.disabled) { b.checked = !!picked[b.value]; } });
            };
            var all = h('input', { type: 'checkbox', disabled: !free.length, on: { change: function () {
                picked = {};
                if (this.checked) { free.forEach(function (tk) { picked[tk.id] = true; }); }
                upd();
            } } });
            var add = h('button', { type: 'button', class: 'btn btn-primary btn-xs', disabled: true, text: t.examples_add_none, on: { click: function () {
                var adding = Object.keys(picked).map(function (x) { return parseInt(x, 10); });
                var room = MAX_EXAMPLES - ids.length;
                note.textContent = adding.length > room ? fill(t.examples_max, { max: MAX_EXAMPLES }) : '';
                adding.slice(0, Math.max(0, room)).forEach(function (id) { if (ids.indexOf(id) < 0) { ids.push(id); } });
                save();
                drawChosen();
                redraw();
            } } });
            var head = [h('label', { class: 'wpf-check' }, [all, ' ' + t.list_all])];
            if (recognised) {
                head.push(h('button', { type: 'button', class: 'btn btn-default btn-xs', text: t.examples_tick_recognised, on: { click: function () {
                    picked = {};
                    free.forEach(function (tk) { if (tk.score >= recognised) { picked[tk.id] = true; } });
                    upd();
                } } }));
            }
            head.push(add);
            if (list.length) { wrap.appendChild(h('div', { class: 'wpf-pick-head' }, head)); }
            list.forEach(function (tk) {
                rows[tk.id] = rows[tk.id] || tk;
                var on = ids.indexOf(tk.id) >= 0;
                var cb = h('input', { type: 'checkbox', value: tk.id, checked: on, disabled: on, on: { change: function () {
                    if (this.checked) { picked[tk.id] = true; } else { delete picked[tk.id]; }
                    upd();
                } } });
                boxes.push(cb);
                var line = h('label', { class: 'wpf-ticket' + (on ? ' is-example' : '') }, [cb, h('span', { class: 'wpf-ticket-text' }, [
                    h('strong', { text: '#' + tk.number + ' ' + (tk.subject || '—') }),
                    h('small', { class: 'wpf-muted', text: (on ? t.examples_added + ' · ' : '') + [tk.from, tk.mailbox, tk.date].filter(Boolean).join(' · ') })])]);
                if (tk.score !== undefined) {
                    line.appendChild(h('span', { class: 'wpf-score ' + (recognised && tk.score >= recognised ? 'is-yes' : 'is-no'), text: tk.score + ' %' }));
                }
                wrap.appendChild(line);
            });
            wrap.appendChild(note);
            return wrap;
        };
        var lastFound = [], lastTest = null;
        var redraw = function () { drawResults(lastFound); drawTest(lastTest); };
        var drawResults = function (list) {
            lastFound = list;
            results.textContent = '';
            results.appendChild(picker(list, 0));
            if (!list.length && search.value) { results.appendChild(h('p', { class: 'wpf-muted', text: t.examples_nothing })); }
        };
        var drawTest = function (d) {
            lastTest = d;
            test.textContent = '';
            if (!d) { return; }
            test.appendChild(h('p', { class: 'wpf-ticket-sum', text: fill(t.examples_result, { yes: d.yes, tested: d.tested, threshold: d.threshold }) }));
            test.appendChild(picker(d.tickets || [], d.threshold));
        };
        var mailboxes = function () { return self.flow.mailbox_ids.map(function (m) { return 'mailbox_ids[]=' + m; }).join('&'); };
        var timer = null;
        var search = h('input', { type: 'search', class: 'form-control input-sm', placeholder: t.examples_search, on: { input: function () {
            clearTimeout(timer);
            var q = this.value;
            timer = setTimeout(function () {
                self.request(self.cfg.urls.tickets + '?q=' + encodeURIComponent(q) + '&' + mailboxes()).then(function (res) {
                    if (search.value === q) { drawResults((res.data && res.data.tickets) || []); }
                });
            }, 300);
        } } });
        var tryIt = h('button', { type: 'button', class: 'btn btn-default btn-sm', text: '▶ ' + t.examples_test, on: { click: function () {
            if (!ids.length) { test.textContent = t.examples_none; return; }
            test.textContent = t.examples_testing;
            self.request(self.cfg.urls.similar_test + '?' + mailboxes(), {
                examples: ids, threshold: b.params.threshold || 75, subject: b.params.subject === undefined ? 50 : b.params.subject,
                body: b.params.body === undefined ? 50 : b.params.body, mailbox_ids: self.flow.mailbox_ids
            }).then(function (res) {
                var d = res.data || {};
                test.textContent = '';
                if (!d.ok) { test.appendChild(h('p', { class: 'wpf-problem-text', text: d.message || '' })); return; }
                drawTest(d);
            });
        } } });
        box.appendChild(chosen);
        box.appendChild(h('div', { class: 'wpf-inline' }, [search]));
        box.appendChild(results);
        box.appendChild(h('div', { class: 'wpf-inline' }, [tryIt, h('small', { class: 'wpf-muted', text: t.examples_test_help })]));
        box.appendChild(test);
        drawChosen();
        // the examples already chosen: their subject instead of a number
        if (ids.length) {
            this.request(this.cfg.urls.tickets + '?' + ids.map(function (id) { return 'ids[]=' + id; }).join('&')).then(function (res) {
                ((res.data && res.data.tickets) || []).forEach(function (tk) { rows[tk.id] = tk; });
                drawChosen();
            });
        }
        return box;
    };

    // ----------------------------------------------------------------------------------------- server calls

    /** Draft saved shortly after the last change; titles and problems come back. */
    Editor.prototype.save = function () {
        var self = this;
        if (!this.flow) { return; }
        this.flow.dirty = true;
        this.status(this.t.saving);
        clearTimeout(this.saveTimer);
        this.pending = new Promise(function (resolve) {
            self.saveTimer = setTimeout(function () {
                var f = self.flow;
                self.request(self.url('save'), { name: f.name, mailbox_ids: f.mailbox_ids, once: f.once, draft: f.draft }).then(function (res) {
                    if (res.ok && self.flow === f) {
                        f.titles = res.data.titles;
                        f.problems = res.data.problems;
                        self.render();
                        if (!self.sim && !(self.sel && self.sel.block)) { self.showPanel(); }
                        if (self.sel && self.sel.block) { self.refreshProblems(); }
                        self.state();
                    } else if (!res.ok) {
                        self.status(fill(self.t.error, { message: res.data.message || '' }), 'error');
                    }
                    resolve();
                });
            }, 500);
        });
    };

    /** Problems of the selected block, without rebuilding its fields (the cursor stays where it is). */
    Editor.prototype.refreshProblems = function () {
        var p = this.panel, self = this;
        Array.prototype.forEach.call(p.querySelectorAll('.wpf-problem-text'), function (n) { n.parentNode.removeChild(n); });
        var anchor = p.querySelector('.wpf-del');
        this.problemsOf(this.sel.block).forEach(function (pr) {
            p.insertBefore(h('p', { class: 'wpf-problem-text', text: pr.message }), anchor);
        });
        void self;
    };

    Editor.prototype.flush = function () {
        if (this.saveTimer && this.pending) {
            clearTimeout(this.saveTimer);
            var f = this.flow;
            this.saveTimer = null;
            return f ? this.request(this.url('save'), { name: f.name, mailbox_ids: f.mailbox_ids, once: f.once, draft: f.draft }) : Promise.resolve();
        }
        return Promise.resolve();
    };

    Editor.prototype.create = function () {
        var self = this;
        this.flush().then(function () {
            return self.request(self.cfg.urls.store, { name: self.t.new_name });
        }).then(function (res) {
            if (res && res.ok) { self.load(res.data.flow.id); }
        });
    };

    Editor.prototype.publish = function () {
        var self = this;
        this.flush().then(function () { return self.request(self.url('publish'), {}); }).then(function (res) {
            if (res.ok) {
                self.flow.version = res.data.version;
                self.flow.problems = [];
                self.flow.dirty = false;
                self.state();
                self.status(self.t.published_ok + ' ' + fill(self.t.published, { n: res.data.version }), 'ok');
                // the new version for new tickets; the ones already there only when asked
                if (window.confirm(self.t.reapply_ask)) { window.location.href = self.url('reapply'); }
            } else {
                self.flow.problems = res.data.problems || [];
                self.select(null);
                self.state();
            }
        });
    };

    Editor.prototype.toggle = function (on) {
        var self = this;
        this.request(this.url('toggle'), { active: on }).then(function (res) {
            if (!res.ok) {
                self.q('.wpf-active').checked = !on;
                self.status(res.data.message || '', 'warn');
                return;
            }
            self.flow.active = res.data.active;
            self.load(self.flow.id);
        });
    };

    Editor.prototype.destroy = function () {
        var self = this;
        if (!this.flow || !window.confirm(this.t.delete_confirm)) { return; }
        this.request(this.url('destroy'), {}).then(function () { self.load(); });
    };

    // ---------------------------------------------------------------------------------------------- simulation

    Editor.prototype.showSim = function () {
        this.sim = { links: {}, seen: {}, on: null, events: null, step: 0 };
        this.sel = null;
        this.selbar();
        this.render();
        this.showPanel();
    };

    /** Path of a real ticket: the blocks it went through lit, the links taken coloured, its real changes. */
    Editor.prototype.showPath = function (number) {
        var self = this;
        this.request(this.url('path') + '?ticket=' + encodeURIComponent(number)).then(function (res) {
            if (!res.ok || !res.data.ok) {
                self.status(self.t.path_none, 'warn');
                return;
            }
            var d = res.data, s = { real: true, links: {}, seen: {}, on: d.block, events: d.events, step: d.events.length, end: d.state, wake: d.wake_at, titles: d.titles };
            d.events.forEach(function (ev, i) {
                s.seen[ev.block] = true;
                var prev = d.events[i - 1];
                if (prev) {
                    self.flow.draft.links.forEach(function (l, li) { if (l.from === prev.block && l.port === prev.output && l.to === ev.block) { s.links[li] = true; } });
                }
            });
            self.sim = s;
            self.sel = null;
            self.render();
            self.showPanel();
        });
    };

    Editor.prototype.simPanel = function () {
        var self = this, t = this.t, p = this.panel, s = this.sim;
        p.appendChild(h('h4', { text: s.real ? t.path_title : t.sim_title }));
        if (s.real) {
            p.appendChild(h('button', { type: 'button', class: 'btn btn-link btn-sm', text: '×', on: { click: function () { self.sim = null; self.render(); self.showPanel(); } } }));
        } else {
        if (!s.form) { s.form = { subject: 'PR #42', from: 'client@example.test', body: '' }; }
        ['subject', 'from', 'body'].forEach(function (k) {
            var input = k === 'body'
                ? h('textarea', { class: 'form-control input-sm', rows: 3, on: { input: function () { s.form[k] = this.value; } } }, [s.form[k]])
                : h('input', { type: 'text', class: 'form-control input-sm', value: s.form[k], on: { input: function () { s.form[k] = this.value; } } });
            p.appendChild(h('label', { class: 'wpf-field' }, [h('span', { text: t['sim_' + k] }), input]));
        });
        p.appendChild(h('div', { class: 'wpf-inline' }, [
            h('button', { type: 'button', class: 'btn btn-primary btn-sm', text: '▶ ' + t.sim_run, on: { click: function () { self.runSim(false); } } }),
            h('button', { type: 'button', class: 'btn btn-default btn-sm', text: t.sim_step, on: { click: function () { self.runSim(true); } } }),
            h('button', { type: 'button', class: 'btn btn-link btn-sm', text: '×', title: 'Esc', on: { click: function () { self.sim = null; self.render(); self.showPanel(); } } })
        ]));
        }
        var list = h('ol', { class: 'wpf-events' });
        (s.events || []).slice(0, s.step).forEach(function (ev) {
            var b = self.block(ev.block);
            var changes = (ev.changes || []).map(function (c) { return h('div', { class: 'wpf-change', text: self.change(c) }); });
            var title = (s.titles && s.titles[ev.block]) || (b && (self.flow.titles || {})[b.id]) || ev.block;
            list.appendChild(h('li', { class: 'lvl-' + ev.level }, [
                h('span', { class: 'wpf-at', text: s.real ? String(ev.time).slice(5, 16) : self.when(ev.at) }),
                h('strong', { text: title }),
                ev.summary && ev.summary !== title ? h('div', { text: ev.summary }) : null
            ].concat(changes)));
        });
        p.appendChild(list);
        if (s.events && s.step >= s.events.length) {
            var endText = s.real ? fill(t['path_' + s.end] || s.end, { date: String(s.wake || '').slice(0, 16) }) : (t['sim_end_' + s.end] || s.end);
            p.appendChild(h('p', { class: 'wpf-muted', text: endText }));
        }
        (s.problems || []).forEach(function (pr) { p.appendChild(h('p', { class: 'wpf-problem-text', text: pr.message })); });
    };

    /** A change "before -> after", in words: Status: Active → Closed. */
    Editor.prototype.change = function (c) {
        var bt = this.bt, t = this.t;
        var labels = { status: bt.vars['ticket.status'], assignee: bt.vars['ticket.assigned'], folder: t.field_path, note: t.field_text, push: t.field_push, deleted: t.field_deleted };
        var value = function (v) { return (c[0] === 'status' && bt.values.status && bt.values.status[v]) || v || '—'; };
        return (labels[c[0]] || c[0]) + ' : ' + value(c[1]) + ' → ' + value(c[2]);
    };

    Editor.prototype.when = function (seconds) {
        if (!seconds) { return '0'; }
        var d = Math.floor(seconds / 86400), hh = Math.floor((seconds % 86400) / 3600), m = Math.floor((seconds % 3600) / 60);
        return '+' + (d ? d + ' ' + this.t.unit_d + ' ' : '') + (hh ? hh + ' h ' : '') + (m ? m + ' min' : '');
    };

    Editor.prototype.runSim = function (stepByStep) {
        var self = this, s = this.sim;
        if (s.events && stepByStep && s.step < s.events.length) { return this.playStep(); }
        this.flush().then(function () {
            return self.request(self.url('simulate'), { draft: self.flow.draft, mail: s.form });
        }).then(function (res) {
            s.problems = res.ok ? [] : (res.data.problems || []);
            s.events = res.ok ? res.data.events : [];
            s.end = res.ok ? res.data.end : 'failed';
            s.step = 0;
            s.links = {};
            s.seen = {};
            if (!stepByStep) {
                var play = function () { if (self.sim === s && s.step < s.events.length) { self.playStep().then(function () { setTimeout(play, 250); }); } };
                play();
            } else {
                self.playStep();
            }
            self.showPanel();
        });
    };

    /** One event: the packet goes along the link to its block, which lights up; the list shows what happened. */
    Editor.prototype.playStep = function () {
        var self = this, s = this.sim, ev = s.events[s.step], prev = s.step ? s.events[s.step - 1] : null;
        var index = -1;
        if (prev) {
            this.flow.draft.links.forEach(function (l, i) { if (l.from === prev.block && l.port === prev.output && l.to === ev.block) { index = i; } });
        }
        return new Promise(function (resolve) {
            var done = function () {
                s.seen[ev.block] = true;
                s.on = ev.block;
                s.step++;
                self.render();
                self.showPanel();
                resolve();
            };
            var path = index >= 0 ? self.q('[data-link="' + index + '"] .wpf-link-line') : null;
            if (!path || !path.getTotalLength) { return done(); }
            s.links[index] = true;
            var dot = svg('circle', { class: 'wpf-packet', r: 7 }, self.act);
            var len = path.getTotalLength(), start = null;
            var frame = function (ts) {
                start = start || ts;
                var k = Math.min(1, (ts - start) / 450), pt = path.getPointAtLength(len * k);
                dot.setAttribute('cx', pt.x);
                dot.setAttribute('cy', pt.y);
                if (k < 1) { window.requestAnimationFrame(frame); } else { dot.parentNode.removeChild(dot); done(); }
            };
            window.requestAnimationFrame(frame);
        });
    };

    var api = { start: function (el, cfg) { return new Editor(el, cfg); }, variablesBefore: variablesBefore, pinchView: pinchView, inRect: inRect, shelf: shelf, MIN_K: MIN_K };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        root.WPFlows = api;
    }
})(typeof window !== 'undefined' ? window : this);
