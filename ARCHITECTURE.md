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
                │      │ Recherche : BM25 → hybride (RRF)        │
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
1. BM25 via `FULLTEXT` MariaDB (disponible sur o2switch).
2. Hybride : embeddings + fusion RRF (k≈60), sans calibration.
   o2switch = MariaDB 11.4 (pas de type `VECTOR`, arrivé en 11.7/11.8) et PostgreSQL 9.6 (pas de pgvector) :
   embeddings stockés en base, similarité calculée dans l'app tant que le volume reste de l'ordre de quelques milliers de fiches.
3. Jeu d'évaluation : ~20 requêtes réelles avec la fiche attendue, pour prouver chaque amélioration.

### ADR-4 — Partage sur invitation, autorisations par contenu (accepté)
Pas d'inscription publique : invitation → approbation. Rôles (Spatie) : lecture seule, écriture partielle, aucun accès.
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

## Feuille de route

| Phase | Contenu | État |
|---|---|---|
| 0 — Fondations | git, dépôt privé, CI tests, bêta sur sous-domaine | fait (bêta : sous-domaine créé, rien de déployé) |
| 1 — Connaissance | modèle de fiche, import megadoc, BM25, serveur MCP (`laravel/mcp`) | |
| 2 — Partage | invitations, visibilité par contenu, tokens par outil | |
| 3 — Événements | webhooks vers telegram-notifier, dashboard | |
| 4 — Recherche hybride | embeddings, RRF, jeu d'évaluation | |
| 5 — Infra | pilotage du cluster | |

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
