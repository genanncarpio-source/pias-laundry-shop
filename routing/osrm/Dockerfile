FROM debian:bookworm-slim AS tarlac-extract

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl osmium-tool \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /data
RUN curl --fail --location --retry 3 \
      https://download.geofabrik.de/asia/philippines-latest.osm.pbf \
      --output philippines.osm.pbf \
    && osmium extract \
      --bbox=119.80,15.05,121.10,16.25 \
      --strategy=complete_ways \
      --set-bounds \
      --output=tarlac.osm.pbf \
      philippines.osm.pbf \
    && rm philippines.osm.pbf

FROM ghcr.io/project-osrm/osrm-backend:v26.11.0-debian AS prepare
COPY --from=tarlac-extract /data/tarlac.osm.pbf /data/tarlac.osm.pbf
RUN osrm-extract -p /opt/car.lua /data/tarlac.osm.pbf \
    && osrm-partition /data/tarlac.osrm \
    && osrm-customize /data/tarlac.osrm \
    && rm /data/tarlac.osm.pbf

FROM ghcr.io/project-osrm/osrm-backend:v26.11.0-debian
COPY --from=prepare /data /data
EXPOSE 5000
CMD ["osrm-routed", "--algorithm", "mld", "--ip", "0.0.0.0", "--port", "5000", "/data/tarlac.osrm"]
