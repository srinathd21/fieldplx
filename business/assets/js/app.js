document.addEventListener('DOMContentLoaded', () => {
  if (window.lucide) lucide.createIcons();

  const appShell = document.getElementById('appShell');
  const sidebar = document.getElementById('sidebar');
  const backdrop = document.getElementById('backdrop');
  const sidebarToggle = document.getElementById('sidebarToggle');
  const mobileMenuButton = document.getElementById('mobileMenuButton');

  if (sidebarToggle && appShell) {
    sidebarToggle.addEventListener('click', () => {
      const collapsed = appShell.classList.toggle('collapsed');
      sidebarToggle.setAttribute('aria-expanded', String(!collapsed));
    });
  }

  if (mobileMenuButton && sidebar && backdrop) {
    mobileMenuButton.addEventListener('click', () => {
      sidebar.classList.toggle('open');
      backdrop.classList.toggle('open');
    });
  }

  if (backdrop && sidebar) {
    backdrop.addEventListener('click', () => {
      sidebar.classList.remove('open');
      backdrop.classList.remove('open');
    });
  }

  window.showToast = function(message) {
    const el = document.getElementById('toast');
    if (!el) return;
    el.textContent = message;
    el.classList.add('show');
    clearTimeout(window.__toast);
    window.__toast = setTimeout(() => el.classList.remove('show'), 2200);
  };

  document.querySelectorAll('[data-action]').forEach(btn => {
    btn.addEventListener('click', () => {
      const a = btn.dataset.action;
      if (a === 'print') {
        window.print();
        return;
      }
      const labels = {
        edit: 'Edit invoice opened.',
        download: 'Invoice PDF download prepared.',
        send: 'Invoice send options opened.',
        payment: 'Record payment opened.',
        more: 'More invoice actions opened.'
      };
      showToast(labels[a] || 'Action opened.');
    });
  });


  /* =========================================================
     Account dropdown
     ========================================================= */
  const accountDropdown = document.getElementById('accountDropdown');
  const accountMenuToggle = document.getElementById('accountMenuToggle');
  const profileMenuToggle = document.getElementById('profileMenuToggle');

  function setAccountDropdown(open) {
    if (!accountDropdown) return;

    accountDropdown.classList.toggle('open', open);
    accountDropdown.setAttribute('aria-hidden', open ? 'false' : 'true');

    accountMenuToggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
    profileMenuToggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function toggleAccountDropdown(event) {
    event?.stopPropagation();
    const willOpen = !accountDropdown?.classList.contains('open');

    closeRightDrawer();
    setAccountDropdown(willOpen);
  }

  accountMenuToggle?.addEventListener('click', toggleAccountDropdown);
  profileMenuToggle?.addEventListener('click', toggleAccountDropdown);

  accountDropdown?.addEventListener('click', event => {
    event.stopPropagation();
  });

  document.addEventListener('click', () => {
    setAccountDropdown(false);
  });

  /* =========================================================
     Global dark mode
     ========================================================= */
  const accountDarkModeToggle = document.getElementById('accountDarkModeToggle');
  const accountDarkModeSwitch = document.getElementById('accountDarkModeSwitch');

  function isDarkModeEnabled() {
    return document.documentElement.classList.contains('app-dark-mode');
  }

  function applyGlobalDarkMode(enabled) {
    document.documentElement.classList.toggle('app-dark-mode', enabled);
    accountDarkModeSwitch?.classList.toggle('on', enabled);

    try {
      localStorage.setItem('fieldplx_dark_mode', enabled ? '1' : '0');
    } catch (e) {}
  }

  applyGlobalDarkMode(isDarkModeEnabled());

  accountDarkModeToggle?.addEventListener('click', event => {
    event.preventDefault();
    event.stopPropagation();
    applyGlobalDarkMode(!isDarkModeEnabled());
  });

  /* =========================================================
     Help / Notification right drawer
     ========================================================= */
  const rightDrawer = document.getElementById('rightDrawer');
  const rightDrawerOverlay = document.getElementById('rightDrawerOverlay');
  const drawerTriggers = document.querySelectorAll('[data-right-drawer]');
  const drawerCloseButtons = document.querySelectorAll('.right-drawer-close');

  function showDrawerView(viewName) {
    document.querySelectorAll('.right-drawer-view').forEach(view => {
      view.classList.toggle('active', view.dataset.drawerView === viewName);
    });
  }

  function openRightDrawer(viewName) {
    if (!rightDrawer) return;

    setAccountDropdown(false);
    showDrawerView(viewName);

    rightDrawer.classList.add('open');
    rightDrawer.setAttribute('aria-hidden', 'false');

    rightDrawerOverlay?.classList.add('open');
    rightDrawerOverlay?.setAttribute('aria-hidden', 'false');

    requestAnimationFrame(() => {
      if (window.lucide) lucide.createIcons();
    });
  }

  function closeRightDrawer() {
    if (!rightDrawer) return;

    rightDrawer.classList.remove('open');
    rightDrawer.setAttribute('aria-hidden', 'true');

    rightDrawerOverlay?.classList.remove('open');
    rightDrawerOverlay?.setAttribute('aria-hidden', 'true');
  }

  drawerTriggers.forEach(trigger => {
    trigger.addEventListener('click', event => {
      event.preventDefault();
      event.stopPropagation();

      const viewName = trigger.dataset.rightDrawer;

      if (
        rightDrawer?.classList.contains('open') &&
        document.querySelector('.right-drawer-view.active')?.dataset.drawerView === viewName
      ) {
        closeRightDrawer();
      } else {
        openRightDrawer(viewName);
      }
    });
  });

  drawerCloseButtons.forEach(button => {
    button.addEventListener('click', closeRightDrawer);
  });

  rightDrawerOverlay?.addEventListener('click', closeRightDrawer);

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      setAccountDropdown(false);
      closeRightDrawer();
    }
  });

  /* Notification tabs */
  document.querySelectorAll('[data-notification-tab]').forEach(tab => {
    tab.addEventListener('click', () => {
      const selected = tab.dataset.notificationTab;

      document.querySelectorAll('[data-notification-tab]').forEach(item => {
        item.classList.toggle('active', item === tab);
      });

      document.querySelectorAll('[data-notification-panel]').forEach(panel => {
        panel.classList.toggle(
          'active',
          panel.dataset.notificationPanel === selected
        );
      });
    });
  });

});
