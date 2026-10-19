import { Platform } from 'react-native';
import * as SecureStore from 'expo-secure-store';

let activeSession = null;
let generation = 0;
let mutations = Promise.resolve();
const SESSION_KEY = 'pias_customer_session_v1';
const LEGACY_TOKEN_KEY = 'pias_customer_api_token';

const serialized = (action) => {
  const operation = mutations.catch(() => {}).then(action);
  mutations = operation.catch(() => {});
  return operation;
};

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

export function getActiveSession(apiBase) {
  const saved = activeSession;
  if (!saved || saved.apiBase !== apiBase || Date.now() >= saved.expiresAt) return null;
  return saved;
}

export async function readSession(apiBase) {
  const raw = await get(SESSION_KEY);
  if (!raw) return null;
  try {
    const saved = JSON.parse(raw);
    return saved.apiBase === apiBase && saved.token && saved.customer?.id && Date.now() < saved.expiresAt ? saved : null;
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
      apiBase,
      token,
      customer,
      verifiedAt: Date.now(),
      expiresAt: sameToken ? previous.expiresAt : Date.now() + (options.expiresIn || 2592000) * 1000,
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