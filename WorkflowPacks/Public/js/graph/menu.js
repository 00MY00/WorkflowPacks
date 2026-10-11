/*
 * Workflow Packs — diagram: quick actions where the mouse is (right click, or end of a selection rectangle), for the
 * selected blocks. Only the actions that make sense for the selection are listed; every change goes through the
 * server (permissions, checks) like the side panel.
 */
(function (WPGraph) {
    'use strict';

    var h = WPGraph.h, icon = WPGraph.icon;

    function Menu(app) {
        this.app = app;
        this.t = app.texts;
        this.el = null;
        var self = this;
        document.addEventListener('pointerdown', function (e) { if (self.el && !self.el.contains(e.target)) { self.close(); } }, true);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { self.close(); } });
        window.addEventListener('scroll', function () { self.close(); }, true);
    }

    Menu.prototype.close = function () {
        if (this.el) { this.el.parentNode.removeChild(this.el); this.el = null; }
    };

    /** Workflows rules behind the selection: a rule in every mailbox where it applies, a mailbox with all its rules. */
    Menu.prototype.instances = function (keys) {
        var seen = {}, out = [];
        this.app.model.edges.forEach(function (e) {
            if (e.kind === 'applies' && e.workflow_id && !seen[e.workflow_id] && (keys.indexOf(e.from) >= 0 || keys.indexOf(e.to) >= 0)) {
                seen[e.workflow_id] = true;
                out.push(e);
            }
        });
        return out;
    };

    Menu.prototype.show = function (x, y, keys) {
        this.close();
        if (!keys.length) { return; }
        var app = this.app, t = this.t, self = this;
        var nodes = keys.map(function (k) { return app.model.nodes[k]; }).filter(Boolean);
        var rules = nodes.filter(function (n) { return n.kind === 'rule'; });
        var inst = this.instances(keys);
        var ids = inst.map(function (e) { return e.workflow_id; });
        var active = inst.filter(function (e) { return e.active; }).length;
        var previous = inst.filter(function (e) { return e.previous; }).length;
        var sensitive = inst.some(function (e) { return (e.sensitive || []).length; });
        var groups = [];

        // one rule: open it
        if (nodes.length === 1 && rules.length === 1) {
            groups.push([[t.menu_edit, 'edit', function () { app.open(rules[0].key); }]]);
        }
        // state of the rules
        if (ids.length) {
            var state = [];
            if (active < ids.length) { state.push([t.menu_enable.replace(':count', ids.length - active), 'zap', function () { app.toggleMany(ids, true); }]); }
            if (active) { state.push([t.menu_disable.replace(':count', active), 'hand', function () { app.toggleMany(ids, false); }]); }
            if (previous < ids.length) {
                state.push([t.menu_prev_on.replace(':count', ids.length - previous), 'undo', function () {
                    if (window.confirm(sensitive ? t.prev_sensitive_confirm : t.prev_confirm)) { app.previousMany(ids, true); }
                }]);
            }
            if (previous) { state.push([t.menu_prev_off.replace(':count', previous), 'redo', function () { app.previousMany(ids, false); }]); }
            groups.push(state);
        }
        // the selected rules elsewhere: another mailbox, a pack file
        var more = [];
        if (rules.length) {
            var mailboxes = app.model.list(function (n) { return n.kind === 'mailbox'; });
            if (mailboxes.length > 1) {
                more.push([t.menu_apply_to, 'move', null, mailboxes.map(function (mb) {
                    return [mb.label, 'mailbox', function () { app.applyMany(rules.map(function (r) { return r.key; }), mb); }];
                })]);
            }
        }
        if (ids.length) {
            more.push([t.menu_export, 'external', function () { app.exportRules(inst); }]);
        }
        if (more.length) { groups.push(more); }
        // the blocks themselves
        var blocks = [[t.menu_focus, 'focus', function () { app.focusKeys(keys); }]];
        if (nodes.length > 1) {
            blocks.push([t.menu_align_left, 'fit', function () { app.arrangeSelection(keys, 'left'); }]);
            blocks.push([t.menu_align_top, 'fit', function () { app.arrangeSelection(keys, 'top'); }]);
        }
        if (nodes.length > 2) {
            blocks.push([t.menu_distribute_v, 'fit', function () { app.arrangeSelection(keys, 'vertical'); }]);
            blocks.push([t.menu_distribute_h, 'fit', function () { app.arrangeSelection(keys, 'horizontal'); }]);
        }
        blocks.push([t.menu_autosize, 'minus', function () { app.autoSize(keys); }]);
        blocks.push([t.menu_deselect, 'search', function () { app.clearSelection(); }]);
        groups.push(blocks);

        var menu = h('div', { class: 'wpg-menu', role: 'menu' }, [h('div', { class: 'wpg-menu-head', text: t.menu_title.replace(':count', keys.length) })]);
        groups.forEach(function (items, gi) {
            if (gi) { menu.appendChild(h('div', { class: 'wpg-menu-sep' })); }
            items.forEach(function (item) { menu.appendChild(self.item(item)); });
        });
        // inside the diagram (its colors), fixed where the mouse is
        app.root.appendChild(menu);
        this.el = menu;
        // inside the window
        var r = menu.getBoundingClientRect();
        menu.style.left = Math.max(8, Math.min(x, window.innerWidth - r.width - 8)) + 'px';
        menu.style.top = Math.max(8, Math.min(y, window.innerHeight - r.height - 8)) + 'px';
        var first = menu.querySelector('button');
        if (first) { first.focus(); }
    };

    /** [label, icon, action, sub-items] */
    Menu.prototype.item = function (item) {
        var self = this;
        var button = h('button', { type: 'button', class: 'wpg-menu-item', role: 'menuitem' }, [icon(item[1]), h('span', { text: item[0] })]);
        if (item[3]) {
            button.appendChild(h('span', { class: 'wpg-menu-more', text: '›' }));
            var sub = h('div', { class: 'wpg-menu wpg-menu-sub' });
            item[3].forEach(function (s) { sub.appendChild(self.item(s)); });
            var wrap = h('div', { class: 'wpg-menu-parent' }, [button, sub]);
            button.addEventListener('click', function () { wrap.classList.toggle('open'); });
            return wrap;
        }
        button.addEventListener('click', function () { self.close(); item[2](); });
        return button;
    };

    WPGraph.Menu = Menu;
})(window.WPGraph = window.WPGraph || {});
