import '../delivery/locationTask';
import { Stack } from 'expo-router';
import { SafeAreaProvider } from 'react-native-safe-area-context';

export default function RootLayout() {
  return (
    <SafeAreaProvider>
      <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: '#18131a' } }}>
        <Stack.Screen name="index" />
        <Stack.Screen name="track" />
        <Stack.Screen name="driver" />
      </Stack>
    </SafeAreaProvider>
  );
}