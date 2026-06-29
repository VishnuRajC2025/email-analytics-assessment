FROM php:8.2-cli

# Install PDO MySQL extension
RUN docker-php-ext-install pdo pdo_mysql

WORKDIR /app
COPY . /app

# Install composer dependencies (Kafka PHP client)
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer install --no-dev --optimize-autoloader

EXPOSE 8080

CMD ["sh", "-c", "php migrate.php && php -S 0.0.0.0:8080 -t public public/router.php"]
