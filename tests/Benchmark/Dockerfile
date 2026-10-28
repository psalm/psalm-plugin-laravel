# Self-contained benchmark image for Bencher Bare Metal (.github/workflows/bencher.yml):
# the runners have no network, so Monica, its vendor, and the plugin are baked in.
# Build from the repository root: docker build --file tests/Benchmark/Dockerfile .
FROM php:8.3-cli

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/
RUN apt-get update && apt-get install -y --no-install-recommends git hyperfine jq time unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions bcmath gd intl opcache zip \
    && echo 'opcache.enable_cli=1' > "$PHP_INI_DIR/conf.d/zz-benchmark.ini"

# Lowest Monica tag on Laravel 12 (the plugin's floor); pinned for comparable numbers.
ARG MONICA_REF=v5.0.0-beta.5
RUN git clone --depth 1 --branch "$MONICA_REF" https://github.com/monicahq/monica /monica

COPY . /plugin
WORKDIR /monica
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1 COMPOSER_NO_AUDIT=1 COMPOSER_NO_INTERACTION=1
# - laravel-lang/http-statuses: Monica's lock pins a release Packagist flags as malware.
# - laravel/framework: the plugin only conflicts with old Laravel, so it must be unlocked explicitly.
# - vimeo/psalm at the plugin's floor: the trend then moves only on commits here, not on Psalm tags.
RUN composer update laravel-lang/http-statuses --no-progress \
    && composer config repositories.plugin '{"type": "path", "url": "/plugin", "options": {"symlink": true}}' \
    && composer config minimum-stability dev \
    && composer require --dev "psalm/plugin-laravel:*" --no-update \
    && PSALM_FLOOR=$(jq -er '.require["vimeo/psalm"] | split("||")[0] | gsub("^[\\s^~>=]+|\\s+$"; "")' /plugin/composer.json) \
    && composer update psalm/plugin-laravel laravel/framework --with="vimeo/psalm:$PSALM_FLOOR" \
        --no-progress --with-all-dependencies \
    && composer show | grep -E '^(psalm/plugin-laravel|vimeo/psalm|laravel/framework) ' \
    && cp /plugin/tests/Benchmark/psalm-benchmark.xml psalm-benchmark.xml \
    && composer clear-cache
