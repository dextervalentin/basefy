FROM php:8.2-cli

LABEL basefy.hotfix="vendor-products-filters-2026-04-27"

RUN apt-get update \
	&& apt-get install -y --no-install-recommends libonig-dev libpq-dev libcurl4-openssl-dev \
	&& docker-php-ext-install pdo pdo_pgsql mbstring curl \
	&& rm -rf /var/lib/apt/lists/*

RUN { \
		echo 'upload_max_filesize=12M'; \
		echo 'post_max_size=40M'; \
		echo 'memory_limit=256M'; \
		echo 'display_errors=0'; \
		echo 'log_errors=1'; \
	} > /usr/local/etc/php/conf.d/basefy-runtime.ini

COPY . /var/www/html
WORKDIR /var/www/html

EXPOSE 8080
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t public public/router.php"]
