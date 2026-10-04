# Dokploy app: build context = repo root, dockerfile = Dockerfile
# Served at portfolio.theo-birost.fr/clicker (path stripped by Traefik).
# Base images are pinned to explicit versions; pin by digest at release time
# (docker buildx imagetools inspect <image>) once verified in the registry.

FROM node:22.19-alpine3.22 AS build
WORKDIR /app
# Public API origin baked into the bundle (not a secret). Override with a
# Dokploy build argument if the API moves.
ARG VITE_API_URL=https://clicker-api.theo-birost.fr
ENV VITE_API_URL=${VITE_API_URL}
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY . .
RUN npm run build

FROM nginxinc/nginx-unprivileged:1.27-alpine
ARG VITE_API_URL=https://clicker-api.theo-birost.fr
USER root
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/security-headers.conf /etc/nginx/snippets/security-headers.conf
RUN API_ORIGIN="$(echo "$VITE_API_URL" | sed -E 's#^(https?://[^/]+).*#\1#')" \
    && sed -i "s#__API_ORIGIN__#${API_ORIGIN}#" /etc/nginx/snippets/security-headers.conf \
    && rm -f /etc/nginx/conf.d/*.default
COPY --from=build /app/dist /usr/share/nginx/html
# Unprivileged runtime (uid 101). Port 80 stays bindable because Docker sets
# net.ipv4.ip_unprivileged_port_start=0 inside containers (Docker >= 20.10).
USER 101
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD wget -q -O /dev/null http://127.0.0.1/ || exit 1
