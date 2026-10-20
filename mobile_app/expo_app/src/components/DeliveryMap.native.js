import React, { useState } from 'react';
import { Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { WebView } from 'react-native-webview';
import { pinMapHtml } from './pinMapHtml';

export default function DeliveryMap({ location, label = 'Driver', offline = false, editable = false, onLocationChange }) {
  const [failed, setFailed] = useState(false);
  const [fullScreen, setFullScreen] = useState(false);
  const latitude = Number(location.latitude);
  const longitude = Number(location.longitude);
  const onMessage = (event) => {
    if (!editable || typeof onLocationChange !== 'function') return;
    try {
      const point = JSON.parse(event.nativeEvent.data);
      if (point.type === 'delivery-pin' && Number.isFinite(point.latitude) && Number.isFinite(point.longitude)) {
        onLocationChange({ latitude: point.latitude, longitude: point.longitude });
      }
    } catch { /* Ignore unrelated map messages. */ }
  };
  const map = (expanded = false) => failed
    ? <Text style={styles.copy}>Map unavailable. Try again when you have an internet connection.</Text>
    : <WebView source={{ html: pinMapHtml({ latitude, longitude, zoom: expanded ? 13 : 17 }) }} style={expanded ? styles.fullMap : styles.map} originWhitelist={['*']} javaScriptEnabled domStorageEnabled accessibilityLabel={label} onMessage={onMessage} onError={() => setFailed(true)} />;

  if (editable) {
    return <View style={styles.container}>
      <Text style={styles.copy}>{label}: {latitude.toFixed(7)}, {longitude.toFixed(7)}</Text>
      {offline ? <Text style={styles.copy}>Reconnect to adjust your pin.</Text> : map()}
      {!offline ? <Pressable accessibilityRole="button" onPress={() => setFullScreen(true)} style={styles.previewButton}><Text style={styles.previewButtonText}>Full-screen map</Text></Pressable> : null}
      <Modal visible={fullScreen} animationType="slide" presentationStyle="fullScreen" onRequestClose={() => setFullScreen(false)}>
        <View style={styles.fullScreen}>
          <View style={styles.fullHeader}><Text style={styles.fullTitle}>Pin Address</Text><Pressable accessibilityRole="button" accessibilityLabel="Close full-screen map" onPress={() => setFullScreen(false)} style={styles.closeButton}><Text style={styles.closeText}>Close</Text></Pressable></View>
          <Text style={styles.copy}>{label}: {latitude.toFixed(7)}, {longitude.toFixed(7)}</Text>
          <Text style={styles.hint}>Drag the pin or tap the map to adjust your delivery location.</Text>
          <View style={styles.expandedMap}>{offline ? <Text style={styles.copy}>Reconnect to adjust your pin.</Text> : map(true)}</View>
        </View>
      </Modal>
    </View>;
  }

  const delta = 0.006;
  const bounds = [longitude - delta, latitude - delta, longitude + delta, latitude + delta].join(',');
  const url = 'https://www.openstreetmap.org/export/embed.html?bbox=' + encodeURIComponent(bounds) + '&layer=mapnik&marker=' + latitude + ',' + longitude;
  return <View style={styles.container}>
    <Text style={styles.copy}>{label} coordinates: {latitude.toFixed(5)}, {longitude.toFixed(5)}</Text>
    {offline || failed ? <Text style={styles.copy}>{offline ? 'Saved coordinates only. Map tiles need an internet connection.' : 'Map unavailable. The coordinates above are still available.'}</Text> : <WebView source={{ uri: url }} style={styles.map} originWhitelist={['https://*']} javaScriptEnabled domStorageEnabled accessibilityLabel={label} onError={() => setFailed(true)} onHttpError={() => setFailed(true)} />}
  </View>;
}

const styles = StyleSheet.create({
  container: { gap: 8 },
  copy: { color: '#514653', fontSize: 13, lineHeight: 19 },
  hint: { color: '#514653', fontSize: 14, lineHeight: 20 },
  map: { height: 230, width: '100%', borderRadius: 12, overflow: 'hidden', backgroundColor: '#eee' },
  previewButton: { alignSelf: 'flex-start', backgroundColor: '#58265f', borderRadius: 10, paddingHorizontal: 14, paddingVertical: 10 },
  previewButtonText: { color: '#fff', fontSize: 14, fontWeight: '700' },
  fullScreen: { flex: 1, backgroundColor: '#fffaf2', padding: 16, paddingTop: 26, gap: 10 },
  fullHeader: { minHeight: 48, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  fullTitle: { color: '#201820', fontSize: 20, fontWeight: '700' },
  closeButton: { backgroundColor: '#58265f', borderRadius: 10, paddingHorizontal: 16, paddingVertical: 10 },
  closeText: { color: '#fff', fontWeight: '700' },
  expandedMap: { flex: 1, minHeight: 0, borderRadius: 14, overflow: 'hidden', backgroundColor: '#eee' },
  fullMap: { flex: 1, width: '100%', backgroundColor: '#eee' },
});
