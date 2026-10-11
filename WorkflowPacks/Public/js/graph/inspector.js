/*
 * Workflow Packs — diagram: side panel of the selected block or link (details + simple actions).
 * Every text from the server is inserted with textContent (never as HTML).
 */
(function (WPGraph) {
    'use strict';

    var I = WPGraph.icons;

    function h(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') { node.textContent = attrs[k]; } else if (k === 'on') {
                Object.keys(attrs.on).forEach(function (ev) { node.addEventListener(ev, attrs.on[ev]); });
            } else { node.setAttribute(k, attrs[k]); }
        });
        (children || []).forEach(function (c) { if (c) { node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
        return node;
    }

    function icon(name) {
        var span = h('span', { class: 'wpg-ic' });
        span.innerHTML = I.html(name); // constant markup of this module, no user data
        return span;
    }

    function Inspector(panel, app) {
        this.panel = panel;
        this.app = app;
        this.t = app.texts;
        this.current = null;
    }

    Inspector.prototype.hide = function () {
        this.current = null;
        this.panel.classList.remove('open');
        this.panel.textContent = '';
    };

    Inspector.prototype.refresh = function () {
        if (this.current) { this[this.current.method].apply(this, this.current.args); }
    };

    Inspector.prototype.frame = function (node, kindLabel) {
        this.panel.textContent = '';
        this.panel.classList.add('open');
        var head = h('div', { class: 'wpg-ins-head kind-' + node.kind + (node.dest ? ' dest-' + node.dest : '') }, [
            h('span', { class: 'wpg-ins-pill' }, [icon(I.of(node))]),
            h('div', { class: 'wpg-ins-title' }, [h('strong', { text: node.label }), h('small', { text: kindLabel })]),
            h('button', { type: 'button', class: 'wpg-ins-close', title: this.t.close, 'aria-label': this.t.close, on: { click: this.app.clearSelection.bind(this.app) } }, ['×'])
        ]);
        this.panel.appendChild(head);
        var body = h('div', { class: 'wpg-ins-body' });
        this.panel.appendChild(body);
        if (node.sub) { body.appendChild(h('p', { class: 'wpg-muted', text: node.sub })); }
        return body;
    };

    Inspector.prototype.section = function (body, title) {
        body.appendChild(h('div', { class: 'wpg-ins-sec', text: title }));
    };

    Inspector.prototype.button = function (label, iconName, onClick, primary) {
        return h('button', { type: 'button', class: 'btn btn-sm ' + (primary ? 'btn-primary' : 'btn-default'), on: { click: onClick } }, [icon(iconName), ' ' + label]);
    };

    /** One Workflows rule (= one link mailbox -> rule): state, on/off switch, edit. */
    Inspector.prototype.instanceRow = function (edge, showRule) {
        var app = this.app, t = this.t;
        var other = app.model.nodes[showRule ? edge.from : edge.to];
        var row = h('div', { class: 'wpg-inst state-' + edge.state }, [
            h('span', { class: 'wpg-chip state-' + edge.state, text: t['edge_' + edge.state] }),
            h('span', { class: 'wpg-inst-name', text: other ? other.label : '' })
        ]);
        if (edge.workflow_id) {
            var sw = h('input', { type: 'checkbox', title: t.toggle_active });
            sw.checked = !!edge.active;
            sw.addEventListener('change', function () { app.toggle(edge, sw.checked, sw); });
            row.appendChild(h('label', { class: 'wpg-switch' }, [sw, h('span', {})]));
            row.appendChild(h('button', { type: 'button', class: 'btn btn-link btn-xs', title: t.edit, on: { click: function () { app.edit(edge); } } }, [icon('edit')]));
        }
        return row;
    };

    Inspector.prototype.show = function (key) {
        var node = this.app.model.nodes[key];
        if (!node) { return this.hide(); }
        this.current = { method: 'show', args: [key] };
        this['show_' + node.kind](node);
    };

    Inspector.prototype.show_mailbox = function (node) {
        var app = this.app, t = this.t, self = this;
        var body = this.frame(node, t.kind_mailbox);
        var rules = app.model.edges.filter(function (e) { return e.kind === 'applies' && e.to === node.key; });
        this.section(body, t.rules_of_mailbox.replace(':count', rules.length));
        rules.forEach(function (e) { body.appendChild(self.instanceRow(e, true)); });
        if (!rules.length) { body.appendChild(h('p', { class: 'wpg-muted', text: t.no_rules })); }
        body.appendChild(h('div', { class: 'wpg-ins-actions' }, [
            this.button(t.new_rule_here, 'plus', function () { app.editor.open({ mailboxId: node.id }); }, true),
            this.button(t.focus_mailbox, 'focus', function () { app.focusOn(node.key); }),
            app.cfg.urls.workflows ? h('a', { class: 'btn btn-sm btn-default', href: app.cfg.urls.workflows.replace('__ID__', node.id) }, [icon('external'), ' ' + t.open_workflows]) : null
        ]));
    };

    Inspector.prototype.show_rule = function (node) {
        var app = this.app, t = this.t, self = this;
        var body = this.frame(node, node.origin === 'pack' ? t.kind_pack_rule.replace(':pack', node.pack) : t.kind_rule);
        var instances = app.model.edges.filter(function (e) { return e.kind === 'applies' && e.from === node.key; });
        var first = instances.filter(function (e) { return e.workflow_id; })[0];
        if (first) {
            var detail = h('div', { class: 'wpg-rule-detail' }, [h('p', { class: 'wpg-muted', text: t.loading })]);
            body.appendChild(detail);
            app.ruleText(first.workflow_id).then(function (block) { detail.textContent = ''; detail.appendChild(block); })
                .catch(function (e) { detail.textContent = e.message; });
        }
        this.section(body, t.applied_to.replace(':count', instances.length));
        instances.forEach(function (e) { body.appendChild(self.instanceRow(e, false)); });
        if (node.origin === 'pack') { body.appendChild(h('p', { class: 'wpg-hint', text: t.pack_edit_hint })); }
        var sends = app.model.edges.filter(function (e) { return e.kind === 'sends' && e.from === node.key; });
        if (sends.length) {
            this.section(body, t.sends_to);
            sends.forEach(function (e) {
                var d = app.model.nodes[e.to];
                body.appendChild(h('div', { class: 'wpg-inst' }, [icon(I.of(d)), h('span', { class: 'wpg-inst-name', text: d.label })]));
            });
        }
        // apply to another mailbox (same as drawing a link)
        var targets = app.model.list(function (n) { return n.kind === 'mailbox' && !app.model.linked(node.key, n.key); });
        if (first && targets.length) {
            var select = h('select', { class: 'form-control input-sm' }, targets.map(function (n) { return h('option', { value: n.key, text: n.label }); }));
            body.appendChild(h('div', { class: 'wpg-ins-sec', text: t.apply_to }));
            body.appendChild(h('div', { class: 'wpg-apply' }, [select, this.button(t.apply, 'move', function () { app.connect(node.key, select.value); })]));
        }
        body.appendChild(h('div', { class: 'wpg-ins-actions' }, [
            first ? this.button(t.edit, 'edit', function () { app.edit(first); }, true) : null,
            this.button(t.focus_rule, 'focus', function () { app.focusOn(node.key); }),
            node.origin === 'pack' && app.cfg.urls.reimport ? h('a', { class: 'btn btn-sm btn-default', href: app.cfg.urls.reimport.replace('__ID__', encodeURIComponent(node.pack)) }, [icon('package'), ' ' + t.update_pack]) : null
        ]));
    };

    Inspector.prototype.show_pack = function (node) {
        var app = this.app, t = this.t;
        var body = this.frame(node, t.kind_pack);
        var rules = app.model.edges.filter(function (e) { return e.kind === 'contains' && e.from === node.key; });
        this.section(body, t.pack_rules.replace(':count', rules.length));
        rules.forEach(function (e) {
            var r = app.model.nodes[e.to];
            body.appendChild(h('button', { type: 'button', class: 'wpg-inst wpg-link', on: { click: function () { app.select(r.key); } } }, [icon(I.of(r)), h('span', { class: 'wpg-inst-name', text: r.label })]));
        });
        body.appendChild(h('div', { class: 'wpg-ins-actions' }, [
            this.button(t.focus_pack, 'focus', function () { app.focusOn(node.key); }),
            app.cfg.urls.reimport ? h('a', { class: 'btn btn-sm btn-default', href: app.cfg.urls.reimport.replace('__ID__', encodeURIComponent(node.pack)) }, [icon('package'), ' ' + t.update_pack]) : null
        ]));
    };

    Inspector.prototype.show_dest = function (node) {
        var app = this.app, t = this.t;
        var body = this.frame(node, t['kind_dest_' + node.dest] || t.kind_dest);
        if (node.warn) { body.appendChild(h('p', { class: 'wpg-warn-text' }, [icon('warn'), ' ' + node.warn])); }
        // e-mail folders: the rules filing into this folder or one of its sub-folders
        var ruleKeys = node.filed_by || app.model.edges.filter(function (e) { return e.kind === 'sends' && e.to === node.key; })
            .map(function (e) { return e.from; });
        this.section(body, (node.filed_by ? t.filed_rules : t.dest_rules).replace(':count', ruleKeys.length));
        ruleKeys.forEach(function (key) {
            var r = app.model.nodes[key];
            if (!r) { return; }
            body.appendChild(h('button', { type: 'button', class: 'wpg-inst wpg-link', on: { click: function () { app.select(r.key); } } }, [icon(I.of(r)), h('span', { class: 'wpg-inst-name', text: r.label })]));
        });
        body.appendChild(h('p', { class: 'wpg-hint', text: t.dest_hint }));
        body.appendChild(h('div', { class: 'wpg-ins-actions' }, [this.button(t.focus_dest, 'focus', function () { app.focusOn(node.key); })]));
    };

    /** A link mailbox -> rule = one Workflows rule in that mailbox. */
    Inspector.prototype.showEdge = function (edge) {
        if (!edge || edge.kind !== 'applies') { return this.hide(); }
        this.current = { method: 'showEdge', args: [edge] };
        var app = this.app, t = this.t;
        var rule = app.model.nodes[edge.from], mailbox = app.model.nodes[edge.to];
        var body = this.frame({ kind: 'rule', label: edge.name || rule.label, type: rule.type, sub: mailbox ? mailbox.label : '' }, t.kind_instance);
        body.appendChild(this.instanceRow(edge, false));
        body.appendChild(h('p', { class: 'wpg-hint', text: t['edge_help_' + edge.state] || '' }));
        if (edge.workflow_id) {
            var detail = h('div', { class: 'wpg-rule-detail' });
            body.appendChild(detail);
            app.ruleText(edge.workflow_id).then(function (block) { detail.appendChild(block); }).catch(function () {});
        }
    };

    WPGraph.Inspector = Inspector;
    WPGraph.h = h;
    WPGraph.icon = icon;
})(window.WPGraph = window.WPGraph || {});
