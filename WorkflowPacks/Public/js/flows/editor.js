/*
 * Workflow Packs — editor of the flows of the internal engine (Workflow Packs › Flows). Each block is an object:
 * drag it, pull one of its outputs onto another block to link them, "+" on an output adds the next block. The side
 * panel is built from the catalog of the server (fields, outputs, variables: no type of block is written here). The
 * draft is saved as it goes; "Publish" freezes a version (checked on the server); "Simulate" plays a sample e-mail
 * through the flow (nothing written, nothing sent).
 */
(function (root) {
    'use strict';

    var NS = 'http://www.w3.org/2000/svg';
    var W = 230, H = 62, PORT_GAP = 24, GRID = 10;
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
        this.q = function (s) { return rootEl.querySelector(s); };
        this.svg = this.q('.wpf-canvas');
        this.vp = this.q('.wpf-vp');
        this.panel = this.q('.wpf-panel');
        var defs = svg('defs', {}, this.svg);
        if (root.WPGraph && root.WPGraph.icons) { root.WPGraph.icons.define(defs); }
        this.bind();
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
            var select = self.q('.wpf-flow-select');
            select.textContent = '';
            self.flows.forEach(function (f) {
                select.appendChild(h('option', { value: f.id, text: f.name + (f.active ? ' ●' : '') }));
            });
            var pick = self.flows.filter(function (f) { return f.id === selectId; })[0] || self.flows[0] || null;
            self.open(pick);
        });
    };

    Editor.prototype.open = function (flow) {
        this.flow = flow ? clone(flow) : null;
        this.sel = null;
        this.sim = null;
        var empty = !flow;
        this.q('.wpf-empty').hidden = !empty;
        Array.prototype.forEach.call(this.root.querySelectorAll('.wpf-toolbar input, .wpf-toolbar [data-act]:not([data-act="new"]), .wpf-flow-select'), function (n) {
            n.disabled = empty;
        });
        if (flow) {
            this.q('.wpf-flow-select').value = flow.id;
            this.q('.wpf-name').value = flow.name;
            this.q('.wpf-once').checked = flow.once;
            this.q('.wpf-active').checked = flow.active;
            this.boxes();
        }
        this.render();
        this.fit();
        this.showPanel();
        this.state();
    };

    /** Version and problems in the toolbar. */
    Editor.prototype.state = function () {
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

    Editor.prototype.render = function () {
        var self = this, links = this.q('.wpf-links'), blocks = this.q('.wpf-blocks');
        links.textContent = '';
        blocks.textContent = '';
        this.setTransform();
        if (!this.flow) { return; }
        var draft = this.flow.draft;
        draft.links.forEach(function (l, i) {
            var a = self.block(l.from), b = self.block(l.to);
            if (!a || !b) { return; }
            var s = [a.x + W, a.y + self.portY(a, l.port)], e = [b.x, b.y + self.size(b)[1] / 2];
            var dx = Math.max(40, Math.abs(e[0] - s[0]) / 2);
            var d = 'M' + s + 'C' + [s[0] + dx, s[1]] + ' ' + [e[0] - dx, e[1]] + ' ' + e;
            var sel = self.sel && self.sel.link === i;
            var g = svg('g', { class: 'wpf-link port-' + l.port + (sel ? ' is-selected' : '') + (self.sim && self.sim.links[i] ? ' is-sim' : ''), 'data-link': i }, links);
            svg('path', { class: 'wpf-link-hit', d: d }, g);
            svg('path', { class: 'wpf-link-line', d: d, 'marker-end': '' }, g);
            if (l.port !== 'next') {
                var t = svg('text', { class: 'wpf-link-label', x: (s[0] + e[0]) / 2, y: (s[1] + e[1]) / 2 - 4 }, g);
                t.textContent = self.bt.outputs[l.port] || l.port;
            }
        });
        draft.blocks.forEach(function (b) {
            var type = self.types[b.type] || { family: 'util', name: b.type, outputs: [] };
            var size = self.size(b);
            var cls = 'wpf-block fam-' + type.family + (self.sel && self.sel.block === b.id ? ' is-selected' : '')
                + (self.problemsOf(b.id).length ? ' has-problem' : '') + (self.sim && self.sim.on === b.id ? ' is-sim' : '')
                + (self.sim && self.sim.seen[b.id] ? ' was-sim' : '');
            var g = svg('g', { class: cls, transform: 'translate(' + b.x + ',' + b.y + ')', 'data-block': b.id }, blocks);
            svg('rect', { class: 'wpf-card', width: size[0], height: size[1], rx: 10 }, g);
            svg('rect', { class: 'wpf-stripe', width: 5, height: size[1], rx: 2 }, g);
            var use = svg('use', { href: '#wpg-i-' + (ICONS[b.type] || ICONS[type.family] || 'dots'), x: 14, y: size[1] / 2 - 11, width: 22, height: 22, class: 'wpf-icon' }, g);
            use.setAttributeNS('http://www.w3.org/1999/xlink', 'href', use.getAttribute('href'));
            var name = svg('text', { class: 'wpf-kind', x: 46, y: 22 }, g);
            name.textContent = cut(type.name, 26);
            var title = svg('text', { class: 'wpf-title', x: 46, y: 41 }, g);
            title.textContent = cut((self.flow.titles || {})[b.id] || type.name, 24);
            if (type.family !== 'trigger') {
                svg('circle', { class: 'wpf-in', cx: 0, cy: size[1] / 2, r: 5 }, g);
            }
            self.outputsOf(b).forEach(function (o) {
                var py = self.portY(b, o.key);
                if (self.outputsOf(b).length > 1) {
                    var lt = svg('text', { class: 'wpf-port-label', x: W - 12, y: py + 4 }, g);
                    lt.textContent = o.label;
                }
                svg('circle', { class: 'wpf-port port-' + o.key, cx: W, cy: py, r: 7, 'data-port': o.key }, g);
                var plus = svg('g', { class: 'wpf-plus', 'data-plus': o.key, transform: 'translate(' + (W + 20) + ',' + py + ')' }, g);
                svg('circle', { r: 9 }, plus);
                svg('path', { d: 'M-4 0H4M0 -4V4' }, plus);
            });
        });
    };

    Editor.prototype.setTransform = function () {
        this.vp.setAttribute('transform', 'translate(' + this.view.x + ',' + this.view.y + ') scale(' + this.view.k + ')');
    };

    Editor.prototype.fit = function () {
        if (!this.flow || !this.flow.draft.blocks.length) { return; }
        var self = this, box = [Infinity, Infinity, -Infinity, -Infinity];
        this.flow.draft.blocks.forEach(function (b) {
            var s = self.size(b);
            box = [Math.min(box[0], b.x), Math.min(box[1], b.y), Math.max(box[2], b.x + s[0] + 40), Math.max(box[3], b.y + s[1])];
        });
        var r = this.svg.getBoundingClientRect(), pad = 30;
        var k = Math.min(1.2, (r.width - pad * 2) / Math.max(1, box[2] - box[0]), (r.height - pad * 2) / Math.max(1, box[3] - box[1]));
        this.view.k = Math.max(0.3, k);
        this.view.x = (r.width - (box[2] - box[0]) * this.view.k) / 2 - box[0] * this.view.k;
        this.view.y = (r.height - (box[3] - box[1]) * this.view.k) / 2 - box[1] * this.view.k;
        this.setTransform();
    };

    Editor.prototype.world = function (e) {
        var r = this.svg.getBoundingClientRect();
        return [(e.clientX - r.left - this.view.x) / this.view.k, (e.clientY - r.top - this.view.y) / this.view.k];
    };

    // ------------------------------------------------------------------------------------------ interactions

    Editor.prototype.bind = function () {
        var self = this, mode = null;
        this.q('.wpf-flow-select').addEventListener('change', function () {
            var id = parseInt(this.value, 10);
            self.flush().then(function () { self.open(self.flows.filter(function (f) { return f.id === id; })[0]); });
        });
        this.q('.wpf-name').addEventListener('input', function () { self.flow.name = this.value; self.save(); });
        this.q('.wpf-once').addEventListener('change', function () { self.flow.once = this.checked; self.save(); });
        this.q('.wpf-active').addEventListener('change', function () { self.toggle(this.checked); });
        this.root.addEventListener('click', function (e) {
            var act = e.target.closest && e.target.closest('[data-act]');
            if (!act || act.disabled) { return; }
            var name = act.getAttribute('data-act');
            if (name === 'new') { self.create(); }
            if (name === 'publish') { self.publish(); }
            if (name === 'simulate') { self.showSim(); }
            if (name === 'fit') { self.fit(); }
            if (name === 'add') { self.menu(act, null, null); }
            if (name === 'delete-flow') { self.destroy(); }
        });
        document.addEventListener('keydown', function (e) {
            if ((e.key === 'Delete' || e.key === 'Backspace') && self.sel && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) {
                e.preventDefault();
                self.removeSelection();
            }
            if (e.key === 'Escape') { self.closeMenu(); }
        });
        this.svg.addEventListener('wheel', function (e) {
            e.preventDefault();
            var r = self.svg.getBoundingClientRect(), mx = e.clientX - r.left, my = e.clientY - r.top;
            var k = Math.min(2, Math.max(0.25, self.view.k * (e.deltaY < 0 ? 1.1 : 0.9)));
            self.view.x = mx - (mx - self.view.x) * k / self.view.k;
            self.view.y = my - (my - self.view.y) * k / self.view.k;
            self.view.k = k;
            self.setTransform();
        }, { passive: false });
        this.svg.addEventListener('pointerdown', function (e) {
            if (!self.flow) { return; }
            self.closeMenu();
            var plus = e.target.closest('[data-plus]'), port = e.target.closest('[data-port]');
            var blockEl = e.target.closest('[data-block]'), linkEl = e.target.closest('[data-link]');
            try { self.svg.setPointerCapture(e.pointerId); } catch (err) { /* old browser */ }
            if (plus) {
                mode = { type: 'plus', from: blockEl.getAttribute('data-block'), port: plus.getAttribute('data-plus') };
            } else if (port) {
                var from = blockEl.getAttribute('data-block');
                mode = { type: 'link', from: from, port: port.getAttribute('data-port'), temp: svg('path', { class: 'wpf-temp' }, self.q('.wpf-links')) };
            } else if (blockEl) {
                var b = self.block(blockEl.getAttribute('data-block')), w = self.world(e);
                self.select({ block: b.id });
                mode = { type: 'drag', b: b, dx: w[0] - b.x, dy: w[1] - b.y, moved: false };
            } else if (linkEl) {
                self.select({ link: parseInt(linkEl.getAttribute('data-link'), 10) });
            } else {
                mode = { type: 'pan', sx: e.clientX, sy: e.clientY, vx: self.view.x, vy: self.view.y, moved: false };
            }
        });
        this.svg.addEventListener('pointermove', function (e) {
            if (!mode) { return; }
            var w = self.world(e);
            if (mode.type === 'drag') {
                mode.b.x = Math.round((w[0] - mode.dx) / GRID) * GRID;
                mode.b.y = Math.round((w[1] - mode.dy) / GRID) * GRID;
                mode.moved = true;
                self.render();
            } else if (mode.type === 'pan') {
                self.view.x = mode.vx + e.clientX - mode.sx;
                self.view.y = mode.vy + e.clientY - mode.sy;
                mode.moved = mode.moved || Math.abs(e.clientX - mode.sx) + Math.abs(e.clientY - mode.sy) > 4;
                self.setTransform();
            } else if (mode.type === 'link') {
                var a = self.block(mode.from), s = [a.x + W, a.y + self.portY(a, mode.port)];
                mode.temp.setAttribute('d', 'M' + s + 'L' + w);
            }
        });
        var end = function (e) {
            if (!mode) { return; }
            var m = mode;
            mode = null;
            if (m.type === 'drag' && m.moved) { self.save(); }
            if (m.type === 'pan' && !m.moved) { self.select(null); }
            if (m.type === 'plus') { self.menu(null, m.from, m.port, e); }
            if (m.type === 'link') {
                if (m.temp.parentNode) { m.temp.parentNode.removeChild(m.temp); }
                var under = document.elementFromPoint(e.clientX, e.clientY);
                var target = under && under.closest ? under.closest('[data-block]') : null;
                if (target) { self.link(m.from, m.port, target.getAttribute('data-block')); }
            }
        };
        this.svg.addEventListener('pointerup', end);
        this.svg.addEventListener('pointercancel', function () { mode = null; self.render(); });
        window.addEventListener('resize', function () { self.setTransform(); });
    };

    Editor.prototype.select = function (sel) {
        this.sel = sel;
        this.render();
        this.showPanel();
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
        var sel = this.sel, draft = this.flow.draft;
        if (!sel) { return; }
        if (sel.link !== undefined) {
            draft.links.splice(sel.link, 1);
        } else {
            var b = this.block(sel.block);
            if (!b || (this.types[b.type] || {}).family === 'trigger') { this.status(this.t.trigger_fixed, 'warn'); return; }
            draft.blocks = draft.blocks.filter(function (x) { return x.id !== b.id; });
            draft.links = draft.links.filter(function (l) { return l.from !== b.id && l.to !== b.id; });
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
                    self.closeMenu();
                    self.add(c.type, from, port);
                } } }, [c.name]));
            });
        });
        document.body.appendChild(menu);
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
        var r = this.svg.getBoundingClientRect(), k = this.view.k, s = this.size(b);
        var left = b.x * k + this.view.x, top = b.y * k + this.view.y, right = (b.x + s[0] + 40) * k + this.view.x, bottom = (b.y + s[1]) * k + this.view.y;
        if (left < 10 || top < 10 || right > r.width - 10 || bottom > r.height - 10) {
            this.view.x = r.width / 2 - (b.x + s[0] / 2) * k;
            this.view.y = r.height / 2 - (b.y + s[1] / 2) * k;
            this.setTransform();
        }
    };

    Editor.prototype.centre = function () {
        var r = this.svg.getBoundingClientRect();
        return [(r.width / 2 - this.view.x) / this.view.k - W / 2, (r.height / 2 - this.view.y) / this.view.k - H / 2];
    };

    // ------------------------------------------------------------------------------------------ side panel

    Editor.prototype.showPanel = function () {
        var p = this.panel, self = this, t = this.t;
        p.textContent = '';
        if (!this.flow) { return; }
        if (this.sim) { return this.simPanel(); }
        if (this.sel && this.sel.link !== undefined) {
            p.appendChild(h('button', { type: 'button', class: 'btn btn-default btn-sm', text: t.delete_link, on: { click: function () { self.removeSelection(); } } }));
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
        var labels = { status: bt.vars['ticket.status'], assignee: bt.vars['ticket.assigned'], folder: t.field_path, note: t.field_text };
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
            var dot = svg('circle', { class: 'wpf-packet', r: 7 }, self.vp);
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

    var api = { start: function (el, cfg) { return new Editor(el, cfg); }, variablesBefore: variablesBefore };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        root.WPFlows = api;
    }
})(typeof window !== 'undefined' ? window : this);
