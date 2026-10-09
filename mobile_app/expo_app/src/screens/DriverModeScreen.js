import * as Location from 'expo-location';
import * as SecureStore from 'expo-secure-store';
import * as TaskManager from 'expo-task-manager';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Alert, KeyboardAvoidingView, Linking, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';
import Constants from 'expo-constants';
import DeliveryMap from '../components/DeliveryMap';
import { DRIVER_API_KEY, DRIVER_LOCATION_TASK, DRIVER_TOKEN_KEY, LEGACY_DELIVERY_LOCATION_TASK } from '../delivery/locationTask';

const API_BASE = (process.env.EXPO_PUBLIC_API_BASE_URL?.trim() || getDevelopmentApi()).replace(/\/+$/, '');

function getDevelopmentApi() {
  const apiPath = '/laundry-pias/api';
  if (Platform.OS === 'web') {
    const host = typeof window !== 'undefined' && window.location.hostname ? window.location.hostname : 'localhost';
    return 'http://' + host + apiPath;
  }
  const hostUri = Constants.expoConfig?.hostUri || '';
  const host = hostUri.startsWith('[') ? hostUri.slice(1, hostUri.indexOf(']')) : hostUri.split(':')[0];
  const isPrivateIpv4 = /^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/.test(host);
  return isPrivateIpv4 ? 'http://' + host + apiPath : 'http://10.0.2.2' + apiPath;
}

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

async function driverRequest(endpoint, { method = 'GET', body, token } = {}) {
  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (token) headers.Authorization = 'Bearer ' + token;
  const response = await fetch(API_BASE + '/' + endpoint, {
    method,
    headers,
    ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
  });
  let data;
  try {
    data = await response.json();
  } catch (_) {
    throw new Error('The shop server returned an unreadable response.');
  }
  if (!response.ok || data.success !== true) {
    const error = new Error(data.error || 'The request could not be completed.');
    error.status = response.status;
    throw error;
  }
  return data;
}

function formatDate(value) {
  if (!value) return 'Not scheduled';
  const parsed = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString();
}

export default function DriverModeScreen() {
  const router = useRouter();
  const [token, setToken] = useState(null);
  const [driver, setDriver] = useState(null);
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [deliveries, setDeliveries] = useState([]);
  const [booting, setBooting] = useState(true);
  const [busy, setBusy] = useState(false);
  const [sharing, setSharing] = useState(false);
  const [backgroundSharing, setBackgroundSharing] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [lastSent, setLastSent] = useState('');
  const foregroundSubscription = useRef(null);

  const loadDeliveries = useCallback(async (activeToken) => {
    const data = await driverRequest('driver_deliveries.php', { token: activeToken });
    setDeliveries(data.deliveries || []);
    return data.deliveries || [];
  }, []);

  useEffect(() => {
    let active = true;
    (async () => {
      if (Platform.OS !== 'web') {
        await Location.stopLocationUpdatesAsync(LEGACY_DELIVERY_LOCATION_TASK).catch(() => {});
        await SecureStore.deleteItemAsync('pias_delivery_driver_code').catch(() => {});
        await SecureStore.deleteItemAsync('pias_delivery_api_base').catch(() => {});
      }
      const savedToken = await storageGet(DRIVER_TOKEN_KEY);
      if (savedToken) {
        try {
          const session = await driverRequest('driver_me.php', { token: savedToken });
          const activeDeliveries = await loadDeliveries(savedToken);
          const taskRunning = Platform.OS !== 'web' && await Location.hasStartedLocationUpdatesAsync(DRIVER_LOCATION_TASK).catch(() => false);
          if (active) {
            setToken(savedToken);
            setDriver(session.driver);
            setSharing(Boolean(taskRunning));
            setBackgroundSharing(Boolean(taskRunning));
            setDeliveries(activeDeliveries);
          }
        } catch (_) {
          await storageDelete(DRIVER_TOKEN_KEY);
          await storageDelete(DRIVER_API_KEY);
        }
      }
      if (active) setBooting(false);
    })();
    return () => {
      active = false;
      foregroundSubscription.current?.remove();
    };
  }, [loadDeliveries]);

  useEffect(() => {
    if (!token) return undefined;
    let active = true;
    const refresh = async () => {
      try {
        await loadDeliveries(token);
      } catch (refreshError) {
        if (active && refreshError.status === 401) {
          await storageDelete(DRIVER_TOKEN_KEY);
          await storageDelete(DRIVER_API_KEY);
          setToken(null);
          setDriver(null);
          setError(refreshError.message);
        }
      }
    };
    void refresh();
    const timer = setInterval(refresh, 10000);
    return () => {
      active = false;
      clearInterval(timer);
    };
  }, [loadDeliveries, token]);

  const signIn = async () => {
    const cleanUsername = username.trim().toLowerCase();
    if (!cleanUsername || !password) {
      setError('Enter the driver username and password supplied by the shop.');
      return;
    }
    setBusy(true);
    setError('');
    setMessage('');
    try {
      const session = await driverRequest('driver_login.php', {
        method: 'POST',
        body: { username: cleanUsername, password },
      });
      await storageSet(DRIVER_TOKEN_KEY, session.token);
      await storageSet(DRIVER_API_KEY, API_BASE);
      setToken(session.token);
      setDriver(session.driver);
      setPassword('');
      await loadDeliveries(session.token);
    } catch (signInError) {
      setError(signInError.message || 'Could not sign in to driver mode.');
    } finally {
      setBusy(false);
    }
  };

  const sendLocation = async (activeToken, location) => {
    await driverRequest('driver_tracking.php', {
      method: 'POST',
      token: activeToken,
      body: {
        action: 'update',
        latitude: location.coords.latitude,
        longitude: location.coords.longitude,
        accuracy: location.coords.accuracy,
        heading: location.coords.heading,
        speed: location.coords.speed,
      },
    });
    setLastSent(new Date().toLocaleTimeString());
  };

  const startSharing = async () => {
    if (Platform.OS === 'web') {
      setError('Location sharing requires the Android or iPhone app. You can still review assigned deliveries here.');
      return;
    }
    if (!token) return;
    if (!deliveries.length) {
      setError('There are no active deliveries assigned to your account yet.');
      return;
    }
    setBusy(true);
    setError('');
    setMessage('');
    try {
      const foreground = await Location.requestForegroundPermissionsAsync();
      if (foreground.status !== 'granted') throw new Error('Allow location access while using the app to start delivery tracking.');
      let allowBackground = await TaskManager.isAvailableAsync().catch(() => false);
      if (allowBackground) {
        allowBackground = await new Promise((resolve) => {
          Alert.alert(
            'Allow background location',
            'The customer can see your location only during your assigned active deliveries. Background access keeps it updated while this app is minimized.',
            [
              { text: 'Keep app open', onPress: () => resolve(false) },
              { text: 'Allow background', onPress: () => resolve(true) },
            ],
            { cancelable: false },
          );
        });
      }
      let backgroundGranted = false;
      if (allowBackground) {
        const background = await Location.requestBackgroundPermissionsAsync();
        backgroundGranted = background.status === 'granted';
      }
      const currentLocation = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
      await sendLocation(token, currentLocation);
      await storageSet(DRIVER_TOKEN_KEY, token);
      await storageSet(DRIVER_API_KEY, API_BASE);
      if (backgroundGranted) {
        await Location.startLocationUpdatesAsync(DRIVER_LOCATION_TASK, {
          accuracy: Location.Accuracy.Balanced,
          timeInterval: 10000,
          distanceInterval: 15,
          pausesUpdatesAutomatically: false,
          foregroundService: {
            notificationTitle: 'Pia delivery tracking is active',
            notificationBody: 'Sharing location for your assigned deliveries',
            notificationColor: '#58265f',
            killServiceOnDestroy: false,
          },
        });
        setSharing(true);
        setBackgroundSharing(true);
        setMessage('Background location sharing is active. A location notification will remain visible until you stop.');
      } else {
        foregroundSubscription.current = await Location.watchPositionAsync(
          { accuracy: Location.Accuracy.Balanced, timeInterval: 10000, distanceInterval: 15 },
          (location) => sendLocation(token, location).catch((sendError) => setError(sendError.message || 'Could not send driver location.')),
        );
        setSharing(true);
        setBackgroundSharing(false);
        setMessage('Location sharing is active while this screen stays open.');
      }
    } catch (startError) {
      setError(startError.message || 'Could not start delivery tracking.');
    } finally {
      setBusy(false);
    }
  };

  const stopSharing = async () => {
    setBusy(true);
    setError('');
    try {
      if (token) await driverRequest('driver_tracking.php', { method: 'POST', token, body: { action: 'stop' } });
    } catch (stopError) {
      if (stopError.status !== 409) setError(stopError.message || 'Could not stop location sharing.');
    }
    if (Platform.OS !== 'web') await Location.stopLocationUpdatesAsync(DRIVER_LOCATION_TASK).catch(() => {});
    foregroundSubscription.current?.remove();
    foregroundSubscription.current = null;
    setSharing(false);
    setBackgroundSharing(false);
    setMessage('Your location sharing has stopped. Your driver account is still signed in.');
    setBusy(false);
    await loadDeliveries(token).catch(() => {});
  };

  const signOut = async () => {
    setBusy(true);
    try {
      if (sharing) await driverRequest('driver_tracking.php', { method: 'POST', token, body: { action: 'stop' } }).catch(() => {});
      await driverRequest('driver_logout.php', { method: 'POST', token, body: {} }).catch(() => {});
    } finally {
      if (Platform.OS !== 'web') await Location.stopLocationUpdatesAsync(DRIVER_LOCATION_TASK).catch(() => {});
      foregroundSubscription.current?.remove();
      foregroundSubscription.current = null;
      await storageDelete(DRIVER_TOKEN_KEY);
      await storageDelete(DRIVER_API_KEY);
      setToken(null);
      setDriver(null);
      setDeliveries([]);
      setSharing(false);
      setBackgroundSharing(false);
      setBusy(false);
      router.replace('/');
    }
  };

  if (booting) return <View style={styles.screen}><ActivityIndicator color="#bd7841" size="large" /></View>;

  return (
    <KeyboardAvoidingView style={styles.screen} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
        <View style={styles.header}>
          <Pressable onPress={() => router.replace('/')} style={styles.backButton}><Text style={styles.backText}>Back</Text></Pressable>
          <View style={styles.flex}>
            <Text style={styles.title}>Delivery driver</Text>
            <Text style={styles.subtitle}>{driver ? 'Signed in as ' + driver.name : 'Sign in with the driver account created by shop staff.'}</Text>
          </View>
          {driver ? <Pressable onPress={signOut} disabled={busy} style={styles.headerAction}><Text style={styles.headerActionText}>Sign out</Text></Pressable> : null}
        </View>
        {error ? <Text style={styles.error}>{error}</Text> : null}
        {message ? <Text style={styles.notice}>{message}</Text> : null}
        {!driver ? (
          <View style={styles.card}>
            <Text style={styles.cardTitle}>Driver sign in</Text>
            <Text style={styles.info}>Customers cannot use driver accounts. Ask a staff member or admin to create your login.</Text>
            <Text style={styles.label}>Username</Text>
            <TextInput value={username} onChangeText={setUsername} placeholder="Driver username" autoCapitalize="none" autoCorrect={false} style={styles.input} />
            <Text style={styles.label}>Password</Text>
            <View style={styles.passwordRow}>
              <TextInput value={password} onChangeText={setPassword} placeholder="Driver password" secureTextEntry={!showPassword} autoCapitalize="none" style={[styles.input, styles.passwordInput]} />
              <Pressable onPress={() => setShowPassword((value) => !value)} style={styles.showButton}><Text style={styles.showButtonText}>{showPassword ? 'Hide' : 'Show'}</Text></Pressable>
            </View>
            <Pressable disabled={busy} onPress={signIn} style={[styles.button, busy && styles.disabled]}>
              {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonText}>Sign in to driver mode</Text>}
            </Pressable>
          </View>
        ) : (
          <>
            <View style={styles.card}>
              <Text style={styles.cardTitle}>Assigned deliveries</Text>
              {deliveries.length ? deliveries.map((delivery) => (
                <View key={delivery.id} style={styles.deliveryCard}>
                  <View style={styles.deliveryHeader}>
                    <Text style={styles.ticket}>{delivery.ticket_number}</Text>
                    <Text style={styles.schedule}>{formatDate(delivery.expected_delivery)}</Text>
                  </View>
                  <Text style={styles.customerName}>{delivery.customer_name}</Text>
                  {delivery.customer_phone ? <Pressable onPress={() => Linking.openURL('tel:' + delivery.customer_phone)}><Text style={styles.phone}>Call {delivery.customer_phone}</Text></Pressable> : null}
                  <Text style={styles.address}>{delivery.delivery_address || 'No delivery address was saved.'}</Text>
                  {delivery.delivery_address ? <Pressable onPress={() => Linking.openURL('https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(delivery.delivery_address))} style={styles.mapLink}><Text style={styles.mapLinkText}>Open delivery address in Maps</Text></Pressable> : null}
                  <View style={styles.customerPin}>
                    <Text style={styles.pinTitle}>Customer location</Text>
                    {delivery.customer_location ? (
                      <>
                        <Text style={styles.info}>{delivery.customer_location.stale ? 'Location may be out of date. Ask the customer to update it.' : 'Customer shared this location for the active delivery.'}</Text>
                        <DeliveryMap location={delivery.customer_location} label="Customer" />
                        <Text style={styles.schedule}>Shared at {formatDate(delivery.customer_location.updated_at)}</Text>
                      </>
                    ) : <Text style={styles.info}>The customer has not shared a GPS location. Use the saved delivery address above.</Text>}
                  </View>
                </View>
              )) : <Text style={styles.info}>No active deliveries are assigned to you. Staff can assign a delivery from its order page.</Text>}
              {sharing ? (
                <View style={styles.sharingPanel}>
                  <Text style={styles.sharingTitle}>{backgroundSharing ? 'Background location sharing is active' : 'Location sharing is active'}</Text>
                  <Text style={styles.info}>{lastSent ? 'Last sent at ' + lastSent : 'Your current location is being sent to the shop.'}</Text>
                  <Pressable disabled={busy} onPress={stopSharing} style={[styles.button, styles.stopButton, busy && styles.disabled]}>
                    {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonText}>Stop my location sharing</Text>}
                  </Pressable>
                </View>
              ) : (
                <Pressable disabled={busy || !deliveries.length} onPress={startSharing} style={[styles.button, (busy || !deliveries.length) && styles.disabled]}>
                  {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonText}>Start sharing my location</Text>}
                </Pressable>
              )}
              <Text style={styles.footnote}>Your GPS location is visible only to customers with one of your active assigned deliveries. Tracking stops when the delivery ends or you stop sharing.</Text>
            </View>
          </>
        )}
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: '#18131a' },
  content: { flexGrow: 1, padding: 18, justifyContent: 'center', alignItems: 'center' },
  header: { width: '100%', maxWidth: 760, flexDirection: 'row', alignItems: 'center', gap: 12, marginBottom: 18 },
  backButton: { minHeight: 42, borderRadius: 14, backgroundColor: '#302734', alignItems: 'center', justifyContent: 'center', paddingHorizontal: 12 },
  backText: { color: '#fff', fontSize: 12, fontWeight: '700' },
  flex: { flex: 1 },
  title: { color: '#fffaf2', fontSize: 24, fontWeight: '800' },
  subtitle: { color: '#c7bac8', fontSize: 14, marginTop: 4 },
  headerAction: { padding: 10 },
  headerActionText: { color: '#fffaf2', fontWeight: '700' },
  card: { width: '100%', maxWidth: 760, borderRadius: 20, padding: 20, gap: 12, borderColor: '#ded3df', borderWidth: 1, backgroundColor: '#fffaf2', marginBottom: 15 },
  cardTitle: { color: '#58265f', fontSize: 20, fontWeight: '800' },
  info: { color: '#514653', fontSize: 14, lineHeight: 21 },
  label: { color: '#514653', fontSize: 13, fontWeight: '700', marginTop: 4 },
  input: { minHeight: 48, borderColor: '#cfc2d1', borderWidth: 1, borderRadius: 12, paddingHorizontal: 13, color: '#201820', backgroundColor: '#fffdf9', fontSize: 15 },
  passwordRow: { flexDirection: 'row', alignItems: 'center' },
  passwordInput: { flex: 1 },
  showButton: { marginLeft: -55, width: 48, alignItems: 'center', justifyContent: 'center', minHeight: 48 },
  showButtonText: { color: '#58265f', fontWeight: '700' },
  button: { minHeight: 48, borderRadius: 14, backgroundColor: '#58265f', alignItems: 'center', justifyContent: 'center', padding: 12, marginTop: 4 },
  stopButton: { backgroundColor: '#a43e4a' },
  buttonText: { color: '#fff', fontSize: 14, fontWeight: '800', textAlign: 'center' },
  disabled: { opacity: 0.55 },
  error: { width: '100%', maxWidth: 760, color: '#7d1d2b', backgroundColor: '#fbe6e8', borderRadius: 10, padding: 12, marginBottom: 10 },
  notice: { width: '100%', maxWidth: 760, color: '#314e39', backgroundColor: '#e3f0e5', borderRadius: 10, padding: 12, marginBottom: 10 },
  deliveryCard: { borderRadius: 14, padding: 14, gap: 8, backgroundColor: '#f5edf6', borderColor: '#e4d8e8', borderWidth: 1, marginTop: 4 },
  deliveryHeader: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', gap: 8 },
  ticket: { color: '#58265f', fontWeight: '800', fontSize: 16 },
  schedule: { color: '#786f78', fontSize: 12 },
  customerName: { color: '#201820', fontSize: 17, fontWeight: '800' },
  phone: { color: '#58265f', fontSize: 14, fontWeight: '700' },
  address: { color: '#514653', fontSize: 14, lineHeight: 20 },
  mapLink: { alignSelf: 'flex-start', paddingVertical: 4 },
  mapLinkText: { color: '#58265f', fontWeight: '700', fontSize: 13 },
  customerPin: { gap: 8, borderRadius: 12, backgroundColor: '#fffaf2', padding: 12, marginTop: 5 },
  pinTitle: { color: '#58265f', fontSize: 15, fontWeight: '800' },
  sharingPanel: { gap: 8, borderRadius: 12, backgroundColor: '#f2eaf4', padding: 13, marginTop: 8 },
  sharingTitle: { color: '#58265f', fontSize: 15, fontWeight: '800' },
  footnote: { color: '#786f78', fontSize: 12, lineHeight: 18, marginTop: 4 },
});
