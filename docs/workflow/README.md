# Workflow

Les trois trajets du code, dans l'ordre où ils s'enchaînent :

| Étape | Document | Quand |
|---|---|---|
| 1 | [Récupérer depuis Git](01-recuperer-depuis-git.md) | Avant de travailler : mettre le PC à jour avec GitHub, ou l'écraser par la version de GitHub |
| 2 | [Envoyer vers Git](02-envoyer-vers-git.md) | Après avoir travaillé : vérifier, committer, pousser |
| 3 | [Mettre en production](03-mise-en-production.md) | Publier sur alwaysdata une version poussée sur `main` |

## Repères

| | Valeur |
|---|---|
| Dépôt | `https://github.com/SamuelnitraM/workplace` |
| Branche de référence | `main` : c'est elle qui part en production |
| PC | Windows, XAMPP (PHP, MariaDB), **PowerShell 5** : une commande par ligne, pas de `&&` |
| Serveur | alwaysdata, accès **SSH** (bash) et **FileZilla** (SFTP) |
| Base locale | `highlightforge` (connexion dans `.env.local`) |
| Base de test | `highlightforge_test` (connexion dans `.env.test.local`) |

**Jamais dans Git** : `.env.local`, `.env.test.local`, `vendor/`, `var/`, `public/uploads/*`, `public/assets/`, `assets/vendor/`. Ces fichiers restent sur chaque machine ; les commandes des documents suivants les régénèrent quand c'est possible.

**Ne jamais créer de fichier `git.bat`** à la racine du projet : `cmd.exe` (utilisé par Composer et Symfony) l'exécuterait à la place du vrai Git.
