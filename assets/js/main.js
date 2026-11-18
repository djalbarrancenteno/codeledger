/* CodeLedger · interacción de la interfaz
   - Tema claro / oscuro / sistema (+ Chart.js adaptado)
   - Sidebar colapsable/responsivo   - Toasts (mensajes flash y errores)
   - Diálogo de confirmación propio  - Spinners en envíos / sincronizaciones
   - Confirmación de ediciones en línea (tarifas, reglas contables)
   Funciona sin Bootstrap JS en login.php; lo que lo necesita se activa solo si existe. */
(function () {
    'use strict';

    // ---------- Toasts ----------
    var ICONS = { success: 'bi-check-circle-fill', danger: 'bi-x-octagon-fill', warning: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };

    function toastHost() {
        var host = document.getElementById('clToasts');
        if (!host) {
            host = document.createElement('div');
            host.id = 'clToasts';
            host.className = 'cl-toasts';
            host.setAttribute('aria-live', 'polite');
            document.body.appendChild(host);
        }
        return host;
    }

    function toast(type, message) {
        type = ICONS[type] ? type : 'info';
        var el = document.createElement('div');
        el.className = 'cl-toast ' + type;
        el.setAttribute('role', type === 'danger' ? 'alert' : 'status');
        var icon = document.createElement('i');
        icon.className = 'bi ' + ICONS[type] + ' t-ico';
        var msg = document.createElement('div');
        msg.className = 't-msg';
        msg.textContent = message;               // textContent: sin riesgo de inyección HTML
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 't-close';
        close.setAttribute('aria-label', 'Cerrar');
        close.innerHTML = '&times;';
        el.appendChild(icon); el.appendChild(msg); el.appendChild(close);
        toastHost().appendChild(el);

        var timer;
        function dismiss() {
            clearTimeout(timer);
            el.classList.add('is-leaving');
            setTimeout(function () { el.remove(); }, 200);
        }
        function arm() { timer = setTimeout(dismiss, type === 'danger' ? 8000 : 5000); }
        close.addEventListener('click', dismiss);
        el.addEventListener('mouseenter', function () { clearTimeout(timer); });
        el.addEventListener('mouseleave', arm);
        arm();
    }
    window.CL = window.CL || {};
    window.CL.toast = toast;

    // ---------- Tema: claro / oscuro / sistema ----------
    // La preferencia se guarda en localStorage ('cl.theme') y se aplica con
    // <html data-bs-theme="light|dark"> (Bootstrap 5.3) + <html data-theme-pref="light|dark|system">.
    // El script del <head> ya lo aplica antes del primer pintado; aquí se gestionan los cambios.
    var THEME_KEY = 'cl.theme';
    var darkQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function readPref() {
        var v = 'system';
        try { v = localStorage.getItem(THEME_KEY) || 'system'; } catch (e) { /* sin storage */ }
        return (v === 'light' || v === 'dark') ? v : 'system';
    }
    function resolveTheme(pref) {
        return pref === 'system' ? (darkQuery && darkQuery.matches ? 'dark' : 'light') : pref;
    }
    function cssVar(name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name);
        return (v && v.trim()) || fallback;
    }

    // Chart.js: colores de texto, rejilla y tooltips según el tema activo
    function applyChartTheme() {
        if (!window.Chart) return;
        var C = Chart.defaults;
        C.font.family = '"Inter", "Segoe UI", system-ui, sans-serif';
        C.font.size = 11;
        C.color = cssVar('--cl-chart-text', '#626c86');
        C.borderColor = cssVar('--cl-chart-grid', '#eceef6');
        C.plugins.legend.labels.usePointStyle = true;
        C.plugins.legend.labels.boxWidth = 8;
        C.plugins.legend.labels.boxHeight = 8;
        C.plugins.legend.labels.padding = 16;
        C.plugins.tooltip.backgroundColor = cssVar('--cl-tooltip-bg', '#0f1424');
        C.plugins.tooltip.titleColor = cssVar('--cl-tooltip-text', '#f1f4fb');
        C.plugins.tooltip.bodyColor = cssVar('--cl-tooltip-text', '#f1f4fb');
        C.plugins.tooltip.padding = 10;
        C.plugins.tooltip.cornerRadius = 10;
        C.plugins.tooltip.boxPadding = 4;
        try { C.scale.border.display = false; } catch (e) { /* versión sin scale.border */ }
        try { C.scale.grid.drawTicks = false; } catch (e) { /* idem */ }
        try { C.scales.category.grid.display = false; } catch (e) { /* sin rejilla vertical en ejes de categorías */ }
    }
    function refreshCharts() {
        if (!window.Chart) return;
        applyChartTheme();
        Object.keys(Chart.instances || {}).forEach(function (id) {
            var chart = Chart.instances[id];
            if (chart) chart.update('none');
        });
    }

    function applyTheme(pref, animate) {
        var root = document.documentElement;
        if (animate) {
            root.classList.add('theme-anim');
            setTimeout(function () { root.classList.remove('theme-anim'); }, 350);
        }
        root.setAttribute('data-bs-theme', resolveTheme(pref));
        root.setAttribute('data-theme-pref', pref);
        refreshCharts();
        document.querySelectorAll('[data-theme-choice]').forEach(function (b) {
            b.setAttribute('aria-pressed', b.dataset.themeChoice === pref ? 'true' : 'false');
        });
    }
    function setPref(pref) {
        try { localStorage.setItem(THEME_KEY, pref); } catch (e) { /* sin storage */ }
        applyTheme(pref, true);
    }

    // Si la preferencia es "sistema", seguir los cambios del sistema operativo en vivo
    if (darkQuery) {
        var onSystemChange = function () { if (readPref() === 'system') applyTheme('system', true); };
        if (darkQuery.addEventListener) darkQuery.addEventListener('change', onSystemChange);
        else if (darkQuery.addListener) darkQuery.addListener(onSystemChange);
    }
    // Sincronizar entre pestañas
    window.addEventListener('storage', function (e) { if (e.key === THEME_KEY) applyTheme(readPref(), false); });

    window.CL = window.CL || {};
    window.CL.theme = function () { return document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light'; };
    window.CL.setTheme = setPref;
    // Degradado vertical para barras/áreas (usa el área del gráfico; vuelve al color plano si aún no existe)
    window.CL.gradient = function (context, top, bottom) {
        var chart = context.chart, area = chart.chartArea;
        if (!area) return top;
        var g = chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
        g.addColorStop(0, top); g.addColorStop(1, bottom);
        return g;
    };
    // Chart.js ya está cargado (el layout lo incluye antes de este archivo): se aplica antes de que
    // las páginas creen sus gráficos, y se reaplica en cada cambio de tema.
    applyTheme(readPref(), false);

    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-theme-choice]') : null;
        if (btn) setPref(btn.dataset.themeChoice);
    });

    // ---------- Diálogo de confirmación ----------
    var dialog = null, pending = false;

    function buildDialog() {
        dialog = document.createElement('dialog');
        dialog.className = 'cl-dialog';
        dialog.innerHTML =
            '<div class="cl-dialog-body">' +
            '<div class="cl-dialog-icon"><i class="bi bi-question-lg"></i></div>' +
            '<div><h3>Confirmar acción</h3><p data-msg></p></div></div>' +
            '<div class="cl-dialog-actions">' +
            '<button type="button" class="btn btn-ghost" data-cancel>Cancelar</button>' +
            '<button type="button" class="btn btn-primary" data-ok>Confirmar</button></div>';
        document.body.appendChild(dialog);
        return dialog;
    }

    function confirmAction(message) {
        return new Promise(function (resolve) {
            if (typeof HTMLDialogElement === 'undefined' || !HTMLDialogElement.prototype.showModal) {
                resolve(window.confirm(message));     // navegadores antiguos
                return;
            }
            var d = dialog || buildDialog();
            var destructive = /elimin|anular|desconect|borrar|remover/i.test(message);
            d.classList.toggle('is-danger', destructive);
            d.querySelector('.cl-dialog-icon i').className = 'bi ' + (destructive ? 'bi-exclamation-triangle-fill' : 'bi-question-lg');
            d.querySelector('[data-msg]').textContent = message;
            var ok = d.querySelector('[data-ok]'), cancel = d.querySelector('[data-cancel]');
            ok.className = 'btn ' + (destructive ? 'btn-danger' : 'btn-primary');
            var done = false;
            function finish(result) {
                if (done) return;
                done = true;
                ok.removeEventListener('click', onOk);
                cancel.removeEventListener('click', onCancel);
                d.removeEventListener('cancel', onCancel);
                d.removeEventListener('close', onCancel);
                if (d.open) d.close();
                resolve(result);
            }
            function onOk() { finish(true); }
            function onCancel() { finish(false); }
            ok.addEventListener('click', onOk);
            cancel.addEventListener('click', onCancel);
            d.addEventListener('cancel', onCancel);
            d.addEventListener('close', onCancel);
            d.showModal();
            cancel.focus();
        });
    }
    window.CL.confirm = confirmAction;

    // ---------- Estados de carga ----------
    function setLoading(form, submitter) {
        var btn = submitter || form.querySelector('button[type="submit"], button:not([type])');
        form.dataset.submitting = '1';
        if (!btn) return;
        btn.dataset.orig = btn.innerHTML;
        btn.classList.add('is-loading');
        btn.setAttribute('aria-busy', 'true');
        // No se deshabilita el botón: un botón deshabilitado no envía su name/value.
        var label = form.dataset.loadingText || btn.dataset.loadingText;
        var spinner = '<span class="btn-spinner" aria-hidden="true"></span>';
        btn.innerHTML = spinner + (label ? label : btn.innerHTML);
    }
    function resetLoading() {
        document.querySelectorAll('form[data-submitting]').forEach(function (f) {
            delete f.dataset.submitting;
            delete f.dataset.confirmed;
        });
        document.querySelectorAll('.btn.is-loading').forEach(function (b) {
            if (b.dataset.orig !== undefined) b.innerHTML = b.dataset.orig;
            b.classList.remove('is-loading');
            b.removeAttribute('aria-busy');
        });
    }
    window.addEventListener('pageshow', function (e) { if (e.persisted) resetLoading(); });

    // ---------- Envío de formularios: confirmación + spinner ----------
    function restoreOriginals(form) {
        form.querySelectorAll('[data-orig-value]').forEach(function (field) { field.value = field.dataset.origValue; });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        var message = form.dataset.confirm;
        var isPost = (form.getAttribute('method') || 'get').toLowerCase() === 'post';

        if (form.dataset.submitting) { event.preventDefault(); return; }   // evita doble envío

        if (message && !form.dataset.confirmed) {
            event.preventDefault();
            if (pending) return;                                           // ya hay un diálogo abierto
            pending = true;
            var submitter = event.submitter || null;
            confirmAction(message).then(function (ok) {
                pending = false;
                if (!ok) { restoreOriginals(form); return; }
                form.dataset.confirmed = '1';
                if (form.requestSubmit) form.requestSubmit(submitter); else form.submit();
            });
            return;
        }
        if (isPost && !event.defaultPrevented) setLoading(form, event.submitter || null);
    });

    // ---------- Ediciones en línea con confirmación (data-confirm-change) ----------
    document.querySelectorAll('form[data-confirm-change]').forEach(function (form) {
        form.dataset.confirm = form.dataset.confirmChange;
        form.querySelectorAll('input:not([type="hidden"]), select').forEach(function (field) {
            field.dataset.origValue = field.value;
            field.addEventListener('change', function () {
                if (field.value === field.dataset.origValue) return;
                if (form.requestSubmit) form.requestSubmit(); else form.submit();
            });
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.documentElement;

        // ---------- Sidebar ----------
        var toggle = document.getElementById('sidebarToggle');
        var collapseBtn = document.getElementById('sidebarCollapse');
        var sidebar = document.getElementById('sidebar');
        var backdrop = document.getElementById('sidebarBackdrop');
        var isDesktop = function () { return window.innerWidth >= 1200; };
        function setCollapsed(on) {
            root.classList.toggle('sidebar-collapsed', on);
            try { localStorage.setItem('cl.sidebar', on ? 'collapsed' : 'expanded'); } catch (e) { /* sin storage */ }
        }
        function closeMobile() {
            if (sidebar) sidebar.classList.remove('show');
            if (backdrop) backdrop.classList.remove('show');
        }
        if (sidebar) {
            if (toggle) toggle.addEventListener('click', function () {
                if (isDesktop()) { setCollapsed(!root.classList.contains('sidebar-collapsed')); return; }
                sidebar.classList.toggle('show');
                if (backdrop) backdrop.classList.toggle('show', sidebar.classList.contains('show'));
            });
            if (collapseBtn) collapseBtn.addEventListener('click', function () { setCollapsed(!root.classList.contains('sidebar-collapsed')); });
            if (backdrop) backdrop.addEventListener('click', closeMobile);
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMobile(); });
            window.addEventListener('resize', function () { if (isDesktop()) closeMobile(); });
        }

        // ---------- Mensajes flash -> toasts ----------
        var flashHost = document.getElementById('clFlash');
        if (flashHost) {
            try {
                JSON.parse(flashHost.dataset.flashes || '[]').forEach(function (f, i) {
                    setTimeout(function () { toast(f.type, f.message); }, i * 140);
                });
            } catch (e) { /* JSON inválido: se ignora */ }
        }

        // ---------- Acción principal de la topbar ----------
        document.querySelectorAll('[data-primary-action]').forEach(function (btn) {
            var target = btn.getAttribute('data-bs-target');
            if (target && document.querySelector(target)) btn.hidden = false;
        });

        // ---------- Drawers que se abren solos (p. ej. detalle de un colaborador) ----------
        document.querySelectorAll('.offcanvas[data-auto-open]').forEach(function (el) {
            if (!window.bootstrap) return;
            var drawer = bootstrap.Offcanvas.getOrCreateInstance(el);
            if (el.dataset.returnUrl) {
                el.addEventListener('hidden.bs.offcanvas', function () { window.location.href = el.dataset.returnUrl; });
            }
            drawer.show();
        });

        // ---------- Pestañas: mantener ?tab= en la URL ----------
        document.querySelectorAll('[data-bs-toggle="pill"], [data-bs-toggle="tab"]').forEach(function (tab) {
            tab.addEventListener('shown.bs.tab', function () {
                var target = (tab.getAttribute('data-bs-target') || '').replace('#tab-', '');
                if (!target || !window.history.replaceState) return;
                var url = new URL(window.location.href);
                url.searchParams.set('tab', target);
                window.history.replaceState(null, '', url);
            });
        });

        // ---------- Login: acceso demo y mostrar/ocultar contraseña ----------
        document.querySelectorAll('[data-demo-email]').forEach(function (button) {
            button.addEventListener('click', function () {
                var email = document.querySelector('#correo');
                var password = document.querySelector('#contrasena');
                if (email) email.value = button.dataset.demoEmail;
                if (password) password.value = 'demo1234';
                if (email) email.focus();
            });
        });
        document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var input = document.querySelector(btn.dataset.togglePassword);
                if (!input) return;
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
                var icon = btn.querySelector('i');
                if (icon) icon.className = 'bi ' + (show ? 'bi-eye-slash' : 'bi-eye');
            });
        });

        // ---------- Filtro proyecto -> tarea en timesheets ----------
        var projectFilter = document.getElementById('projectSelect');
        var taskSelect = document.getElementById('taskSelect');
        if (projectFilter && taskSelect) {
            var filterTasks = function () {
                var project = projectFilter.value;
                var visible = 0;
                Array.from(taskSelect.options).forEach(function (option) {
                    var matches = !project || option.dataset.project === project;
                    option.hidden = !matches;
                    option.disabled = !matches;
                    if (matches) visible++;
                });
                var current = taskSelect.selectedOptions[0];
                if (current && current.disabled) taskSelect.value = '';
                if (visible === 0) taskSelect.value = '';
            };
            projectFilter.addEventListener('change', filterTasks);
            filterTasks();
        }

        // ---------- Autoenviar filtros ----------
        document.querySelectorAll('[data-auto-submit]').forEach(function (field) {
            field.addEventListener('change', function () {
                var form = field.closest('form');
                if (form) form.submit();
            });
        });
    });
})();
