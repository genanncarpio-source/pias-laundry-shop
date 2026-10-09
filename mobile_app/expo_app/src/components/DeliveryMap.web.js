import React from 'react';
import { Linking, Pressable, StyleSheet, Text, View } from 'react-native';

export default function DeliveryMap({ location, label = 'Driver' }) {
  const latitude = Number(location.latitude);
  const longitude = Number(location.longitude);
  const url = 'https://www.openstreetmap.org/?mlat=' + latitude + '&mlon=' + longitude + '#map=16/' + latitude + '/' + longitude;

  return (
    <View style={styles.mapFallback}>
      <Text style={styles.coordinates}>{label} coordinates: {latitude.toFixed(5)}, {longitude.toFixed(5)}</Text>
      <Pressable onPress={() => Linking.openURL(url)} style={styles.mapButton}>
        <Text style={styles.mapButtonText}>Open live location map</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  mapFallback: { minHeight: 100, borderRadius: 12, backgroundColor: '#f2eaf4', justifyContent: 'center', alignItems: 'center', gap: 10, padding: 16 },
  coordinates: { color: '#514653', fontSize: 13 },
  mapButton: { backgroundColor: '#58265f', borderRadius: 12, paddingHorizontal: 16, paddingVertical: 11 },
  mapButtonText: { color: '#fff', fontWeight: '700' },
});