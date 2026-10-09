import { Platform } from 'react-native';
import * as SecureStore from 'expo-secure-store';
import * as Location from 'expo-location';
import * as TaskManager from 'expo-task-manager';

export const DRIVER_LOCATION_TASK = 'pias-driver-location';
export const DRIVER_TOKEN_KEY = 'pias_delivery_driver_token';
export const DRIVER_API_KEY = 'pias_delivery_driver_api_base';

export const LEGACY_DELIVERY_LOCATION_TASK = 'pias-delivery-location';
const LEGACY_DRIVER_CODE_KEY = 'pias_delivery_driver_code';
const LEGACY_DRIVER_API_KEY = 'pias_delivery_api_base';

if (Platform.OS !== 'web' && typeof TaskManager.defineTask === 'function' && typeof TaskManager.isTaskDefined === 'function' && !TaskManager.isTaskDefined(LEGACY_DELIVERY_LOCATION_TASK)) {
  TaskManager.defineTask(LEGACY_DELIVERY_LOCATION_TASK, async () => {
    await Location.stopLocationUpdatesAsync(LEGACY_DELIVERY_LOCATION_TASK).catch(() => {});
    await SecureStore.deleteItemAsync(LEGACY_DRIVER_CODE_KEY).catch(() => {});
    await SecureStore.deleteItemAsync(LEGACY_DRIVER_API_KEY).catch(() => {});
  });
}

if (Platform.OS !== 'web' && typeof TaskManager.defineTask === 'function' && typeof TaskManager.isTaskDefined === 'function' && !TaskManager.isTaskDefined(DRIVER_LOCATION_TASK)) {
  TaskManager.defineTask(DRIVER_LOCATION_TASK, async ({ data, error }) => {
    if (error || !data?.locations?.length) return;
    const [token, apiBase] = await Promise.all([
      SecureStore.getItemAsync(DRIVER_TOKEN_KEY),
      SecureStore.getItemAsync(DRIVER_API_KEY),
    ]);
    if (!token || !apiBase) return;
    for (const location of data.locations) {
      try {
        const response = await fetch(apiBase.replace(/\/+$/, '') + '/driver_tracking.php', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            Authorization: 'Bearer ' + token,
          },
          body: JSON.stringify({
            action: 'update',
            latitude: location.coords.latitude,
            longitude: location.coords.longitude,
            accuracy: location.coords.accuracy,
            heading: location.coords.heading,
            speed: location.coords.speed,
          }),
        });
        if ([401, 403, 409].includes(response.status)) {
          await Location.stopLocationUpdatesAsync(DRIVER_LOCATION_TASK).catch(() => {});
          if (response.status !== 409) {
            await SecureStore.deleteItemAsync(DRIVER_TOKEN_KEY).catch(() => {});
            await SecureStore.deleteItemAsync(DRIVER_API_KEY).catch(() => {});
          }
          return;
        }
      } catch (_) {
        // The next location event retries when the network is available.
      }
    }
  });
}
