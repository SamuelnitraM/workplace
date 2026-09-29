# 3. Mettre en production (PC → alwaysdata)

La production publie la branche **`main`** telle qu'elle est sur GitHub. Avant de commencer : tout est poussé ([Envoyer vers Git](02-envoyer-vers-git.md)) et le PC est à jour ([Récupérer depuis Git](01-recuperer-depuis-git.md)).

| | Valeur |
|---|---|
| Hébergement | alwaysdata, offre gratuite |
| Base | MariaDB `hforge_prod` sur `mysql-hforge.alwaysdata.net`, utilisateur `hforge` |
| Secrets | `.env.local` **du serveur** (`APP_ENV=prod`, `APP_SECRET`, `DATABASE_URL`, `PUSHER_*`, `MAILER_*`) : il n'est jamais envoyé ni écrasé |

Dans les commandes du serveur, `~/www` désigne le dossier du site (celui qui contient `bin/console`) : l'adapter s'il porte un autre nom.

## Étape 1 — Préparer l'envoi sur le PC (PowerShell)

```powershell
git switch main
git pull origin main
php bin/console tailwind:build --minify
git archive --format=zip --output=..\sprue-deploy.zip main
```

- `git archive` produit une copie **propre** de `main` : uniquement les fichiers versionnés, donc jamais `.env.local`, `vendor/`, `var/` ni les photos des membres.
- `tailwind:build --minify` produit `var\tailwind\app.built.css`. Il se construit **sur le PC** : sur l'offre gratuite, la commande est interrompue faute de mémoire.

## Étape 2 — Envoyer (FileZilla)

| Fichier du PC | Destination sur le serveur |
|---|---|
| `..\sprue-deploy.zip` | `~/sprue-deploy.zip` |
| `var\tailwind\app.built.css` | `~/www/var/tailwind/app.built.css` (créer le dossier `var/tailwind` s'il manque) |

## Étape 3 — Installer (SSH, bash)

```bash
cd ~/www

# 1. Sauvegarde de la base et du code en place
mysqldump --no-tablespaces -h mysql-hforge.alwaysdata.net -u hforge -p hforge_prod > ~/backup-db-$(date +%F).sql
rm -rf ~/ancien && mkdir ~/ancien && rsync -a --exclude=var/ --exclude=public/uploads/ ./ ~/ancien/

# 2. Nouveau code : fichiers remplacés, fichiers supprimés du dépôt retirés du serveur
rm -rf ~/sprue-deploy && unzip -q ~/sprue-deploy.zip -d ~/sprue-deploy
rsync -a --delete \
  --exclude=.env.local --exclude=vendor/ --exclude=var/ \
  --exclude=public/uploads/ --exclude=public/bundles/ --exclude=public/assets/ --exclude=assets/vendor/ \
  ~/sprue-deploy/ ./

# 3. Dépendances, base, bibliothèques JavaScript, assets, cache
composer install --no-dev --optimize-autoloader
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console importmap:install
php bin/console asset-map:compile
php bin/console cache:clear

# 4. Ménage
rm -rf ~/sprue-deploy ~/sprue-deploy.zip
```

- `rsync --delete` retire du serveur les fichiers supprimés du dépôt, sans toucher aux exclusions (secrets, dépendances, cache, photos des membres, CSS construit).
- `importmap:install` télécharge les bibliothèques JavaScript (Turbo, Stimulus, Alpine, Pusher…) : sans elle, le site s'affiche sans aucune interactivité.

## Étape 4 — Selon les changements

| Changement | Commande |
|---|---|
| Badges modifiés dans `BadgeCatalog` | `php bin/console app:gamification:sync-badges` |
| Règles d'XP ou de badges modifiées | `php bin/console app:gamification:recompute --resum` |
| Nouvelles catégories du forum dans la commande de création | `php bin/console app:forum:seed-categories` |
| Extraction BSData modifiée | `php bin/console army:sync-bsdata --force` |

## Étape 5 — Vérifier

- ouvrir le site, se connecter, parcourir accueil, forum, un profil ;
- ouvrir la messagerie flottante (preuve que JavaScript et Pusher sont chargés) ;
- en cas de page d'erreur : `tail -n 50 var/log/prod.log`.

Une erreur 500 dès l'accueil vient presque toujours d'un `.env.local` absent ou sans `APP_ENV=prod` : le site démarre alors en `dev` alors que les paquets de développement ne sont pas installés.

## Revenir en arrière

```bash
cd ~/www
rsync -a --delete --exclude=.env.local --exclude=var/ --exclude=public/uploads/ ~/ancien/ ./
composer install --no-dev --optimize-autoloader
php bin/console cache:clear
```

Si une migration a modifié la base, restaurer aussi la sauvegarde :

```bash
mysql -h mysql-hforge.alwaysdata.net -u hforge -p hforge_prod < ~/backup-db-AAAA-MM-JJ.sql
```

## Tâches planifiées (une seule fois)

Dans l'administration alwaysdata : **Avancé › Tâches planifiées › Ajouter**, type « Exécuter la commande », une tâche par ligne :

| Commande | Fréquence |
|---|---|
| `cd ~/www && php bin/console app:notifications:purge --no-interaction` | chaque nuit (par exemple 3 h 10) |
| `cd ~/www && php bin/console app:accounts:purge-inactive --no-interaction` | chaque nuit (3 h 20) |
| `cd ~/www && php bin/console app:moderation:purge --no-interaction` | chaque nuit (3 h 30) |
| `cd ~/www && php bin/console army:sync-bsdata --no-interaction` | chaque semaine |
| `mysqldump --no-tablespaces -h mysql-hforge.alwaysdata.net -u hforge -p'MOT_DE_PASSE' hforge_prod > ~/backup-db-$(date +\%u).sql` | chaque nuit (7 fichiers tournants, un par jour de la semaine) |

Dans une tâche planifiée, le `%` doit être échappé (`\%`). Les durées de conservation appliquées par les purges se règlent dans `config/packages/legal.yaml`.
