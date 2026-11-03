# Farm E2E runtime image — Ubuntu 24.04, no systemd, no install.sh.
# Packages only; app tree + composer/npm baked at build time.
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive \
    LANG=C.UTF-8 \
    DISPLAY=:99 \
    FARM_ROOT=/opt/farm \
    PUPPETEER_SKIP_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/google-chrome-stable \
    APACHE_RUN_USER=www-data \
    APACHE_RUN_GROUP=www-data \
    APACHE_PID_FILE=/var/run/apache2/apache2.pid \
    APACHE_RUN_DIR=/var/run/apache2 \
    APACHE_LOCK_DIR=/var/lock/apache2 \
    APACHE_LOG_DIR=/var/log/apache2

# ---------------------------------------------------------------------------
# Runtime packages (CONTRACT stack minus systemd)
# ---------------------------------------------------------------------------
# Prevent package postinst from invoking systemd during image build
RUN printf '%s\n' '#!/bin/sh' 'exit 101' > /usr/sbin/policy-rc.d \
 && chmod +x /usr/sbin/policy-rc.d \
 && mkdir -p /var/run/mysqld \
 && apt-get update -y \
 && apt-get install -y --no-install-recommends \
      ca-certificates curl gnupg openssl rsync sudo iproute2 procps \
      python3 python3-websockify \
      apache2 \
      php8.3-fpm php8.3-cli php8.3-mysql php8.3-redis php8.3-curl \
      php8.3-mbstring php8.3-xml php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl \
      mysql-server \
      redis-server \
      supervisor \
      xvfb x11vnc novnc websockify \
      fonts-liberation fonts-noto-color-emoji \
      libnss3 libatk-bridge2.0-0 libdrm2 libxkbcommon0 libxcomposite1 \
      libxdamage1 libxfixes3 libxrandr2 libgbm1 libpango-1.0-0 \
      libcairo2 libcups2 libgtk-3-0 \
 && (apt-get install -y --no-install-recommends libasound2t64 \
     || apt-get install -y --no-install-recommends libasound2) \
 && chown mysql:mysql /var/run/mysqld \
 && rm -f /usr/sbin/policy-rc.d \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

# Node.js 20
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
 && apt-get install -y --no-install-recommends nodejs \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/* \
 && node -v | grep -qE '^v20\.'

# Google Chrome stable (real browser — not mocked)
RUN curl -fsSL https://dl.google.com/linux/linux_signing_key.pub \
      | gpg --dearmor -o /usr/share/keyrings/google-chrome.gpg \
 && echo "deb [arch=amd64 signed-by=/usr/share/keyrings/google-chrome.gpg] http://dl.google.com/linux/chrome/deb/ stable main" \
      > /etc/apt/sources.list.d/google-chrome.list \
 && apt-get update -y \
 && apt-get install -y --no-install-recommends google-chrome-stable \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/* \
 && test -x /usr/bin/google-chrome-stable

# Composer (autoload only — no packagist runtime deps beyond PHP)
RUN curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php \
 && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
 && rm -f /tmp/composer-setup.php

# ---------------------------------------------------------------------------
# Farm user + tree
# ---------------------------------------------------------------------------
RUN groupadd --system farm \
 && useradd --system --gid farm --home-dir /opt/farm --shell /usr/sbin/nologin farm \
 && usermod -aG farm www-data \
 && mkdir -p /opt/farm /var/log/farm /var/run/mysqld /var/lib/farm-e2e \
 && chown mysql:mysql /var/run/mysqld

WORKDIR /opt/farm

# Copy application (build context = repo root).
# Keep e2e-only files AFTER composer/npm so assert/fixture edits do not bust vendor cache.
COPY --chown=farm:farm composer.json ./
COPY --chown=farm:farm app ./app
COPY --chown=farm:farm public ./public
COPY --chown=farm:farm src ./src
COPY --chown=farm:farm migrations ./migrations
COPY --chown=farm:farm seeds ./seeds
COPY --chown=farm:farm scenarios ./scenarios
COPY --chown=farm:farm workers ./workers
COPY --chown=farm:farm deploy ./deploy
COPY --chown=farm:farm farm ./farm
COPY --chown=farm:farm docs ./docs
COPY --chown=farm:farm vendor ./vendor

RUN mkdir -p \
      storage/logs storage/screenshots storage/profiles storage/cookies \
      /opt/farm/e2e \
 && chown -R farm:farm /opt/farm \
 && chmod 755 /opt/farm/public \
 && chmod +x /opt/farm/farm \
 && test -f /opt/farm/vendor/autoload.php \
 && test -d /opt/farm/workers/node_modules

USER farm
# vendor/ is copied from host (no Packagist). dump-autoload only — do not
# run `composer install` without a lockfile (it always hits Packagist).
RUN cd /opt/farm \
 && COMPOSER_DISABLE_NETWORK=1 composer dump-autoload --optimize --no-interaction \
 && test -f /opt/farm/vendor/autoload.php
USER root

# e2e-only files AFTER vendor — edits here must not bust dep layers
COPY e2e/assert.php /opt/farm/e2e-assert.php
COPY e2e/geo-fixtures.json /opt/farm/e2e/geo-fixtures.json
COPY e2e/setup-fixtures.sh /opt/farm/e2e/setup-fixtures.sh
RUN chown farm:farm /opt/farm/e2e-assert.php /opt/farm/e2e/geo-fixtures.json \
 && chmod 755 /opt/farm/e2e/setup-fixtures.sh

# ---------------------------------------------------------------------------
# Apache / PHP-FPM / Supervisor (no systemd)
# ---------------------------------------------------------------------------
RUN a2enmod proxy proxy_http proxy_wstunnel rewrite headers ssl setenvif proxy_fcgi \
 && a2enconf php8.3-fpm || true \
 && a2dissite 000-default.conf || true

COPY e2e/supervisord.conf /etc/supervisor/supervisord.conf
COPY e2e/supervisor.d/ /etc/supervisor/conf.d/
COPY e2e/php-fpm-farm.conf /etc/php/8.3/fpm/pool.d/farm.conf
COPY e2e/entrypoint.sh /usr/local/sbin/farm-e2e-entrypoint.sh
RUN chmod 755 /usr/local/sbin/farm-e2e-entrypoint.sh \
 && mkdir -p /etc/ssl/farm \
 && openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
      -keyout /etc/ssl/farm/privkey.pem \
      -out /etc/ssl/farm/fullchain.pem \
      -subj "/CN=localhost" \
      -addext "subjectAltName=DNS:localhost,IP:127.0.0.1" \
 && chmod 600 /etc/ssl/farm/privkey.pem \
 && sed \
      -e 's|__DOMAIN__|localhost|g' \
      -e 's|__FARM_ROOT__|/opt/farm|g' \
      -e 's|__PHP_SOCK__|/run/php/php8.3-fpm-farm.sock|g' \
      /opt/farm/deploy/nginx-or-apache.conf > /etc/apache2/sites-available/farm.conf \
 && a2ensite farm.conf \
 && printf '%s\n' \
      '[mysqld]' \
      'bind-address = 127.0.0.1' \
      'mysqlx = 0' \
      'skip-log-bin' \
      'innodb_buffer_pool_size = 256M' \
      > /etc/mysql/mysql.conf.d/farm-e2e.cnf \
 && sed -i 's/^daemonize yes/daemonize no/' /etc/redis/redis.conf \
 && sed -i 's/^bind .*/bind 127.0.0.1/' /etc/redis/redis.conf

VOLUME ["/opt/farm/storage/profiles", "/opt/farm/storage"]

EXPOSE 80 443 6080 3000

HEALTHCHECK --interval=10s --timeout=5s --start-period=90s --retries=30 \
  CMD test -f /var/lib/farm-e2e/ready && curl -sf http://127.0.0.1/api/v1/health >/dev/null || exit 1

ENTRYPOINT ["/usr/local/sbin/farm-e2e-entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
