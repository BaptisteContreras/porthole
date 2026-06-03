# Stage 1: Install PHP dependencies
FROM harbor.local/proxy/composer:latest AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Stage 2: Build self-contained static binary via FrankenPHP
FROM harbor.local/proxy/dunglas/frankenphp:static-builder AS builder
WORKDIR /go/src/app
COPY --from=vendor /app /go/src/app
COPY bin/ bin/
COPY src/ src/
RUN EMBED=/go/src/app/bin/porthole /builder/build-static.sh

# Stage 3: Minimal final image — contains only the binary
FROM scratch
COPY --from=builder /go/src/app/dist/frankenphp-linux-x86_64 /porthole
ENTRYPOINT ["/porthole"]
