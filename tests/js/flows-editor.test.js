// Tests of the flows editor (pure functions).  node tests/js/flows-editor.test.js
const assert = require('assert');
const E = require('../../WorkflowPacks/Public/js/flows/editor.js');

const types = {
    'trigger.new_mail': { variables: { 'ticket.subject': { type: 'text' }, 'ticket.status': { type: 'choice', values: ['active', 'closed'] } } },
    'condition.ticket': { variables: {} },
    'action.scan': { variables: { 'scan.verdict': { type: 'choice', values: ['clean', 'malicious'] } } },
    'action.status': { variables: {} },
};
// trigger -> if -> (yes) scan -> status ; (no) -> status
const draft = {
    blocks: [{ id: 't', type: 'trigger.new_mail' }, { id: 'if', type: 'condition.ticket' }, { id: 'sc', type: 'action.scan' }, { id: 's', type: 'action.status' }],
    links: [{ from: 't', port: 'next', to: 'if' }, { from: 'if', port: 'yes', to: 'sc' }, { from: 'sc', port: 'next', to: 's' }, { from: 'if', port: 'no', to: 's' }],
};
let v = E.variablesBefore(draft, 'if', types);
assert.deepStrictEqual(Object.keys(v).sort(), ['ticket.status', 'ticket.subject'], 'the trigger gives the ticket');
assert.deepStrictEqual(v['ticket.status'].values, ['active', 'closed'], 'a closed list keeps its values');
assert.strictEqual(v['ticket.subject'].partial, false);
assert.deepStrictEqual(Object.keys(E.variablesBefore(draft, 't', types)), [], 'nothing before the trigger');
v = E.variablesBefore(draft, 's', types);
assert.strictEqual(v['scan.verdict'].partial, true, 'made on the "yes" path only: may be empty');
assert.strictEqual(v['ticket.subject'].partial, false, 'on every path');
console.log('variablesBefore: OK');

// two fingers: zoom with their spacing, the point under their middle stays under it
let pv = E.pinchView({ x: 0, y: 0, k: 1 }, [100, 100], 100, [100, 100], 200);
assert.deepStrictEqual(pv, { x: -100, y: -100, k: 2 }, 'spread: zoom x2 around the middle');
pv = E.pinchView({ x: 10, y: 20, k: 1 }, [50, 50], 100, [80, 60], 100);
assert.deepStrictEqual(pv, { x: 40, y: 30, k: 1 }, 'same spacing: the view follows the fingers');
assert.strictEqual(E.pinchView({ x: 0, y: 0, k: 1 }, [0, 0], 100, [0, 0], 10000).k, 2, 'zoom capped');
assert.strictEqual(E.pinchView({ x: 0, y: 0, k: 1 }, [0, 0], 100, [0, 0], 1).k, 0.25, 'zoom floored');
console.log('pinchView: OK');

// selection rectangle: blocks it touches, whatever corner it starts from
const blocks = [{ id: 'a', x: 0, y: 0 }, { id: 'b', x: 300, y: 0 }, { id: 'c', x: 0, y: 300 }];
const size = () => [230, 62];
assert.deepStrictEqual(E.inRect(blocks, size, [-10, -10], [250, 100]), ['a']);
assert.deepStrictEqual(E.inRect(blocks, size, [600, 100], [200, -50]), ['a', 'b'], 'drawn from the bottom right');
assert.deepStrictEqual(E.inRect(blocks, size, [240, 100], [290, 200]), [], 'empty space');
console.log('inRect: OK');
