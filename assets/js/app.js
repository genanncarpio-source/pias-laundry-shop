/* Shared JavaScript for the Laundry POS system. */

document.addEventListener('DOMContentLoaded', function () {
  // Mobile sidebar drawer
  const toggle = document.getElementById('menuToggle');
  const sidebar = document.getElementById('sidebar');
  const backdrop = document.getElementById('sidebarBackdrop');
  if (toggle && sidebar) {
    const closeSidebar = () => {
      sidebar.classList.remove('open');
      document.body.classList.remove('nav-open');
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-label', 'Open navigation menu');
    };
    const openSidebar = () => {
      sidebar.classList.add('open');
      document.body.classList.add('nav-open');
      toggle.setAttribute('aria-expanded', 'true');
      toggle.setAttribute('aria-label', 'Close navigation menu');
    };
    toggle.addEventListener('click', () => sidebar.classList.contains('open') ? closeSidebar() : openSidebar());
    backdrop?.addEventListener('click', closeSidebar);
    sidebar.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeSidebar));
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && sidebar.classList.contains('open')) closeSidebar();
    });
  }

  // Auto-dismiss flash messages
  document.querySelectorAll('.flash[data-auto-dismiss]').forEach((el) => {
    setTimeout(() => {
      el.style.transition = 'opacity .4s';
      el.style.opacity = '0';
      setTimeout(() => el.remove(), 400);
    }, 3500);
  });

  // Confirm dialogs via data-confirm attribute
  document.querySelectorAll('[data-confirm]').forEach((el) => {
    el.addEventListener('click', (ev) => {
      if (!window.confirm(el.getAttribute('data-confirm'))) {
        ev.preventDefault();
      }
    });
  });

  // Buttons that trigger print (receipts / reports)
  document.querySelectorAll('[data-print]').forEach((el) => {
    el.addEventListener('click', () => window.print());
  });

  // Open an order when any non-control part of its table row is selected.
  document.querySelectorAll('.order-row[data-order-url]').forEach((row) => {
    const openOrder = () => { window.location.href = row.dataset.orderUrl; };
    row.addEventListener('click', (event) => {
      if (event.target.closest('a, button, input, select, textarea, label')) return;
      openOrder();
    });
    row.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openOrder();
      }
    });
  });

  // Generic modal open/close helpers
  document.querySelectorAll('[data-modal-open]').forEach((el) => {
    el.addEventListener('click', () => {
      const target = document.getElementById(el.getAttribute('data-modal-open'));
      if (target) target.classList.add('open');
    });
  });
  document.querySelectorAll('[data-modal-close]').forEach((el) => {
    el.addEventListener('click', () => {
      const modal = el.closest('.modal-overlay');
      if (modal) modal.classList.remove('open');
    });
  });
  document.querySelectorAll('.modal-overlay').forEach((overlay) => {
    overlay.addEventListener('click', (ev) => {
      if (ev.target === overlay) overlay.classList.remove('open');
    });
  });
});

/** Escape HTML for safe client-side rendering. */
function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

/** Format a number as peso currency. */
function peso(n) {
  return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
