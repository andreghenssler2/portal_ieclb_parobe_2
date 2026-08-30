/* Portal IECLB Parobé v0.54.0 - Submenus recursivos */
(() => {
  'use strict';

  const nestedSelector = '[data-portal-submenu-toggle]';

  function nestedMenu(toggle) {
    const item = toggle.closest('.dropdown-submenu');
    if (!item) return null;

    return Array.from(item.children).find(
      child => child.classList?.contains('dropdown-menu')
    ) || null;
  }

  function closeNested(root, exceptMenu = null) {
    if (!root) return;

    root.querySelectorAll('.dropdown-submenu > .dropdown-menu.show')
      .forEach(menu => {
        if (menu === exceptMenu || menu.contains(exceptMenu)) {
          return;
        }

        menu.classList.remove('show');

        const item = menu.closest('.dropdown-submenu');
        const toggle = item?.querySelector(
          ':scope > .portal-submenu-row > [data-portal-submenu-toggle]'
        );

        toggle?.setAttribute('aria-expanded', 'false');
      });
  }

  document.addEventListener('click', event => {
    const toggle = event.target.closest(nestedSelector);

    if (!toggle) return;

    event.preventDefault();
    event.stopPropagation();

    const item = toggle.closest('.dropdown-submenu');
    const menu = nestedMenu(toggle);

    if (!item || !menu) return;

    const opening = !menu.classList.contains('show');
    const parentMenu = item.parentElement;

    if (opening && parentMenu) {
      Array.from(parentMenu.children).forEach(sibling => {
        if (
          sibling === item
          || !sibling.classList?.contains('dropdown-submenu')
        ) {
          return;
        }

        const siblingMenu = Array.from(sibling.children).find(
          child => child.classList?.contains('dropdown-menu')
        );

        siblingMenu?.classList.remove('show');

        sibling.querySelector(
          ':scope > .portal-submenu-row > [data-portal-submenu-toggle]'
        )?.setAttribute('aria-expanded', 'false');
      });
    }

    menu.classList.toggle('show', opening);
    toggle.setAttribute(
      'aria-expanded',
      opening ? 'true' : 'false'
    );
  });

  /*
   * Mantém o dropdown principal aberto quando o usuário está apenas
   * expandindo um nível interno.
   */
  document.addEventListener('click', event => {
    if (event.target.closest('.portal-submenu')) {
      event.stopPropagation();
    }
  });

  document.addEventListener('hide.bs.dropdown', event => {
    const root = event.target.closest('.portal-menu-root-parent')
      || event.target.parentElement;

    closeNested(root);
  });

  /*
   * Se um submenu lateral ultrapassar a borda direita da tela,
   * abre automaticamente para o lado esquerdo.
   */
  document.querySelectorAll('.dropdown-submenu')
    .forEach(item => {
      item.addEventListener('mouseenter', () => {
        if (window.innerWidth < 992) {
          item.classList.remove('portal-submenu-left');
          return;
        }

        const menu = Array.from(item.children).find(
          child => child.classList?.contains('dropdown-menu')
        );

        if (!menu) return;

        item.classList.remove('portal-submenu-left');

        requestAnimationFrame(() => {
          const rect = menu.getBoundingClientRect();

          if (rect.right > window.innerWidth - 8) {
            item.classList.add('portal-submenu-left');
          }
        });
      });
    });

  window.addEventListener('resize', () => {
    if (window.innerWidth < 992) {
      document.querySelectorAll('.portal-submenu-left')
        .forEach(item => {
          item.classList.remove('portal-submenu-left');
        });
    }
  });
})();
