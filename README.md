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
- `Idempotency-Key` (POST) valable 24 h : un rejeu renvoie le run et le jeton
  déjà émis (stocké chiffré avec une clé dérivée de l'`Idempotency-Key`, jamais
  stockée) ; après 24 h un nouveau run est créé. Verrou optimiste (`version`),
  refus des mises à jour « en arrière » : temps, rebirths ou score (409), corps
  ≤ 2 Ko (413).
- Limites de débit (`symfony/rate-limiter`, fenêtres glissantes, cache
  filesystem + verrou `flock` par clé, IPv6 agrégée par /64) : POST 10 / 10 min
  par IP et 500 / h au total, PUT 30 / 10 min, suppression 10 / 10 min, GET
  120 / min (réponse mise en cache 15 s côté serveur, invalidée à chaque
  écriture), échecs admin 5 / 15 min (429 + `Retry-After`).
- Garde-fous de plausibilité (`Backend/src/Security/RunPlausibility.php`, 422) :
  `activeSeconds ≤ timeSeconds`, trophées ≤ 54 (nombre réel de succès), coût
  cumulé des rebirths couvert par le score (tolérance ×2), temps de jeu ajouté
  par un PUT ≤ temps réel écoulé depuis la dernière mise à jour × 1,1 + 300 s,
  et score atteignable dans le temps déclaré (modèle majorant du jeu : meilleur
  CPS achetable avec l'argent gagné × bonus max 2,35 + 100 clics/s × (1 + 3 ×
  rebirths) × bonus max 2,45 ; tolérance ×10 + 120 s). Les constantes du jeu
  sont recopiées de `src/data/*.js` (un test vérifie la synchronisation : à
  mettre à jour si l'équilibrage change).
- Pseudos : normalisés NFKC, liste de mots interdits (422) vérifiée à la
  création et au changement de nom ; la suppression admin reste l'outil de
  modération.
- Les scores restent **déclarés par le navigateur** (non vérifiés) : l'interface
  l'indique.
- Suppression admin : `DELETE /api/leaderboard/{id}` + `X-Admin-Token`
  (`ADMIN_TOKEN` hex/base64 aléatoire ≥ 32 caractères avec assez d'entropie,
  ex. `openssl rand -hex 32`, sinon refus ; 5 échecs / 15 min / IP).
- Ménage : `php bin/console app:leaderboard:purge-inactive --days=180 --force`
  supprime les runs inactifs **non visibles** (le top 100 de chaque tri est
  toujours conservé) et oublie les `Idempotency-Key` de plus de 24 h ; sans
  `--force`, simple simulation. À planifier si besoin (non automatisé).

## Déploiement (Dokploy)

1. **Sauvegarder la base avant tout déploiement contenant une migration**
   (ex. `mysqldump --single-transaction` vers un stockage chiffré hors VPS) et
   vérifier la restauration.
2. Variables API requises : `APP_SECRET`, `DATABASE_URL`, `CORS_ALLOW_ORIGIN`
   (origine exacte du front, plus de regex), `TRUSTED_PROXIES`, `ADMIN_TOKEN`
   (`openssl rand -hex 32`), optionnelles `DB_WAIT_TIMEOUT`, `RUN_MIGRATIONS`.
   - `TRUSTED_PROXIES` doit être **le CIDR exact du réseau Traefik** (ex.
     `docker network inspect dokploy-network` → sous-réseau), jamais
     `REMOTE_ADDR` ni une plage large si le conteneur est joignable autrement
     que par Traefik : sinon un client forge `X-Forwarded-For` et contourne les
     limites de débit. Un pair non fiable voit son `X-Forwarded-For` ignoré
     (test `testForgedForwardedForFromUntrustedPeerIsIgnored`).
   - **Aucun port publié** pour les conteneurs API et front (pas de `ports:` /
     « Published port » dans Dokploy) : seul Traefik les joint.
3. `RUN_MIGRATIONS=1` (défaut) applique les migrations au démarrage sous verrou
   MySQL (`app:migrate-locked`). Pour une étape séparée : `RUN_MIGRATIONS=0` puis
   `php bin/console app:migrate-locked` une fois, après sauvegarde.
4. Front : argument de build `VITE_API_URL` (défaut
   `https://clicker-api.theo-birost.fr`), utilisé aussi pour la CSP `connect-src`.
   Le build de production échoue si `VITE_API_URL` est absent ou n'est pas en
   HTTPS (`vite-api-url.js`).
   HSTS : vérifier qu'il est envoyé par Traefik sur les deux domaines
   (`curl -sI https://clicker-api.theo-birost.fr/api/health | grep -i strict-transport`
   doit renvoyer `max-age` ≥ 31536000 ; sinon ajouter un middleware Traefik
   `headers.stsSeconds=31536000` + `stsIncludeSubdomains`) et que HTTP redirige
   vers HTTPS.
5. Les deux images tournent sans root et écoutent toujours sur le port 80
   (Docker autorise les ports < 1024 aux utilisateurs non root dans les
   conteneurs) ; chacune a un `HEALTHCHECK` (`/` et `/api/health`).
6. Rollback : redéployer l'image précédente ; les migrations du 2026-10-04 et
   du 2026-10-06 (`updated_at`, index de tri, `idempotency_created_at` /
   `idempotency_token_box`) sont additives (l'ancienne version reste compatible
   avec le nouveau schéma). Le `down` de 2026-10-04 refuse de tronquer un score
   > INT : restaurer la sauvegarde.
7. Les limites de débit et le cache du classement vivent dans le cache
   filesystem du conteneur (`var/cache`) : avec plusieurs réplicas, chaque
   réplica a ses propres compteurs (prévoir un stockage partagé si l'API est
   un jour mise à l'échelle).
