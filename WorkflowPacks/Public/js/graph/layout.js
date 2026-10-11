/*
 * Workflow Packs — diagram: automatic layouts (pure functions, no DOM: tested with Node, tests/js/layout.test.js).
 *
 *   columns  flow from left to right: mailboxes | rules | destinations (folders, people, tags…), packs in a row above
 *            the rules; rules ordered by their mailboxes and destinations by their rules (fewer crossing links)
 *   radial   mailboxes on a circle, each rule near the mailboxes that use it, destinations on an outer ring,
 *            packs in the middle
 *   grid     compact grid, by kind then by name
 *
 * Every function returns positions { nodeKey: [x, y] } (top-left corner of the block).
 */
(function (root) {
    'use strict';

    var SIZE = { mailbox: [230, 64], rule: [260, 64], pack: [210, 56], dest: [210, 56] };
    var GAP_Y = 28;
    var GAP_X = 170;

    /** Size of a block: fitted to its text by textfit.js (node.box), else the default size of its kind. */
    function size(node) {
        if (node.box) {
            return [node.box.w, node.box.h];
        }
        return SIZE[node.kind] || [220, 60];
    }

    function widest(list) {
        return list.reduce(function (m, n) { return Math.max(m, size(n)[0]); }, 0);
    }

    function byLabel(a, b) {
        return String(a.label).localeCompare(String(b.label));
    }

    /** key -> keys linked to it (both directions) */
    function adjacency(edges) {
        var adj = {};
        edges.forEach(function (e) {
            (adj[e.from] = adj[e.from] || []).push(e.to);
            (adj[e.to] = adj[e.to] || []).push(e.from);
        });
        return adj;
    }

    function mean(values, fallback) {
        if (!values.length) {
            return fallback;
        }
        return values.reduce(function (s, v) { return s + v; }, 0) / values.length;
    }

    function byKind(nodes) {
        var out = { mailbox: [], rule: [], dest: [], pack: [] };
        nodes.forEach(function (n) { if (out[n.kind]) { out[n.kind].push(n); } });
        return out;
    }

    /** Sorts `list` by the average rank of the blocks it is linked to (barycenter), then by name. */
    function orderBy(list, adj, rank) {
        var bary = {};
        list.forEach(function (n) {
            bary[n.key] = mean((adj[n.key] || []).filter(function (k) { return k in rank; }).map(function (k) { return rank[k]; }), 1e6);
        });
        list.sort(function (a, b) { return bary[a.key] - bary[b.key] || byLabel(a, b); });
        list.forEach(function (n, i) { rank[n.key] = i; });
    }

    /**
     * Places an ordered column as close as possible to the wanted y of each block, without overlap and keeping the
     * order: blocks that would overlap are merged into one group, whose top is the average of the tops its members
     * ask for (wanted top of the member minus its offset in the group).
     */
    function stack(list, wanted) {
        var groups = [];
        list.forEach(function (n) {
            groups.push({ items: [n], h: size(n)[1], sum: wanted[n.key], count: 1, top: wanted[n.key] });
            while (groups.length > 1) {
                var b = groups[groups.length - 1], a = groups[groups.length - 2];
                if (a.top + a.h + GAP_Y <= b.top) {
                    break;
                }
                a.sum += b.sum - b.count * (a.h + GAP_Y);
                a.count += b.count;
                a.items = a.items.concat(b.items);
                a.h += GAP_Y + b.h;
                a.top = a.sum / a.count;
                groups.pop();
            }
        });
        var out = {};
        groups.forEach(function (g) {
            var y = g.top;
            g.items.forEach(function (n) {
                out[n.key] = Math.round(y);
                y += size(n)[1] + GAP_Y;
            });
        });
        return out;
    }

    function columns(nodes, edges) {
        var k = byKind(nodes);
        var adj = adjacency(edges);
        var rank = {};
        // sub-folders of an e-mail folder get their own columns, after their parent ("[WP] Invoices" -> "Suppliers")
        var deep = k.dest.filter(function (n) { return (n.depth || 0) > 0; });
        k.dest = k.dest.filter(function (n) { return !((n.depth || 0) > 0); });
        k.mailbox.sort(byLabel);
        k.mailbox.forEach(function (n, i) { rank[n.key] = i; });
        orderBy(k.rule, adj, rank);
        orderBy(k.dest, adj, rank);
        orderBy(k.pack, adj, rank);
        // second sweep: mailboxes follow the order of their rules (fewer crossings when a rule is shared)
        orderBy(k.mailbox, adj, rank);
        orderBy(k.rule, adj, rank);
        orderBy(k.dest, adj, rank);

        // columns as wide as their widest block (blocks fit their text)
        var x = { mailbox: 0 };
        x.rule = Math.max(400, widest(k.mailbox) + GAP_X);
        x.dest = x.rule + Math.max(260, widest(k.rule)) + GAP_X;
        var height = function (list) { return list.reduce(function (s, n) { return s + size(n)[1] + GAP_Y; }, -GAP_Y); };
        var top = k.pack.length ? 110 : 0;
        var pos = {};
        var byKey = {};
        nodes.forEach(function (n) { byKey[n.key] = n; });
        var isRule = {};
        k.rule.forEach(function (n) { isRule[n.key] = true; });
        var middle = function (key) { return pos[key][1] + size(byKey[key])[1] / 2; };
        // the rules column is the spine; when there are more mailboxes than rules, they are spread like the rules
        var y = top + Math.max(0, (height(k.mailbox) - height(k.rule)) / 2);
        k.rule.forEach(function (n) {
            pos[n.key] = [x.rule, Math.round(y)];
            y += size(n)[1] + GAP_Y;
        });
        // mailboxes and destinations face the rules they are linked to
        ['mailbox', 'dest'].forEach(function (kind) {
            var wanted = {};
            var fallback = top;
            k[kind].forEach(function (n) {
                var ys = (adj[n.key] || []).filter(function (key) { return isRule[key]; }).map(middle);
                wanted[n.key] = mean(ys, fallback + size(n)[1] / 2) - size(n)[1] / 2;
                fallback = wanted[n.key] + size(n)[1] + GAP_Y;
            });
            var ys = stack(k[kind], wanted);
            k[kind].forEach(function (n) { pos[n.key] = [x[kind], Math.max(top, ys[n.key])]; });
            settle(k[kind], pos);
        });
        // sub-folders, level by level, each one facing its parent folder
        var colX = x.dest + Math.max(210, widest(k.dest)) + GAP_X;
        for (var depth = 1; deep.length; depth++) {
            var level = deep.filter(function (n) { return n.depth === depth; });
            deep = deep.filter(function (n) { return n.depth !== depth; });
            if (!level.length) {
                if (depth > 10) { break; }
                continue;
            }
            var parentOf = {};
            level.forEach(function (n) {
                parentOf[n.key] = (adj[n.key] || []).filter(function (key) { return pos[key] && byKey[key] && byKey[key].kind === 'dest'; })[0];
            });
            level.sort(function (a, b) {
                var pa = parentOf[a.key], pb = parentOf[b.key];
                return ((pa ? pos[pa][1] : 0) - (pb ? pos[pb][1] : 0)) || byLabel(a, b);
            });
            var want = {};
            level.forEach(function (n) {
                var p = parentOf[n.key];
                want[n.key] = p ? middle(p) - size(n)[1] / 2 : top;
            });
            var ly = stack(level, want);
            level.forEach(function (n) { pos[n.key] = [colX, Math.max(top, ly[n.key])]; });
            settle(level, pos);
            colX += widest(level) + GAP_X;
        }
        // packs: one row above the rules column, centered on it
        var rowWidth = k.pack.reduce(function (s, n) { return s + size(n)[0] + 30; }, -30);
        var px = x.rule + Math.max(260, widest(k.rule)) / 2 - rowWidth / 2;
        k.pack.forEach(function (n) {
            pos[n.key] = [Math.round(px), 0];
            px += size(n)[0] + 30;
        });
        return pos;
    }

    /** A column pushed below its top may overlap again: each block starts after the previous one. */
    function settle(list, pos) {
        for (var i = 1; i < list.length; i++) {
            var prev = pos[list[i - 1].key], cur = pos[list[i].key];
            cur[1] = Math.max(cur[1], prev[1] + size(list[i - 1])[1] + GAP_Y);
        }
    }

    function radial(nodes, edges) {
        var k = byKind(nodes);
        var adj = adjacency(edges);
        k.mailbox.sort(byLabel);
        // mailboxes where a rule applies (a "move" action also links a rule to a mailbox: not counted here)
        var uses = adjacency(edges.filter(function (e) { return e.kind !== 'sends' && e.kind !== 'contains'; }));
        var mailboxesOf = function (key) { return (uses[key] || []).filter(function (other) { return other.indexOf('mb:') === 0; }).sort(); };
        // the circle must hold the tallest column of shared rules
        var shared = {};
        k.rule.forEach(function (n) {
            var linked = mailboxesOf(n.key);
            if (linked.length > 1) { shared[linked.join('|')] = (shared[linked.join('|')] || 0) + 1; }
        });
        var tallest = Math.max.apply(null, [0].concat(Object.keys(shared).map(function (id) { return shared[id]; }))) * (SIZE.rule[1] + GAP_Y);
        var radius = Math.max(340, k.mailbox.length * 320 / (2 * Math.PI), tallest * 0.8 + 120);
        var center = [radius * 1.7 + 200, radius * 1.7 + 120];
        var pos = {};
        var at = function (n, angle, r) {
            return [Math.round(center[0] + r * Math.cos(angle) - size(n)[0] / 2), Math.round(center[1] + r * Math.sin(angle) - size(n)[1] / 2)];
        };
        var angle = {};
        k.mailbox.forEach(function (n, i) {
            angle[n.key] = (2 * Math.PI * i) / Math.max(1, k.mailbox.length) - Math.PI / 2;
            pos[n.key] = at(n, angle[n.key], radius);
        });
        // rules grouped by the set of mailboxes using them: a rule of one mailbox goes outside, next to it; a rule
        // shared by several mailboxes goes inside the circle, between them. Each group is a small column.
        var groups = {};
        var order = [];
        k.rule.sort(byLabel).forEach(function (n) {
            var linked = mailboxesOf(n.key);
            var id = linked.join('|');
            if (!groups[id]) {
                groups[id] = { linked: linked, items: [] };
                order.push(id);
            }
            groups[id].items.push(n);
        });
        order.forEach(function (id) {
            var g = groups[id];
            var anchor;
            if (g.linked.length === 1) {
                var a = angle[g.linked[0]];
                anchor = [center[0] + (radius + 320) * Math.cos(a), center[1] + (radius + 320) * Math.sin(a)];
            } else if (g.linked.length > 1) {
                var cx = mean(g.linked.map(function (key) { return pos[key][0] + size({ kind: 'mailbox' })[0] / 2; }), center[0]);
                var cy = mean(g.linked.map(function (key) { return pos[key][1] + size({ kind: 'mailbox' })[1] / 2; }), center[1]);
                anchor = [cx + (center[0] - cx) * 0.35, cy + (center[1] - cy) * 0.35];
            } else {
                anchor = [center[0], center[1] + radius + 320];
            }
            var h = g.items.length * (size({ kind: 'rule' })[1] + GAP_Y) - GAP_Y;
            g.items.forEach(function (n, i) {
                pos[n.key] = [Math.round(anchor[0] - size(n)[0] / 2), Math.round(anchor[1] - h / 2 + i * (size(n)[1] + GAP_Y))];
                angle[n.key] = Math.atan2(anchor[1] - center[1], anchor[0] - center[0]);
            });
        });
        // destinations on an outer ring, in the direction of the rules that send there
        k.dest.sort(byLabel).forEach(function (n, i) {
            var linked = (adj[n.key] || []).filter(function (key) { return key in angle && key.indexOf('mb:') !== 0; });
            var sx = mean(linked.map(function (key) { return Math.cos(angle[key]); }), Math.cos(i));
            var sy = mean(linked.map(function (key) { return Math.sin(angle[key]); }), Math.sin(i));
            pos[n.key] = at(n, Math.atan2(sy, sx), radius + 760);
        });
        k.pack.sort(byLabel).forEach(function (n, i) {
            pos[n.key] = [Math.round(center[0] - size(n)[0] / 2), Math.round(center[1] - 30 + i * (size(n)[1] + GAP_Y))];
        });
        return separate(pos, nodes, 200);
    }

    function grid(nodes) {
        var order = { pack: 0, mailbox: 1, rule: 2, dest: 3 };
        var list = nodes.slice().sort(function (a, b) { return (order[a.kind] - order[b.kind]) || byLabel(a, b); });
        var cols = Math.max(1, Math.ceil(Math.sqrt(list.length)));
        var pos = {};
        // cells as large as the largest block (blocks fit their text)
        var cellW = Math.max(300, widest(list) + 40);
        var cellH = Math.max(110, list.reduce(function (m, n) { return Math.max(m, size(n)[1]); }, 0) + 46);
        list.forEach(function (n, i) {
            pos[n.key] = [(i % cols) * cellW, Math.floor(i / cols) * cellH];
        });
        return pos;
    }

    /** Pushes overlapping blocks apart (a few passes, deterministic). Only keys in `movable` move (all by default). */
    function separate(pos, nodes, passes, movable) {
        var list = nodes.filter(function (n) { return pos[n.key]; });
        var canMove = function (key) { return !movable || movable.indexOf(key) >= 0; };
        for (var p = 0; p < passes; p++) {
            var moved = false;
            for (var i = 0; i < list.length; i++) {
                for (var j = i + 1; j < list.length; j++) {
                    var a = list[i], b = list[j];
                    if (!canMove(a.key) && !canMove(b.key)) {
                        continue;
                    }
                    var pa = pos[a.key], pb = pos[b.key], sa = size(a), sb = size(b);
                    var dx = Math.min(pa[0] + sa[0] + 20, pb[0] + sb[0] + 20) - Math.max(pa[0], pb[0]);
                    var dy = Math.min(pa[1] + sa[1] + 16, pb[1] + sb[1] + 16) - Math.max(pa[1], pb[1]);
                    if (dx > 0 && dy > 0) {
                        moved = true;
                        // along the smaller overlap; both blocks share the move when both may move (converges faster)
                        var axis = dy <= dx ? 1 : 0;
                        var gap = Math.ceil(axis ? dy : dx);
                        var sign = pb[axis] > pa[axis] || (pb[axis] === pa[axis] && j > i) ? 1 : -1;
                        if (canMove(a.key) && canMove(b.key)) {
                            pb[axis] += sign * Math.ceil(gap / 2);
                            pa[axis] -= sign * Math.floor(gap / 2);
                        } else if (canMove(b.key)) {
                            pb[axis] += sign * gap;
                        } else {
                            pa[axis] -= sign * gap;
                        }
                    }
                }
            }
            if (!moved) {
                break;
            }
        }
        return pos;
    }

    /** Bounding box of positioned blocks: [minX, minY, maxX, maxY] */
    function bounds(pos, nodes) {
        var box = [Infinity, Infinity, -Infinity, -Infinity];
        nodes.forEach(function (n) {
            var p = pos[n.key];
            if (!p) {
                return;
            }
            var s = size(n);
            box[0] = Math.min(box[0], p[0]);
            box[1] = Math.min(box[1], p[1]);
            box[2] = Math.max(box[2], p[0] + s[0]);
            box[3] = Math.max(box[3], p[1] + s[1]);
        });
        return box[0] === Infinity ? [0, 0, 800, 600] : box;
    }

    /** Positions for blocks that have none yet: next to a linked block, else below everything. Saved blocks stay. */
    function placeNew(pos, nodes, edges) {
        var adj = adjacency(edges);
        var fresh = [];
        var box = bounds(pos, nodes);
        var offset = { rule: 400, dest: 420, mailbox: -400, pack: 0 };
        nodes.forEach(function (n, i) {
            if (pos[n.key]) {
                return;
            }
            var anchor = (adj[n.key] || []).filter(function (k) { return pos[k]; })[0];
            pos[n.key] = anchor
                ? [pos[anchor][0] + (offset[n.kind] || 300), pos[anchor][1] + 10]
                : [box[0] + (i % 4) * 300, box[3] + 60];
            fresh.push(n.key);
        });
        return separate(pos, nodes, 40, fresh);
    }

    var api = { SIZE: SIZE, columns: columns, radial: radial, grid: grid, separate: separate, bounds: bounds, placeNew: placeNew, size: size, adjacency: adjacency };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        root.WPGraph = root.WPGraph || {};
        root.WPGraph.layout = api;
    }
})(typeof window !== 'undefined' ? window : this);
