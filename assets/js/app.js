/* Shared JavaScript for the Laundry Shop Management System. */

document.addEventListener('DOMContentLoaded', function () {
  // Mobile sidebar toggle
  const toggle = document.getElementById('menuToggle');
  const sidebar = document.getElementById('sidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', () => sidebar.classList.toggle('open'));
    document.addEventListener('click', (ev) => {
      if (sidebar.classList.contains('open') && !sidebar.contains(ev.target) && !toggle.contains(ev.target)) {
        sidebar.classList.remove('open');
      }
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
