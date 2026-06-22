FROM php:8.2-cli

# Install PDO MySQL extension
RUN docker-php-ext-install pdo pdo_mysql

WORKDIR /app
COPY . /app

EXPOSE 8080

CMD ["sh", "-c", "php migrate.php && php -S 0.0.0.0:8080 -t public"]
