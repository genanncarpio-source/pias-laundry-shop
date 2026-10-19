import { Platform } from 'react-native';
import * as SecureStore from 'expo-secure-store';
import * as LocalAuthentication from 'expo-local-authentication';
import { OFFLINE_ACCESS_MS, canUnlockOffline } from './sessionPolicy.mjs';
let activeSession = null;
let generation = 0;
let mutations = Promise.resolve();
const serialized = (action) => {
  const operation = mutations.catch(() => {}).then(action);
  mutations = operation.catch(() => {});
  return operation;
};
export function getActiveSession(apiBase) {
  const saved = activeSession;
  if (!saved || saved.apiBase !== apiBase || Date.now() >= saved.expiresAt
    || Date.now() - saved.verifiedAt >= OFFLINE_ACCESS_MS || Date.now() < saved.verifiedAt) return null;
  return saved;
}
const SESSION_KEY = 'pias_customer_session_v1';
const LEGACY_TOKEN_KEY = 'pias_customer_api_token';
async function get(key) {
  if (Platform.OS === 'web') return typeof window !== 'undefined' ? window.localStorage.getItem(key) : null;
  return SecureStore.getItemAsync(key);
}
async function set(key, value) {
  if (Platform.OS === 'web') { window.localStorage.setItem(key, value); return; }
  await SecureStore.setItemAsync(key, value, { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY });
}
async function remove(key) {
  if (Platform.OS === 'web') { if (typeof window !== 'undefined') window.localStorage.removeItem(key); return; }
  await SecureStore.deleteItemAsync(key);
}
export async function readSession(apiBase) {
  const raw = await get(SESSION_KEY);
  if (!raw) return null;
  try {
    const saved = JSON.parse(raw);
    return saved.apiBase === apiBase && saved.token && saved.customer?.id ? saved : null;
  } catch { return null; }
}
export async function readLegacyToken() { return get(LEGACY_TOKEN_KEY); }
export function saveVerifiedSession(apiBase, token, customer, options = {}) {
  const started = generation;
  return serialized(async () => {
    if (started !== generation) throw Object.assign(new Error('Sign in again.'), { status: 401 });
    const previous = await readSession(apiBase);
    const sameToken = previous?.token === token;
    const saved = {
      apiBase, token, customer, verifiedAt: Date.now(),
      expiresAt: sameToken ? previous.expiresAt : Date.now() + (options.expiresIn || 2592000) * 1000,
      offlineEnabled: Platform.OS !== 'web' && (options.offlineEnabled ?? (sameToken && previous.offlineEnabled) ?? false),
    };
    await set(SESSION_KEY, JSON.stringify(saved));
    await remove(LEGACY_TOKEN_KEY);
    if (started === generation) activeSession = saved;
    return saved;
  });
}
export function clearSession() {
  generation++;
  activeSession = null;
  return serialized(async () => {
    await remove(SESSION_KEY);
    await remove(LEGACY_TOKEN_KEY);
  });
}
export async function authorizeDevice() {
  if (Platform.OS === 'web') throw new Error('Offline sign-in is available in the installed Android or iOS app.');
  const level = await LocalAuthentication.getEnrolledLevelAsync();
  if (level === LocalAuthentication.SecurityLevel.NONE) throw new Error('Set a phone screen lock or fingerprint to enable offline access.');
  const result = await LocalAuthentication.authenticateAsync({
    promptMessage: "Unlock Pia's Laundry Shop",
    disableDeviceFallback: false,
    biometricsSecurityLevel: 'strong',
    fallbackLabel: 'Use phone passcode',
  });
  if (!result.success) throw new Error('Device unlock was cancelled or unsuccessful. Try again or sign in online.');
}
export async function unlockOfflineSession(apiBase) {
  const saved = await readSession(apiBase);
  if (!canUnlockOffline(saved, apiBase)) {
    throw new Error('Sign in online first and enable offline access. Saved access lasts up to 7 days after the last online verification.');
  }
  await authorizeDevice();
  // Re-read after the device prompt to avoid restoring a signed-out session.
  const current = await readSession(apiBase);
  if (!canUnlockOffline(current, apiBase) || current.token !== saved.token) throw new Error('Sign in online again.');
  activeSession = { ...current, offline: true };
  return activeSession;
}
