/*
 * Workflow Packs — diagram: "Simulate". A fake e-mail goes through the rules of a mailbox on the server, in memory
 * (Services/Flow/Simulator: nothing written, nothing sent, nothing kept), then its path lights up in the flow view,
 * block after block, and the side panel tells what each rule changed. Every text from the server is inserted with
 * textContent (never as HTML).
 */
(function (WPGraph) {
    'use strict';

    var STEP_MS = 300;   // time of the "energy" on one link

    function h(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') { node.textContent = attrs[k]; } else if (k === 'on') {
                Object.keys(attrs.on).forEach(function (ev) { node.addEventListener(ev, attrs.on[ev]); });
            } else if (attrs[k] !== null && attrs[k] !== false) { node.setAttribute(k, attrs[k] === true ? '' : attrs[k]); }
        });
        (children || []).forEach(function (c) { if (c) { node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
        return node;
    }

    function icon(name) {
        var span = h('span', { class: 'wpg-ic' });
        span.innerHTML = WPGraph.icons.html(name); // constant markup of this module, no user data
        return span;
    }

    function reducedMotion() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function Sim(app) {
        this.app = app;
        this.t = app.texts;
        this.result = null;
        this.mailboxId = 0;
        this.timers = [];
        this.last = { subject: '', customer_email: 'client@example.test', customer_name: '', body: '', tags: '', assigned: false, replied: false, status: '1', include_inactive: true, target: '' };
    }

    // ----------------------------------------------------------------------------------- the fake e-mail

    /** Rules of a mailbox, for "Rule to test": [[workflow id, name]], in the order of Workflows. */
    Sim.prototype.rulesOf = function (mailboxId) {
        var seen = {};
        return this.app.overview.edges.filter(function (e) {
            if (e.kind !== 'applies' || !e.workflow_id || e.to !== 'mb:' + mailboxId || seen[e.workflow_id]) { return false; }
            seen[e.workflow_id] = true;
            return true;
        }).map(function (e) { return [e.workflow_id, e.name]; });
    };

    /**
     * The form of the fake e-mail. opts.workflowId: "test this rule" — the rule is chosen and the e-mail is made to
     * meet its conditions (server: SampleMail).
     */
    Sim.prototype.open = function (opts) {
        var self = this, t = this.t, app = this.app;
        opts = opts || {};
        var mailboxes = app.overview.list(function (n) { return n.kind === 'mailbox'; });
        if (!mailboxes.length) { app.toast(t.no_mailbox, 'error'); return; }
        var selected = Object.keys(app.view.selected).map(function (k) { return app.mailboxOf(app.model.nodes[k]); }).filter(Boolean)[0];
        // the mailbox of the selected block, the last one simulated, else the first one with rules
        var flows = (app.data && app.data.flows) || {};
        var withRules = mailboxes.filter(function (mb) { return ((flows[mb.id] || {}).flows || []).length; })[0];
        var current = opts.mailboxId || selected || this.mailboxId || (withRules || mailboxes[0]).id;
        var overlay = app.root.querySelector('.wpg-overlay');
        var v = this.last;
        var field = function (name, label, input) {
            return h('div', { class: 'form-group' }, [h('label', { for: 'wpg-sim-' + name, text: label }), input]);
        };
        var text = function (name, type) {
            return h('input', { id: 'wpg-sim-' + name, name: name, type: type || 'text', class: 'form-control', value: v[name] || '', maxlength: name === 'subject' ? 255 : 191 });
        };
        var check = function (name, label) {
            return h('label', { class: 'wpg-check' }, [h('input', { type: 'checkbox', name: name, checked: !!v[name] }), ' ' + label]);
        };
        var select = h('select', { id: 'wpg-sim-mailbox_id', name: 'mailbox_id', class: 'form-control' }, mailboxes.map(function (mb) {
            return h('option', { value: mb.id, selected: mb.id === current, text: mb.label });
        }));
        var rule = h('select', { id: 'wpg-sim-target', name: 'target', class: 'form-control' });
        var unmet = h('div', { class: 'wpg-sim-unmet', role: 'status' });
        var status = h('select', { id: 'wpg-sim-status', name: 'status', class: 'form-control' }, ['1', '2', '3', '4'].map(function (s) {
            return h('option', { value: s, selected: String(v.status || '1') === s, text: t['sim_status_' + s] });
        }));
        var fillRules = function (mailboxId, chosen) {
            rule.textContent = '';
            rule.appendChild(h('option', { value: '', text: t.sim_rule_none }));
            self.rulesOf(mailboxId).forEach(function (r) { rule.appendChild(h('option', { value: r[0], selected: String(r[0]) === String(chosen), text: r[1] })); });
        };
        var form = h('form', { class: 'wpg-dialog wpg-sim-form', novalidate: true }, [
            h('div', { class: 'wpg-dialog-head' }, [icon('play'), h('strong', { text: t.sim_title }),
                h('button', { type: 'button', class: 'wpg-ins-close', 'aria-label': t.close, on: { click: function () { self.closeForm(); } } }, ['×'])]),
            h('div', { class: 'wpg-dialog-body' }, [
                h('p', { class: 'wpg-hint', text: t.sim_intro_short }),
                h('div', { class: 'wpg-row2' }, [field('mailbox_id', t.sim_mailbox, select), field('target', t.sim_rule, rule)]),
                unmet,
                h('div', { class: 'wpg-row2' }, [field('subject', t.sim_subject, text('subject')), field('customer_email', t.sim_from, text('customer_email', 'email'))]),
                h('details', { class: 'wpg-sim-more' }, [h('summary', { text: t.sim_more }),
                    h('div', { class: 'wpg-row2' }, [field('customer_name', t.sim_from_name, text('customer_name')), field('status', t.sim_status, status)]),
                    field('body', t.sim_body, h('textarea', { id: 'wpg-sim-body', name: 'body', class: 'form-control', rows: 3, maxlength: 20000 }, [v.body || ''])),
                    app.cfg.mailTags ? field('tags', t.sim_tags, text('tags')) : null
                ]),
                check('assigned', t.sim_assigned), check('replied', t.sim_replied), check('include_inactive', t.sim_inactive)
            ]),
            h('div', { class: 'wpg-dialog-foot' }, [
                h('button', { type: 'button', class: 'btn btn-default', on: { click: function () { self.closeForm(); } } }, [t.cancel]),
                h('button', { type: 'submit', class: 'btn btn-primary' }, [icon('play'), ' ' + t.sim_run])
            ])
        ]);
        // a rule chosen: the fake e-mail is made for it
        var fill = function (id) {
            unmet.textContent = '';
            if (!id) { return; }
            app.api.sample(id).then(function (res) {
                ['subject', 'customer_email', 'customer_name', 'body', 'tags'].forEach(function (k) { if (form.elements[k]) { form.elements[k].value = res.input[k] || ''; } });
                form.elements.status.value = String(res.input.status || 1);
                form.elements.assigned.checked = !!res.input.assigned;
                form.elements.replied.checked = !!res.input.replied;
                unmet.appendChild(h('p', { class: 'wpg-hint is-ok', text: t.sim_filled }));
                if (res.unmet.length) {
                    unmet.appendChild(h('p', { class: 'wpg-warn-text', text: t.sim_unmet + ' ' + res.unmet.join(' · ') }));
                }
            }).catch(function (e) { app.toast(e.message, 'error'); });
        };
        fillRules(current, opts.workflowId || '');
        select.addEventListener('change', function () { fillRules(this.value, ''); unmet.textContent = ''; });
        rule.addEventListener('change', function () { fill(this.value); });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var input = {};
            ['mailbox_id', 'subject', 'customer_email', 'customer_name', 'body', 'tags', 'status', 'target'].forEach(function (k) { if (form.elements[k]) { input[k] = form.elements[k].value; } });
            ['assigned', 'replied', 'include_inactive'].forEach(function (k) { input[k] = form.elements[k].checked; });
            self.last = Object.assign({}, input);
            input.tags = String(input.tags || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
            self.run(input);
        });
        overlay.textContent = '';
        overlay.appendChild(form);
        overlay.classList.add('open');
        if (opts.workflowId) { fill(opts.workflowId); }
        setTimeout(function () { (form.elements.subject || select).focus(); }, 30);
    };

    Sim.prototype.closeForm = function () {
        var overlay = this.app.root.querySelector('.wpg-overlay');
        overlay.classList.remove('open');
        overlay.textContent = '';
    };

    Sim.prototype.run = function (input) {
        var self = this, app = this.app;
        app.api.simulate(input).then(function (result) {
            self.closeForm();
            // the path is drawn in the flow view (switching view clears an older result)
            if (app.settings.view !== 'flow') { app.setView('flow'); }
            self.clear();
            self.result = result;
            self.mailboxId = parseInt(input.mailbox_id, 10);
            self.panel();
            self.animate();
        }).catch(function (e) { app.toast(e.message, 'error'); });
    };

    // ----------------------------------------------------------------------------------- on the diagram

    /** Keys a rule goes through (FlowBuilder::paths of the server). */
    Sim.prototype.path = function (workflowId) {
        var flows = (this.app.data && this.app.data.flows) || {};
        return ((flows[this.mailboxId] || {}).paths || {})[workflowId] || [];
    };

    Sim.prototype.nodeEl = function (key) {
        return this.app.view.nodeLayer.querySelector('[data-key="' + (window.CSS && CSS.escape ? CSS.escape(key) : key) + '"]');
    };

    Sim.prototype.edgeEls = function (from, to) {
        var out = [];
        Array.prototype.forEach.call(this.app.view.edgeLayer.querySelectorAll('.wpg-edge'), function (g) {
            if (g.getAttribute('data-from') === from && g.getAttribute('data-to') === to) { out.push(g); }
        });
        return out;
    };

    /** Classes of the blocks: went through (hit), not reached (miss), cannot be simulated (unsure). */
    Sim.prototype.paint = function () {
        var self = this, r = this.result;
        if (!r || this.app.settings.view !== 'flow') { return; }
        // the energy settles: solid links (no animation left running)
        Array.prototype.forEach.call(this.app.view.svg.querySelectorAll('.sim-energy'), function (el) { el.classList.remove('sim-energy'); });
        r.skipped.forEach(function (s) {
            var cls = s.reason === 'sim_uncertain' ? 'sim-unsure' : 'sim-miss';
            self.path(s.workflow_id).slice(2).forEach(function (key) { var el = self.nodeEl(key); if (el) { el.classList.add(cls); } });
        });
        r.events.forEach(function (ev) {
            var keys = self.path(ev.workflow_id);
            keys.forEach(function (key, i) {
                var el = self.nodeEl(key);
                if (el) { el.classList.remove('sim-miss', 'sim-unsure'); el.classList.add('sim-hit'); }
                if (i) { self.edgeEls(keys[i - 1], key).forEach(function (g) { g.classList.add('sim-hit'); }); }
            });
        });
    };

    /** The "energy" runs along the path of each rule that ran, in the order of the simulation. */
    Sim.prototype.animate = function () {
        var self = this, r = this.result, app = this.app;
        this.stop();
        if (!r) { return; }
        var keys = [];
        r.events.forEach(function (ev) { keys = keys.concat(self.path(ev.workflow_id)); });
        var section = keys.length ? keys : ['mb:' + this.mailboxId];
        app.view.fit(section.filter(function (k) { return app.model.nodes[k]; }));
        Array.prototype.forEach.call(app.view.svg.querySelectorAll('.sim-hit, .sim-miss, .sim-unsure, .sim-energy'), function (el) {
            el.classList.remove('sim-hit', 'sim-miss', 'sim-unsure', 'sim-energy');
        });
        if (reducedMotion()) { this.paint(); return; }
        var delay = 0, done = {};
        r.events.forEach(function (ev) {
            var path = self.path(ev.workflow_id);
            path.forEach(function (key, i) {
                // a part of the path already lit by an earlier rule (mailbox → conditions…) is not run again
                var link = i ? path[i - 1] + '>' + key : key;
                if (done[link]) { return; }
                done[link] = true;
                self.timers.push(setTimeout(function () {
                    if (i) { self.edgeEls(path[i - 1], key).forEach(function (g) { g.classList.add('sim-energy'); }); }
                    var el = self.nodeEl(key);
                    if (el) { el.classList.add('sim-hit'); }
                }, delay));
                delay += i ? STEP_MS : STEP_MS / 2;
            });
        });
        this.timers.push(setTimeout(function () { self.paint(); }, delay + STEP_MS));
    };

    Sim.prototype.stop = function () {
        this.timers.forEach(clearTimeout);
        this.timers = [];
    };

    /** The flow view was drawn again (data reloaded): the result stays visible, without animation. */
    Sim.prototype.redraw = function () { this.paint(); };

    Sim.prototype.clear = function () {
        this.stop();
        this.result = null;
        var svg = this.app.view.svg;
        Array.prototype.forEach.call(svg.querySelectorAll('.sim-hit, .sim-miss, .sim-unsure, .sim-energy'), function (el) {
            el.classList.remove('sim-hit', 'sim-miss', 'sim-unsure', 'sim-energy');
        });
    };

    // ----------------------------------------------------------------------------------- side panel

    Sim.prototype.changeLines = function (action) {
        var t = this.t;
        var lines = action.changes.map(function (c) { return h('li', { text: c.field + ' : ' + c.before + ' → ' + c.after }); });
        if (action.status === 'recorded') { lines.push(h('li', { class: 'wpg-sim-recorded', text: t.sim_recorded })); }
        if (action.status === 'unsupported') { lines.push(h('li', { class: 'wpg-sim-unsure-text', text: t.sim_unsupported })); }
        return h('ul', { class: 'wpg-sim-changes' }, lines);
    };

    Sim.prototype.conditionLines = function (conditions) {
        return h('ul', { class: 'wpg-sim-conds' }, conditions.map(function (c) {
            var mark = c.result === true ? '✓' : (c.result === false ? '✗' : '?');
            return h('li', { class: 'is-' + (c.result === true ? 'ok' : (c.result === false ? 'no' : 'unsure')), text: mark + ' ' + c.text + (c.note ? ' — ' + c.note : '') });
        }));
    };

    /** Result of the simulation in the side panel: verdict, each rule that ran (what it changed), the others, the end. */
    Sim.prototype.panel = function () {
        var self = this, t = this.t, app = this.app, r = this.result, ins = app.inspector;
        if (!r) { return; }
        ins.open();
        ins.current = { method: 'simPanel', args: [] };
        ins.panel.appendChild(h('div', { class: 'wpg-ins-head' }, [
            h('span', { class: 'wpg-ins-pill' }, [icon('play')]),
            h('div', { class: 'wpg-ins-title' }, [h('strong', { text: t.sim_title }), h('small', { text: t.sim_until.replace(':when', r.horizon) })])
        ].concat(ins.headButtons())));
        var body = h('div', { class: 'wpg-ins-body' });
        ins.panel.appendChild(body);
        body.appendChild(h('div', { class: 'wpg-sim-verdict ' + (r.processed ? 'is-ok' : 'is-no') }, [icon(r.processed ? 'closed' : 'warn'),
            h('span', { text: r.processed ? t.sim_processed.replace(':count', r.events.length) : t.sim_not_processed })]));
        if (r.target) {
            var name = r.target.name || '#' + r.target.workflow_id;
            body.appendChild(h('div', { class: 'wpg-sim-target ' + (r.target.applied ? 'is-ok' : 'is-no') }, [icon(r.target.applied ? 'closed' : 'warn'),
                h('span', { text: r.target.applied ? t.sim_target_ok.replace(':name', name).replace(':when', r.target.when)
                    : t.sim_target_no.replace(':name', name).replace(':reason', r.target.reason_text || t.sim_rule_none) })]));
        }
        if (r.uncertain) { body.appendChild(h('p', { class: 'wpg-warn-text', text: t.sim_some_unsure })); }

        r.events.forEach(function (ev) {
            var card = h('div', { class: 'wpg-sim-event', role: 'button', tabindex: 0 }, [
                h('div', { class: 'wpg-sim-when', text: ev.when + (ev.active ? '' : ' · ' + t.inactive) }),
                h('strong', { text: ev.name })
            ]);
            ev.actions.forEach(function (a) {
                // status, folder, person…: the value says it all; a note, a sending: what and where
                var detail = a.sub && ['note', 'send', 'other'].indexOf(a.kind) >= 0 ? ' — ' + a.sub : '';
                card.appendChild(h('div', { class: 'wpg-sim-action' }, [icon(WPGraph.icons.of({ kind: 'step', dest: WPGraph.flow.family(a.kind) })), h('span', { text: a.label + detail })]));
                card.appendChild(self.changeLines(a));
            });
            var show = function () { var keys = self.path(ev.workflow_id); app.view.select(keys); app.view.fit(keys); };
            card.addEventListener('click', show);
            card.addEventListener('keydown', function (e) { if (e.key === 'Enter') { show(); } });
            body.appendChild(card);
        });

        if (r.skipped.length) {
            ins.section(body, t.sim_skipped.replace(':count', r.skipped.length));
            r.skipped.forEach(function (s) {
                var box = h('details', { class: 'wpg-sim-skip' }, [h('summary', { text: s.name + ' — ' + s.reason_text })]);
                if (s.conditions.length) { box.appendChild(self.conditionLines(s.conditions)); }
                body.appendChild(box);
            });
        }

        ins.section(body, t.sim_final);
        var f = r.final;
        var rows = [[t.sim_field_status, f.status], [t.sim_field_user_id, f.user], [t.sim_field_mailbox_id, f.mailbox], [t.sim_field_folder, f.folder || '—'],
            [t.sim_field_tags, f.tags || '—'], [t.sim_field_sent, f.sent.length ? f.sent.join(' · ') : '—']];
        if (f.deleted) { rows.push([t.sim_field_deleted, f.deleted]); }
        body.appendChild(h('table', { class: 'wpg-sim-final' }, rows.map(function (row) { return h('tr', {}, [h('th', { text: row[0] }), h('td', { text: row[1] })]); })));
        body.appendChild(h('p', { class: 'wpg-hint', text: t.sim_destroyed }));
        body.appendChild(h('div', { class: 'wpg-ins-actions' }, [
            ins.button(t.sim_replay, 'play', function () { self.animate(); }, true),
            ins.button(t.sim_again, 'edit', function () { self.open(); }),
            ins.button(t.sim_clear, 'deleted', function () { self.clear(); app.clearSelection(); })
        ]));
    };

    /** In the panel of a block: what the fake e-mail did there (after a simulation). */
    Sim.prototype.details = function (node, body) {
        var self = this, t = this.t, r = this.result;
        if (!r || node.mailbox !== this.mailboxId) { return; }
        var hits = r.events.filter(function (ev) { return self.path(ev.workflow_id).indexOf(node.key) >= 0; });
        var miss = r.skipped.filter(function (s) { return self.path(s.workflow_id).indexOf(node.key) >= 0; });
        if (!hits.length && !miss.length) { return; }
        this.app.inspector.section(body, t.sim_here);
        hits.forEach(function (ev) {
            body.appendChild(h('p', { class: 'wpg-sim-when', text: ev.when + ' · ' + ev.name }));
            var index = node.action_index;
            if (index !== undefined && ev.workflow_id === node.workflow_id && ev.actions[index]) {
                body.appendChild(self.changeLines(ev.actions[index]));
            } else {
                body.appendChild(self.conditionLines(ev.conditions));
            }
        });
        miss.forEach(function (s) {
            body.appendChild(h('p', { class: 'wpg-sim-when', text: s.name + ' — ' + s.reason_text }));
            if (s.conditions.length) { body.appendChild(self.conditionLines(s.conditions)); }
        });
    };

    // the side panel shows the result again when it is refreshed (inspector.refresh())
    WPGraph.Inspector.prototype.simPanel = function () { this.app.sim.panel(); };

    WPGraph.Sim = Sim;
})(window.WPGraph = window.WPGraph || {});
