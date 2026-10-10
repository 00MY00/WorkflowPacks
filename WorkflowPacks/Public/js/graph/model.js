/*
 * Workflow Packs — diagram: state of the graph (nodes, links, positions) and history of the moves (undo / redo).
 */
(function (WPGraph) {
    'use strict';

    function Model() {
        this.nodes = {};      // key -> node (from the server)
        this.edges = [];      // links
        this.pos = {};        // key -> [x, y]
        this.done = [];       // history: { positions before, positions after }
        this.undone = [];
    }

    Model.prototype.load = function (data) {
        var nodes = {};
        (data.nodes || []).forEach(function (n) { nodes[n.key] = n; });
        this.nodes = nodes;
        this.edges = data.edges || [];
        // positions of blocks that no longer exist are dropped
        var pos = {};
        Object.keys(this.pos).forEach(function (k) { if (nodes[k]) { pos[k] = this.pos[k]; } }, this);
        this.pos = pos;
    };

    Model.prototype.list = function (filter) {
        var nodes = this.nodes;
        return Object.keys(nodes).map(function (k) { return nodes[k]; }).filter(filter || function () { return true; });
    };

    Model.prototype.edgesOf = function (key) {
        return this.edges.filter(function (e) { return e.from === key || e.to === key; });
    };

    /** Keys linked to a block (one step) */
    Model.prototype.neighbors = function (key) {
        var out = {};
        this.edgesOf(key).forEach(function (e) { out[e.from === key ? e.to : e.from] = true; });
        return Object.keys(out);
    };

    /** Is there already a rule -> mailbox link? */
    Model.prototype.linked = function (ruleKey, mailboxKey) {
        return this.edges.some(function (e) { return e.from === ruleKey && e.to === mailboxKey && e.kind === 'applies'; });
    };

    Model.prototype.snapshot = function (keys) {
        var out = {};
        keys.forEach(function (k) { out[k] = this.pos[k] ? this.pos[k].slice() : null; }, this);
        return out;
    };

    /** Records a change of positions (one undo step) and applies it. */
    Model.prototype.commit = function (before, after) {
        this.done.push({ before: before, after: after });
        if (this.done.length > 200) {
            this.done.shift();
        }
        this.undone = [];
        this.apply(after);
    };

    Model.prototype.apply = function (positions) {
        Object.keys(positions).forEach(function (k) {
            if (positions[k]) { this.pos[k] = positions[k].slice(); }
        }, this);
    };

    Model.prototype.undo = function () {
        var step = this.done.pop();
        if (!step) { return null; }
        this.undone.push(step);
        this.apply(step.before);
        return step.before;
    };

    Model.prototype.redo = function () {
        var step = this.undone.pop();
        if (!step) { return null; }
        this.done.push(step);
        this.apply(step.after);
        return step.after;
    };

    WPGraph.Model = Model;
})(window.WPGraph = window.WPGraph || {});
