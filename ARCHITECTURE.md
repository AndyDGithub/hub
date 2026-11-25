# Hub — architecture

API centrale de l'environnement de travail d'Andy : elle stocke la connaissance (megadoc, cours, docs),
permet de la retrouver, la partage sur invitation, et pilote les autres outils (notifications, dashboard, cluster).

## Vue d'ensemble

```
                ┌──────────────── HUB (Laravel) ────────────────┐
 Claude Code ──►│ MCP  │ Identité & accès │ Journal d'audit      │
 Dashboard   ──►│ API  │  (rôles, tokens par outil, invitations) │
 Invités     ──►│      ├─────────────────────────────────────────┤
                │      │ Connaissance : megadoc, cours, docs     │
                │      │ Recherche : FULLTEXT → hybride (RRF)    │
                │      │ Événements → webhooks sortants          │──► telegram-notifier, ...
                │      │ Infra : paramètres + déclenchement      │──► runner CI ──► OpenTofu ──► cluster
                └───────────────────────────┬────────────────────┘
                                         MariaDB
```

## Décisions (ADR)

### ADR-1 — Monolithe modulaire Laravel (2026-11-24, accepté)
Une seule application, découpée en modules aux frontières strictes : un module n'accède jamais
directement aux tables d'un autre, il passe par son service/ses événements.
Modules : `Identity`, `Knowledge`, `Search`, `Events`, `Infra`.
Un module ne devient un service séparé que si un besoin réel l'impose (charge, langage, isolation).
*Pourquoi* : un seul développeur ; le coût opérationnel des microservices n'est pas justifié.

### ADR-2 — Megadoc en fiches structurées (accepté)
Une fiche = un problème : message d'erreur normalisé, contexte (langage, lib, version), cause, solution,
statut validée/non, compteur d'occurrences, liens. À chaque erreur : chercher d'abord ; si correspondance,
incrémenter ; sinon créer. Sources initiales : `~/hesso/megadoc_skills.md`, `~/megadoc/*`,
cours sur le serveur dans `~/hesso_save`.

### ADR-3 — Recherche progressive (accepté)
1. `FULLTEXT` MariaDB (disponible sur o2switch). Le classement d'InnoDB est une variante de TF-IDF, pas un vrai BM25 :
   le jeu d'évaluation dira s'il suffit. Mots de moins de 3 lettres non indexés, pas de racinisation (« volume » ≠ « volumes »).
2. Hybride : embeddings + fusion RRF (k≈60), sans calibration.
   o2switch = MariaDB 11.4 (pas de type `VECTOR`, arrivé en 11.7/11.8) et PostgreSQL 9.6 (pas de pgvector) :
   embeddings stockés en base, similarité calculée dans l'app tant que le volume reste de l'ordre de quelques milliers de fiches.
3. Jeu d'évaluation : ~20 requêtes réelles avec la fiche attendue, pour prouver chaque amélioration.

### ADR-4 — Partage sur invitation, autorisations par contenu (accepté)
Pas d'inscription publique : un admin/maintainer crée le compte, la personne reçoit un mot de passe temporaire.

| Rôle | Lire | Écrire du contenu | Outils standard | Outils coûteux | Gérer les comptes |
|---|---|---|---|---|---|
| `reader` | ✓ | | | | |
| `user` | ✓ | | ✓ | | |
| `premium` | ✓ | | ✓ | ✓ | |
| `maintainer` | ✓ | ✓ | ✓ | | crée `user`, `reader` |
| `admin` | ✓ | ✓ (+ suppression) | ✓ | ✓ | tous |

Permissions : `content.*`, `attachment.*`, `user.manage`, `tools.use`, `tools.premium` (`DatabaseSeeder`).
Visibilité par contenu (privé / groupe / personnes) via les Policies Laravel.
Chaque outil reçoit son propre token, limité à ce dont il a besoin.

### ADR-5 — IaC : git est la vérité, l'API déclenche (accepté)
Le code OpenTofu vit dans git. L'API stocke des paramètres (taille, durée max, job) et déclenche un runner CI,
qui obtient des identifiants cloud courts via OIDC. State dans un bucket chiffré.
**Aucune clé cloud sur l'hébergement web.**

### ADR-6 — Hébergement : o2switch d'abord, portable ensuite (accepté)
- PHP minimum **8.4** (dépendances Symfony 8). Sur o2switch, `php` en CLI = 8.1 → utiliser `/opt/cpanel/ea-php84/root/usr/bin/php` pour `artisan`.
- Pas de worker permanent ni de websockets : files traitées par cron (`queue:work --stop-when-empty`).
- Configuration 100 % par variables d'environnement (jamais `env()` hors de `config/`) pour pouvoir déménager
  sur un VPS ou le cluster sans réécriture.

### ADR-7 — Authentification (à décider en phase 2)
Existant : JWT HS256 maison (`app/Services/JwtService.php`), correctement signé et vérifié.
Option : Laravel Sanctum (officiel, tokens avec « abilities » = permissions par outil, cf. ADR-4).

### ADR-8 — Outils : catalogue dans le hub, exécution là où c'est possible (2026-11-25, proposé)
Chaque outil est déclaré dans le hub (nom, permission requise `tools.use`/`tools.premium`, où il s'exécute, limites).
Un appel crée un **job** (`tool_runs` : entrée, statut, sortie, durée, coût), visible dans le dashboard et notifié (phase 3).
Trois lieux d'exécution, du moins cher au plus cher :

| Lieu | Quand | Exemples |
|---|---|---|
| **Navigateur** (JS/Canvas/WASM) | calcul léger sur les fichiers de l'utilisateur, rien à stocker | noir et blanc, inversion, contraste, révéler les bits de poids faible (curseur 1–8 bits) |
| **Hub** (PHP + cron) | court, sans GPU, ou appel d'une API externe | PDF via iLoveAPI, recherche megadoc |
| **Workers** (cluster, PC) | lourd : ffmpeg, GPU, modèles | mp4 ↔ gif, transcription + locuteurs (psifx : `audio/transcription`, `audio/diarization`), vidéo (pose, visage) |

Les workers **tirent** les jobs (`GET /api/jobs/next` avec un token propre à chaque worker) au lieu d'être appelés :
aucun port ouvert sur le cluster, aucune clé cloud sur o2switch (ADR-5), et un worker éteint ne fait que retarder.
o2switch n'a pas de file permanente : les jobs « hub » passent par cron (ADR-6).
Fichiers : taille max et durée de conservation par outil ; jamais servis depuis `public/`.

## Feuille de route

| Phase | Contenu | État |
|---|---|---|
| 0 — Fondations | git, dépôt privé, CI tests, bêta sur sous-domaine | fait (bêta : sous-domaine créé, rien de déployé) |
| 1 — Connaissance | fiches, import megadoc, recherche FULLTEXT, résolutions d'erreurs, serveur MCP (`laravel/mcp`) | en cours : tout sauf MCP et `~/hesso_save` |
| 2 — Partage | visibilité par contenu, tokens par outil | |
| 3 — Événements | webhooks vers telegram-notifier, dashboard, envoi du mot de passe temporaire | |
| 4 — Outils | catalogue + jobs (ADR-8) ; d'abord éditeur d'images (navigateur), puis PDF, puis workers | |
| 5 — Recherche hybride | embeddings, RRF, jeu d'évaluation | |
| 6 — Infra | pilotage du cluster, workers premium | |

## Connaissance (phase 1)

Une fiche est un `contents` (comme docs et cours) avec `type` : `concept`, `resolution`, `project`, `note`, `doc`, `course`, `exercise`.
`source` + `source_hash` rendent l'import idempotent ; `verification` = `planned` < `in_code` < `executed` < `validated`.

- `php artisan hub:knowledge:import ~/hesso/megadoc_skills.md ~/megadoc` : simulation ; `--write` pour écrire.
  `megadoc_skills.md` → une fiche par `###` ; autres pages → une fiche par fichier. Pages vides, cachées, `README.md`
  et > 200 Ko ignorées ; une fiche qui contient un secret probable est sautée.
- `GET /api/knowledge/search?q=…&type=…` (`content.read`), `GET /api/knowledge/{slug}`.
- `POST /api/knowledge/resolutions` (`content.create`) : `{error_message, context?, cause?, solution?, tags?}`.
  Erreur connue → `known`, compteur +1 ; inconnue sans solution → `unknown` + fiches proches ; avec solution → `created`.
  L'empreinte ignore chemins, nombres, adresses : la même erreur dans un autre fichier est reconnue.

## Dette connue

Corrigé le 2026-11-24 :
- [x] Pepper d'exemple → pepper obligatoire (≥ 32 caractères, sinon l'app refuse de hacher), appliqué par HMAC-SHA256
  avant bcrypt (bcrypt ignore tout au-delà de 72 octets). Tous les anciens mots de passe sont invalidés.
- [x] Comptes `ADMIN_*`/`READER_*` en double dans `.env` → supprimés ; le seeder ne crée plus que rôles et permissions.
- [x] `POST /auth/register` public → `POST /api/users` (admin, maintainer) + `php artisan hub:user:create`,
  avec mot de passe temporaire (72 h) à changer avant tout autre appel. Rôles attribuables : `config/hub.php`.
- [x] `env()` hors de `config/` → `config/hub.php`.
- [x] Migrations redondantes du 2025-12-17 fusionnées dans `create_users_table` (colonnes `description` inutilisées retirées).
- [x] Tests : création de comptes, droits par rôle, mot de passe temporaire, pepper.

Reste :
- [ ] `GET /api/docs` (public) renvoie l'e-mail de l'auteur → ne garder que le nom (phase 2, visibilité).
- [ ] `POST /auth/refresh` ne vérifie pas `is_banned` (les routes protégées le font, mais le token est quand même émis).
- [ ] Envoi automatique du mot de passe temporaire (e-mail ou Telegram) : phase 3, événement `user.created`.
- [ ] Mises à jour majeures en attente : `spatie/laravel-permission` 8, `laravel/framework` 13.
