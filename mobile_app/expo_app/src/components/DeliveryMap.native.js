import React, { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { WebView } from 'react-native-webview';
import { pinMapHtml } from './pinMapHtml';

export default function DeliveryMap({ location, label = 'Driver', offline = false, editable = false, onLocationChange }) {
  const [failed, setFailed] = useState(false);
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

  if (editable) {
    return <View style={styles.container}>
      <Text style={styles.copy}>{label}: {latitude.toFixed(7)}, {longitude.toFixed(7)}</Text>
      {failed ? <Text style={styles.copy}>Map unavailable. Try again when you have an internet connection.</Text> : <WebView
        source={{ html: pinMapHtml({ latitude, longitude }) }}
        style={styles.map}
        originWhitelist={['*']}
        javaScriptEnabled
        domStorageEnabled
        accessibilityLabel={label}
        onMessage={onMessage}
        onError={() => setFailed(true)}
      />}
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
  map: { height: 230, width: '100%', borderRadius: 12, overflow: 'hidden', backgroundColor: '#eee' },
});
