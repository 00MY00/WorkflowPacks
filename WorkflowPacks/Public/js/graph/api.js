/*
 * Workflow Packs — diagram: calls to the server (JSON). The CSRF token of the page is sent with every change;
 * the server checks permissions and validates everything again.
 */
(function (WPGraph) {
    'use strict';

    function Api(urls, token) {
        this.urls = urls;
        this.token = token;
        this.pending = 0;
        this.onBusy = null;     // function (busy): loading indicator while a request is running
    }

    Api.prototype.busy = function (delta) {
        this.pending = Math.max(0, this.pending + delta);
        if (this.onBusy) { this.onBusy(this.pending > 0); }
    };

    Api.prototype.request = function (method, url, body) {
        var self = this;
        this.busy(1);
        var done = function (value) { self.busy(-1); return value; };
        var failed = function (error) { self.busy(-1); throw error; };
        return fetch(url, {
            method: method,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.token,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body ? JSON.stringify(body) : undefined
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (json) {
                if (!response.ok) {
                    var error = new Error((json.errors || [json.error || response.statusText]).join('\n'));
                    error.data = json;
                    throw error;
                }
                return json;
            });
        }).then(done, failed);
    };

    Api.prototype.url = function (name, id) {
        return this.urls[name].replace('__ID__', encodeURIComponent(id));
    };

    Api.prototype.data = function () { return this.request('GET', this.urls.data); };
    Api.prototype.saveLayout = function (positions, settings, replace, sizes) {
        return this.request('POST', this.urls.layout, { positions: positions, settings: settings || {}, replace: !!replace, sizes: sizes || {} });
    };
    Api.prototype.rule = function (id) { return this.request('GET', this.url('rule', id)); };
    Api.prototype.catalog = function (mailboxId) { return this.request('GET', this.url('catalog', mailboxId)); };
    Api.prototype.saveRule = function (payload) { return this.request('POST', this.urls.save, payload); };
    Api.prototype.toggle = function (id, active) { return this.request('POST', this.url('toggle', id), { active: !!active }); };
    Api.prototype.toggleMany = function (ids, active) { return this.request('POST', this.urls.toggleMany, { ids: ids, active: !!active }); };
    Api.prototype.previousMany = function (ids, on) { return this.request('POST', this.urls.previousMany, { ids: ids, on: !!on }); };
    Api.prototype.saveRetention = function (folder, number, metric, mode) {
        return this.request('POST', this.urls.retention, { folder: folder, number: number, metric: metric, mode: mode });
    };
    Api.prototype.removeRetention = function (folder) { return this.request('POST', this.urls.retentionRemove, { folder: folder }); };
    Api.prototype.apply = function (id, mailboxId) { return this.request('POST', this.url('apply', id), { mailbox_id: mailboxId }); };

    WPGraph.Api = Api;
})(window.WPGraph = window.WPGraph || {});
