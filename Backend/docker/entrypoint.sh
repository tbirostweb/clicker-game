#!/bin/sh
set -eu

# Bounded wait: fail fast (and let the orchestrator restart/alert) instead of
# looping forever when the database is unreachable.
DB_WAIT_TIMEOUT="${DB_WAIT_TIMEOUT:-60}"
waited=0
echo ">>> Attente de la base de données (max ${DB_WAIT_TIMEOUT}s)..."
until php bin/console dbal:run-sql "SELECT 1" >/dev/null 2>&1; do
  if [ "$waited" -ge "$DB_WAIT_TIMEOUT" ]; then
    echo "Base injoignable après ${DB_WAIT_TIMEOUT}s, arrêt." >&2
    exit 1
  fi
  sleep 2
  waited=$((waited + 2))
done
echo ">>> Base prête."

# RUN_MIGRATIONS=1 (default, keeps the previous behaviour): apply pending
# migrations under a MySQL advisory lock (one container at a time).
# Set RUN_MIGRATIONS=0 once migrations are run as a separate, backed-up
# release step (see README "Déploiement").
if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
  php bin/console app:migrate-locked --no-interaction
else
  echo ">>> RUN_MIGRATIONS=0 : migrations non appliquées automatiquement."
  php bin/console doctrine:migrations:up-to-date --no-interaction || \
    echo "ATTENTION : des migrations sont en attente." >&2
fi

php bin/console cache:clear --env=prod --no-debug

exec "$@"
