/*
 * Workflow Packs — diagram, flow view: one block per instruction (pure functions, no DOM: tests/js/flow.test.js).
 *
 *   mailbox → [conditions] → [branch: assigned? replied?] → [delay] → [action] → [action] → [delay] → [action] …
 *
 * graph()  the flows of the server (Services/Flow/FlowBuilder) -> nodes and links of the diagram's model
 * layout() positions: a grid of rows (one per lane) and columns (one per step), each cell as large as its largest
 *          block — two blocks never overlap, whatever their texts (the sizes come from textfit.js)
 */
(function (root) {
    'use strict';

    var GAP_X = 70;      // between two columns (room for the arrow)
    var GAP_Y = 22;      // between two rows
    var GAP_FLOW = 26;   // between two flows of a mailbox
    var GAP_MAILBOX = 70;

    /** Block colour / icon family of an action (FlowBuilder::actionKind) — the same as the overview's destinations. */
    function family(kind) {
        if (kind.indexOf('status-') === 0) { return kind.slice(7); }
        return { folder: 'imap', assign: 'person', move: 'mailbox', tag: 'tag', note: 'note', send: 'send', delete: 'deleted' }[kind] || 'other';
    }

    /**
     * @param flows     { mailboxId: { flows: [...], paths: {...} } } (graph data "flows")
     * @param mailboxes [mailbox nodes of the overview] (label, sub, id)
     * @return { nodes: [...], edges: [...], sections: [{ mailbox, flows: [{ cond, lanes: [[keys…]] }] }] }
     */
    function graph(flows, mailboxes) {
        var nodes = [], edges = [], sections = [];
        var link = function (from, to, extra) {
            var e = { from: from, to: to, kind: 'flow' };
            Object.keys(extra || {}).forEach(function (k) { e[k] = extra[k]; });
            edges.push(e);
        };
        mailboxes.forEach(function (mb) {
            var mbKey = 'mb:' + mb.id;
            nodes.push({ key: mbKey, kind: 'mailbox', id: mb.id, label: mb.label, sub: mb.sub });
            var section = { mailbox: mbKey, flows: [] };
            ((flows[mb.id] || {}).flows || []).forEach(function (flow) {
                nodes.push({ key: flow.key, kind: 'step', dest: 'cond', label: flow.conditions[0] || '', sub: flow.conditions.slice(1).join(' · '),
                    mailbox: mb.id, flow: flow });
                link(mbKey, flow.key);
                var block = { cond: flow.key, lanes: [] };
                flow.lanes.forEach(function (lane) {
                    var row = [], prev = flow.key;
                    if (lane.key) {
                        nodes.push({ key: lane.key, kind: 'step', dest: 'branch', label: lane.label, sub: '', mailbox: mb.id, flow: flow, lane: lane });
                        link(prev, lane.key);
                        row.push(lane.key);
                        prev = lane.key;
                    }
                    lane.steps.forEach(function (step) {
                        var ctx = { mailbox: mb.id, flow: flow, lane: lane, step: step, workflow_id: step.workflow_id, active: step.active, origin: step.origin, pack: step.pack };
                        var add = function (node) {
                            Object.keys(ctx).forEach(function (k) { node[k] = ctx[k]; });
                            nodes.push(node);
                            link(prev, node.key, { active: step.active, workflow_id: step.workflow_id });
                            row.push(node.key);
                            prev = node.key;
                        };
                        if (step.delay) {
                            add({ key: step.delay.key, kind: 'step', dest: 'timer', label: step.delay.label, sub: step.name });
                        }
                        step.actions.forEach(function (action, i) {
                            add({ key: action.key, kind: 'step', dest: family(action.kind), action_kind: action.kind, action_index: i,
                                label: action.label || '', sub: action.sub || (step.delay || i ? '' : step.name) });
                        });
                        if (!step.delay && !step.actions.length) {
                            add({ key: 'fa:' + step.workflow_id + ':none', kind: 'step', dest: 'other', label: step.name, sub: '' });
                        }
                    });
                    block.lanes.push(row);
                });
                section.flows.push(block);
            });
            sections.push(section);
        });
        return { nodes: nodes, edges: edges, sections: sections };
    }

    /**
     * Positions { key: [x, y] }. Columns: 0 mailbox, 1 conditions, 2… the steps of a lane. Rows: one per lane. A
     * spanning block (mailbox, conditions) is centred on its rows, and its rows grow when it is taller than them.
     *
     * @param g    graph()
     * @param size function (node) -> [w, h]
     */
    function layout(g, size) {
        var byKey = {};
        g.nodes.forEach(function (n) { byKey[n.key] = n; });
        var dim = function (key) { return size(byKey[key]); };

        // column widths, over every mailbox (same columns for all: tidy)
        var widths = [0, 0];
        g.sections.forEach(function (s) {
            widths[0] = Math.max(widths[0], dim(s.mailbox)[0]);
            s.flows.forEach(function (f) {
                widths[1] = Math.max(widths[1], dim(f.cond)[0]);
                f.lanes.forEach(function (row) {
                    row.forEach(function (key, i) { widths[i + 2] = Math.max(widths[i + 2] || 0, dim(key)[0]); });
                });
            });
        });
        var xs = [];
        widths.reduce(function (x, w, i) { xs[i] = x; return x + w + GAP_X; }, 0);

        var pos = {};
        var y = 0;
        g.sections.forEach(function (s) {
            var top = y;
            s.flows.forEach(function (f) {
                var flowTop = y;
                f.lanes.forEach(function (row) {
                    var h = row.reduce(function (m, key) { return Math.max(m, dim(key)[1]); }, 0);
                    row.forEach(function (key, i) { pos[key] = [xs[i + 2], y + (h - dim(key)[1]) / 2]; });
                    y += h + GAP_Y;
                });
                var ch = dim(f.cond)[1];
                // the conditions block is never taller than its rows: else its rows grow
                y = Math.max(y, flowTop + ch + GAP_Y);
                pos[f.cond] = [xs[1], flowTop + (y - GAP_Y - flowTop - ch) / 2];
                y += GAP_FLOW - GAP_Y;
            });
            if (!s.flows.length) { y += GAP_Y; }
            var mh = dim(s.mailbox)[1];
            y = Math.max(y, top + mh + GAP_Y);
            pos[s.mailbox] = [xs[0], top + Math.max(0, (y - GAP_FLOW - top - mh) / 2)];
            y += GAP_MAILBOX;
        });
        return pos;
    }

    /** Do two blocks overlap (tests, and a last check before drawing)? */
    function overlaps(pos, g, size) {
        var list = g.nodes.filter(function (n) { return pos[n.key]; });
        for (var i = 0; i < list.length; i++) {
            for (var j = i + 1; j < list.length; j++) {
                var a = pos[list[i].key], b = pos[list[j].key], sa = size(list[i]), sb = size(list[j]);
                if (a[0] < b[0] + sb[0] && b[0] < a[0] + sa[0] && a[1] < b[1] + sb[1] && b[1] < a[1] + sa[1]) {
                    return [list[i].key, list[j].key];
                }
            }
        }
        return null;
    }

    var api = { graph: graph, layout: layout, overlaps: overlaps, family: family };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        root.WPGraph = root.WPGraph || {};
        root.WPGraph.flow = api;
    }
})(typeof window !== 'undefined' ? window : this);
