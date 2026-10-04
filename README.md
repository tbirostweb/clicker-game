# Clicker Game

Jeu incrémental Vue 3 + Vite (progression locale) avec un classement en ligne
servi par une API Symfony (`Backend/`).

## Développement

```bash
npm ci && npm run dev          # front (http://localhost:5173)
npm test                       # tests unitaires front (node --test)
cd Backend && composer install
php vendor/bin/phpunit         # tests fonctionnels API (SQLite, aucune base réelle)
```

Variables : voir `.env.example` (front, `VITE_API_URL` public) et
`Backend/.env.example` (API). Les valeurs réelles ne sont jamais commitées :
en production elles sont définies dans Dokploy. En local,
`CORS_ALLOW_ORIGIN` doit être l'origine exacte du front (ex. `http://localhost:5173`).

## Sécurité du classement

- `POST /api/leaderboard` renvoie une fois un `editToken` secret (seul son
  SHA-256 est stocké). `PUT` et la suppression par le joueur exigent l'en-tête
  `X-Edit-Token`. Les runs créés avant cette version ne sont plus modifiables :
  le client en crée un nouveau automatiquement.
- `Idempotency-Key` (POST), verrou optimiste (`version`), refus des mises à
  jour « en arrière » (409), limites de débit par IP (429), corps ≤ 2 Ko (413).
- Les scores restent **déclarés par le navigateur** (non vérifiés) : l'interface
  l'indique.
- Suppression admin : `DELETE /api/leaderboard/{id}` + `X-Admin-Token`
  (`ADMIN_TOKEN` ≥ 32 caractères, sinon refus ; 5 échecs / 15 min / IP).

## Déploiement (Dokploy)

1. **Sauvegarder la base avant tout déploiement contenant une migration**
   (ex. `mysqldump --single-transaction` vers un stockage chiffré hors VPS) et
   vérifier la restauration.
2. Variables API requises : `APP_SECRET`, `DATABASE_URL`, `CORS_ALLOW_ORIGIN`
   (origine exacte du front, plus de regex), `TRUSTED_PROXIES` (IP/CIDR de
   Traefik, nécessaire pour que les limites de débit voient l'IP client),
   `ADMIN_TOKEN` (≥ 32 caractères), optionnelles `DB_WAIT_TIMEOUT`,
   `RUN_MIGRATIONS`.
3. `RUN_MIGRATIONS=1` (défaut) applique les migrations au démarrage sous verrou
   MySQL (`app:migrate-locked`). Pour une étape séparée : `RUN_MIGRATIONS=0` puis
   `php bin/console app:migrate-locked` une fois, après sauvegarde.
4. Front : argument de build `VITE_API_URL` (défaut
   `https://clicker-api.theo-birost.fr`), utilisé aussi pour la CSP `connect-src`.
5. Les deux images tournent sans root et écoutent toujours sur le port 80
   (Docker autorise les ports < 1024 aux utilisateurs non root dans les
   conteneurs) ; chacune a un `HEALTHCHECK` (`/` et `/api/health`).
6. Rollback : redéployer l'image précédente ; la migration du 2026-10-04 est
   additive (l'ancienne version reste compatible avec le nouveau schéma). Le
   `down` refuse de tronquer un score > INT : restaurer la sauvegarde.
