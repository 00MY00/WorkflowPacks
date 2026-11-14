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
