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
- PHP 8.2–8.6 disponibles mais `php` en CLI = 8.1 → utiliser `/opt/cpanel/ea-php84/root/usr/bin/php` pour `artisan`.
- Pas de worker permanent ni de websockets : files traitées par cron (`queue:work --stop-when-empty`).
- Configuration 100 % par variables d'environnement (jamais `env()` hors de `config/`) pour pouvoir déménager
  sur un VPS ou le cluster sans réécriture.

### ADR-7 — Authentification (à décider en phase 2)
Existant : JWT HS256 maison (`app/Services/JwtService.php`), correctement signé et vérifié.
Option : Laravel Sanctum (officiel, tokens avec « abilities » = permissions par outil, cf. ADR-4).

## Feuille de route

| Phase | Contenu | État |
|---|---|---|
| 0 — Fondations | git, dépôt privé, CI tests, bêta sur sous-domaine | en cours |
| 1 — Connaissance | modèle de fiche, import megadoc, BM25, serveur MCP (`laravel/mcp`) | |
| 2 — Partage | invitations, visibilité par contenu, tokens par outil | |
| 3 — Événements | webhooks vers telegram-notifier, dashboard | |
| 4 — Recherche hybride | embeddings, RRF, jeu d'évaluation | |
| 5 — Infra | pilotage du cluster | |

## Dette connue (relevé du 2026-11-24)

- [ ] `.env` utilise le `PASSWORD_PEPPER` d'exemple → générer un vrai secret (invalide les mots de passe existants).
- [ ] `ADMIN_EMAIL`/`ADMIN_NAME` définis deux fois dans `.env` : le second (maintainer) écrase l'admin.
- [ ] Route publique `POST /register` : contraire à ADR-4 → remplacer par un flux d'invitation.
- [ ] 7 appels `env()` hors de `config/` (ex. `JwtService`, `AuthController`) → renvoient `null` après `config:cache`.
- [ ] Migrations `create_users/roles/permissions_table` du 2025-12-17 redondantes avec celles de Laravel/Spatie (protégées par `hasTable`) → à fusionner.
- [ ] Tests : seulement les exemples Laravel.
