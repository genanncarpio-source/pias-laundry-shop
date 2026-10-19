import { useCallback, useEffect, useRef, useState } from 'react';
import { AppState } from 'react-native';
import { apiRequest } from './api';
import { getActiveSession, saveVerifiedSession } from './session';

const empty = { services: [], tickets: [], payment: null };

export function useOnlineShop({ apiBase, customer, token, onSessionExpired }) {
  const [snapshot, setSnapshot] = useState(empty);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');
  const active = useRef(false);
  const inFlight = useRef(null);

  const refresh = useCallback((quiet = false) => {
    if (inFlight.current) return inFlight.current;
    const operation = (async () => {
      if (!active.current) return;
      if (!quiet) setError('');
      setRefreshing(true);
      try {
        const me = await apiRequest(apiBase, 'me.php', { token, timeoutMs: 10000 });
        if (!active.current || getActiveSession(apiBase)?.token !== token) return;
        if (String(me.customer?.id) !== String(customer.id)) {
          throw Object.assign(new Error('Sign in again.'), { status: 401 });
        }
        await saveVerifiedSession(apiBase, token, me.customer);
        const [services, tickets, payment] = await Promise.all([
          apiRequest(apiBase, 'services.php', { timeoutMs: 10000 }),
          apiRequest(apiBase, 'my_tickets.php', { token, timeoutMs: 10000 }),
          apiRequest(apiBase, 'payment_info.php', { token, timeoutMs: 10000 }),
        ]);
        const withTracking = await Promise.all((tickets.tickets || []).map(async (ticket) => {
          if (ticket.order_type !== 'delivery_request' || ticket.status !== 'out_for_delivery') return ticket;
          try {
            const data = await apiRequest(apiBase, 'delivery_tracking.php?order_id=' + encodeURIComponent(ticket.id), { token });
            return { ...ticket, tracking: data.tracking || null };
          } catch (trackingError) {
            if (trackingError.status === 401 || trackingError.status === 403) throw trackingError;
            return { ...ticket, tracking: null };
          }
        }));
        if (active.current) {
          setSnapshot({ services: services.services || [], tickets: withTracking, payment: payment.payment || null });
          setError('');
        }
      } catch (problem) {
        if (!active.current) return;
        if (problem.status === 401 || problem.status === 403) {
          await onSessionExpired();
          return;
        }
        if (!quiet) setError(problem.message || 'Could not connect to the shop. Check your internet connection.');
      } finally {
        if (active.current) {
          setLoading(false);
          setRefreshing(false);
        }
      }
    })();
    inFlight.current = operation;
    void operation.finally(() => { if (inFlight.current === operation) inFlight.current = null; }).catch(() => {});
    return operation;
  }, [apiBase, customer.id, onSessionExpired, token]);

  useEffect(() => {
    active.current = true;
    void refresh();
    return () => { active.current = false; };
  }, [refresh]);

  useEffect(() => {
    const subscription = AppState.addEventListener('change', (state) => {
      if (state === 'active') void refresh(true);
    });
    return () => subscription.remove();
  }, [refresh]);

  return { ...snapshot, loading, refreshing, error, setError, refresh };
}