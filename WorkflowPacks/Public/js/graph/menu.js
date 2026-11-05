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
        // the lists open: the menu, then its sub-menus (Windows-like); phone: the lists left, for "‹ back"
        this.panels = [];
        this.history = [];
        var self = this;
        document.addEventListener('pointerdown', function (e) { if (self.el && !self.contains(e.target)) { self.close(); } }, true);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { self.close(); } });
        // the page scrolled (not the menu itself, nor the scroll that brings the selected block into view on a phone,
        // right after a long press): the menu no longer faces its block
        window.addEventListener('scroll', function (e) {
            if (self.el && !self.contains(e.target) && Date.now() - self.shownAt > 600) { self.close(); }
        }, true);
    }

    Menu.prototype.close = function () {
        clearTimeout(this.hoverTimer);
        this.panels.forEach(function (p) { if (p.parentNode) { p.parentNode.removeChild(p); } });
        this.panels = [];
        this.history = [];
        this.el = null;
    };

    Menu.prototype.contains = function (node) {
        return this.panels.some(function (p) { return p.contains(node); });
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
        this.shownAt = Date.now();
        if (!keys.length) { return; }
        var app = this.app, t = this.t, self = this;
        var nodes = keys.map(function (k) { return app.model.nodes[k]; }).filter(Boolean);
        var rules = nodes.filter(function (n) { return n.kind === 'rule'; });
        var inst = this.instances(keys);
        var ids = inst.map(function (e) { return e.workflow_id; });
        var active = inst.filter(function (e) { return e.active; }).length;
        var previous = inst.filter(function (e) { return e.previous; }).length;
        var filing = inst.filter(function (e) { return e.files; }).map(function (e) { return e.workflow_id; });
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
            // rules filing into an e-mail folder: their e-mails back into it (moved since, by hand or elsewhere)
            if (filing.length) { state.push([t.menu_refile.replace(':count', filing.length), 'folder', function () { app.refileMany(filing); }]); }
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
        // one block: what can follow it (same list as its "+")
        if (nodes.length === 1 && WPGraph.next.can(nodes[0])) {
            groups.push([[t.menu_next, 'plus', function () { app.next.open(nodes[0].key, x, y); }]]);
        }
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
        // delete: what it does is shown and confirmed first (app.deleteBlocks)
        groups.push([[t.menu_delete, 'deleted', function () { app.deleteBlocks(keys); }]]);
        this.render(x, y, t.menu_title.replace(':count', keys.length), groups);
    };

    /**
     * A menu where the pointer is: a title, then groups of items ([label, icon, action, sub-items]). Sub-items open in
     * a menu of their own next to their item, like the context menus of Windows (hover or click, keyboard arrows); on a
     * phone (no room on the side) they replace the list, with "‹ back" at the top.
     */
    Menu.prototype.render = function (x, y, title, groups) {
        var self = this;
        this.close();
        var menu = this.panel([h('div', { class: 'wpg-menu-head', text: title })]);
        groups.forEach(function (items, gi) {
            if (gi) { menu.appendChild(h('div', { class: 'wpg-menu-sep' })); }
            items.forEach(function (item) { menu.appendChild(self.item(item, 0)); });
        });
        this.el = menu;
        this.place(menu, x, y);
        var first = menu.querySelector('button');
        if (first) { first.focus(); }
    };

    /** One list of the menu (the first one, or a sub-menu), inside the diagram (its colors), fixed on the screen. */
    Menu.prototype.panel = function (children) {
        var self = this;
        var panel = h('div', { class: 'wpg-menu', role: 'menu' }, children);
        panel.addEventListener('pointerenter', function () { clearTimeout(self.hoverTimer); });
        panel.addEventListener('keydown', function (e) {
            var buttons = Array.prototype.slice.call(panel.querySelectorAll('.wpg-menu-item'));
            var at = buttons.indexOf(document.activeElement);
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                var next = buttons[(at + (e.key === 'ArrowDown' ? 1 : buttons.length - 1)) % buttons.length];
                if (next) { next.focus(); }
            } else if (e.key === 'ArrowLeft') {
                e.preventDefault();
                var level = self.panels.indexOf(panel);
                if (level > 0) {
                    var owner = panel.wpgOwner;
                    self.closeFrom(level);
                    if (owner) { owner.focus(); }
                } else if (self.history.length) {
                    self.back();
                }
            }
        });
        this.app.root.appendChild(panel);
        this.panels.push(panel);
        return panel;
    };

    /** Inside the window, at (x, y) or as close as possible. */
    Menu.prototype.place = function (panel, x, y) {
        var r = panel.getBoundingClientRect();
        panel.style.left = Math.max(8, Math.min(x, window.innerWidth - r.width - 8)) + 'px';
        panel.style.top = Math.max(8, Math.min(y, window.innerHeight - r.height - 8)) + 'px';
    };

    Menu.prototype.narrow = function () {
        return window.innerWidth < 640;
    };

    /** [label, icon, action, sub-items], in the list `level` (0: the first one) */
    Menu.prototype.item = function (item, level) {
        var self = this;
        var button = h('button', { type: 'button', class: 'wpg-menu-item', role: 'menuitem' }, [icon(item[1]), h('span', { text: item[0] })]);
        var hover = function (fn, delay) {
            button.addEventListener('pointerenter', function (e) {
                if (e.pointerType !== 'mouse' || self.narrow()) { return; }
                clearTimeout(self.hoverTimer);
                self.hoverTimer = setTimeout(fn, delay);
            });
        };
        if (item[3]) {
            button.appendChild(h('span', { class: 'wpg-menu-more', text: '›' }));
            button.setAttribute('aria-haspopup', 'menu');
            button.setAttribute('aria-expanded', 'false');
            var open = function (focus) { self.openSub(level, button, item[0], item[3], focus); };
            button.addEventListener('click', function () { open(true); });
            button.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowRight') { e.preventDefault(); open(true); }
            });
            hover(function () { open(false); }, 120);
            return button;
        }
        // another item of this list: its sub-menu closes (after a moment, so that the mouse can go through)
        hover(function () { self.closeFrom(level + 1); }, 250);
        button.addEventListener('click', function () { self.close(); item[2](); });
        return button;
    };

    /** The sub-menu of an item: next to it (right, or left without room), or instead of the list on a phone. */
    Menu.prototype.openSub = function (level, button, title, items, focus) {
        var self = this;
        if (this.narrow()) { return this.drill(title, items); }
        var current = this.panels[level + 1];
        if (!current || current.wpgOwner !== button) {
            this.closeFrom(level + 1);
            current = this.panel([]);
            current.classList.add('wpg-menu-fly');
            current.wpgOwner = button;
            items.forEach(function (s) { current.appendChild(self.item(s, level + 1)); });
            button.classList.add('is-open');
            button.setAttribute('aria-expanded', 'true');
            var r = button.getBoundingClientRect(), w = current.getBoundingClientRect().width;
            var x = r.right + 2 + w > window.innerWidth - 8 ? r.left - w - 2 : r.right + 2;
            this.place(current, x, r.top - 7);
        }
        if (focus) {
            var first = current.querySelector('.wpg-menu-item');
            if (first) { first.focus(); }
        }
    };

    /** Closes the sub-menus from the list `level` on (its item in the list before is no longer open). */
    Menu.prototype.closeFrom = function (level) {
        if (!this.panels[level]) { return; }
        var owner = this.panels[level].wpgOwner;
        this.panels.splice(level).forEach(function (p) { if (p.parentNode) { p.parentNode.removeChild(p); } });
        if (owner) {
            owner.classList.remove('is-open');
            owner.setAttribute('aria-expanded', 'false');
        }
    };

    /** Phone: the sub-items replace the list; "‹ title" goes back. */
    Menu.prototype.drill = function (title, items) {
        var self = this, menu = this.panels[0];
        this.history.push(Array.prototype.slice.call(menu.childNodes));
        menu.textContent = '';
        var back = h('button', { type: 'button', class: 'wpg-menu-item wpg-menu-back' }, [h('span', { class: 'wpg-menu-arrow', text: '‹' }), h('span', { text: title })]);
        back.addEventListener('click', function () { self.back(); });
        menu.appendChild(back);
        menu.appendChild(h('div', { class: 'wpg-menu-sep' }));
        items.forEach(function (s) { menu.appendChild(self.item(s, 0)); });
        this.drilled(menu);
    };

    Menu.prototype.back = function () {
        var menu = this.panels[0], nodes = this.history.pop();
        if (!menu || !nodes) { return; }
        menu.textContent = '';
        nodes.forEach(function (n) { menu.appendChild(n); });
        this.drilled(menu);
    };

    Menu.prototype.drilled = function (menu) {
        menu.scrollTop = 0;
        var r = menu.getBoundingClientRect();
        this.place(menu, r.left, r.top);
        var first = menu.querySelector('.wpg-menu-item');
        if (first) { first.focus(); }
    };

    WPGraph.Menu = Menu;
})(window.WPGraph = window.WPGraph || {});
