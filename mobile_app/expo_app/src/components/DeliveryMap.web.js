import React, { useEffect, useRef, useState } from 'react';
import { Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { pinMapHtml } from './pinMapHtml';
import { trackingMapHtml } from './trackingMapHtml';

function MapFrame({ mapFrame, label, html, expanded = false }) {
  return React.createElement('iframe', { ref: mapFrame, title: label, srcDoc: html, style: expanded ? styles.fullMap : styles.map });
}
export default function DeliveryMap({ location, label = 'Customer pin', offline = false, editable = false, onLocationChange, routeFrom = null, apiBase, token }) {
  const mapFrame = useRef(null);
  const [fullScreen, setFullScreen] = useState(false);
  const [baseLayer, setBaseLayer] = useState('Street map');
  const latitude = Number(location.latitude);
  const longitude = Number(location.longitude);

  useEffect(() => {
    if (!editable || typeof window === 'undefined') return undefined;
    const receivePin = (event) => {
      if (event.source !== mapFrame.current?.contentWindow) return;
      const message = event.data;
      if (message?.type === 'delivery-map-layer') {
        setBaseLayer(message.layer === 'Satellite' ? 'Satellite' : 'Street map');
        return;
      }
      if (message?.type === 'delivery-pin' && Number.isFinite(message.latitude) && Number.isFinite(message.longitude)) onLocationChange?.({ latitude: message.latitude, longitude: message.longitude });
    };
    window.addEventListener('message', receivePin);
    return () => window.removeEventListener('message', receivePin);
  }, [editable, onLocationChange]);

  if (editable) {
    return <View style={styles.editableWrap}>
      <Text style={styles.coordinates}>{label}: {latitude.toFixed(7)}, {longitude.toFixed(7)}</Text>
      {offline ? <Text style={styles.coordinates}>Reconnect to adjust your pin.</Text> : !fullScreen ? <MapFrame mapFrame={mapFrame} label={label} html={pinMapHtml({ latitude, longitude, baseLayer })} /> : null}
      {!offline ? <Pressable accessibilityRole="button" onPress={() => setFullScreen(true)} style={styles.previewButton}><Text style={styles.previewButtonText}>Full-screen map</Text></Pressable> : null}
      <Modal visible={fullScreen} animationType="slide" onRequestClose={() => setFullScreen(false)}>
        <View style={styles.fullScreen}>
          <View style={styles.fullHeader}><Text style={styles.fullTitle}>Pin Address</Text><Pressable accessibilityRole="button" accessibilityLabel="Close full-screen map" onPress={() => setFullScreen(false)} style={styles.closeButton}><Text style={styles.closeText}>Close</Text></Pressable></View>
          <Text style={styles.coordinates}>{label}: {latitude.toFixed(7)}, {longitude.toFixed(7)}</Text>
          <Text style={styles.hint}>Drag the pin or tap the map to adjust your delivery location.</Text>
          <View style={styles.expandedMap}>{offline ? <Text style={styles.coordinates}>Reconnect to adjust your pin.</Text> : <MapFrame mapFrame={mapFrame} label={label} html={pinMapHtml({ latitude, longitude, zoom: 17, baseLayer })} expanded />}</View>
        </View>
      </Modal>
    </View>;
  }

  const html = trackingMapHtml({ driver: routeFrom, destination: { latitude, longitude }, driverLabel: 'Your location', destinationLabel: label, routeApiUrl: apiBase ? apiBase.replace(/\/+$/, '') + '/delivery_route.php' : '', token });
  return <View style={styles.editableWrap}>
    <Text style={styles.coordinates}>{label}: {latitude.toFixed(5)}, {longitude.toFixed(5)}</Text>
    {offline ? <Text style={styles.coordinates}>Saved coordinates only. Reconnect to open the map.</Text> : <MapFrame mapFrame={mapFrame} label={label} html={html} />}
    {routeFrom ? <Text style={styles.coordinates}>Solid line shows the road route; a dashed line is shown if routing is unavailable.</Text> : null}
    <Text style={styles.coordinates}>Road route is provided by the shop routing service.</Text>
  </View>;
}

const styles = StyleSheet.create({
  editableWrap: { width: '100%', gap: 8 },
  coordinates: { color: '#514653', fontSize: 13 },
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
