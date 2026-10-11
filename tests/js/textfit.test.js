// Size of the blocks of the diagram from their text (pure functions).  node tests/js/textfit.test.js
const assert = require('assert');
const F = require('../../WorkflowPacks/Public/js/graph/textfit.js');

// estimate: 8 px per character for titles, 7 px for the second line
const measure = (text, kind) => String(text).length * (kind === 't2' ? 7 : 8);
const base = [230, 64];

let n = 0;
function test(name, fn) { fn(); n++; console.log('PASS ' + name); }

test('short text: default size of the kind, one line each', () => {
    const box = F.fit({ label: 'Support', sub: 'support@example.test' }, base, measure, null);
    assert.deepStrictEqual([box.w, box.h], base);
    assert.deepStrictEqual(box.t1, ['Support']);
    assert.deepStrictEqual(box.t2, ['support@example.test']);
});

test('longer title: the block widens up to the automatic maximum, then the text wraps and the block grows', () => {
    const mid = F.fit({ label: 'Factures fournisseurs 2026' }, base, measure, null);
    assert.ok(mid.w > base[0] && mid.w <= 380, 'wider, at most 380');
    assert.deepStrictEqual(mid.t1, ['Factures fournisseurs 2026'], 'still one line');

    const long = F.fit({ label: '[Tri] Alertes de sécurité envoyées par les fournisseurs de services en ligne', sub: '3 condition(s) → 2 action(s)' }, base, measure, null);
    assert.strictEqual(long.w, 380);
    assert.ok(long.t1.length >= 2, 'wrapped');
    assert.ok(long.h > base[1], 'taller');
    const avail = long.w - F.TEXT_X - 16;
    long.t1.forEach((line) => assert.ok(measure(line, 't1') <= avail, 'no line wider than the block: ' + line));
    assert.strictEqual(long.t1.join(' '), '[Tri] Alertes de sécurité envoyées par les fournisseurs de services en ligne', 'whole text kept');
});

test('a word longer than the block is cut, never hidden', () => {
    const word = 'x'.repeat(80);
    const box = F.fit({ label: word }, base, measure, null);
    assert.strictEqual(box.t1.join(''), word);
    box.t1.forEach((line) => assert.ok(measure(line, 't1') <= box.w - F.TEXT_X - 16));
});

test('size chosen by hand: kept, within bounds, never smaller than the text needs', () => {
    const big = F.fit({ label: 'Support' }, base, measure, [500, 200]);
    assert.deepStrictEqual([big.w, big.h], [500, 200]);
    const tooSmall = F.fit({ label: 'Une règle avec un nom vraiment très long pour un petit bloc' }, base, measure, [160, 40]);
    assert.strictEqual(tooSmall.w, 160);
    assert.ok(tooSmall.h >= 12 * 2 + tooSmall.t1.length * F.LINE1, 'height holds every line');
    const bounded = F.fit({ label: 'x' }, base, measure, [5000, 5000]);
    assert.deepStrictEqual([bounded.w, bounded.h], [F.MAX_W, F.MAX_H]);
});

test('room kept on the right for a warning icon', () => {
    const plain = F.fit({ label: 'a'.repeat(25) }, base, measure, null);
    const warned = F.fit({ label: 'a'.repeat(25) }, base, measure, null, 20);
    assert.strictEqual(warned.w - plain.w, 20);
});

console.log(n + ' tests OK');
