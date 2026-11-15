import '../delivery/locationTask';
import { Stack } from 'expo-router';
import { SafeAreaProvider } from 'react-native-safe-area-context';
export default function DriverLayout() { return <SafeAreaProvider><Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: '#18131a' } }} /></SafeAreaProvider>; }