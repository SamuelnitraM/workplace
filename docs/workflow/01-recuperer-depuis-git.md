# 1. Récupérer depuis Git (GitHub → PC)

Toutes les commandes se tapent dans **PowerShell**, dans le dossier du projet, **une par ligne**.

## Première installation sur un PC

```powershell
cd C:\xampp\htdocs
git clone https://github.com/SamuelnitraM/workplace.git HighlightForge
cd HighlightForge
```

Créer `.env.local` à la racine (jamais versionné) avec au minimum :

```dotenv
APP_ENV=dev
DATABASE_URL="mysql://root:@127.0.0.1:3306/highlightforge?serverVersion=10.4.27-MariaDB&charset=utf8mb4"
PUSHER_APP_ID=…
PUSHER_KEY=…
PUSHER_SECRET=…
PUSHER_CLUSTER=eu
MAILER_DSN=null://null
```

Puis installer et créer la base :

```powershell
composer install
php bin/console importmap:install
php bin/console doctrine:database:create
php bin/console doctrine:schema:create
php bin/console doctrine:migrations:version --add --all --no-interaction
php bin/console app:forum:seed-categories
php bin/console app:gamification:sync-badges
php bin/console army:sync-bsdata
php bin/console tailwind:build
```

> L'historique des migrations ne se rejoue pas sur une base vide : une base neuve se crée avec `doctrine:schema:create`, puis toutes les migrations sont marquées comme passées.

Lancer le site : démarrer Apache et MySQL dans XAMPP, ou `symfony serve`.

## Mettre à jour le PC (cas normal)

À faire avant de commencer à travailler :

```powershell
git switch main
git pull origin main
composer install
php bin/console importmap:install
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console tailwind:build
php bin/console cache:clear
```

- `composer install` et `importmap:install` ne font rien s'il n'y a pas de nouvelle dépendance : on peut les lancer à chaque fois.
- `doctrine:migrations:migrate` applique les nouvelles migrations (colonnes, tables) à la base locale.
- Pendant le travail sur le CSS, `php bin/console tailwind:build --watch` reconstruit la feuille à chaque enregistrement.

## Écraser le PC par la version de GitHub

Quand le dossier local est dans un état incertain (modifications ratées, fichiers en trop) et qu'on veut repartir exactement de GitHub.

⚠️ **Toutes les modifications locales non poussées sont perdues.** Les fichiers ignorés par Git (`.env.local`, `vendor/`, `var/`, `public/uploads/`) ne sont **pas** touchés.

```powershell
git fetch origin
git switch main
git reset --hard origin/main
git clean -fd
```

- `reset --hard` remet chaque fichier suivi dans l'état de GitHub ;
- `clean -fd` supprime les fichiers et dossiers **non suivis** qui ne sont pas ignorés (par exemple un fichier créé à la main et jamais commité). Pour voir d'abord ce qui serait supprimé : `git clean -nd`.

Puis enchaîner les commandes de la section « Mettre à jour le PC » à partir de `composer install`.

## Récupérer une autre branche

```powershell
git fetch origin
git switch nom-de-la-branche
git pull origin nom-de-la-branche
```

Si la branche n'existe pas encore sur le PC, `git switch` la crée à partir de celle de GitHub.
