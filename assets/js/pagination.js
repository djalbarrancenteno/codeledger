/*
 * CodeLedger · Paginación adaptable
 * --------------------------------------------------------------------------
 * El tamaño de página se calcula según el alto disponible en pantalla y el
 * alto real de cada registro. También permite fijar 10/25/50 filas.
 *
 * Uso:
 *   <div class="table-responsive" data-paginate data-paginate-controls="#miPager">
 *       <table>...<tbody>...</tbody></table>
 *   </div>
 *   <div class="cl-pager-host" id="miPager"></div>
 *
 * Filas que pertenecen al mismo registro lógico (p. ej. una factura y su
 * detalle desplegable) se agrupan con data-paginate-group="<clave>".
 */
(function () {
    'use strict';

    var SIZE_KEY = 'cl.pageSize';
    var SIZES = ['auto', '10', '25', '50'];
    var MIN_ITEMS = 3;
    var MAX_ITEMS = 50;
    var VIEWPORT_RESERVE = 250; // topbar + cabecera de tarjeta + paginador + márgenes

    function readPref() {
        var value = 'auto';
        try { value = localStorage.getItem(SIZE_KEY) || 'auto'; } catch (e) { /* sin storage */ }
        return SIZES.indexOf(value) !== -1 ? value : 'auto';
    }

    function savePref(value) {
        try { localStorage.setItem(SIZE_KEY, value); } catch (e) { /* sin storage */ }
    }

    function collectGroups(container) {
        var nodes;
        var table = container.tagName === 'TABLE' ? container : container.querySelector('table');

        if (table) {
            nodes = Array.prototype.slice.call(table.querySelectorAll(':scope > tbody > tr'));
        } else {
            var selector = container.getAttribute('data-paginate-item');
            nodes = Array.prototype.slice.call(
                container.querySelectorAll(selector ? ':scope ' + selector : ':scope > *')
            );
        }

        nodes = nodes.filter(function (node) {
            return !node.querySelector('.empty-state') && !node.hasAttribute('data-paginate-skip');
        });

        var groups = [];
        var current = null;
        var lastKey = null;

        nodes.forEach(function (node) {
            var key = node.getAttribute('data-paginate-group');
            if (key !== null && current && key === lastKey) {
                current.els.push(node);
            } else {
                current = { els: [node] };
                groups.push(current);
                lastKey = key;
            }
        });

        return groups;
    }

    function showAll(groups) {
        groups.forEach(function (group) {
            group.els.forEach(function (el) { el.hidden = false; });
        });
    }

    function groupBottom(group, containerTop) {
        var bottom = -Infinity;
        group.els.forEach(function (el) {
            var rect = el.getBoundingClientRect();
            if (rect.height > 0) bottom = Math.max(bottom, rect.bottom - containerTop);
        });
        return bottom === -Infinity ? 0 : bottom;
    }

    function autoSize(container, groups) {
        var budget = Math.max(260, (window.innerHeight || 800) - VIEWPORT_RESERVE);
        var top = container.getBoundingClientRect().top;
        var fits = 0;

        for (var i = 0; i < groups.length; i++) {
            if (groupBottom(groups[i], top) <= budget) fits++;
            else break;
        }

        if (!fits) fits = 10;
        return Math.max(MIN_ITEMS, Math.min(MAX_ITEMS, fits));
    }

    function pageSequence(current, total) {
        var out = [];
        function push(value) {
            if (out[out.length - 1] !== value) out.push(value);
        }

        if (total <= 7) {
            for (var i = 1; i <= total; i++) push(i);
            return out;
        }

        push(1);
        if (current > 3) push('…');
        for (var p = Math.max(2, current - 1); p <= Math.min(total - 1, current + 1); p++) push(p);
        if (current < total - 2) push('…');
        push(total);
        return out;
    }

    function init(container) {
        var groups = collectGroups(container);
        var hostSelector = container.getAttribute('data-paginate-controls');
        var host = hostSelector ? document.querySelector(hostSelector) : null;

        if (!host) {
            host = document.createElement('div');
            host.className = 'cl-pager-host';
            container.insertAdjacentElement('afterend', host);
        }

        if (groups.length <= 1) {
            host.hidden = true;
            return;
        }

        var state = {
            page: 1,
            pref: readPref(),
            perPage: 10
        };

        function totalPages() {
            return Math.max(1, Math.ceil(groups.length / state.perPage));
        }

        function apply() {
            var pages = totalPages();
            if (state.page > pages) state.page = pages;
            if (state.page < 1) state.page = 1;

            var start = (state.page - 1) * state.perPage;
            var end = start + state.perPage;

            groups.forEach(function (group, index) {
                var visible = index >= start && index < end;
                group.els.forEach(function (el) { el.hidden = !visible; });
            });
        }

        function compute() {
            showAll(groups);
            state.perPage = state.pref === 'auto'
                ? autoSize(container, groups)
                : parseInt(state.pref, 10);
            if (!state.perPage || state.perPage < 1) state.perPage = 10;
        }

        function scrollToStart() {
            var card = container.closest('.card');
            var anchor = card || container;
            var top = anchor.getBoundingClientRect().top + window.pageYOffset - 88;
            window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
        }

        function render() {
            var pages = totalPages();

            if (pages <= 1) {
                host.hidden = true;
                host.innerHTML = '';
                return;
            }

            host.hidden = false;

            var from = (state.page - 1) * state.perPage + 1;
            var to = Math.min(groups.length, state.page * state.perPage);
            var label = container.getAttribute('data-paginate-label') || 'registros';

            var html = '<nav class="cl-pager" aria-label="Paginación de ' + label + '">' +
                '<p class="cl-pager-info" aria-live="polite">Mostrando <strong>' + from + '–' + to +
                '</strong> de <strong>' + groups.length + '</strong></p>' +
                '<div class="cl-pager-controls">' +
                '<label class="cl-pager-size">Por página <select aria-label="Registros por página">';

            SIZES.forEach(function (size) {
                html += '<option value="' + size + '"' + (state.pref === size ? ' selected' : '') + '>' +
                    (size === 'auto' ? 'Auto' : size) + '</option>';
            });

            html += '</select></label><ul class="pagination pagination-sm">';
            html += '<li class="page-item' + (state.page === 1 ? ' disabled' : '') + '">' +
                '<button class="page-link" type="button" data-page="' + (state.page - 1) +
                '" aria-label="Página anterior">‹</button></li>';

            pageSequence(state.page, pages).forEach(function (value) {
                if (value === '…') {
                    html += '<li class="page-item disabled"><span class="page-link">…</span></li>';
                } else {
                    html += '<li class="page-item' + (value === state.page ? ' active' : '') + '">' +
                        '<button class="page-link" type="button" data-page="' + value + '"' +
                        (value === state.page ? ' aria-current="page"' : '') + '>' + value + '</button></li>';
                }
            });

            html += '<li class="page-item' + (state.page === pages ? ' disabled' : '') + '">' +
                '<button class="page-link" type="button" data-page="' + (state.page + 1) +
                '" aria-label="Página siguiente">›</button></li></ul></div></nav>';

            host.innerHTML = html;

            host.querySelectorAll('[data-page]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var next = parseInt(button.getAttribute('data-page'), 10);
                    if (!next || next === state.page) return;
                    state.page = next;
                    apply();
                    render();
                    scrollToStart();
                });
            });

            var select = host.querySelector('select');
            if (select) {
                select.addEventListener('change', function () {
                    state.pref = select.value;
                    savePref(state.pref);
                    state.page = 1;
                    compute();
                    apply();
                    render();
                });
            }
        }

        compute();
        apply();
        render();

        var resizeTimer = null;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                compute();
                apply();
                render();
            }, 180);
        });

        window.addEventListener('load', function () {
            compute();
            apply();
            render();
        });
    }

    function boot() {
        document.querySelectorAll('[data-paginate]').forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
