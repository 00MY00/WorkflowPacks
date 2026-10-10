/*
 * Workflow Packs — diagram: modern line icons (24 x 24, stroke only, drawn for this module).
 * Used as SVG symbols in the diagram (#wpg-i-<name>) and as inline SVG in the side panel and the toolbar.
 */
(function (WPGraph) {
    'use strict';

    var P = {
        inbox:    ['M4 13h4.5l1.5 3h4l1.5-3H20', 'M6 5h12l2 8v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-5z'],
        zap:      ['M13 3 5 13.5h6L10 21l8-10.5h-6z'],
        hand:     ['M8 12V6.5a1.5 1.5 0 0 1 3 0V11', 'M11 10V5a1.5 1.5 0 0 1 3 0v5', 'M14 10V6.5a1.5 1.5 0 0 1 3 0V14a6 6 0 0 1-6 6h-1a5 5 0 0 1-4-2l-2.5-3.5a1.5 1.5 0 0 1 2.3-1.9L8 14'],
        package:  ['M4 7.5 12 4l8 3.5v9L12 20l-8-3.5z', 'M4 7.5l8 3.5 8-3.5', 'M12 11v9', 'M8 5.8l8 3.4'],
        folder:   ['M3.5 7a1.5 1.5 0 0 1 1.5-1.5h4l2 2h8a1.5 1.5 0 0 1 1.5 1.5v8.5A1.5 1.5 0 0 1 19 19H5a1.5 1.5 0 0 1-1.5-1.5z'],
        closed:   ['M12 3.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17z', 'M8.5 12.2l2.4 2.4 4.6-5'],
        pending:  ['M12 3.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17z', 'M12 7.5V12l3 2'],
        active:   ['M12 3.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17z', 'M12 9.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z'],
        spam:     ['M12 3.5 19 6v5.5c0 4.5-3 7.6-7 9-4-1.4-7-4.5-7-9V6z', 'M12 8.5v4', 'M12 15.5v.5'],
        deleted:  ['M4.5 7h15', 'M9.5 7V4.5h5V7', 'M6.5 7l1 12.5h9l1-12.5', 'M10.5 10.5v6', 'M13.5 10.5v6'],
        person:   ['M12 4a4 4 0 1 0 0 8 4 4 0 0 0 0-8z', 'M4.5 20.5c.8-3.6 3.8-5.5 7.5-5.5s6.7 1.9 7.5 5.5'],
        tag:      ['M3.5 12.3V4.5a1 1 0 0 1 1-1h7.8l8.2 8.2a1.4 1.4 0 0 1 0 2L13.7 20.5a1.4 1.4 0 0 1-2 0z', 'M8 7.5h.01'],
        mailbox:  ['M4 8.5h16', 'M7 5h10l3 3.5V18a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V8.5z', 'M10 12.5h4'],
        move:     ['M4 8h13', 'M14 5l3 3-3 3', 'M20 16H7', 'M10 13l-3 3 3 3'],
        warn:     ['M12 4 21 19.5H3z', 'M12 10v4', 'M12 17v.5'],
        fit:      ['M4 9V4h5', 'M20 9V4h-5', 'M4 15v5h5', 'M20 15v5h-5'],
        plus:     ['M12 5v14', 'M5 12h14'],
        minus:    ['M5 12h14'],
        undo:     ['M9 7 4.5 11.5 9 16', 'M5 11.5h9a5 5 0 0 1 0 10h-2'],
        redo:     ['M15 7l4.5 4.5L15 16', 'M19 11.5h-9a5 5 0 0 0 0 10h2'],
        edit:     ['M4 20h4L19 9l-4-4L4 16z', 'M13.5 6.5l4 4'],
        external: ['M14 4h6v6', 'M20 4l-9 9', 'M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5'],
        focus:    ['M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7z', 'M12 2.5v3', 'M12 18.5v3', 'M2.5 12h3', 'M18.5 12h3'],
        search:   ['M10.5 4a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13z', 'M15.5 15.5 20 20']
    };

    /** Icon of a block of the diagram. */
    function of(node) {
        if (node.kind === 'mailbox') { return 'inbox'; }
        if (node.kind === 'pack') { return 'package'; }
        if (node.kind === 'rule') { return node.type === 'manual' ? 'hand' : 'zap'; }
        return { closed: 'closed', pending: 'pending', active: 'active', spam: 'spam', deleted: 'deleted', person: 'person', tag: 'tag', mailbox: 'mailbox' }[node.dest] || 'folder';
    }

    /** <symbol> elements in the <defs> of the diagram. */
    function define(defs) {
        var NS = 'http://www.w3.org/2000/svg';
        Object.keys(P).forEach(function (name) {
            var symbol = document.createElementNS(NS, 'symbol');
            symbol.setAttribute('id', 'wpg-i-' + name);
            symbol.setAttribute('viewBox', '0 0 24 24');
            P[name].forEach(function (d) {
                var path = document.createElementNS(NS, 'path');
                path.setAttribute('d', d);
                symbol.appendChild(path);
            });
            defs.appendChild(symbol);
        });
    }

    /** Inline SVG markup for HTML (side panel, toolbar). */
    function html(name, cls) {
        return '<svg class="wpg-svg-icon ' + (cls || '') + '" viewBox="0 0 24 24" aria-hidden="true">'
            + (P[name] || P.folder).map(function (d) { return '<path d="' + d + '"></path>'; }).join('') + '</svg>';
    }

    WPGraph.icons = { define: define, html: html, of: of, names: Object.keys(P) };
})(window.WPGraph = window.WPGraph || {});
