// Tests of the automatic layouts of the diagram (pure functions).  node tests/js/layout.test.js
const assert = require('assert');
const L = require('../../WorkflowPacks/Public/js/graph/layout.js');

const nodes = [
    { key: 'mb:1', kind: 'mailbox', label: 'Support' },
    { key: 'mb:2', kind: 'mailbox', label: 'Comptabilité' },
    { key: 'mb:3', kind: 'mailbox', label: 'Ventes' },
    { key: 'tpl:p/a', kind: 'rule', label: 'A' },
    { key: 'tpl:p/b', kind: 'rule', label: 'B' },
    { key: 'wf:9', kind: 'rule', label: 'Locale' },
    { key: 'pack:p', kind: 'pack', label: 'Pack' },
    { key: 'dest:status:closed', kind: 'dest', label: 'Fermées' },
    { key: 'dest:assign:5', kind: 'dest', label: 'Alice' },
];
const edges = [
    { from: 'tpl:p/a', to: 'mb:1' }, { from: 'tpl:p/a', to: 'mb:2' },
    { from: 'tpl:p/b', to: 'mb:3' }, { from: 'wf:9', to: 'mb:2' },
    { from: 'pack:p', to: 'tpl:p/a' }, { from: 'pack:p', to: 'tpl:p/b' },
    { from: 'tpl:p/a', to: 'dest:status:closed', kind: 'sends' }, { from: 'tpl:p/b', to: 'dest:assign:5', kind: 'sends' },
];

function overlaps(pos) {
    const list = nodes.filter((n) => pos[n.key]);
    for (let i = 0; i < list.length; i++) {
        for (let j = i + 1; j < list.length; j++) {
            const a = pos[list[i].key], b = pos[list[j].key], sa = L.size(list[i]), sb = L.size(list[j]);
            if (a[0] < b[0] + sb[0] && b[0] < a[0] + sa[0] && a[1] < b[1] + sb[1] && b[1] < a[1] + sa[1]) {
                return [list[i].key, list[j].key];
            }
        }
    }
    return null;
}

let n = 0;
function test(name, fn) { fn(); n++; console.log('PASS ' + name); }

test('columns: flow mailboxes | rules | destinations, packs above the rules, no overlap', () => {
    const pos = L.columns(nodes, edges);
    nodes.forEach((x) => assert.ok(pos[x.key], x.key));
    assert.ok(pos['mb:1'][0] < pos['tpl:p/a'][0] && pos['tpl:p/a'][0] < pos['dest:status:closed'][0]);
    assert.ok(pos['pack:p'][1] < Math.min(pos['tpl:p/a'][1], pos['tpl:p/b'][1], pos['wf:9'][1]), 'pack row above');
    assert.strictEqual(overlaps(pos), null);
});

test('columns: rules ordered by their mailboxes, destinations by their rules (barycenter)', () => {
    const pos = L.columns(nodes, edges);
    // mailboxes sorted by name: Comptabilité, Support, Ventes -> rule B (Ventes) below rule A (Comptabilité + Support)
    assert.ok(pos['tpl:p/b'][1] > pos['tpl:p/a'][1]);
    assert.ok(pos['dest:assign:5'][1] > pos['dest:status:closed'][1], 'destination of B below the one of A');
});

test('radial and grid: every block placed, no overlap, deterministic', () => {
    ['radial', 'grid'].forEach((name) => {
        const a = L[name](nodes, edges), b = L[name](nodes, edges);
        nodes.forEach((x) => assert.ok(a[x.key], name + ' ' + x.key));
        assert.strictEqual(overlaps(a), null, name);
        assert.deepStrictEqual(a, b, name + ' deterministic');
    });
});

test('columns: a mailbox faces its rules, a destination faces the rules sending there', () => {
    const pos = L.columns(nodes, edges);
    const mid = (key, kind) => pos[key][1] + L.size({ kind })[1] / 2;
    // Ventes only uses rule B: (almost) the same height
    assert.ok(Math.abs(mid('mb:3', 'mailbox') - mid('tpl:p/b', 'rule')) < 32, 'mailbox next to its only rule (within half a block)');
    assert.ok(Math.abs(mid('dest:assign:5', 'dest') - mid('tpl:p/b', 'rule')) < 1, 'destination aligned with its only rule');
});

test('radial and columns: a pack shared by several mailboxes stays readable (no overlap)', () => {
    const big = [], links = [];
    ['Factures', 'RH', 'Support', 'Ventes'].forEach((name, i) => big.push({ key: 'mb:' + i, kind: 'mailbox', label: name }));
    for (let r = 0; r < 7; r++) {
        big.push({ key: 'tpl:tri/r' + r, kind: 'rule', label: 'Tri ' + r });
        links.push({ from: 'tpl:tri/r' + r, to: 'mb:2' }, { from: 'tpl:tri/r' + r, to: 'mb:3' }, { from: 'pack:tri', to: 'tpl:tri/r' + r });
        links.push({ from: 'tpl:tri/r' + r, to: 'dest:status:' + (r % 3), kind: 'sends' });
    }
    for (let r = 0; r < 5; r++) {
        big.push({ key: 'wf:' + r, kind: 'rule', label: 'Locale ' + r });
        links.push({ from: 'wf:' + r, to: 'mb:' + (r % 2) });
    }
    big.push({ key: 'pack:tri', kind: 'pack', label: 'Tri' });
    [0, 1, 2].forEach((d) => big.push({ key: 'dest:status:' + d, kind: 'dest', label: 'Dossier ' + d }));
    ['radial', 'columns'].forEach((name) => {
        const pos = L[name](big, links);
        const list = big.filter((x) => pos[x.key]);
        assert.strictEqual(list.length, big.length, name + ': all placed');
        for (let i = 0; i < list.length; i++) {
            for (let j = i + 1; j < list.length; j++) {
                const a = pos[list[i].key], b = pos[list[j].key], sa = L.size(list[i]), sb = L.size(list[j]);
                const hit = a[0] < b[0] + sb[0] && b[0] < a[0] + sa[0] && a[1] < b[1] + sb[1] && b[1] < a[1] + sa[1];
                assert.ok(!hit, name + ': ' + list[i].key + ' overlaps ' + list[j].key);
            }
        }
    });
});

test('columns: e-mail folders as a flow, each sub-folder in the next column facing its parent; wide blocks never overlap', () => {
    const list = [
        { key: 'mb:1', kind: 'mailbox', label: 'Support' },
        { key: 'wf:1', kind: 'rule', label: 'Une règle au nom très long', box: { w: 380, h: 98 } },
        { key: 'wf:2', kind: 'rule', label: 'Devis' },
        { key: 'dest:imap:A', kind: 'dest', dest: 'imap', label: '[WP] Factures', depth: 0 },
        { key: 'dest:imap:A/B', kind: 'dest', dest: 'imap', label: 'Fournisseurs', depth: 1, box: { w: 300, h: 56 } },
        { key: 'dest:imap:A/B/C', kind: 'dest', dest: 'imap', label: '2026', depth: 2 },
        { key: 'dest:status:closed', kind: 'dest', label: 'Fermées' },
    ];
    const links = [
        { from: 'wf:1', to: 'mb:1', kind: 'applies' }, { from: 'wf:2', to: 'mb:1', kind: 'applies' },
        { from: 'wf:1', to: 'dest:imap:A', kind: 'sends' }, { from: 'dest:imap:A', to: 'dest:imap:A/B', kind: 'folder' },
        { from: 'dest:imap:A/B', to: 'dest:imap:A/B/C', kind: 'folder' }, { from: 'wf:2', to: 'dest:status:closed', kind: 'sends' },
    ];
    const pos = L.columns(list, links);
    list.forEach((x) => assert.ok(pos[x.key], x.key));
    const right = (key) => pos[key][0] + L.size(list.find((x) => x.key === key))[0];
    assert.ok(pos['wf:1'][0] > right('mb:1'), 'rules after the mailboxes');
    assert.ok(pos['dest:imap:A'][0] > right('wf:1'), 'destinations after the widest rule');
    assert.ok(pos['dest:imap:A/B'][0] > right('dest:imap:A'), 'sub-folder in the next column');
    assert.ok(pos['dest:imap:A/B/C'][0] > right('dest:imap:A/B'), 'and its own sub-folder after it');
    const mid = (key) => pos[key][1] + L.size(list.find((x) => x.key === key))[1] / 2;
    assert.ok(Math.abs(mid('dest:imap:A/B') - mid('dest:imap:A')) < 1, 'facing its parent');
    for (let i = 0; i < list.length; i++) {
        for (let j = i + 1; j < list.length; j++) {
            const a = pos[list[i].key], b = pos[list[j].key], sa = L.size(list[i]), sb = L.size(list[j]);
            assert.ok(!(a[0] < b[0] + sb[0] && b[0] < a[0] + sa[0] && a[1] < b[1] + sb[1] && b[1] < a[1] + sa[1]), list[i].key + ' / ' + list[j].key);
        }
    }
});

test('separate: an enlarged or dropped block stays put, the blocks it covers step aside', () => {
    const list = [
        { key: 'wf:1', kind: 'rule', label: 'Agrandie', box: { w: 520, h: 180 } },
        { key: 'wf:2', kind: 'rule', label: 'Voisine 1' },
        { key: 'wf:3', kind: 'rule', label: 'Voisine 2' },
        { key: 'dest:status:closed', kind: 'dest', label: 'Fermées' },
    ];
    // saved positions from before the blocks fitted their text: everything piled up
    const pos = { 'wf:1': [0, 0], 'wf:2': [100, 60], 'wf:3': [200, 120], 'dest:status:closed': [150, 90] };
    L.separate(pos, list, 120, ['wf:2', 'wf:3', 'dest:status:closed']);
    assert.deepStrictEqual(pos['wf:1'], [0, 0], 'fixed block not moved');
    for (let i = 0; i < list.length; i++) {
        for (let j = i + 1; j < list.length; j++) {
            const a = pos[list[i].key], b = pos[list[j].key], sa = L.size(list[i]), sb = L.size(list[j]);
            assert.ok(!(a[0] < b[0] + sb[0] && b[0] < a[0] + sa[0] && a[1] < b[1] + sb[1] && b[1] < a[1] + sa[1]), list[i].key + ' / ' + list[j].key);
        }
    }
});

test('separate: a big diagram with blocks piled up and enlarged never keeps an overlap', () => {
    let seed = 7;
    const rnd = () => { seed = (seed * 1103515245 + 12345) & 0x7fffffff; return seed / 0x7fffffff; };
    for (let round = 0; round < 30; round++) {
        const list = [];
        const pos = {};
        [['mailbox', 8, 0], ['rule', 40, 450], ['dest', 20, 1100], ['dest', 15, 1500]].forEach(([kind, count, x], c) => {
            for (let i = 0; i < count; i++) {
                const node = { key: kind + c + ':' + i, kind, label: 'b' + i };
                if (rnd() < 0.6) { node.box = { w: 200 + Math.round(rnd() * 220), h: 56 + Math.round(rnd() * 40) }; }
                list.push(node);
                // saved from an older layout with smaller blocks: tight columns, some blocks piled up
                const piled = rnd() < 0.15;
                pos[node.key] = [x + Math.round((rnd() - 0.5) * (piled ? 120 : 40)), Math.round(i * 92 * (piled ? 0.3 : 0.85))];
            }
        });
        const fixed = [list[12].key, list[30].key];
        const kept = fixed.map((k) => pos[k].slice());
        L.separate(pos, list, 120, list.map((n) => n.key).filter((k) => fixed.indexOf(k) < 0));
        assert.deepStrictEqual(fixed.map((k) => pos[k]), kept, 'fixed blocks not moved');
        for (let i = 0; i < list.length; i++) {
            for (let j = i + 1; j < list.length; j++) {
                const a = pos[list[i].key], b = pos[list[j].key], sa = L.size(list[i]), sb = L.size(list[j]);
                assert.ok(!(a[0] < b[0] + sb[0] && b[0] < a[0] + sa[0] && a[1] < b[1] + sb[1] && b[1] < a[1] + sa[1]), 'round ' + round + ': ' + list[i].key + ' / ' + list[j].key);
            }
        }
    }
});

test('placeNew: a new rule is put next to its mailbox, saved blocks do not move', () => {
    const pos = L.columns(nodes, edges);
    const saved = JSON.parse(JSON.stringify(pos));
    const more = nodes.concat([{ key: 'wf:10', kind: 'rule', label: 'Nouvelle' }]);
    const moreEdges = edges.concat([{ from: 'wf:10', to: 'mb:3' }]);
    L.placeNew(pos, more, moreEdges);
    assert.ok(pos['wf:10']);
    nodes.forEach((x) => assert.deepStrictEqual(pos[x.key], saved[x.key], 'unchanged ' + x.key));
    assert.ok(Math.abs(pos['wf:10'][1] - pos['mb:3'][1]) < 400, 'near its mailbox');
});

test('bounds: box of the blocks, default box when empty', () => {
    const box = L.bounds({ 'mb:1': [10, 20] }, nodes);
    assert.deepStrictEqual(box, [10, 20, 10 + L.size({ kind: 'mailbox' })[0], 20 + L.size({ kind: 'mailbox' })[1]]);
    assert.deepStrictEqual(L.bounds({}, nodes), [0, 0, 800, 600]);
});

console.log(n + ' tests OK');
