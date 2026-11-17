FROM dunglas/frankenphp:1-php8.4-alpine

WORKDIR /app

# PHP production configuration + cURL for Google Apps Script, Upstash Redis and Vercel Blob.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && install-php-extensions curl opcache

COPY Caddyfile /etc/caddy/Caddyfile
COPY public/ /app/public/

ENV PORT=80
ENV APP_ENV=production
ENV TZ=Asia/Jakarta

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
