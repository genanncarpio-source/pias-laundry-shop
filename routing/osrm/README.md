# Tarlac OSRM service

This directory contains the standalone Railway service for road routing. It downloads the Geofabrik Philippines extract at image-build time, clips a Tarlac-sized bounding box with a buffer, and prepares an OSRM MLD graph. The routing service listens on port 5000 and should stay private (do not generate a public domain).

## Add the service in Railway

1. Add a service from this repository in the same Railway project and environment as the PHP app.
2. Set its service name to `osrm` and its Dockerfile path to `routing/osrm/Dockerfile` (leave the repository root as the build context).
3. Do not add public networking. The service listens on port 5000 for Railway private-network traffic.
4. In the PHP app service, add `OSRM_BASE_URL` as a Railway reference variable:

   `http://${{osrm.RAILWAY_PRIVATE_DOMAIN}}:5000`

5. Deploy the OSRM service first, then redeploy the PHP app so `api/delivery_route.php` can reach it.

The Docker build fetches the current Philippines OSM PBF and processes the regional extract. Keep enough build disk available for the download and intermediate graph. The prepared graph is part of the image, so no persistent volume is required. Rebuild periodically to refresh OSM road data.

The PHP proxy requires a valid customer or driver bearer token and accepts route points only inside the configured Tarlac bounding box. Mobile apps call the PHP endpoint; only the PHP service calls OSRM over Railway private networking.

The map uses OpenStreetMap map tiles with attribution. Routing follows the `driving` profile and does not include live traffic.
