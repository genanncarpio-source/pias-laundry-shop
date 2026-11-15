export function trackingMapHtml({ driver, destination, driverLabel = 'Driver', destinationLabel = 'Delivery address', routeApiUrl = '', token = '' }) {
  const point = JSON.stringify({
    driver: driver && Number.isFinite(Number(driver.latitude)) && Number.isFinite(Number(driver.longitude))
      ? { latitude: Number(driver.latitude), longitude: Number(driver.longitude) } : null,
    destination: destination && Number.isFinite(Number(destination.latitude)) && Number.isFinite(Number(destination.longitude))
      ? { latitude: Number(destination.latitude), longitude: Number(destination.longitude) } : null,
    driverLabel,
    destinationLabel,
    routeApiUrl,
    token,
  });
  return `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1"><link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"><style>html,body,#map{height:100%;width:100%;margin:0;background:#e8e8e8}.leaflet-control-attribution{font-size:9px}.leaflet-popup-content-wrapper{border-radius:14px;font:14px Arial}</style><script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script></head><body><div id="map"></div><script>
const p=${point};const fallback=[15.4905338,120.6641949];const center=p.driver?[p.driver.latitude,p.driver.longitude]:p.destination?[p.destination.latitude,p.destination.longitude]:fallback;const map=L.map('map').setView(center,14);L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© OpenStreetMap contributors'}).addTo(map);let routeLine=null;
if(p.driver){L.marker([p.driver.latitude,p.driver.longitude]).addTo(map).bindPopup(p.driverLabel||'Driver · On the way');}
if(p.destination){L.marker([p.destination.latitude,p.destination.longitude]).addTo(map).bindPopup(p.destinationLabel||'Delivery address');}
if(p.driver&&p.destination){const endpoints=[[p.driver.latitude,p.driver.longitude],[p.destination.latitude,p.destination.longitude]];routeLine=L.polyline(endpoints,{color:'#8639a5',weight:5,dashArray:'8 8',opacity:.9}).addTo(map).bindTooltip('Direct-line fallback preview');map.fitBounds(L.latLngBounds(endpoints).pad(.2));}
async function showRoadRoute(){if(!p.driver||!p.destination||!p.routeApiUrl||!p.token)return;try{const response=await fetch(p.routeApiUrl,{method:'POST',headers:{'Accept':'application/json','Content-Type':'application/json','Authorization':'Bearer '+p.token},body:JSON.stringify({origin:p.driver,destination:p.destination})});const data=await response.json();const coords=data?.route?.geometry?.coordinates;if(!response.ok||data.success!==true||!Array.isArray(coords)||coords.length<2)return;const points=coords.map(([lng,lat])=>[lat,lng]);if(routeLine){routeLine.setLatLngs(points).setStyle({color:'#8639a5',weight:5,dashArray:null,opacity:.95}).bindTooltip('Road route');map.fitBounds(routeLine.getBounds().pad(.2));}}catch(_){/* Keep the direct-line preview if routing is unavailable. */}}
showRoadRoute();setTimeout(()=>map.invalidateSize(),100);
</script></body></html>`;
}
