import { StatusBar } from 'expo-status-bar';
import { useLocalSearchParams, usePathname, useRouter } from 'expo-router';
import Constants from 'expo-constants';
import { apiRequest } from './src/api';
import { readSession, readLegacyToken, saveVerifiedSession, clearSession, getActiveSession } from './src/session';
import { useOnlineShop } from './src/useOnlineShop';
import * as Location from 'expo-location';
import { LinearGradient } from 'expo-linear-gradient';
import * as ImagePicker from 'expo-image-picker';
import { manipulateAsync, SaveFormat } from 'expo-image-manipulator';
import { SafeAreaView } from 'react-native-safe-area-context';
import DeliveryMap from './src/components/DeliveryMap';
import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Dimensions,
  Image,
  Linking,
  Keyboard,
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

const NAV_ICON_ASSETS = {
  home: require('./assets/flaticon/home.png'),
  track: require('./assets/flaticon/track.png'),
  offers: require('./assets/flaticon/offers.png'),
  about: require('./assets/flaticon/about.png'),
  profile: require('./assets/flaticon/profile.png'),
};

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
let welcomeSeenThisLaunch = false;

// Delivery coverage follows the Tarlac City corridor from San Jose to Balingcanaway.
const DELIVERY_BOUNDS = { south: 15.44, north: 15.535, west: 120.6, east: 120.73 };
function isDeliveryArea(latitude, longitude) {
  const lat = Number(latitude);
  const lon = Number(longitude);
  return Number.isFinite(lat) && Number.isFinite(lon)
    && lat >= DELIVERY_BOUNDS.south && lat <= DELIVERY_BOUNDS.north
    && lon >= DELIVERY_BOUNDS.west && lon <= DELIVERY_BOUNDS.east;
}

function normalizeApiUrl(url) {
  return url.trim().replace(/\/+$/, '');
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
  const [session, setSession] = useState(() => getActiveSession(apiBase));
  const [booting, setBooting] = useState(() => !getActiveSession(apiBase));
  const [sessionNotice, setSessionNotice] = useState('');
  const [welcomeSeen, setWelcomeSeen] = useState(welcomeSeenThisLaunch);
  const token = session?.token;
  const customer = session?.customer;

  useEffect(() => {
    if (!sessionNotice) return undefined;
    const timer = setTimeout(() => setSessionNotice(''), 3000);
    return () => clearTimeout(timer);
  }, [sessionNotice]);

  useEffect(() => {
    if (getActiveSession(apiBase)) return;
    let active = true;
    (async () => {
      let saved;
      try {
        saved = await readSession(apiBase);
        const savedToken = saved?.token || await readLegacyToken();
        if (!savedToken || !active) return;
        try {
          const data = await apiRequest(apiBase, 'me.php', { token: savedToken, timeoutMs: 6000 });
          if (!active) return;
          const verified = await saveVerifiedSession(apiBase, savedToken, data.customer);
          if (active) setSession(verified);
        } catch (problem) {
          if (!active) return;
          if (problem.status === 401 || problem.status === 403) {
            await clearSession();
            setSessionNotice('Your session expired. Sign in again to continue.');
          } else {
            setSessionNotice('The shop cannot be reached. Check your internet connection and try again.');
          }
        }
      } catch (problem) {
        if (active) setSessionNotice('Could not open saved sign-in: ' + problem.message);
      } finally { if (active) setBooting(false); }
    })();
    return () => { active = false; };
  }, [apiBase]);

  const handleSignedIn = (nextSession) => {
    setSession(nextSession);
    setSessionNotice('');
    router.replace('/');
  };
  const handleSessionExpired = useCallback(async () => {
    const saved = await readSession(apiBase);
    if (saved?.token && saved.token !== token) return;
    await clearSession();
    setSession(null);
    setSessionNotice('Your session expired. Sign in again to continue.');
  }, [apiBase, token]);
  const handleSignOut = async () => {
    try {
      await clearSession();
      setSession(null);
      setSessionNotice('Signed out successfully.');
      void apiRequest(apiBase, 'logout.php', { method: 'POST', body: {}, token }).catch(() => {});
    } catch (_problem) { setSessionNotice('Could not clear saved sign-in. Please try signing out again.'); }
  };
  if (!welcomeSeen) return <WelcomeScreen onStart={() => { welcomeSeenThisLaunch = true; setWelcomeSeen(true); }} />;
  if (booting) return <LoadingScreen message="Opening Pia&apos;s Laundry Shop..." />;
  return (
    <View style={styles.app}>
      <StatusBar style="light" />
      {customer && token ? (
        <CustomerHome apiBase={apiBase} customer={customer} token={token}
          onSessionExpired={handleSessionExpired} onSignOut={handleSignOut}
          onCustomerUpdated={async (nextCustomer) => {
            const saved = await saveVerifiedSession(apiBase, token, nextCustomer);
            setSession(saved);
          }} />
      ) : (
        <AuthScreen apiBase={apiBase} onSignedIn={handleSignedIn} sessionNotice={sessionNotice} />
      )}
    </View>
  );
}

function WelcomeScreen({ onStart }) {
  const { width, height } = useWindowDimensions();
  const isLandscape = width > height;
  return (
    <LinearGradient colors={['#211422', '#54265d', '#8d5996']} start={{ x: 0.1, y: 0 }} end={{ x: 0.9, y: 1 }} style={styles.welcomeRoot}>
      <StatusBar style="light" />
      <View style={styles.welcomeBubbleOne} />
      <View style={styles.welcomeBubbleTwo} />
      <ScrollView
        style={styles.welcomeScroll}
        contentContainerStyle={[styles.welcomeScrollContent, isLandscape && styles.welcomeScrollContentLandscape]}
        showsVerticalScrollIndicator={false}
        bounces={false}
      >
      <View style={[styles.welcomeContent, isLandscape && styles.welcomeContentLandscape]}>
        <View style={[styles.welcomeLogoShell, isLandscape && styles.welcomeLogoShellLandscape]}>
          <Image source={require('./assets/pias-logo-app-icon.png')} resizeMode="contain" style={styles.welcomeLogo} />
        </View>
        <Text style={[styles.welcomeBrand, isLandscape && styles.welcomeBrandLandscape]}>Pia&apos;s Laundry Shop</Text>
        <Text style={[styles.welcomeTagline, isLandscape && styles.welcomeTaglineLandscape]}>Fresh clothes, less hassle.</Text>
        <Text style={[styles.welcomeDescription, isLandscape && styles.welcomeDescriptionLandscape]}>Book trusted laundry services, request pickup or delivery, and follow every service ticket from your phone.</Text>
        <View style={[styles.welcomeFeatureRow, isLandscape && styles.welcomeFeatureRowLandscape]}>
          <View style={styles.welcomeFeature}><Text style={styles.welcomeFeatureIcon}>🧺</Text><Text style={styles.welcomeFeatureText}>Easy booking</Text></View>
          <View style={styles.welcomeFeature}><Text style={styles.welcomeFeatureIcon}>🚚</Text><Text style={styles.welcomeFeatureText}>Pickup & delivery</Text></View>
          <View style={styles.welcomeFeature}><Text style={styles.welcomeFeatureIcon}>📍</Text><Text style={styles.welcomeFeatureText}> tracking</Text></View>
        </View>
        <Pressable accessibilityRole="button" onPress={onStart} style={({ pressed }) => [styles.welcomeStart, isLandscape && styles.welcomeStartLandscape, pressed && styles.pressed]}>
          <Text style={styles.welcomeStartText}>Get Started</Text><Text style={styles.welcomeStartArrow}>→</Text>
        </Pressable>
        <Text style={[styles.welcomeAddress, isLandscape && styles.welcomeAddressLandscape]}>Blk. 2, Brgy. San Manuel, Tarlac City · 0918-967-9623</Text>
      </View>
      </ScrollView>
    </LinearGradient>
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

function HeroBanner({ compact = false, keyboardCompact = false }) {
  return (
    <LinearGradient colors={['#54245f', '#754583', '#8e5a95']} start={{ x: 0, y: 0.3 }} end={{ x: 1, y: 0.8 }} style={[styles.hero, compact && styles.heroCompact, keyboardCompact && styles.heroKeyboardCompact]}>
      <View style={styles.heroDecorOne} />
      <View style={styles.heroDecorTwo} />
      <View style={[styles.heroContent, keyboardCompact && styles.heroContentKeyboardCompact]}>
        <View style={[styles.heroIcon, keyboardCompact && styles.heroIconKeyboardCompact]}><Text style={[styles.heroEmoji, keyboardCompact && styles.heroEmojiKeyboardCompact]}>🫧</Text></View>
        <View style={styles.heroTextBlock}>
          <Text style={[styles.heroTitle, keyboardCompact && styles.heroTitleKeyboardCompact]}>Pia&apos;s Laundry Shop</Text>
          <Text style={[styles.heroDetail, keyboardCompact && styles.heroDetailKeyboardCompact]}>Blk. 2, Brgy. San Manuel, Tarlac City</Text>
          <Text style={[styles.heroDetail, keyboardCompact && styles.heroDetailKeyboardCompact]}>Contact: 0918-967-9623</Text>
        </View>
        <Text style={[styles.heroFloatingEmoji, keyboardCompact && styles.heroFloatingEmojiKeyboardCompact]}>🧺</Text>
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

function PlaceAddressSearch({ apiBase, value, onChangeText, onPlaceSelected, disabled = false }) {
  const [suggestions, setSuggestions] = useState([]);
  const [searching, setSearching] = useState(false);
  const [searchError, setSearchError] = useState('');
  const requestId = useRef(0);
  const selectedAddress = useRef('');
  useEffect(() => {
    const input = value.trim();
    if (input.length < 3 || disabled || input === selectedAddress.current) { setSuggestions([]); setSearchError(''); return undefined; }
    const id = ++requestId.current;
    const timer = setTimeout(async () => {
      setSearching(true); setSearchError('');
      try {
        const data = await apiRequest(apiBase, 'places.php', { method: 'POST', body: { action: 'autocomplete', input }, timeoutMs: 8000 });
        if (id === requestId.current) setSuggestions(data.suggestions || []);
      } catch (problem) {
        if (id === requestId.current) { setSuggestions([]); setSearchError(problem.message || 'Could not search addresses.'); }
      } finally { if (id === requestId.current) setSearching(false); }
    }, 350);
    return () => clearTimeout(timer);
  }, [apiBase, value, disabled]);
  const choosePlace = (suggestion) => {
    selectedAddress.current = suggestion.address;
    setSuggestions([]);
    setSearchError('');
    onPlaceSelected(suggestion);
  };
  return <View style={styles.placeSearchWrap}>
    <Text style={styles.fieldLabel}>Address</Text>
    <View style={styles.inputShell}><TextInput value={value} onChangeText={onChangeText} editable={!disabled} placeholder="Type your street, barangay, or landmark" placeholderTextColor="#988d99" style={styles.input} autoCapitalize="words" maxLength={180} returnKeyType="search" />{searching ? <View style={styles.inputRight}><ActivityIndicator color={COLORS.plum} size="small" /></View> : null}</View>
    {suggestions.length > 0 ? <View style={styles.placeSuggestions}>{suggestions.map((suggestion) => <Pressable key={suggestion.place_id} onPress={() => choosePlace(suggestion)} style={styles.placeSuggestion}><Text style={styles.placeSuggestionPrimary}>{suggestion.primary_text}</Text>{suggestion.secondary_text ? <Text style={styles.placeSuggestionSecondary}>{suggestion.secondary_text}</Text> : null}</Pressable>)}</View> : null}
    {searchError ? <Text style={styles.placeSearchError}>{searchError}</Text> : null}
    <Text style={styles.placeAttribution} onPress={() => Linking.openURL('https://www.geoapify.com/')}>Powered by Geoapify | OpenStreetMap contributors</Text>
  </View>;
}

function AuthScreen({ apiBase, onSignedIn, sessionNotice }) {
  const router = useRouter();
  const [registering, setRegistering] = useState(false);
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [address, setAddress] = useState('');
  const [addressDetails, setAddressDetails] = useState('');
  const [locationPin, setLocationPin] = useState(null);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [verificationPending, setVerificationPending] = useState(false);
  const [verificationCode, setVerificationCode] = useState('');
  const [forgotPassword, setForgotPassword] = useState(false);
  const [resetCodeSent, setResetCodeSent] = useState(false);
  const [resetCode, setResetCode] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmNewPassword, setConfirmNewPassword] = useState('');
  const [showNewPassword, setShowNewPassword] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const submitting = useRef(false);
  const { width, height } = useWindowDimensions();
  const compactAuth = width < 420;
  const registeringRef = useRef(registering);
  useEffect(() => { registeringRef.current = registering; }, [registering]);
  const authScrollRef = useRef(null);
  const restingWindowHeight = useRef(height);
  const [keyboardScrollInset, setKeyboardScrollInset] = useState(0);
  const scrollToAuthEndAfterKeyboard = useRef(false);
  const scrollAuthFieldIntoView = () => {
    scrollToAuthEndAfterKeyboard.current = true;
    // Android can leave the auth screen in adjust-pan mode, where keyboard events may arrive late.
    // Compact the header as soon as a field receives focus so the active input has room.
    setKeyboardVisible(true);
    setTimeout(() => authScrollRef.current?.scrollToEnd?.({ animated: true }), 520);
  };
  const [keyboardVisible, setKeyboardVisible] = useState(false);

  useEffect(() => {
    const showSubscription = Keyboard.addListener('keyboardDidShow', (event) => {
      setKeyboardVisible(true);
      if (registeringRef.current) {
        const keyboardHeight = event.endCoordinates?.height || 0;
        const resizedBy = Math.max(0, restingWindowHeight.current - Dimensions.get('window').height);
        setKeyboardScrollInset(Platform.OS === 'android' ? Math.max(0, keyboardHeight - resizedBy) : 0);
      } else {
        setKeyboardScrollInset(0);
      }
      if (scrollToAuthEndAfterKeyboard.current) setTimeout(() => authScrollRef.current?.scrollToEnd?.({ animated: true }), 240);
    });
    const hideSubscription = Keyboard.addListener('keyboardDidHide', () => {
      setKeyboardVisible(false);
      setKeyboardScrollInset(0);
      scrollToAuthEndAfterKeyboard.current = false;
      setTimeout(() => { restingWindowHeight.current = Dimensions.get('window').height; }, 120);
    });
    return () => { showSubscription.remove(); hideSubscription.remove(); };
  }, []);

  useEffect(() => {
    if (!notice) return undefined;
    const timer = setTimeout(() => setNotice(''), 3000);
    return () => clearTimeout(timer);
  }, [notice]);

  useEffect(() => {
    if (!error) return undefined;
    const timer = setTimeout(() => setError(''), 3000);
    return () => clearTimeout(timer);
  }, [error]);

  const selectRegistrationPlace = (place) => {
    setAddress(place.address);
    setLocationPin({ latitude: place.latitude, longitude: place.longitude, deliveryAvailable: place.delivery_available });
    setError('');
  };
  const adjustRegistrationPin = ({ latitude, longitude }) => {
    setLocationPin((current) => current ? { ...current, latitude, longitude, deliveryAvailable: isDeliveryArea(latitude, longitude) } : current);
  };
  const submit = async () => {
    if (submitting.current) return;
    setError('');
    setNotice('');
    const normalizedEmail = email.trim();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(normalizedEmail)) return setError('Enter a valid email address, such as name@example.com.');
    if (registering && verificationPending && !/^\d{6}$/.test(verificationCode)) return setError('Enter the 6-digit code sent to your email.');
    if (registering && !verificationPending && !name.trim()) return setError('Enter your full name.');
    if (registering && !verificationPending && !phone.trim()) return setError('Enter your phone number.');
    const completeRegistrationAddress = [addressDetails.trim(), address.trim()].filter(Boolean).join(', ');
    if (registering && !verificationPending && !address.trim()) return setError('Choose an address from the map search results. House, unit, or landmark details are optional.');
    if (registering && !verificationPending && !locationPin) return setError('Choose your address from the address search results.');
    if (registering && !verificationPending && !/^09\d{9}$/.test(phone)) return setError('Enter an 11-digit mobile number starting with 09.');
    if ((!registering || !verificationPending) && (!password || (registering && password.length < 10))) return setError(registering ? 'Use a password with at least 10 characters.' : 'Enter your password.');

    submitting.current = true;
    setBusy(true);
    try {
      const endpoint = registering ? (verificationPending ? 'verify_registration.php' : 'register.php') : 'login.php';
      const data = await apiRequest(apiBase, endpoint, {
        method: 'POST',
        body: registering && verificationPending
          ? { email: normalizedEmail, code: verificationCode }
          : {
              ...(registering ? { name: name.trim(), phone, address: completeRegistrationAddress, latitude: locationPin?.latitude, longitude: locationPin?.longitude } : {}),
              email: normalizedEmail,
              password,
            },
      });
      if (registering) {
        if (!verificationPending) {
          setVerificationPending(true);
          setVerificationCode('');
          setNotice(data.message || 'A verification code was sent to your email.');
          return;
        }
        setName('');
        setPhone('');
        setAddress('');
        setLocationPin(null);
        setPassword('');
        setShowPassword(false);
        setVerificationPending(false);
        setVerificationCode('');
        setRegistering(false);
        setNotice('Email verified. Your account was created. Please sign in.');
        return;
      }
      const nextToken = String(data.token);
      const saved = await saveVerifiedSession(apiBase, nextToken, data.customer, { expiresIn: data.expires_in });
      setPassword('');
      setShowPassword(false);
      onSignedIn(saved);
    } catch (submitError) {
      setError(submitError.message || 'The request could not be completed.');
    } finally {
      submitting.current = false;
      setBusy(false);
    }
  };

  const resendVerificationCode = async () => {
    if (submitting.current) return;
    submitting.current = true;
    setBusy(true);
    setError('');
    try {
      const data = await apiRequest(apiBase, 'register.php', {
        method: 'POST',
        body: { name: name.trim(), phone, address: [addressDetails.trim(), address.trim()].filter(Boolean).join(', '), latitude: locationPin?.latitude, longitude: locationPin?.longitude, email: email.trim(), password },
      });
      setVerificationCode('');
      setNotice(data.message || 'A new verification code was sent.');
    } catch (resendError) {
      setError(resendError.message || 'Could not resend the verification code.');
    } finally {
      submitting.current = false;
      setBusy(false);
    }
  };

  const requestPasswordReset = async () => {
    if (submitting.current) return;
    const normalizedEmail = email.trim();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(normalizedEmail)) return setError('Enter the email address used by your account.');
    submitting.current = true;
    setBusy(true);
    setError('');
    try {
      const data = await apiRequest(apiBase, 'request_password_reset.php', { method: 'POST', body: { email: normalizedEmail } });
      setResetCodeSent(true);
      setResetCode('');
      setNotice(data.message || 'A password reset code was sent if this email has a verified account.');
    } catch (resetError) {
      setError(resetError.message || 'Could not request a password reset code.');
    } finally {
      submitting.current = false;
      setBusy(false);
    }
  };

  const completePasswordReset = async () => {
    if (submitting.current) return;
    if (!/^\d{6}$/.test(resetCode)) return setError('Enter the 6-digit reset code sent to your email.');
    if (newPassword.length < 10) return setError('Use a new password with at least 10 characters.');
    if (newPassword !== confirmNewPassword) return setError('The new passwords do not match.');
    submitting.current = true;
    setBusy(true);
    setError('');
    try {
      const data = await apiRequest(apiBase, 'reset_password.php', { method: 'POST', body: { email: email.trim(), code: resetCode, new_password: newPassword } });
      setForgotPassword(false);
      setResetCodeSent(false);
      setResetCode('');
      setNewPassword('');
      setConfirmNewPassword('');
      setShowNewPassword(false);
      setPassword('');
      setNotice(data.message || 'Password changed. Sign in with your new password.');
    } catch (resetError) {
      setError(resetError.message || 'Could not reset the password.');
    } finally {
      submitting.current = false;
      setBusy(false);
    }
  };


  return (
    <KeyboardAvoidingView
      style={styles.authRoot}
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
    >
      <BrandBar />
      <SafeAreaView style={styles.authSafeArea} edges={['bottom']}>
        <View style={[styles.authContainer, compactAuth && styles.authContainerCompact]}>
          <HeroBanner compact keyboardCompact={keyboardVisible} />
          <View style={[styles.authIntro, keyboardVisible && styles.authIntroKeyboardHidden]}>
            <Text style={styles.authTitle}>{forgotPassword ? 'Reset your password' : registering ? 'Join the laundry club' : 'Welcome back'}</Text>
            <Text style={styles.authDescription}>{forgotPassword ? 'Receive a code by email and choose a new password.' : registering ? 'Create an account to book laundry services and follow your tickets.' : 'Sign in to book a service and follow your laundry ticket.'}</Text>
          </View>
          <View style={[styles.authCard, compactAuth && styles.authCardCompact]}>
            <View style={styles.authSwitch}>
              <Pressable onPress={() => { setRegistering(false); setKeyboardScrollInset(0); scrollToAuthEndAfterKeyboard.current = false; setForgotPassword(false); setError(''); }} style={[styles.authSwitchButton, !registering && styles.authSwitchSelected]}>
                <Text style={[styles.authSwitchText, !registering && styles.authSwitchSelectedText]}>Sign in</Text>
              </Pressable>
              <Pressable onPress={() => { setRegistering(true); setForgotPassword(false); setError(''); }} style={[styles.authSwitchButton, registering && styles.authSwitchSelected]}>
                <Text style={[styles.authSwitchText, registering && styles.authSwitchSelectedText]}>Create account</Text>
              </Pressable>
            </View>

            <ScrollView ref={authScrollRef} style={styles.authCardScroll} contentContainerStyle={[styles.authCardScrollContent, registering && keyboardVisible && keyboardScrollInset > 0 && { paddingBottom: keyboardScrollInset + 24 }]} nestedScrollEnabled keyboardShouldPersistTaps="handled" keyboardDismissMode={registering ? (Platform.OS === 'ios' ? 'interactive' : 'none') : (Platform.OS === 'ios' ? 'interactive' : 'on-drag')}>
            {sessionNotice ? <Text style={styles.successBox}>{sessionNotice}</Text> : null}
            {notice ? <Text style={styles.successBox}>{notice}</Text> : null}
            {error ? <Text style={styles.errorBox}>{error}</Text> : null}

            <>
            {forgotPassword ? (
              <>
                <Text style={styles.authFootnote}>{resetCodeSent ? 'Enter the 6-digit code from your email and choose a new password.' : 'Enter the verified email address used by your customer account.'}</Text>
                <Field label={'Email address'} value={email} onFocus={scrollAuthFieldIntoView} onChangeText={setEmail} editable={!resetCodeSent} placeholder={'you@example.com'} keyboardType={'email-address'} autoCapitalize={'none'} autoCorrect={false} maxLength={120} />
                {resetCodeSent ? <>
                  <Field label={'Reset code'} value={resetCode} onChangeText={(value) => setResetCode(value.replace(/\D/g, '').slice(0, 6))} placeholder={'6-digit code'} keyboardType={'number-pad'} maxLength={6} returnKeyType={'next'} />
                  <Field label={'New password'} value={newPassword} onFocus={scrollAuthFieldIntoView} onChangeText={setNewPassword} placeholder={'At least 10 characters'} secureTextEntry={!showNewPassword} autoCapitalize={'none'} right={<Pressable accessibilityRole={'button'} accessibilityLabel={showNewPassword ? 'Hide password' : 'Show password'} hitSlop={8} onPress={() => setShowNewPassword((visible) => !visible)} style={styles.passwordVisibilityButton}><Text style={styles.showPassword}>{showNewPassword ? 'Hide' : 'Show'}</Text></Pressable>} returnKeyType={'go'} onSubmitEditing={completePasswordReset} />
                  <Field label={'Confirm new password'} value={confirmNewPassword} onFocus={scrollAuthFieldIntoView} onChangeText={setConfirmNewPassword} placeholder={'Enter the new password again'} secureTextEntry={!showNewPassword} autoCapitalize={'none'} returnKeyType={'go'} onSubmitEditing={completePasswordReset} />
                  <Pressable onPress={completePasswordReset} disabled={busy} style={({ pressed }) => [styles.primaryButton, pressed && !busy && styles.pressed, busy && styles.disabledButton]}>{busy ? <ActivityIndicator color={COLORS.white} /> : <Text style={styles.primaryButtonText}>Reset password</Text>}</Pressable>
                  <Pressable onPress={requestPasswordReset} disabled={busy} style={styles.driverSignInLink}><Text style={styles.driverSignInText}>Resend reset code</Text></Pressable>
                </> : <Pressable onPress={requestPasswordReset} disabled={busy} style={({ pressed }) => [styles.primaryButton, pressed && !busy && styles.pressed, busy && styles.disabledButton]}>{busy ? <ActivityIndicator color={COLORS.white} /> : <Text style={styles.primaryButtonText}>Send reset code</Text>}</Pressable>}
                <Pressable onPress={() => { setForgotPassword(false); setResetCodeSent(false); setResetCode(''); setNewPassword(''); setConfirmNewPassword(''); setError(''); }} disabled={busy} style={styles.driverSignInLink}><Text style={styles.driverSignInText}>Back to sign in</Text></Pressable>
              </>
            ) : <>
            {registering ? <Field label="Full name" value={name} onChangeText={setName} placeholder="Your name" autoCapitalize="words" maxLength={100} returnKeyType="next" /> : null}
            {registering ? <Field label="Phone number" value={phone} onChangeText={(value) => setPhone(value.replace(/\D/g, '').slice(0, 11))} placeholder="09xx xxx xxxx" keyboardType="phone-pad" maxLength={11} returnKeyType="next" /> : null}
            {registering ? <><PlaceAddressSearch apiBase={apiBase} value={address} onChangeText={(value) => { setAddress(value); setLocationPin(null); }} onPlaceSelected={selectRegistrationPlace} />
              <Field label="Address Detail (optional)" value={addressDetails} onChangeText={setAddressDetails} placeholder="House number, unit, gate, or nearby landmark" autoCapitalize="words" maxLength={180} returnKeyType="next" />
              <View style={styles.locationPinPanel}><Text style={styles.locationPinTitle}>Pin Address</Text><Text style={styles.locationPinCopy}>Choose one address, then pin the exact spot where the driver should go. Tap “Full-screen map” for a larger map preview. The pin is saved with your address. Free delivery covers San Jose, San Manuel, and Balingcanaway, Tarlac; distance-based fees apply outside these areas.</Text>{locationPin ? <><Text style={[styles.locationPinSuccess, !locationPin.deliveryAvailable && styles.locationPinWarning]}>{locationPin.deliveryAvailable ? 'This pin is in the free delivery area.' : 'This pin is outside the free delivery area. You can still request delivery for a distance-based fee.'}</Text><Text style={styles.locationPinCopy}>Adjust the marker to your exact home or gate. The coordinates below are what the driver will use.</Text><View style={styles.registrationMap}><DeliveryMap location={locationPin} label="Delivery pin" editable onLocationChange={adjustRegistrationPin} /></View></> : <Text style={styles.locationPinCopy}>Select one address from the search suggestions to place its pin on the map.</Text>}</View></> : null}
            <Field label="Email address" value={email} onFocus={scrollAuthFieldIntoView} onChangeText={setEmail} placeholder="you@example.com" keyboardType="email-address" autoCapitalize="none" autoCorrect={false} maxLength={120} returnKeyType="next" />
            <Field
              label="Password"
              value={password}
              onFocus={scrollAuthFieldIntoView}
              onChangeText={setPassword}
              placeholder={registering ? 'At least 10 characters' : 'Enter your password'}
              secureTextEntry={!showPassword}
              autoCapitalize="none"
              right={<Pressable accessibilityRole="button" accessibilityLabel={showPassword ? 'Hide password' : 'Show password'} hitSlop={8} onPress={() => setShowPassword((visible) => !visible)} style={styles.passwordVisibilityButton}><Text style={styles.showPassword}>{showPassword ? 'Hide' : 'Show'}</Text></Pressable>}
              returnKeyType="go"
              onSubmitEditing={submit}
            />
            <Pressable onPress={submit} disabled={busy} style={({ pressed }) => [styles.primaryButton, pressed && !busy && styles.pressed, busy && styles.disabledButton]}>
              {busy ? <ActivityIndicator color={COLORS.white} /> : <Text style={styles.primaryButtonText}>{registering ? 'Create account' : 'Sign in'}</Text>}
            </Pressable>
            {!registering ? <Pressable onPress={() => { setForgotPassword(true); setResetCodeSent(false); setResetCode(''); setNewPassword(''); setConfirmNewPassword(''); setError(''); }} disabled={busy} style={styles.driverSignInLink}><Text style={styles.driverSignInText}>Forgot password?</Text></Pressable> : null}
            <Text style={styles.authFootnote}>The shop confirms your service, price, and pickup or delivery schedule.</Text>
            {!registering ? (
              <Pressable accessibilityRole="link" onPress={() => router.push('/driver')} style={styles.driverSignInLink}>
                <Text style={styles.driverSignInText}>Delivery driver? Sign in here</Text>
              </Pressable>
            ) : null}
            </>}
            </>
            </ScrollView>
          </View>
        </View>
      </SafeAreaView>
      <Modal
        transparent
        visible={registering && verificationPending}
        animationType="fade"
        onRequestClose={() => {
          if (busy) return;
          setVerificationPending(false);
          setVerificationCode('');
          setError('');
        }}
      >
        <View style={styles.modalShade}>
          <KeyboardAvoidingView
            style={styles.verificationModalKeyboard}
            behavior={Platform.OS === 'ios' ? 'padding' : undefined}
          >
            <View style={styles.modalCard}>
              <Text style={styles.modalTitle}>Verify your email</Text>
              <Text style={styles.modalCopy}>We sent a 6-digit verification code to {email}. Enter it below within 10 minutes to create your account.</Text>
              {notice ? <Text style={styles.successBox}>{notice}</Text> : null}
              {error ? <Text style={styles.errorBox}>{error}</Text> : null}
              <Field
                label="Verification code"
                value={verificationCode}
                onChangeText={(value) => setVerificationCode(value.replace(/\D/g, '').slice(0, 6))}
                placeholder="6-digit code"
                keyboardType="number-pad"
                maxLength={6}
                autoFocus
                returnKeyType="go"
                onSubmitEditing={submit}
              />
              <Pressable onPress={submit} disabled={busy} style={({ pressed }) => [styles.primaryButton, pressed && !busy && styles.pressed, busy && styles.disabledButton]}>
                {busy ? <ActivityIndicator color={COLORS.white} /> : <Text style={styles.primaryButtonText}>Verify and create account</Text>}
              </Pressable>
              <Pressable onPress={resendVerificationCode} disabled={busy} style={styles.driverSignInLink}>
                <Text style={styles.driverSignInText}>Resend verification code</Text>
              </Pressable>
              <Pressable onPress={() => { setVerificationPending(false); setVerificationCode(''); setError(''); setNotice(''); }} disabled={busy} style={styles.driverSignInLink}>
                <Text style={styles.driverSignInText}>Edit account details</Text>
              </Pressable>
            </View>
          </KeyboardAvoidingView>
        </View>
      </Modal>
    </KeyboardAvoidingView>
  );
}

function CustomerHome({ apiBase, customer, token, onSessionExpired, onSignOut, onCustomerUpdated }) {
  const pathname = usePathname();
  const router = useRouter();
  const params = useLocalSearchParams();
  const tab = pathname === '/track' ? 'track'
    : pathname === '/offers' ? 'offers'
    : pathname === '/about' ? 'about'
    : pathname === '/profile' ? 'profile'
    : 'home';
  const { services, tickets, payment: gcashInfo, loading, refreshing,
    error, setError, refresh: loadShopData } = useOnlineShop({ apiBase, customer, token, onSessionExpired });
  const bookingBusy = useRef(false);
  const [quantities, setQuantities] = useState({});
  const [filter, setFilter] = useState('All');
  const [search, setSearch] = useState('');
  const [notes, setNotes] = useState('');
  const [fulfillment, setFulfillment] = useState('pickup_delivery');
  const [deliveryAddress, setDeliveryAddress] = useState(customer.address || '');
  const deliveryAvailable = isDeliveryArea(customer.location_latitude, customer.location_longitude);
  const [deliveryQuote, setDeliveryQuote] = useState(deliveryAvailable ? { delivery_fee: 0, distance_km: 0 } : null);
  const [deliveryQuoteLoading, setDeliveryQuoteLoading] = useState(false);
  const [deliveryQuoteError, setDeliveryQuoteError] = useState('');
  useEffect(() => {
    if (deliveryAvailable) {
      setDeliveryQuote({ delivery_fee: 0, distance_km: 0 });
      setDeliveryQuoteError('');
      setDeliveryQuoteLoading(false);
      return undefined;
    }
    let active = true;
    setDeliveryQuote(null);
    setDeliveryQuoteError('');
    setDeliveryQuoteLoading(true);
    apiRequest(apiBase, 'delivery_quote.php', { token, timeoutMs: 12000 })
      .then((result) => { if (active) setDeliveryQuote(result.quote); })
      .catch((problem) => { if (active) setDeliveryQuoteError(problem.message || 'Could not calculate the delivery fee.'); })
      .finally(() => { if (active) setDeliveryQuoteLoading(false); });
    return () => { active = false; };
  }, [apiBase, token, deliveryAvailable, customer.location_latitude, customer.location_longitude]);
  const deliveryFee = fulfillment === 'dropoff_pickup' || deliveryAvailable ? 0 : Number(deliveryQuote?.delivery_fee || 0);
  const [pickupAt, setPickupAt] = useState(null);
  const [pickupDate, setPickupDate] = useState('');
  const [pickupTime, setPickupTime] = useState('09:00');
  const [pickupError, setPickupError] = useState('');
  const [pickupModal, setPickupModal] = useState(false);
  const [calendarMonth, setCalendarMonth] = useState(() => {
    const today = new Date();
    return new Date(today.getFullYear(), today.getMonth(), 1);
  });
  const [sending, setSending] = useState(false);
  const [locationBusy, setLocationBusy] = useState({});
  const [locationError, setLocationError] = useState({});
  const [paymentMethod, setPaymentMethod] = useState('cash_on_handoff');
  const [gcashReference, setGcashReference] = useState('');
  const [retryReferences, setRetryReferences] = useState({});
  const [retryBusy, setRetryBusy] = useState({});
  const [editingLocation, setEditingLocation] = useState(false);
  const [profileAddress, setProfileAddress] = useState(customer.address || '');
  const [profileAddressDetails, setProfileAddressDetails] = useState('');
  const [profilePin, setProfilePin] = useState(() => Number.isFinite(Number(customer.location_latitude)) && Number.isFinite(Number(customer.location_longitude)) ? { latitude: Number(customer.location_latitude), longitude: Number(customer.location_longitude), deliveryAvailable: isDeliveryArea(customer.location_latitude, customer.location_longitude) } : null);
  const [profileSaving, setProfileSaving] = useState(false);
  const [profileLocationError, setProfileLocationError] = useState('');
  const [profilePhotoPickerVisible, setProfilePhotoPickerVisible] = useState(false);
  const [profilePhotoPreviewVisible, setProfilePhotoPreviewVisible] = useState(false);
  const [profilePhotoDraft, setProfilePhotoDraft] = useState(null);
  const [profileDetailsEditing, setProfileDetailsEditing] = useState('');
  const [profileNameDraft, setProfileNameDraft] = useState(customer.name || '');
  const [profilePhoneDraft, setProfilePhoneDraft] = useState(customer.phone || '');
  const [profileDetailsSaving, setProfileDetailsSaving] = useState(false);
  const [profileDetailsError, setProfileDetailsError] = useState('');
  const toast = typeof params.booking === 'string' && params.booking ? 'Booking submitted. Ticket ' + params.booking : '';

  useEffect(() => {
    if (!toast) return undefined;
    const timer = setTimeout(() => router.replace(pathname), 3000);
    return () => clearTimeout(timer);
  }, [toast, router, pathname]);

  useEffect(() => {
    if (!error) return undefined;
    const timer = setTimeout(() => setError(''), 3000);
    return () => clearTimeout(timer);
  }, [error, setError]);

  const { width } = useWindowDimensions();
  const wide = width >= 760;
  const selectProfilePlace = (place) => {
    setProfileAddress(place.address);
    setProfileAddressDetails('');
    setProfilePin({ latitude: place.latitude, longitude: place.longitude, deliveryAvailable: place.delivery_available });
    setProfileLocationError('');
  };
  const adjustProfilePin = ({ latitude, longitude }) => {
    setProfilePin((current) => current ? { ...current, latitude, longitude, deliveryAvailable: isDeliveryArea(latitude, longitude) } : current);
  };
  const saveProfileLocation = async () => {
    const completeProfileAddress = [profileAddressDetails.trim(), profileAddress.trim()].filter(Boolean).join(', ');
    if (!profileAddress.trim()) return setProfileLocationError('Enter an address. House, unit, or landmark details are optional.');
    if (!profilePin) return setProfileLocationError('Choose your address from the address search results before saving.');
    setProfileSaving(true); setProfileLocationError('');
    try {
      const result = await apiRequest(apiBase, 'customer_profile.php', { method: 'POST', token, body: { address: completeProfileAddress, latitude: profilePin.latitude, longitude: profilePin.longitude } });
      await onCustomerUpdated(result.customer); setDeliveryAddress(completeProfileAddress); setEditingLocation(false);
    } catch (saveError) {
      if (saveError.status === 401) await onSessionExpired(); else setProfileLocationError(saveError.message || 'Could not save your location.');
    } finally { setProfileSaving(false); }
  };
  const saveProfileDetail = async (field) => {
    const value = field === 'name' ? profileNameDraft.trim() : profilePhoneDraft.trim();
    setProfileDetailsError('');
    setProfileDetailsSaving(true);
    try {
      const body = field === 'name' ? { name: value } : { phone: value };
      const result = await apiRequest(apiBase, 'customer_details.php', { method: 'POST', token, body, timeoutMs: 30000 });
      await onCustomerUpdated(result.customer);
      setProfileDetailsEditing('');
    } catch (saveError) {
      if (saveError.status === 401) await onSessionExpired();
      else setProfileDetailsError(saveError.message || 'Could not update your profile.');
    } finally { setProfileDetailsSaving(false); }
  };
  const chooseProfilePhoto = async (source) => {
    setProfilePhotoPickerVisible(false);
    setProfileDetailsError('');
    try {
      if (source === 'camera') {
        const permission = await ImagePicker.requestCameraPermissionsAsync();
        if (!permission.granted) {
          setProfileDetailsError('Allow camera access in your phone settings, or choose a picture from your gallery.');
          return;
        }
      }
      // Use our own preview and Save button instead of Android's fixed “Crop” action.
      const options = { mediaTypes: ['images'], allowsEditing: false, quality: 1, base64: false };
      const result = source === 'camera'
        ? await ImagePicker.launchCameraAsync(options)
        : await ImagePicker.launchImageLibraryAsync(options);
      if (result.canceled) return;
      const asset = result.assets?.[0];
      if (!asset?.uri || !asset.width || !asset.height) throw new Error('Could not read that photo. Please choose another image.');
      setProfilePhotoDraft({ uri: asset.uri, width: asset.width, height: asset.height });
      setProfilePhotoPreviewVisible(true);
    } catch (photoError) {
      setProfileDetailsError(photoError.message || 'Could not open that photo.');
    }
  };
  const saveProfilePhoto = async () => {
    if (!profilePhotoDraft || profileDetailsSaving) return;
    setProfileDetailsError('');
    setProfileDetailsSaving(true);
    try {
      const { uri, width, height } = profilePhotoDraft;
      const side = Math.min(width, height);
      const resized = await manipulateAsync(uri, [
        { crop: { originX: Math.floor((width - side) / 2), originY: Math.floor((height - side) / 2), width: side, height: side } },
        { resize: { width: 1024, height: 1024 } },
      ], { compress: 0.85, format: SaveFormat.JPEG, base64: true });
      if (!resized.base64) throw new Error('Could not prepare that photo. Please choose another image.');
      const profilePhoto = `data:image/jpeg;base64,${resized.base64}`;
      if (profilePhoto.length > 7000000) throw new Error('Choose an image smaller than 5 MB.');
      const response = await apiRequest(apiBase, 'customer_details.php', { method: 'POST', token, body: { profile_photo: profilePhoto }, timeoutMs: 30000 });
      await onCustomerUpdated(response.customer);
      setProfilePhotoPreviewVisible(false);
      setProfilePhotoDraft(null);
    } catch (photoError) {
      if (photoError.status === 401) await onSessionExpired();
      else setProfileDetailsError(photoError.message || 'Could not update your profile picture.');
    } finally { setProfileDetailsSaving(false); }
  };  const beginLocationEdit = () => {
    setProfileAddress(customer.address || '');
    setProfilePin(Number.isFinite(Number(customer.location_latitude)) && Number.isFinite(Number(customer.location_longitude)) ? { latitude: Number(customer.location_latitude), longitude: Number(customer.location_longitude), deliveryAvailable: isDeliveryArea(customer.location_latitude, customer.location_longitude) } : null);
    setProfileAddressDetails('');
    setProfileLocationError(''); setEditingLocation(true);
  };
  const navigateTo = (nextTab, bookingNumber) => {
    if (nextTab === 'track' && bookingNumber) {
      router.replace({ pathname: '/track', params: { booking: bookingNumber } });
      return;
    }
    const paths = { home: '/', track: '/track', offers: '/offers', about: '/about', profile: '/profile' };
    router.replace(paths[nextTab] || '/');
  };


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

  const serviceSubtotal = useMemo(() => services.reduce((total, service) => {
    const id = String(service.id);
    const quantity = Number(quantities[id] || 0);
    return total + (Number(service.price) || 0) * (Number.isFinite(quantity) ? quantity : 0);
  }, 0), [quantities, services]);
  const estimate = serviceSubtotal + deliveryFee;

  const updateQuantity = (service, value) => {
    const clean = String(value).replace(/[^0-9.]/g, '');
    const id = String(service.id);
    const isCoreService = ['Wash', 'Dry', 'Full service'].includes(serviceCategory(service));
    setQuantities((current) => {
      if (!isCoreService || Number(clean || 0) <= 0) return { ...current, [id]: clean };
      const next = { ...current };
      services.forEach((item) => {
        if (String(item.id) !== id && ['Wash', 'Dry', 'Full service'].includes(serviceCategory(item))) next[String(item.id)] = '';
      });
      next[id] = clean;
      return next;
    });
    if (isCoreService && Number(clean || 0) > 0) {
      setError('');
    }
  };

  const confirmPickup = () => {
    const selected = new Date(`${pickupDate}T${pickupTime}:00`);
    if (!pickupDate || !pickupTime || Number.isNaN(selected.getTime())) {
      setPickupError('Enter a valid pickup date and time.');
      return;
    }
    if (selected <= new Date()) {
      setPickupError('That schedule has already passed. Choose a future time slot.');
      return;
    }
    const hour = selected.getHours();
    if (hour < 8 || hour >= 18) {
      setPickupError('Choose a time within shop hours: 8:00 AM to 6:00 PM.');
      return;
    }
    setPickupAt(selected.toISOString());
    setPickupModal(false);
    setPickupError('');
  };

  const bookService = async () => {
    if (bookingBusy.current) return;
    const chosen = services.map((service) => ({
      service_id: Number(service.id), quantity: Number(quantities[String(service.id)] || 0),
    })).filter((line) => line.quantity > 0);
    if (!chosen.length) return setError('Add at least one service before booking.');
    if (chosen.some((line) => !Number.isFinite(line.quantity) || line.quantity > 500 ||
      (['piece', 'load'].includes(services.find((service) => Number(service.id) === line.service_id)?.unit) && !Number.isInteger(line.quantity)))) {
      return setError('Use up to 500 per service, with whole numbers for loads and pieces.');
    }
    if (pickupAt && new Date(pickupAt) <= new Date()) return setError('Choose a future schedule or remove the suggested time.');
    if (['pickup_delivery', 'dropoff_delivery'].includes(fulfillment) && !deliveryAvailable && !deliveryQuote) return setError(deliveryQuoteError || 'Wait for the delivery fee to finish calculating before booking.');
    if (fulfillment !== 'dropoff_pickup' && !deliveryAddress.trim()) return setError('Add an address to your profile and pin its location before booking delivery.');
    if (paymentMethod === 'gcash') {
      if (!gcashInfo?.configured) return setError('The shop has not configured GCash payments yet.');
      if (gcashReference.trim().length < 6) return setError('Enter the GCash reference from your completed transfer.');
    }
    bookingBusy.current = true;
    setSending(true);
    setError('');
    try {
      const result = await apiRequest(apiBase, 'tickets.php', {
        method: 'POST', token, timeoutMs: 20000,
        body: {
          services: chosen,
          fulfillment_type: fulfillment,
          delivery_address: deliveryAddress.trim(),
          expected_pickup: pickupAt,
          notes: notes.trim(),
          quoted_total: Number(serviceSubtotal.toFixed(2)),
          payment_method: paymentMethod,
          ...(paymentMethod === 'gcash' ? {
            payment_amount: Number(estimate.toFixed(2)),
            payment_reference: gcashReference.trim(),
          } : {}),
        },
      });
      setQuantities({});
      setNotes('');
      setPickupAt(null);
      setPaymentMethod('cash_on_handoff');
      setGcashReference('');
      await loadShopData(true);
      navigateTo('track', result.ticket?.ticket_number || result.ticket?.order_number);
    } catch (problem) {
      if (problem.status === 401 || problem.status === 403) await onSessionExpired();
      setError((problem.message || 'Could not submit the booking.') + ' Your form has been kept.');
    } finally {
      bookingBusy.current = false;
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
  const todayForSchedule = new Date();
  todayForSchedule.setHours(0, 0, 0, 0);
  const scheduleCalendarDays = (() => {
    const year = calendarMonth.getFullYear();
    const month = calendarMonth.getMonth();
    const firstWeekday = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    return Array.from({ length: firstWeekday + daysInMonth }, (_, index) => {
      if (index < firstWeekday) return null;
      const day = index - firstWeekday + 1;
      const date = new Date(year, month, day);
      return {
        value: [year, String(month + 1).padStart(2, '0'), String(day).padStart(2, '0')].join('-'),
        day,
        isPast: date < todayForSchedule,
      };
    });
  })();
  const canShowPreviousScheduleMonth = calendarMonth.getFullYear() > todayForSchedule.getFullYear()
    || (calendarMonth.getFullYear() === todayForSchedule.getFullYear() && calendarMonth.getMonth() > todayForSchedule.getMonth());
  const scheduleSlots = [
    { value: '08:00', label: '8:00 AM – 10:00 AM' },
    { value: '10:00', label: '10:00 AM – 12:00 PM' },
    { value: '12:00', label: '12:00 PM – 2:00 PM' },
    { value: '14:00', label: '2:00 PM – 4:00 PM' },
    { value: '16:00', label: '4:00 PM – 6:00 PM' },
  ];

  return (
    <View style={styles.homeRoot}>
      <BrandBar />
      {tab === 'home' ? (
        <ScrollView contentContainerStyle={styles.homeScroll}>
          <View style={styles.pageWidth}>
            <HeroBanner />
            <View style={styles.homeWelcomeCard}>
              <Text style={styles.homeEyebrow}>WELCOME TO</Text>
              <Text style={styles.homeHeadline}>Pia&apos;s Laundry Shop</Text>
              <Text style={styles.homeLead}>Hello, {customer.name || 'Customer'}! Let us take care of your laundry today.</Text>
              <Pressable onPress={() => navigateTo('offers')} style={styles.homeCta}><Text style={styles.homeCtaText}>Choose a service</Text><Text style={styles.homeCtaArrow}>→</Text></Pressable>
              <Pressable onPress={() => navigateTo('track')} style={styles.homeTrackCta}><Text style={styles.homeTrackCtaText}>Track my bookings</Text><Text style={styles.homeTrackCtaArrow}>▣</Text></Pressable>
            </View>
            {error ? <Text style={styles.errorBox}>{error}</Text> : null}
            <View style={styles.homeStatsRow}>
              <View style={styles.homeStat}><Text style={styles.homeStatValue}>{services.length}</Text><Text style={styles.homeStatLabel}>Services available</Text></View>
              <View style={styles.homeStat}><Text style={styles.homeStatValue}>{tickets.length}</Text><Text style={styles.homeStatLabel}>My service tickets</Text></View>
            </View>
            <View style={styles.sectionHeadingRow}><View><Text style={styles.sectionHeading}>Laundry made simple</Text><Text style={styles.sectionCopy}>Choose the service that fits your needs.</Text></View><Pressable onPress={() => navigateTo('offers')}><Text style={styles.sectionLink}>View all</Text></Pressable></View>
            <View style={styles.homeServiceGrid}>
              {services.slice(0, 4).map((service) => (
                <Pressable key={service.id} onPress={() => navigateTo('offers')} style={styles.homeServiceCard}>
                  <View style={styles.homeServiceIcon}><Text style={styles.homeServiceEmoji}>{serviceEmoji(service)}</Text></View>
                  <Text style={styles.homeServiceName} numberOfLines={2}>{service.name}</Text>
                  <Text style={styles.homeServicePrice}>{money(service.price)} / {service.unit || 'unit'}</Text>
                </Pressable>
              ))}
            </View>
            <View style={styles.homeInfoRow}>
              <View style={styles.homeInfoCard}><Text style={styles.homeInfoIcon}>🧺</Text><Text style={styles.homeInfoTitle}>Book online</Text><Text style={styles.homeInfoCopy}>Select your actual services and quantity.</Text></View>
              <View style={styles.homeInfoCard}><Text style={styles.homeInfoIcon}>🚚</Text><Text style={styles.homeInfoTitle}>Pickup or delivery</Text><Text style={styles.homeInfoCopy}>Choose how the shop should handle your order.</Text></View>
            </View>
          </View>
        </ScrollView>
      ) : tab === 'offers' ? (
        <ScrollView contentContainerStyle={styles.homeScroll} keyboardShouldPersistTaps="handled">
          <View style={styles.pageWidth}>
            <HeroBanner />
            <View style={styles.welcomeLine}>
              <View>
                <Text style={styles.greeting}>Choose your services</Text>
                <Text style={styles.welcomeCopy}>Add or remove quantities, then schedule your pickup or delivery.</Text>
              </View>
            </View>
            {toast ? <Text style={styles.successBox}>{toast}</Text> : null}
            {error ? <Text style={styles.errorBox}>{error}</Text> : null}

            <View style={styles.searchRow}>
              <Text style={styles.searchIcon}>⌕</Text>
              <TextInput value={search} onChangeText={setSearch} placeholder="Search laundry services…" placeholderTextColor="#a59ba5" style={styles.searchInput} />
              <Pressable accessibilityRole="button" accessibilityLabel="Refresh" disabled={refreshing} onPress={() => loadShopData()} style={[styles.refreshButton, refreshing && styles.disabledButton]}>{refreshing ? <ActivityIndicator color={COLORS.purple} size="small" /> : <Text style={styles.refreshText}>↻</Text>}</Pressable>
            </View>
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.filtersRow}>
              {filters.map((item) => (
                <Pressable key={item} onPress={() => setFilter(item)} style={[styles.filterChip, filter === item && styles.filterChipActive]}>
                  <Text style={[styles.filterText, filter === item && styles.filterTextActive]}>{item}</Text>
                </Pressable>
              ))}
            </ScrollView>
            <View style={styles.serviceRuleNote}>
              <Text style={styles.serviceRuleTitle}>Choose one main laundry service</Text>
              <Text style={styles.serviceRuleCopy}>Select Wash, Dry, or Full Service. Add-ons such as detergent and fabcon can still be included.</Text>
            </View>

            {loading && !services.length ? (
              <View style={styles.inlineLoading}><ActivityIndicator color={COLORS.purple} /><Text style={styles.mutedText}>Loading shop services…</Text></View>
            ) : visibleServices.length ? (
              <View style={styles.serviceGrid}>
                {visibleServices.map((service, index) => (
                  <ServiceCard
                    key={service.id}
                    service={service}
                    quantity={quantities[String(service.id)] || ''}
                    onQuantityChange={(value) => updateQuantity(service, value)}
                    wide={wide}
                    index={index}
                  />
                ))}
              </View>
            ) : (
              <View style={styles.emptyCard}><Text style={styles.emptyEmoji}>🫧</Text><Text style={styles.emptyTitle}>No services found</Text><Text style={styles.mutedText}>{error && !services.length ? 'Connect to the internet and refresh the service list.' : 'Try another search or category.'}</Text></View>
            )}

            <View style={styles.bookingPanel}>
              <Text style={styles.panelTitle}>Service handoff</Text>
              <View style={styles.deliveryRestriction}><Text style={styles.deliveryRestrictionTitle}>{deliveryAvailable ? 'Free delivery zone' : 'Delivery outside the free zone'}</Text><Text style={styles.deliveryRestrictionCopy}>Free delivery covers San Jose, San Manuel, and Balingcanaway, Tarlac. Outside the free zone, the fee is ₱30 for the first kilometer plus ₱10 per additional kilometer, measured by driving route from the shop.</Text>{!deliveryAvailable ? (deliveryQuoteLoading ? <Text style={styles.deliveryRestrictionCopy}>Calculating route and delivery fee…</Text> : deliveryQuote ? <Text style={styles.deliveryRestrictionCopy}>{deliveryQuote.distance_km} km driving distance · Delivery fee: {money(deliveryQuote.delivery_fee)}{deliveryQuote.billable_km ? ' (' + deliveryQuote.billable_km + ' km billed)' : ''}</Text> : <Text style={styles.deliveryRestrictionCopy}>{deliveryQuoteError || 'Delivery fee is unavailable right now.'}</Text>) : null}</View>
              <View style={styles.handoffChoices}>
                <Pressable onPress={() => { setFulfillment('pickup_delivery'); setPaymentMethod('cash_on_handoff'); }} style={[styles.handoffCard, fulfillment === 'pickup_delivery' && styles.handoffCardActive]}>
                  <Text style={styles.handoffIcon}>🚚</Text>
                  <View style={styles.flexOne}><Text style={[styles.handoffTitle, fulfillment === 'pickup_delivery' && styles.handoffTitleActive]}>Pickup & Delivery</Text><Text style={[styles.handoffCopy, fulfillment === 'pickup_delivery' && styles.handoffCopyActive]}>Driver collects your laundry and returns it after service.</Text></View>
                </Pressable>
                <Pressable onPress={() => { setFulfillment('dropoff_delivery'); setPaymentMethod('cash_on_handoff'); }} style={[styles.handoffCard, fulfillment === 'dropoff_delivery' && styles.handoffCardActive]}>
                  <Text style={styles.handoffIcon}>🏪</Text>
                  <View style={styles.flexOne}><Text style={[styles.handoffTitle, fulfillment === 'dropoff_delivery' && styles.handoffTitleActive]}>Drop-off & Delivery</Text><Text style={[styles.handoffCopy, fulfillment === 'dropoff_delivery' && styles.handoffCopyActive]}>Bring laundry to the shop; a driver returns it when done.</Text></View>
                </Pressable>
                <Pressable onPress={() => { setFulfillment('dropoff_pickup'); setPaymentMethod('cash_on_handoff'); }} style={[styles.handoffCard, fulfillment === 'dropoff_pickup' && styles.handoffCardActive]}>
                  <Text style={styles.handoffIcon}>🧺</Text>
                  <View style={styles.flexOne}><Text style={[styles.handoffTitle, fulfillment === 'dropoff_pickup' && styles.handoffTitleActive]}>Drop-off & Pick-up</Text><Text style={[styles.handoffCopy, fulfillment === 'dropoff_pickup' && styles.handoffCopyActive]}>Bring laundry to the shop and collect it when it is ready.</Text></View>
                </Pressable>
              </View>
              {fulfillment !== 'dropoff_pickup' ? <View>
                <Text style={styles.addressLabel}>Complete delivery address</Text>
                <TextInput value={deliveryAddress} editable={false} onChangeText={setDeliveryAddress} maxLength={255} placeholder="House/Blk/Lot No., Street, Barangay, Tarlac City" placeholderTextColor="#a59ba5" style={styles.deliveryAddressInput} />
                <Text style={styles.addressHelp}>{fulfillment === 'pickup_delivery' ? 'We use your saved address and pin for collection and final delivery. Change it in Profile before booking.' : 'We use your saved address and pin for delivery. Change it in Profile before booking.'}</Text>
              </View> : null}
              <View style={styles.panelTitleRow}>
                <Text style={styles.panelIcon}>🗓</Text>
                <View style={styles.flexOne}><Text style={styles.panelTitle}>Preferred schedule</Text><Text style={styles.panelSub}>{fulfillment === 'pickup_delivery' ? 'Choose when the driver should collect your laundry.' : 'Choose when you plan to drop off at the shop.'}</Text></View>
                <Text style={styles.pickupBadgeText}>OPTIONAL</Text>
              </View>
              <Pressable onPress={() => {
                const savedSchedule = pickupAt ? new Date(pickupAt) : null;
                const initialDate = savedSchedule && savedSchedule > new Date() ? savedSchedule : new Date();
                const localDate = [initialDate.getFullYear(), String(initialDate.getMonth() + 1).padStart(2, '0'), String(initialDate.getDate()).padStart(2, '0')].join('-');
                setPickupDate(localDate);
                setPickupTime(savedSchedule && savedSchedule > new Date() ? String(initialDate.getHours()).padStart(2, '0') + ':00' : '08:00');
                setCalendarMonth(new Date(initialDate.getFullYear(), initialDate.getMonth(), 1));
                setPickupError('');
                setPickupModal(true);
              }} style={styles.pickupButton}>
                <Text style={styles.pickupButtonText}>{pickupAt ? (fulfillment === 'pickup_delivery' ? 'Pickup: ' : 'Drop-off: ') + new Date(pickupAt).toLocaleString() : '＋  Select a ' + (fulfillment === 'pickup_delivery' ? 'pickup' : 'drop-off') + ' date and time'}</Text>
              </Pressable>
              {pickupAt ? <Pressable onPress={() => setPickupAt(null)}><Text style={styles.clearSchedule}>Remove suggested time</Text></Pressable> : null}
            </View>

            <View style={styles.paymentPanel}>
              <Text style={styles.panelTitle}>Payment method</Text>
              <View style={styles.fulfillmentRow}>
                <Pressable onPress={() => setPaymentMethod('cash_on_handoff')} style={[styles.fulfillmentChoice, paymentMethod === 'cash_on_handoff' && styles.fulfillmentChoiceActive]}>
                  <Text style={[styles.fulfillmentChoiceText, paymentMethod === 'cash_on_handoff' && styles.fulfillmentChoiceTextActive]}>{fulfillment === 'pickup_delivery' ? 'Pay driver upon pickup' : 'Pay at shop upon drop-off'}</Text>
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
              ) : <Text style={styles.addressHelp}>Pay during the initial laundry handoff after the shop confirms your request.</Text>) : (
                <Text style={styles.addressHelp}>The shop has not added its GCash account yet. Payment during handoff is available.</Text>
              )}
            </View>

            <View style={styles.notesPanel}>
              <Text style={styles.panelTitle}>Special instructions</Text>
              <TextInput value={notes} onChangeText={(value) => setNotes(value.slice(0, 255))} multiline maxLength={255} placeholder="Add a note for the shop (optional)" placeholderTextColor="#a59ba5" style={styles.notesInput} />
              <Text style={styles.noteCount}>{notes.length}/255</Text>
              {fulfillment !== 'dropoff_pickup' ? <View style={styles.estimateRow}><Text style={styles.estimateLabel}>Delivery fee</Text><Text style={styles.estimateValue}>{deliveryQuoteLoading && !deliveryAvailable ? '…' : money(deliveryFee)}</Text></View> : null}
              <View style={styles.estimateRow}><Text style={styles.estimateLabel}>Estimated total</Text><Text style={styles.estimateValue}>{money(estimate)}</Text></View>
              <Pressable onPress={bookService} disabled={sending || estimate <= 0 || (fulfillment !== 'dropoff_pickup' && !deliveryAvailable && !deliveryQuote) || (paymentMethod === 'gcash' && gcashReference.trim().length < 6)} style={[styles.bookButton, (sending || estimate <= 0 || (fulfillment !== 'dropoff_pickup' && !deliveryAvailable && !deliveryQuote) || (paymentMethod === 'gcash' && gcashReference.trim().length < 6)) && styles.bookButtonDisabled]}>
                {sending ? <ActivityIndicator color={COLORS.white} /> : <Text style={styles.bookButtonText}>Book Service</Text>}
              </Pressable>
              <Text style={styles.estimateNote}>This is an estimate. The shop will confirm your service, price, and handoff and final delivery schedule.</Text>
            </View>
          </View>
        </ScrollView>
      ) : tab === 'track' ? (
        <ScrollView contentContainerStyle={styles.homeScroll}>
          <View style={styles.pageWidth}>
            <HeroBanner compact />
            <View style={styles.ticketHeadingRow}>
              <View><Text style={styles.greeting}>My service tickets</Text><Text style={styles.welcomeCopy}>Track the status of your laundry bookings.</Text></View>
              <Pressable accessibilityRole="button" accessibilityLabel="Refresh" disabled={refreshing} onPress={() => loadShopData()} style={[styles.refreshButton, refreshing && styles.disabledButton]}>{refreshing ? <ActivityIndicator color={COLORS.purple} size="small" /> : <Text style={styles.refreshText}>↻</Text>}</Pressable>
            </View>
            {toast ? <Text style={styles.successBox}>{toast}</Text> : null}
            {error ? <Text style={styles.errorBox}>{error}</Text> : null}
            {loading ? <View style={styles.inlineLoading}><ActivityIndicator color={COLORS.purple} /><Text style={styles.mutedText}>Loading your tickets…</Text></View> : null}
            {!loading && tickets.length === 0 ? <View style={styles.emptyCard}><Text style={styles.emptyEmoji}>📋</Text><Text style={styles.emptyTitle}>No service tickets yet</Text><Text style={styles.mutedText}>Your bookings will appear here after you book a service.</Text><Pressable onPress={() => navigateTo('offers')} style={styles.smallPrimary}><Text style={styles.smallPrimaryText}>Browse services</Text></Pressable></View> : null}
            {tickets.map((ticket) => <TicketCard key={ticket.id || ticket.order_number} ticket={ticket} onShareLocation={() => updateCustomerLocation(ticket, 'share')} onRevokeLocation={() => updateCustomerLocation(ticket, 'revoke')} locationBusy={Boolean(locationBusy[String(ticket.id)])} locationError={locationError[String(ticket.id)]} retryReference={retryReferences[String(ticket.id)] || ''} onRetryReferenceChange={(value) => setRetryReferences((current) => ({ ...current, [String(ticket.id)]: value }))} onResubmitGcash={() => resubmitGcashReference(ticket)} retryBusy={Boolean(retryBusy[String(ticket.id)])} />)}
          </View>
        </ScrollView>
      ) : tab === 'about' ? (
        <ScrollView contentContainerStyle={styles.homeScroll}>
          <View style={styles.pageWidth}>
            <HeroBanner />
            <View style={styles.contentCard}>
              <Text style={styles.contentEyebrow}>ABOUT US</Text>
              <Text style={styles.contentTitle}>Clean clothes and convenient service for every customer.</Text>
              <Text style={styles.contentText}>Pia&apos;s Laundry Shop provides reliable laundry care with online booking, shop confirmation, pickup or delivery choices, and service ticket tracking.</Text>
            </View>
            <View style={styles.aboutGrid}>
              <View style={styles.aboutCard}><Text style={styles.aboutIcon}>📍</Text><Text style={styles.aboutTitle}>Visit us</Text><Text style={styles.aboutText}>Blk. 2, Brgy. San Manuel, Tarlac City</Text></View>
              <View style={styles.aboutCard}><Text style={styles.aboutIcon}>☎️</Text><Text style={styles.aboutTitle}>Contact us</Text><Text style={styles.aboutText}>0918-967-9623</Text></View>
              <View style={styles.aboutCard}><Text style={styles.aboutIcon}>✅</Text><Text style={styles.aboutTitle}>Shop confirmed</Text><Text style={styles.aboutText}>The shop confirms the final service, price, and schedule.</Text></View>
              <View style={styles.aboutCard}><Text style={styles.aboutIcon}>📱</Text><Text style={styles.aboutTitle}>Track online</Text><Text style={styles.aboutText}>Follow the latest status of every service ticket.</Text></View>
            </View>
            <View style={styles.iconAttribution}><Text style={styles.iconAttributionLabel}>Navigation icon credits</Text><Text style={styles.iconAttributionText}>Designed by <Text style={styles.iconAttributionLink} onPress={() => Linking.openURL('https://www.flaticon.com/free-icon/home_16812417')}>pictranoosa</Text>, <Text style={styles.iconAttributionLink} onPress={() => Linking.openURL('https://www.flaticon.com/free-icon/receipt_12449398')}>zero_wing</Text>, <Text style={styles.iconAttributionLink} onPress={() => Linking.openURL('https://www.flaticon.com/free-icon/laundry-basket_10638619')}>Phoebeicons</Text>, <Text style={styles.iconAttributionLink} onPress={() => Linking.openURL('https://www.flaticon.com/free-icon/information_9554031')}>Anggara</Text>, and <Text style={styles.iconAttributionLink} onPress={() => Linking.openURL('https://www.flaticon.com/free-icon/user-profile_10994782')}>Saepul Nahwan</Text> from Flaticon.</Text></View>
          </View>
        </ScrollView>
      ) : (
        <ScrollView contentContainerStyle={styles.homeScroll}>
          <View style={styles.pageWidth}>
            <View style={styles.profileHeader}>
              <View style={styles.profileAvatarWrap}><View style={styles.profileAvatar}>{customer.profile_photo ? <Image source={{ uri: customer.profile_photo }} style={styles.profileAvatarImage} /> : <Text style={styles.profileAvatarText}>{String(customer.name || 'C').charAt(0).toUpperCase()}</Text>}</View><Pressable accessibilityRole="button" accessibilityLabel="Change profile picture" disabled={profileDetailsSaving} onPress={() => setProfilePhotoPickerVisible(true)} style={[styles.profilePhotoButton, profileDetailsSaving && styles.disabledButton]}><Text style={styles.profilePhotoButtonText}>📷</Text></Pressable></View>
              <Text style={styles.profileName}>{customer.name || 'Customer'}</Text>
              <Text style={styles.profileEmail}>{customer.email || ''}</Text>
            </View>
            <View style={styles.profileCard}>
              <ProfileRow icon={String.fromCodePoint(0x1F464)} label="Full name" value={customer.name} editable editing={profileDetailsEditing === 'name'} draft={profileNameDraft} onChangeDraft={setProfileNameDraft} onEdit={() => { setProfileNameDraft(customer.name || ''); setProfileDetailsError(''); setProfileDetailsEditing('name'); }} onSave={() => saveProfileDetail('name')} onCancel={() => setProfileDetailsEditing('')} saving={profileDetailsSaving} />
              <ProfileRow icon={String.fromCodePoint(0x2709)} label="Email address" value={customer.email} />
              <ProfileRow icon={String.fromCodePoint(0x260E)} label="Phone number" value={customer.phone} editable editing={profileDetailsEditing === 'phone'} draft={profilePhoneDraft} onChangeDraft={(value) => setProfilePhoneDraft(value.replace(/[^0-9]/g, '').slice(0, 11))} keyboardType="phone-pad" onEdit={() => { setProfilePhoneDraft(customer.phone || ''); setProfileDetailsError(''); setProfileDetailsEditing('phone'); }} onSave={() => saveProfileDetail('phone')} onCancel={() => setProfileDetailsEditing('')} saving={profileDetailsSaving} />
              <ProfileRow icon={String.fromCodePoint(0x1F4CD)} label="Delivery address" value={customer.address} last />
            </View>
            {profileDetailsError ? <Text style={styles.errorBox}>{profileDetailsError}</Text> : null}
            <View style={styles.profileLocationCard}>
              <Text style={styles.profileLocationTitle}>Address and saved location</Text>
              <Text style={styles.profileLocationCopy}>Save one delivery address and one map pin. Choose a search result, then move the marker to your exact home or gate. Free delivery covers San Jose, San Manuel, and Balingcanaway, Tarlac; distance-based fees apply outside these areas.</Text>
              {editingLocation ? <>
                <PlaceAddressSearch apiBase={apiBase} value={profileAddress} onChangeText={(value) => { setProfileAddress(value); setProfilePin(null); }} onPlaceSelected={selectProfilePlace} disabled={profileSaving} />
                <Text style={styles.profileAddressLabel}>Address Detail (optional)</Text>
                <TextInput value={profileAddressDetails} onChangeText={setProfileAddressDetails} maxLength={180} placeholder="House number, unit, gate, or nearby landmark" placeholderTextColor="#a59ba5" style={styles.deliveryAddressInput} />
                {profilePin ? <><Text style={[styles.locationPinSuccess, !profilePin.deliveryAvailable && styles.locationPinWarning]}>{profilePin.deliveryAvailable ? 'This pin is in the free delivery area.' : 'This pin is outside the free delivery area. Delivery is available for a distance-based fee.'}</Text><Text style={styles.profileLocationCopy}>Drag the marker or tap the exact spot for the driver. Tap “Full-screen map” to preview it larger.</Text><View style={styles.registrationMap}><DeliveryMap location={profilePin} label="Delivery pin" editable onLocationChange={adjustProfilePin} /></View></> : <Text style={styles.profileLocationCopy}>Select one address from the search suggestions to place its pin on the map.</Text>}
                {profileLocationError ? <Text style={styles.errorBox}>{profileLocationError}</Text> : null}
                <View style={styles.profileLocationActions}><Pressable disabled={profileSaving} onPress={() => setEditingLocation(false)} style={styles.secondaryButton}><Text style={styles.secondaryButtonText}>Cancel</Text></Pressable><Pressable disabled={profileSaving} onPress={saveProfileLocation} style={[styles.primaryButton, profileSaving && styles.disabledButton]}>{profileSaving ? <ActivityIndicator color={COLORS.white} /> : <Text style={styles.primaryButtonText}>Save address</Text>}</Pressable></View>
              </> : <><Text style={[styles.locationPinSuccess, !isDeliveryArea(customer.location_latitude, customer.location_longitude) && styles.locationPinWarning]}>{isDeliveryArea(customer.location_latitude, customer.location_longitude) ? 'Your saved pin is in the free delivery area.' : 'Your saved pin is outside the free delivery area. Delivery is available for a distance-based fee.'}</Text><Pressable onPress={beginLocationEdit} style={styles.locationPinButton}><Text style={styles.locationPinButtonText}>Edit address</Text></Pressable></>}
            </View>
            <Pressable onPress={onSignOut} style={styles.signOutButton}><Text style={styles.signOutButtonText}>Sign out</Text></Pressable>
          </View>
        </ScrollView>
      )}

      <View style={styles.bottomNav}>
        <NavItem icon="home" label="Home" active={tab === 'home'} onPress={() => navigateTo('home')} />
        <NavItem icon="track" label="Track" active={tab === 'track'} onPress={() => navigateTo('track')} />
        <NavItem icon="offers" label="Offers" active={tab === 'offers'} onPress={() => navigateTo('offers')} />
        <NavItem icon="about" label="About" active={tab === 'about'} onPress={() => navigateTo('about')} />
        <NavItem icon="profile" label="Profile" active={tab === 'profile'} onPress={() => navigateTo('profile')} />
      </View>

      <Modal transparent visible={profilePhotoPickerVisible} animationType="fade" onRequestClose={() => setProfilePhotoPickerVisible(false)}>
        <View style={styles.modalShade}><View style={styles.modalCard}>
          <Text style={styles.modalTitle}>Profile picture</Text><Text style={styles.modalCopy}>Take a new photo or choose one from your gallery.</Text>
          <Pressable disabled={profileDetailsSaving} onPress={() => chooseProfilePhoto('camera')} style={styles.profilePhotoOption}><Text style={styles.profilePhotoOptionText}>📷  Take photo</Text></Pressable>
          <Pressable disabled={profileDetailsSaving} onPress={() => chooseProfilePhoto('gallery')} style={styles.profilePhotoOption}><Text style={styles.profilePhotoOptionText}>🖼️  Choose from gallery</Text></Pressable>
          <Pressable onPress={() => setProfilePhotoPickerVisible(false)} style={styles.secondaryButton}><Text style={styles.secondaryButtonText}>Cancel</Text></Pressable>
        </View></View>
      </Modal>
      <Modal transparent visible={profilePhotoPreviewVisible} animationType="fade" onRequestClose={() => { setProfilePhotoPreviewVisible(false); setProfilePhotoDraft(null); }}>
        <View style={styles.modalShade}><View style={styles.modalCard}>
          <Text style={styles.modalTitle}>Save profile picture</Text>
          <Text style={styles.modalCopy}>The center of this image will be used for your profile photo.</Text>
          {profileDetailsError ? <Text style={styles.errorBox}>{profileDetailsError}</Text> : null}
          {profilePhotoDraft ? <Image source={{ uri: profilePhotoDraft.uri }} style={styles.profilePhotoPreview} /> : null}
          <Pressable disabled={profileDetailsSaving} onPress={saveProfilePhoto} style={({ pressed }) => [styles.primaryButton, pressed && !profileDetailsSaving && styles.pressed, profileDetailsSaving && styles.disabledButton]}>
            {profileDetailsSaving ? <ActivityIndicator color={COLORS.white} /> : <Text style={styles.primaryButtonText}>Save</Text>}
          </Pressable>
          <Pressable disabled={profileDetailsSaving} onPress={() => { setProfilePhotoPreviewVisible(false); setProfilePhotoDraft(null); }} style={styles.secondaryButton}><Text style={styles.secondaryButtonText}>Cancel</Text></Pressable>
        </View></View>
      </Modal>
      <Modal transparent visible={pickupModal} animationType="fade" onRequestClose={() => setPickupModal(false)}>
        <View style={styles.modalShade}>
          <View style={styles.modalCard}>
            <Text style={styles.modalTitle}>{fulfillment === 'pickup_delivery' ? 'Pickup Date & Time' : 'Drop-off Date & Time'}</Text>
            <Text style={styles.modalCopy}>Choose a future schedule within shop hours, 8:00 AM to 6:00 PM.</Text>
            {pickupError ? <Text style={styles.modalError}>{pickupError}</Text> : null}
            <Text style={styles.scheduleSectionTitle}>Choose a date</Text>
            <View style={styles.scheduleCalendar}>
              <View style={styles.scheduleCalendarHeader}>
                <Pressable
                  accessibilityLabel="Previous month"
                  disabled={!canShowPreviousScheduleMonth}
                  onPress={() => setCalendarMonth((current) => new Date(current.getFullYear(), current.getMonth() - 1, 1))}
                  style={[styles.calendarArrow, !canShowPreviousScheduleMonth && styles.calendarArrowDisabled]}
                ><Text style={styles.calendarArrowText}>{'<'}</Text></Pressable>
                <Text style={styles.calendarMonthLabel}>{calendarMonth.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })}</Text>
                <Pressable
                  accessibilityLabel="Next month"
                  onPress={() => setCalendarMonth((current) => new Date(current.getFullYear(), current.getMonth() + 1, 1))}
                  style={styles.calendarArrow}
                ><Text style={styles.calendarArrowText}>{'>'}</Text></Pressable>
              </View>
              <View style={styles.calendarWeekdayRow}>{['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map((day) => <Text key={day} style={styles.calendarWeekday}>{day}</Text>)}</View>
              <View style={styles.calendarGrid}>
                {scheduleCalendarDays.map((date, index) => date ? (
                  <Pressable
                    key={date.value}
                    disabled={date.isPast}
                    onPress={() => { setPickupDate(date.value); setPickupError(''); }}
                    style={[styles.calendarDay, pickupDate === date.value && styles.calendarDaySelected, date.isPast && styles.calendarDayDisabled]}
                  ><Text style={[styles.calendarDayText, pickupDate === date.value && styles.calendarDaySelectedText, date.isPast && styles.calendarDayDisabledText]}>{date.day}</Text></Pressable>
                ) : <View key={'blank-' + index} style={styles.calendarDay} />)}
              </View>
            </View>
            <Text style={styles.scheduleSectionTitle}>Available time slots</Text>
            <View style={styles.scheduleSlotGrid}>
              {scheduleSlots.map((slot) => {
                const slotDate = pickupDate ? new Date(pickupDate + 'T' + slot.value + ':00') : null;
                const unavailable = !pickupDate || (slotDate && slotDate <= new Date());
                return <Pressable key={slot.value} disabled={unavailable} onPress={() => { setPickupTime(slot.value); setPickupError(''); }} style={[styles.scheduleSlot, pickupTime === slot.value && !unavailable && styles.scheduleSelected, unavailable && styles.scheduleDisabled]}><Text style={[styles.scheduleSlotText, pickupTime === slot.value && !unavailable && styles.scheduleSelectedText]}>{slot.label}</Text></Pressable>;
              })}
            </View>
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

function NavItem({ icon, label, active, onPress }) {
  return <Pressable accessibilityRole="button" accessibilityLabel={label} onPress={onPress} style={[styles.navItem, active && styles.navItemActive]}><Image source={NAV_ICON_ASSETS[icon]} style={[styles.navIcon, icon !== 'offers' && { tintColor: active ? COLORS.plum : '#8a8089' }]} resizeMode="contain" /><Text style={[styles.navLabel, active && styles.navLabelActive]}>{label}</Text></Pressable>;
}
function ProfileRow({ icon, label, value, last = false, editable = false, editing = false, draft = '', onChangeDraft, onEdit, onSave, onCancel, saving = false, keyboardType = 'default' }) {
  return <View style={[styles.profileRow, last && styles.profileRowLast]}><View style={styles.profileRowIcon}><Text style={styles.profileRowIconText}>{icon}</Text></View><View style={styles.flexOne}><Text style={styles.profileRowLabel}>{label}</Text>{editing ? <TextInput autoFocus value={draft} onChangeText={onChangeDraft} keyboardType={keyboardType} maxLength={label === 'Full name' ? 100 : 11} placeholder={label === 'Full name' ? 'Your full name' : '09xx xxx xxxx'} placeholderTextColor="#a59ba5" style={styles.profileRowInput} editable={!saving} /> : <Text style={styles.profileRowValue}>{value || 'Not provided'}</Text>}</View>{editable ? editing ? <View style={styles.profileRowEditActions}><Pressable accessibilityRole="button" accessibilityLabel={'Save ' + label} disabled={saving} onPress={onSave} style={styles.profileRowEditButton}><Text style={styles.profileRowEditText}>{saving ? '…' : '✓'}</Text></Pressable><Pressable accessibilityRole="button" accessibilityLabel="Cancel edit" disabled={saving} onPress={onCancel} style={styles.profileRowEditButton}><Text style={styles.profileRowCancelText}>×</Text></Pressable></View> : <Pressable accessibilityRole="button" accessibilityLabel={'Edit ' + label} onPress={onEdit} style={styles.profileRowEditButton}><Text style={styles.profileRowEditText}>✎</Text></Pressable> : null}</View>;
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
          <View style={styles.quantityControl}>
            <Pressable accessibilityLabel={'Remove one ' + service.name} onPress={() => onQuantityChange(String(Math.max(0, (Number(quantity) || 0) - 1)))} style={styles.quantityButton}><Text style={styles.quantityButtonText}>−</Text></Pressable>
            <View style={styles.quantityBox}>
              <TextInput value={String(quantity)} onChangeText={onQuantityChange} keyboardType="decimal-pad" placeholder="0" placeholderTextColor="#9d929c" style={styles.quantityInput} accessibilityLabel={'Quantity for ' + service.name} />
              <Text style={styles.quantityUnit}>{service.unit || 'unit'}</Text>
            </View>
            <Pressable accessibilityLabel={'Add one ' + service.name} onPress={() => onQuantityChange(String((Number(quantity) || 0) + 1))} style={styles.quantityButton}><Text style={styles.quantityButtonText}>+</Text></Pressable>
          </View>
        </View>
      </View>
    </View>
  );
}

function OrderProgress({ ticket }) {
  const isPickup = ticket.handoff_type === 'pickup_delivery';
  const hasDelivery = ['pickup_delivery', 'dropoff_delivery'].includes(ticket.handoff_type);
  const isDropoffPickup = ticket.handoff_type === 'dropoff_pickup';
  const steps = [
    { key: 'pending', icon: '🧾', label: 'Requested', detail: 'Booking sent to the shop.' },
    { key: 'confirmed', icon: '✅', label: 'Confirmed', detail: 'The shop accepted your request.' },
    ...(isPickup ? [{ key: 'picked_up_for_service', icon: '🚚', label: 'Picked up', detail: 'Driver collected your laundry.' }] : []),
    { key: 'received', icon: '📦', label: 'Received', detail: 'Laundry arrived at the shop.' },
    { key: 'processing', icon: '🫧', label: 'In process', detail: 'Laundry service is in progress.' },
    {
      key: 'ready_for_pickup',
      icon: '🧺',
      label: isDropoffPickup ? 'Ready to claim' : 'Ready',
      detail: isDropoffPickup ? 'Your laundry is ready for collection at the shop.' : 'Laundry is ready for delivery.',
    },
    ...(hasDelivery ? [
      { key: 'out_for_delivery', icon: '🚐', label: 'Out for delivery', detail: 'Driver is bringing it to you.' },
      { key: 'completed', icon: '🏠', label: 'Delivered', detail: 'Laundry was delivered successfully.' },
    ] : []),
  ];
  const statusToStep = {
    pending: 'pending', confirmed: 'confirmed', picked_up_for_service: 'picked_up_for_service', received: 'received',
    weighing: 'processing', washing: 'processing', drying: 'processing', folding: 'processing', ready: 'ready_for_pickup',
    ready_for_pickup: 'ready_for_pickup', out_for_delivery: hasDelivery ? 'out_for_delivery' : 'ready_for_pickup',
    completed: hasDelivery ? 'completed' : 'ready_for_pickup', picked_up: hasDelivery ? 'completed' : 'ready_for_pickup',
  };
  const currentKey = statusToStep[ticket.status] || 'pending';
  const currentIndex = Math.max(0, steps.findIndex((step) => step.key === currentKey));
  const history = Array.isArray(ticket.history) ? ticket.history : [];
  const historyFor = (key) => history.find((item) => key === 'processing' ? ['weighing', 'washing', 'drying', 'folding'].includes(item.status) : item.status === key);
  if (['cancelled', 'rejected'].includes(ticket.status)) {
    return <View style={styles.progressCard}><Text style={styles.progressCancelled}>{ticket.status === 'cancelled' ? 'Order cancelled' : 'Request declined'}</Text></View>;
  }
  return (
    <View style={styles.progressCard}>
      <Text style={styles.progressHeading}>Laundry journey</Text>
      {steps.map((step, index) => {
        const done = index <= currentIndex;
        const current = index === currentIndex;
        const event = historyFor(step.key);
        return (
          <View key={step.key} style={styles.progressRow}>
            <View style={styles.progressRail}>
              <View style={[styles.progressDot, done && styles.progressDotDone]}><Text style={styles.progressIcon}>{step.icon}</Text></View>
              {index < steps.length - 1 ? <View style={[styles.progressLine, index < currentIndex && styles.progressLineDone]} /> : null}
            </View>
            <View style={styles.progressText}>
              <Text style={[styles.progressLabel, !done && styles.progressLabelFuture]}>{step.label}{current ? ' · Current' : ''}</Text>
              <Text style={[styles.progressDetail, !done && styles.progressDetailFuture]}>{step.detail}</Text>
              {event?.created_at ? <Text style={styles.progressTime}>{formatDate(event.created_at)}</Text> : null}
            </View>
          </View>
        );
      })}
    </View>
  );
}
function TicketCard({ ticket, onShareLocation, onRevokeLocation, locationBusy, locationError, retryReference, onRetryReferenceChange, onResubmitGcash, retryBusy }) {
  const lines = (ticket.services || []).map((line) => `${line.service_name} (${line.quantity} ${line.unit})`).join(' · ');
  const status = ticket.handoff_type === 'dropoff_pickup' && ['ready', 'ready_for_pickup'].includes(ticket.status)
    ? 'Ready to claim'
    : ticket.order_type === 'delivery_request' && ticket.status === 'ready_for_pickup' ? 'Ready for delivery' : statusLabel(ticket.status);
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
        <Text style={styles.ticketDetail}>{ticket.handoff_type === 'pickup_delivery' ? 'Pickup & Delivery' : ticket.handoff_type === 'dropoff_delivery' ? 'Drop-off & Delivery' : 'Drop-off & Pick-up'}</Text>
        <OrderProgress ticket={ticket} />
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
                <Text style={styles.ticketDetail}>Location sharing is optional and updates while this app is open and connected.</Text>
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
        <View style={styles.ticketTotals}>{Number(ticket.delivery_fee) > 0 ? <Text style={styles.ticketDetail}>Distance-based delivery fee: {money(ticket.delivery_fee)}</Text> : null}
          <View><Text style={styles.ticketTotalLabel}>Estimated total</Text><Text style={styles.ticketTotalValue}>{money(ticket.total)}</Text></View>
          <View style={styles.ticketBalance}><Text style={styles.ticketTotalLabel}>Balance due</Text><Text style={styles.ticketTotalValue}>{money(ticket.balance_due)}</Text></View>
        </View>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({

  welcomeRoot: { flex: 1, overflow: 'hidden', alignItems: 'center', justifyContent: 'center' },
  welcomeScroll: { flex: 1, width: '100%' },
  welcomeScrollContent: { flexGrow: 1, alignItems: 'center', justifyContent: 'center', padding: 24 },
  welcomeScrollContentLandscape: { paddingVertical: 10, paddingHorizontal: 20 },
  welcomeContent: { width: '100%', maxWidth: 520, alignItems: 'center', zIndex: 2 },
  welcomeContentLandscape: { maxWidth: 680 },
  welcomeLogoShellLandscape: { width: 82, height: 82, borderRadius: 25, padding: 8, marginBottom: 8 },
  welcomeLogoShell: { width: 168, height: 168, borderRadius: 48, backgroundColor: COLORS.white, padding: 14, marginBottom: 24, shadowColor: '#000', shadowOpacity: 0.25, shadowRadius: 18, shadowOffset: { width: 0, height: 10 }, elevation: 8 },
  welcomeLogo: { width: '100%', height: '100%' },
  welcomeBrandLandscape: { fontSize: 26, lineHeight: 31 },
  welcomeBrand: { color: COLORS.white, fontSize: 34, lineHeight: 40, fontWeight: '900', textAlign: 'center' },
  welcomeTaglineLandscape: { fontSize: 15, marginTop: 2 },
  welcomeTagline: { color: '#f3d4f7', fontSize: 19, fontWeight: '700', marginTop: 8, textAlign: 'center' },
  welcomeDescriptionLandscape: { fontSize: 12, lineHeight: 16, marginTop: 6 },
  welcomeDescription: { color: '#f3e8f4', fontSize: 15, lineHeight: 23, textAlign: 'center', maxWidth: 440, marginTop: 14 },
  welcomeFeatureRow: { width: '100%', flexDirection: 'row', gap: 8, marginVertical: 26 },
  welcomeFeatureRowLandscape: { marginVertical: 10 },
  welcomeFeature: { flex: 1, minHeight: 85, borderRadius: 16, backgroundColor: '#ffffff17', borderColor: '#ffffff2d', borderWidth: 1, alignItems: 'center', justifyContent: 'center', padding: 8 },
  welcomeFeatureIcon: { fontSize: 25, marginBottom: 5 },
  welcomeFeatureText: { color: COLORS.white, fontSize: 11, lineHeight: 15, fontWeight: '700', textAlign: 'center' },
  welcomeStartLandscape: { minHeight: 48 },
  welcomeStart: { width: '100%', minHeight: 56, borderRadius: 28, paddingHorizontal: 22, backgroundColor: COLORS.cream, flexDirection: 'row', alignItems: 'center', justifyContent: 'center' },
  welcomeStartText: { color: COLORS.plum, fontWeight: '900', fontSize: 16 },
  welcomeStartArrow: { position: 'absolute', right: 22, color: COLORS.plum, fontSize: 25 },
  welcomeAddress: { color: '#decce2', fontSize: 11, textAlign: 'center', marginTop: 20 },
  welcomeAddressLandscape: { marginTop: 8 },
  welcomeBubbleOne: { position: 'absolute', width: 340, height: 340, borderRadius: 170, backgroundColor: '#ffffff09', right: -155, top: -80 },
  welcomeBubbleTwo: { position: 'absolute', width: 270, height: 270, borderRadius: 135, backgroundColor: '#e5a6ed0c', left: -150, bottom: -65 },
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
  authSafeArea: { flex: 1 },
  authContainer: { flex: 1, width: '100%', maxWidth: 520, alignSelf: 'center', gap: 12, paddingHorizontal: 16, paddingTop: 8, paddingBottom: 8, minHeight: 0 },
  authContainerCompact: { gap: 12, paddingBottom: 8 },
  hero: { minHeight: 184, borderRadius: 20, overflow: 'hidden', justifyContent: 'center', paddingHorizontal: 24, marginTop: 16, marginBottom: 20 },
  heroCompact: { minHeight: 145, marginTop: 4, marginBottom: 4, paddingHorizontal: 20 },
  heroKeyboardCompact: { minHeight: 82, marginTop: 0, marginBottom: 0, paddingHorizontal: 12 },
  heroContent: { flexDirection: 'row', alignItems: 'center', gap: 16, zIndex: 1 },
  heroContentKeyboardCompact: { gap: 9 },
  heroIcon: { width: 70, height: 70, borderRadius: 22, backgroundColor: '#fff7e9', alignItems: 'center', justifyContent: 'center', transform: [{ rotate: '-5deg' }] },
  heroIconKeyboardCompact: { width: 50, height: 50, borderRadius: 16 },
  heroEmoji: { fontSize: 38 },
  heroEmojiKeyboardCompact: { fontSize: 27 },
  heroTextBlock: { flex: 1 },
  heroTitle: { color: COLORS.white, fontWeight: '800', fontSize: 25, marginBottom: 7 },
  heroTitleKeyboardCompact: { fontSize: 16, marginBottom: 1 },
  heroDetail: { color: '#f5e9f3', fontSize: 14, marginTop: 2 },
  heroDetailKeyboardCompact: { fontSize: 9, marginTop: 0 },
  heroFloatingEmoji: { fontSize: 44, opacity: 0.95 },
  heroFloatingEmojiKeyboardCompact: { fontSize: 32 },
  heroDecorOne: { position: 'absolute', width: 220, height: 220, borderRadius: 110, backgroundColor: '#ffffff12', right: 22, top: -120 },
  heroDecorTwo: { position: 'absolute', width: 160, height: 160, borderRadius: 80, backgroundColor: '#e0a8e218', right: 145, bottom: -117 },
  authIntro: { alignItems: 'center', paddingHorizontal: 16 },
  authIntroKeyboardHidden: { display: 'none' },
  authTitle: { color: COLORS.cream, fontSize: 25, fontWeight: '800', textAlign: 'center' },
  authDescription: { color: '#c7bac8', textAlign: 'center', marginTop: 6, lineHeight: 21, maxWidth: 410 },
  authCard: { flex: 1, minHeight: 0, width: '100%', borderRadius: 20, backgroundColor: COLORS.cream, borderColor: '#eadfed', borderWidth: 1, padding: 20, gap: 12, ...(Platform.OS === 'web' ? { boxShadow: '0px 8px 16px rgba(0,0,0,0.22)' } : { shadowColor: '#000', shadowOpacity: 0.22, shadowRadius: 16, shadowOffset: { width: 0, height: 8 }, elevation: 6 }) },
  authCardCompact: { padding: 14, borderRadius: 16 },
  authCardScroll: { flex: 1, minHeight: 0 },
  authCardScrollContent: { gap: 12, paddingBottom: 24 },
  authSwitch: { width: '100%', flexDirection: 'row', borderRadius: 15, borderColor: COLORS.line, borderWidth: 1, padding: 4, backgroundColor: '#f2ebf3', marginBottom: 2 },
  authSwitchButton: { flex: 1, minHeight: 42, alignItems: 'center', justifyContent: 'center', borderRadius: 12 },
  authSwitchSelected: { backgroundColor: COLORS.plum },
  authSwitchText: { color: COLORS.muted, fontWeight: '700', fontSize: 14 },
  authSwitchSelectedText: { color: COLORS.white },
  fieldWrap: { width: '100%', gap: 6, marginBottom: 2 },
  fieldLabel: { color: '#514653', fontSize: 13, fontWeight: '700' },
  inputShell: { width: '100%', flexDirection: 'row', overflow: 'hidden', alignItems: 'center', borderRadius: 12, borderColor: '#cfc2d1', borderWidth: 1, backgroundColor: '#fffdf9', minHeight: 47 },
  input: { flexGrow: 1, flexShrink: 1, flexBasis: 0, minWidth: 0, paddingHorizontal: 13, paddingVertical: 11, color: COLORS.ink, fontSize: 15, outlineStyle: 'none' },
  inputRight: { flexShrink: 0, minWidth: 62, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 8 },
  passwordVisibilityButton: { minWidth: 52, minHeight: 42, alignItems: 'center', justifyContent: 'center' },
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
  placeSearchWrap: { marginBottom: 12, zIndex: 3 },
  placeSuggestions: { marginTop: 5, borderWidth: 1, borderColor: '#d5c8d7', borderRadius: 10, backgroundColor: COLORS.white, overflow: 'hidden' },
  placeSuggestion: { paddingHorizontal: 12, paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: '#eee5ee' },
  placeSuggestionPrimary: { color: COLORS.ink, fontSize: 13, fontWeight: '700' },
  placeSuggestionSecondary: { color: COLORS.muted, fontSize: 11, marginTop: 2 },
  placeSearchError: { color: COLORS.red, fontSize: 11, marginTop: 5 },
  placeAttribution: { color: COLORS.muted, fontSize: 10, marginTop: 5 },
  locationPinPanel: { marginBottom: 12, padding: 12, borderRadius: 12, borderWidth: 1, borderColor: '#d5c8d7', backgroundColor: '#f7eff8' },
  locationPinTitle: { color: COLORS.plum, fontSize: 13, fontWeight: '800' },
  locationPinCopy: { color: COLORS.muted, fontSize: 11, lineHeight: 16, marginTop: 3 },
  locationPinButton: { minHeight: 40, borderRadius: 11, backgroundColor: COLORS.plum, alignItems: 'center', justifyContent: 'center', marginTop: 10 },
  locationPinButtonText: { color: COLORS.white, fontWeight: '800', fontSize: 13 },
  locationPinSuccess: { color: '#315c3b', fontSize: 11, fontWeight: '700', marginTop: 8 },
  locationPinWarning: { color: '#8a3c47' },
  registrationMap: { marginTop: 10 },
  successBox: { color: '#314e39', backgroundColor: '#e3f0e5', borderRadius: 12, paddingHorizontal: 13, paddingVertical: 11, fontSize: 14, lineHeight: 20, marginVertical: 8 },
  homeRoot: { flex: 1, backgroundColor: COLORS.dark },
  homeScroll: { paddingHorizontal: 16, paddingBottom: 22, alignItems: 'center' },
  pageWidth: { width: '100%', maxWidth: 1180 },
  welcomeLine: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginBottom: 13 },
  greeting: { color: COLORS.cream, fontSize: 23, fontWeight: '800' },
  welcomeCopy: { color: '#c7bac8', fontSize: 14, marginTop: 3, lineHeight: 20 },
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
  serviceRuleNote: { borderRadius: 13, backgroundColor: '#2b2330', borderColor: '#554359', borderWidth: 1, padding: 12, marginBottom: 14 },
  serviceRuleTitle: { color: '#f4e7f6', fontSize: 13, fontWeight: '900' },
  serviceRuleCopy: { color: '#cfc0d1', fontSize: 11, lineHeight: 17, marginTop: 3 },  serviceGrid: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', gap: 16, paddingBottom: 18 },
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
  quantityControl: { flexDirection: 'row', alignItems: 'center', gap: 5 },
  quantityButton: { width: 34, height: 42, borderRadius: 11, alignItems: 'center', justifyContent: 'center', backgroundColor: COLORS.purpleLight, borderColor: '#d5c8d7', borderWidth: 1 },
  quantityButtonText: { color: COLORS.plum, fontSize: 22, fontWeight: '800', lineHeight: 24 },
  quantityBox: { width: 62, borderRadius: 12, borderWidth: 1, borderColor: '#d5c8d7', backgroundColor: '#fffdf9', alignItems: 'center', padding: 4 },
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
  handoffChoices: { gap: 10, marginVertical: 12 },
  handoffCard: { minHeight: 82, flexDirection: 'row', alignItems: 'center', gap: 12, borderRadius: 15, borderWidth: 1, borderColor: '#6e4a78', padding: 13, backgroundColor: '#211c23' },
  handoffCardActive: { backgroundColor: COLORS.plum, borderColor: '#bd87c7' },
  handoffCardDisabled: { opacity: 0.42 },
  deliveryRestriction: { borderRadius: 12, borderWidth: 1, borderColor: '#a95e65', backgroundColor: '#3b2027', padding: 11, marginTop: 10 },
  deliveryRestrictionTitle: { color: '#ffd4d7', fontWeight: '900', fontSize: 13 },
  deliveryRestrictionCopy: { color: '#f0c6cb', fontSize: 11, lineHeight: 16, marginTop: 3 },
  handoffIcon: { fontSize: 28 },
  handoffTitle: { color: '#d9bce0', fontSize: 14, fontWeight: '900' },
  handoffTitleActive: { color: COLORS.white },
  handoffCopy: { color: '#bcaebe', fontSize: 11, lineHeight: 16, marginTop: 3 },
  handoffCopyActive: { color: '#f0dff3' },
  fulfillmentChoice: { flex: 1, minHeight: 42, borderRadius: 13, borderWidth: 1, borderColor: '#6e4a78', alignItems: 'center', justifyContent: 'center', paddingHorizontal: 8 },
  fulfillmentChoiceActive: { backgroundColor: COLORS.plum, borderColor: COLORS.plum },
  fulfillmentChoiceText: { color: '#d9bce0', fontWeight: '700', fontSize: 13, textAlign: 'center' },
  fulfillmentChoiceTextActive: { color: COLORS.white },
  addressLabel: { color: '#e4d6e6', fontSize: 12, fontWeight: '700', marginBottom: 6 },
  profileAddressLabel: { color: '#41234a', fontSize: 13, fontWeight: '800', marginBottom: 2 },
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
  bottomNav: { minHeight: 68, paddingBottom: Platform.OS === 'ios' ? 8 : 2, backgroundColor: COLORS.cream, borderTopColor: '#ded3df', borderTopWidth: 1, flexDirection: 'row', paddingHorizontal: 3, zIndex: 3 },
  navItem: { flex: 1, minWidth: 0, alignItems: 'center', justifyContent: 'center', gap: 2, borderTopWidth: 3, borderTopColor: 'transparent', paddingVertical: 5 },
  navItemActive: { borderTopColor: COLORS.copper, backgroundColor: '#fbf2e9' },
  navIcon: { width: 20, height: 20 },
  navLabel: { color: '#776c78', fontSize: 10, fontWeight: '700' },
  navLabelActive: { color: COLORS.plum },
  homeWelcomeCard: { borderRadius: 20, padding: 20, backgroundColor: COLORS.cream, borderColor: '#eadfed', borderWidth: 1, marginBottom: 14 },
  homeEyebrow: { color: COLORS.copper, fontSize: 11, fontWeight: '900', letterSpacing: 1.4 },
  homeHeadline: { color: COLORS.ink, fontSize: 25, fontWeight: '900', marginTop: 6 },
  homeLead: { color: COLORS.muted, fontSize: 14, lineHeight: 21, marginTop: 7 },
  homeCta: { minHeight: 48, borderRadius: 15, marginTop: 16, paddingHorizontal: 17, backgroundColor: COLORS.plum, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  homeCtaText: { color: COLORS.white, fontWeight: '800', fontSize: 15 },
  homeCtaArrow: { color: COLORS.white, fontSize: 23 },
  homeTrackCta: { minHeight: 45, borderRadius: 15, marginTop: 10, paddingHorizontal: 17, borderColor: '#d9bddf', borderWidth: 1, backgroundColor: '#251e28', flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  homeTrackCtaText: { color: '#f1e3f2', fontWeight: '800', fontSize: 14 },
  homeTrackCtaArrow: { color: '#e5b4ec', fontSize: 20 },
  homeStatsRow: { flexDirection: 'row', gap: 10, marginBottom: 22 },
  homeStat: { flex: 1, padding: 15, borderRadius: 16, backgroundColor: COLORS.darkPanel, borderColor: '#493b4c', borderWidth: 1 },
  homeStatValue: { color: COLORS.cream, fontSize: 25, fontWeight: '900' },
  homeStatLabel: { color: '#c7bac8', fontSize: 11, marginTop: 3 },
  sectionHeadingRow: { flexDirection: 'row', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 12 },
  sectionHeading: { color: COLORS.cream, fontSize: 20, fontWeight: '900' },
  sectionCopy: { color: '#bdb0bf', fontSize: 12, marginTop: 3 },
  sectionLink: { color: '#deb4e5', fontWeight: '800', fontSize: 12, padding: 7 },
  homeServiceGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginBottom: 18 },
  homeServiceCard: { width: '48%', flexGrow: 1, minHeight: 156, padding: 14, borderRadius: 17, backgroundColor: COLORS.cream, borderColor: '#e1d6e3', borderWidth: 1 },
  homeServiceIcon: { width: 58, height: 58, borderRadius: 29, alignItems: 'center', justifyContent: 'center', backgroundColor: '#eee2f1', marginBottom: 10 },
  homeServiceEmoji: { fontSize: 28 },
  homeServiceName: { color: COLORS.ink, fontSize: 14, lineHeight: 18, fontWeight: '800' },
  homeServicePrice: { color: COLORS.copper, fontSize: 11, fontWeight: '800', marginTop: 5 },
  homeInfoRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginBottom: 8 },
  homeInfoCard: { minWidth: 150, flex: 1, borderRadius: 17, padding: 16, backgroundColor: COLORS.darkPanel, borderColor: '#493b4c', borderWidth: 1 },
  homeInfoIcon: { fontSize: 28 },
  homeInfoTitle: { color: COLORS.cream, fontWeight: '800', fontSize: 14, marginTop: 9 },
  homeInfoCopy: { color: '#bdb0bf', fontSize: 11, lineHeight: 17, marginTop: 4 },
  contentCard: { padding: 20, borderRadius: 18, backgroundColor: COLORS.cream, marginBottom: 12 },
  contentEyebrow: { color: COLORS.copper, fontWeight: '900', fontSize: 11, letterSpacing: 1.3 },
  contentTitle: { color: COLORS.ink, fontSize: 22, lineHeight: 29, fontWeight: '900', marginTop: 7 },
  contentText: { color: COLORS.muted, fontSize: 14, lineHeight: 22, marginTop: 10 },
  aboutGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  iconAttribution: { marginTop: 16, padding: 12, borderRadius: 12, backgroundColor: '#2e2631' },
  iconAttributionLabel: { color: '#f5e9f3', fontSize: 11, fontWeight: '800', marginBottom: 4 },
  iconAttributionText: { color: '#c7bac8', fontSize: 10, lineHeight: 16 },
  iconAttributionLink: { color: '#f1d4f5', textDecorationLine: 'underline' },
  aboutCard: { flexGrow: 1, width: '48%', minHeight: 145, borderRadius: 17, padding: 16, backgroundColor: COLORS.darkPanel, borderColor: '#493b4c', borderWidth: 1 },
  aboutIcon: { fontSize: 27 },
  aboutTitle: { color: COLORS.cream, fontSize: 15, fontWeight: '800', marginTop: 9 },
  aboutText: { color: '#c7bac8', fontSize: 12, lineHeight: 18, marginTop: 5 },
  profileHeader: { alignItems: 'center', paddingVertical: 32 },
  profileLocationCard: { width: '100%', maxWidth: 760, backgroundColor: COLORS.purpleLight, borderColor: COLORS.line, borderWidth: 1, borderRadius: 16, padding: 18, gap: 10, marginTop: 12 },
  profileLocationTitle: { color: '#2d1c31', fontSize: 18, fontWeight: '900' },
  profileLocationCopy: { color: COLORS.muted, fontSize: 13, lineHeight: 19 },
  profileLocationActions: { flexDirection: 'row', gap: 10, marginTop: 2 },
  profileAvatarWrap: { width: 88, height: 88, position: 'relative', marginBottom: 0 },
  profileAvatar: { width: 88, height: 88, borderRadius: 44, alignItems: 'center', justifyContent: 'center', backgroundColor: COLORS.purple, borderColor: '#d7b7dd', borderWidth: 3, overflow: 'hidden' },
  profileAvatarImage: { width: '100%', height: '100%' },
  profilePhotoButton: { position: 'absolute', right: -5, bottom: -3, width: 32, height: 32, borderRadius: 16, backgroundColor: COLORS.cream, borderColor: COLORS.plum, borderWidth: 2, alignItems: 'center', justifyContent: 'center' },
  profilePhotoButtonText: { fontSize: 15 },
  profileAvatarText: { color: COLORS.white, fontSize: 37, fontWeight: '900' },
  profileName: { color: COLORS.cream, fontSize: 23, fontWeight: '900', marginTop: 13 },
  profileEmail: { color: '#bdb0bf', fontSize: 13, marginTop: 3 },
  profileCard: { borderRadius: 18, backgroundColor: COLORS.cream, paddingHorizontal: 16, marginBottom: 14 },
  profileRow: { minHeight: 72, flexDirection: 'row', alignItems: 'center', gap: 13, borderBottomColor: '#e5dce6', borderBottomWidth: 1 },
  profileRowLast: { borderBottomWidth: 0 },
  profileRowIcon: { width: 38, height: 38, borderRadius: 12, backgroundColor: COLORS.purpleLight, alignItems: 'center', justifyContent: 'center' },
  profileRowIconText: { fontSize: 20, textAlign: 'center' },
  profileRowLabel: { color: COLORS.muted, fontSize: 11, fontWeight: '700' },
  profileRowValue: { color: COLORS.ink, fontSize: 14, lineHeight: 19, fontWeight: '700', marginTop: 3 },
  profileRowInput: { minHeight: 40, borderWidth: 1, borderColor: COLORS.line, borderRadius: 10, paddingHorizontal: 10, paddingVertical: 7, color: COLORS.ink, fontSize: 14, marginTop: 4 },
  profileRowEditActions: { flexDirection: 'row', alignItems: 'center', gap: 5 },
  profileRowEditButton: { width: 36, height: 36, borderRadius: 12, backgroundColor: COLORS.purpleLight, alignItems: 'center', justifyContent: 'center' },
  profileRowEditText: { color: COLORS.plum, fontSize: 21, fontWeight: '900' },
  profileRowCancelText: { color: COLORS.red, fontSize: 22, fontWeight: '900' },
  profilePhotoOption: { minHeight: 48, borderRadius: 12, backgroundColor: COLORS.purpleLight, justifyContent: 'center', paddingHorizontal: 14 },
  profilePhotoPreview: { width: 220, height: 220, alignSelf: 'center', borderRadius: 16, backgroundColor: COLORS.purpleLight },
  profilePhotoOptionText: { color: COLORS.plum, fontWeight: '800', fontSize: 14 },
  signOutButton: { minHeight: 50, borderRadius: 15, borderColor: '#9b6472', borderWidth: 1, alignItems: 'center', justifyContent: 'center', marginTop: 18, marginBottom: 12 },
  signOutButtonText: { color: '#f0b8c3', fontSize: 14, fontWeight: '800' },
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
  progressCard: { borderRadius: 14, borderColor: '#e0d2e5', borderWidth: 1, backgroundColor: '#fbf7fc', padding: 13, marginTop: 13 },
  progressHeading: { color: COLORS.plum, fontSize: 15, fontWeight: '900', marginBottom: 12 },
  progressRow: { minHeight: 72, flexDirection: 'row' },
  progressRail: { width: 42, alignItems: 'center' },
  progressDot: { width: 34, height: 34, borderRadius: 17, alignItems: 'center', justifyContent: 'center', backgroundColor: '#e7e1e7', borderColor: '#d4cbd5', borderWidth: 1 },
  progressDotDone: { backgroundColor: '#e2f0e3', borderColor: '#83b189' },
  progressIcon: { fontSize: 17 },
  progressLine: { width: 2, flex: 1, backgroundColor: '#ddd5de', marginVertical: 3 },
  progressLineDone: { backgroundColor: '#69a471' },
  progressText: { flex: 1, paddingLeft: 8, paddingBottom: 12 },
  progressLabel: { color: COLORS.ink, fontSize: 13, fontWeight: '900' },
  progressLabelFuture: { color: '#aaa1aa' },
  progressDetail: { color: COLORS.muted, fontSize: 11, lineHeight: 16, marginTop: 2 },
  progressDetailFuture: { color: '#bbb3bb' },
  progressTime: { color: COLORS.green, fontSize: 10, fontWeight: '700', marginTop: 3 },
  progressCancelled: { color: COLORS.red, fontSize: 14, fontWeight: '900' },
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
  verificationModalKeyboard: { width: '100%', maxWidth: 460 },
  modalCard: { width: '100%', maxWidth: 460, borderRadius: 20, backgroundColor: COLORS.cream, padding: 20, gap: 12, borderColor: '#ded3df', borderWidth: 1 },
  modalTitle: { color: COLORS.ink, fontSize: 20, fontWeight: '900' },
  modalCopy: { color: COLORS.muted, fontSize: 13, lineHeight: 19, marginBottom: 2 },
  modalError: { color: COLORS.red, fontSize: 13, fontWeight: '700' },
  scheduleSectionTitle: { color: COLORS.ink, fontSize: 15, fontWeight: '900', marginTop: 3 },
  scheduleCalendar: { borderWidth: 1, borderColor: COLORS.line, borderRadius: 14, padding: 10, backgroundColor: '#fffdf9' },
  scheduleCalendarHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 },
  calendarArrow: { width: 32, height: 32, borderRadius: 16, borderWidth: 1, borderColor: COLORS.line, alignItems: 'center', justifyContent: 'center', backgroundColor: COLORS.cream },
  calendarArrowDisabled: { opacity: 0.35 },
  calendarArrowText: { color: COLORS.plum, fontSize: 20, fontWeight: '900', lineHeight: 22 },
  calendarMonthLabel: { color: COLORS.ink, fontSize: 15, fontWeight: '900' },
  calendarWeekdayRow: { flexDirection: 'row', marginBottom: 4 },
  calendarWeekday: { width: '14.2857%', color: COLORS.muted, fontSize: 10, fontWeight: '800', textAlign: 'center' },
  calendarGrid: { flexDirection: 'row', flexWrap: 'wrap' },
  calendarDay: { width: '14.2857%', aspectRatio: 1, alignItems: 'center', justifyContent: 'center', borderRadius: 10, marginVertical: 1 },
  calendarDaySelected: { backgroundColor: COLORS.plum },
  calendarDayDisabled: { opacity: 0.28 },
  calendarDayText: { color: COLORS.ink, fontSize: 13, fontWeight: '800' },
  calendarDaySelectedText: { color: COLORS.white },
  calendarDayDisabledText: { color: COLORS.muted },
  scheduleSlotGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  scheduleSlot: { width: '48%', flexGrow: 1, minHeight: 46, borderRadius: 12, borderColor: COLORS.line, borderWidth: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 8, backgroundColor: '#fffdf9' },
  scheduleSlotText: { color: COLORS.ink, fontSize: 12, fontWeight: '700', textAlign: 'center' },
  scheduleSelected: { backgroundColor: COLORS.plum, borderColor: COLORS.plum },
  scheduleSelectedText: { color: COLORS.white },
  scheduleDisabled: { opacity: 0.35, backgroundColor: '#e7e1e7' },
  modalActions: { flexDirection: 'row', gap: 10, marginTop: 5 },
});
