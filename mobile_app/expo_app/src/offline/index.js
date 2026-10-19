import AsyncStorage from '@react-native-async-storage/async-storage';
import * as Crypto from 'expo-crypto';
import { createOfflineStore } from './store.mjs';
export const offlineStore = createOfflineStore(AsyncStorage, () => Crypto.randomUUID());
export const accountScope = (apiBase, customerId) => apiBase.replace(/\/+$/, '') + ':' + String(customerId);
