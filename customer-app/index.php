<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
$shopName = get_setting('shop_name', "Pia's Laundry Shop");
?>
<!doctype html>
<html lang="fil">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#d63384">
  <title>Customer App · <?= e($shopName) ?></title>
  <link rel="stylesheet" href="app.css">
</head>
<body>
<main class="app-shell">
  <header class="brand"><span class="brand-mark">🧺</span><div><strong><?= e($shopName) ?></strong><small>Laundry service app</small></div></header>

  <section id="authScreen" class="panel">
    <div class="tabs" role="tablist"><button type="button" class="tab active" data-auth-tab="login">Mag-login</button><button type="button" class="tab" data-auth-tab="register">Gumawa ng account</button></div>
    <div id="notice" class="notice" hidden></div>
    <form id="loginForm" class="auth-form">
      <h1>Welcome back</h1><p class="muted">Mag-login para mag-request at sumubaybay ng laundry service.</p>
      <label for="loginEmail">Email</label><input id="loginEmail" name="email" type="email" autocomplete="email" required>
      <label for="loginPassword">Password</label><input id="loginPassword" name="password" type="password" autocomplete="current-password" required>
      <button class="primary" type="submit">Mag-login</button>
    </form>
    <form id="registerForm" class="auth-form" hidden>
      <h1>Gumawa ng account</h1><p class="muted">Kailangan ng account para makapag-request ng serbisyo.</p>
      <label for="registerName">Buong pangalan</label><input id="registerName" name="name" maxlength="100" autocomplete="name" required>
      <label for="registerEmail">Email</label><input id="registerEmail" name="email" type="email" maxlength="120" autocomplete="email" required>
      <label for="registerPhone">Mobile number</label><input id="registerPhone" name="phone" type="tel" maxlength="30" autocomplete="tel" required>
      <label for="registerPassword">Password (10 character minimum)</label><input id="registerPassword" name="password" type="password" minlength="10" autocomplete="new-password" required>
      <button class="primary" type="submit">Gumawa ng account</button>
    </form>
  </section>

  <section id="serviceScreen" hidden>
    <div class="panel welcome"><div><span class="muted">Naka-login bilang</span><strong id="customerName"></strong></div><button id="logoutButton" class="link-button" type="button">Mag-logout</button></div>
    <div id="serviceNotice" class="notice" hidden></div>
    <section class="panel">
      <h1>Mag-request ng laundry service</h1><p class="muted">Pumili ng serbisyo at dami. Kukumpirmahin ng shop ang request at pickup schedule.</p>
      <div id="servicesList" class="service-list"><p class="muted">Kinukuha ang mga serbisyo…</p></div>
      <label for="pickupDate">Iminungkahing pickup date (optional)</label><input id="pickupDate" type="datetime-local">
      <label for="serviceNotes">Karagdagang detalye (optional)</label><textarea id="serviceNotes" maxlength="255" rows="3" placeholder="Hal. espesyal na tagubilin sa labada"></textarea>
      <div class="estimate"><span>Tantiyang halaga</span><strong id="estimatedTotal">₱0.00</strong></div>
      <button id="submitTicket" class="primary" type="button" disabled>Isumite ang service request</button>
      <p class="fine-print">Tantya lamang ang halaga. Hindi pa ito bayad; kokolektahin ng shop ang bayad sa branch.</p>
    </section>
    <section class="panel">
      <div class="section-heading"><h2>Aking mga laundry ticket</h2><button id="refreshTickets" class="link-button" type="button">I-refresh</button></div>
      <div id="ticketsList"><p class="muted">Kinukuha ang mga ticket…</p></div>
    </section>
  </section>
  <footer>Para sa account o service assistance, kontakin ang shop.</footer>
</main>
<script>
(() => {
  const API = '../api';
  const authScreen = document.getElementById('authScreen');
  const serviceScreen = document.getElementById('serviceScreen');
  const notice = document.getElementById('notice');
  const serviceNotice = document.getElementById('serviceNotice');
  const tokenKey = 'laundry_customer_token';
  let services = [];

  async function api(path, options = {}) {
    const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
    const token = sessionStorage.getItem(tokenKey);
    if (token) headers.Authorization = 'Bearer ' + token;
    const response = await fetch(API + '/' + path, { ...options, headers });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.success) throw new Error(data.error || 'May error. Subukan ulit.');
    return data;
  }

  function showNotice(element, message, error = false) {
    element.textContent = message;
    element.className = 'notice ' + (error ? 'error' : 'success');
    element.hidden = false;
  }

  document.querySelectorAll('[data-auth-tab]').forEach(button => button.addEventListener('click', () => {
    const registering = button.dataset.authTab === 'register';
    document.querySelectorAll('[data-auth-tab]').forEach(tab => tab.classList.toggle('active', tab === button));
    document.getElementById('loginForm').hidden = registering;
    document.getElementById('registerForm').hidden = !registering;
    notice.hidden = true;
  }));

  async function enterApp(customer) {
    authScreen.hidden = true;
    serviceScreen.hidden = false;
    document.getElementById('customerName').textContent = customer.name;
    await Promise.all([loadServices(), loadTickets()]);
  }

  document.getElementById('loginForm').addEventListener('submit', async event => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    try {
      const data = await api('login.php', { method: 'POST', body: JSON.stringify({ email: form.get('email'), password: form.get('password') }) });
      sessionStorage.setItem(tokenKey, data.token);
      await enterApp(data.customer);
    } catch (error) { showNotice(notice, error.message, true); }
  });

  document.getElementById('registerForm').addEventListener('submit', async event => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    try {
      const data = await api('register.php', { method: 'POST', body: JSON.stringify({ name: form.get('name'), email: form.get('email'), phone: form.get('phone'), password: form.get('password') }) });
      sessionStorage.setItem(tokenKey, data.token);
      await enterApp(data.customer);
    } catch (error) { showNotice(notice, error.message, true); }
  });

  async function loadServices() {
    const list = document.getElementById('servicesList');
    try {
      const data = await api('services.php');
      services = data.services;
      list.replaceChildren();
      if (!services.length) { list.innerHTML = '<p class="muted">Wala pang aktibong serbisyo.</p>'; return; }
      services.forEach(service => {
        const row = document.createElement('label');
        row.className = 'service-row';
        row.innerHTML = `<span class="service-info"><strong></strong><small></small></span><input type="number" min="0" step="${service.unit === 'piece' ? '1' : '0.25'}" value="0" inputmode="decimal" aria-label="Dami">`;
        row.querySelector('strong').textContent = service.name;
        row.querySelector('small').textContent = `${service.category} · ₱${Number(service.price).toFixed(2)} / ${service.unit}`;
        row.querySelector('input').dataset.serviceId = service.id;
        row.querySelector('input').addEventListener('input', updateEstimate);
        list.append(row);
      });
      updateEstimate();
    } catch (error) { list.textContent = error.message; }
  }

  function updateEstimate() {
    let total = 0;
    document.querySelectorAll('#servicesList input').forEach(input => {
      const service = services.find(item => Number(item.id) === Number(input.dataset.serviceId));
      total += Number(input.value || 0) * Number(service?.price || 0);
    });
    document.getElementById('estimatedTotal').textContent = '₱' + total.toFixed(2);
    document.getElementById('submitTicket').disabled = total <= 0;
  }

  async function loadTickets() {
    const list = document.getElementById('ticketsList');
    try {
      const data = await api('my_tickets.php');
      list.replaceChildren();
      if (!data.tickets.length) { list.innerHTML = '<p class="muted">Wala ka pang service ticket.</p>'; return; }
      data.tickets.forEach(ticket => {
        const card = document.createElement('article');
        card.className = 'ticket';
        const heading = document.createElement('div'); heading.className = 'section-heading';
        const number = document.createElement('strong'); number.textContent = ticket.order_number;
        const status = document.createElement('span'); status.className = 'status'; status.textContent = ({ pending: 'Natanggap', washing: 'Pinoproseso', ready: 'Handa na', picked_up: 'Naklaim na', cancelled: 'Kinansela' })[ticket.status] || ticket.status;
        heading.append(number, status);
        const date = document.createElement('p'); date.className = 'muted'; date.textContent = new Date(ticket.created_at.replace(' ', 'T')).toLocaleString();
        const lines = document.createElement('p'); lines.textContent = ticket.services.map(line => `${line.service_name} (${line.quantity} ${line.unit})`).join(', ');
        const total = document.createElement('strong'); total.textContent = 'Tantiyang halaga: ₱' + Number(ticket.total).toFixed(2);
        card.append(heading, date, lines, total);
        list.append(card);
      });
    } catch (error) { showNotice(serviceNotice, error.message, true); }
  }

  document.getElementById('submitTicket').addEventListener('click', async () => {
    const chosen = [...document.querySelectorAll('#servicesList input')].filter(input => Number(input.value) > 0).map(input => ({ service_id: Number(input.dataset.serviceId), quantity: Number(input.value) }));
    if (!chosen.length) return;
    const button = document.getElementById('submitTicket'); button.disabled = true;
    try {
      const pickupValue = document.getElementById('pickupDate').value;
      const data = await api('tickets.php', { method: 'POST', body: JSON.stringify({ services: chosen, expected_pickup: pickupValue ? new Date(pickupValue).toISOString() : null, notes: document.getElementById('serviceNotes').value }) });
      showNotice(serviceNotice, `${data.message} Ticket: ${data.ticket.ticket_number}`);
      document.querySelectorAll('#servicesList input').forEach(input => input.value = '0');
      document.getElementById('serviceNotes').value = '';
      updateEstimate();
      await loadTickets();
    } catch (error) { showNotice(serviceNotice, error.message, true); }
    finally { button.disabled = false; updateEstimate(); }
  });

  document.getElementById('refreshTickets').addEventListener('click', loadTickets);
  document.getElementById('logoutButton').addEventListener('click', async () => {
    try { await api('logout.php', { method: 'POST', body: '{}' }); } catch (_) {}
    sessionStorage.removeItem(tokenKey);
    location.reload();
  });

  const existingToken = sessionStorage.getItem(tokenKey);
  if (existingToken) api('me.php').then(data => enterApp(data.customer)).catch(() => sessionStorage.removeItem(tokenKey));
})();
</script>
</body>
</html>
