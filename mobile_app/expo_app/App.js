import { StatusBar } from 'expo-status-bar';
import { useLocalSearchParams, usePathname, useRouter } from 'expo-router';
import Constants from 'expo-constants';
import * as SecureStore from 'expo-secure-store';
import * as Location from 'expo-location';
import { LinearGradient } from 'expo-linear-gradient';
import DeliveryMap from './src/components/DeliveryMap';
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  ActivityIndicator,
  Image,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  useWindowDimensions,
  View,
} from 'react-native';

const COLORS = {
  ink: '#201820',
  dark: '#18131a',
  darkPanel: '#241d26',
  plum: '#58265f',
  purple: '#8a5594',
  purpleLight: '#f2eaf4',
  copper: '#bd7841',
  cream: '#fffaf2',
  paper: '#f6efe6',
  muted: '#786f78',
  line: '#ded3df',
  green: '#40513b',
  red: '#a43e4a',
  white: '#ffffff',
};

const TOKEN_KEY = 'pias_customer_api_token';
const CONFIGURED_API = process.env.EXPO_PUBLIC_API_BASE_URL?.trim();

function getLocalDevelopmentApi() {
  const apiPath = '/laundry-pias/api';
  if (Platform.OS === 'web') {
    const host = typeof window !== 'undefined' && window.location.hostname ? window.location.hostname : 'localhost';
    return `http://${host}${apiPath}`;
  }

  const hostUri = Constants.expoConfig?.hostUri || '';
  const host = hostUri.startsWith('[') ? hostUri.slice(1, hostUri.indexOf(']')) : hostUri.split(':')[0];
  const isPrivateIpv4 = /^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/.test(host);
  if (isPrivateIpv4) return `http://${host}${apiPath}`;

  return Platform.OS === 'android'
    ? `http://10.0.2.2${apiPath}`
    : `http://localhost${apiPath}`;
}

const LOCAL_DEV_API = getLocalDevelopmentApi();
const DEFAULT_API = (CONFIGURED_API || (__DEV__ ? LOCAL_DEV_API : '')).replace(/\/+$/, '');

async function storageGet(key) {
  try {
    if (Platform.OS === 'web') return typeof window !== 'undefined' ? window.localStorage.getItem(key) : null;
    return await SecureStore.getItemAsync(key);
  } catch (_) {
    return null;
  }
}

async function storageSet(key, value) {
  if (Platform.OS === 'web') {
    if (typeof window !== 'undefined') window.localStorage.setItem(key, value);
    return;
  }
  await SecureStore.setItemAsync(key, value);
}

async function storageDelete(key) {
  if (Platform.OS === 'web') {
    if (typeof window !== 'undefined') window.localStorage.removeItem(key);
    return;
  }
  await SecureStore.deleteItemAsync(key);
}

function normalizeApiUrl(url) {
  return url.trim().replace(/\/+$/, '');
}

async function apiRequest(baseUrl, endpoint, { method = 'GET', body, token } = {}) {
  if (!baseUrl) {
    throw new Error('Shop connection is not configured. Set EXPO_PUBLIC_API_BASE_URL for this build.');
  }
  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (token) headers.Authorization = `Bearer ${token}`;

  let response;
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 20000);
  try {
    response = await fetch(`${normalizeApiUrl(baseUrl)}/${endpoint}`, {
      method,
      headers,
      ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
      signal: controller.signal,
    });
  } catch (_) {
    throw new Error('Could not connect to the shop. Check the API address and network connection.');
  } finally {
    clearTimeout(timeout);
  }

  let data;
  let responseText;
  try {
    responseText = await response.text();
    data = JSON.parse(responseText);
  } catch (_) {
    const contentType = response.headers.get('content-type') || '';
    if (contentType.includes('text/html') || /^\s*</.test(responseText || '')) {
      throw new Error('The configured shop server returned a web page instead of API data. Check the app connection configuration.');
    }
    throw new Error('The shop server returned an unreadable response.');
  }

  if (!response.ok || data.success !== true) {
    const error = new Error(data.error || 'The request could not be completed.');
    error.status = response.status;
    throw error;
  }
  return data;
}

function money(value) {
  const number = Number(value);
  return `₱${Number.isFinite(number) ? number.toFixed(2) : '0.00'}`;
}

function serviceCategory(service) {
  const name = String(service.name || '').toLowerCase();
  const category = String(service.category || '').toLowerCase();
  if (category.includes('add') || /detergent|fabcon|fabric conditioner/.test(name) || name === 'fold') return 'Add-ons';
  if (/full service|wash.?dry.?fold|wash and fold/.test(name) || category.includes('full service') || category.includes('wash & fold')) return 'Full service';
  if (name === 'wash' || category === 'wash') return 'Wash';
  if (name === 'dry' || name.includes('dry clean') || category === 'dry') return 'Dry';
  return 'Other';
}

function serviceEmoji(service) {
  const name = String(service.name || '').toLowerCase();
  if (name.includes('detergent')) return '🧴';
  if (name.includes('fabcon') || name.includes('conditioner')) return '🫧';
  if (name.includes('dry')) return '💨';
  if (name.includes('fold')) return '👕';
  return '🧺';
}

function formatDate(value) {
  if (!value) return '';
  const parsed = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString();
}

function statusLabel(value) {
  return String(value || 'pending').replaceAll('_', ' ');
}

export default function App() {
  const router = useRouter();
  const apiBase = DEFAULT_API;
  const [token, setToken] = useState(null);
  const [customer, setCustomer] = useState(null);
  const [booting, setBooting] = useState(true);

  useEffect(() => {
    let active = true;
    (async () => {
      const savedToken = await storageGet(TOKEN_KEY);
      if (!active) return;
      if (savedToken) {
        try {
          const data = await apiRequest(apiBase, 'me.php', { token: savedToken });
          if (active) {
            setToken(savedToken);
            setCustomer(data.customer);
          }
        } catch (_) {
          await storageDelete(TOKEN_KEY);
        }
      }
      if (active) setBooting(false);
    })();
    return () => { active = false; };
  }, [apiBase]);

  const handleSignedIn = (session) => {
    setToken(session.token);
    setCustomer(session.customer);
    router.replace('/');
  };

  const handleSignOut = async () => {
    try {
      await apiRequest(apiBase, 'logout.php', { method: 'POST', body: {}, token });
    } catch (_) {
      // Clear the local token even if the shop cannot be reached.
    }
    await storageDelete(TOKEN_KEY);
    setToken(null);
    setCustomer(null);
    router.replace('/');
  };

  const handleSessionExpired = async () => {
    await storageDelete(TOKEN_KEY);
    setToken(null);
    setCustomer(null);
    router.replace('/');
  };

  if (booting) {
    return <LoadingScreen message="Opening Pia&apos;s Laundry Shop…" />;
  }

  return (
    <View style={styles.app}>
      <StatusBar style="light" />
      {customer && token ? (
        <CustomerHome
          apiBase={apiBase}
          customer={customer}
          token={token}
          onSessionExpired={handleSessionExpired}
          onSignOut={handleSignOut}
        />
      ) : (
        <AuthScreen apiBase={apiBase} onSignedIn={handleSignedIn} />
      )}
    </View>
  );
}

function LoadingScreen({ message }) {
  return (
    <View style={styles.loadingScreen}>
      <StatusBar style="light" />
      <Text style={styles.brandMark}>🧺</Text>
      <ActivityIndicator color={COLORS.copper} size="large" />
      <Text style={styles.loadingText}>{message}</Text>
    </View>
  );
}

function BrandBar({ onSignOut }) {
  return (
    <View style={styles.topBar}>
      <View style={styles.brandLockup}>
        <View style={styles.miniLogo}><Text style={styles.miniLogoText}>🧺</Text></View>
        <Text style={styles.topTitle}>Pia&apos;s Laundry Shop</Text>
      </View>
      <View style={styles.topActions}>
        {onSignOut ? <Pressable onPress={onSignOut} style={styles.headerButton} accessibilityLabel="Sign out"><Text style={styles.headerButtonText}>↪</Text></Pressable> : null}
      </View>
    </View>
  );
}

function HeroBanner({ compact = false }) {
  return (
    <LinearGradient colors={['#54245f', '#754583', '#8e5a95']} start={{ x: 0, y: 0.3 }} end={{ x: 1, y: 0.8 }} style={[styles.hero, compact && styles.heroCompact]}>
      <View style={styles.heroDecorOne} />
      <View style={styles.heroDecorTwo} />
      <View style={styles.heroContent}>
        <View style={styles.heroIcon}><Text style={styles.heroEmoji}>🫧</Text></View>
        <View style={styles.heroTextBlock}>
          <Text style={styles.heroTitle}>Pia&apos;s Laundry Shop</Text>
          <Text style={styles.heroDetail}>Blk. 2, Brgy. San Jose, Tarlac City</Text>
          <Text style={styles.heroDetail}>Contact: 0918-967-9623</Text>
        </View>
        <Text style={styles.heroFloatingEmoji}>🧺</Text>
      </View>
    </LinearGradient>
  );
}

function Field({ label, value, onChangeText, secureTextEntry, right, ...inputProps }) {
  return (
    <View style={styles.fieldWrap}>
      {label ? <Text style={styles.fieldLabel}>{label}</Text> : null}
      <View style={styles.inputShell}>
        <TextInput
          value={value}
          onChangeText={onChangeText}
          secureTextEntry={secureTextEntry}
          placeholderTextColor="#988d99"
          style={styles.input}
          {...inputProps}
        />
        {right ? <View style={styles.inputRight}>{right}</View> : null}
      </View>
    </View>
  );
}

function AuthScreen({ apiBase, onSignedIn }) {
  const router = useRouter();
  const [registering, setRegistering] = useState(false);
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [address, setAddress] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const { width } = useWindowDimensions();

  const submit = async () => {
    setError('');
    setNotice('');
    if (registering && !name.trim()) return setError('Enter your full name.');
    if (registering && !phone.trim()) return setError('Enter your phone number.');
    if (registering && !address.trim()) return setError('Enter your pickup address.');
    if (!email.includes('@')) return setError('Enter a valid email address.');
    if (!password || (registering && password.length < 10)) return setError(registering ? 'Use a password with at least 10 characters.' : 'Enter your password.');

    setBusy(true);
    try {
      const data = await apiRequest(apiBase, registering ? 'register.php' : 'login.php', {
        method: 'POST',
        body: {
          ...(registering ? { name: name.trim(), phone: phone.trim(), address: address.trim() } : {}),
          email: email.trim(),
          password,
        },
      });
      const nextToken = String(data.token);
      await storageSet(TOKEN_KEY, nextToken);
      onSignedIn({ token: nextToken, customer: data.customer });
    } catch (submitError) {
      setError(submitError.message || 'The request could not be completed.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <KeyboardAvoidingView style={styles.authRoot} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <BrandBar />
      <ScrollView contentContainerStyle={styles.authScroll} keyboardShouldPersistTaps="handled">
        <View style={[styles.authContainer, { width: Math.min(width - 32, 520) }]}>
          <HeroBanner compact />
          <View style={styles.authIntro}>
            <Text style={styles.authTitle}>{registering ? 'Join the laundry club' : 'Welcome back'}</Text>
            <Text style={styles.authDescription}>{registering ? 'Create an account to book laundry services and follow your tickets.' : 'Sign in to book a service and follow your laundry ticket.'}</Text>
          </View>
          <View style={styles.authCard}>
            <View style={styles.authSwitch}>
              <Pressable onPress={() => { setRegistering(false); setError(''); }} style={[styles.authSwitchButton, !registering && styles.authSwitchSelected]}>
                <Text style={[styles.authSwitchText, !registering && styles.authSwitchSelectedText]}>Sign in</Text>
              </Pressable>
              <Pressable onPress={() => { setRegistering(true); setError(''); }} style={[styles.authSwitchButton, registering && styles.authSwitchSelected]}>
                <Text style={[styles.authSwitchText, registering && styles.authSwitchSelectedText]}>Create account</Text>
              </Pressable>
            </View>

            {notice ? <Text style={styles.successBox}>{notice}</Text> : null}
            {error ? <Text style={styles.errorBox}>{error}</Text> : null}

            {registering ? <Field label="Full name" value={name} onChangeText={setName} placeholder="Your name" autoCapitalize="words" returnKeyType="next" /> : null}
            {registering ? <Field label="Phone number" value={phone} onChangeText={setPhone} placeholder="09xx xxx xxxx" keyboardType="phone-pad" returnKeyType="next" /> : null}
            {registering ? <Field label="Pickup address" value={address} onChangeText={setAddress} placeholder="Street, barangay, city" autoCapitalize="words" returnKeyType="next" /> : null}
            <Field label="Email address" value={email} onChangeText={setEmail} placeholder="you@example.com" keyboardType="email-address" autoCapitalize="none" autoCorrect={false} returnKeyType="next" />
            <Field
              label="Password"
              value={password}
              onChangeText={setPassword}
              placeholder={registering ? 'At least 10 characters' : 'Enter your password'}
              secureTextEntry={!showPassword}
              autoCapitalize="none"
              right={<Pressable onPress={() => setShowPassword(!showPassword)}><Text style={styles.showPassword}>{showPassword ? 'Hide' : 'Show'}</Text></Pressable>}
              returnKeyType="go"
              onSubmitEditing={submit}
            />
            <Pressable onPress={submit} disabled={busy} style={({ pressed }) => [styles.primaryButton, pressed && !busy && styles.pressed, busy && styles.disabledButton]}>
              {busy ? <ActivityIndicator color={COLORS.white} /> : <Text style={styles.primaryButtonText}>{registering ? 'Create account' : 'Sign in'}</Text>}
            </Pressable>
            <Text style={styles.authFootnote}>The shop confirms your service, price, and pickup or delivery schedule.</Text>
            {!registering ? (
              <Pressable accessibilityRole="link" onPress={() => router.push('/driver')} style={styles.driverSignInLink}>
                <Text style={styles.driverSignInText}>Delivery driver? Sign in here</Text>
              </Pressable>
            ) : null}
          </View>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

function CustomerHome({ apiBase, customer, token, onSessionExpired, onSignOut }) {
  const pathname = usePathname();
  const router = useRouter();
  const params = useLocalSearchParams();
  const tab = pathname === '/track' ? 'track' : 'book';
  const [services, setServices] = useState([]);
  const [tickets, setTickets] = useState([]);
  const [quantities, setQuantities] = useState({});
  const [filter, setFilter] = useState('All');
  const [search, setSearch] = useState('');
  const [notes, setNotes] = useState('');
  const [fulfillment, setFulfillment] = useState('pickup');
  const [deliveryAddress, setDeliveryAddress] = useState(customer.address || '');
  const [pickupAt, setPickupAt] = useState(null);
  const [pickupDate, setPickupDate] = useState('');
  const [pickupTime, setPickupTime] = useState('09:00');
  const [pickupError, setPickupError] = useState('');
  const [pickupModal, setPickupModal] = useState(false);
  const [loading, setLoading] = useState(true);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState('');
  const [locationBusy, setLocationBusy] = useState({});
  const [locationError, setLocationError] = useState({});
  const [gcashInfo, setGcashInfo] = useState(null);
  const [paymentMethod, setPaymentMethod] = useState('at_shop');
  const [gcashReference, setGcashReference] = useState('');
  const [retryReferences, setRetryReferences] = useState({});
  const [retryBusy, setRetryBusy] = useState({});
  const toast = typeof params.booking === 'string' && params.booking ? 'Booking submitted. Ticket ' + params.booking : '';
  const { width } = useWindowDimensions();
  const wide = width >= 760;
  const navigateTo = (nextTab, bookingNumber) => {
    if (nextTab === 'track' && bookingNumber) {
      router.replace({ pathname: '/track', params: { booking: bookingNumber } });
    } else {
      router.replace(nextTab === 'track' ? '/track' : '/');
    }
  };


  const loadShopData = useCallback(async (quiet = false) => {
    if (!quiet) {
      setLoading(true);
      setError('');
    }
    try {
      const [serviceData, ticketData, paymentData] = await Promise.all([
        apiRequest(apiBase, 'services.php'),
        apiRequest(apiBase, 'my_tickets.php', { token }),
        apiRequest(apiBase, 'payment_info.php', { token }),
      ]);
      setServices(serviceData.services || []);
      setGcashInfo(paymentData.payment || null);
      const nextTickets = await Promise.all((ticketData.tickets || []).map(async (ticket) => {
        if (ticket.order_type !== 'delivery_request' || ticket.status !== 'out_for_delivery') return ticket;
        try {
          const trackingData = await apiRequest(apiBase, 'delivery_tracking.php?order_id=' + encodeURIComponent(ticket.id), { token });
          return { ...ticket, tracking: trackingData.tracking };
        } catch (_) {
          return ticket;
        }
      }));
      setTickets(nextTickets);
    } catch (loadError) {
      if (loadError.status === 401) await onSessionExpired();
      setError(loadError.message || 'Could not load the shop right now.');
    } finally {
      if (!quiet) setLoading(false);
    }
  }, [apiBase, onSessionExpired, token]);

  const updateCustomerLocation = async (ticket, action) => {
    const ticketId = String(ticket.id);
    setLocationBusy((current) => ({ ...current, [ticketId]: true }));
    setLocationError((current) => ({ ...current, [ticketId]: '' }));
    try {
      const body = { order_id: Number(ticket.id), action };
      if (action === 'share') {
        const permission = await Location.requestForegroundPermissionsAsync();
        if (permission.status !== 'granted') throw new Error('Allow location access to share your current delivery location.');
        const currentLocation = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
        body.latitude = currentLocation.coords.latitude;
        body.longitude = currentLocation.coords.longitude;
        body.accuracy = currentLocation.coords.accuracy;
      }
      await apiRequest(apiBase, 'customer_delivery_location.php', { method: 'POST', token, body });
      await loadShopData(true);
    } catch (locationActionError) {
      if (locationActionError.status === 401) await onSessionExpired();
      setLocationError((current) => ({ ...current, [ticketId]: locationActionError.message || 'Could not update location sharing.' }));
    } finally {
      setLocationBusy((current) => ({ ...current, [ticketId]: false }));
    }
  };

  useEffect(() => {
    const timer = setTimeout(() => { void loadShopData(); }, 0);
    return () => clearTimeout(timer);
  }, [loadShopData]);

  useEffect(() => {
    const hasActiveDelivery = tickets.some((ticket) => ticket.order_type === 'delivery_request' && ticket.status === 'out_for_delivery');
    if (tab !== 'track' || !hasActiveDelivery) return undefined;
    const timer = setInterval(() => { loadShopData(true); }, 12000);
    return () => clearInterval(timer);
  }, [loadShopData, tab, tickets]);

  const sharedDeliveryIds = tickets
    .filter((ticket) => ticket.order_type === 'delivery_request' && ticket.status === 'out_for_delivery' && ticket.tracking?.customer_location_shared)
    .map((ticket) => Number(ticket.id))
    .filter((id) => Number.isInteger(id) && id > 0);
  const sharedDeliveryKey = sharedDeliveryIds.join(',');

  useEffect(() => {
    if (!sharedDeliveryKey) return undefined;
    let cancelled = false;
    let subscription = null;
    (async () => {
      try {
        const permission = await Location.getForegroundPermissionsAsync();
        if (cancelled || permission.status !== 'granted') return;
        subscription = await Location.watchPositionAsync(
          { accuracy: Location.Accuracy.Balanced, timeInterval: 15000, distanceInterval: 25 },
          (location) => {
            const updates = sharedDeliveryKey.split(',').map((id) => apiRequest(apiBase, 'customer_delivery_location.php', {
              method: 'POST',
              token,
              body: {
                order_id: Number(id),
                action: 'share',
                latitude: location.coords.latitude,
                longitude: location.coords.longitude,
                accuracy: location.coords.accuracy,
              },
            }).catch(async (updateError) => {
              if (updateError.status === 401) await onSessionExpired();
              else if (updateError.status === 409) void loadShopData(true);
            }));
            void Promise.all(updates);
          },
        );
        if (cancelled) subscription.remove();
      } catch (_) {
        // Keep the last shared location visible if live updates are unavailable.
      }
    })();
    return () => {
      cancelled = true;
      subscription?.remove();
    };
  }, [apiBase, loadShopData, onSessionExpired, sharedDeliveryKey, token]);
  const visibleServices = useMemo(() => services.filter((service) => {
    const matchesCategory = filter === 'All' || serviceCategory(service) === filter;
    const query = search.trim().toLowerCase();
    const matchesSearch = !query || `${service.name || ''} ${service.category || ''} ${service.description || ''}`.toLowerCase().includes(query);
    return matchesCategory && matchesSearch;
  }), [filter, search, services]);

  const estimate = useMemo(() => services.reduce((total, service) => {
    const id = String(service.id);
    const quantity = Number(quantities[id] || 0);
    return total + (Number(service.price) || 0) * (Number.isFinite(quantity) ? quantity : 0);
  }, 0), [quantities, services]);

  const updateQuantity = (id, value) => {
    const clean = String(value).replace(/[^0-9.]/g, '');
    setQuantities((current) => ({ ...current, [String(id)]: clean }));
  };

  const confirmPickup = () => {
    const selected = new Date(`${pickupDate}T${pickupTime}:00`);
    if (!pickupDate || !pickupTime || Number.isNaN(selected.getTime())) {
      setPickupError('Enter a valid pickup date and time.');
      return;
    }
    if (selected <= new Date()) {
      setPickupError('Choose a pickup time in the future.');
      return;
    }
    setPickupAt(selected.toISOString());
    setPickupModal(false);
    setPickupError('');
  };

  const bookService = async () => {
    const chosen = services
      .map((service) => ({ service_id: Number(service.id), quantity: Number(quantities[String(service.id)] || 0) }))
      .filter((line) => line.quantity > 0);
    if (!chosen.length) return setError('Add at least one service before booking.');
    if (fulfillment === 'delivery' && !deliveryAddress.trim()) return setError('Enter the address where the clean laundry should be delivered.');
    if (paymentMethod === 'gcash') {
      if (!gcashInfo?.configured) return setError('The shop has not configured GCash payments yet. Choose Pay at shop or contact the shop.');
      if (gcashReference.trim().length < 6) return setError('Enter the GCash reference number from your completed transfer.');
    }
    setSending(true);
    setError('');
    try {
      const result = await apiRequest(apiBase, 'tickets.php', {
        method: 'POST',
        token,
        body: {
          services: chosen,
          fulfillment_type: fulfillment,
          ...(fulfillment === 'delivery' ? { delivery_address: deliveryAddress.trim() } : {}),
          expected_pickup: pickupAt,
          notes: notes.trim(),
          payment_method: paymentMethod,
          ...(paymentMethod === 'gcash' ? { payment_amount: Number(estimate.toFixed(2)), payment_reference: gcashReference.trim() } : {}),
        },
      });
      setQuantities({});
      setNotes('');
      setPickupAt(null);
      setPaymentMethod('at_shop');
      setGcashReference('');
      navigateTo('track', result.ticket.ticket_number);
    } catch (bookError) {
      if (bookError.status === 401) await onSessionExpired();
      setError(bookError.message || 'Could not submit the booking.');
    } finally {
      setSending(false);
    }
  };

  const resubmitGcashReference = async (ticket) => {
    const ticketId = String(ticket.id);
    const reference = String(retryReferences[ticketId] || '').trim();
    if (reference.length < 6) {
      setError('Enter a GCash reference with at least 6 characters.');
      return;
    }
    setRetryBusy((current) => ({ ...current, [ticketId]: true }));
    setError('');
    try {
      await apiRequest(apiBase, 'gcash_payment.php', {
        method: 'POST', token,
        body: { order_id: Number(ticket.id), payment_amount: Number(ticket.total), reference_number: reference },
      });
      setRetryReferences((current) => ({ ...current, [ticketId]: '' }));
      await loadShopData(true);
    } catch (retryError) {
      if (retryError.status === 401) await onSessionExpired();
      setError(retryError.message || 'Could not submit the GCash reference.');
    } finally {
      setRetryBusy((current) => ({ ...current, [ticketId]: false }));
    }
  };

  const filters = ['All', 'Wash', 'Dry', 'Full service', 'Add-ons'];

  return (
    <View style={styles.homeRoot}>
      <BrandBar onSignOut={onSignOut} />
      {tab === 'book' ? (
        <ScrollView contentContainerStyle={styles.homeScroll} keyboardShouldPersistTaps="handled">
          <View style={styles.pageWidth}>
            <HeroBanner />
            <View style={styles.welcomeLine}>
              <View>
                <Text style={styles.greeting}>Hello, {customer.name || 'Customer'}</Text>
                <Text style={styles.welcomeCopy}>Choose your laundry service and how you want it handled.</Text>
              </View>
              <View style={styles.pickupBadge}><Text style={styles.pickupBadgeText}>PICKUP OR DELIVERY</Text></View>
            </View>

            {toast ? <Text style={styles.successBox}>{toast}</Text> : null}
            {error ? <Text style={styles.errorBox}>{error}</Text> : null}

            <View style={styles.searchRow}>
              <Text style={styles.searchIcon}>⌕</Text>
              <TextInput value={search} onChangeText={setSearch} placeholder="Search laundry services…" placeholderTextColor="#a59ba5" style={styles.searchInput} />
              <Pressable onPress={loadShopData} style={styles.refreshButton}><Text style={styles.refreshText}>↻</Text></Pressable>
            </View>
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.filtersRow}>
              {filters.map((item) => (
                <Pressable key={item} onPress={() => setFilter(item)} style={[styles.filterChip, filter === item && styles.filterChipActive]}>
                  <Text style={[styles.filterText, filter === item && styles.filterTextActive]}>{item}</Text>
                </Pressable>
              ))}
            </ScrollView>

            {loading ? (
              <View style={styles.inlineLoading}><ActivityIndicator color={COLORS.purple} /><Text style={styles.mutedText}>Loading shop services…</Text></View>
            ) : visibleServices.length ? (
              <View style={styles.serviceGrid}>
                {visibleServices.map((service, index) => (
                  <ServiceCard
                    key={service.id}
                    service={service}
                    quantity={quantities[String(service.id)] || ''}
                    onQuantityChange={(value) => updateQuantity(service.id, value)}
                    wide={wide}
                    index={index}
                  />
                ))}
              </View>
            ) : (
              <View style={styles.emptyCard}><Text style={styles.emptyEmoji}>🫧</Text><Text style={styles.emptyTitle}>No services found</Text><Text style={styles.mutedText}>Try another search or category.</Text></View>
            )}

            <View style={styles.bookingPanel}>
              <Text style={styles.panelTitle}>Service handoff</Text>
              <View style={styles.fulfillmentRow}>
                <Pressable onPress={() => setFulfillment('pickup')} style={[styles.fulfillmentChoice, fulfillment === 'pickup' && styles.fulfillmentChoiceActive]}>
                  <Text style={[styles.fulfillmentChoiceText, fulfillment === 'pickup' && styles.fulfillmentChoiceTextActive]}>Pickup from my address</Text>
                </Pressable>
                <Pressable onPress={() => setFulfillment('delivery')} style={[styles.fulfillmentChoice, fulfillment === 'delivery' && styles.fulfillmentChoiceActive]}>
                  <Text style={[styles.fulfillmentChoiceText, fulfillment === 'delivery' && styles.fulfillmentChoiceTextActive]}>Deliver to me</Text>
                </Pressable>
              </View>
              {fulfillment === 'delivery' ? (
                <View>
                  <Text style={styles.addressLabel}>Delivery address</Text>
                  <TextInput value={deliveryAddress} onChangeText={setDeliveryAddress} maxLength={255} placeholder="Street, barangay, city" placeholderTextColor="#a59ba5" style={styles.deliveryAddressInput} />
                  <Text style={styles.addressHelp}>The shop will use this address for the delivery and live tracking.</Text>
                </View>
              ) : null}
              <View style={styles.panelTitleRow}>
                <Text style={styles.panelIcon}>🗓</Text>
                <View style={styles.flexOne}><Text style={styles.panelTitle}>Preferred schedule</Text><Text style={styles.panelSub}>The shop will confirm the {fulfillment === 'delivery' ? 'delivery' : 'pickup'} time.</Text></View>
                <Text style={styles.pickupBadgeText}>OPTIONAL</Text>
              </View>
              <Pressable onPress={() => {
                const tomorrow = new Date();
                tomorrow.setDate(tomorrow.getDate() + 1);
                const localDate = [tomorrow.getFullYear(), String(tomorrow.getMonth() + 1).padStart(2, '0'), String(tomorrow.getDate()).padStart(2, '0')].join('-');
                setPickupDate(localDate);
                setPickupTime('09:00');
                setPickupError('');
                setPickupModal(true);
              }} style={styles.pickupButton}>
                <Text style={styles.pickupButtonText}>{pickupAt ? (fulfillment === 'delivery' ? 'Delivery: ' : 'Pickup: ') + new Date(pickupAt).toLocaleString() : '＋  Select a ' + (fulfillment === 'delivery' ? 'delivery' : 'pickup') + ' date and time'}</Text>
              </Pressable>
              {pickupAt ? <Pressable onPress={() => setPickupAt(null)}><Text style={styles.clearSchedule}>Remove suggested time</Text></Pressable> : null}
            </View>

            <View style={styles.paymentPanel}>
              <Text style={styles.panelTitle}>Payment method</Text>
              <View style={styles.fulfillmentRow}>
                <Pressable onPress={() => setPaymentMethod('at_shop')} style={[styles.fulfillmentChoice, paymentMethod === 'at_shop' && styles.fulfillmentChoiceActive]}>
                  <Text style={[styles.fulfillmentChoiceText, paymentMethod === 'at_shop' && styles.fulfillmentChoiceTextActive]}>Pay at shop</Text>
                </Pressable>
                <Pressable disabled={!gcashInfo?.configured} onPress={() => setPaymentMethod('gcash')} style={[styles.fulfillmentChoice, paymentMethod === 'gcash' && styles.fulfillmentChoiceActive, !gcashInfo?.configured && styles.disabledButton]}>
                  <Text style={[styles.fulfillmentChoiceText, paymentMethod === 'gcash' && styles.fulfillmentChoiceTextActive]}>GCash transfer</Text>
                </Pressable>
              </View>
              {gcashInfo?.configured ? (paymentMethod === 'gcash' ? (
                <View style={styles.gcashDetails}>
                  <Text style={styles.gcashInstruction}>Transfer the exact amount below to the Admin GCash account. Then enter the reference from your completed transfer. The shop must verify it before processing your order.</Text>
                  <Text style={styles.gcashAccount}>Account name: {gcashInfo.account_name}</Text>
                  <Text style={styles.gcashAccount}>GCash number: {gcashInfo.number}</Text>
                  {gcashInfo.qr_path ? <Image accessibilityLabel="Admin GCash QR code" source={{ uri: normalizeApiUrl(apiBase).replace('/api', '') + '/' + gcashInfo.qr_path }} resizeMode="contain" style={styles.gcashQr} /> : null}
                  <View style={styles.gcashAmountRow}><Text style={styles.gcashAccount}>Transfer amount</Text><Text style={styles.gcashAmount}>{money(estimate)}</Text></View>
                  <Text style={styles.addressLabel}>GCash reference number</Text>
                  <TextInput value={gcashReference} onChangeText={setGcashReference} maxLength={120} autoCapitalize="characters" placeholder="Enter the completed transfer reference" placeholderTextColor="#a59ba5" style={styles.deliveryAddressInput} />
                </View>
              ) : <Text style={styles.addressHelp}>You can pay the shop after your laundry service is confirmed.</Text>) : (
                <Text style={styles.addressHelp}>The shop has not added its GCash account yet. Pay at the shop is available.</Text>
              )}
            </View>

            <View style={styles.notesPanel}>
              <Text style={styles.panelTitle}>Special instructions</Text>
              <TextInput value={notes} onChangeText={(value) => setNotes(value.slice(0, 255))} multiline maxLength={255} placeholder="Add a note for the shop (optional)" placeholderTextColor="#a59ba5" style={styles.notesInput} />
              <Text style={styles.noteCount}>{notes.length}/255</Text>
              <View style={styles.estimateRow}><Text style={styles.estimateLabel}>Estimated total</Text><Text style={styles.estimateValue}>{money(estimate)}</Text></View>
              <Pressable onPress={bookService} disabled={sending || estimate <= 0 || (paymentMethod === 'gcash' && gcashReference.trim().length < 6)} style={[styles.bookButton, (sending || estimate <= 0 || (paymentMethod === 'gcash' && gcashReference.trim().length < 6)) && styles.bookButtonDisabled]}>
                {sending ? <ActivityIndicator color={COLORS.white} /> : <Text style={styles.bookButtonText}>➤  Book Service</Text>}
              </Pressable>
              <Text style={styles.estimateNote}>This is an estimate. The shop will confirm your service, price, and {fulfillment === 'delivery' ? 'delivery' : 'pickup'} schedule.</Text>
            </View>
          </View>
        </ScrollView>
      ) : (
        <ScrollView contentContainerStyle={styles.homeScroll}>
          <View style={styles.pageWidth}>
            <HeroBanner compact />
            <View style={styles.ticketHeadingRow}>
              <View><Text style={styles.greeting}>My service tickets</Text><Text style={styles.welcomeCopy}>Track the status of your laundry bookings.</Text></View>
              <Pressable onPress={loadShopData} style={styles.refreshButton}><Text style={styles.refreshText}>↻</Text></Pressable>
            </View>
            {toast ? <Text style={styles.successBox}>{toast}</Text> : null}
            {error ? <Text style={styles.errorBox}>{error}</Text> : null}
            {loading ? <View style={styles.inlineLoading}><ActivityIndicator color={COLORS.purple} /><Text style={styles.mutedText}>Loading your tickets…</Text></View> : null}
            {!loading && tickets.length === 0 ? <View style={styles.emptyCard}><Text style={styles.emptyEmoji}>📋</Text><Text style={styles.emptyTitle}>No service tickets yet</Text><Text style={styles.mutedText}>Your bookings will appear here after you book a service.</Text><Pressable onPress={() => navigateTo('book')} style={styles.smallPrimary}><Text style={styles.smallPrimaryText}>Browse services</Text></Pressable></View> : null}
            {tickets.map((ticket) => <TicketCard key={ticket.id || ticket.order_number} ticket={ticket} onShareLocation={() => updateCustomerLocation(ticket, 'share')} onRevokeLocation={() => updateCustomerLocation(ticket, 'revoke')} locationBusy={Boolean(locationBusy[String(ticket.id)])} locationError={locationError[String(ticket.id)]} retryReference={retryReferences[String(ticket.id)] || ''} onRetryReferenceChange={(value) => setRetryReferences((current) => ({ ...current, [String(ticket.id)]: value }))} onResubmitGcash={() => resubmitGcashReference(ticket)} retryBusy={Boolean(retryBusy[String(ticket.id)])} />)}
          </View>
        </ScrollView>
      )}

      <View style={styles.bottomNav}>
        <Pressable onPress={() => navigateTo('book')} style={[styles.navItem, tab === 'book' && styles.navItemActive]}><Text style={styles.navEmoji}>🧺</Text><Text style={[styles.navLabel, tab === 'book' && styles.navLabelActive]}>Book</Text></Pressable>
        <Pressable onPress={() => navigateTo('track')} style={[styles.navItem, tab === 'track' && styles.navItemActive]}><Text style={styles.navEmoji}>📋</Text><Text style={[styles.navLabel, tab === 'track' && styles.navLabelActive]}>Track</Text></Pressable>
      </View>

      <Modal transparent visible={pickupModal} animationType="fade" onRequestClose={() => setPickupModal(false)}>
        <View style={styles.modalShade}>
          <View style={styles.modalCard}>
            <Text style={styles.modalTitle}>Suggest a {fulfillment === 'delivery' ? 'delivery' : 'pickup'} time</Text>
            <Text style={styles.modalCopy}>The shop will confirm your requested schedule.</Text>
            {pickupError ? <Text style={styles.modalError}>{pickupError}</Text> : null}
            <Field label="Date (YYYY-MM-DD)" value={pickupDate} onChangeText={setPickupDate} placeholder="2026-10-08" />
            <Field label="Time (24-hour format)" value={pickupTime} onChangeText={setPickupTime} placeholder="09:00" />
            <View style={styles.modalActions}>
              <Pressable onPress={() => setPickupModal(false)} style={styles.secondaryButton}><Text style={styles.secondaryButtonText}>Cancel</Text></Pressable>
              <Pressable onPress={confirmPickup} style={styles.primaryButton}><Text style={styles.primaryButtonText}>Save time</Text></Pressable>
            </View>
          </View>
        </View>
      </Modal>

    </View>
  );
}

function ServiceCard({ service, quantity, onQuantityChange, wide, index }) {
  const gradients = [
    ['#45234f', '#88568c'],
    ['#362741', '#756283'],
    ['#4d2b51', '#a36a8b'],
    ['#3b334b', '#776c94'],
  ];
  const colors = gradients[index % gradients.length];
  const description = service.description || `Laundry service · ${service.unit || 'unit'}`;
  return (
    <View style={[styles.serviceCard, wide ? styles.serviceCardWide : styles.serviceCardNarrow]}>
      <LinearGradient colors={colors} start={{ x: 0, y: 0 }} end={{ x: 1, y: 0.8 }} style={styles.serviceImageBand}>
        <Text style={styles.serviceHeroEmoji}>{serviceEmoji(service)}</Text>
        <Text style={styles.serviceBandCaption}>{serviceCategory(service).toUpperCase()}</Text>
        <View style={styles.bandBubble} />
      </LinearGradient>
      <View style={styles.serviceCardBody}>
        <View style={styles.serviceTitleRow}>
          <View style={styles.serviceTextBlock}>
            <Text style={styles.serviceTitle}>{service.name || 'Laundry service'}</Text>
            <Text style={styles.serviceDescription} numberOfLines={2}>{description}</Text>
            <Text style={styles.servicePrice}>{money(service.price)} <Text style={styles.serviceUnit}>/ {service.unit || 'unit'}</Text></Text>
          </View>
          <View style={styles.quantityBox}>
            <TextInput
              value={String(quantity)}
              onChangeText={onQuantityChange}
              keyboardType="decimal-pad"
              placeholder="0"
              placeholderTextColor="#9d929c"
              style={styles.quantityInput}
              accessibilityLabel={`Quantity for ${service.name}`}
            />
            <Text style={styles.quantityUnit}>{service.unit || 'unit'}</Text>
          </View>
        </View>
      </View>
    </View>
  );
}

function TicketCard({ ticket, onShareLocation, onRevokeLocation, locationBusy, locationError, retryReference, onRetryReferenceChange, onResubmitGcash, retryBusy }) {
  const lines = (ticket.services || []).map((line) => `${line.service_name} (${line.quantity} ${line.unit})`).join(' · ');
  const status = ticket.order_type === 'delivery_request' && ticket.status === 'ready_for_pickup' ? 'Ready for delivery' : statusLabel(ticket.status);
  const statusColors = /complete|picked_up|delivered/.test(status) ? styles.statusGreen : /cancel/.test(status) ? styles.statusRed : styles.statusPurple;
  return (
    <View style={styles.ticketCard}>
      <LinearGradient colors={['#54245f', '#80528a']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 0 }} style={styles.ticketBand}>
        <Text style={styles.ticketBandEmoji}>🧺</Text>
        <Text style={styles.ticketNumber}>{ticket.order_number || 'Laundry ticket'}</Text>
      </LinearGradient>
      <View style={styles.ticketBody}>
        <View style={styles.ticketStatusRow}><Text style={styles.ticketDate}>{formatDate(ticket.created_at)}</Text><Text style={[styles.statusPill, statusColors]}>{status}</Text></View>
        {lines ? <Text style={styles.ticketLines}>{lines}</Text> : null}
        {ticket.expected_pickup ? <Text style={styles.ticketDetail}>🗓  Suggested {ticket.order_type === 'delivery_request' ? 'delivery' : 'pickup'}: {formatDate(ticket.expected_pickup)}</Text> : null}
        {ticket.gcash_payment_status ? (
          <View style={styles.ticketPaymentPanel}>
            <Text style={styles.trackingTitle}>GCash payment: {ticket.gcash_payment_status === 'verified' ? 'Verified' : ticket.gcash_payment_status === 'rejected' ? 'Needs attention' : 'Waiting for shop verification'}</Text>
            {ticket.gcash_reference_number ? <Text style={styles.ticketDetail}>Reference: {ticket.gcash_reference_number}</Text> : null}
            {ticket.gcash_review_note ? <Text style={styles.ticketDetail}>{ticket.gcash_review_note}</Text> : null}
            {ticket.gcash_payment_status === 'rejected' && ticket.payment_method === 'gcash_pending' ? (
              <>
                <TextInput value={retryReference} onChangeText={onRetryReferenceChange} maxLength={120} autoCapitalize="characters" placeholder="Enter a corrected GCash reference" placeholderTextColor="#8c7d8e" style={styles.retryReferenceInput} />
                <Pressable disabled={retryBusy || String(retryReference).trim().length < 6} onPress={onResubmitGcash} style={[styles.locationShareButton, (retryBusy || String(retryReference).trim().length < 6) && styles.disabledButton]}>
                  {retryBusy ? <ActivityIndicator color={COLORS.white} /> : <Text style={styles.locationShareButtonText}>Submit corrected reference</Text>}
                </Pressable>
              </>
            ) : null}
          </View>
        ) : null}
        {ticket.order_type === 'delivery_request' ? (
          <View style={styles.deliveryTrackingPanel}>
            <Text style={styles.trackingTitle}>Delivery to your address</Text>
            {ticket.delivery_address ? <Text style={styles.ticketDetail}>{ticket.delivery_address}</Text> : null}
            {ticket.status === 'out_for_delivery' ? (
              <View style={styles.customerLocationPanel}>
                <Text style={styles.ticketDetail}>Live location sharing is optional. When enabled, your location updates while this app is open and stops when delivery ends. You can revoke it any time.</Text>
                {ticket.tracking?.customer_location_shared ? <Text style={styles.locationSharedText}>Your location is shared. Last updated: {formatDate(ticket.tracking.customer_location_updated_at)}</Text> : null}
                {locationError ? <Text style={styles.errorBox}>{locationError}</Text> : null}
                <Pressable disabled={locationBusy} onPress={onShareLocation} style={[styles.locationShareButton, locationBusy && styles.disabledButton]}>
                  {locationBusy ? <ActivityIndicator color={COLORS.white} /> : <Text style={styles.locationShareButtonText}>{ticket.tracking?.customer_location_shared ? 'Update my location now' : 'Share my live location with driver'}</Text>}
                </Pressable>
                {ticket.tracking?.customer_location_shared ? <Pressable disabled={locationBusy} onPress={onRevokeLocation} style={styles.locationRevokeButton}><Text style={styles.locationRevokeText}>Stop sharing my location</Text></Pressable> : null}
              </View>
            ) : null}
            {ticket.status === 'out_for_delivery' && ticket.tracking?.active ? (
              ticket.tracking.location ? (
                <>
                  <Text style={styles.trackingStatus}>{ticket.tracking.location.stale ? 'Last location update is delayed.' : 'Your driver is on the way.'}</Text>
                  <DeliveryMap location={ticket.tracking.location} />
                  <Text style={styles.trackingUpdated}>Last updated: {formatDate(ticket.tracking.location.updated_at)}</Text>
                </>
              ) : <Text style={styles.ticketDetail}>Your driver has started the delivery. Waiting for the first location update.</Text>
            ) : (
              <Text style={styles.ticketDetail}>{ticket.status === 'completed' || ticket.status === 'picked_up' ? 'Delivery completed. Live location sharing has stopped.' : ticket.status === 'cancelled' ? 'This delivery was cancelled. Live location sharing has stopped.' : ticket.tracking?.stopped ? 'The driver stopped live location sharing. Contact the shop if needed.' : ticket.status === 'out_for_delivery' ? 'Waiting for the driver to start sharing location.' : 'Live driver tracking will appear when the shop sends your order out for delivery.'}</Text>
            )}
          </View>
        ) : null}
        <View style={styles.ticketTotals}>
          <View><Text style={styles.ticketTotalLabel}>Estimated total</Text><Text style={styles.ticketTotalValue}>{money(ticket.total)}</Text></View>
          <View style={styles.ticketBalance}><Text style={styles.ticketTotalLabel}>Balance due</Text><Text style={styles.ticketTotalValue}>{money(ticket.balance_due)}</Text></View>
        </View>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  app: { flex: 1, backgroundColor: COLORS.dark },
  loadingScreen: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 18, backgroundColor: COLORS.dark },
  brandMark: { fontSize: 44 },
  loadingText: { color: '#e6dbe9', fontSize: 15 },
  topBar: { minHeight: 62, paddingHorizontal: 22, backgroundColor: COLORS.darkPanel, borderBottomColor: '#3b303e', borderBottomWidth: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', zIndex: 2 },
  brandLockup: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  miniLogo: { width: 34, height: 34, borderRadius: 12, alignItems: 'center', justifyContent: 'center', backgroundColor: '#f3e9f5' },
  miniLogoText: { fontSize: 19 },
  topTitle: { color: COLORS.cream, fontSize: 17, fontWeight: '700' },
  topActions: { flexDirection: 'row', gap: 8 },
  headerButton: { width: 38, height: 38, borderRadius: 14, alignItems: 'center', justifyContent: 'center', backgroundColor: '#362c39' },
  headerButtonText: { color: '#f6eaf9', fontSize: 22, fontWeight: '700' },
  authRoot: { flex: 1, backgroundColor: COLORS.dark },
  authScroll: { flexGrow: 1, padding: 16, alignItems: 'center', justifyContent: 'center' },
  authContainer: { maxWidth: 520, alignSelf: 'center', gap: 16, paddingBottom: 16 },
  hero: { minHeight: 184, borderRadius: 20, overflow: 'hidden', justifyContent: 'center', paddingHorizontal: 24, marginTop: 16, marginBottom: 20 },
  heroCompact: { minHeight: 145, marginTop: 4, marginBottom: 4, paddingHorizontal: 20 },
  heroContent: { flexDirection: 'row', alignItems: 'center', gap: 16, zIndex: 1 },
  heroIcon: { width: 70, height: 70, borderRadius: 22, backgroundColor: '#fff7e9', alignItems: 'center', justifyContent: 'center', transform: [{ rotate: '-5deg' }] },
  heroEmoji: { fontSize: 38 },
  heroTextBlock: { flex: 1 },
  heroTitle: { color: COLORS.white, fontWeight: '800', fontSize: 25, marginBottom: 7 },
  heroDetail: { color: '#f5e9f3', fontSize: 14, marginTop: 2 },
  heroFloatingEmoji: { fontSize: 44, opacity: 0.95 },
  heroDecorOne: { position: 'absolute', width: 220, height: 220, borderRadius: 110, backgroundColor: '#ffffff12', right: 22, top: -120 },
  heroDecorTwo: { position: 'absolute', width: 160, height: 160, borderRadius: 80, backgroundColor: '#e0a8e218', right: 145, bottom: -117 },
  authIntro: { alignItems: 'center', paddingHorizontal: 16 },
  authTitle: { color: COLORS.cream, fontSize: 25, fontWeight: '800', textAlign: 'center' },
  authDescription: { color: '#c7bac8', textAlign: 'center', marginTop: 6, lineHeight: 21, maxWidth: 410 },
  authCard: { borderRadius: 20, backgroundColor: COLORS.cream, borderColor: '#eadfed', borderWidth: 1, padding: 20, gap: 12, ...(Platform.OS === 'web' ? { boxShadow: '0px 8px 16px rgba(0,0,0,0.22)' } : { shadowColor: '#000', shadowOpacity: 0.22, shadowRadius: 16, shadowOffset: { width: 0, height: 8 }, elevation: 6 }) },
  authSwitch: { flexDirection: 'row', borderRadius: 15, borderColor: COLORS.line, borderWidth: 1, padding: 4, backgroundColor: '#f2ebf3', marginBottom: 2 },
  authSwitchButton: { flex: 1, minHeight: 42, alignItems: 'center', justifyContent: 'center', borderRadius: 12 },
  authSwitchSelected: { backgroundColor: COLORS.plum },
  authSwitchText: { color: COLORS.muted, fontWeight: '700', fontSize: 14 },
  authSwitchSelectedText: { color: COLORS.white },
  fieldWrap: { gap: 6, marginBottom: 2 },
  fieldLabel: { color: '#514653', fontSize: 13, fontWeight: '700' },
  inputShell: { flexDirection: 'row', alignItems: 'center', borderRadius: 12, borderColor: '#cfc2d1', borderWidth: 1, backgroundColor: '#fffdf9', minHeight: 47 },
  input: { flex: 1, minWidth: 0, paddingHorizontal: 13, paddingVertical: 11, color: COLORS.ink, fontSize: 15, outlineStyle: 'none' },
  inputRight: { paddingHorizontal: 12 },
  showPassword: { color: COLORS.plum, fontWeight: '700', fontSize: 13 },
  primaryButton: { flex: 1, minHeight: 46, alignItems: 'center', justifyContent: 'center', backgroundColor: COLORS.plum, borderRadius: 14, paddingHorizontal: 14, paddingVertical: 12 },
  primaryButtonText: { color: COLORS.white, fontSize: 15, fontWeight: '800' },
  secondaryButton: { flex: 1, minHeight: 46, alignItems: 'center', justifyContent: 'center', borderRadius: 14, borderColor: COLORS.line, borderWidth: 1, backgroundColor: COLORS.cream, paddingHorizontal: 14 },
  secondaryButtonText: { color: COLORS.ink, fontWeight: '700' },
  pressed: { opacity: 0.82, transform: [{ scale: 0.99 }] },
  disabledButton: { opacity: 0.58 },
  authFootnote: { color: COLORS.muted, fontSize: 12, lineHeight: 17, textAlign: 'center' },
  driverSignInLink: { alignSelf: 'center', paddingVertical: 5 },
  driverSignInText: { color: COLORS.plum, fontWeight: '700', fontSize: 13 },
  errorBox: { color: '#7d1d2b', backgroundColor: '#fbe6e8', borderRadius: 12, paddingHorizontal: 13, paddingVertical: 11, fontSize: 14, lineHeight: 20, marginVertical: 8 },
  successBox: { color: '#314e39', backgroundColor: '#e3f0e5', borderRadius: 12, paddingHorizontal: 13, paddingVertical: 11, fontSize: 14, lineHeight: 20, marginVertical: 8 },
  homeRoot: { flex: 1, backgroundColor: COLORS.dark },
  homeScroll: { paddingHorizontal: 16, paddingBottom: 22, alignItems: 'center' },
  pageWidth: { width: '100%', maxWidth: 1180 },
  welcomeLine: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginBottom: 13 },
  greeting: { color: COLORS.cream, fontSize: 23, fontWeight: '800' },
  welcomeCopy: { color: '#c7bac8', fontSize: 14, marginTop: 3, lineHeight: 20 },
  pickupBadge: { backgroundColor: '#34273a', borderColor: '#6e4a78', borderWidth: 1, borderRadius: 20, paddingHorizontal: 13, paddingVertical: 8 },
  pickupBadgeText: { color: '#d9bce0', fontSize: 10, letterSpacing: 0.7, fontWeight: '800' },
  searchRow: { minHeight: 48, flexDirection: 'row', alignItems: 'center', backgroundColor: '#211c23', borderColor: '#443b46', borderWidth: 1, borderRadius: 24, paddingLeft: 15, paddingRight: 5, marginTop: 4 },
  searchIcon: { color: '#d9cadc', fontSize: 26, marginRight: 8 },
  searchInput: { flex: 1, color: COLORS.cream, paddingVertical: 9, fontSize: 14, outlineStyle: 'none' },
  refreshButton: { width: 40, height: 40, alignItems: 'center', justifyContent: 'center', borderRadius: 15, backgroundColor: '#302734' },
  refreshText: { color: '#eaddf0', fontSize: 23, lineHeight: 27 },
  filtersRow: { gap: 8, paddingVertical: 14 },
  filterChip: { paddingHorizontal: 16, paddingVertical: 10, borderRadius: 22, borderWidth: 1, borderColor: '#4b414d', backgroundColor: '#211c23' },
  filterChipActive: { backgroundColor: COLORS.purple, borderColor: COLORS.purple },
  filterText: { color: '#d2c7d2', fontSize: 13, fontWeight: '600' },
  filterTextActive: { color: COLORS.white, fontWeight: '800' },
  serviceGrid: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', gap: 16, paddingBottom: 18 },
  serviceCard: { borderRadius: 17, overflow: 'hidden', backgroundColor: COLORS.cream, borderColor: '#ddd2df', borderWidth: 1, ...(Platform.OS === 'web' ? { boxShadow: '0px 3px 8px rgba(0,0,0,0.14)' } : { shadowColor: '#000', shadowOpacity: 0.14, shadowRadius: 8, shadowOffset: { width: 0, height: 3 }, elevation: 2 }) },
  serviceCardWide: { width: '48.8%' },
  serviceCardNarrow: { width: '100%' },
  serviceImageBand: { height: 90, overflow: 'hidden', flexDirection: 'row', alignItems: 'center', paddingHorizontal: 20, justifyContent: 'space-between' },
  serviceHeroEmoji: { fontSize: 42, zIndex: 1 },
  serviceBandCaption: { color: '#fff9f5', fontWeight: '800', fontSize: 12, letterSpacing: 1, zIndex: 1 },
  bandBubble: { position: 'absolute', width: 125, height: 125, right: 35, top: -76, borderRadius: 80, backgroundColor: '#ffffff1a' },
  serviceCardBody: { padding: 15 },
  serviceTitleRow: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  serviceTextBlock: { flex: 1, minWidth: 0, gap: 3 },
  serviceTitle: { color: COLORS.ink, fontSize: 16, fontWeight: '800' },
  serviceDescription: { color: '#716974', fontSize: 12, minHeight: 17, lineHeight: 16 },
  servicePrice: { color: COLORS.copper, fontSize: 15, fontWeight: '900', marginTop: 4 },
  serviceUnit: { color: '#786f78', fontSize: 12, fontWeight: '600' },
  quantityBox: { width: 82, borderRadius: 12, borderWidth: 1, borderColor: '#d5c8d7', backgroundColor: '#fffdf9', alignItems: 'center', padding: 6 },
  quantityInput: { width: '100%', color: COLORS.ink, textAlign: 'center', fontWeight: '800', fontSize: 16, padding: 4, outlineStyle: 'none' },
  quantityUnit: { color: COLORS.muted, fontSize: 10, marginTop: 1 },
  inlineLoading: { paddingVertical: 36, alignItems: 'center', justifyContent: 'center', gap: 10 },
  mutedText: { color: '#bcb0bd', fontSize: 13, lineHeight: 19, textAlign: 'center' },
  emptyCard: { borderRadius: 18, backgroundColor: '#241d26', borderColor: '#453a48', borderWidth: 1, padding: 25, alignItems: 'center', gap: 8, marginVertical: 12 },
  emptyEmoji: { fontSize: 34 },
  emptyTitle: { color: COLORS.cream, fontWeight: '800', fontSize: 17 },
  bookingPanel: { padding: 16, borderRadius: 18, backgroundColor: '#241d26', borderColor: '#443744', borderWidth: 1, marginBottom: 12 },
  paymentPanel: { padding: 16, borderRadius: 18, backgroundColor: '#241d26', borderColor: '#443744', borderWidth: 1, marginBottom: 12 },
  gcashDetails: { gap: 8, marginTop: 4 },
  gcashInstruction: { color: '#d3c5d4', fontSize: 12, lineHeight: 18, marginBottom: 4 },
  gcashAccount: { color: '#e7dce8', fontSize: 13, fontWeight: '700' },
  gcashQr: { width: 220, height: 220, alignSelf: 'center', backgroundColor: '#ffffff', borderRadius: 12, marginVertical: 6 },
  gcashAmountRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', borderTopColor: '#514454', borderTopWidth: 1, paddingTop: 10, marginTop: 2 },
  gcashAmount: { color: '#e2b378', fontSize: 19, fontWeight: '900' },
  fulfillmentRow: { flexDirection: 'row', gap: 10, marginVertical: 10 },
  fulfillmentChoice: { flex: 1, minHeight: 42, borderRadius: 13, borderWidth: 1, borderColor: '#6e4a78', alignItems: 'center', justifyContent: 'center', paddingHorizontal: 8 },
  fulfillmentChoiceActive: { backgroundColor: COLORS.plum, borderColor: COLORS.plum },
  fulfillmentChoiceText: { color: '#d9bce0', fontWeight: '700', fontSize: 13, textAlign: 'center' },
  fulfillmentChoiceTextActive: { color: COLORS.white },
  addressLabel: { color: '#e4d6e6', fontSize: 12, fontWeight: '700', marginBottom: 6 },
  deliveryAddressInput: { minHeight: 46, paddingHorizontal: 12, borderRadius: 12, borderColor: '#6e4a78', borderWidth: 1, color: COLORS.cream, backgroundColor: '#211c23' },
  addressHelp: { color: '#bcb0bd', fontSize: 11, marginTop: 5, marginBottom: 10 },
  panelTitleRow: { flexDirection: 'row', alignItems: 'center', gap: 10, marginBottom: 12 },
  panelIcon: { fontSize: 25 },
  flexOne: { flex: 1 },
  panelTitle: { color: COLORS.cream, fontSize: 15, fontWeight: '800' },
  panelSub: { color: '#bcb0bd', fontSize: 12, marginTop: 3 },
  pickupButton: { minHeight: 43, borderColor: '#8a6990', borderWidth: 1, borderRadius: 23, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 12 },
  pickupButtonText: { color: '#e2cfe8', fontSize: 13, fontWeight: '600', textAlign: 'center' },
  clearSchedule: { color: '#d9bce0', fontSize: 12, fontWeight: '700', textAlign: 'center', paddingTop: 9 },
  notesPanel: { padding: 16, borderRadius: 18, backgroundColor: COLORS.cream, borderColor: '#ded3df', borderWidth: 1, marginBottom: 20 },
  notesInput: { borderColor: '#d0c3d1', borderWidth: 1, borderRadius: 12, minHeight: 78, padding: 12, textAlignVertical: 'top', color: COLORS.ink, backgroundColor: '#fffdf9', marginTop: 10, outlineStyle: 'none' },
  noteCount: { color: COLORS.muted, fontSize: 11, textAlign: 'right', marginTop: 4 },
  estimateRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 10, marginBottom: 12 },
  estimateLabel: { color: COLORS.ink, fontSize: 15, fontWeight: '700' },
  estimateValue: { color: COLORS.plum, fontSize: 19, fontWeight: '900' },
  bookButton: { minHeight: 48, borderRadius: 15, backgroundColor: COLORS.plum, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 18 },
  bookButtonDisabled: { backgroundColor: '#777078' },
  bookButtonText: { color: COLORS.white, fontSize: 15, fontWeight: '800' },
  estimateNote: { color: COLORS.muted, fontSize: 11, lineHeight: 16, textAlign: 'center', marginTop: 9 },
  bottomNav: { minHeight: 62, backgroundColor: COLORS.cream, borderTopColor: '#ded3df', borderTopWidth: 1, flexDirection: 'row', justifyContent: 'center', gap: 24, paddingHorizontal: 20, zIndex: 3 },
  navItem: { minWidth: 120, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, borderTopWidth: 3, borderTopColor: 'transparent' },
  navItemActive: { borderTopColor: COLORS.copper },
  navEmoji: { fontSize: 18 },
  navLabel: { color: '#776c78', fontSize: 13, fontWeight: '700' },
  navLabelActive: { color: COLORS.plum },
  ticketHeadingRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 4, marginBottom: 14 },
  ticketCard: { borderRadius: 17, overflow: 'hidden', backgroundColor: COLORS.cream, borderColor: '#ddd2df', borderWidth: 1, marginBottom: 14 },
  ticketBand: { minHeight: 53, flexDirection: 'row', alignItems: 'center', paddingHorizontal: 15, gap: 10 },
  ticketBandEmoji: { fontSize: 20 },
  ticketNumber: { flex: 1, color: COLORS.white, fontWeight: '800', fontSize: 15 },
  ticketBody: { padding: 15 },
  customerLocationPanel: { gap: 8, borderRadius: 12, backgroundColor: '#eee5f0', padding: 12, marginTop: 8 },
  locationSharedText: { color: COLORS.green, fontSize: 12, fontWeight: '700' },
  locationShareButton: { minHeight: 42, borderRadius: 11, backgroundColor: COLORS.plum, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 12, paddingVertical: 9 },
  locationShareButtonText: { color: COLORS.white, fontWeight: '800', fontSize: 12, textAlign: 'center' },
  locationRevokeButton: { alignSelf: 'flex-start', paddingVertical: 5 },
  locationRevokeText: { color: COLORS.red, fontWeight: '700', fontSize: 12 },  deliveryTrackingPanel: { gap: 9, borderRadius: 13, borderColor: '#e0d2e5', borderWidth: 1, backgroundColor: '#f7eff8', padding: 13, marginTop: 13 },
  trackingTitle: { color: COLORS.plum, fontSize: 14, fontWeight: '800' },
  trackingStatus: { color: COLORS.green, fontSize: 13, fontWeight: '700' },
  trackingUpdated: { color: COLORS.muted, fontSize: 11 },
  ticketStatusRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 10 },
  ticketDate: { flex: 1, color: '#766d77', fontSize: 12 },
  statusPill: { textTransform: 'capitalize', overflow: 'hidden', paddingHorizontal: 12, paddingVertical: 6, borderRadius: 20, fontSize: 12, fontWeight: '800' },
  statusPurple: { color: COLORS.plum, backgroundColor: '#efe3f1' },
  statusGreen: { color: '#305438', backgroundColor: '#e1eee1' },
  statusRed: { color: '#8d2936', backgroundColor: '#f9e2e4' },
  ticketLines: { color: COLORS.ink, fontSize: 14, lineHeight: 21, marginTop: 12 },
  ticketPaymentPanel: { gap: 5, borderRadius: 12, backgroundColor: '#f3eaf4', padding: 12, marginTop: 12 },
  retryReferenceInput: { minHeight: 44, paddingHorizontal: 11, borderRadius: 10, borderColor: '#cfc2d1', borderWidth: 1, color: COLORS.ink, backgroundColor: '#fffdf9', marginTop: 5, outlineStyle: 'none' },
  ticketDetail: { color: '#665d67', fontSize: 12, marginTop: 8 },
  ticketTotals: { flexDirection: 'row', justifyContent: 'space-between', borderTopColor: '#e4dce5', borderTopWidth: 1, marginTop: 12, paddingTop: 12 },
  ticketBalance: { alignItems: 'flex-end' },
  ticketTotalLabel: { color: '#796f7b', fontSize: 11 },
  ticketTotalValue: { color: COLORS.ink, fontSize: 15, fontWeight: '900', marginTop: 3 },
  smallPrimary: { paddingVertical: 11, paddingHorizontal: 17, borderRadius: 13, backgroundColor: COLORS.plum, marginTop: 8 },
  smallPrimaryText: { color: COLORS.white, fontWeight: '800', fontSize: 13 },
  modalShade: { flex: 1, backgroundColor: '#09070bcc', alignItems: 'center', justifyContent: 'center', padding: 18 },
  modalCard: { width: '100%', maxWidth: 460, borderRadius: 20, backgroundColor: COLORS.cream, padding: 20, gap: 12, borderColor: '#ded3df', borderWidth: 1 },
  modalTitle: { color: COLORS.ink, fontSize: 20, fontWeight: '900' },
  modalCopy: { color: COLORS.muted, fontSize: 13, lineHeight: 19, marginBottom: 2 },
  modalError: { color: COLORS.red, fontSize: 13, fontWeight: '700' },
  modalActions: { flexDirection: 'row', gap: 10, marginTop: 5 },
});