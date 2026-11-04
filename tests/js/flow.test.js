// Tests of the flow view (pure functions).  node tests/js/flow.test.js
const assert = require('assert');
const F = require('../../WorkflowPacks/Public/js/graph/flow.js');

const step = (id, delay, actions, active = true) => ({
    workflow_id: id, name: 'Règle ' + id, active, origin: 'local', pack: null,
    delay: delay ? { key: 'ft:' + id, seconds: delay, label: 'Après ' + delay, conditions: [] } : null,
    actions: actions.map((kind, i) => ({ key: 'fa:' + id + ':' + i, kind, label: kind, sub: '' })),
});
const flows = {
    4: { flows: [
        { key: 'fc:4:a', conditions: ['Sujet contient « facture »'], base: [], lanes: [
            { key: null, label: '', branch: [], steps: [step(1, 0, ['note']), step(2, 3600, ['folder']), step(3, 172800, ['status-closed', 'send'])] },
            { key: 'fb:4:a:x', label: 'Assigné : non', branch: [], steps: [step(4, 259200, ['assign'], false)] },
        ] },
        { key: 'fc:4:b', conditions: ['Tous les e-mails'], base: [], lanes: [{ key: null, label: '', branch: [], steps: [step(5, 0, [])] }] },
    ] },
    7: { flows: [] },
    8: { flows: [
        { key: 'fc:8:z', conditions: [], base: [], blocks: [
            { key: 'fc:8:z', family: 'text', label: 'Sujet contient « commande »' },
            { key: 'fc:8:z:1', family: 'status-pending', label: 'Statut = En attente' },
            { key: 'fc:8:z:2', family: 'person', label: 'Assigné à : Alice' },
        ], lanes: [{ key: null, label: '', branch: [], steps: [step(6, 86400, ['status-closed'])] }] },
    ] },
};
const mailboxes = [{ id: 4, label: 'Support', sub: 'support@example.test' }, { id: 7, label: 'Vide', sub: '' }, { id: 8, label: 'Ventes', sub: '' }];

const g = F.graph(flows, mailboxes);
const keys = g.nodes.map((n) => n.key);
assert.deepStrictEqual(keys.slice(0, 3), ['mb:4', 'fc:4:a', 'fa:1:0'], 'mailbox, its conditions, the first step');
assert.ok(keys.includes('fb:4:a:x') && keys.includes('ft:3') && keys.includes('fa:3:1') && keys.includes('mb:7'));
assert.ok(keys.includes('fa:5:none'), 'a rule without delay nor action still has a block');
assert.strictEqual(g.nodes.find((n) => n.key === 'fa:3:0').dest, 'closed');
assert.strictEqual(g.nodes.find((n) => n.key === 'fa:2:0').dest, 'imap');
assert.strictEqual(g.nodes.find((n) => n.key === 'ft:2').dest, 'timer');
assert.strictEqual(g.nodes.find((n) => n.key === 'fb:4:a:x').dest, 'branch');
// chain: mailbox → conditions → note → delay → folder → delay → closed → send
const next = (from) => g.edges.filter((e) => e.from === from).map((e) => e.to);
assert.deepStrictEqual(next('mb:4'), ['fc:4:a', 'fc:4:b']);
assert.deepStrictEqual(next('fa:1:0'), ['ft:2']);
assert.deepStrictEqual(next('ft:3'), ['fa:3:0']);
assert.deepStrictEqual(next('fa:3:0'), ['fa:3:1']);
assert.deepStrictEqual(next('fb:4:a:x'), ['ft:4']);
assert.strictEqual(g.edges.find((e) => e.to === 'ft:4').active, false, 'disabled rule: its links say so');
assert.strictEqual(g.sections[0].flows[0].lanes[0].length, 6);
// one block per condition, in a chain, then the steps
assert.deepStrictEqual(g.sections[2].flows[0].conds, ['fc:8:z', 'fc:8:z:1', 'fc:8:z:2']);
assert.deepStrictEqual(next('mb:8'), ['fc:8:z']);
assert.deepStrictEqual(next('fc:8:z'), ['fc:8:z:1']);
assert.deepStrictEqual(next('fc:8:z:2'), ['ft:6']);
assert.strictEqual(g.nodes.find((n) => n.key === 'fc:8:z:1').cond_family, 'status-pending');

// no overlap, whatever the sizes (random text sizes, many times)
let seed = 7;
const rand = () => { seed = (seed * 16807) % 2147483647; return seed / 2147483647; };
for (let run = 0; run < 200; run++) {
    const sizes = {};
    g.nodes.forEach((n) => { sizes[n.key] = [120 + Math.round(rand() * 260), 40 + Math.round(rand() * 160)]; });
    const size = (n) => sizes[n.key];
    const pos = F.layout(g, size);
    g.nodes.forEach((n) => assert.ok(pos[n.key], 'positioned: ' + n.key));
    assert.strictEqual(F.overlaps(pos, g, size), null, 'run ' + run);
    // a lane goes from left to right
    const row = g.sections[0].flows[0].lanes[0];
    for (let i = 1; i < row.length; i++) { assert.ok(pos[row[i]][0] > pos[row[i - 1]][0] + size(g.nodes.find((n) => n.key === row[i - 1])) [0] - 1); }
    // conditions, then the steps, from left to right
    const chain = g.sections[2].flows[0].conds.concat(g.sections[2].flows[0].lanes[0]);
    for (let i = 1; i < chain.length; i++) { assert.ok(pos[chain[i]][0] > pos[chain[i - 1]][0], 'chain ' + i); }
}
console.log('flow.test.js: OK');
