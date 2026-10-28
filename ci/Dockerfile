FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive
# Match the VM's Debian/PHP versions, without starting system services.
RUN printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d \
    && chmod +x /usr/sbin/policy-rc.d \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl git patch unzip python3 nodejs sudo build-essential \
        nginx sqlite3 redis-server php8.2-cli php8.2-fpm php8.2-sqlite3 \
        php8.2-curl php8.2-xml php8.2-mbstring php8.2-gd php8.2-intl \
        php8.2-tidy php8.2-zip \
    && echo 'deb http://deb.debian.org/debian bookworm-backports main' > /etc/apt/sources.list.d/backports.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends -t bookworm-backports golang-go \
    && rm -rf /var/lib/apt/lists/*

COPY integration/php-security.ini /etc/php/8.2/cli/conf.d/99-wallabag-security.ini
COPY integration/php-security.ini /etc/php/8.2/fpm/conf.d/99-wallabag-security.ini

# Match ownership of the mounted checkout and run tests like the non-root grain.
ARG CI_UID=1001
ARG CI_GID=1001
RUN groupadd --gid "$CI_GID" ci \
    && useradd --uid "$CI_UID" --gid "$CI_GID" --create-home ci \
    && install -d -o ci -g ci /opt/app /var/lib/wallabag /var/lib/nginx/uwsgi /var/lib/nginx/scgi \
    && printf 'ci ALL=(ALL) NOPASSWD: ALL\n' > /etc/sudoers.d/ci
USER ci
WORKDIR /opt/app
CMD ["bash", "ci/check.sh"]
