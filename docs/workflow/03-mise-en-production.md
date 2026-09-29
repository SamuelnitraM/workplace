# 3. Mettre en production (PC → alwaysdata)

La production publie la branche **`main`** telle qu'elle est sur GitHub. Avant de commencer : tout est poussé ([Envoyer vers Git](02-envoyer-vers-git.md)) et le PC est à jour ([Récupérer depuis Git](01-recuperer-depuis-git.md)).

Deux méthodes, au choix :

| | [A. SSH + FileZilla](#méthode-a--ssh--filezilla) | [B. FileZilla seul (FTP)](#méthode-b--filezilla-seul-ftp) |
|---|---|---|
| Principe | On envoie une archive du code, le serveur installe tout lui-même | Le PC prépare un site complet, prêt à tourner, et on envoie les fichiers |
| Durée | Quelques minutes | Plus long : les fichiers partent un par un |
| Fichiers supprimés du dépôt | Retirés automatiquement du serveur | À supprimer à la main (liste fournie par Git) |
| Base de données | Une commande | Tâche planifiée ou phpMyAdmin |

---

## Le serveur

### Organisation

Tout le site est **en vrac dans `~/www`**, exactement comme le dossier du projet sur le PC :

```
~/www/
├── .env              valeurs par défaut, sans secret (envoyé avec le code)
├── .env.local        ⚠ SECRETS DE PRODUCTION — n'existe que sur le serveur
├── assets/  bin/  config/  migrations/  src/  templates/  translations/
├── composer.json  composer.lock  importmap.php  symfony.lock
├── public/           seul dossier qui doit être visible depuis le navigateur
│   ├── index.php
│   ├── assets/       CSS et JavaScript compilés
│   ├── bundles/      fichiers de l'administration (EasyAdmin)
│   ├── images/  favicon.ico
│   └── uploads/      ⚠ PHOTOS DES MEMBRES — n'existent que sur le serveur
├── var/              ⚠ cache et journaux du serveur
└── vendor/           dépendances PHP
```

`~` est le dossier personnel du compte alwaysdata (`/home/<compte>`).

### À ne jamais écraser ni supprimer

| Élément | Pourquoi |
|---|---|
| `www/.env.local` | Les secrets de production : `APP_ENV=prod`, `APP_SECRET`, `DATABASE_URL`, `PUSHER_*`, `MAILER_*`. Sans lui, le site démarre en mode développement et affiche une erreur 500 |
| `www/public/uploads/` | Avatars, bannières, galeries et images du forum des membres : ils n'existent nulle part ailleurs |
| `www/var/log/` | Journaux d'erreurs de production |
| Tout `.htaccess` ajouté directement sur le serveur (par exemple `www/public/.htaccess`) | Configuration propre au serveur, absente du dépôt |

### Vérification de sécurité (une fois)

Le navigateur ne doit voir **que** `www/public`. Dans l'administration alwaysdata, **Web › Sites › (le site) › Modifier** : le répertoire racine doit être `www/public`.

Test : ouvrir `https://<adresse-du-site>/composer.json` et `https://<adresse-du-site>/.env.local`. Les deux doivent répondre **404**. Si l'un des deux s'affiche, les secrets de production sont lisibles par n'importe qui : corriger le répertoire racine immédiatement, puis changer tous les mots de passe et clés de `.env.local`.

### Base de données

MariaDB `hforge_prod` sur `mysql-hforge.alwaysdata.net`, utilisateur `hforge`. phpMyAdmin : administration alwaysdata › **Bases de données** › lien phpMyAdmin.

---

## Étape commune — Préparer la version sur le PC (PowerShell)

```powershell
git switch main
git pull origin main
php bin/phpunit
```

Repérer ce qui a changé depuis la dernière mise en production, grâce à l'étiquette posée la fois précédente (voir la fin de ce document). Remplacer `prod-2026-09-28` par la dernière étiquette (`git tag` les liste toutes) :

```powershell
git diff --name-status prod-2026-09-28 main
```

Chaque ligne commence par une lettre : `A` ajouté, `M` modifié, `D` **supprimé**, `R` renommé. À noter pour la suite :
- une ligne dans `migrations/` → **la base doit être mise à jour** ;
- `composer.lock` modifié → **les dépendances PHP ont changé** ;
- `importmap.php` modifié → **les bibliothèques JavaScript ont changé**.

Pour la toute première mise en production, il n'y a pas d'étiquette : tout est à envoyer.

---

## Méthode A — SSH + FileZilla

### A1. Sur le PC (PowerShell)

```powershell
php bin/console tailwind:build --minify
git archive --format=zip --output=..\sprue-deploy.zip main
```

- `git archive` produit une copie **propre** de `main` : uniquement les fichiers versionnés, donc jamais `.env.local`, `vendor/`, `var/` ni les photos des membres.
- `tailwind:build --minify` produit `var\tailwind\app.built.css`. Il se construit **sur le PC** : sur l'offre gratuite, la commande est interrompue faute de mémoire.

### A2. Envoyer (FileZilla)

| Fichier du PC | Destination sur le serveur |
|---|---|
| `..\sprue-deploy.zip` | `~/sprue-deploy.zip` (dossier personnel, **pas** dans `www`) |
| `var\tailwind\app.built.css` | `~/www/var/tailwind/app.built.css` (créer `var/tailwind` s'il manque) |

### A3. Installer (SSH)

```bash
cd ~/www

# 0. Contrôle : le serveur doit tourner en production (doit afficher « prod » et « false »)
php bin/console about | grep -E "Environment|Debug"

# 1. Sauvegarde de la base et du code en place
mysqldump --no-tablespaces -h mysql-hforge.alwaysdata.net -u hforge -p hforge_prod > ~/backup-db-$(date +%F).sql
rm -rf ~/ancien && mkdir ~/ancien && rsync -a --exclude=var/ --exclude=public/uploads/ ./ ~/ancien/

# 2. Nouveau code : fichiers remplacés, fichiers supprimés du dépôt retirés du serveur
rm -rf ~/sprue-deploy && unzip -q ~/sprue-deploy.zip -d ~/sprue-deploy
rsync -a --delete \
  --exclude=.env.local --exclude=vendor/ --exclude=var/ \
  --exclude=public/uploads/ --exclude=public/bundles/ --exclude=public/assets/ \
  --exclude=public/.htaccess --exclude=assets/vendor/ \
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

- Si le contrôle 0 affiche `dev` (ou si une commande échoue sur `Attempted to load class "DebugBundle"`), **s'arrêter** : `.env.local` est absent, ou ne contient pas `APP_ENV=prod`. Voir [Dépannage](#dépannage).
- `rsync --delete` rend `www` identique à `main`, sauf les exclusions (secrets, dépendances, cache, photos des membres, CSS construit, `.htaccess` propre au serveur). Si d'autres fichiers ont été créés à la main dans `www`, ajouter une ligne `--exclude=` pour chacun.
- `importmap:install` télécharge les bibliothèques JavaScript (Turbo, Stimulus, Alpine, Pusher…) : sans elle, le site s'affiche sans aucune interactivité.

Continuer avec [Selon les changements](#selon-les-changements), puis [Vérifier](#vérifier).

---

## Méthode B — FileZilla seul (FTP)

Sans SSH, le serveur ne peut rien installer : le PC prépare un site **complet et prêt à tourner** dans un dossier à part, et FileZilla l'envoie.

### B1. Construire le site de production sur le PC (PowerShell)

Dans un dossier **séparé** du projet (sinon le site local serait mis en mode production) :

```powershell
Remove-Item -Recurse -Force C:\sprue-prod -ErrorAction SilentlyContinue
git archive --format=zip --output=C:\sprue-prod.zip main
Expand-Archive C:\sprue-prod.zip -DestinationPath C:\sprue-prod
Remove-Item C:\sprue-prod.zip
cd C:\sprue-prod
$env:APP_ENV = "prod"
composer install --no-dev --optimize-autoloader
php bin/console tailwind:build --minify
php bin/console asset-map:compile
Remove-Item Env:APP_ENV
```

- `composer install` installe les dépendances PHP sans les outils de développement, puis lance automatiquement `assets:install` (fichiers de l'administration dans `public\bundles`) et `importmap:install` (bibliothèques JavaScript).
- `asset-map:compile` produit `public\assets` : le CSS et le JavaScript définitifs, servis tels quels par le serveur.
- `$env:APP_ENV = "prod"` ne vaut que pour cette fenêtre PowerShell ; `Remove-Item Env:APP_ENV` l'annule. **Refermer ensuite la fenêtre** avant de retravailler sur le projet.

`C:\sprue-prod` contient maintenant le site tel qu'il doit être dans `www`, **sauf** `var\` et `.env.local`, qui ne doivent jamais être envoyés.

### B2. Sauvegarder

- **Base** : phpMyAdmin › base `hforge_prod` › **Exporter** › Exécuter : garder le fichier `.sql` sur le PC.
- **Code** (facultatif) : télécharger `www` dans un dossier du PC, **sans** `public/uploads` ni `vendor`.

### B3. Envoyer (FileZilla)

Réglage à faire une fois : **Édition › Paramètres › Transferts › Actions sur les fichiers existants › Téléchargement vers le serveur : Écraser le fichier**.

Panneau de gauche : `C:\sprue-prod`. Panneau de droite : `~/www`.

| Quoi | Quand |
|---|---|
| Les fichiers et dossiers modifiés ou ajoutés : lignes `A`, `M` et `R` de l'[étape commune](#étape-commune--préparer-la-version-sur-le-pc-powershell) | À chaque mise en production |
| `public/assets/` **en entier** (supprimer d'abord l'ancien dossier `public/assets` sur le serveur) | À chaque mise en production |
| `public/bundles/` en entier | Si `composer.lock` a changé |
| `vendor/` en entier (environ 25 000 fichiers : compter une à plusieurs heures) | Si `composer.lock` a changé, et la première fois |
| Tout le contenu de `C:\sprue-prod` **sauf** `var/` | La première fois |

Plus simple, au prix d'un envoi plus long : sélectionner tout le contenu de `C:\sprue-prod` sauf `var` et `vendor`, et l'envoyer ; FileZilla écrase ce qui existe.

Ne **jamais** envoyer `var/` ni un `.env.local`, et ne rien supprimer dans `public/uploads/`.

### B4. Retirer les fichiers supprimés du dépôt

FileZilla ajoute et remplace, mais ne supprime jamais. Pour chaque ligne `D` de l'étape commune (et l'ancien nom des lignes `R`), supprimer le fichier correspondant dans `~/www` (clic droit › Supprimer). Un fichier PHP oublié dans `src/` peut provoquer une erreur 500.

### B5. Mettre à jour la base (si `migrations/` a changé)

**Par une tâche planifiée** (recommandé) : administration alwaysdata › **Avancé › Tâches planifiées › Ajouter** :
- type : exécuter la commande ;
- commande : `cd ~/www && php bin/console doctrine:migrations:migrate --no-interaction` ;
- fréquence : une exécution quelques minutes plus tard.

Après son passage, vérifier dans le journal de la tâche qu'elle se termine par `[OK]`, puis **supprimer la tâche**.

**Par phpMyAdmin** (si la tâche planifiée n'est pas possible) : pour chaque nouveau fichier de `migrations/`, dans l'ordre des noms :
1. ouvrir le fichier et exécuter, dans l'onglet **SQL** de `hforge_prod`, chaque requête écrite dans les `$this->addSql('…')` de la fonction `up()`. Remplacer les éventuels paramètres `:nom` par leur valeur, écrite dans le même fichier ;
2. enregistrer la migration comme passée, en remplaçant le nom de version :
```sql
INSERT INTO doctrine_migration_versions (version, executed_at, execution_time)
VALUES ('DoctrineMigrations\\Version20261115100000', NOW(), 0);
```

### B6. Vider le cache

Supprimer le dossier `~/www/var/cache/prod` dans FileZilla. Le site le reconstruit à la première visite (quelques secondes plus lente).

---

## Selon les changements

Ces commandes demandent SSH (`cd ~/www` d'abord), ou une tâche planifiée ponctuelle comme en B5 :

| Changement | Commande |
|---|---|
| Badges modifiés dans `BadgeCatalog` | `php bin/console app:gamification:sync-badges` |
| Règles d'XP ou de badges modifiées | `php bin/console app:gamification:recompute --resum` |
| Nouvelles catégories dans la commande de création du forum | `php bin/console app:forum:seed-categories` |
| Extraction BSData modifiée | `php bin/console army:sync-bsdata --force` |

## Vérifier

- ouvrir le site, se connecter, parcourir l'accueil, le forum et un profil ;
- ouvrir la messagerie flottante : si elle répond, JavaScript et Pusher sont chargés ;
- ouvrir `/admin` : l'administration doit être mise en forme (sinon `public/bundles` manque) ;
- en cas de page d'erreur : lire la fin de `~/www/var/log/prod.log` (FileZilla : télécharger le fichier ; SSH : `tail -n 50 var/log/prod.log`).

Une erreur 500 dès l'accueil vient presque toujours d'un `.env.local` absent ou sans `APP_ENV=prod`, ou d'un fichier supprimé du dépôt resté sur le serveur.

## Dépannage

| Symptôme | Cause | Solution |
|---|---|---|
| `Attempted to load class "DebugBundle"` (ou `WebProfilerBundle`, `MakerBundle`) pendant `composer install` ou une commande | Le serveur démarre en `dev` alors que les outils de développement ne sont pas installés | Vérifier `~/www/.env.local` : il doit exister et contenir `APP_ENV=prod` et `APP_DEBUG=0` ; un fichier `.env.local.php` éventuel passe avant lui (le supprimer ou le régénérer). Contrôle : `php bin/console about` affiche `prod`. Puis relancer toutes les commandes de A3 à partir de `composer install` |
| Les clés (base, Pusher, e-mails) disparaissent après une mise en production et le site repasse en `dev` | Les secrets ont été écrits dans `~/www/.env` : ce fichier fait partie du dépôt et il est remplacé à chaque envoi | Les mettre dans `~/www/.env.local` (`cp .env .env.local` si le `.env` du serveur les contient), puis remettre le `.env` du dépôt |
| Erreur 500 sur tout le site | Même cause, ou fichier supprimé du dépôt resté sur le serveur | Même contrôle, puis `tail -n 50 var/log/prod.log` |
| Site sans mise en forme ni interactivité | `importmap:install` ou `asset-map:compile` non lancés, ou CSS Tailwind absent | Envoyer `var/tailwind/app.built.css`, relancer les deux commandes et `cache:clear` |
| Administration sans mise en forme | `public/bundles` absent | `php bin/console assets:install public` |

`.env.local` perdu : le recréer dans `~/www` avec `APP_ENV=prod`, `APP_DEBUG=0`, `APP_SECRET` (nouveau : `php -r 'echo bin2hex(random_bytes(16)), PHP_EOL;'`, les membres devront se reconnecter), `DATABASE_URL`, `PUSHER_APP_ID`, `PUSHER_KEY`, `PUSHER_SECRET`, `PUSHER_CLUSTER`, `MAILER_DSN`, `MAILER_FROM_ADDRESS`, `MAILER_FROM_NAME`. En garder une copie hors du serveur (gestionnaire de mots de passe), jamais dans Git.

## Marquer la version publiée

Sur le PC, une fois la production vérifiée, poser une étiquette sur la version publiée. La prochaine mise en production s'en servira pour lister les changements :

```powershell
git tag prod-2026-09-29 main
git push origin prod-2026-09-29
```

## Revenir en arrière

**Avec SSH** :

```bash
cd ~/www
rsync -a --delete --exclude=.env.local --exclude=var/ --exclude=public/uploads/ --exclude=public/.htaccess ~/ancien/ ./
composer install --no-dev --optimize-autoloader
php bin/console cache:clear
```

**Avec FileZilla** : renvoyer la sauvegarde du code faite en B2 (ou reconstruire `C:\sprue-prod` depuis l'étiquette précédente : `git archive --format=zip --output=C:\sprue-prod.zip prod-2026-09-28`, puis l'étape B1 à partir de `Expand-Archive`), puis supprimer `~/www/var/cache/prod`.

Si une migration a modifié la base, restaurer aussi la sauvegarde : en SSH `mysql -h mysql-hforge.alwaysdata.net -u hforge -p hforge_prod < ~/backup-db-AAAA-MM-JJ.sql`, ou phpMyAdmin › **Importer** le fichier `.sql` gardé sur le PC.

---

## Tâches planifiées (une seule fois)

Administration alwaysdata › **Avancé › Tâches planifiées › Ajouter**, type « Exécuter la commande », une tâche par ligne :

| Commande | Fréquence |
|---|---|
| `cd ~/www && php bin/console app:notifications:purge --no-interaction` | Chaque nuit (par exemple 3 h 10) |
| `cd ~/www && php bin/console app:accounts:purge-inactive --no-interaction` | Chaque nuit (3 h 20) |
| `cd ~/www && php bin/console app:moderation:purge --no-interaction` | Chaque nuit (3 h 30) |
| `cd ~/www && php bin/console army:sync-bsdata --no-interaction` | Chaque semaine |
| `mysqldump --no-tablespaces -h mysql-hforge.alwaysdata.net -u hforge -p'MOT_DE_PASSE' hforge_prod > ~/backup-db-$(date +\%u).sql` | Chaque nuit (7 fichiers tournants, un par jour de la semaine) |

Dans une tâche planifiée, le `%` doit être échappé (`\%`). Les durées de conservation appliquées par les purges se règlent dans `config/packages/legal.yaml`.
