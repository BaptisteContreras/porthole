FROM harbor.local/proxy/php:8.5-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    git \
    unzip \
    libssl-dev \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=harbor.local/proxy/composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENTRYPOINT ["php"]
