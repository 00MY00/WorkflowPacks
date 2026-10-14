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
        return node.kind === 'dest' && ((node.dest === 'imap' && !!node.folder) || !!STATUS[node.dest]);
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

        if (node.kind === 'mailbox') {
            out.push({ id: 'new_rule', icon: 'plus', label: 'next_new_rule', run: { editor: { rule: null } } });
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
        } else if (node.dest === 'imap') {
            if (ctx.admin) {
                out.push({ id: 'retention', icon: 'deleted', label: node.retention ? 'next_retention_edit' : 'next_retention', run: { retention: true } });
            }
            if (hasA(FOLDER) && hasC('subject')) {
                out.push({ id: 'subfolder', icon: 'folder', label: 'next_subfolder', run: { editor: { rule: rule(ctx.names.subfolder,
                    [{ type: 'subject', operator: 'contains', value: '' }], [{ type: FOLDER, value: node.folder + '/' }]) } } });
            }
        } else if (STATUS[node.dest]) {
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

        return out;
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
        var ruleKey = node.kind === 'rule' ? node.key : (model.edges.filter(function (e) { return e.kind === 'sends' && e.to === node.key; })[0] || {}).from
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
        var edge = node.kind === 'rule' ? app.model.edges.filter(function (e) { return e.kind === 'applies' && e.from === key && e.workflow_id; })[0] : null;
        var detail = edge ? app.api.rule(edge.workflow_id) : Promise.resolve(null);
        var status = node.dest && STATUS[node.dest] ? node.label : '';
        return Promise.all([app.catalogOf(mailbox.id), detail]).then(function (res) {
            var ctx = {
                catalog: res[0].catalog, admin: !!app.cfg.admin,
                ruleActions: res[1] ? (res[1].rule.actions || []).map(function (a) { return a.type; }) : [],
                names: {
                    file_new: t.next_name_file_new, waiting_close: t.next_name_waiting_close, tag_spam: t.next_name_tag_spam,
                    subfolder: (t.next_name_subfolder || '').replace(':folder', node.label || ''),
                    after_delete: (t.next_name_after_delete || '').replace(':status', status),
                    after_close: (t.next_name_after_close || '').replace(':status', status)
                }
            };
            return suggest(node, ctx).map(function (s) {
                return [t[s.label] || s.label, s.icon, function () { self.run(node, mailbox, edge, s.run); }];
            });
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
        if (run.editor.addAction) {
            app.editor.open({ mailboxId: mailbox.id, workflowId: edge.workflow_id, addAction: run.editor.addAction });
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

    var api = { can: can, suggest: suggest, STATUS: STATUS };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        root.WPGraph = root.WPGraph || {};
        root.WPGraph.next = api;
        root.WPGraph.Next = Next;
    }
})(typeof window !== 'undefined' ? window : this);
