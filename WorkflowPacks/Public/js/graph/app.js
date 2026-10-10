/*
 * Workflow Packs — diagram: wiring of the modules (model, layout, view, minimap, side panel, editor, server).
 *
 *   - positions saved live (only the moved blocks, grouped every 500 ms), one layout per user;
 *   - display choices saved too: layout, style of the links, packs and destinations shown;
 *   - overall view (fit), "go to" shortcuts and focus mode (one mailbox / rule / pack / destination and its links);
 *   - keyboard: + / - zoom, 0 overall view, F focus on the selection, Esc back, Ctrl+Z / Ctrl+Y, N new rule.
 */
(function (WPGraph) {
    'use strict';

    var L = WPGraph.layout, h = WPGraph.h, icon = WPGraph.icon;

    function App(root) {
        this.root = root;
        this.cfg = JSON.parse(root.querySelector('#wpg-config').textContent);
        this.texts = this.cfg.texts;
        this.api = new WPGraph.Api(this.cfg.urls, this.cfg.token);
        this.model = new WPGraph.Model();
        this.settings = { layout: 'columns', edges: 'orthogonal', packs: true, dests: true };
        this.catalogs = {};
        this.queue = {};
        this.timer = null;
        var self = this;
        this.view = new WPGraph.View(root.querySelector('.wpg-canvas'), this.model, {
            texts: this.texts,
            onMoved: function (before, after) { self.model.commit(before, after); self.savePositions(after); self.minimap.update(); },
            onSelect: function (key) { if (key) { self.inspector.show(key); } else { self.inspector.hide(); } self.minimap.update(); },
            onEdge: function (edge) { self.inspector.showEdge(edge); },
            onConnect: function (from, to) { self.connect(from, to); },
            onRefused: function () { self.toast(self.texts.connect_refused, 'error'); },
            onOpen: function (key) { self.open(key); },
            onView: function () { if (self.minimap) { self.minimap.update(); } }
        });
        this.minimap = new WPGraph.Minimap(root.querySelector('.wpg-minimap svg'), this.view);
        this.inspector = new WPGraph.Inspector(root.querySelector('.wpg-inspector'), this);
        this.editor = new WPGraph.Editor(root.querySelector('.wpg-overlay'), this);
        this.bindToolbar();
        this.bindKeys();
        this.load(true);
    }

    // ------------------------------------------------------------------------------------------------ data

    App.prototype.load = function (first) {
        var self = this;
        return this.api.data().then(function (data) {
            if (first) {
                self.model.pos = data.layout.positions || {};
                self.settings = data.layout.settings;
                self.syncToolbar();
            }
            self.model.load(data);
            var nodes = self.model.list();
            if (first && !Object.keys(self.model.pos).length) {
                // first visit: automatic layout, saved
                self.model.pos = L[self.settings.layout === 'free' ? 'columns' : self.settings.layout](nodes, self.model.edges);
                self.savePositions(self.model.pos);
            } else {
                var before = Object.keys(self.model.pos);
                L.placeNew(self.model.pos, nodes, self.model.edges);
                var fresh = {};
                Object.keys(self.model.pos).forEach(function (k) { if (before.indexOf(k) < 0) { fresh[k] = self.model.pos[k]; } });
                if (Object.keys(fresh).length) { self.savePositions(fresh); }
            }
            self.view.edgeStyle = self.settings.edges;
            self.view.showPacks = self.settings.packs;
            self.view.showDests = self.settings.dests;
            self.view.render();
            if (first) { self.view.fit(); }
            self.fillGoto();
            self.minimap.update();
            self.inspector.refresh();
            self.root.classList.toggle('is-empty', !nodes.length);
        }).catch(function (e) { self.toast(e.message, 'error'); });
    };

    App.prototype.reload = function () { return this.load(false); };

    App.prototype.catalogOf = function (mailboxId) {
        if (!this.catalogs[mailboxId]) { this.catalogs[mailboxId] = this.api.catalog(mailboxId); }
        return this.catalogs[mailboxId];
    };

    // ------------------------------------------------------------------------------------- live saving

    App.prototype.savePositions = function (positions, replace) {
        var self = this;
        Object.keys(positions).forEach(function (k) { self.queue[k] = positions[k]; });
        this.replace = this.replace || !!replace;
        this.status('saving');
        clearTimeout(this.timer);
        this.timer = setTimeout(function () { self.flush(); }, 500);
    };

    App.prototype.saveSettings = function (changes) {
        var self = this;
        Object.keys(changes).forEach(function (k) { self.settings[k] = changes[k]; });
        this.pendingSettings = Object.assign(this.pendingSettings || {}, changes);
        this.savePositions({});
    };

    App.prototype.flush = function () {
        var self = this;
        var positions = this.queue, settings = this.pendingSettings || {}, replace = this.replace;
        this.queue = {};
        this.pendingSettings = null;
        this.replace = false;
        this.api.saveLayout(positions, settings, replace).then(function () { self.status('saved'); })
            .catch(function () { self.status('error'); });
    };

    App.prototype.status = function (state) {
        var el = this.root.querySelector('.wpg-status');
        el.className = 'wpg-status is-' + state;
        el.textContent = this.texts['status_' + state];
    };

    // ------------------------------------------------------------------------------------ toolbar / keys

    App.prototype.bindToolbar = function () {
        var self = this, r = this.root;
        var on = function (sel, ev, fn) { var el = r.querySelector(sel); if (el) { el.addEventListener(ev, fn); } };
        on('[data-act="zoom-in"]', 'click', function () { self.view.zoomAt(1.25); });
        on('[data-act="zoom-out"]', 'click', function () { self.view.zoomAt(0.8); });
        on('[data-act="fit"]', 'click', function () { self.clearFocus(); self.view.fit(); });
        on('[data-act="undo"]', 'click', function () { self.undo(); });
        on('[data-act="redo"]', 'click', function () { self.redo(); });
        on('[data-act="new"]', 'click', function () { self.newRule(); });
        on('[data-act="unfocus"]', 'click', function () { self.clearFocus(); self.view.fit(); });
        on('#wpg-layout', 'change', function () { self.arrange(this.value); });
        on('#wpg-edges', 'change', function () { self.view.edgeStyle = this.value; self.view.render(); self.saveSettings({ edges: this.value }); });
        on('#wpg-packs', 'change', function () { self.view.showPacks = this.checked; self.view.render(); self.minimap.update(); self.saveSettings({ packs: this.checked }); });
        on('#wpg-dests', 'change', function () { self.view.showDests = this.checked; self.view.render(); self.minimap.update(); self.saveSettings({ dests: this.checked }); });
        on('#wpg-goto', 'change', function () { if (this.value) { self.focusOn(this.value); this.value = ''; } });
        on('#wpg-search', 'keydown', function (e) {
            if (e.key !== 'Enter') { return; }
            var q = this.value.trim().toLowerCase();
            var keys = self.model.list(function (n) { return q && (n.label + ' ' + (n.sub || '')).toLowerCase().indexOf(q) >= 0; }).map(function (n) { return n.key; });
            self.view.select(keys);
            if (keys.length) { self.view.fit(keys); } else { self.toast(self.texts.search_none, 'info'); }
        });
    };

    App.prototype.syncToolbar = function () {
        var r = this.root, s = this.settings;
        r.querySelector('#wpg-layout').value = s.layout;
        r.querySelector('#wpg-edges').value = s.edges;
        r.querySelector('#wpg-packs').checked = !!s.packs;
        r.querySelector('#wpg-dests').checked = !!s.dests;
    };

    App.prototype.bindKeys = function () {
        var self = this;
        document.addEventListener('keydown', function (e) {
            if (/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName) || self.root.querySelector('.wpg-overlay.open')) {
                if (e.key === 'Escape') { self.editor.close(); }
                return;
            }
            var ctrl = e.ctrlKey || e.metaKey;
            if (ctrl && e.key.toLowerCase() === 'z' && !e.shiftKey) { e.preventDefault(); self.undo(); }
            else if (ctrl && (e.key.toLowerCase() === 'y' || (e.key.toLowerCase() === 'z' && e.shiftKey))) { e.preventDefault(); self.redo(); }
            else if (e.key === '+' || e.key === '=') { self.view.zoomAt(1.25); }
            else if (e.key === '-') { self.view.zoomAt(0.8); }
            else if (e.key === '0') { self.clearFocus(); self.view.fit(); }
            else if (e.key.toLowerCase() === 'f') {
                var keys = Object.keys(self.view.selected);
                if (keys.length) { self.view.fit(keys); }
            } else if (e.key.toLowerCase() === 'n') { self.newRule(); }
            else if (e.key === 'Escape') { self.clearFocus(); self.clearSelection(); }
        });
    };

    // ---------------------------------------------------------------------------------- layout / moves

    /** "Rearrange": automatic layout for every block (one undo step), saved as the new layout. */
    App.prototype.arrange = function (name) {
        if (name === 'free') { this.saveSettings({ layout: 'free' }); return; }
        var nodes = this.model.list();
        var before = this.model.snapshot(nodes.map(function (n) { return n.key; }));
        var after = L[name](nodes, this.model.edges);
        this.model.commit(before, after);
        this.view.render();
        this.view.fit();
        this.saveSettings({ layout: name });
        this.savePositions(after, true);
    };

    App.prototype.undo = function () { this.applyHistory(this.model.undo()); };
    App.prototype.redo = function () { this.applyHistory(this.model.redo()); };
    App.prototype.applyHistory = function (positions) {
        if (!positions) { return; }
        this.view.moveNodes(Object.keys(positions));
        this.minimap.update();
        var clean = {};
        Object.keys(positions).forEach(function (k) { if (positions[k]) { clean[k] = positions[k]; } });
        this.savePositions(clean);
    };

    // ------------------------------------------------------------------------------------ navigation

    /** Shortcuts of the "go to" list: mailboxes, packs, destinations. */
    App.prototype.fillGoto = function () {
        var select = this.root.querySelector('#wpg-goto');
        var t = this.texts;
        select.textContent = '';
        select.appendChild(h('option', { value: '', text: t.goto }));
        [['mailbox', t.kind_mailbox], ['pack', t.kind_pack], ['dest', t.kind_dest]].forEach(function (g) {
            var nodes = this.model.list(function (n) { return n.kind === g[0]; }).sort(function (a, b) { return a.label.localeCompare(b.label); });
            if (!nodes.length) { return; }
            var group = h('optgroup', { label: g[1] });
            nodes.forEach(function (n) { group.appendChild(h('option', { value: n.key, text: n.label })); });
            select.appendChild(group);
        }, this);
    };

    /** Focus mode: one block and what is linked to it (two steps for mailboxes: their rules and their destinations). */
    App.prototype.focusOn = function (key) {
        var node = this.model.nodes[key];
        if (!node) { return; }
        var keys = [key];
        var add = function (k) { if (keys.indexOf(k) < 0) { keys.push(k); } };
        this.model.neighbors(key).forEach(add);
        if (node.kind === 'mailbox' || node.kind === 'pack' || node.kind === 'dest') {
            keys.slice(1).forEach(function (k) {
                if (this.model.nodes[k] && this.model.nodes[k].kind === 'rule') { this.model.neighbors(k).forEach(add); }
            }, this);
        }
        this.view.focus = { keys: keys };
        this.view.render();
        this.view.select([key]);
        this.view.fit(keys);
        this.inspector.show(key);
        var banner = this.root.querySelector('.wpg-focus');
        banner.querySelector('.wpg-focus-label').textContent = this.texts.focus_on.replace(':name', node.label);
        banner.classList.add('open');
    };

    App.prototype.clearFocus = function () {
        if (!this.view.focus) { return; }
        this.view.focus = null;
        this.view.render();
        this.root.querySelector('.wpg-focus').classList.remove('open');
    };

    App.prototype.select = function (key) {
        this.view.select([key]);
        var p = this.model.pos[key];
        if (p) { this.view.centerOn(p[0] + 120, p[1] + 30); }
        this.inspector.show(key);
    };

    App.prototype.clearSelection = function () {
        this.view.select([]);
        this.inspector.hide();
    };

    App.prototype.open = function (key) {
        var node = this.model.nodes[key];
        if (!node) { return; }
        if (node.kind === 'rule') {
            var first = this.model.edges.filter(function (e) { return e.kind === 'applies' && e.from === key && e.workflow_id; })[0];
            if (first) { this.edit(first); }
        } else {
            this.focusOn(key);
        }
    };

    // ------------------------------------------------------------------------------------ changes

    App.prototype.newRule = function () {
        var selected = Object.keys(this.view.selected).map(function (k) { return this.model.nodes[k]; }, this)
            .filter(function (n) { return n && n.kind === 'mailbox'; })[0];
        var mailbox = selected || this.model.list(function (n) { return n.kind === 'mailbox'; })[0];
        if (!mailbox) { this.toast(this.texts.no_mailbox, 'error'); return; }
        this.editor.open({ mailboxId: mailbox.id });
    };

    App.prototype.edit = function (edge) {
        var mailbox = this.model.nodes[edge.to];
        this.editor.open({ mailboxId: mailbox.id, workflowId: edge.workflow_id });
    };

    App.prototype.toggle = function (edge, active, checkbox) {
        var self = this;
        this.api.toggle(edge.workflow_id, active).then(function () {
            self.toast(active ? self.texts.enabled : self.texts.disabled, 'ok');
            self.reload();
        }).catch(function (e) {
            if (checkbox) { checkbox.checked = !active; }
            self.toast(e.message, 'error');
        });
    };

    /** Link drawn from a rule to a mailbox: the rule is applied there (inactive), after confirmation. */
    App.prototype.connect = function (ruleKey, mailboxKey) {
        var self = this, rule = this.model.nodes[ruleKey], mailbox = this.model.nodes[mailboxKey];
        var source = this.model.edges.filter(function (e) { return e.kind === 'applies' && e.from === ruleKey && e.workflow_id; })[0];
        if (!rule || !mailbox || !source) { return; }
        if (!window.confirm(this.texts.apply_confirm.replace(':rule', rule.label).replace(':mailbox', mailbox.label))) { return; }
        this.api.apply(source.workflow_id, mailbox.id).then(function () {
            self.toast(self.texts.applied, 'ok');
            self.reload();
        }).catch(function (e) { self.toast(e.message, 'error'); });
    };

    /** Readable rule (IF … AND/OR … THEN …) for the side panel, with the labels of the catalog. */
    App.prototype.ruleText = function (workflowId) {
        var self = this, t = this.texts;
        return this.api.rule(workflowId).then(function (detail) {
            return self.catalogOf(detail.mailbox_id).then(function (c) {
                var cat = c.catalog, rule = detail.rule;
                var block = h('div', { class: 'wpg-sentence' });
                var label = function (kind, type) { return (cat[kind][type] && cat[kind][type].title) || type; };
                // local references (person, mailbox) shown by name, like in the editor
                var names = { '-1': t.unassigned, '-10': t.current_user, assignee: t.assignee, last_user: t.last_user };
                var refs = { user: c.users, mailbox: c.mailboxes };
                var val = function (kind, type, v) {
                    if (v === undefined || v === null || v === '') { return ''; }
                    var labels = (cat[kind][type] && cat[kind][type].value_labels) || {};
                    if (typeof v === 'object') { return v.body !== undefined ? String(v.body).replace(/<[^>]*>/g, '').slice(0, 80) : JSON.stringify(v); }
                    var ref = refs[(c.refs[kind] || {})[type]];
                    var found = ref && ref.filter(function (r) { return String(r.id) === String(v); })[0];
                    return labels[v] || (found && found.label) || (ref && names[v]) || String(v);
                };
                WPGraph.editorFormat.lanesOf(rule.conditions).forEach(function (lane, i) {
                    var line = h('div', { class: 'wpg-s-line' }, [h('span', { class: i ? 'wpg-and' : 'wpg-if', text: i ? t.and : t.if })]);
                    lane.forEach(function (cond, j) {
                        if (j) { line.appendChild(h('span', { class: 'wpg-or', text: t.or })); }
                        var opLabel = (cat.conditions[cond.type] && cat.conditions[cond.type].operator_labels[cond.operator]) || cond.operator;
                        line.appendChild(h('span', { class: 'wpg-s-cond' }, [h('b', { text: label('conditions', cond.type) }), ' ' + opLabel + ' ', h('code', { text: val('conditions', cond.type, cond.value) })]));
                    });
                    block.appendChild(line);
                });
                rule.actions.forEach(function (a, i) {
                    var sens = c.sensitive[a.type];
                    block.appendChild(h('div', { class: 'wpg-s-line' + (sens ? ' is-sensitive' : '') }, [
                        h('span', { class: i ? 'wpg-and' : 'wpg-then', text: i ? '+' : t.then }),
                        h('span', { class: 'wpg-s-cond' }, [h('b', { text: label('actions', a.type) }), ' ', h('code', { text: val('actions', a.type, a.value) })]),
                        sens ? icon('warn') : null
                    ]));
                });
                block.appendChild(h('div', { class: 'wpg-muted' }, [detail.active ? t.active : t.inactive, detail.edit_url ? ' · ' : '',
                    detail.edit_url ? h('a', { href: detail.edit_url, text: t.open_in_workflows }) : null]));
                return block;
            });
        });
    };

    App.prototype.toast = function (message, kind) {
        var box = this.root.querySelector('.wpg-toasts');
        var el = h('div', { class: 'wpg-toast is-' + (kind || 'info'), role: 'status', text: message });
        box.appendChild(el);
        setTimeout(function () { el.classList.add('out'); }, 3500);
        setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, 4200);
    };

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('.wpg-app');
        if (root) { WPGraph.app = new App(root); }
    });
})(window.WPGraph = window.WPGraph || {});
