# LALI Holdings Management System (LHMS)

ERP central de **LALI Holdings** : multi-pays, multi-sociétés, modulaire et bilingue (français / anglais).

Le cœur de la plateforme (LHMS Core) gère l’organisation, les utilisateurs, les rôles, les permissions, l’activation des modules, l’audit et les notifications. Chaque activité du groupe (mode, engins, immobilier, rizerie, média) s’ajoute comme un **module métier** isolé. Les modules ne lisent pas les tables internes des autres modules : ils exposent des **contrats / services** au cœur.

---

## Sommaire

- [Aperçu](#aperçu)
- [Fonctionnalités](#fonctionnalités)
- [Architecture](#architecture)
- [Stack technique](#stack-technique)
- [Structure du dépôt](#structure-du-dépôt)
- [Prérequis](#prérequis)
- [Installation](#installation)
- [Lancer l’application](#lancer-lapplication)
- [Comptes de démonstration](#comptes-de-démonstration)
- [Données de seed](#données-de-seed)
- [Modules métier](#modules-métier)
- [Ajouter un module](#ajouter-un-module)
- [Rôles et permissions](#rôles-et-permissions)
- [API REST](#api-rest)
- [Frontend](#frontend)
- [Internationalisation](#internationalisation)
- [Scripts utiles](#scripts-utiles)
- [Licence](#licence)

---

## Aperçu

LHMS est un système de gestion de holding conçu pour un groupe présent en **République démocratique du Congo** et en **Tanzanie**. Un super-administrateur voit le tableau de bord groupe ; un administrateur de société n’accède qu’à son périmètre (King Attitude, rizerie, etc.).

Le frontend React est une SPA. En développement, Vite tourne sur le port `5173` et proxifie `/api` vers Laravel. En production, le build Vite est servi par Laravel (`backend/public/build`) via une vue Blade (`lhms.blade.php`).

---

## Fonctionnalités

| Domaine | Contenu |
|---|---|
| Authentification | Login / logout, jeton Sanctum, profil, avatar, mot de passe |
| Tableau de bord groupe | Vue consolidée, actions, dashboards par module |
| Organisation | Pays → provinces → villes → sociétés → bureaux → départements → activités |
| Sociétés | CRUD, devise, rattachement géographique, activation de modules |
| Utilisateurs | CRUD, rôles, scopes (groupe / société) |
| Modules | Catalogue, statut (actif, en développement, bientôt, inactif), activation par société |
| KAMS | Clients, produits, commandes, campagnes, indicateurs financiers |
| Notifications | Liste, marquer comme lu |
| Tâches | Suivi d’actions internes |
| Audit | Journal des actions sensibles |
| i18n | Interface FR / EN (i18next) + locale API |

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                         Frontend (React)                         │
│   SPA · React Router · i18next · Axios · Tailwind CSS 4          │
└───────────────────────────────┬─────────────────────────────────┘
                                │  REST /api/v1  (Sanctum)
┌───────────────────────────────▼─────────────────────────────────┐
│                         LHMS Core (Laravel)                      │
│  Auth · Organisation · Users · Roles · Modules · Audit · Notifs  │
│  Contrats : BusinessModule · FinancialContributor                │
└───────┬───────────┬───────────┬───────────┬───────────┬─────────┘
        │           │           │           │           │
     KAMS      Heavy Eq.   Real Estate  Rice Mills   LALI TV
   (actif)   (en dev.)    (bientôt)    (bientôt)   (inactif)
```

**Règle d’isolation :** le cœur n’interroge pas `kams_orders` ni les tables d’un autre module. Il demande un `financialSummary()` au module via `FinancialContributor`. Les routes d’un module sont enregistrées par son `ServiceProvider` sous `api/v1/modules/{slug}`.

---

## Stack technique

| Couche | Technologies |
|---|---|
| Backend | PHP 8.3+ / 8.4, [Laravel 13](https://laravel.com), API REST, [Laravel Sanctum](https://laravel.com/docs/sanctum) |
| Base de données | MySQL (`lhms`) |
| Frontend | React 19, TypeScript, Vite 8, Tailwind CSS 4, React Router 7 |
| HTTP client | Axios |
| Graphiques | Recharts |
| i18n | i18next + react-i18next |
| Auth API | Bearer token Sanctum |

---

## Structure du dépôt

```
lali-holdings-management-system/
├── README.md
├── frontend/                          # SPA React (Vite)
│   ├── src/
│   │   ├── App.tsx                    # Routes
│   │   ├── components/                # UI, layout, avatar, profil
│   │   ├── context/                   # Auth, modules système
│   │   ├── i18n/                      # fr.json, en.json
│   │   ├── modules/registry.ts        # Navigation par module
│   │   ├── pages/                     # Écrans (dashboard, org, KAMS…)
│   │   └── lib/                       # Formatage, helpers
│   ├── package.json
│   └── vite.config.ts                 # Proxy /api → :8000, build → backend/public/build
└── backend/                           # API Laravel + SPA fallback
    ├── app/
    │   ├── Core/                      # Contrats, ModuleManager, audit, permissions
    │   ├── Http/Controllers/Api/      # Contrôleurs du cœur
    │   ├── Models/                    # Organisation, users, rôles…
    │   └── Modules/                   # Un dossier par module métier
    │       ├── Kams/
    │       ├── HeavyEquipment/
    │       ├── RealEstate/
    │       ├── RiceMills/
    │       └── LaliTv/
    ├── config/lhms.php                # Catalogue des modules
    ├── database/migrations/
    ├── database/seeders/DatabaseSeeder.php
    ├── routes/api.php                 # API v1 du cœur
    └── resources/views/lhms.blade.php # Point d’entrée SPA
```

---

## Prérequis

- **PHP** 8.3 ou 8.4, extensions : `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`
- **Composer** 2
- **Node.js** 20+ et npm
- **MySQL** 8 (base `lhms`)
- Optionnel : Redis si vous changez le cache / les files d’attente

---

## Installation

### 1. Cloner le dépôt

```bash
git clone https://github.com/heritiermulume/lali-holdings-management-system.git
cd lali-holdings-management-system
```

### 2. Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Ajustez `.env` si besoin. Valeurs locales par défaut :

| Variable | Valeur |
|---|---|
| `APP_URL` | `http://localhost:8000` |
| `FRONTEND_URL` | `http://localhost:5173` |
| `APP_LOCALE` | `fr` |
| `DB_CONNECTION` | `mysql` |
| `DB_DATABASE` | `lhms` |
| `DB_USERNAME` | `root` |
| `DB_PASSWORD` | *(vide)* |

Créez la base puis migrez et semez :

```sql
CREATE DATABASE lhms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
php artisan migrate:fresh --seed
php artisan storage:link
```

`migrate:fresh --seed` **efface** toutes les tables. En environnement déjà peuplé, préférez `php artisan migrate` puis `php artisan db:seed`.

### 3. Frontend

```bash
cd ../frontend
npm install
```

---

## Lancer l’application

Deux processus sont nécessaires en développement.

**Terminal 1 — API Laravel**

```bash
cd backend
php artisan serve
```

API : [http://127.0.0.1:8000](http://127.0.0.1:8000)  
Santé : [http://127.0.0.1:8000/up](http://127.0.0.1:8000/up)

**Terminal 2 — Vite**

```bash
cd frontend
npm run dev
```

Interface : [http://127.0.0.1:5173](http://127.0.0.1:5173)

Vite proxifie `/api` vers `http://127.0.0.1:8000`. CORS autorise `FRONTEND_URL`.

### Build de production (SPA servie par Laravel)

```bash
cd frontend
npm run build
```

Le build est écrit dans `backend/public/build` (manifest Vite). Laravel sert alors la SPA sur `APP_URL` **sans** serveur Vite. En local, si le build est absent et que Vite tourne, `SpaController` injecte les scripts du serveur de développement.

---

## Comptes de démonstration

Créés par `DatabaseSeeder` :

| Rôle | Email | Mot de passe |
|---|---|---|
| Super Administrator | `samuel.w@example.com` | `Password123!` |
| Company Administrator (King Attitude) | `kevin.m@example.com` | `Password123!` |

Changez ces mots de passe avant tout déploiement réel.

---

## Données de seed

Le seeder installe une géographie et des sociétés représentatives du groupe :

| Code | Société | Pays / ville |
|---|---|---|
| `LALI` | LALI Holdings (siège) | RDC — Kinshasa |
| `KAMS` | King Attitude | RDC — Goma |
| `LMRM` | LALI Miembeni Rice Mills | Tanzanie — Zanzibar |

Pays seedés : **CD** (USD) et **TZ** (TZS). KAMS reçoit des clients, produits, une commande démo et des campagnes.

---

## Modules métier

| Slug | Nom | Statut | Contenu |
|---|---|---|---|
| `kams` | King Attitude Management System | **Actif** (v1.0.0) | Clients, produits, commandes, campagnes, contribution financière au dashboard |
| `heavy-equipment` | LALI Heavy Equipment | En développement | Stub (parc, locations, maintenance) |
| `real-estate` | LALI Apartments / Real Estate | Bientôt | Stub |
| `rice-mills` | LALI Miembeni Rice Mills | Bientôt | Stub |
| `lali-tv` | LALI TV | Inactif | Stub |

Les stubs implémentent `BusinessModule` via `App\Modules\Support\StubModule` : ils apparaissent au catalogue sans routes métier.

### KAMS (module actif)

Préfixe API : `/api/v1/modules/kams`

| Ressource | Opérations |
|---|---|
| `GET dashboard` | Indicateurs (permission `kams.view`) |
| Clients | liste, création, mise à jour, suppression |
| Produits | SKU, stock, collections |
| Commandes | création, mise à jour de statut |
| Campagnes | marketing / collections |

Écrans React : `/modules/kams`, `/clients`, `/stock`, `/finance`, `/campaigns`.

---

## Ajouter un module

1. Créer `backend/app/Modules/{Nom}/` avec :
   - `{Nom}Module.php` implémentant `App\Core\Contracts\BusinessModule`
   - optionnellement `FinancialContributor` pour alimenter le dashboard groupe
   - `{Nom}ServiceProvider.php` qui enregistre les routes sous `api/v1/modules/{slug}`
   - `routes.php`, contrôleurs, modèles, migrations
2. Enregistrer la classe dans `backend/config/lhms.php` (`modules`)
3. Ajouter le provider dans `backend/bootstrap/providers.php`
4. Déclarer les permissions via `PermissionCatalog::forModule('slug')` (seeder / migration)
5. Côté frontend : entrée dans `frontend/src/modules/registry.ts`, routes dans `App.tsx`, clés i18n dans `fr.json` / `en.json`

Les modules communiquent avec le cœur uniquement par les contrats (`BusinessModule`, `FinancialContributor`) et les APIs publiques, jamais en joignant les tables d’un autre module.

---

## Rôles et permissions

Les permissions du cœur sont définies dans `App\Core\Support\PermissionCatalog`. Chaque module ajoute `view`, `create`, `update`, `delete`, `approve`, `export`.

Profils KAMS (niveaux décroissants) :

| Rôle | Niveau | Périmètre typique |
|---|---|---|
| Super Admin | 100 | Toutes les permissions, tout le système |
| Partenaire | 90 | Tous les droits, sauf supprimer / désactiver un Super Admin |
| Vice-président | 80 | KAMS complet + lecture groupe (hors bureau unique) |
| Divisionnaire | 70 | KAMS régional |
| Manager | 60 | Bureau + opérations KAMS |
| Secrétaire | 50 | Bureau, suivi administratif KAMS |
| Assistant Manager | 40 | Bureau, opérations limitées |
| Chef | 30 | Équipe terrain |
| Distributeur | 20 | Ventes et clients |

Les routes API sont protégées par `auth:sanctum` et `EnsurePermission` (`permission.slug`). Le middleware `SetLocale` aligne la langue API sur l’utilisateur / l’en-tête.

---

## API REST

Base : **`/api/v1`**

Authentification : `POST /auth/login` → jeton Bearer. En-tête : `Authorization: Bearer {token}`.

### Cœur (extrait)

| Méthode | Chemin | Permission |
|---|---|---|
| `POST` | `/auth/login` | public |
| `GET` | `/auth/me` | authentifié |
| `PUT` | `/auth/profile` | authentifié |
| `POST` | `/auth/avatar` | authentifié |
| `PUT` | `/auth/password` | authentifié |
| `POST` | `/auth/logout` | authentifié |
| `GET` | `/dashboard` | authentifié |
| `GET/POST` | `/notifications`, `…/read`, `…/read-all` | authentifié |
| `GET/POST/PUT` | `/tasks` | authentifié |
| `GET/PUT` | `/modules`, `/modules/{module}` | `modules.view` / `modules.manage` |
| `PUT` | `/companies/{company}/modules` | `modules.manage` |
| `CRUD` | `/users` | `users.*` |
| `GET/PUT` | `/roles`, `GET /permissions` | `roles.view` / `roles.manage` |
| `CRUD` | `/countries`, `/provinces`, `/cities`, `/offices`, `/departments`, `/activities` | `organization.*` |
| `CRUD` | `/companies` | `companies.*` |
| `GET` | `/organization/tree` | `organization.view` |
| `GET` | `/audit-logs` | `audit.view` |
| `GET/PUT` | `/settings` | `settings.manage` |

Les routes métier KAMS sont listées plus haut. Laravel ignore le préfixe SPA `api|sanctum|up|storage` pour laisser l’API et les fichiers publics intacts.

---

## Frontend

- **Auth** : `AuthContext` stocke l’utilisateur et le jeton ; les routes hors `/login` passent par un `Guard`.
- **Layout** : `AppShell` (navigation groupe) + `ModuleWorkspaceLayout` (sous-navigation d’un module).
- **Modules** : `moduleRegistry` décrit icônes, libellés i18n et items de menu ; `ModulesContext` synchronise le catalogue serveur (activation / statut).
- **UI** : composants partagés dans `components/ui.tsx`, icônes Lucide, thème vert holding (`#094924`).

Routes principales :

| Chemin | Page |
|---|---|
| `/login` | Connexion |
| `/` | Tableau de bord |
| `/modules/dashboard` | Vue groupe |
| `/modules/kams/*` | Espace KAMS |
| `/organization` | Arbre organisationnel |
| `/companies` | Sociétés et modules |
| `/users` | Utilisateurs |
| `/notifications` | Notifications |
| `/audit` | Journal d’audit |
| `/modules` | Catalogue des modules |

---

## Internationalisation

- Frontend : `frontend/src/i18n/fr.json` et `en.json`.
- Backend : `APP_LOCALE=fr`, `APP_FALLBACK_LOCALE=en`, noms FR/EN sur pays, modules, etc.
- Locales supportées : `config/lhms.php` → `supported_locales`.

---

## Scripts utiles

**Frontend** (`frontend/package.json`)

| Commande | Rôle |
|---|---|
| `npm run dev` | Serveur Vite (port 5173) |
| `npm run build` | `tsc -b` + bundle vers `backend/public/build` |
| `npm run preview` | Prévisualisation du build |
| `npm run lint` | Oxlint |

**Backend**

| Commande | Rôle |
|---|---|
| `php artisan serve` | Serveur HTTP |
| `php artisan migrate:fresh --seed` | Recréer la base + démo |
| `php artisan test` | Tests PHPUnit |
| `vendor/bin/pint` | Formatage PHP |

---

## Licence

Usage interne LALI Holdings. Le squelette Laravel reste sous licence MIT (dépendances Composer). Le code applicatif de ce dépôt n’est pas publié comme logiciel libre sauf mention contraire du propriétaire du dépôt.

---

**Dépôt :** [github.com/heritiermulume/lali-holdings-management-system](https://github.com/heritiermulume/lali-holdings-management-system)
