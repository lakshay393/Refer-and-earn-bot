# Use official PHP with built-in web server
FROM php:8.2-cli

# Install PDO + Postgres driver for Supabase
RUN docker-php-ext-install pdo pdo_pgsql

# App directory
WORKDIR /app

# Copy your project files (index.php must be in this folder)
COPY . /app

# Render provides $PORT automatically
ENV PORT=10000

# Start PHP built-in server
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t /app index.php"]