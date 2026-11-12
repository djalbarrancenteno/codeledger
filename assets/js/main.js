document.addEventListener('DOMContentLoaded', () => {
    // Sidebar móvil
    const toggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    if (toggle && sidebar) {
        toggle.addEventListener('click', () => sidebar.classList.toggle('show'));
        document.addEventListener('click', (event) => {
            if (window.innerWidth < 1200 && sidebar.classList.contains('show') && !sidebar.contains(event.target) && event.target !== toggle) {
                sidebar.classList.remove('show');
            }
        });
    }

    // Confirmaciones
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    // Acceso rápido demo
    document.querySelectorAll('[data-demo-email]').forEach((button) => {
        button.addEventListener('click', () => {
            const email = document.querySelector('#correo');
            const password = document.querySelector('#contrasena');
            if (email) email.value = button.dataset.demoEmail;
            if (password) password.value = 'demo1234';
            if (email) email.focus();
        });
    });

    // Filtro proyecto -> tarea en timesheets
    const projectFilter = document.getElementById('projectSelect');
    const taskSelect = document.getElementById('taskSelect');
    if (projectFilter && taskSelect) {
        const filterTasks = () => {
            const project = projectFilter.value;
            let visible = 0;
            Array.from(taskSelect.options).forEach((option) => {
                const matches = !project || option.dataset.project === project;
                option.hidden = !matches;
                option.disabled = !matches;
                if (matches) visible++;
            });
            const current = taskSelect.selectedOptions[0];
            if (current && current.disabled) {
                taskSelect.value = '';
            }
            if (visible === 0) {
                taskSelect.value = '';
            }
        };
        projectFilter.addEventListener('change', filterTasks);
        filterTasks();
    }

    // Autoenviar filtros
    document.querySelectorAll('[data-auto-submit]').forEach((field) => {
        field.addEventListener('change', () => field.closest('form')?.submit());
    });

    // Chart.js defaults
    if (window.Chart) {
        Chart.defaults.font.family = '"Inter", "Segoe UI", system-ui, sans-serif';
        Chart.defaults.font.size = 11;
        Chart.defaults.color = '#6b7280';
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.boxWidth = 8;
        Chart.defaults.plugins.legend.labels.boxHeight = 8;
        Chart.defaults.borderColor = '#eef1f5';
    }
});
