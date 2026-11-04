/*
 * Workflow Packs — diagram: "what next?" for a block, like the arrows of draw.io. The "+" next to a selected block
 * (or "Add a next step…" in its quick actions and its side panel) lists what can follow it:
 *
 *   mailbox         a new rule; file its new e-mails into a folder; close the tickets left without answer; the
 *                   e-mails classified as spam (e-mail tags of RefreshGlobal, when installed)
 *   rule            then file into an e-mail folder / change the status / assign (the actions it does not do yet)
 *   e-mail folder   delete its e-mails after a delay (administrators); file a part into a sub-folder
 *   status          after a delay in that state: delete the tickets, or close them
 *
 * A rule is never saved from here: the editor opens, filled in, to be checked and saved (created disabled, like
 * every new rule). Only what the catalog of Workflows offers for the mailbox is proposed.
 *
 * suggest() is a pure function (tested with Node, tests/js/next.test.js); Next wires it to the application.
 */
(function (root) {
    'use strict';

    var STATUS = { active: '1', pending: '2', closed: '3', spam: '4' };
    var FOLDER = 'wp_imap_folder';

    /** Can this block have a next step at all (the "+" is drawn only on those)? */
    function can(node) {
        if (!node) { return false; }
        if (node.kind === 'mailbox' || node.kind === 'rule') { return true; }
        // flow view: conditions, branch, delay or action of a rule
        if (node.kind === 'step') { return node.dest === 'cond' || !!node.workflow_id; }
        return node.kind === 'dest' && node.dest !== 'cleanup';
    }

    /**
     * What can follow a block.
     *
     * @param node block of the diagram
     * @param ctx  { catalog: { conditions: {type: …}, actions: {type: …} } of the mailbox, admin: bool,
     *               ruleActions: [types the rule already does], folderLabel: shown name of a folder }
     * @return [{ id, icon, label: text key, params: {…} for the text, run: { editor: { rule } | { addAction },
     *            retention: true } }]
     */
    function suggest(node, ctx) {
        var out = [];
        if (!can(node)) { return out; }
        var cat = (ctx && ctx.catalog) || { conditions: {}, actions: {} };
        var hasC = function (type) { return !!(cat.conditions || {})[type]; };
        var hasA = function (type) { return !!(cat.actions || {})[type]; };
        var rule = function (name, conditions, actions) {
            return { name: name, type: 'automatic', settings: { apply_to_prev: false, max_executions: 1 }, conditions: { all: conditions }, actions: actions };
        };
        var days = function (n) { return { number: n, metric: 'd' }; };
        var closeAction = { type: 'status', value: STATUS.closed };

        var all = palette(cat);
        if (node.kind === 'mailbox') {
            out.push({ id: 'new_rule', icon: 'plus', label: 'next_new_rule', run: { editor: { rule: null } } });
            if (all.length && hasC('new_or_reply')) {
                // "Set the status of the ticket" first: the block people look for
                var st = all.filter(function (a) { return a.action.type === 'status'; });
                if (st.length) {
                    out.push({ id: 'new_status', icon: 'closed', label: 'next_set_status', sub: st.map(function (a) {
                        return { id: 'new_' + a.id, icon: a.icon, label: a.label, params: a.params,
                            run: { editor: { rule: rule(ctx.names.new_with, [{ type: 'new_or_reply', operator: 'new' }], [a.action]) } } };
                    }) });
                }
                out.push({ id: 'new_with', icon: 'zap', label: 'next_new_with', sub: all.map(function (a) {
                    return { id: 'new_' + a.id, icon: a.icon, text: a.text, label: a.label, params: a.params,
                        run: { editor: { rule: rule(ctx.names.new_with, [{ type: 'new_or_reply', operator: 'new' }], [a.action]) } } };
                }) });
            }
            if (hasA(FOLDER) && hasC('new_or_reply')) {
                out.push({ id: 'file_new', icon: 'folder', label: 'next_file_new', run: { editor: { rule: rule(ctx.names.file_new,
                    [{ type: 'new_or_reply', operator: 'new' }], [{ type: FOLDER, value: '' }]) } } });
            }
            if (hasC('wp_mail_tag')) {
                out.push({ id: 'tag_spam', icon: 'spam', label: 'next_tag_spam', run: { editor: { rule: rule(ctx.names.tag_spam,
                    [{ type: 'wp_mail_tag', operator: 'has', value: 'classified_spam' }], hasA('status') ? [{ type: 'status', value: STATUS.spam }] : []) } } });
            }
            if (hasC('waiting') && hasA('status')) {
                out.push({ id: 'waiting_close', icon: 'timer', label: 'next_waiting_close', run: { editor: { rule: rule(ctx.names.waiting_close,
                    [{ type: 'waiting', operator: 'longer', value: days(7) }], [{ type: 'status', value: STATUS.closed }]) } } });
            }
        } else if (node.kind === 'rule') {
            var does = ctx.ruleActions || [];
            [[FOLDER, '', 'folder', 'next_then_folder'], ['status', STATUS.closed, 'closed', 'next_then_status'], ['assign', '', 'person', 'next_then_assign']]
                .forEach(function (a) {
                    if (hasA(a[0]) && does.indexOf(a[0]) < 0) {
                        out.push({ id: 'then_' + a[0], icon: a[2], label: a[3], run: { editor: { addAction: { type: a[0], value: a[1] } } } });
                    }
                });
            if (all.length) { out = out.concat(addTo(all)); }
        } else if (node.kind === 'step') {
            // flow view: the new rule keeps the conditions of the flow (and of the branch), with a delay after the step
            var base = lanes(node.flow.base).concat((node.lane && node.lane.branch) || []);
            var after = laterDelay(node.step && node.step.delay ? node.step.delay.seconds : 0);
            var delayed = function (delay) { return base.concat([{ type: 'created', operator: 'not_in_last', value: delay }]); };
            if (node.dest === 'cond') {
                // a block "status of the ticket": pending for 7 days -> closed
                if (hasC('status') && hasC('waiting') && hasA('status')) {
                    out.push({ id: 'flow_status_close', icon: 'pending', label: 'next_flow_status_close', run: { editor: { rule: rule(ctx.names.flow_status_close,
                        lanes(node.flow.base).concat([{ type: 'status', operator: 'equal', value: STATUS.pending }, { type: 'waiting', operator: 'longer', value: days(7) }]), [closeAction]) } } });
                }
                if (hasC('wp_replied') && hasC('created') && hasA('status')) {
                    out.push({ id: 'flow_noreply', icon: 'branch', label: 'next_flow_noreply', run: { editor: { rule: rule(ctx.names.flow_noreply,
                        lanes(node.flow.base).concat([{ type: 'wp_replied', operator: 'no' }, { type: 'created', operator: 'not_in_last', value: days(2) }]), [closeAction]) } } });
                }
                if (hasC('wp_assigned') && hasC('created') && hasA('assign')) {
                    out.push({ id: 'flow_unassigned', icon: 'branch', label: 'next_flow_unassigned', run: { editor: { rule: rule(ctx.names.flow_unassigned,
                        lanes(node.flow.base).concat([{ type: 'wp_assigned', operator: 'no' }, { type: 'created', operator: 'not_in_last', value: { number: 1, metric: 'h' } }]),
                        [{ type: 'assign', value: '' }]) } } });
                }
            }
            if (hasC('created') && hasA('status')) {
                out.push({ id: 'flow_delay_close', icon: 'hourglass', label: 'next_flow_delay_close', params: { delay: after }, run: { editor: { rule: rule(ctx.names.flow_delay_close, delayed(after), [closeAction]) } } });
            }
            if (hasC('created') && hasA(FOLDER)) {
                out.push({ id: 'flow_delay_folder', icon: 'hourglass', label: 'next_flow_delay_folder', params: { delay: after }, run: { editor: { rule: rule(ctx.names.flow_delay_folder, delayed(after), [{ type: FOLDER, value: '' }]) } } });
            }
            if (node.workflow_id) {
                var has = ctx.ruleActions || [];
                if (hasA('status') && has.indexOf('status') < 0) {
                    out.push({ id: 'flow_here_close', icon: 'closed', label: 'next_flow_here_close', run: { editor: { addAction: closeAction } } });
                }
                if (hasA(FOLDER) && has.indexOf(FOLDER) < 0) {
                    out.push({ id: 'flow_here_folder', icon: 'folder', label: 'next_then_folder', run: { editor: { addAction: { type: FOLDER, value: '' } } } });
                }
                if (all.length) { out = out.concat(addTo(all)); }
            }
        } else if (node.dest === 'imap') {
            out = out.concat(toRules(all, ctx.rules));
            if (ctx.admin) {
                out.push({ id: 'retention', icon: 'deleted', label: node.retention ? 'next_retention_edit' : 'next_retention', run: { retention: true } });
            }
            if (hasA(FOLDER) && hasC('subject')) {
                out.push({ id: 'subfolder', icon: 'folder', label: 'next_subfolder', run: { editor: { rule: rule(ctx.names.subfolder,
                    [{ type: 'subject', operator: 'contains', value: '' }], [{ type: FOLDER, value: node.folder + '/' }]) } } });
            }
        } else if (node.kind === 'dest' && !STATUS[node.dest]) {
            out = out.concat(toRules(all, ctx.rules));
        } else if (STATUS[node.dest]) {
            out = out.concat(toRules(all, ctx.rules));
            var inState = { type: 'status', operator: 'equal', value: STATUS[node.dest] };
            if (hasC('status') && hasC('customer_reply') && hasA('delete')) {
                out.push({ id: 'after_delete', icon: 'deleted', label: 'next_after_delete', run: { editor: { rule: rule(ctx.names.after_delete,
                    [inState, { type: 'customer_reply', operator: 'not_in_last', value: days(30) }], [{ type: 'delete' }]) } } });
            }
            if (node.dest !== 'closed' && hasC('status') && hasC('waiting') && hasA('status')) {
                out.push({ id: 'after_close', icon: 'closed', label: 'next_after_close', run: { editor: { rule: rule(ctx.names.after_close,
                    [inState, { type: 'waiting', operator: 'longer', value: days(7) }], [{ type: 'status', value: STATUS.closed }]) } } });
            }
        }

        // "Add a timer": after a delay, a new rule of the same e-mails (its action is chosen in the editor)
        var base = timerBase(node, ctx);
        if (base && hasC('created')) {
            var timer = { id: 'add_timer', icon: 'hourglass', label: 'next_add_timer', sub: TIMERS.map(function (d) {
                return { id: 'timer_' + d.number + d.metric, icon: 'hourglass', label: 'next_timer_preset', params: { delay: d },
                    run: { editor: { rule: rule((ctx.names.timer || '').replace(':delay', ctx.delayText ? ctx.delayText(d) : d.number + d.metric),
                        base.concat([{ type: 'created', operator: 'not_in_last', value: d }]), []) } } };
            }) };
            var at = out.map(function (s) { return s.id; }).filter(function (id) { return id === 'new_status' || id === 'set_status'; })[0];
            out.splice(at ? out.map(function (s) { return s.id; }).indexOf(at) + 1 : out.length, 0, timer);
        }
        return out;
    }

    var TIMERS = [{ number: 15, metric: 'i' }, { number: 1, metric: 'h' }, { number: 1, metric: 'd' }, { number: 2, metric: 'd' },
        { number: 7, metric: 'd' }, { number: 30, metric: 'd' }];
    var DATE_TYPES = ['created', 'waiting', 'user_reply', 'customer_reply'];

    /**
     * The e-mails a timer of this block is about: none for a mailbox (every e-mail), the conditions of the flow (and of
     * the branch) for a step, the conditions of the rule (without its own delay) for a rule or the destination of one
     * rule. null: no timer offered.
     */
    function timerBase(node, ctx) {
        if (node.kind === 'mailbox') { return []; }
        if (node.kind === 'step') { return lanes(node.flow.base).concat((node.lane && node.lane.branch) || []); }
        var c = ctx.ruleConditions;
        if (!c || (node.kind === 'dest' && (ctx.rules || []).length !== 1)) { return null; }
        var list = c.all || (c.any ? [{ any: c.any }] : []);
        return list.filter(function (item) {
            return !(item && DATE_TYPES.indexOf(item.type) >= 0 && (item.operator === 'not_in_last' || item.operator === 'longer'));
        });
    }

    /**
     * Actions added to a rule (editor opened, filled in): "Set the status of the ticket" (its values), then "Add an
     * action…" (every action of the catalog). workflowId / ruleName: the rule sending to a destination.
     */
    function addTo(all, workflowId, ruleName) {
        var sub = function (a) {
            var editor = { addAction: a.action };
            if (workflowId) { editor.workflowId = workflowId; }
            return { id: 'then_' + a.id, icon: a.icon, text: a.text, label: a.label, params: a.params, run: { editor: editor } };
        };
        var out = [];
        var st = all.filter(function (a) { return a.action.type === 'status'; });
        if (st.length) { out.push({ id: 'set_status', icon: 'closed', label: 'next_set_status', sub: st.map(sub) }); }
        out.push(ruleName ? { id: 'then_any', icon: 'plus', label: 'next_then_any_here', params: { rule: ruleName }, sub: all.map(sub) }
            : { id: 'then_any', icon: 'plus', label: 'next_then_any', sub: all.map(sub) });
        return out;
    }

    /**
     * A destination: the rule sending there gets any action; several rules: the rule is chosen first (its editor
     * opens, the action is chosen there).
     */
    function toRules(all, rules) {
        rules = rules || [];
        if (!all.length || !rules.length) { return []; }
        if (rules.length === 1) { return addTo(all, rules[0].workflow_id, rules[0].name); }
        return [{ id: 'pick_rule', icon: 'edit', label: 'next_pick_rule', sub: rules.map(function (r) {
            return { id: 'rule_' + r.workflow_id, icon: 'zap', text: r.name, run: { edit: r.workflow_id } };
        }) }];
    }

    var STATUS_ICON = { 1: 'active', 2: 'pending', 3: 'closed', 4: 'spam' };
    var ACTION_ICON = { assign: 'person', move: 'move', note: 'note', reply: 'send', email_customer: 'send', forward: 'send', notification: 'send',
        delete: 'deleted', delete_forever: 'deleted', wp_imap_folder: 'folder' };

    /**
     * Every action of the catalog of the mailbox, ready to be added: the status in each of its values, the others with
     * an empty value (chosen in the editor). Labels come from the catalog (the names Workflows shows).
     *
     * @return [{ id, icon, text (shown as is) | label (text key), params, action: { type, value } }]
     */
    function palette(cat) {
        var out = [];
        var actions = (cat && cat.actions) || {};
        Object.keys(actions).forEach(function (type) {
            var item = actions[type] || {};
            if (type === 'status') {
                (item.values || ['1', '2', '3', '4']).forEach(function (value) {
                    out.push({ id: 'act_status_' + value, icon: STATUS_ICON[value] || 'active', label: 'next_act_status',
                        params: { status: (item.value_labels || {})[value] || value }, action: { type: 'status', value: String(value) } });
                });
                return;
            }
            var icon = ACTION_ICON[type] || (type.toLowerCase().indexOf('tag') >= 0 ? 'tag' : 'dots');
            out.push({ id: 'act_' + type, icon: icon, text: item.title || type, action: { type: type, value: '' } });
        });
        return out;
    }

    /** Workflows' lanes [[a], [b, c]] -> conditions of the editor: a, { any: [b, c] }. */
    function lanes(list) {
        return (list || []).map(function (lane) { return lane.length === 1 ? lane[0] : { any: lane }; });
    }

    /** The delay of the next step: 1 hour after the arrival, then 1 day, 2 days, then one more day each time. */
    function laterDelay(seconds) {
        if (!seconds) { return { number: 1, metric: 'h' }; }
        if (seconds < 86400) { return { number: 1, metric: 'd' }; }
        if (seconds < 2 * 86400) { return { number: 2, metric: 'd' }; }
        return { number: Math.floor(seconds / 86400) + 1, metric: 'd' };
    }

    // ----------------------------------------------------------------------------------- in the application

    function Next(app) {
        this.app = app;
        this.t = app.texts;
    }

    /** Mailbox of a block: its own, the one of the rule, or the one of the first rule sending to it. */
    Next.prototype.mailboxOf = function (node) {
        var model = this.app.model;
        if (node.kind === 'mailbox') { return node; }
        if (node.kind === 'step') { return this.app.overview.nodes['mb:' + node.mailbox]; }
        // the rule of the block: itself, the rule of a timer block, the first rule sending to it or filing into it
        var ruleKey = node.kind === 'rule' ? node.key : node.rule || (model.edges.filter(function (e) { return e.kind === 'sends' && e.to === node.key; })[0] || {}).from
            || ((node.filed_by || [])[0]);
        var applies = model.edges.filter(function (e) { return e.kind === 'applies' && e.from === ruleKey && e.workflow_id; })[0];
        return applies ? model.nodes[applies.to] : model.list(function (n) { return n.kind === 'mailbox'; })[0];
    };

    /** The next steps of a block, with their texts: Promise of [[label, icon, action]] (menu items). */
    Next.prototype.items = function (key) {
        var self = this, app = this.app, t = this.t, node = app.model.nodes[key];
        if (!can(node)) { return Promise.resolve([]); }
        var mailbox = this.mailboxOf(node);
        if (!mailbox) { return Promise.resolve([]); }
        var edge = node.kind === 'rule' ? app.model.edges.filter(function (e) { return e.kind === 'applies' && e.from === key && e.workflow_id; })[0]
            : (node.workflow_id ? { workflow_id: node.workflow_id } : null);
        var status = node.dest && STATUS[node.dest] ? node.label : '';
        // a destination: the rules of this mailbox sending there (or filing into the folder, or whose timer it is)
        var model = app.model, rules = [];
        if (node.kind === 'dest') {
            var ruleKeys = (node.filed_by || []).concat(model.edges.filter(function (e) { return (e.kind === 'sends' || e.kind === 'timer') && e.to === node.key; }).map(function (e) { return e.from; }));
            model.edges.forEach(function (e) {
                if (e.kind === 'applies' && e.workflow_id && e.to === mailbox.key && ruleKeys.indexOf(e.from) >= 0
                    && !rules.some(function (r) { return r.workflow_id === e.workflow_id; })) {
                    rules.push({ workflow_id: e.workflow_id, name: e.name });
                }
            });
        }
        // the rule (its actions, its conditions for a timer): of the block, or the only rule sending to a destination
        var ruleId = edge ? edge.workflow_id : (rules.length === 1 ? rules[0].workflow_id : null);
        var detail = ruleId ? app.api.rule(ruleId) : Promise.resolve(null);
        return Promise.all([app.catalogOf(mailbox.id), detail]).then(function (res) {
            var ctx = {
                catalog: res[0].catalog, admin: !!app.cfg.admin, rules: rules,
                ruleConditions: res[1] ? res[1].rule.conditions : null,
                delayText: function (d) { return d.number + ' ' + (t['unit_' + d.metric] || d.metric); },
                ruleActions: res[1] ? (res[1].rule.actions || []).map(function (a) { return a.type; }) : [],
                names: {
                    file_new: t.next_name_file_new, waiting_close: t.next_name_waiting_close, tag_spam: t.next_name_tag_spam,
                    subfolder: (t.next_name_subfolder || '').replace(':folder', node.label || ''),
                    after_delete: (t.next_name_after_delete || '').replace(':status', status),
                    after_close: (t.next_name_after_close || '').replace(':status', status),
                    flow_noreply: t.next_name_flow_noreply, flow_status_close: t.next_name_flow_status_close, new_with: t.next_name_new_with, flow_unassigned: t.next_name_flow_unassigned,
                    flow_delay_close: t.next_name_flow_delay_close, flow_delay_folder: t.next_name_flow_delay_folder, timer: t.next_name_timer
                }
            };
            var text = function (s) {
                var label = s.text || t[s.label] || s.label;
                var p = s.params || {};
                if (p.delay) { label = label.replace(':delay', p.delay.number + ' ' + (t['unit_' + p.delay.metric] || p.delay.metric)); }
                if (p.status !== undefined) { label = label.replace(':status', p.status); }
                if (p.rule !== undefined) { label = label.replace(':rule', p.rule); }
                return label;
            };
            var item = function (s) {
                return [text(s), s.icon, s.run ? function () { self.run(node, mailbox, edge, s.run); } : null, s.sub ? s.sub.map(item) : null];
            };
            return suggest(node, ctx).map(item);
        });
    };

    Next.prototype.run = function (node, mailbox, edge, run) {
        var app = this.app;
        if (run.retention) {
            // the clean-up of the folder, in its panel (its own block when it is already set)
            var clean = 'dest:clean:' + node.key.slice('dest:'.length);
            app.select(app.model.nodes[clean] ? clean : node.key);
            setTimeout(function () {
                var input = app.root.querySelector('.wpg-retention input');
                if (input) { input.focus(); input.select(); input.scrollIntoView({ block: 'center' }); }
            }, 50);
            return;
        }
        if (run.edit) {
            app.editor.open({ mailboxId: mailbox.id, workflowId: run.edit });
            return;
        }
        if (run.editor.addAction) {
            app.editor.open({ mailboxId: mailbox.id, workflowId: run.editor.workflowId || edge.workflow_id, addAction: run.editor.addAction });
        } else {
            app.editor.open({ mailboxId: mailbox.id, rule: run.editor.rule });
        }
        if (run.editor.rule || run.editor.addAction) { app.toast(this.t.next_hint, 'info'); }
    };

    /** The "+" of a block (or "Add a next step…"): the list where the pointer is. */
    Next.prototype.open = function (key, x, y) {
        var self = this, app = this.app, node = app.model.nodes[key];
        this.items(key).then(function (items) {
            if (!items.length) { app.toast(self.t.next_none, 'info'); return; }
            app.menu.render(x, y, self.t.next_title.replace(':name', node.label), [items]);
        }).catch(function (e) { app.toast(e.message, 'error'); });
    };

    var api = { can: can, suggest: suggest, palette: palette, lanes: lanes, laterDelay: laterDelay, STATUS: STATUS };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        root.WPGraph = root.WPGraph || {};
        root.WPGraph.next = api;
        root.WPGraph.Next = Next;
    }
})(typeof window !== 'undefined' ? window : this);
