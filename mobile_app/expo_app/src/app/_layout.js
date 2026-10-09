import '../delivery/locationTask';
import { Stack } from 'expo-router';

export default function RootLayout() {
  return (
    <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: '#18131a' } }}>
      <Stack.Screen name="index" />
      <Stack.Screen name="track" />
      <Stack.Screen name="driver" />
    </Stack>
  );
}