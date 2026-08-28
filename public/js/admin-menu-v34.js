/* Portal IECLB Parobé v0.34.0 - comportamento do menu administrativo */
(() => {
    'use strict';

    const STORAGE_KEY = 'portal.admin.sidebar.collapsed';
    const DESKTOP_MIN = 992;

    const isDesktop = () => window.innerWidth >= DESKTOP_MIN;
    const sidebar = () => document.getElementById('adminSidebar');

    function setCollapsed(collapsed, persist = true) {
        if (!isDesktop()) {
            document.body.classList.remove('admin-sidebar-collapsed');
            return;
        }
        document.body.classList.toggle('admin-sidebar-collapsed', collapsed);
        const button = document.getElementById('adminDesktopMenuToggle');
        if (button) {
            button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            button.setAttribute('title', collapsed ? 'Expandir menu' : 'Recolher menu');
            button.setAttribute('aria-label', collapsed ? 'Expandir menu administrativo' : 'Recolher menu administrativo');
            const icon = button.querySelector('i');
            if (icon) icon.className = collapsed ? 'bi bi-layout-sidebar-inset' : 'bi bi-list';
        }
        if (persist) {
            try { localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0'); } catch (_) {}
        }
    }

    function savedCollapsed() {
        try { return localStorage.getItem(STORAGE_KEY) === '1'; } catch (_) { return false; }
    }

    document.addEventListener('DOMContentLoaded', () => {
        setCollapsed(savedCollapsed(), false);

        const desktopToggle = document.getElementById('adminDesktopMenuToggle');
        if (desktopToggle) {
            desktopToggle.addEventListener('click', () => {
                setCollapsed(!document.body.classList.contains('admin-sidebar-collapsed'));
            });
        }

        document.querySelectorAll('.admin-nav-link').forEach((link) => {
            const label = link.querySelector('span');
            if (label && !link.getAttribute('title')) {
                link.setAttribute('title', label.textContent.trim());
            }
        });

        document.querySelectorAll('.admin-nav-toggle').forEach((toggle) => {
            toggle.addEventListener('click', (event) => {
                if (isDesktop() && document.body.classList.contains('admin-sidebar-collapsed')) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    setCollapsed(false);
                }
            }, true);
        });

        document.querySelectorAll('#adminSidebar a[href]').forEach((link) => {
            link.addEventListener('click', () => {
                if (isDesktop()) return;
                const element = sidebar();
                if (!element || !window.bootstrap || !bootstrap.Offcanvas) return;
                const instance = bootstrap.Offcanvas.getInstance(element);
                if (instance) instance.hide();
            });
        });

        window.addEventListener('resize', () => {
            if (isDesktop()) setCollapsed(savedCollapsed(), false);
            else document.body.classList.remove('admin-sidebar-collapsed');
        });
    });
})();
