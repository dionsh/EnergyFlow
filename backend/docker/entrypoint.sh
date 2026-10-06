#!/bin/sh
# Render assigns $PORT at runtime; Apache must listen on it.
set -e

PORT="${PORT:-10000}"
sed -i "s|[$]{PORT}|${PORT}|g" /etc/apache2/sites-available/000-default.conf
echo "Listen ${PORT}" > /etc/apache2/ports.conf

# Apply pending migrations (idempotent, guarded by a MySQL lock). If the database
# is unreachable we still start Apache so /api/v1/health can report the problem.
php /var/www/energyflow/bin/migrate.php || echo "[entrypoint] migrations failed - see log above"

exec apache2-foreground
