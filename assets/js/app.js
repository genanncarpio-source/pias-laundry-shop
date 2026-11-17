/* Shared JavaScript for the Laundry POS system. */

document.addEventListener('DOMContentLoaded', function () {
  // Add a consistent show/hide control to password inputs on authenticated pages.
  document.querySelectorAll('input[type="password"]').forEach((input) => {
    let field = input.closest('.password-field');
    if (!field) {
      field = document.createElement('div');
      field.className = 'password-field';
      input.parentNode.insertBefore(field, input);
      field.appendChild(input);
    }
    if (field.querySelector('.toggle-password')) return;

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'toggle-password';
    toggle.setAttribute('aria-label', 'Show password');
    toggle.setAttribute('title', 'Show password');
    toggle.setAttribute('aria-pressed', 'false');
    toggle.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path class="eye-open" d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle class="eye-open" cx="12" cy="12" r="3"/><path class="eye-closed" d="m3 3 18 18M10.6 5.1A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.1 3.9M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.1 0 2.2-.2 3.1-.6"/><path class="eye-closed" d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
    toggle.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      toggle.querySelectorAll('.eye-open').forEach((part) => { part.style.display = show ? '' : 'none'; });
      toggle.querySelectorAll('.eye-closed').forEach((part) => { part.style.display = show ? 'none' : ''; });
      toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      toggle.setAttribute('title', show ? 'Hide password' : 'Show password');
      toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
      input.focus();
    });
    field.appendChild(toggle);
  });

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

  // Poll staff notifications and surface new items without reloading the page.
  const appRoot = document.querySelector('.app[data-notification-poll-url]');
  if (appRoot) {
    let latestNotificationId = Number(appRoot.dataset.notificationLatestId || 0);
    let polling = false;
    const updateUnreadCount = (count) => {
      const badge = document.querySelector('[data-notification-count]');
      if (!badge) return;
      badge.textContent = String(count);
      badge.hidden = Number(count) < 1;
    };
    const appendNotification = (notice) => {
      const list = document.querySelector('[data-notification-list]');
      if (!list) return;
      const tbody = list.querySelector('tbody');
      if (!tbody || document.getElementById('notification-' + notice.id)) return;
      list.querySelector('[data-notification-empty]')?.setAttribute('hidden', '');
      document.querySelector('[data-clear-all-notifications]')?.removeAttribute('hidden');

      const row = document.createElement('tr');
      row.id = 'notification-' + notice.id;
      row.dataset.notificationId = String(notice.id);
      const detail = document.createElement('td');
      const title = document.createElement('strong');
      title.textContent = notice.title;
      detail.append(title, document.createElement('br'), document.createTextNode(notice.message));
      const order = document.createElement('td');
      if (notice.order_id) {
        const orderLink = document.createElement('a');
        orderLink.href = 'order_view.php?id=' + encodeURIComponent(notice.order_id);
        orderLink.textContent = notice.order_number || 'View order';
        order.appendChild(orderLink);
      } else {
        order.textContent = '—';
      }
      const date = document.createElement('td');
      const parsedDate = new Date(String(notice.created_at).replace(' ', 'T'));
      date.textContent = Number.isNaN(parsedDate.getTime()) ? notice.created_at : parsedDate.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' });
      const status = document.createElement('td');
      status.textContent = notice.is_read ? 'Read' : 'Unread';
      const action = document.createElement('td');
      const actionGroup = document.createElement('div');
      actionGroup.className = 'notification-actions';
      if (!notice.is_read) {
        const form = document.createElement('form');
        form.method = 'post';
        const csrf = document.createElement('input');
        csrf.type = 'hidden'; csrf.name = 'csrf'; csrf.value = list.dataset.csrfToken || '';
        const noticeId = document.createElement('input');
        noticeId.type = 'hidden'; noticeId.name = 'notification_id'; noticeId.value = String(notice.id);
        const markRead = document.createElement('button');
        markRead.className = 'btn btn-outline btn-sm'; markRead.name = 'mark_read'; markRead.value = '1'; markRead.textContent = 'Mark read';
        form.append(csrf, noticeId, markRead);
        actionGroup.appendChild(form);
      }
      const deleteForm = document.createElement('form');
      deleteForm.method = 'post';
      deleteForm.onsubmit = () => window.confirm('Delete this notification?');
      const deleteCsrf = document.createElement('input');
      deleteCsrf.type = 'hidden'; deleteCsrf.name = 'csrf'; deleteCsrf.value = list.dataset.csrfToken || '';
      const deleteNoticeId = document.createElement('input');
      deleteNoticeId.type = 'hidden'; deleteNoticeId.name = 'notification_id'; deleteNoticeId.value = String(notice.id);
      const deleteButton = document.createElement('button');
      deleteButton.className = 'btn btn-danger btn-sm'; deleteButton.name = 'delete_notification'; deleteButton.value = '1';
      deleteButton.setAttribute('aria-label', 'Delete notification'); deleteButton.title = 'Delete notification'; deleteButton.textContent = '×';
      deleteForm.append(deleteCsrf, deleteNoticeId, deleteButton);
      actionGroup.appendChild(deleteForm);
      action.appendChild(actionGroup);
      row.append(detail, order, date, status, action);
      tbody.prepend(row);
    };
    const showNotificationToast = (notice) => {
      document.querySelector('.notification-toast')?.remove();
      const toast = document.createElement('a');
      toast.className = 'notification-toast';
      toast.href = 'notifications.php#notification-' + encodeURIComponent(notice.id);
      toast.setAttribute('role', 'status');
      const title = document.createElement('strong');
      title.textContent = notice.title || 'New notification';
      const message = document.createElement('span');
      message.textContent = notice.message || 'Open notifications to see the update.';
      toast.append(title, message);
      document.body.appendChild(toast);
      window.setTimeout(() => toast.classList.add('visible'), 10);
      window.setTimeout(() => {
        toast.classList.remove('visible');
        window.setTimeout(() => toast.remove(), 250);
      }, 7000);
    };
    const pollNotifications = async () => {
      if (polling || document.hidden) return;
      polling = true;
      try {
        const url = new URL(appRoot.dataset.notificationPollUrl, window.location.href);
        url.searchParams.set('after_id', String(latestNotificationId));
        const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin' });
        if (!response.ok) return;
        const payload = await response.json();
        if (!payload.success) return;
        updateUnreadCount(payload.unread_count);
        const incoming = Array.isArray(payload.notifications) ? payload.notifications : [];
        incoming.forEach(appendNotification);
        if (incoming.length) showNotificationToast(incoming[incoming.length - 1]);
        latestNotificationId = Math.max(latestNotificationId, Number(payload.latest_id || 0));
      } catch (_) {
        // Retry on the next interval if the network is temporarily unavailable.
      } finally {
        polling = false;
      }
    };
    window.setInterval(pollNotifications, 10000);
  }
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
