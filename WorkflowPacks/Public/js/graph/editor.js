/*
 * Workflow Packs — diagram: simple rule editor.
 *
 *   IF   [Subject ▾] [contains ▾] [text]   OR …          <- one AND line = one group of OR conditions
 *   AND  …                                                   (only "AND of OR groups": what Workflows can run)
 *   THEN [Change status ▾] [Closed ▾]  …
 *
 * Types, operators and values come from the catalog of Workflows for the mailbox (nothing hard-coded). "Expert"
 * shows the rule in the pack format (JSON) and lets it be edited. The server checks everything again.
 */
(function (WPGraph) {
    'use strict';

    var h = WPGraph.h, icon = WPGraph.icon;

    function Editor(overlay, app) {
        this.overlay = overlay;
        this.app = app;
        this.t = app.texts;
    }

    /** counter of the lists of known values (ids of their <datalist>) */
    Editor.lists = 0;

    // ------------------------------------------------------------------------------- pack format <-> form

    function lanesOf(tree) {
        if (!tree) { return []; }
        if (tree.any) { return [tree.any.slice()]; }
        return (tree.all || []).map(function (item) { return item.any ? item.any.slice() : [item]; });
    }

    function treeOf(lanes) {
        var groups = lanes.filter(function (l) { return l.length; });
        if (!groups.length) { return null; }
        return { all: groups.map(function (l) { return l.length === 1 ? l[0] : { any: l }; }) };
    }

    Editor.prototype.toRule = function () {
        var s = this.state;
        var rule = { name: s.name, type: s.type, settings: s.settings };
        var tree = treeOf(s.lanes);
        if (tree) { rule.conditions = tree; }
        rule.actions = s.actions.map(function (a) {
            var out = { type: a.type };
            if (a.value !== undefined) { out.value = a.value; }
            return out;
        });
        return rule;
    };

    Editor.prototype.fromRule = function (rule) {
        this.state = {
            name: rule.name || '',
            type: rule.type || 'automatic',
            settings: rule.settings || { apply_to_prev: false, max_executions: 1 },
            lanes: lanesOf(rule.conditions),
            actions: (rule.actions || []).map(function (a) { return { type: a.type, value: a.value }; })
        };
    };

    // ---------------------------------------------------------------------------------------------- open

    /**
     * opts: { mailboxId, workflowId?, packRule?, rule? (new rule, filled in: next.js), addAction? (existing rule: an
     * action added at the end, to be completed and saved) }
     */
    Editor.prototype.open = function (opts) {
        var self = this, app = this.app;
        this.opts = opts;
        this.expert = false;
        this.errors = [];
        var load = opts.workflowId ? app.api.rule(opts.workflowId) : Promise.resolve(null);
        Promise.all([load, app.catalogOf(opts.mailboxId)]).then(function (res) {
            self.catalog = res[1];
            if (res[0]) {
                self.fromRule(res[0].rule);
                self.packInfo = res[0].pack;
                if (opts.addAction) { self.state.actions.push({ type: opts.addAction.type, value: opts.addAction.value }); }
            } else {
                self.packInfo = null;
                self.fromRule(opts.rule || { name: '', type: 'automatic', conditions: { all: [{ type: self.firstType('conditions', 'subject'), operator: '' }] }, actions: [] });
            }
            self.render();
            self.overlay.classList.add('open');
        }).catch(function (e) { app.toast(e.message, 'error'); });
    };

    Editor.prototype.close = function () {
        this.overlay.classList.remove('open');
        this.overlay.textContent = '';
    };

    Editor.prototype.firstType = function (kind, preferred) {
        var cat = this.catalog.catalog[kind];
        return cat[preferred] ? preferred : Object.keys(cat)[0];
    };

    // -------------------------------------------------------------------------------------------- render

    Editor.prototype.render = function () {
        var self = this, t = this.t, s = this.state;
        this.overlay.textContent = '';
        var dialog = h('div', { class: 'wpg-dialog', role: 'dialog', 'aria-modal': 'true' });
        this.overlay.appendChild(dialog);
        var mailboxName = (this.catalog.mailboxes.filter(function (m) { return m.id === self.opts.mailboxId; })[0] || {}).label || '';
        dialog.appendChild(h('div', { class: 'wpg-dialog-head' }, [
            h('strong', { text: this.opts.workflowId ? t.edit_rule : t.new_rule }),
            h('span', { class: 'wpg-muted', text: ' — ' + mailboxName }),
            h('button', { type: 'button', class: 'wpg-ins-close', 'aria-label': t.close, on: { click: this.close.bind(this) } }, ['×'])
        ]));
        var body = h('div', { class: 'wpg-dialog-body' });
        dialog.appendChild(body);
        if (this.packInfo) { body.appendChild(h('div', { class: 'alert alert-warning', text: t.pack_edit_hint })); }

        // mailbox (new rule only)
        if (!this.opts.workflowId) {
            var mb = h('select', { class: 'form-control input-sm' }, this.catalog.mailboxes.map(function (m) {
                var o = h('option', { value: m.id, text: m.label });
                if (m.id === self.opts.mailboxId) { o.selected = true; }
                return o;
            }));
            mb.addEventListener('change', function () {
                var rule = self.toRule();
                self.opts.mailboxId = parseInt(mb.value, 10);
                self.app.catalogOf(self.opts.mailboxId).then(function (c) { self.catalog = c; self.fromRule(rule); self.render(); });
            });
            body.appendChild(this.field(t.mailbox, mb));
        }
        var name = h('input', { class: 'form-control', value: s.name, maxlength: 75, placeholder: t.rule_name_ph });
        name.addEventListener('input', function () { s.name = name.value; self.sync(); });
        var type = h('select', { class: 'form-control input-sm' }, ['automatic', 'manual'].map(function (v) {
            var o = h('option', { value: v, text: t['type_' + v] });
            if (s.type === v) { o.selected = true; }
            return o;
        }));
        type.addEventListener('change', function () { s.type = type.value; self.sync(); });
        body.appendChild(h('div', { class: 'wpg-row2' }, [this.field(t.rule_name, name), this.field(t.rule_type, type)]));

        // IF
        body.appendChild(h('div', { class: 'wpg-ed-sec' }, [h('span', { class: 'wpg-if', text: t.if }), ' ' + t.if_help]));
        s.lanes.forEach(function (lane, li) {
            if (li > 0) { body.appendChild(h('div', { class: 'wpg-op-and', text: t.and })); }
            var box = h('div', { class: 'wpg-lane' });
            lane.forEach(function (c, ci) {
                if (ci > 0) { box.appendChild(h('div', { class: 'wpg-op-or', text: t.or })); }
                box.appendChild(self.conditionRow(lane, ci));
            });
            box.appendChild(h('button', { type: 'button', class: 'btn btn-link btn-xs', on: { click: function () {
                lane.push({ type: self.firstType('conditions', 'subject'), operator: '' });
                self.render();
            } } }, ['+ ' + t.add_or]));
            body.appendChild(box);
        });
        body.appendChild(h('button', { type: 'button', class: 'btn btn-default btn-xs', on: { click: function () {
            s.lanes.push([{ type: self.firstType('conditions', 'subject'), operator: '' }]);
            self.render();
        } } }, ['+ ' + t.add_and]));
        // timer rule in one click: "AND created more than 7 days ago" (Workflows checks it again and again)
        if ((this.catalog.timers || []).indexOf('created') >= 0) {
            body.appendChild(h('button', { type: 'button', class: 'btn btn-default btn-xs wpg-add-timer', title: t.timer_help, on: { click: function () {
                s.lanes.push([{ type: 'created', operator: 'not_in_last', value: { number: 7, metric: 'd' } }]);
                self.render();
            } } }, [icon('timer'), ' ' + t.add_timer]));
        }

        // THEN
        body.appendChild(h('div', { class: 'wpg-ed-sec' }, [h('span', { class: 'wpg-then', text: t.then }), ' ' + t.then_help]));
        s.actions.forEach(function (a, ai) { body.appendChild(self.actionRow(ai)); });
        body.appendChild(h('button', { type: 'button', class: 'btn btn-default btn-xs', on: { click: function () {
            s.actions.push({ type: self.firstType('actions', 'note') });
            self.render();
        } } }, ['+ ' + t.add_action]));

        // options
        if (!this.opts.workflowId) {
            this.activate = h('input', { type: 'checkbox' });
            body.appendChild(h('label', { class: 'wpg-check' }, [this.activate, ' ' + t.activate_now]));
            body.appendChild(h('p', { class: 'wpg-hint', text: t.new_inactive_hint }));
        }

        // expert: the rule in the pack format, editable
        var expertToggle = h('label', { class: 'wpg-check' }, [h('input', { type: 'checkbox' }), ' ' + t.expert]);
        var json = h('textarea', { class: 'form-control wpg-mono', rows: 10, spellcheck: 'false' });
        var jsonBox = h('div', { class: 'wpg-expert' + (this.expert ? ' open' : '') }, [json, h('button', { type: 'button', class: 'btn btn-default btn-xs', on: { click: function () {
            try {
                self.fromRule(JSON.parse(json.value));
                self.render();
            } catch (e) {
                self.errors = [t.json_invalid + ' ' + e.message];
                self.renderErrors();
            }
        } } }, [t.apply_json])]);
        expertToggle.firstChild.checked = this.expert;
        expertToggle.firstChild.addEventListener('change', function () { self.expert = this.checked; jsonBox.classList.toggle('open', self.expert); self.sync(); });
        this.json = json;
        body.appendChild(expertToggle);
        body.appendChild(jsonBox);
        this.errorBox = h('div', { class: 'wpg-errors' });
        body.appendChild(this.errorBox);
        this.renderErrors();
        this.sync();

        dialog.appendChild(h('div', { class: 'wpg-dialog-foot' }, [
            h('button', { type: 'button', class: 'btn btn-default', on: { click: this.close.bind(this) } }, [t.cancel]),
            h('button', { type: 'button', class: 'btn btn-primary', on: { click: this.save.bind(this) } }, [icon('closed'), ' ' + t.save])
        ]));
    };

    Editor.prototype.field = function (label, control) {
        return h('div', { class: 'form-group' }, [h('label', { text: label }), control]);
    };

    Editor.prototype.sync = function () {
        if (this.json && this.expert) { this.json.value = JSON.stringify(this.toRule(), null, 2); }
    };

    Editor.prototype.renderErrors = function () {
        if (!this.errorBox) { return; }
        this.errorBox.textContent = '';
        this.errors.forEach(function (e) { this.errorBox.appendChild(h('div', { class: 'alert alert-danger', text: e })); }, this);
    };

    function select(options, current, onChange, cls) {
        var s = h('select', { class: 'form-control input-sm ' + (cls || '') }, options.map(function (o) {
            var opt = h('option', { value: o[0], text: o[1] });
            if (String(o[0]) === String(current)) { opt.selected = true; }
            return opt;
        }));
        s.addEventListener('change', function () { onChange(s.value); });
        return s;
    }

    /** Options of a catalog list, grouped by the group of Workflows (People, Conversation…). */
    Editor.prototype.typeOptions = function (kind) {
        var cat = this.catalog.catalog[kind];
        return Object.keys(cat).map(function (k) { return [k, (cat[k].group ? cat[k].group + ' › ' : '') + (cat[k].title || k)]; });
    };

    Editor.prototype.conditionRow = function (lane, index) {
        var self = this, t = this.t, c = lane[index];
        var cfg = this.catalog.catalog.conditions[c.type] || { operators: null, values: null, operator_labels: {}, value_labels: {} };
        var ops = (cfg.operators || []).map(function (o) { return [o, (cfg.operator_labels || {})[o] || o]; });
        if (ops.length && ops.map(function (o) { return o[0]; }).indexOf(c.operator) < 0) { c.operator = ops[0][0]; }
        var row = h('div', { class: 'wpg-cond' }, [
            select(this.typeOptions('conditions'), c.type, function (v) { c.type = v; c.operator = ''; delete c.value; self.render(); }, 'wpg-w-type'),
            ops.length ? select(ops, c.operator, function (v) { c.operator = v; self.sync(); }, 'wpg-w-op') : h('input', { class: 'form-control input-sm wpg-w-op', value: c.operator || '', placeholder: 'operator', on: { input: function () { c.operator = this.value; self.sync(); } } }),
            this.valueControl(cfg, c, c.operator === 'regex' ? t.regex_ph : t.value_ph),
            h('button', { type: 'button', class: 'btn btn-link btn-xs wpg-del', title: t.remove, on: { click: function () {
                lane.splice(index, 1);
                self.state.lanes = self.state.lanes.filter(function (l) { return l.length; });
                self.render();
            } } }, ['×'])
        ]);
        return row;
    };

    /** Value of a condition: closed list -> select, no value -> nothing, else text. */
    Editor.prototype.valueControl = function (cfg, item, placeholder) {
        var self = this, t = this.t;
        // date condition: a delay, number + unit (what Workflows expects: { number, metric })
        if ((this.catalog.timers || []).indexOf(item.type) >= 0) {
            if (!item.value || typeof item.value !== 'object') { item.value = { number: 7, metric: 'd' }; }
            var number = h('input', { type: 'number', min: 1, max: 100000, step: 1, class: 'form-control input-sm wpg-w-num', value: item.value.number,
                'aria-label': t.timer_number, on: { input: function () { item.value.number = Math.max(1, parseInt(this.value, 10) || 1); self.sync(); } } });
            var unit = select([['i', t.unit_i], ['h', t.unit_h], ['d', t.unit_d]], item.value.metric || 'd',
                function (v) { item.value.metric = v; self.sync(); }, 'wpg-w-unit');
            return h('span', { class: 'wpg-w-val wpg-timer', title: t.timer_help }, [h('span', { class: 'wpg-timer-ic' }, [icon('timer')]), number, unit]);
        }
        if (Array.isArray(cfg.values) && cfg.values.length === 0) {
            delete item.value;
            return h('span', { class: 'wpg-w-val wpg-muted', text: '—' });
        }
        if (Array.isArray(cfg.values) && cfg.values.length) {
            if (item.value === undefined || cfg.values.indexOf(String(item.value)) < 0) { item.value = cfg.values[0]; }
            return select(cfg.values.map(function (v) { return [v, (cfg.value_labels || {})[v] || v]; }), item.value, function (v) { item.value = v; self.sync(); }, 'wpg-w-val');
        }
        var text = typeof item.value === 'object' && item.value !== null ? JSON.stringify(item.value) : (item.value === undefined ? '' : String(item.value));
        // known values (e.g. e-mail tags): a free text, with the known ones as buttons and in the browser's list
        var known = cfg.suggest && typeof cfg.suggest === 'object' ? Object.keys(cfg.suggest) : [];
        if (known.length) {
            var listId = 'wpg-suggest-' + (++Editor.lists);
            var buttons = [];
            var mark = function () { buttons.forEach(function (b) { b.classList.toggle('is-on', b.getAttribute('data-v') === String(item.value || '')); }); };
            var input = h('input', { class: 'form-control input-sm', value: text, placeholder: t.tag_ph || placeholder, list: listId, on: { input: function () {
                item.value = this.value.trim();
                mark();
                self.sync();
            } } });
            buttons = known.map(function (v) {
                return h('button', { type: 'button', class: 'wpg-tag-btn', 'data-v': v, title: cfg.suggest[v], text: v, on: { click: function () {
                    item.value = v;
                    input.value = v;
                    mark();
                    self.sync();
                } } });
            });
            mark();
            return h('span', { class: 'wpg-w-val wpg-tags' }, [input,
                h('datalist', { id: listId }, known.map(function (v) { return h('option', { value: v, label: cfg.suggest[v] }); })),
                h('span', { class: 'wpg-tag-list', title: t.tag_known }, buttons)]);
        }
        return h('input', { class: 'form-control input-sm wpg-w-val', value: text, placeholder: placeholder, on: { input: function () {
            var v = this.value;
            if (/^[\[{]/.test(v)) { try { v = JSON.parse(v); } catch (e) { /* kept as text */ } }
            item.value = v;
            self.sync();
        } } });
    };

    Editor.prototype.actionRow = function (index) {
        var self = this, t = this.t, a = this.state.actions[index], c = this.catalog;
        var cfg = c.catalog.actions[a.type] || { values: null, value_labels: {} };
        var sensitive = c.sensitive[a.type];
        var row = h('div', { class: 'wpg-act' + (sensitive ? ' is-sensitive' : '') }, [
            select(this.typeOptions('actions'), a.type, function (v) { a.type = v; delete a.value; self.render(); }, 'wpg-w-type')
        ]);
        var box = h('div', { class: 'wpg-act-value' });
        row.appendChild(box);
        var ref = (c.refs.actions || {})[a.type];
        if (c.json_actions.indexOf(a.type) >= 0) {
            // reply / email the customer / forward / note: object with a body (HTML) and, depending on the type, addresses
            if (typeof a.value !== 'object' || a.value === null || Array.isArray(a.value)) { a.value = { body: '' }; }
            var fields = a.type === 'forward' ? ['to', 'cc', 'bcc', 'body'] : (a.type === 'email_customer' ? ['subject', 'cc', 'bcc', 'body'] : (a.type === 'note' ? ['body'] : ['cc', 'bcc', 'body']));
            fields.forEach(function (f) {
                var input = h(f === 'body' ? 'textarea' : 'input', { class: 'form-control input-sm', rows: 3, placeholder: t['field_' + f] || f });
                input.value = a.value[f] || '';
                input.addEventListener('input', function () { a.value[f] = input.value; self.sync(); });
                box.appendChild(input);
            });
        } else if (ref === 'user' && a.type === 'notification') {
            var current = Array.isArray(a.value) ? a.value.map(String) : [];
            a.value = current;
            [['assignee', t.assignee], ['last_user', t.last_user]].concat(c.users.map(function (u) { return [String(u.id), u.label]; })).forEach(function (u) {
                var cb = h('input', { type: 'checkbox' });
                cb.checked = current.indexOf(u[0]) >= 0;
                cb.addEventListener('change', function () {
                    var i = a.value.indexOf(u[0]);
                    if (cb.checked && i < 0) { a.value.push(u[0]); } else if (!cb.checked && i >= 0) { a.value.splice(i, 1); }
                    self.sync();
                });
                box.appendChild(h('label', { class: 'wpg-check' }, [cb, ' ' + u[1]]));
            });
        } else if (ref === 'user') {
            var users = [['-1', t.unassigned], ['-10', t.current_user]].concat(c.users.map(function (u) { return [String(u.id), u.label]; }));
            if (a.value === undefined) { a.value = users[0][0]; }
            box.appendChild(select(users, a.value, function (v) { a.value = v; self.sync(); }));
        } else if (ref === 'mailbox') {
            var boxes = c.mailboxes.map(function (m) { return [String(m.id), m.label]; });
            if (a.value === undefined && boxes.length) { a.value = boxes[0][0]; }
            box.appendChild(select(boxes, a.value, function (v) { a.value = v; self.sync(); }));
        } else {
            box.appendChild(this.valueControl(cfg, a, t.value_ph));
        }
        if (sensitive) {
            row.appendChild(h('span', { class: 'wpg-sens', title: t['sensitive_' + sensitive] }, [icon('warn'), ' ' + (t['sensitive_' + sensitive] || '')]));
        }
        row.appendChild(h('button', { type: 'button', class: 'btn btn-link btn-xs wpg-del', title: t.remove, on: { click: function () {
            self.state.actions.splice(index, 1);
            self.render();
        } } }, ['×']));
        return row;
    };

    Editor.prototype.save = function () {
        var self = this, app = this.app;
        this.errors = [];
        this.renderErrors();
        app.api.saveRule({
            mailbox_id: this.opts.mailboxId,
            workflow_id: this.opts.workflowId || null,
            rule: this.toRule(),
            activate: this.activate ? this.activate.checked : false
        }).then(function (res) {
            self.close();
            app.toast(res.pack_modified ? self.t.saved_pack_modified : self.t.saved_rule, 'ok');
            app.reload();
        }).catch(function (e) {
            self.errors = (e.data && e.data.errors) || [e.message];
            self.renderErrors();
        });
    };

    WPGraph.Editor = Editor;
    WPGraph.editorFormat = { lanesOf: lanesOf, treeOf: treeOf };
})(window.WPGraph = window.WPGraph || {});
