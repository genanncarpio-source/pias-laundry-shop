import { useCallback, useEffect, useRef, useState } from 'react';
import { AppState } from 'react-native';
import { apiRequest, isUnavailable } from '../api';
import { accountScope, offlineStore } from './index';
import { getActiveSession, saveVerifiedSession } from './session';

const empty = { services: [], tickets: [], payment: null, fetchedAt: null };
export function useOfflineShop({ apiBase, customer, token, onSessionExpired }) {
  const scope = accountScope(apiBase, customer.id);
  const [snapshot, setSnapshot] = useState(empty);
  const [queue, setQueue] = useState([]);
  const [offline, setOffline] = useState(true);
  const [loading, setLoading] = useState(true);
  const [syncing, setSyncing] = useState(false);
  const [error, setError] = useState('');
  const active = useRef(false);
  const currentSnapshot = useRef(empty);
  const inFlight = useRef(null);
  const publish = useCallback((value) => {
    currentSnapshot.current = value || empty;
    if (active.current) setSnapshot(value || empty);
  }, []);
  const reloadQueue = useCallback(async () => {
    const items = await offlineStore.listBookings(scope);
    if (active.current) setQueue(items);
    return items;
  }, [scope]);
  const refresh = useCallback((quiet = false) => {
    if (inFlight.current) return inFlight.current;
    const operation = (async () => {
      if (!active.current) return;
      if (!quiet) setError('');
      setSyncing(true);
      try {
        const me = await apiRequest(apiBase, 'me.php', { token, timeoutMs: 6000 });
        if (!active.current || getActiveSession(apiBase)?.token !== token) return;
        if (String(me.customer?.id) !== String(customer.id)) throw Object.assign(new Error('Sign in again.'), { status: 401 });
        await saveVerifiedSession(apiBase, token, me.customer);
        if (!active.current) return;
        // Never replay against an older server that could silently ignore request IDs.
        if (me.offline_booking_version === 1) {
          const result = await offlineStore.syncBookings(scope, (body) => {
            if (!active.current || getActiveSession(apiBase)?.token !== token) {
              throw Object.assign(new Error('Sign in again to send saved bookings.'), { status: 401 });
            }
            return apiRequest(apiBase, 'tickets.php', { method: 'POST', token, body, timeoutMs: 15000 });
          });
          if (!active.current) return;
          await reloadQueue();
          publish(await offlineStore.readCache(scope));
          if (result.authRequired) throw Object.assign(new Error('Your session expired. Sign in online to send saved bookings.'), { status: 401 });
          if (result.offline) throw Object.assign(new Error('The shop has not confirmed every saved booking. Reconnecting will safely check again.'), { offline: true });
        } else if ((await reloadQueue()).length) {
          setError('Saved bookings are waiting. Update the shop API with offline booking support before syncing.');
        }
        const [services, tickets, payment] = await Promise.all([
          apiRequest(apiBase, 'services.php'),
          apiRequest(apiBase, 'my_tickets.php', { token }),
          apiRequest(apiBase, 'payment_info.php', { token }),
        ]);
        const oldTickets = currentSnapshot.current.tickets || [];
        const withTracking = await Promise.all((tickets.tickets || []).map(async (ticket) => {
          if (ticket.order_type !== 'delivery_request' || ticket.status !== 'out_for_delivery') return ticket;
          try {
            const data = await apiRequest(apiBase, 'delivery_tracking.php?order_id=' + encodeURIComponent(ticket.id), { token });
            return { ...ticket, tracking: { ...data.tracking, cached: false } };
          } catch (trackingError) {
            if (trackingError.status === 401 || trackingError.status === 403) throw trackingError;
            const previous = oldTickets.find((old) => String(old.id) === String(ticket.id));
            return { ...ticket, tracking: previous?.tracking ? { ...previous.tracking, cached: true } : null };
          }
        }));
        if (!active.current || getActiveSession(apiBase)?.token !== token) return;
        const next = { services: services.services || [], tickets: withTracking,
          payment: payment.payment || null, fetchedAt: new Date().toISOString() };
        setOffline(false);
        publish(next);
        try { publish(await offlineStore.writeCache(scope, next)); }
        catch { setError('Connected, but this device could not save an offline copy. Free storage before going offline.'); }
      } catch (problem) {
        if (!active.current) return;
        if (problem.status === 401 || problem.status === 403) {
          await onSessionExpired();
          return;
        }
        if (isUnavailable(problem)) {
          setOffline(true);
          if (!quiet) setError(problem.message);
          if (!getActiveSession(apiBase)) await onSessionExpired();
        } else {
          setError(problem.message || 'Could not update saved information.');
        }
      } finally {
        if (active.current) {
          try { await reloadQueue(); } catch (problem) { setError(problem.message); }
          setLoading(false);
          setSyncing(false);
        }
      }
    })();
    inFlight.current = operation;
    void operation.finally(() => { if (inFlight.current === operation) inFlight.current = null; }).catch(() => {});
    return operation;
  }, [apiBase, customer.id, onSessionExpired, publish, reloadQueue, scope, token]);
  useEffect(() => {
    active.current = true;
    let cancelled = false;
    (async () => {
      try {
        const saved = await offlineStore.readCache(scope);
        if (cancelled) return;
        if (saved) { publish(saved); setLoading(false); }
        await reloadQueue();
      } catch (problem) { if (!cancelled) setError('Could not read saved data: ' + problem.message); }
      if (!cancelled) void refresh(true);
    })();
    return () => { cancelled = true; active.current = false; };
  }, [publish, refresh, reloadQueue, scope]);
  useEffect(() => {
    const timer = setInterval(() => {
      if (AppState.currentState === 'active' || AppState.currentState == null) void refresh(true);
    }, 20000);
    const subscription = AppState.addEventListener('change', (state) => { if (state === 'active') void refresh(true); });
    return () => { clearInterval(timer); subscription.remove(); };
  }, [refresh]);
  const enqueue = useCallback(async (request) => {
    const booking = await offlineStore.enqueueBooking(scope, request);
    try { await reloadQueue(); } catch (problem) { setError(problem.message); }
    return booking;
  }, [reloadQueue, scope]);
  const remove = useCallback(async (id) => {
    await offlineStore.removeBooking(scope, id);
    await reloadQueue();
  }, [reloadQueue, scope]);
  return { ...snapshot, queue, offline, loading, syncing, error, setError, refresh, enqueue, remove };
}
