import React from 'react';
import { StyleSheet } from 'react-native';
import { WebView } from 'react-native-webview';

export default function DeliveryMap({ location, label = 'Driver' }) {
  const latitude = Number(location.latitude);
  const longitude = Number(location.longitude);
  const delta = 0.006;
  const bounds = (longitude - delta) + ',' + (latitude - delta) + ',' + (longitude + delta) + ',' + (latitude + delta);
  const url = 'https://www.openstreetmap.org/export/embed.html?bbox=' + encodeURIComponent(bounds) + '&layer=mapnik&marker=' + latitude + ',' + longitude;

  return (
    <WebView
      source={{ uri: url }}
      style={styles.map}
      originWhitelist={['https://*']}
      javaScriptEnabled
      domStorageEnabled
      accessibilityLabel={label + ' location map'}
    />
  );
}

const styles = StyleSheet.create({
  map: { height: 230, width: '100%', borderRadius: 12, overflow: 'hidden', backgroundColor: '#eee' },
});