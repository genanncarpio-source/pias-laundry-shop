import { useCallback, useEffect, useRef, useState } from 'react';
import { AppState } from 'react-native';
import { apiRequest } from './api';
import { getActiveSession, saveVerifiedSession } from './session';

const empty = { services: [], tickets: [], payment: null, notifications: [], unreadNotifications: 0, shop: {} };

export function useOnlineShop({ apiBase, customer, token, onSessionExpired }) {
  const [snapshot, setSnapshot] = useState(empty);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');
  const [notificationsError, setNotificationsError] = useState('');
  const [notificationsLoaded, setNotificationsLoaded] = useState(false);
  const active = useRef(false);
  const inFlight = useRef(null);
  const notificationRequest = useRef(null);

  const refreshNotifications = useCallback(() => {
    if (notificationRequest.current) return notificationRequest.current;
    const operation = apiRequest(apiBase, 'customer_notifications.php', { token, timeoutMs: 10000 }).then((data) => {
      if (active.current) { setSnapshot((current) => ({ ...current, notifications: data.notifications || [], unreadNotifications: Number(data.unread_count) || 0 })); setNotificationsLoaded(true); setNotificationsError(''); }
    }).catch(async (problem) => {
      if (problem.status === 401 || problem.status === 403) await onSessionExpired();
      else if (active.current) setNotificationsError(problem.message || 'Could not load Alerts. Check your connection and try again.');
    }).finally(() => { if (notificationRequest.current === operation) notificationRequest.current = null; });
    notificationRequest.current = operation;
    return operation;
  }, [apiBase, onSessionExpired, token]);

  const markNotificationRead = useCallback(async (id = null) => {
    await apiRequest(apiBase, 'customer_notifications.php', { method: 'POST', token, body: id == null ? { mark_all_read: true } : { id } });
    await refreshNotifications();
  }, [apiBase, refreshNotifications, token]);

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
          setSnapshot((current) => ({ ...current, services: services.services || [], tickets: withTracking, payment: payment.payment || null, shop: tickets.shop || current.shop }));
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

  const refreshServices = useCallback(async () => {
    try {
      const data = await apiRequest(apiBase, 'services.php', { timeoutMs: 10000 });
      if (active.current) setSnapshot((current) => ({ ...current, services: data.services || [] }));
    } catch { /* Keep the last available service list while temporarily offline. */ }
  }, [apiBase]);

  useEffect(() => {
    active.current = true;
    void refresh();
    void refreshNotifications();
    return () => { active.current = false; };
  }, [refresh, refreshNotifications]);

  useEffect(() => {
    const timer = setInterval(() => {
      if (AppState.currentState === 'active' || AppState.currentState == null) void refreshNotifications();
    }, 15000);
    return () => clearInterval(timer);
  }, [refreshNotifications]);

  useEffect(() => {
    if (!(snapshot.tickets || []).some((ticket) => ticket.order_type === 'delivery_request' && ticket.status === 'out_for_delivery')) return undefined;
    const timer = setInterval(() => { void refresh(true); }, 10000);
    return () => clearInterval(timer);
  }, [refresh, snapshot.tickets]);

  useEffect(() => {
    const timer = setInterval(() => {
      if (AppState.currentState === 'active' || AppState.currentState == null) void refreshServices();
    }, 30000);
    return () => clearInterval(timer);
  }, [refreshServices]);

  useEffect(() => {
    const subscription = AppState.addEventListener('change', (state) => {
      if (state === 'active') { void refresh(true); void refreshNotifications(); }
    });
    return () => subscription.remove();
  }, [refresh, refreshNotifications]);

  return { ...snapshot, loading, refreshing, error, setError, refresh, refreshNotifications, markNotificationRead, notificationsLoaded, notificationsError };
}