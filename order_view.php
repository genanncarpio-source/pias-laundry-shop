<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/feature_schema.php';
require_once __DIR__ . '/includes/service_pricing.php';
ensure_feature_schema();
ensure_workflow_schema();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

/* ---------- Handle status update ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['update_status']) || isset($_POST['cancel_order']) || isset($_POST['refund_order']) || isset($_POST['record_payment']) || isset($_POST['record_weight']) || isset($_POST['save_staff_notes']) || isset($_POST['assign_delivery_driver']) || isset($_POST['verify_gcash']) || isset($_POST['reject_gcash']))) {
    if (is_admin() && !isset($_POST['refund_order']) && !isset($_POST['verify_gcash']) && !isset($_POST['reject_gcash'])) {
        flash_set('error', 'Admin accounts can monitor orders. Staff accounts handle order processing and payments.');
        redirect("order_view.php?id=$id");
    }
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'This form has expired. Refresh the page and try again.');
    } else {
        try {
            $current = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
            $current->execute([$id]);
            $currentOrder = $current->fetch();
            if (!$currentOrder) throw new InvalidArgumentException('Order not found.');
            if ($currentOrder['payment_method'] === 'gcash_pending' && (isset($_POST['update_status']) || isset($_POST['record_payment']) || isset($_POST['record_weight']) || isset($_POST['assign_delivery_driver']))) {
                $paymentCheck = $pdo->prepare('SELECT status FROM gcash_payment_submissions WHERE order_id = ? ORDER BY id DESC LIMIT 1');
                $paymentCheck->execute([$id]);
                if ($paymentCheck->fetchColumn() !== 'verified') throw new InvalidArgumentException('Verify the customer GCash transfer before processing this order.');
            }
            $processStmt = $pdo->prepare('SELECT oi.service_name, s.category FROM order_items oi LEFT JOIN services s ON s.id = oi.service_id WHERE oi.order_id = ?');
            $processStmt->execute([$id]);
            $processItems = $processStmt->fetchAll();
            $isFullService = order_has_full_service($processItems);

            if (isset($_POST['verify_gcash'])) {
                $pdo->beginTransaction();
                $lockedOrderStmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
                $lockedOrderStmt->execute([$id]);
                $lockedOrder = $lockedOrderStmt->fetch();
                $submissionStmt = $pdo->prepare('SELECT * FROM gcash_payment_submissions WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
                $submissionStmt->execute([$id]);
                $submission = $submissionStmt->fetch();
                if (!$lockedOrder || !$submission || $lockedOrder['payment_method'] !== 'gcash_pending' || $submission['status'] !== 'pending' || (float)$lockedOrder['amount_paid'] > 0 || round((float)$submission['amount'], 2) !== round((float)$lockedOrder['total'], 2) || in_array($lockedOrder['status'], ['cancelled', 'rejected', 'completed', 'picked_up'], true)) {
                    throw new InvalidArgumentException('This GCash transfer is no longer eligible for verification. Refresh the order and check its payment status.');
                }
                $paid = round((float)$submission['amount'], 2);
                $pdo->prepare("INSERT INTO payments (order_id, amount, payment_method, reference_number, received_by) VALUES (?, ?, 'gcash', ?, ?)")->execute([$id, $paid, $submission['reference_number'], $_SESSION['user_id']]);
                $pdo->prepare("UPDATE orders SET amount_paid = ?, change_due = 0, payment_method = 'gcash' WHERE id = ?")->execute([$paid, $id]);
                $pdo->prepare("UPDATE gcash_payment_submissions SET status = 'verified', reviewed_at = NOW(), reviewed_by = ?, review_note = NULL WHERE id = ?")->execute([$_SESSION['user_id'], $submission['id']]);
                add_order_history($id, (string)$lockedOrder['status'], 'GCash transfer verified by shop', (int)$_SESSION['user_id']);
                if ($lockedOrder['customer_id']) add_notification('customer', (int)$lockedOrder['customer_id'], $id, 'GCash payment verified', 'The shop verified your GCash payment for ' . $lockedOrder['order_number'] . '. Your order can now be processed.');
                $pdo->commit();
                add_audit_log('GCash payment verified', 'payments', $id, 'pending', ['amount' => $paid, 'reference' => $submission['reference_number']]);
                flash_set('success', 'GCash payment verified. Staff can now process this order.');
            } elseif (isset($_POST['reject_gcash'])) {
                $reason = trim((string)($_POST['gcash_review_note'] ?? ''));
                if (strlen($reason) > 255) throw new InvalidArgumentException('The review note must be 255 characters or fewer.');
                if ($reason === '') $reason = 'Transfer could not be verified. Please check the amount and reference, then submit a new reference.';
                $pdo->beginTransaction();
                $lockedOrderStmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
                $lockedOrderStmt->execute([$id]);
                $lockedOrder = $lockedOrderStmt->fetch();
                $submissionStmt = $pdo->prepare('SELECT * FROM gcash_payment_submissions WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
                $submissionStmt->execute([$id]);
                $submission = $submissionStmt->fetch();
                if (!$lockedOrder || !$submission || $lockedOrder['payment_method'] !== 'gcash_pending' || $submission['status'] !== 'pending' || (float)$lockedOrder['amount_paid'] > 0) {
                    throw new InvalidArgumentException('This GCash submission has already been reviewed or cannot be rejected.');
                }
                $pdo->prepare("UPDATE gcash_payment_submissions SET status = 'rejected', reviewed_at = NOW(), reviewed_by = ?, review_note = ? WHERE id = ?")->execute([$_SESSION['user_id'], $reason, $submission['id']]);
                add_order_history($id, (string)$lockedOrder['status'], 'GCash transfer rejected: ' . $reason, (int)$_SESSION['user_id']);
                if ($lockedOrder['customer_id']) add_notification('customer', (int)$lockedOrder['customer_id'], $id, 'GCash payment needs attention', $reason);
                $pdo->commit();
                add_audit_log('GCash payment rejected', 'payments', $id, 'pending', $reason);
                flash_set('success', 'GCash payment submission rejected. The customer can submit a corrected reference.');
            } elseif (isset($_POST['assign_delivery_driver'])) {
                if ($currentOrder['order_type'] !== 'delivery_request') throw new InvalidArgumentException('Only delivery orders can be assigned to a driver.');
                if (in_array($currentOrder['status'], ['out_for_delivery', 'completed', 'picked_up', 'cancelled', 'rejected'], true)) {
                    throw new InvalidArgumentException('A driver can only be assigned or changed before the delivery starts.');
                }
                $driverId = filter_var($_POST['driver_id'] ?? null, FILTER_VALIDATE_INT);
                if ($driverId === false || $driverId < 0) throw new InvalidArgumentException('Choose a valid driver.');
                if ($driverId > 0) {
                    $driverCheck = $pdo->prepare('SELECT id FROM delivery_drivers WHERE id = ? AND is_active = 1 LIMIT 1');
                    $driverCheck->execute([(int)$driverId]);
                    if (!$driverCheck->fetchColumn()) throw new InvalidArgumentException('That driver account is inactive or unavailable.');
                    $pdo->prepare('UPDATE orders SET assigned_driver_id = ? WHERE id = ?')->execute([(int)$driverId, $id]);
                } else {
                    $pdo->prepare('UPDATE orders SET assigned_driver_id = NULL WHERE id = ?')->execute([$id]);
                }
                add_order_history($id, (string)$currentOrder['status'], $driverId > 0 ? 'Delivery driver assigned' : 'Delivery driver assignment removed', (int)$_SESSION['user_id']);
                add_audit_log('Delivery driver assignment changed', 'orders', $id, $currentOrder['assigned_driver_id'] ?? null, $driverId > 0 ? (int)$driverId : null);
                flash_set('success', $driverId > 0 ? 'Delivery driver assigned.' : 'Delivery driver unassigned.');
            } elseif (isset($_POST['save_staff_notes'])) {
                $staffNotes = trim((string)($_POST['staff_notes'] ?? ''));
                if (mb_strlen($staffNotes) > 1000) throw new InvalidArgumentException('Processing notes must be 1,000 characters or fewer.');
                $pdo->prepare('UPDATE orders SET staff_notes = ? WHERE id = ?')->execute([$staffNotes !== '' ? $staffNotes : null, $id]);
                add_order_history($id, (string)$currentOrder['status'], 'Processing note updated', (int)$_SESSION['user_id']);
                add_audit_log('Processing notes updated', 'orders', $id, $currentOrder['staff_notes'], $staffNotes);
                flash_set('success', 'Processing notes saved.');
            } elseif (isset($_POST['record_payment'])) {
                if (in_array($currentOrder['status'], ['cancelled', 'rejected'], true)) throw new InvalidArgumentException('Payments cannot be recorded for a cancelled or rejected order.');
                $amount = round((float)($_POST['payment_amount'] ?? 0), 2);
                $method = $_POST['payment_method'] ?? '';
                $reference = trim((string)($_POST['reference_number'] ?? ''));
                $validMethods = ['cash', 'gcash'];
                $alreadyPaid = max(0, (float)$currentOrder['amount_paid'] - (float)$currentOrder['change_due']);
                $balance = max(0, (float)$currentOrder['total'] - $alreadyPaid);
                if (!in_array($method, $validMethods, true) || $amount <= 0 || $amount > $balance || strlen($reference) > 120) throw new InvalidArgumentException('Enter a valid payment up to the remaining balance.');
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO payments (order_id, amount, payment_method, reference_number, received_by) VALUES (?, ?, ?, ?, ?)")->execute([$id, $amount, $method, $reference !== '' ? $reference : null, $_SESSION['user_id']]);
                $newPaid = round($alreadyPaid + $amount, 2);
                $pdo->prepare('UPDATE orders SET amount_paid = ?, change_due = 0, payment_method = ? WHERE id = ?')->execute([$newPaid, $method, $id]);
                $pdo->commit();
                if ($currentOrder['customer_id']) add_notification('customer', (int)$currentOrder['customer_id'], $id, 'Payment recorded', money($amount) . ' payment received for ' . $currentOrder['order_number'] . '.');
                add_audit_log('Payment recorded', 'payments', $id, $alreadyPaid, $newPaid);
                flash_set('success', 'Payment recorded. Remaining balance: ' . money(max(0, (float)$currentOrder['total'] - $newPaid)));
            } elseif (isset($_POST['record_weight'])) {
                if (in_array($currentOrder['status'], ['cancelled', 'rejected', 'completed', 'picked_up'], true)) throw new InvalidArgumentException('Actual weight cannot be changed for this order.');
                $weight = round((float)($_POST['actual_weight'] ?? 0), 2);
                if ($weight <= 0 || $weight > 1000) throw new InvalidArgumentException('Enter an actual weight between 0.01 and 1,000 kg.');
                $itemStmt = $pdo->prepare('SELECT i.*, s.category FROM order_items i LEFT JOIN services s ON s.id = i.service_id WHERE i.order_id = ? ORDER BY i.id');
                $itemStmt->execute([$id]);
                $orderLines = $itemStmt->fetchAll();
                $hasKg = false;
                $estimatedKgTotal = 0.0;
                foreach ($orderLines as $line) {
                    $estimate = $line['estimated_weight_kg'] !== null
                        ? (float)$line['estimated_weight_kg']
                        : ($line['unit'] === 'kg' ? (float)$line['quantity'] : 0.0);
                    if ($estimate > 0) {
                        $hasKg = true;
                        $estimatedKgTotal += $estimate;
                    }
                }
                $newSubtotal = 0.0;
                $updates = [];
                foreach ($orderLines as $line) {
                    if ($line['estimated_weight_kg'] !== null) {
                        $estimatedQty = (float)$line['estimated_weight_kg'];
                        $actualQty = $estimatedKgTotal > 0 ? $weight * $estimatedQty / $estimatedKgTotal : $weight;
                        $unitPrice = (float)$line['unit_price'];
                        $category = (string)($line['category'] ?? 'Wash');
                        $lineTotal = service_weight_load_price($actualQty, $unitPrice, $category);
                        $loadCount = service_weight_load_count($actualQty, $category);
                        $updates[] = [$loadCount, $unitPrice, $lineTotal, $line['id']];
                        $newSubtotal += $lineTotal;
                    } elseif ($line['unit'] === 'kg') {
                        $estimatedQty = (float)$line['quantity'];
                        $actualQty = $estimatedKgTotal > 0 ? $weight * $estimatedQty / $estimatedKgTotal : $weight;
                        $perKg = (float)$line['subtotal'] / max(7.0, $estimatedQty);
                        $lineTotal = round($perKg * max(7.0, $actualQty), 2);
                        $updates[] = [$actualQty, $perKg, $lineTotal, $line['id']];
                        $newSubtotal += $lineTotal;
                    } else {
                        $newSubtotal += (float)$line['subtotal'];
                    }
                }
                $newSubtotal = round($newSubtotal, 2);
                $newTotal = round(max(0, $newSubtotal - (float)$currentOrder['discount']) + (float)$currentOrder['delivery_fee'], 2);
                $alreadyPaid = max(0, (float)$currentOrder['amount_paid'] - (float)$currentOrder['change_due']);
                if (!$hasKg) throw new InvalidArgumentException('This order has no weight-based service.');
                if ($alreadyPaid > $newTotal) throw new InvalidArgumentException('The new total is below the amount already paid. The recorded weight was not changed.');
                $pdo->beginTransaction();
                foreach ($updates as [$qty, $unitPrice, $lineTotal, $lineId]) $pdo->prepare('UPDATE order_items SET quantity = ?, unit_price = ?, subtotal = ? WHERE id = ?')->execute([$qty, $unitPrice, $lineTotal, $lineId]);
                $pdo->prepare('UPDATE orders SET actual_weight = ?, subtotal = ?, total = ? WHERE id = ?')->execute([$weight, $newSubtotal, $newTotal, $id]);
                add_order_history($id, (string)$currentOrder['status'], 'Actual weight recorded: ' . number_format($weight, 2) . ' kg. Total recalculated to ' . money($newTotal), (int)$_SESSION['user_id']);
                $pdo->commit();
                if ($currentOrder['customer_id']) add_notification('customer', (int)$currentOrder['customer_id'], $id, 'Order weighed', 'Actual weight for ' . $currentOrder['order_number'] . ' is ' . number_format($weight, 2) . ' kg. Updated total: ' . money($newTotal) . '.');
                add_audit_log('Actual weight recorded', 'orders', $id, null, ['weight' => $weight, 'total' => $newTotal]);
                flash_set('success', 'Actual weight saved and order total recalculated.');
            } elseif (isset($_POST['refund_order'])) {
                if (!is_admin()) throw new InvalidArgumentException('Only an administrator can record a refund.');
                $amount = round((float)($_POST['refund_amount'] ?? 0), 2);
                $reason = trim($_POST['refund_reason'] ?? '');
                $refundMethod = $_POST['refund_method'] ?? $currentOrder['payment_method'];
                if (!in_array($refundMethod, ['cash', 'gcash'], true)) throw new InvalidArgumentException('Choose a valid refund method.');
                $sum = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM order_adjustments WHERE order_id = ? AND adjustment_type = 'refund'");
                $sum->execute([$id]);
                $ledger = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END),0) FROM payments WHERE order_id = ?");
                $ledger->execute([$id]);
                [$paymentCount, $ledgerPaid] = $ledger->fetch(PDO::FETCH_NUM);
                $paidAmount = (int)$paymentCount > 0 ? (float)$ledgerPaid : max(0, (float)$currentOrder['amount_paid'] - (float)$currentOrder['change_due']);
                $remaining = max(0, $paidAmount - (float)$sum->fetchColumn());
                if ($currentOrder['status'] === 'cancelled' || $amount <= 0 || $amount > $remaining || $reason === '') throw new InvalidArgumentException('Enter a refund amount up to the remaining refundable balance and a reason.');
                $pdo->prepare("INSERT INTO order_adjustments (order_id, adjustment_type, amount, payment_method, reason, created_by) VALUES (?, 'refund', ?, ?, ?, ?)")->execute([$id, $amount, $refundMethod, $reason, $_SESSION['user_id']]);
                flash_set('success', 'Refund recorded.');
            } elseif (isset($_POST['cancel_order'])) {
                $reason = trim($_POST['cancel_reason'] ?? '');
                $refund = round((float)($_POST['cancel_refund'] ?? 0), 2);
                $cancelRefundMethod = $_POST['cancel_refund_method'] ?? $currentOrder['payment_method'];
                if (in_array($currentOrder['status'], ['cancelled', 'picked_up', 'completed', 'rejected'], true) || $reason === '') throw new InvalidArgumentException('Enter a reason. Completed, rejected or already cancelled orders cannot be cancelled.');
                if ($refund > 0 && !is_admin()) throw new InvalidArgumentException('An administrator must record a refund.');
                if (!in_array($cancelRefundMethod, ['cash', 'gcash', 'maya', 'card'], true)) throw new InvalidArgumentException('Choose a valid refund method.');
                $sum = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM order_adjustments WHERE order_id = ? AND adjustment_type = 'refund'");
                $sum->execute([$id]);
                $ledger = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END),0) FROM payments WHERE order_id = ?");
                $ledger->execute([$id]);
                [$paymentCount, $ledgerPaid] = $ledger->fetch(PDO::FETCH_NUM);
                $paidAmount = (int)$paymentCount > 0 ? (float)$ledgerPaid : max(0, (float)$currentOrder['amount_paid'] - (float)$currentOrder['change_due']);
                if ($refund < 0 || $refund > max(0, $paidAmount - (float)$sum->fetchColumn())) throw new InvalidArgumentException('Refund cannot exceed the amount already paid.');
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO order_adjustments (order_id, adjustment_type, amount, reason, created_by) VALUES (?, 'cancellation', 0, ?, ?)")->execute([$id, $reason, $_SESSION['user_id']]);
                if ($refund > 0) $pdo->prepare("INSERT INTO order_adjustments (order_id, adjustment_type, amount, payment_method, reason, created_by) VALUES (?, 'refund', ?, ?, ?, ?)")->execute([$id, $refund, $cancelRefundMethod, 'Refund on cancellation: ' . $reason, $_SESSION['user_id']]);
                $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")->execute([$id]);
                $pdo->prepare('UPDATE delivery_tracking SET is_active = 0, latitude = NULL, longitude = NULL, accuracy = NULL, heading = NULL, speed = NULL, customer_location_shared = 0, customer_latitude = NULL, customer_longitude = NULL, customer_accuracy = NULL, customer_location_updated_at = NULL, customer_shared_at = NULL, stopped_at = NOW() WHERE order_id = ?')->execute([$id]);
                add_order_history($id, 'cancelled', 'Cancelled: ' . $reason, (int)$_SESSION['user_id']);
                $pdo->commit();
                if ($currentOrder['customer_id']) add_notification('customer', (int)$currentOrder['customer_id'], $id, 'Order cancelled', $currentOrder['order_number'] . ' was cancelled.');
                add_audit_log('Order cancelled', 'orders', $id, $currentOrder['status'], 'cancelled');
                flash_set('success', 'Order cancelled and reason recorded.');
            } else {
                $status = $_POST['status'] ?? '';
                if (!in_array($status, order_next_statuses($currentOrder['status'], $processItems), true)) throw new InvalidArgumentException('That status change is not allowed from the current stage.');
                $isDeliveryOrder = $currentOrder['order_type'] === 'delivery_request';
                if ($status === 'out_for_delivery' && !$isDeliveryOrder) throw new InvalidArgumentException('Only delivery requests can be sent out for delivery.');
                if ($isDeliveryOrder && $currentOrder['status'] === 'ready_for_pickup' && $status !== 'out_for_delivery') throw new InvalidArgumentException('Delivery requests must be sent out for delivery before completion.');
                if ($isDeliveryOrder && $status === 'completed' && $currentOrder['status'] !== 'out_for_delivery') throw new InvalidArgumentException('Mark this delivery as Out for Delivery before completing it.');
                if ($status === 'out_for_delivery' && trim((string)($currentOrder['delivery_address'] ?? '')) === '') throw new InvalidArgumentException('Add a delivery address before sending this order out.');
                if ($status === 'completed') {
                    $kgCheck = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = ? AND (unit = 'kg' OR estimated_weight_kg IS NOT NULL)");
                    $kgCheck->execute([$id]);
                    if ((int)$kgCheck->fetchColumn() > 0 && $currentOrder['actual_weight'] === null) throw new InvalidArgumentException('Record the actual weight before completing this order.');
                    $alreadyPaid = max(0, (float)$currentOrder['amount_paid'] - (float)$currentOrder['change_due']);
                    if ($alreadyPaid < (float)$currentOrder['total']) throw new InvalidArgumentException('Record the remaining payment before completing this order.');
                }
                $pdo->beginTransaction();
                if ($isFullService && $currentOrder['status'] === 'drying' && $status === 'ready_for_pickup') {
                    add_order_history($id, 'folding', 'Full Service fold completed automatically', (int)$_SESSION['user_id']);
                }
                $assignedSql = $status === 'confirmed' ? ', assigned_staff_id = COALESCE(assigned_staff_id, ?)' : '';
                $statusStmt = $pdo->prepare('UPDATE orders SET status = ?' . $assignedSql . ($status === 'completed' ? ', completed_at = NOW()' : '') . ' WHERE id = ?');
                $status === 'confirmed' ? $statusStmt->execute([$status, $_SESSION['user_id'], $id]) : $statusStmt->execute([$status, $id]);
                if ($status === 'out_for_delivery') {
                    $assignedDriver = $pdo->prepare('SELECT id FROM delivery_drivers WHERE id = ? AND is_active = 1 LIMIT 1');
                    $assignedDriver->execute([(int)($currentOrder['assigned_driver_id'] ?? 0)]);
                    if (!$assignedDriver->fetchColumn()) throw new InvalidArgumentException('Assign an active delivery driver before sending this order out.');
                    $pdo->prepare(
                        'INSERT INTO delivery_tracking (order_id, driver_code_hash, is_active, started_at)
                         VALUES (?, NULL, 1, NOW())
                         ON DUPLICATE KEY UPDATE driver_code_hash = NULL, is_active = 1,
                             latitude = NULL, longitude = NULL, accuracy = NULL, heading = NULL, speed = NULL,
                             customer_location_shared = 0, customer_latitude = NULL, customer_longitude = NULL,
                             customer_accuracy = NULL, customer_location_updated_at = NULL, customer_shared_at = NULL,
                             started_at = NOW(), updated_at = NULL, stopped_at = NULL'
                    )->execute([$id]);
                } elseif ($status === 'completed') {
                    $pdo->prepare('UPDATE delivery_tracking SET is_active = 0, latitude = NULL, longitude = NULL, accuracy = NULL, heading = NULL, speed = NULL, customer_location_shared = 0, customer_latitude = NULL, customer_longitude = NULL, customer_accuracy = NULL, customer_location_updated_at = NULL, customer_shared_at = NULL, stopped_at = NOW() WHERE order_id = ?')->execute([$id]);
                }
                $label = order_status_labels()[$status] ?? ucwords(str_replace('_', ' ', $status));
                add_order_history($id, $status, 'Status changed to ' . $label, (int)$_SESSION['user_id']);
                if ($currentOrder['customer_id']) {
                    $message = $isFullService && $status === 'ready_for_pickup'
                        ? $currentOrder['order_number'] . ' completed Full Service (Wash, Dry, Fold) and is Ready for Pick-up.'
                        : $currentOrder['order_number'] . ' is now ' . $label . '.';
                    add_notification('customer', (int)$currentOrder['customer_id'], $id, 'Laundry status updated', $message);
                }
                add_audit_log('Order status changed', 'orders', $id, $currentOrder['status'], $status);
                $pdo->commit();

                flash_set('success', 'Order status updated to ' . $label . '.');
            }
        } catch (InvalidArgumentException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash_set('error', $e->getMessage());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash_set('error', 'Unable to save this order update. Refresh the page and try again.');
        }
    }
    redirect("order_view.php?id=$id");
}

$stmt = $pdo->prepare("SELECT o.*, u.full_name AS created_by_name, assigned.full_name AS assigned_staff_name, driver.full_name AS assigned_driver_name, driver.username AS assigned_driver_username, c.phone AS customer_phone
                       FROM orders o LEFT JOIN users u ON u.id = o.created_by
                       LEFT JOIN users assigned ON assigned.id = o.assigned_staff_id
                       LEFT JOIN delivery_drivers driver ON driver.id = o.assigned_driver_id
                       LEFT JOIN customers c ON c.id = o.customer_id
                       WHERE o.id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    flash_set('error', 'Order not found.');
    redirect('orders.php');
}

$availableDrivers = $pdo->query('SELECT id, full_name, username FROM delivery_drivers WHERE is_active = 1 ORDER BY full_name')->fetchAll();
$items = $pdo->prepare("SELECT oi.*, s.category FROM order_items oi LEFT JOIN services s ON s.id = oi.service_id WHERE oi.order_id = ?");
$items->execute([$id]);
$lines = $items->fetchAll();
$isFullService = order_has_full_service($lines);
$hasWeightService = false;
foreach ($lines as $line) if ($line['unit'] === 'kg' || $line['estimated_weight_kg'] !== null) $hasWeightService = true;

$statusFlow   = order_next_statuses($order['status'], $lines);
if ($order['order_type'] === 'delivery_request') {
    if ($order['status'] === 'confirmed' && ($order['handoff_type'] ?? '') === 'pickup_delivery') $statusFlow = [];
    if ($order['status'] === 'confirmed' && ($order['handoff_type'] ?? '') === 'dropoff_delivery') $statusFlow = ['received'];
    if ($order['status'] === 'picked_up_for_service') $statusFlow = ['received'];
    if ($order['status'] === 'ready_for_pickup') $statusFlow = ['out_for_delivery'];
} else {
    $statusFlow = array_values(array_diff($statusFlow, ['out_for_delivery']));
}
$statusLabels = order_status_labels();
$statusActionLabels = [
    'confirmed' => 'Accept Request',
    'picked_up_for_service' => 'Driver Picked Up',
    'received' => 'Mark Received at Shop',
    'washing' => 'Washing',
    'drying' => 'Drying',
    'ready_for_pickup' => 'Ready for Pickup',
    'rejected' => 'Reject Request',
    'out_for_delivery' => 'Send for Delivery',
    'completed' => 'Complete Order',
];
if ($order['order_type'] === 'delivery_request') {
    $statusLabels['ready_for_pickup'] = 'Ready for Delivery';
    $statusActionLabels['ready_for_pickup'] = 'Ready for Delivery';
}
$gcashStmt = $pdo->prepare('SELECT g.*, u.full_name AS reviewer_name FROM gcash_payment_submissions g LEFT JOIN users u ON u.id = g.reviewed_by WHERE g.order_id = ? ORDER BY g.id DESC LIMIT 1');
$gcashStmt->execute([$id]);
$gcashSubmission = $gcashStmt->fetch();
$gcashUnverified = $order['payment_method'] === 'gcash_pending' && (!$gcashSubmission || $gcashSubmission['status'] !== 'verified');
$paymentStmt = $pdo->prepare('SELECT p.*, u.full_name AS received_by_name FROM payments p LEFT JOIN users u ON u.id = p.received_by WHERE p.order_id = ? ORDER BY p.paid_at, p.id');
$paymentStmt->execute([$id]);
$payments = $paymentStmt->fetchAll();
$paidTotal = 0.0;
foreach ($payments as $payment) if ($payment['status'] === 'paid') $paidTotal += (float)$payment['amount'];
if (!$payments) $paidTotal = max(0, (float)$order['amount_paid'] - (float)$order['change_due']);
$remainingBalance = max(0, (float)$order['total'] - $paidTotal);

/* ---------- Status timeline for this order ---------- */
$history = order_history($id);
$adjustmentStmt = $pdo->prepare('SELECT a.*, u.full_name FROM order_adjustments a LEFT JOIN users u ON u.id = a.created_by WHERE a.order_id = ? ORDER BY a.id DESC');
$adjustmentStmt->execute([$id]);
$adjustments = $adjustmentStmt->fetchAll();
$refundTotal = 0.0;
foreach ($adjustments as $adjustment) if ($adjustment['adjustment_type'] === 'refund') $refundTotal += (float)$adjustment['amount'];
$refundableBalance = max(0, $paidTotal - $refundTotal);
$smsMessage = 'Hi ' . ($order['customer_name'] ?: 'there') . ', your laundry order ' . $order['order_number'] . ($order['order_type'] === 'delivery_request' ? ' is ready for delivery from ' : ' is ready for pickup at ') . get_setting('shop_name', "Pia's Laundry Shop") . '.';
$smsPhone = preg_replace('/[^0-9+]/', '', (string)($order['customer_phone'] ?? ''));

$pageTitle = 'Order ' . $order['order_number'];
$pageSubtitle = 'Order details';
include __DIR__ . '/includes/header.php';
?>

<div class="row-between mb-16">
  <a class="btn btn-outline btn-sm" href="orders.php">← All orders</a>
  <div>
    <a class="btn btn-outline btn-sm" href="receipt.php?id=<?= (int)$id ?>" target="_blank">🖨️ Receipt</a>
  </div>
</div>

<div class="grid two">
  <div class="card">
    <h2>Summary</h2>
    <table>
      <tbody>
        <tr><td class="muted">Order #</td><td><strong><?= e($order['order_number']) ?></strong></td></tr>
        <tr><td class="muted">Date</td><td><?= e(date('M j, Y g:i A', strtotime($order['created_at']))) ?></td></tr>
        <tr><td class="muted">Type</td><td><span class="badge badge-<?= e($order['order_type']) ?>"><?= $order['order_type'] === 'delivery_request' ? (($order['handoff_type'] ?? '') === 'pickup_delivery' ? 'Pickup & Delivery' : 'Drop-off & Delivery') : ($order['order_type'] === 'drop_off' ? 'Drop-off' : 'Walk-in') ?></span></td></tr>
        <tr><td class="muted">Service Status</td><td><span class="badge badge-<?= e($order['status']) ?>"><?= e($statusLabels[$order['status']] ?? $order['status']) ?></span></td></tr>
        <tr><td class="muted">Customer</td><td><?= e($order['customer_name'] ?: 'Walk-in') ?></td></tr>
        <?php if ($isFullService): ?><tr><td class="muted">Process</td><td>Full Service (Wash, Dry, Fold)</td></tr><?php endif; ?>
        <?php $serviceAddress = $order['order_type'] === 'delivery_request' ? ($order['delivery_address'] ?? '') : ($order['pickup_address'] ?? ''); ?>
        <?php if ($serviceAddress !== ''): ?>
          <tr><td class="muted"><?= $order['order_type'] === 'delivery_request' ? 'Delivery address' : 'Pick-up address' ?></td><td><?= e($serviceAddress) ?><br><a href="https://www.google.com/maps/search/?api=1&amp;query=<?= rawurlencode($serviceAddress) ?>" target="_blank" rel="noopener">Open location in Maps</a></td></tr>
        <?php endif; ?>
        <?php if ($order['expected_pickup']): ?>
          <tr><td class="muted"><?= ($order['handoff_type'] ?? '') === 'pickup_delivery' ? 'Preferred pickup' : 'Preferred drop-off' ?></td><td><?= e(date('M j, Y g:i A', strtotime($order['expected_pickup']))) ?></td></tr>
        <?php endif; ?>
        <tr><td class="muted">Payment Method</td><td><?= $order['payment_method'] === 'gcash_pending' ? 'GCash (awaiting verification)' : ($order['payment_method'] === 'cash_on_handoff' ? (($order['handoff_type'] ?? '') === 'pickup_delivery' ? 'Cash to driver upon pickup' : 'Cash at shop upon drop-off') : e(strtoupper($order['payment_method']))) ?></td></tr>
        <?php if ($order['actual_weight'] !== null): ?><tr><td class="muted">Actual Weight</td><td><?= e($order['actual_weight']) ?> kg</td></tr><?php endif; ?>
        <?php if ($order['assigned_staff_name']): ?><tr><td class="muted">Assigned Staff</td><td><?= e($order['assigned_staff_name']) ?></td></tr><?php endif; ?>
        <?php if ($order['assigned_driver_name']): ?><tr><td class="muted">Delivery Driver</td><td><?= e($order['assigned_driver_name']) ?></td></tr><?php endif; ?>
        <tr><td class="muted">Created by</td><td><?= e($order['created_by_name'] ?: '—') ?></td></tr>
        <?php if ($order['notes']): ?>
          <tr><td class="muted">Notes</td><td><?= e($order['notes']) ?></td></tr>
        <?php endif; ?>
        <?php if ($order['staff_notes']): ?><tr><td class="muted">Processing Notes</td><td><?= nl2br(e($order['staff_notes'])) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h2>Items</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Service</th><th>Qty</th><th class="num">Price</th><th class="num">Amount</th></tr></thead>
        <tbody>
          <?php foreach ($lines as $l): ?>
          <tr>
            <td><?= e($l['service_name']) ?></td>
            <td><?= e(rtrim(rtrim(number_format($l['quantity'], 2), '0'), '.')) ?> <?= e($l['unit']) ?><?php if ($l['estimated_weight_kg'] !== null): ?> (<?= e(rtrim(rtrim(number_format($l['estimated_weight_kg'], 2), '0'), '.')) ?> kg estimated)<?php endif; ?></td>
            <td class="num"><?= money($l['unit_price']) ?><?= $l['unit'] === 'kg' ? ' / kg' : ($l['unit'] === 'load' ? ' / load' : ' / piece') ?></td>
            <td class="num"><?= money($l['subtotal']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="3" class="right"><strong>Subtotal</strong></td>
            <td class="num"><?= money($order['subtotal']) ?></td>
          </tr>
          <?php if ($order['discount'] > 0): ?>
          <tr>
            <td colspan="3" class="right muted">Discount</td>
            <td class="num">-<?= money($order['discount']) ?></td>
          </tr>
          <?php endif; ?>
          <?php if ((float)($order['delivery_fee'] ?? 0) > 0): ?>
          <tr>
            <td colspan="3" class="right muted">Delivery fee</td>
            <td class="num"><?= money($order['delivery_fee']) ?></td>
          </tr>
          <?php endif; ?>
          <tr>
            <td colspan="3" class="right"><strong>Total</strong></td>
            <td class="num"><strong><?= money($order['total']) ?></strong></td>
          </tr>
          <?php if ($order['payment_method'] === 'cash'): ?>
          <tr>
            <td colspan="3" class="right muted">Tendered</td>
            <td class="num"><?= money($order['amount_paid']) ?></td>
          </tr>
          <tr>
            <td colspan="3" class="right muted">Change</td>
            <td class="num"><?= money($order['change_due']) ?></td>
          </tr>
          <?php endif; ?>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<?php if (!is_admin() && !in_array($order['status'], ['completed', 'picked_up', 'cancelled', 'rejected'], true)): ?>
<div class="card mt-16">
  <h2>Processing notes</h2>
  <form method="post" action="order_view.php?id=<?= (int)$id ?>">
    <?= csrf_field() ?><input type="hidden" name="save_staff_notes" value="1">
    <textarea name="staff_notes" maxlength="1000" rows="3" placeholder="Laundry handling or processing notes"><?= e($order['staff_notes'] ?? '') ?></textarea>
    <button class="btn btn-outline mt-16" type="submit">Save notes</button>
  </form>
</div>
<?php endif; ?>

<?php if (!is_admin() && !$gcashUnverified && $hasWeightService && !in_array($order['status'], ['cancelled', 'rejected', 'completed', 'picked_up'], true)): ?>
<div class="card mt-16">
  <h2>Actual weight and billing</h2>
  <p>Current total: <strong><?= money($order['total']) ?></strong> · Paid: <strong><?= money($paidTotal) ?></strong> · Balance: <strong><?= money($remainingBalance) ?></strong></p>
  <form method="post" action="order_view.php?id=<?= (int)$id ?>" class="form-row">
    <?= csrf_field() ?><input type="hidden" name="record_weight" value="1">
    <div><label for="actualWeight">Actual laundry weight (kg)</label><input id="actualWeight" name="actual_weight" type="number" min="0.01" max="1000" step="0.01" value="<?= e($order['actual_weight'] ?? '') ?>" required></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save weight and recalculate</button></div>
  </form>
</div>
<?php endif; ?>

<?php if (!is_admin() && !$gcashUnverified && $order['order_type'] === 'delivery_request' && !in_array($order['status'], ['completed', 'picked_up', 'cancelled', 'rejected'], true)): ?>
<div class="card mt-16">
  <h2>Assign delivery driver</h2>
  <p class="muted">Assign an active driver for customer pickup and final delivery. The assigned driver can update collection/delivery status and share live location.</p>
  <form method="post" action="order_view.php?id=<?= (int)$id ?>" class="form-row">
    <?= csrf_field() ?><input type="hidden" name="assign_delivery_driver" value="1">
    <div>
      <label for="deliveryDriver">Driver</label>
      <select id="deliveryDriver" name="driver_id" required <?= $order['status'] === 'out_for_delivery' ? 'disabled' : '' ?>>
        <option value="0">Unassigned</option>
        <?php foreach ($availableDrivers as $driver): ?>
          <option value="<?= (int)$driver['id'] ?>" <?= (int)($order['assigned_driver_id'] ?? 0) === (int)$driver['id'] ? 'selected' : '' ?>><?= e($driver['full_name']) ?> (<?= e($driver['username']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($order['status'] !== 'out_for_delivery'): ?><div class="form-actions"><button class="btn btn-outline" type="submit">Save driver assignment</button></div><?php endif; ?>
  </form>
  <?php if ($order['status'] === 'out_for_delivery'): ?><p class="success mt-16">Assigned to <?= e($order['assigned_driver_name'] ?: 'driver') ?>. The driver signs into the Driver screen in the mobile app.</p><?php endif; ?>
</div>
<?php endif; ?>
<div class="card mt-16">
  <h2>Payments</h2>
  <?php if ($gcashSubmission): ?>
    <div class="<?= $gcashSubmission['status'] === 'verified' ? 'success' : ($gcashSubmission['status'] === 'rejected' ? 'error' : 'notice') ?> mt-16">
      <strong>Mobile GCash transfer: <?= e(ucfirst($gcashSubmission['status'])) ?></strong><br>
      Amount: <?= money($gcashSubmission['amount']) ?> | Reference: <?= e($gcashSubmission['reference_number']) ?><br>
      Submitted: <?= e(date('M j, Y g:i A', strtotime($gcashSubmission['submitted_at']))) ?>
      <?php if ($gcashSubmission['review_note']): ?><br>Review note: <?= e($gcashSubmission['review_note']) ?><?php endif; ?>
      <?php if ($gcashSubmission['reviewer_name']): ?><br>Reviewed by: <?= e($gcashSubmission['reviewer_name']) ?><?php endif; ?>
    </div>
    <?php if ($gcashSubmission['status'] === 'pending' && $order['payment_method'] === 'gcash_pending'): ?>
      <div class="form-actions mt-16">
        <form method="post" action="order_view.php?id=<?= (int)$id ?>" onsubmit="return confirm('Confirm this transfer in the Admin GCash account before verifying it. Continue?');">
          <?= csrf_field() ?><input type="hidden" name="verify_gcash" value="1"><button class="btn btn-primary" type="submit">Verify GCash payment</button>
        </form>
        <form method="post" action="order_view.php?id=<?= (int)$id ?>">
          <?= csrf_field() ?><input type="hidden" name="reject_gcash" value="1"><label for="gcashReviewNote">Reason if rejecting (optional)</label><input id="gcashReviewNote" name="gcash_review_note" maxlength="255" placeholder="Reference not found or amount mismatch"><button class="btn btn-danger mt-16" type="submit">Reject reference</button>
        </form>
      </div>
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($gcashUnverified): ?><p class="notice mt-16">This order is on hold until the GCash transfer is verified.</p><?php endif; ?>
  <?php if ($payments): ?><div class="table-wrap"><table><thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Received by</th><th class="num">Amount</th></tr></thead><tbody><?php foreach ($payments as $payment): ?><tr><td><?= e(date('M j, Y g:i A', strtotime($payment['paid_at']))) ?></td><td><?= e(strtoupper($payment['payment_method'])) ?></td><td><?= e($payment['reference_number'] ?: '—') ?></td><td><?= e($payment['received_by_name'] ?: '—') ?></td><td class="num"><?= money($payment['amount']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="muted">No payment ledger entries yet.</p><?php endif; ?>
  <?php if (!is_admin() && !$gcashUnverified && $order['payment_method'] !== 'gcash_pending' && $remainingBalance > 0 && !in_array($order['status'], ['cancelled', 'rejected'], true)): ?>
  <form method="post" action="order_view.php?id=<?= (int)$id ?>" class="form-row mt-16">
    <?= csrf_field() ?><input type="hidden" name="record_payment" value="1">
    <div><label for="paymentAmount">Amount (balance <?= money($remainingBalance) ?>)</label><input id="paymentAmount" name="payment_amount" type="number" min="0.01" max="<?= e($remainingBalance) ?>" step="0.01" required></div>
    <div><label for="paymentMethod">Payment Method</label><select id="paymentMethod" name="payment_method" required><?php foreach (['cash' => 'Cash', 'gcash' => 'GCash'] as $value => $label): ?><option value="<?= e($value) ?>" <?= $value === $order['payment_method'] ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div><label for="referenceNumber">Reference number (optional)</label><input id="referenceNumber" name="reference_number" maxlength="120"></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Record Payment</button></div>
  </form>
  <?php endif; ?>
</div>

<?php if (in_array($order['status'], ['ready', 'ready_for_pickup'], true) && $smsPhone !== ''): ?>
<div class="card mt-16">
  <h2>Notify customer</h2>
  <p class="muted">Open the phone's messaging app with a ready-for-<?= $order['order_type'] === 'delivery_request' ? 'delivery' : 'pickup' ?> message, then review and send it.</p>
  <textarea id="readySms" rows="2" readonly><?= e($smsMessage) ?></textarea>
  <div class="form-actions"><a class="btn btn-primary" href="sms:<?= e($smsPhone) ?>?body=<?= e(rawurlencode($smsMessage)) ?>">Open SMS app</a><button class="btn btn-outline" type="button" onclick="navigator.clipboard.writeText(document.getElementById('readySms').value).then(()=>this.textContent='Copied')">Copy message</button></div>
</div>
<?php endif; ?>

<?php if (!is_admin() && !$gcashUnverified && !in_array($order['status'], ['cancelled', 'rejected', 'completed', 'picked_up'], true)): ?>
<div class="card mt-16">
  <h2>Update Status</h2>
  <?php if ($isFullService && $order['status'] === 'drying'): ?><p class="muted">When you mark this Full Service order Ready, Fold will be recorded automatically.</p><?php endif; ?>
  <div class="row-between">
    <?php foreach ($statusFlow as $st): ?>
      <form method="post" action="order_view.php?id=<?= (int)$id ?>" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="update_status" value="1">
        <input type="hidden" name="status" value="<?= e($st) ?>">
        <button type="submit" class="btn <?= $st === 'rejected' ? 'btn-danger' : 'btn-primary' ?> btn-sm">
          <?= e($statusActionLabels[$st] ?? $statusLabels[$st]) ?>
        </button>
      </form>
    <?php endforeach; ?>

    <?php if (!in_array($order['status'], ['picked_up', 'completed', 'rejected'], true)): ?><form method="post" action="order_view.php?id=<?= (int)$id ?>"
          onsubmit="return confirm('Cancel this order? This cannot be undone.');">
      <?= csrf_field() ?><input type="hidden" name="cancel_order" value="1">
      <label for="cancelReason">Cancellation reason</label><input id="cancelReason" name="cancel_reason" maxlength="255" required placeholder="Reason for cancellation">
      <?php if (is_admin()): ?><label for="cancelRefund">Refund now (optional)</label><input id="cancelRefund" name="cancel_refund" type="number" min="0" max="<?= e($order['total']) ?>" step="0.01" value="0"><label for="cancelRefundMethod">Refund method</label><select id="cancelRefundMethod" name="cancel_refund_method"><?php foreach (['cash' => 'Cash', 'gcash' => 'GCash'] as $method => $methodLabel): ?><option value="<?= e($method) ?>" <?= $method === $order['payment_method'] ? 'selected' : '' ?>><?= e($methodLabel) ?></option><?php endforeach; ?></select><?php endif; ?>
      <button type="submit" class="btn btn-danger btn-sm">✕ Cancel order</button>
    </form><?php endif; ?>
  </div>
</div>
<?php else: ?>
<div class="card mt-16">
  <p class="muted mb-0">This order is <?= e(strtolower($statusLabels[$order['status']] ?? $order['status'])) ?>.</p>
</div>
<?php endif; ?>


<?php if ($adjustments): ?><div class="card mt-16"><h2>Cancellations &amp; refunds</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Action</th><th>Reason</th><th>Method</th><th class="num">Amount</th><th>Recorded by</th></tr></thead><tbody>
<?php foreach ($adjustments as $adj): ?><tr><td><?= e(date('M j, Y g:i A', strtotime($adj['created_at']))) ?></td><td><?= $adj['adjustment_type'] === 'refund' ? 'Refund' : 'Cancelled' ?></td><td><?= e($adj['reason']) ?></td><td><?= e($adj['payment_method'] ? strtoupper($adj['payment_method']) : '—') ?></td><td class="num"><?= $adj['adjustment_type'] === 'refund' ? money($adj['amount']) : '—' ?></td><td><?= e($adj['full_name'] ?: '—') ?></td></tr><?php endforeach; ?>
</tbody></table></div></div><?php endif; ?>

<!-- ================= Status Timeline ================= -->
<?php
$isDeliveryTimeline = $order['order_type'] === 'delivery_request';
$timelineStages = [
    ['key' => 'pending', 'label' => 'Pending', 'detail' => 'Service request submitted through the mobile app.'],
    ['key' => 'confirmed', 'label' => 'Accepted', 'detail' => 'The shop accepted your request.'],
];
if ($isDeliveryTimeline && ($order['handoff_type'] ?? '') === 'pickup_delivery') {
    $timelineStages[] = ['key' => 'picked_up_for_service', 'label' => 'Picked Up by Driver', 'detail' => 'Driver collected the laundry for service.'];
}
$timelineStages[] = ['key' => 'received', 'label' => 'Received at Shop', 'detail' => 'Laundry arrived at the shop.'];
foreach (order_process_statuses($lines) as $processStatus) {
    $timelineStages[] = [
        'key' => $processStatus,
        'label' => $statusLabels[$processStatus] ?? ucwords(str_replace('_', ' ', $processStatus)),
        'detail' => 'Laundry is being ' . strtolower($statusLabels[$processStatus] ?? $processStatus) . '.',
    ];
}
$timelineStages[] = [
    'key' => 'ready_for_pickup',
    'label' => $isDeliveryTimeline ? 'Ready for Delivery' : 'Ready for Pickup',
    'detail' => $isDeliveryTimeline ? 'Laundry is ready for the assigned driver.' : 'Laundry is ready for customer pickup.',
];
if ($isDeliveryTimeline) {
    $timelineStages[] = ['key' => 'out_for_delivery', 'label' => 'Out for Delivery', 'detail' => 'Driver is bringing the laundry to the customer.'];
}
$timelineStages[] = ['key' => 'completed', 'label' => $isDeliveryTimeline ? 'Delivered' : 'Completed', 'detail' => $isDeliveryTimeline ? 'Laundry was delivered successfully.' : 'Laundry was picked up successfully.'];
$timelineCurrentMap = [
    'pending' => 'pending', 'confirmed' => 'confirmed', 'picked_up_for_service' => 'picked_up_for_service',
    'received' => 'received', 'weighing' => (order_process_statuses($lines)[0] ?? 'received'),
    'washing' => 'washing', 'drying' => 'drying', 'folding' => 'folding',
    'ready' => 'ready_for_pickup', 'ready_for_pickup' => 'ready_for_pickup',
    'out_for_delivery' => 'out_for_delivery', 'completed' => 'completed', 'picked_up' => 'completed',
];
$timelineCurrentKey = $timelineCurrentMap[$order['status']] ?? 'pending';
$timelineCurrentIndex = 0;
foreach ($timelineStages as $timelineIndex => $timelineStage) {
    if ($timelineStage['key'] === $timelineCurrentKey) { $timelineCurrentIndex = $timelineIndex; break; }
}
$timelineHistory = [];
foreach ($history as $historyEntry) {
    $historyKey = $timelineCurrentMap[$historyEntry['status']] ?? null;
    if ($historyKey && !isset($timelineHistory[$historyKey])) $timelineHistory[$historyKey] = $historyEntry;
}
?>
<style>
/* Original web Status Timeline: completed/current green, future muted. */
.order-status-timeline { position:relative; padding-left:22px; margin:4px 0 0; }
.order-status-timeline::before { content:''; position:absolute; left:6px; top:6px; bottom:6px; width:2px; background:#ded3df; border-radius:2px; }
.order-status-timeline .timeline-item { position:relative; padding-bottom:16px; }
.order-status-timeline .timeline-item:last-child { padding-bottom:0; }
.order-status-timeline .timeline-dot { position:absolute; left:-22px; top:3px; width:14px; height:14px; border-radius:50%; background:#c6bec7; border:3px solid #fffaf2; box-shadow:0 0 0 2px #e4dde5; }
.order-status-timeline .timeline-head { display:flex; flex-wrap:wrap; gap:4px 10px; align-items:baseline; font-size:13px; }
.order-status-timeline .timeline-body .small { margin-top:1px; }
.order-status-timeline .timeline-item.is-complete .timeline-dot,
.order-status-timeline .timeline-item.is-current .timeline-dot { background:#5e9b69; box-shadow:0 0 0 2px rgba(94,155,105,.28); }
.order-status-timeline .timeline-item.is-current .timeline-dot { box-shadow:0 0 0 3px rgba(94,155,105,.40); }
.order-status-timeline .timeline-item.is-complete .timeline-head strong,
.order-status-timeline .timeline-item.is-current .timeline-head strong { color:#201820; }
.order-status-timeline .timeline-item.is-complete .timeline-body,
.order-status-timeline .timeline-item.is-current .timeline-body { color:#526858; }
.order-status-timeline .timeline-item.is-future .timeline-head strong,
.order-status-timeline .timeline-item.is-future .timeline-body { color:#aaa1aa; }
</style>
<div class="card mt-16">
  <h2>Status Timeline</h2>
  <?php if (in_array($order['status'], ['cancelled', 'rejected'], true)): ?>
    <p class="error mb-0"><?= $order['status'] === 'cancelled' ? 'This order was cancelled.' : 'This request was declined.' ?></p>
  <?php else: ?>
    <div class="timeline order-status-timeline">
      <?php foreach ($timelineStages as $timelineIndex => $timelineStage): ?>
        <?php
          $isCurrentTimelineStage = $timelineIndex === $timelineCurrentIndex;
          $isCompletedTimelineStage = $timelineIndex < $timelineCurrentIndex;
          $isFutureTimelineStage = $timelineIndex > $timelineCurrentIndex;
          $timelineEvent = $timelineHistory[$timelineStage['key']] ?? null;
        ?>
        <div class="timeline-item <?= $isCurrentTimelineStage ? 'is-current' : ($isCompletedTimelineStage ? 'is-complete' : 'is-future') ?>">
          <span class="timeline-dot" aria-hidden="true"></span>
          <div class="timeline-head">
            <strong><?= e($timelineStage['label']) ?><?= $isCurrentTimelineStage ? ' · Current' : '' ?></strong>
            <?php if ($timelineEvent): ?><span class="small muted"><?= e(date('M j, Y g:i A', strtotime($timelineEvent['created_at']))) ?></span><?php endif; ?>
          </div>
          <div class="timeline-body">
            <div class="small"><?= e($timelineEvent['note'] ?? $timelineStage['detail']) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
