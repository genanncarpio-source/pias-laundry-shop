import React, { useEffect, useRef, useState } from 'react';
import { Linking, Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { pinMapHtml } from './pinMapHtml';

function MapFrame({ mapFrame, label, html, expanded = false }) {
  return React.createElement('iframe', {
    ref: mapFrame,
    title: label,
    srcDoc: html,
    style: expanded ? styles.fullMap : styles.map,
  });
}
export default function DeliveryMap({ location, label = 'Driver', offline = false, editable = false, onLocationChange }) {
  const mapFrame = useRef(null);
  const [fullScreen, setFullScreen] = useState(false);
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
      {offline ? <Text style={styles.coordinates}>Reconnect to adjust your pin.</Text> : !fullScreen ? <MapFrame mapFrame={mapFrame} label={label} html={pinMapHtml({ latitude, longitude })} /> : null}
      {!offline ? <Pressable accessibilityRole="button" onPress={() => setFullScreen(true)} style={styles.previewButton}><Text style={styles.previewButtonText}>Full-screen map</Text></Pressable> : null}
      <Modal visible={fullScreen} animationType="slide" onRequestClose={() => setFullScreen(false)}>
        <View style={styles.fullScreen}>
          <View style={styles.fullHeader}><Text style={styles.fullTitle}>Pin Address</Text><Pressable accessibilityRole="button" accessibilityLabel="Close full-screen map" onPress={() => setFullScreen(false)} style={styles.closeButton}><Text style={styles.closeText}>Close</Text></Pressable></View>
          <Text style={styles.coordinates}>{label}: {latitude.toFixed(7)}, {longitude.toFixed(7)}</Text>
          <Text style={styles.hint}>Drag the pin or tap the map to adjust your delivery location.</Text>
          <View style={styles.expandedMap}>{offline ? <Text style={styles.coordinates}>Reconnect to adjust your pin.</Text> : <MapFrame mapFrame={mapFrame} label={label} html={pinMapHtml({ latitude, longitude, zoom: 13 })} expanded />}</View>
        </View>
      </Modal>
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
  map: { width: '100%', height: 230, border: 0, borderRadius: 12, backgroundColor: '#eee' },
  previewButton: { alignSelf: 'flex-start', backgroundColor: '#58265f', borderRadius: 10, paddingHorizontal: 14, paddingVertical: 10 },
  previewButtonText: { color: '#fff', fontSize: 14, fontWeight: '700' },
  fullScreen: { flex: 1, backgroundColor: '#fffaf2', padding: 16, paddingTop: 26, gap: 10 },
  fullHeader: { minHeight: 48, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  fullTitle: { color: '#201820', fontSize: 20, fontWeight: '700' },
  closeButton: { backgroundColor: '#58265f', borderRadius: 10, paddingHorizontal: 16, paddingVertical: 10 },
  closeText: { color: '#fff', fontWeight: '700' },
  hint: { color: '#514653', fontSize: 14, lineHeight: 20 },
  expandedMap: { flex: 1, minHeight: 0, borderRadius: 14, overflow: 'hidden', backgroundColor: '#eee' },
  fullMap: { flex: 1, width: '100%', border: 0, backgroundColor: '#eee' },
});
