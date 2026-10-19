const clean = (value) => JSON.parse(JSON.stringify(value, (key, item) =>
  /^(token|password|authorization)$/i.test(key) ? undefined : item));
const ticketId = (ticket) => String(ticket.id ?? ticket.client_request_id ?? ticket.order_number);
export function createOfflineStore(storage, createId, now = () => new Date().toISOString()) {
  const locks = new Map();
  const syncing = new Map();
  const keyFor = (scope) => 'pias.offline.v1.' + encodeURIComponent(scope);
  const locked = (scope, action) => {
    const operation = (locks.get(scope) || Promise.resolve()).catch(() => {}).then(action);
    const settled = operation.catch(() => {});
    locks.set(scope, settled);
    void settled.then(() => { if (locks.get(scope) === settled) locks.delete(scope); });
    return operation;
  };
  const read = async (scope) => {
    const text = await storage.getItem(keyFor(scope));
    if (!text) return { version: 1, cache: null, bookings: [], receipts: [] };
    const doc = JSON.parse(text);
    if (doc.version !== 1 || !Array.isArray(doc.bookings) || !Array.isArray(doc.receipts)) {
      throw new Error('Saved offline data cannot be read. Do not clear app data while bookings are waiting.');
    }
    return doc;
  };
  const write = (scope, doc) => storage.setItem(keyFor(scope), JSON.stringify(clean(doc)));
  const mergedCache = (doc) => {
    if (!doc.cache && !doc.receipts.length) return null;
    const cache = doc.cache || { services: [], tickets: [], payment: null, fetchedAt: null };
    const tickets = new Map((cache.tickets || []).map((t) => [ticketId(t), t]));
    doc.receipts.forEach((t) => { if (!tickets.has(ticketId(t))) tickets.set(ticketId(t), t); });
    return { ...cache, tickets: [...tickets.values()] };
  };
  const readCache = (scope) => locked(scope, async () => mergedCache(await read(scope)));
  const writeCache = (scope, snapshot) => locked(scope, async () => {
    const doc = await read(scope);
    const ids = new Set((snapshot.tickets || []).map(ticketId));
    doc.receipts = doc.receipts.filter((t) => !ids.has(ticketId(t)));
    doc.cache = clean(snapshot);
    await write(scope, doc);
    return mergedCache(doc);
  });
  const clearCache = (scope) => locked(scope, async () => {
    const doc = await read(scope);
    doc.cache = null;
    doc.receipts = [];
    await write(scope, doc);
  });
  const listBookings = (scope) => locked(scope, async () => (await read(scope)).bookings);
  const enqueueBooking = (scope, { body, summary }) => locked(scope, async () => {
    const doc = await read(scope);
    if (doc.bookings.length >= 50) throw new Error('Sync or review your saved bookings before adding more.');
    const id = createId();
    const item = { id, body: clean({ ...body, client_request_id: id }), summary: clean(summary),
      createdAt: now(), status: 'pending', attempted: false, error: '' };
    doc.bookings.push(item);
    await write(scope, doc);
    return item;
  });
  const removeBooking = (scope, id) => locked(scope, async () => {
    const doc = await read(scope);
    const item = doc.bookings.find((b) => b.id === id);
    if (!item) return;
    if ((item.attempted && item.status !== 'review') || item.errorCode === 'client_request_conflict') {
      throw new Error('Reconnect first to confirm whether this booking reached the shop.');
    }
    doc.bookings = doc.bookings.filter((b) => b.id !== id);
    await write(scope, doc);
  });
  const syncBookings = (scope, send) => {
    if (syncing.has(scope)) return syncing.get(scope);
    const operation = (async () => {
      const result = { sent: 0, offline: false, authRequired: false };
      const ids = (await listBookings(scope)).filter((b) => b.status === 'pending').map((b) => b.id);
      for (const id of ids) {
        const item = await locked(scope, async () => {
          const doc = await read(scope);
          const booking = doc.bookings.find((b) => b.id === id && b.status === 'pending');
          if (!booking) return null;
          booking.attempted = true;
          await write(scope, doc);
          return clean(booking);
        });
        if (!item) continue;
        let response;
        try {
          response = await send(item.body, item);
          if (!response?.ticket?.id) throw new Error('The shop has not confirmed this booking. It will be checked again.');
        } catch (error) {
          const status = Number(error.status || 0);
          if (status === 401 || status === 403) { result.authRequired = true; break; }
          const retry = !status || status >= 500 || status === 408 || status === 429;
          await locked(scope, async () => {
            const doc = await read(scope);
            const booking = doc.bookings.find((b) => b.id === id);
            if (booking) {
              booking.status = retry ? 'pending' : 'review';
              booking.error = error.message || 'The booking needs review.';
              booking.errorCode = error.code || '';
              await write(scope, doc);
            }
          });
          if (retry) { result.offline = true; break; }
          continue;
        }
        // Persist receipt and remove the outbox entry together. A failed write
        // leaves the same request ID available for a safe server replay.
        await locked(scope, async () => {
          const doc = await read(scope);
          const receipt = { ...item.summary, ...response.ticket,
            order_number: response.ticket.ticket_number || response.ticket.order_number,
            total: response.ticket.estimated_total ?? item.summary.total,
            balance_due: response.ticket.estimated_total ?? item.summary.balance_due,
            client_request_id: id, created_at: item.createdAt };
          doc.receipts = [...doc.receipts.filter((t) => ticketId(t) !== ticketId(receipt)), receipt];
          doc.bookings = doc.bookings.filter((b) => b.id !== id);
          await write(scope, doc);
        });
        result.sent++;
      }
      return result;
    })();
    syncing.set(scope, operation);
    void operation.finally(() => { if (syncing.get(scope) === operation) syncing.delete(scope); }).catch(() => {});
    return operation;
  };
  return { readCache, writeCache, clearCache, listBookings, enqueueBooking, removeBooking, syncBookings };
}
