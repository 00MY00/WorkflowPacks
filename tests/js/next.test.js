// Tests of the "what next?" of the diagram blocks (pure function).  node tests/js/next.test.js
const assert = require('assert');
const N = require('../../WorkflowPacks/Public/js/graph/next.js');

let n = 0;
function test(name, fn) { fn(); n++; console.log('PASS ' + name); }

const full = {
    conditions: { subject: {}, status: {}, new_or_reply: {}, waiting: {}, customer_reply: {} },
    actions: { wp_imap_folder: {}, status: {}, assign: {}, delete: {} },
};
const names = { file_new: 'Ranger', waiting_close: 'Fermer', subfolder: 'Sous-dossier', after_delete: 'Supprimer', after_close: 'Fermer après' };
const ids = (list) => list.map((s) => s.id);

test('which blocks get a "+"', () => {
    assert.ok(N.can({ kind: 'mailbox' }));
    assert.ok(N.can({ kind: 'rule' }));
    assert.ok(N.can({ kind: 'dest', dest: 'imap', folder: 'Factures' }));
    assert.ok(N.can({ kind: 'dest', dest: 'closed' }));
    assert.ok(N.can({ kind: 'dest', dest: 'person' }), 'a person: actions for the rule sending there');
    assert.ok(N.can({ kind: 'dest', dest: 'tag' }));
    assert.ok(!N.can({ kind: 'dest', dest: 'cleanup' }), 'a clean-up: its own panel');
    assert.ok(!N.can({ kind: 'pack' }));
    assert.ok(!N.can(null));
});

test('mailbox: new rule, file the new e-mails, close the tickets without answer — only what the catalog offers', () => {
    const all = N.suggest({ kind: 'mailbox' }, { catalog: full, names });
    assert.deepStrictEqual(ids(all), ['new_rule', 'new_status', 'new_with', 'file_new', 'waiting_close']);
    assert.deepStrictEqual(all[1].sub[2].run.editor.rule.actions, [{ type: 'status', value: '3' }], 'set the status: a new rule');
    const fileNew = all[3].run.editor.rule;
    assert.deepStrictEqual(fileNew.conditions, { all: [{ type: 'new_or_reply', operator: 'new' }] });
    assert.deepStrictEqual(fileNew.actions, [{ type: 'wp_imap_folder', value: '' }]);
    assert.deepStrictEqual(all[4].run.editor.rule.conditions.all[0], { type: 'waiting', operator: 'longer', value: { number: 7, metric: 'd' } });
    // option "e-mail folders" off: its action is not in the catalog
    const noFolder = N.suggest({ kind: 'mailbox' }, { catalog: { conditions: full.conditions, actions: { status: {} } }, names });
    assert.deepStrictEqual(ids(noFolder), ['new_rule', 'new_status', 'new_with', 'waiting_close']);
});

test('mailbox: the e-mails classified as spam, only when the e-mail tag condition is there (RefreshGlobal)', () => {
    const withTags = { conditions: Object.assign({ wp_mail_tag: {} }, full.conditions), actions: full.actions };
    const s = N.suggest({ kind: 'mailbox' }, { catalog: withTags, names });
    assert.deepStrictEqual(ids(s), ['new_rule', 'new_status', 'new_with', 'file_new', 'tag_spam', 'waiting_close']);
    const spam = s[4].run.editor.rule;
    assert.deepStrictEqual(spam.conditions, { all: [{ type: 'wp_mail_tag', operator: 'has', value: 'classified_spam' }] });
    assert.deepStrictEqual(spam.actions, [{ type: 'status', value: '4' }]);
    assert.ok(!ids(N.suggest({ kind: 'mailbox' }, { catalog: full, names })).includes('tag_spam'), 'not without RefreshGlobal');
});

test('rule: only the actions it does not do yet', () => {
    assert.deepStrictEqual(ids(N.suggest({ kind: 'rule' }, { catalog: full, names, ruleActions: ['status'] })), ['then_wp_imap_folder', 'then_assign', 'set_status', 'then_any']);
    const s = N.suggest({ kind: 'rule' }, { catalog: full, names, ruleActions: [] });
    assert.deepStrictEqual(s[0].run.editor.addAction, { type: 'wp_imap_folder', value: '' });
    assert.deepStrictEqual(s[1].run.editor.addAction, { type: 'status', value: '3' });
});

test('e-mail folder: clean-up for administrators only, a sub-folder under it', () => {
    const folder = { kind: 'dest', dest: 'imap', folder: 'Factures/Fournisseurs' };
    const admin = N.suggest(folder, { catalog: full, names, admin: true });
    assert.deepStrictEqual(ids(admin), ['retention', 'subfolder']);
    assert.strictEqual(admin[0].label, 'next_retention');
    assert.deepStrictEqual(admin[1].run.editor.rule.actions, [{ type: 'wp_imap_folder', value: 'Factures/Fournisseurs/' }]);
    assert.deepStrictEqual(ids(N.suggest(folder, { catalog: full, names, admin: false })), ['subfolder']);
    assert.strictEqual(N.suggest(Object.assign({ retention: { number: 30 } }, folder), { catalog: full, names, admin: true })[0].label, 'next_retention_edit');
});

test('status: delete after a delay; close after a delay (not for the closed ones)', () => {
    const closed = N.suggest({ kind: 'dest', dest: 'closed' }, { catalog: full, names });
    assert.deepStrictEqual(ids(closed), ['after_delete']);
    assert.deepStrictEqual(closed[0].run.editor.rule.conditions.all, [
        { type: 'status', operator: 'equal', value: '3' },
        { type: 'customer_reply', operator: 'not_in_last', value: { number: 30, metric: 'd' } },
    ]);
    assert.deepStrictEqual(closed[0].run.editor.rule.actions, [{ type: 'delete' }]);
    const pending = N.suggest({ kind: 'dest', dest: 'pending' }, { catalog: full, names });
    assert.deepStrictEqual(ids(pending), ['after_delete', 'after_close']);
    assert.strictEqual(pending[1].run.editor.rule.conditions.all[0].value, '2');
    assert.deepStrictEqual(N.suggest({ kind: 'dest', dest: 'pending' }, { catalog: { conditions: {}, actions: {} }, names }), [], 'nothing without the catalog');
});

test('prepared rules are new and safe: automatic, not applied to existing tickets', () => {
    [{ kind: 'mailbox' }, { kind: 'dest', dest: 'imap', folder: 'X' }, { kind: 'dest', dest: 'pending' }].forEach((node) => {
        N.suggest(node, { catalog: full, names, admin: true }).flatMap((s) => s.sub || [s]).filter((s) => s.run && s.run.editor && s.run.editor.rule).forEach((s) => {
            assert.strictEqual(s.run.editor.rule.type, 'automatic');
            assert.strictEqual(s.run.editor.rule.settings.apply_to_prev, false, s.id);
        });
    });
});

test('flow view: a new rule keeps the conditions of the flow and of the branch, with a later delay', () => {
    const flowCatalog = { conditions: Object.assign({ created: {}, wp_replied: {}, wp_assigned: {} }, full.conditions), actions: full.actions };
    const flow = { base: [[{ type: 'subject', operator: 'contains', value: 'facture' }], [{ type: 'to', operator: 'equal', value: 'a' }, { type: 'to', operator: 'equal', value: 'b' }]] };
    const cond = { kind: 'step', dest: 'cond', flow };
    assert.ok(N.can(cond));
    assert.ok(!N.can({ kind: 'step', dest: 'branch' }), 'a branch alone: through its steps');
    const fromCond = N.suggest(cond, { catalog: flowCatalog, names });
    assert.deepStrictEqual(ids(fromCond), ['flow_status_close', 'flow_noreply', 'flow_unassigned', 'flow_delay_close', 'flow_delay_folder', 'add_timer']);
    assert.deepStrictEqual(fromCond[0].run.editor.rule.conditions.all.slice(-2), [
        { type: 'status', operator: 'equal', value: '2' }, { type: 'waiting', operator: 'longer', value: { number: 7, metric: 'd' } }]);
    fromCond.shift();
    assert.deepStrictEqual(fromCond[0].run.editor.rule.conditions.all, [
        { type: 'subject', operator: 'contains', value: 'facture' },
        { any: [{ type: 'to', operator: 'equal', value: 'a' }, { type: 'to', operator: 'equal', value: 'b' }] },
        { type: 'wp_replied', operator: 'no' },
        { type: 'created', operator: 'not_in_last', value: { number: 2, metric: 'd' } },
    ]);
    assert.deepStrictEqual(fromCond[0].run.editor.rule.actions, [{ type: 'status', value: '3' }], 'mark as closed');

    // after a step "+1 day" of the branch "not assigned": +2 days, branch kept; "close here" when the rule does not
    const branch = [{ type: 'wp_assigned', operator: 'no' }];
    const step = { kind: 'step', dest: 'timer', workflow_id: 9, flow, lane: { branch }, step: { delay: { seconds: 86400 } } };
    const fromStep = N.suggest(step, { catalog: flowCatalog, names, ruleActions: ['note'] });
    assert.deepStrictEqual(ids(fromStep), ['flow_delay_close', 'flow_delay_folder', 'flow_here_close', 'flow_here_folder', 'set_status', 'add_timer', 'then_any']);
    const all = fromStep[0].run.editor.rule.conditions.all;
    assert.deepStrictEqual(all.slice(-2), [{ type: 'wp_assigned', operator: 'no' }, { type: 'created', operator: 'not_in_last', value: { number: 2, metric: 'd' } }]);
    assert.deepStrictEqual(fromStep[2].run.editor.addAction, { type: 'status', value: '3' });
    assert.ok(!ids(N.suggest(step, { catalog: flowCatalog, names, ruleActions: ['status'] })).includes('flow_here_close'), 'already closes');
    assert.deepStrictEqual([0, 1800, 86400, 172800, 400000].map(N.laterDelay), [
        { number: 1, metric: 'h' }, { number: 1, metric: 'd' }, { number: 2, metric: 'd' }, { number: 3, metric: 'd' }, { number: 5, metric: 'd' }]);
});

test('palette: every action of the catalog, the status in each value, labels of the catalog', () => {
    const cat = { conditions: full.conditions, actions: {
        status: { title: 'Change Status', values: ['1', '2', '3', '4'], value_labels: { 1: 'Active', 2: 'Pending', 3: 'Closed', 4: 'Spam' } },
        note: { title: 'Add a Note' }, forward: { title: 'Forward' }, assign: { title: 'Assign' }, add_tag: { title: 'Add Tag' }, wp_imap_folder: { title: 'Folder' } } };
    const p = N.palette(cat);
    assert.deepStrictEqual(ids(p), ['act_status_1', 'act_status_2', 'act_status_3', 'act_status_4', 'act_note', 'act_forward', 'act_assign', 'act_add_tag', 'act_wp_imap_folder']);
    assert.deepStrictEqual(p[2].action, { type: 'status', value: '3' });
    assert.strictEqual(p[2].params.status, 'Closed');
    assert.deepStrictEqual(p.map((a) => a.icon).slice(4), ['note', 'send', 'person', 'tag', 'folder']);

    // a folder filed by one rule: any action added to that rule
    const one = N.suggest({ kind: 'dest', dest: 'imap', folder: 'Pull Requests' }, { catalog: cat, names, rules: [{ workflow_id: 7, name: 'GitHub' }] });
    const status = one.find((s) => s.id === 'set_status');
    assert.deepStrictEqual(status.sub.map((s) => s.run.editor.addAction.value), ['1', '2', '3', '4'], 'set the status of the ticket, first');
    assert.strictEqual(status.sub[0].run.editor.workflowId, 7);
    const any = one.find((s) => s.id === 'then_any');
    assert.strictEqual(any.params.rule, 'GitHub');
    assert.strictEqual(any.sub.length, 9);
    assert.deepStrictEqual(any.sub[2].run.editor, { addAction: { type: 'status', value: '3' }, workflowId: 7 });
    // several rules: the rule is chosen first
    const two = N.suggest({ kind: 'dest', dest: 'person' }, { catalog: cat, names, rules: [{ workflow_id: 7, name: 'A' }, { workflow_id: 8, name: 'B' }] });
    assert.deepStrictEqual(two[0].sub.map((s) => s.run.edit), [7, 8]);
    // a rule and a mailbox
    assert.ok(ids(N.suggest({ kind: 'rule' }, { catalog: cat, names, ruleActions: [] })).includes('then_any'));
    const mb = N.suggest({ kind: 'mailbox' }, { catalog: cat, names });
    assert.strictEqual(ids(mb)[1], 'new_status');
    const withAction = mb.find((s) => s.id === 'new_with');
    assert.deepStrictEqual(withAction.sub[4].run.editor.rule.actions, [{ type: 'note', value: '' }]);
    assert.strictEqual(withAction.sub[4].run.editor.rule.settings.apply_to_prev, false, 'new rule, safe');
});

test('timers: "Add a timer" with presets, for a mailbox, a rule (without its own delay), a step, one rule of a destination', () => {
    const cat = { conditions: Object.assign({ created: {} }, full.conditions), actions: full.actions };
    const ctx = { catalog: cat, names: Object.assign({ timer: 'Après :delay' }, names), delayText: (d) => d.number + d.metric };
    const mb = N.suggest({ kind: 'mailbox' }, ctx);
    assert.strictEqual(ids(mb)[2], 'add_timer', 'right after "set the status"');
    const timer = mb[2];
    assert.deepStrictEqual(timer.sub.map((s) => s.params.delay), [{ number: 15, metric: 'i' }, { number: 1, metric: 'h' }, { number: 1, metric: 'd' },
        { number: 2, metric: 'd' }, { number: 7, metric: 'd' }, { number: 30, metric: 'd' }]);
    const r = timer.sub[1].run.editor.rule;
    assert.deepStrictEqual(r.conditions.all, [{ type: 'created', operator: 'not_in_last', value: { number: 1, metric: 'h' } }]);
    assert.deepStrictEqual(r.actions, [], 'the action is chosen in the editor');
    assert.strictEqual(r.name, 'Après 1h');
    assert.strictEqual(r.settings.apply_to_prev, false);
    // a rule: its conditions without its own delay
    const ruleConditions = { all: [{ type: 'subject', operator: 'contains', value: 'x' }, { type: 'created', operator: 'not_in_last', value: { number: 2, metric: 'd' } }] };
    const fromRule = N.suggest({ kind: 'rule' }, Object.assign({ ruleConditions, ruleActions: [] }, ctx)).find((s) => s.id === 'add_timer');
    assert.deepStrictEqual(fromRule.sub[4].run.editor.rule.conditions.all, [{ type: 'subject', operator: 'contains', value: 'x' },
        { type: 'created', operator: 'not_in_last', value: { number: 7, metric: 'd' } }]);
    // a timer block (overview) of one rule: same; several rules or no detail: no timer
    assert.ok(ids(N.suggest({ kind: 'dest', dest: 'timer' }, Object.assign({ ruleConditions, rules: [{ workflow_id: 1, name: 'A' }] }, ctx))).includes('add_timer'));
    assert.ok(!ids(N.suggest({ kind: 'dest', dest: 'timer' }, Object.assign({ ruleConditions, rules: [{ workflow_id: 1 }, { workflow_id: 2 }] }, ctx))).includes('add_timer'));
    assert.ok(!ids(N.suggest({ kind: 'mailbox' }, { catalog: full, names })).includes('add_timer'), 'not without the date condition');
});

console.log(n + ' tests OK');
