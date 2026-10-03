#!/bin/sh
set -e

echo ">>> Attente de la base de données..."
until php bin/console dbal:run-sql "SELECT 1" >/dev/null 2>&1; do
  echo "Base pas encore prête, nouvelle tentative dans 2s..."
  sleep 2
done
echo ">>> Base prête."

php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
php bin/console cache:clear --env=prod --no-debug

exec "$@"
