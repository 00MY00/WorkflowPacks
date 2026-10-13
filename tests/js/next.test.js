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
    assert.ok(!N.can({ kind: 'dest', dest: 'person' }), 'a person: nothing');
    assert.ok(!N.can({ kind: 'dest', dest: 'imap' }), 'invalid folder');
    assert.ok(!N.can({ kind: 'pack' }));
    assert.ok(!N.can(null));
});

test('mailbox: new rule, file the new e-mails, close the tickets without answer — only what the catalog offers', () => {
    const all = N.suggest({ kind: 'mailbox' }, { catalog: full, names });
    assert.deepStrictEqual(ids(all), ['new_rule', 'file_new', 'waiting_close']);
    const fileNew = all[1].run.editor.rule;
    assert.deepStrictEqual(fileNew.conditions, { all: [{ type: 'new_or_reply', operator: 'new' }] });
    assert.deepStrictEqual(fileNew.actions, [{ type: 'wp_imap_folder', value: '' }]);
    assert.deepStrictEqual(all[2].run.editor.rule.conditions.all[0], { type: 'waiting', operator: 'longer', value: { number: 7, metric: 'd' } });
    // option "e-mail folders" off: its action is not in the catalog
    const noFolder = N.suggest({ kind: 'mailbox' }, { catalog: { conditions: full.conditions, actions: { status: {} } }, names });
    assert.deepStrictEqual(ids(noFolder), ['new_rule', 'waiting_close']);
});

test('rule: only the actions it does not do yet', () => {
    assert.deepStrictEqual(ids(N.suggest({ kind: 'rule' }, { catalog: full, names, ruleActions: ['status'] })), ['then_wp_imap_folder', 'then_assign']);
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
        N.suggest(node, { catalog: full, names, admin: true }).filter((s) => s.run.editor && s.run.editor.rule).forEach((s) => {
            assert.strictEqual(s.run.editor.rule.type, 'automatic');
            assert.strictEqual(s.run.editor.rule.settings.apply_to_prev, false, s.id);
        });
    });
});

console.log(n + ' tests OK');
