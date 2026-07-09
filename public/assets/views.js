/*
 * Saved Views / Bookmarks for the KPI dashboards.
 *
 * Lets a user save the current filter combination under a name and re-apply it
 * in one click. Views are stored per-page in the browser's localStorage (no
 * backend, no server state), so they are personal to that browser/profile.
 *
 * The control injects itself into the `.filters` form on any page that loads
 * this script, just before the `.filter-actions` buttons.
 */
(function () {
    'use strict';

    var form = document.querySelector('form.filters');
    if (!form || !window.localStorage) {
        return;
    }

    var page = (location.pathname.split('/').pop() || 'index').toLowerCase();
    var KEY = 'kpiSavedViews:' + page;

    function load() {
        try {
            return JSON.parse(localStorage.getItem(KEY)) || {};
        } catch (e) {
            return {};
        }
    }

    function store(views) {
        localStorage.setItem(KEY, JSON.stringify(views));
    }

    /* Serialise the form's *current* field values into a clean, comparable
       query string (empty values dropped, keys sorted). */
    function currentQuery() {
        var raw = new URLSearchParams(new FormData(form));
        var clean = new URLSearchParams();
        raw.forEach(function (value, key) {
            if (value !== '') {
                clean.append(key, value);
            }
        });
        clean.sort();
        return clean.toString();
    }

    var wrap = document.createElement('div');
    wrap.className = 'filter filter-views';
    wrap.innerHTML =
        '<label for="savedView">Saved Views</label>' +
        '<div class="views-row">' +
            '<select id="savedView"><option value="">\u2014 select \u2014</option></select>' +
            '<button type="button" class="btn btn-view" id="viewSave" title="Save the current filters as a named view">Save</button>' +
            '<button type="button" class="btn btn-view btn-view-del" id="viewDel" title="Delete the selected view" aria-label="Delete selected view">\u2715</button>' +
        '</div>';

    var actions = form.querySelector('.filter-actions');
    form.insertBefore(wrap, actions || null);

    var select = wrap.querySelector('#savedView');

    function esc(s) {
        return s.replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    function refresh() {
        var views = load();
        var names = Object.keys(views).sort(function (a, b) {
            return a.localeCompare(b);
        });
        var html = '<option value="">\u2014 select \u2014</option>';
        names.forEach(function (n) {
            html += '<option value="' + encodeURIComponent(n) + '">' + esc(n) + '</option>';
        });
        select.innerHTML = html;

        // Highlight the view that matches the currently-applied filters.
        var cur = currentQuery();
        for (var i = 0; i < names.length; i++) {
            if (views[names[i]] === cur) {
                select.value = encodeURIComponent(names[i]);
                break;
            }
        }
    }

    select.addEventListener('change', function () {
        if (!select.value) {
            return;
        }
        var name = decodeURIComponent(select.value);
        var views = load();
        if (!Object.prototype.hasOwnProperty.call(views, name)) {
            return;
        }
        var q = views[name];
        location.search = q ? '?' + q : '';
    });

    document.getElementById('viewSave').addEventListener('click', function () {
        var suggested = select.value ? decodeURIComponent(select.value) : '';
        var name = (window.prompt('Save current filters as:', suggested) || '').trim();
        if (!name) {
            return;
        }
        var views = load();
        views[name] = currentQuery();
        store(views);
        refresh();
        select.value = encodeURIComponent(name);
    });

    document.getElementById('viewDel').addEventListener('click', function () {
        if (!select.value) {
            return;
        }
        var name = decodeURIComponent(select.value);
        if (!window.confirm('Delete saved view "' + name + '"?')) {
            return;
        }
        var views = load();
        delete views[name];
        store(views);
        refresh();
    });

    refresh();
})();
