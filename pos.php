<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/feature_schema.php';
ensure_customer_auth_schema();

$pdo = db();

/* ============================================================
   AJAX: create an order (POST pos.php?action=create, JSON body)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'create') {
    $data = json_input();

    if (!csrf_verify($data['csrf'] ?? null)) {
        json_out(['success' => false, 'error' => 'Your session has expired. Refresh the page and try again.'], 400);
    }

    $items = $data['items'] ?? [];
    if (!is_array($items) || count($items) === 0) {
        json_out(['success' => false, 'error' => 'The cart is empty.'], 400);
    }

    /* Re-price every line from the database (never trust client prices). */
    $ids = [];
    foreach ($items as $it) {
        $ids[] = (int)($it['service_id'] ?? 0);
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM services WHERE id IN ($placeholders) AND is_active = 1");
    $stmt->execute($ids);
    $svcMap = [];
    foreach ($stmt->fetchAll() as $s) {
        $svcMap[$s['id']] = $s;
    }

    $subtotal = 0.0;
    $lines = [];
    foreach ($items as $it) {
        $sid = (int)($it['service_id'] ?? 0);
        $qty = (float)($it['quantity'] ?? 0);
        if (!isset($svcMap[$sid]) || $qty <= 0) continue;
        $s = $svcMap[$sid];
        $sub = round((float)$s['price'] * $qty, 2);
        $subtotal += $sub;
        $lines[] = [
            'service_id'   => $sid,
            'service_name' => $s['name'],
            'unit'         => $s['unit'],
            'quantity'     => $qty,
            'unit_price'   => (float)$s['price'],
            'subtotal'     => $sub,
        ];
    }
    if (count($lines) === 0) {
        json_out(['success' => false, 'error' => 'No valid items in the cart.'], 400);
    }
    $subtotal = round($subtotal, 2);

    $discount = max(0, (float)($data['discount'] ?? 0));
    if ($discount > $subtotal) $discount = $subtotal;
    $total = round($subtotal - $discount, 2);

    $allowedMethods = ['cash', 'gcash', 'maya', 'card'];
    $payment_method = in_array($data['payment_method'] ?? 'cash', $allowedMethods, true)
        ? $data['payment_method'] : 'cash';

    if ($payment_method === 'cash') {
        $tendered = (float)($data['amount_tendered'] ?? 0);
        if ($tendered < $total) {
            json_out(['success' => false, 'error' => 'Amount tendered is less than the total due.'], 400);
        }
        $amount_paid = $tendered;
        $change = round($tendered - $total, 2);
    } else {
        $amount_paid = $total;
        $change = 0.0;
    }
    $paid_at = date('Y-m-d H:i:s');

    /* Customer resolution: existing, new, or walk-in. */
    $customer_id   = (int)($data['customer_id'] ?? 0);
    $customer_name = '';
    if ($customer_id > 0) {
        $c = $pdo->prepare("SELECT name FROM customers WHERE id = ?");
        $c->execute([$customer_id]);
        $row = $c->fetch();
        $customer_name = $row ? $row['name'] : '';
    } else {
        $newName  = trim($data['new_customer_name'] ?? '');
        $newPhone = trim($data['new_customer_phone'] ?? '');
        if ($newName !== '') {
            $ins = $pdo->prepare("INSERT INTO customers (name, phone) VALUES (?, ?)");
            $ins->execute([$newName, $newPhone]);
            $customer_id   = (int)$pdo->lastInsertId();
            $customer_name = $newName;
        } else {
            $customer_name = trim($data['customer_name'] ?? '');
        }
    }

    $order_type = ($data['order_type'] ?? 'walk_in') === 'drop_off' ? 'drop_off' : 'walk_in';
    $expected_pickup = null;
    if ($order_type === 'drop_off' && !empty($data['expected_pickup'])) {
        $ts = strtotime(str_replace('T', ' ', (string)$data['expected_pickup']));
        if ($ts) $expected_pickup = date('Y-m-d H:i:s', $ts);
    }
    $notes = trim($data['notes'] ?? '');

    /* Generate order number: ORD-YYYYMMDD-NNN
     * Uses MAX(existing sequence for today) + 1 rather than COUNT(*), so the
     * numbering stays collision-free even when orders are deleted or when the
     * database was seeded with sample orders. */
    $prefix = 'ORD-' . date('Ymd') . '-';
    $seqStmt = $pdo->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING(order_number, ?) AS UNSIGNED)), 0)
           FROM orders
          WHERE order_number LIKE ?"
    );
    $seqStmt->execute([strlen($prefix) + 1, $prefix . '%']);
    $nextSeq   = ((int)$seqStmt->fetchColumn()) + 1;
    $orderNumber = $prefix . str_pad((string)$nextSeq, 3, '0', STR_PAD_LEFT);

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            "INSERT INTO orders
                (order_number, customer_id, customer_name, order_type, status,
                 subtotal, discount, total, amount_paid, change_due,
                 payment_method, paid_at, expected_pickup, notes, created_by)
             VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $ins->execute([
            $orderNumber, $customer_id ?: null, $customer_name, $order_type,
            $subtotal, $discount, $total, $amount_paid, $change,
            $payment_method, $paid_at, $expected_pickup, $notes, $_SESSION['user_id'],
        ]);
        $orderId = (int)$pdo->lastInsertId();

        $itStmt = $pdo->prepare(
            "INSERT INTO order_items (order_id, service_id, service_name, unit, quantity, unit_price, subtotal)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($lines as $l) {
            $itStmt->execute([
                $orderId, $l['service_id'], $l['service_name'], $l['unit'],
                $l['quantity'], $l['unit_price'], $l['subtotal'],
            ]);
        }
        $pdo->commit();
    } catch (Exception $ex) {
        $pdo->rollBack();
        json_out(['success' => false, 'error' => 'Could not save the order. Please try again.'], 500);
    }

    /* ---- Status timeline ---- */
    add_order_history($orderId, 'pending', 'Order created via POS', (int)$_SESSION['user_id']);

    json_out([
        'success'      => true,
        'order_id'     => $orderId,
        'order_number' => $orderNumber,
        'total'        => $total,
        'change'       => $change,
    ]);
}

/* ============================================================
   POS screen
   ============================================================ */
$services = $pdo->query("SELECT * FROM services WHERE is_active = 1 ORDER BY category, name")->fetchAll();
$customers = $pdo->query("SELECT id, name, phone FROM customers WHERE is_active = 1 ORDER BY name")->fetchAll();

/* Group services by category for display. */
$groups = [];
foreach ($services as $s) {
    $groups[$s['category']][] = $s;
}

$pageTitle = 'New Service Ticket';
$pageSubtitle = 'Record services and customer details';
include __DIR__ . '/includes/header.php';
?>

<div class="pos-grid">
  <!-- LEFT: service picker -->
  <div class="card">
    <h2 class="mb-0">1. Choose services</h2>
    <p class="muted mt-0 mb-16">Tap a service to add it. Prices are shown per kilogram or per piece.</p>
    <?php foreach ($groups as $cat => $svcList): ?>
    <div class="service-group">
      <h3><?= e($cat) ?></h3>
      <div class="service-list">
        <?php foreach ($svcList as $s): ?>
        <button class="service-btn" type="button"
                data-id="<?= (int)$s['id'] ?>"
                data-name="<?= e($s['name']) ?>"
                data-unit="<?= e($s['unit']) ?>"
                data-price="<?= e($s['price']) ?>">
          <span class="s-name"><?= e($s['name']) ?></span>
          <span class="s-price"><?= money($s['price']) ?> <span class="s-unit">/ <?= e($s['unit']) ?></span></span>
        </button>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- RIGHT: cart + checkout -->
  <div class="card">
    <h2 class="mb-16">Current service ticket</h2>

    <div id="cartList">
      <div class="cart-empty">No services added yet. Choose services to create a ticket.</div>
    </div>

    <div class="summary" id="summary" style="display:none;">
      <div class="summary-row"><span class="muted">Subtotal</span><span id="sumSubtotal">₱0.00</span></div>
      <div class="summary-row">
        <span class="muted">Discount</span>
        <span><input type="number" id="discount" min="0" step="0.01" value="0" style="width:110px;text-align:right;"></span>
      </div>
    <div class="summary-row total"><span>Total due</span><span id="sumTotal">₱0.00</span></div>
    </div>

    <hr style="border:none;border-top:1px solid var(--line);margin:16px 0;">

    <form id="checkoutForm" autocomplete="off">
      <?= csrf_field() ?>

      <h3 class="pos-step-title">2. Service details</h3>
      <label for="orderType">Service intake</label>
      <select id="orderType" name="order_type">
        <option value="walk_in">Walk-in</option>
        <option value="drop_off">Drop-off</option>
      </select>

      <div id="pickupWrap" style="display:none;">
        <label for="expectedPickup">Expected Pickup Date &amp; Time</label>
        <input type="datetime-local" id="expectedPickup" name="expected_pickup">
      </div>

      <label for="customerSelect">Customer</label>
      <select id="customerSelect" name="customer_id">
        <option value="">— Walk-in customer —</option>
        <?php foreach ($customers as $c): ?>
        <option value="<?= (int)$c['id'] ?>">
          <?= e($c['name']) ?><?= $c['phone'] ? ' (' . e($c['phone']) . ')' : '' ?>
        </option>
        <?php endforeach; ?>
        <option value="__new__">+ New customer…</option>
      </select>

      <div id="newCustomerWrap" style="display:none;">
        <label for="newCustomerName">New Customer Name</label>
        <input type="text" id="newCustomerName" placeholder="Full name">
        <label for="newCustomerPhone">Phone (optional)</label>
        <input type="text" id="newCustomerPhone" placeholder="09xx xxx xxxx">
      </div>

      <h3 class="pos-step-title">3. Payment</h3>
      <label for="paymentMethod">Payment method</label>
      <select id="paymentMethod" name="payment_method">
        <option value="cash">Cash</option>
        <option value="gcash">GCash</option>
        <option value="maya">Maya</option>
        <option value="card">Card</option>
      </select>

      <div id="tenderedWrap">
        <label for="amountTendered">Amount Tendered</label>
        <input type="number" id="amountTendered" min="0" step="0.01" value="0">
        <div class="summary-row mt-16">
          <span>Change</span>
          <span class="change-amount" id="changeDue">₱0.00</span>
        </div>
      </div>

      <label for="notes">Notes (optional)</label>
      <textarea id="notes" name="notes" rows="2" placeholder="Special instructions…"></textarea>

      <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-block" id="completeBtn" disabled>Create Service Ticket</button>
      </div>
      <button type="button" class="btn btn-ghost btn-block mt-16" id="clearCart">Clear cart</button>
    </form>
  </div>
</div>

<script>
(function () {
  const services = <?= json_encode(array_map(function ($s) {
      return ['id' => (int)$s['id'], 'name' => $s['name'], 'unit' => $s['unit'], 'price' => (float)$s['price']];
  }, $services), JSON_UNESCAPED_UNICODE) ?>;

  const cart = [];   // {id, name, unit, price, qty}

  const cartList      = document.getElementById('cartList');
  const summary       = document.getElementById('summary');
  const sumSubtotal   = document.getElementById('sumSubtotal');
  const sumTotal      = document.getElementById('sumTotal');
  const discountInput = document.getElementById('discount');
  const changeDue     = document.getElementById('changeDue');
  const amountTendered= document.getElementById('amountTendered');
  const completeBtn   = document.getElementById('completeBtn');

  function findItem(id) { return cart.find(c => c.id === id); }

  function addItem(svc) {
    const existing = findItem(svc.id);
    if (existing) { existing.qty += 1; }
    else { cart.push({ id: svc.id, name: svc.name, unit: svc.unit, price: svc.price, qty: 1 }); }
    render();
  }

  function setQty(id, qty) {
    const it = findItem(id);
    if (!it) return;
    it.qty = Math.max(0, Math.round(qty * 100) / 100);
    if (it.qty === 0) {
      const i = cart.indexOf(it);
      cart.splice(i, 1);
    }
    render();
  }

  function removeItem(id) {
    const i = cart.findIndex(c => c.id === id);
    if (i >= 0) cart.splice(i, 1);
    render();
  }

  function clearCart() { cart.length = 0; render(); }

  function subtotal() {
    return cart.reduce((sum, c) => sum + c.price * c.qty, 0);
  }

  function discount() {
    let d = parseFloat(discountInput.value) || 0;
    return Math.max(0, Math.min(d, subtotal()));
  }

  function total() { return subtotal() - discount(); }

  function render() {
    completeBtn.disabled = cart.length === 0;
    if (cart.length === 0) {
      cartList.innerHTML = '<div class="cart-empty">No services added yet. Choose services to create a ticket.</div>';
      summary.style.display = 'none';
      return;
    }
    summary.style.display = '';

    let html = '<div class="table-wrap"><table class="cart-table"><thead><tr>' +
      '<th>Service</th><th>Qty</th><th class="num">Amount</th><th></th></tr></thead><tbody>';
    cart.forEach(c => {
      html += '<tr>' +
        '<td>' + escapeHtml(c.name) + '<div class="muted" style="font-size:11px;">' + peso(c.price) + ' / ' + escapeHtml(c.unit) + '</div></td>' +
        '<td><div class="qty-ctrl">' +
          '<button type="button" onclick="POS.setQty(' + c.id + ',' + (c.qty - 1) + ')">−</button>' +
          '<input type="number" min="0" step="0.01" aria-label="Quantity of ' + escapeHtml(c.name) + ' in ' + escapeHtml(c.unit) + '" value="' + c.qty + '" onchange="POS.setQty(' + c.id + ', this.value)">' +
          '<button type="button" aria-label="Add one ' + escapeHtml(c.unit) + ' of ' + escapeHtml(c.name) + '" onclick="POS.setQty(' + c.id + ',' + (c.qty + 1) + ')">+</button>' +
        '</div></td>' +
        '<td class="num cart-line-total">' + peso(c.price * c.qty) + '</td>' +
        '<td><button type="button" class="btn btn-danger btn-sm" onclick="POS.removeItem(' + c.id + ')">✕</button></td>' +
        '</tr>';
    });
    html += '</tbody></table></div>';
    cartList.innerHTML = html;

    updateTotals();
  }

  function updateTotals() {
    sumSubtotal.textContent = peso(subtotal());
    sumTotal.textContent = peso(total());
    updateChange();
  }

  function updateChange() {
    const method = document.getElementById('paymentMethod').value;
    if (method === 'cash') {
      const tendered = parseFloat(amountTendered.value) || 0;
      const change = tendered - total();
      changeDue.textContent = peso(Math.max(0, change));
    } else {
      changeDue.textContent = '₱0.00';
    }
  }

  window.POS = { setQty, removeItem, clearCart };

  /* Wire up service buttons */
  document.querySelectorAll('.service-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      addItem({
        id: parseInt(btn.dataset.id),
        name: btn.dataset.name,
        unit: btn.dataset.unit,
        price: parseFloat(btn.dataset.price)
      });
    });
  });

  discountInput.addEventListener('input', updateTotals);
  amountTendered.addEventListener('input', updateChange);
  document.getElementById('paymentMethod').addEventListener('change', () => {
    const method = document.getElementById('paymentMethod').value;
    document.getElementById('tenderedWrap').style.display = (method === 'cash') ? '' : 'none';
    updateChange();
  });

  /* Order type -> pickup date */
  document.getElementById('orderType').addEventListener('change', function () {
    document.getElementById('pickupWrap').style.display = (this.value === 'drop_off') ? '' : 'none';
  });

  /* Customer select -> new customer fields */
  document.getElementById('customerSelect').addEventListener('change', function () {
    document.getElementById('newCustomerWrap').style.display = (this.value === '__new__') ? '' : 'none';
  });

  document.getElementById('clearCart').addEventListener('click', clearCart);

  /* Submit order */
  document.getElementById('checkoutForm').addEventListener('submit', async function (ev) {
    ev.preventDefault();
    if (cart.length === 0) { alert('The cart is empty.'); return; }
    if (total() < 0) { alert('Invalid discount.'); return; }

    const method = document.getElementById('paymentMethod').value;
    if (method === 'cash' && (parseFloat(amountTendered.value) || 0) < total()) {
      alert('Amount tendered is less than the total due.');
      return;
    }

    const payload = {
      csrf: this.csrf.value,
      items: cart.map(c => ({ service_id: c.id, quantity: c.qty })),
      discount: discount(),
      payment_method: method,
      amount_tendered: parseFloat(amountTendered.value) || 0,
      customer_id: document.getElementById('customerSelect').value === '__new__'
                    ? 0 : parseInt(document.getElementById('customerSelect').value) || 0,
      new_customer_name: document.getElementById('newCustomerName').value.trim(),
      new_customer_phone: document.getElementById('newCustomerPhone').value.trim(),
      order_type: document.getElementById('orderType').value,
      expected_pickup: document.getElementById('expectedPickup').value,
      notes: document.getElementById('notes').value.trim()
    };

    completeBtn.disabled = true;
      completeBtn.textContent = 'Saving…';

    try {
      const res = await fetch('pos.php?action=create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const result = await res.json();
      if (result.success) {
        window.open('receipt.php?id=' + result.order_id + '&new=1', '_blank');
        clearCart();
        document.getElementById('notes').value = '';
        document.getElementById('amountTendered').value = '0';
        document.getElementById('discount').value = '0';
        document.getElementById('newCustomerName').value = '';
        document.getElementById('newCustomerPhone').value = '';
        document.getElementById('customerSelect').value = '';
        updateTotals();
      } else {
        alert(result.error || 'Something went wrong.');
      }
    } catch (err) {
      alert('Network error. Please try again.');
    } finally {
      completeBtn.disabled = cart.length === 0;
      completeBtn.textContent = 'Create Service Ticket';
    }
  });

})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
