import React, { useEffect, useRef } from 'react';
import { Linking, Pressable, StyleSheet, Text, View } from 'react-native';
import { pinMapHtml } from './pinMapHtml';

export default function DeliveryMap({ location, label = 'Driver', offline = false, editable = false, onLocationChange }) {
  const mapFrame = useRef(null);
  const latitude = Number(location.latitude);
  const longitude = Number(location.longitude);
  const url = 'https://www.openstreetmap.org/?mlat=' + latitude + '&mlon=' + longitude + '#map=16/' + latitude + '/' + longitude;

  useEffect(() => {
    if (!editable || typeof window === 'undefined') return undefined;
    const receivePin = (event) => {
      if (event.source !== mapFrame.current?.contentWindow || event.data?.type !== 'delivery-pin') return;
      const pin = event.data;
      if (Number.isFinite(pin.latitude) && Number.isFinite(pin.longitude)) onLocationChange?.({ latitude: pin.latitude, longitude: pin.longitude });
    };
    window.addEventListener('message', receivePin);
    return () => window.removeEventListener('message', receivePin);
  }, [editable, onLocationChange]);

  if (editable) {
    return <View style={styles.editableWrap}>
      <Text style={styles.coordinates}>{label}: {latitude.toFixed(7)}, {longitude.toFixed(7)}</Text>
      {offline ? <Text style={styles.coordinates}>Reconnect to adjust your pin.</Text> : React.createElement('iframe', {
        ref: mapFrame,
        title: label,
        srcDoc: pinMapHtml({ latitude, longitude }),
        style: { width: '100%', height: 230, border: 0, borderRadius: 12, backgroundColor: '#eee' },
      })}
    </View>;
  }

  return <View style={styles.mapFallback}>
    <Text style={styles.coordinates}>{label} coordinates: {latitude.toFixed(5)}, {longitude.toFixed(5)}</Text>
    {offline ? <Text style={styles.coordinates}>Saved coordinates only. Reconnect to open the map.</Text> : <Pressable onPress={() => Linking.openURL(url)} style={styles.mapButton}>
      <Text style={styles.mapButtonText}>Open location on map</Text>
    </Pressable>}
  </View>;
}

const styles = StyleSheet.create({
  editableWrap: { width: '100%', gap: 8 },
  mapFallback: { minHeight: 100, borderRadius: 12, backgroundColor: '#f2eaf4', justifyContent: 'center', alignItems: 'center', gap: 10, padding: 16 },
  coordinates: { color: '#514653', fontSize: 13 },
  mapButton: { backgroundColor: '#58265f', borderRadius: 12, paddingHorizontal: 16, paddingVertical: 11 },
  mapButtonText: { color: '#fff', fontWeight: '700' },
});
