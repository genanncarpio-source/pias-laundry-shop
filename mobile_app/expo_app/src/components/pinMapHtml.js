export function pinMapHtml({ latitude, longitude }) {
  const lat = Number(latitude);
  const lon = Number(longitude);
  const centerLat = Number.isFinite(lat) ? lat : 15.4905338;
  const centerLon = Number.isFinite(lon) ? lon : 120.6641949;
  return `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no"><link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"><style>html,body,#map{height:100%;width:100%;margin:0}.leaflet-control-attribution{font-size:9px}</style></head><body><div id="map"></div><script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script><script>
const map=L.map('map').setView([${centerLat},${centerLon}],18);L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);const marker=L.marker([${centerLat},${centerLon}],{draggable:true}).addTo(map);function sendPin(p){const data={type:'delivery-pin',latitude:Number(p.lat.toFixed(7)),longitude:Number(p.lng.toFixed(7))};if(window.ReactNativeWebView)window.ReactNativeWebView.postMessage(JSON.stringify(data));else window.parent.postMessage(data,'*')}marker.on('dragend',()=>sendPin(marker.getLatLng()));map.on('click',e=>{marker.setLatLng(e.latlng);sendPin(e.latlng)});setTimeout(()=>map.invalidateSize(),100);
</script></body></html>`;
}
