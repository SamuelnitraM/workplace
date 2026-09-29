# SprueHub — Récapitulatif du site

> Réseau social francophone du hobby de la figurine : Warhammer, autres wargames, peinture et maquettes.
> Ce document explique comment le site fonctionne, comment il est construit, quelles pages il contient et **quel fichier modifier** pour changer son contenu.
> Voir aussi : [design-system.md](design-system.md) (interface), [roadmap.md](roadmap.md) (suite du projet), [workflow/](workflow/README.md) (Git et mise en production).

---

## Sommaire

1. [Vue d'ensemble](#1-vue-densemble)
2. [Plan du site](#2-plan-du-site)
3. [Fonctionnement](#3-fonctionnement)
4. [Administration](#4-administration)
5. [Architecture](#5-architecture)
6. [Modifier le contenu du site](#6-modifier-le-contenu-du-site)
7. [Commandes console et tâches planifiées](#7-commandes-console-et-tâches-planifiées)
8. [Environnements](#8-environnements)
9. [Conventions de développement](#9-conventions-de-développement)

---

## 1. Vue d'ensemble

| Élément | Valeur |
|---|---|
| Nom | **SprueHub** (dépôt GitHub `SamuelnitraM/workplace`, dossier local HighlightForge) |
| Public | Joueurs de wargame, peintres de figurines, maquettistes ; inscription dès 15 ans |
| Langue | Français, tutoiement des membres |
| Production | alwaysdata (offre gratuite), base MariaDB `hforge_prod` |
| Développement | Windows, XAMPP (PHP 8.2 ou plus, MariaDB) |

**Grands blocs :**
1. Comptes, profils et galerie photo ;
2. Fil d'actualité et page d'accueil ;
3. Forum à catégories hiérarchiques ;
4. Amis et messagerie privée en temps réel ;
5. Groupes (salons, épingles, tâches partagées) et tâches personnelles ;
6. Listes d'armée Warhammer 40k (données BSData) ;
7. Gamification (XP, niveaux, badges, titres, classement) ;
8. Notifications en temps réel ;
9. Présentation guidée et didacticiel ;
10. Modération (signalements, sanctions, réclamations) ;
11. Données personnelles (pages légales, suppression de compte, purges).

---

## 2. Plan du site

### Pages publiques
```
/                                   Accueil (visiteur : présentation et carrousels ; membre : fil d'actualité)
/login                              Connexion (affiche le motif d'une sanction en cours)
/register                           Inscription
/mot-de-passe-oublie                Mot de passe oublié (e-mail ou pseudo)
├── /mot-de-passe-oublie/verifier               Confirmation d'envoi (identique que le compte existe ou non)
└── /mot-de-passe-oublie/reinitialiser/{token}  Nouveau mot de passe (lien à usage unique, 1 heure)
/verify/email?id=…                  Confirmation de l'adresse e-mail (lien signé)
/reclamation/{id}                   Réclamation d'un membre sanctionné (lien signé de la page de connexion)
/mentions-legales                   Mentions légales
/confidentialite                    Confidentialité et cookies
/conditions-utilisation             Conditions d'utilisation
/forum/                             Forum : catégories racines
├── /forum/category/{slug}          Catégorie : sous-catégories et sujets
└── /forum/thread/{slug}            Sujet et réponses
/profil/{username}                  Profil (onglets Galerie, Armées, Badges, Activité)
├── /profil/{username}/amis         Amis d'un membre
├── /profil/{username}/album/{id}   Album de la galerie
└── /profil/{username}/photo/{id}   Photo (likes, commentaires, précédente / suivante)
/army/explorer                      Explorer : listes d'armée publiques
/army/{id}                          Liste d'armée publique (ou la sienne)
├── /army/{id}/imprimer             Version imprimable / PDF
└── /army/{id}/export.txt           Export texte (format de l'application officielle)
/classement                         Classement des membres
/groups/                            Groupes (visiteur : groupes publics)
├── /groups/publics                 Tous les groupes publics
└── /groups/{slug}                  Groupe (selon visibilité et adhésion)
```

### Pages membres (connexion requise)
```
/bienvenue/etape/{1..4}             Présentation guidée après l'inscription
/didacticiel                        Visites guidées de chaque fonctionnalité
/profil/settings/edit               Paramètres : profil, son des notifications, confidentialité, sécurité, suppression du compte
/profil/settings/change-password    Changer de mot de passe
/friendship/list                    Amis, demandes, bloqués, suggestions
/messages/                          Messagerie privée
└── /messages/{username}            Conversation avec un ami
/notifications                      Toutes les notifications
/forum/category/{slug}/new-thread   Nouveau sujet
/groups/new                         Créer un groupe
/groups/{slug}/edit                 Paramètres du groupe (admin ou propriétaire)
/groups/{slug}/todo/                Tâches du groupe
/todo/                              Tâches personnelles
/army/                              Mes listes d'armée
├── /army/new                       Créer une liste
├── /army/import                    Importer une liste au format texte
└── /army/{id}/edit                 Modifier une liste
/signaler/{type}/{id}               Signaler un contenu
```

### Administration (`/admin`)
```
/admin                              Tableau de bord (ROLE_ADMIN ; un modérateur arrive sur les signalements)
├── /admin/report                   Signalements
├── /admin/appeal                   Réclamations
├── /admin/moderation/report/{id}   Décision sur un signalement
├── /admin/moderation/appeal/{id}   Décision sur une réclamation
├── /admin/moderation/member/{id}   Sanctions et suppression d'un membre
├── /admin/user                     Utilisateurs
├── /admin/friendship               Amitiés
├── /admin/badge                    Badges (lecture seule)
├── /admin/category                 Catégories du forum
├── /admin/thread, /admin/post      Sujets, réponses
└── /admin/todo-node                Tâches
```

### Points d'accès techniques (sans page)
| Route | Rôle |
|---|---|
| `POST /heartbeat` | Signal de présence |
| `POST /pusher/auth` | Autorisation des canaux privés temps réel |
| `GET /search/api` | Recherche de l'en-tête |
| `GET /mentions?q=` | Suggestions de mention `@pseudo` |
| `GET /fil?fil=…&apres=…` | Suite du fil d'actualité |
| `POST /forum/editor/preview`, `POST /forum/editor/image` | Éditeur Markdown : aperçu, image |
| `GET /notifications/recent`, `POST /notifications/{id}/read`, `POST /notifications/read-all` | Menu des notifications |
| `/messages/ajax/…` | Messagerie flottante |
| `/army/units/{faction}`, `/army/detachments/{faction}`, `/army/enhancements/{faction}`, `/army/fiche`, `/army/fiche-de-liste` | Données et fiches du constructeur de listes |
| `POST /gamification/activity/{key}` | Activités des badges d'exploration |
| `/_styleguide` | Catalogue des composants d'interface (**développement uniquement**) |

Toutes les actions qui modifient des données sont en `POST` avec un jeton CSRF.

---

## 3. Fonctionnement

### 3.1 Comptes
- **Inscription** : pseudo, e-mail, mot de passe, case « J'ai au moins 15 ans et j'accepte les conditions ». Le mot de passe suit une règle unique (`App\Security\PasswordPolicy`) : **8 caractères minimum, avec majuscule, minuscule, chiffre et symbole**, vérifiée en direct pendant la saisie.
- **Adresse e-mail** : un lien signé (1 heure) la confirme ; tant qu'elle ne l'est pas, un bandeau propose de renvoyer le lien. Le compte reste utilisable.
- **Connexion** : « Se souvenir de moi » (30 jours), 5 échecs par minute au maximum. La connexion quotidienne rapporte de l'XP.
- **Mot de passe oublié** : lien à usage unique valable 1 heure, réponse identique que le compte existe ou non, une demande toutes les 10 minutes.
- **Déconnexion** : le membre disparaît aussitôt des connectés.
- **Suppression du compte** : voir 3.14.

### 3.2 Présentation guidée et didacticiel
- **Présentation** (`/bienvenue`, `OnboardingService`) après l'inscription, en 4 étapes passables : faction principale, profil (avatar, bio), groupes suggérés, conclusion. L'étape en cours est mémorisée ; le pied de page propose « Continuer la présentation » tant qu'elle n'est pas finie.
- **Didacticiel** (`/didacticiel`, lien « Je suis perdu ») : une visite guidée par fonctionnalité (Premiers pas, Forum, Groupes, Profil et galerie, Listes d'armée, Messagerie). La visite s'ouvre sur la page concernée (`?visite=<clé>`) et met en avant chaque élément avec driver.js ; un élément absent (mobile, page vide) donne une explication au centre de l'écran.

### 3.3 Accueil et fil d'actualité
- **Visiteur** : présentation du site, carrousels « Dernières créations » et « Tendances de la semaine » (photos les plus aimées sur 7 jours, complétées par les plus aimées de tous les temps).
- **Membre** : fil d'actualité (`App\Feed\FeedService`) avec les photos, sujets, listes publiques et badges des autres membres ; filtres Tout (amis et groupes), Amis, Groupes, Communauté, Actualités (sujets des catégories en lecture seule). 12 éléments par page, chargement automatique en bas du fil. « J'aime » et commentaires directement depuis le fil. Colonne de droite (masquée sur mobile) : dernières discussions, mes sujets, sections du forum.

### 3.4 Profil et galerie
- **En-tête** : bannière, avatar, pseudo, **titre** (badge choisi), niveau et barre d'XP, série de connexion, faction favorite, bio, compteur d'amis cliquable.
- **Onglets** : Galerie, Armées (listes publiques), Badges (par catégorie, progression, rareté, historique d'XP), Activité (masquable par le membre).
- **Paramètres** : avatar et bannière recadrés dans le navigateur (Cropper.js ; le serveur applique exactement le cadre, WebP), bio, faction, titre, son des notifications, visibilité de l'activité, mot de passe, suppression du compte.
- **Galerie** : 10 photos par membre, description, masquage sans suppression, **albums** (20 au plus, une photo dans un album au plus ; création depuis les photos cochées, déplacement, renommage, suppression).
- **Page photo** : agrandissement, photo précédente / suivante (boutons et flèches du clavier), likes regroupés dans une seule notification, commentaires supprimables par leur auteur ou le propriétaire.

### 3.5 Forum
- **Catégories** hiérarchiques gérées dans l'administration : parent, position, description, icône facultative ; **création de sujets autorisée** ou non (une catégorie de regroupement affiche ses sous-catégories) ; **lecture seule** (seuls les administrateurs y écrivent ; ses sujets alimentent le filtre « Actualités »).
- **Sujets et réponses** en Markdown : barre d'outils, aperçu rendu par le serveur, images (collées ou glissées, WebP, 30 par jour), émoticônes, brouillon gardé dans le navigateur, citation d'une réponse.
- **Rendu** (`App\Forum\ForumMarkdown`) : HTML échappé, liens externes en `nofollow`, seules les images envoyées sur le site s'affichent.
- **Votes** « Positif » et « Aide » (une fois par membre), **mentions `@pseudo`** avec notification, **abonnements** automatiques (auteur et participants) avec notification des nouvelles réponses.
- **Sujet résolu** : l'auteur choisit la solution, qui rapporte 50 XP à son auteur.
- **Sujet ancien** (6 mois sans activité) : le formulaire prévient que la réponse le fera remonter.

### 3.6 Amis et messagerie privée
- **Amis** : demande, acceptation, refus, retrait ; suggestions par amis en commun.
- **Blocage** (`MemberBlocker`) : dans les deux sens, plus de demande d'ami, de message ni d'invitation ; bloquer retire l'amitié.
- **Messages privés** entre amis uniquement, en temps réel (pages `/messages` et messagerie flottante ouvrable partout), accusés de lecture, compteur de non-lus, mentions en liens, émoticônes.

### 3.7 Groupes
- **Création** : nom, description, public ou privé, ouvert aux demandes ou non ; le créateur est **propriétaire** (couronne).
- **Rôles** : membre, admin, propriétaire. Réglages par groupe : qui peut inviter, épingler, modifier les tâches, tout voir dans les tâches, gérer les assignations, et nombre de membres par tâche (1 à 3).
- **Page des groupes** : invitations reçues, mes groupes (tri par activité ou ordre personnalisé par glisser-déposer), suggestions (groupes publics de mes amis, puis populaires).
- **Salons** : droits de lecture et d'écriture par rôle, messages en temps réel, émoticônes, mentions, messages épinglés, sourdine par membre, signalement du groupe et des messages.
- **Tâches du groupe** : arbre projet → catégorie → tâche, progression, **assignations** (demande à valider ou mode libre), notifications des demandes et décisions. Règles centralisées dans `GroupVoter` et `TodoNodeVoter`.
- **Tâches personnelles** (`/todo/`) : même fonctionnement, pour soi seul.

### 3.8 Listes d'armée (Warhammer 40k)
- **Données** synchronisées depuis le dépôt GitHub **BSData/wh40k-11e** (`army:sync-bsdata`) : factions, unités (règles actuelles et Legends, sans les unités spéciales Crucible), points, armes, aptitudes, mots-clés, détachements et leurs améliorations.
- **Éditeur** (Alpine.js) : liste **libre** ou **officielle** (Incursion 1000, Force de frappe 2000, Assaut 3000 points), unités classées par catégorie, taille, Seigneur de guerre, améliorations, jauge de points.
- **Règles officielles** (`App\Army\ArmyListRules`, identiques côté navigateur et serveur) : limite de points, 3 exemplaires d'une fiche (6 pour les troupes de ligne et transports assignés, 1 pour un personnage épique), un Seigneur de guerre personnage, améliorations du détachement une fois chacune sur un personnage non épique. Une action interdite est refusée avec sa raison.
- **Page d'une liste** : unités par catégorie, fiches techniques complètes en fenêtre, export texte, téléchargement `.txt`, version imprimable / PDF, lien de partage, duplication.
- **Import** d'un texte de l'application officielle ou d'un export SprueHub ; les lignes non reconnues sont listées.
- **Explorer** : listes publiques filtrables (faction, détachement, format, recherche) ; compteurs de vues, exports et duplications ; « les plus dupliquées » et « les plus exportées ».

### 3.9 Gamification
- **XP** : +20 par jour de connexion, +100 tous les 7 jours de série (la série survit à 3 jours d'absence), XP des badges, +50 pour une solution. Niveau N à `100 × (N−1)^1,5` XP, niveau maximum 50. Toute l'XP passe par un grand livre (`experience_award`) qui empêche les doublons.
- **17 badges** en 4 catégories (Forum, Exploration, Niveau, Spécial), paliers de couleur bronze, argent, or, premium et honorifique ; icônes SVG dessinées pour le site. Un badge peut servir de **titre**.
- **Classement** (`/classement`) : niveau, XP de la semaine ou du mois (heure de Paris), badges, série, sujets, votes reçus ; 25 par page, 100 classés.

### 3.10 Notifications et présence
- **15 types** (amis, groupes, tâches, forum, galerie, gamification, modération), diffusés en temps réel sur le canal privé du membre : compteur, toast, menu mis à jour sans rechargement. Likes regroupés. Notifications lues supprimées après 90 jours.
- **Son** : 6 sons synthétisés dans le navigateur (aucun fichier audio), choisis dans les paramètres ; un seul son même avec plusieurs onglets.
- **Présence** : signal toutes les 30 secondes (suspendu quand l'onglet est caché), « en ligne » pendant 90 secondes. Le premier signal du jour alimente les statistiques d'activité.

### 3.11 Recherche
Barre de l'en-tête, résultats instantanés : membres, groupes publics, sections et sujets du forum.

### 3.12 Interface
- **Thème** clair, automatique (celui du système, par défaut) ou sombre : interrupteur à trois positions, choix gardé un an (cookie `hf_theme`) et appliqué par le serveur dès le premier affichage.
- **Navigation** : en-tête sur ordinateur (rubriques, recherche, bouton « Publier », messages, notifications, compte), barre du bas sur mobile, fils d'Ariane hiérarchiques, boutons **« Retour »** vers la page d'où l'on vient (`App\Navigation\BackLinkResolver` : « Retour à l'accueil », « Retour au sujet »…, retour dans l'historique à la même position de défilement).
- **Chargement** : barre de progression entre les pages, lignes squelettes pendant les chargements.
- **Partage** : feuille de partage du système, sinon copie du lien.
- **Favicon** : logo blanc pour une interface de navigateur sombre, noir pour une interface claire.

### 3.13 Modération
- **Signaler** (drapeau) : sujets, réponses, photos, commentaires, messages privés reçus, profils, groupes, messages de groupe ; motif et précisions ; un signalement en attente par membre et par contenu. L'extrait, l'auteur et le lien sont figés au moment du signalement.
- **Décision combinée** (`ModerationService::process()`, point d'entrée unique) : laisser, masquer ou supprimer le contenu, avertir, suspendre (24 h, 3 jours, 7 jours, 1 mois, définitif), note interne ; validation unique « Traiter » après récapitulatif ; rien n'est appliqué si une partie est invalide. L'auteur reçoit une notification et un e-mail.
- **Sanctions** depuis la fiche d'un membre ; une suspension expirée cesse d'elle-même. Code couleur : rouge banni, orange suspendu, jaune contenu retiré.
- **Pendant une sanction** : connexion refusée et session fermée ; la page de connexion affiche le motif tant que la sanction dure (cookie signé).
- **Réclamations** : un bouton sous le motif ouvre un formulaire (lien signé 2 heures, une réclamation par sanction) ; l'équipe lève ou maintient la sanction et répond par e-mail.
- **Membre banni définitivement** (`App\Moderation\BannedMembers`) : masqué partout (fil, classement, suggestions, recherche, carrousels, Explorer) ; son profil ne montre que photo, pseudo et « Banni » ; ses messages du forum et des groupes restent visibles avec la mention « Banni ».
- **Limites anti-spam** (`App\Security\SubmissionThrottle`, `config/packages/rate_limiter.yaml`) : inscription 3/heure, mot de passe oublié 5/heure, renvoi du lien de confirmation 3/heure, message privé 20/minute, message de salon 20/minute, sujet 5/heure, réponse 6/minute, commentaire 6/minute, envoi de photo 20/heure, import de liste d'armée 20/heure, signalement 10/heure. Les messages du forum sont limités à 20 000 caractères, la description d'un groupe à 2 000.

### 3.14 Données personnelles et pages légales
- **Pages légales** (`templates/legal/`) : mentions légales, confidentialité et cookies, conditions d'utilisation. Toutes les informations variables sont dans `config/packages/legal.yaml`.
- **Cookies** : uniquement des cookies nécessaires ou demandés (session, « Se souvenir de moi », CSRF, thème, motif de suspension) : pas de bandeau. Aucune bibliothèque chargée depuis un site tiers.
- **Suppression du compte** (`App\Account\AccountDeleter`, point d'entrée unique) :
  - par le membre (Paramètres, mot de passe et case de confirmation ; un administrateur ne peut pas supprimer son propre compte) ou par un administrateur (fiche Sanctions, en recopiant le pseudo ; jamais un membre de l'équipe) ;
  - les sujets, réponses, messages et tâches de groupe passent au compte technique **« Membre supprimé »** (créé au premier besoin, sans connexion possible, profil introuvable) ;
  - un groupe possédé passe à son plus ancien admin, sinon à son plus ancien membre, sinon il est supprimé ;
  - tout le reste est effacé ; un e-mail confirme la suppression.
- **Comptes inactifs** : après 3 ans sans activité, e-mail de prévenance puis suppression 30 jours plus tard ; toute activité annule la procédure. L'équipe n'est jamais concernée.
- **Conservation de la modération** : signalements et réclamations supprimés 12 mois après la décision, sauf tant que le membre visé est sanctionné.

---

## 4. Administration

**Accès** : `ROLE_ADMIN` voit tout ; `ROLE_MODERATOR` voit uniquement la modération (signalements, réclamations, sanctions).

**Tableau de bord** (`assets/styles/admin.css`, thème clair ou sombre d'EasyAdmin) :
1. **À traiter** : signalements et réclamations en attente, membres connectés ;
2. **Chiffres clés** : membres, actifs sur 7 jours, réponses et messages sur 30 jours ;
3. **Graphiques sur 30 jours** : membres actifs, inscriptions, rétention d'un jour sur l'autre (`App\Statistics`) ;
4. **Modération** : temps moyen de traitement, motifs fréquents, contenus les plus signalés, décisions ;
5. **Activité par période** (24 h, 7 jours, 30 jours, total) et contenu du site ;
6. **Derniers inscrits** et **sujets récents**.

**Menus** : Modération (Signalements, Réclamations, avec compteurs), Site (Utilisateurs avec colonne « Sanction » et actions Modifier / Sanctions, Amitiés, Badges), Forum (Catégories, Sujets, Réponses), Organisation (Tâches).

**Particularités** :
- les badges sont en lecture seule : leur source est `App\Gamification\BadgeCatalog` ;
- les signalements sont en lecture seule : un clic ouvre la page de décision, avec l'historique des signalements visant l'auteur ;
- un compte ne se supprime pas depuis la liste des utilisateurs, seulement depuis sa fiche Sanctions (anonymisation, transmission des groupes, e-mail).

---

## 5. Architecture

### 5.1 Technologies

| Couche | Technologie |
|---|---|
| Framework | Symfony 7.4, PHP 8.2 ou plus |
| Base de données | MariaDB, Doctrine ORM 3, migrations écrites à la main |
| Gabarits | Twig ; thème de formulaire global `templates/form/theme.html.twig` |
| Styles | Tailwind CSS v4 (`symfonycasts/tailwind-bundle`), jetons et composants dans `assets/styles/app.css` |
| JavaScript | AssetMapper et importmap (sans Node) : Turbo Drive, Stimulus, Alpine.js (constructeur de listes), driver.js, Cropper.js, pusher-js ; **toutes servies par le site** (`importmap:install`) |
| Temps réel | Pusher (canaux privés) |
| Administration | EasyAdmin 5 |
| E-mails | Symfony Mailer + Mailjet, envoi synchrone (pas de worker sur l'offre gratuite) |
| Sécurité | `symfony/rate-limiter`, `symfonycasts/reset-password-bundle`, `symfonycasts/verify-email-bundle` |
| Tests | PHPUnit 11 (`tests/Functional`, `tests/Unit`) |

### 5.2 Parcours d'une requête

```
Navigateur ──(Turbo Drive : seul le <body> est remplacé)──▶ public/index.php
  └─▶ EventSubscribers (suspension, gamification, présence…)
      └─▶ Contrôleur (src/Controller) : lit la requête, vérifie les droits (Voters), appelle les services
          └─▶ Services métier (src/Service, src/<Domaine>) : règles, calculs, envois
              └─▶ Repositories (src/Repository) ─▶ Entités Doctrine (src/Entity) ─▶ MariaDB
          └─▶ Twig (templates/) ─▶ HTML
Navigateur : Stimulus connecte les contrôleurs (assets/controllers) aux attributs data-controller du HTML
Temps réel : un service publie sur Pusher ─▶ assets/lib/realtime.js reçoit et distribue aux contrôleurs
```

- **Contrôleurs minces** : la logique métier vit dans les services, un service par règle critique (un seul point d'entrée pour la modération, la suppression de compte, l'envoi d'e-mails, l'XP, les invitations de groupe…).
- **Droits** : les Voters (`src/Security/Voter`) décident ; les contrôleurs et templates appellent `isGranted`.
- **Pas de JavaScript dans les templates** : tout comportement passe par un contrôleur Stimulus déclaré en attribut `data-controller`, chargé seulement quand la page en a besoin (contrôleurs « lazy »).

### 5.3 Temps réel

| Canal | Événements | Utilisation |
|---|---|---|
| `private-user-{id}` | `notification`, `private-message` | Notifications et messages reçus |
| `private-conversation-{id}` | `new-message` | Conversation privée ouverte |
| `private-group-channel-{id}` | `new-message`, `message-pinned`, `message-unpinned` | Salons de groupe |

Chaque abonnement est autorisé par `/pusher/auth`. `PusherService` n'interrompt jamais une action si Pusher est indisponible.

### 5.4 Sécurité
- Rôles : `ROLE_USER`, `ROLE_MODERATOR`, `ROLE_ADMIN` (hérite de modérateur). Sanctions : un modérateur sanctionne les membres, un administrateur aussi les modérateurs ; personne ne sanctionne un administrateur ni soi-même (`MemberSanctionVoter`).
- Connexion refusée aux comptes suspendus et au compte « Membre supprimé » (`App\Security\UserChecker`).
- CSRF sur toutes les actions `POST`, formulaires et AJAX.
- Fichiers envoyés : type et taille vérifiés, images ré-encodées en WebP (métadonnées EXIF retirées), noms générés.
- Secrets uniquement dans `.env.local` (jamais versionné).

### 5.5 Modèle de données

| Domaine | Entités |
|---|---|
| Comptes | `User`, `ResetPasswordRequest`, `MemberDailyActivity` |
| Forum | `Category`, `Thread`, `Post`, `PostVote`, `ThreadSubscription`, `ForumImage` |
| Social | `Friendship`, `UserBlock`, `PrivateConversation`, `PrivateMessage` |
| Galerie | `GalleryAlbum`, `GalleryPhoto`, `GalleryPhotoLike`, `GalleryPhotoComment` |
| Groupes | `Group`, `GroupMember`, `GroupChannel`, `GroupMessage`, `GroupInvitation` |
| Tâches | `TodoNode` (arbre, personnel ou de groupe), `TodoAssignment` |
| Armées | `ArmyList`, `ArmyUnit`, `FactionUnit`, `FactionDetachement`, `FactionEnhancement`, `FactionSyncState` |
| Gamification | `Badge`, `UserBadge`, `ExperienceAward`, `GamificationActivity` |
| Notifications | `Notification` |
| Modération | `Report`, `Appeal` |

### 5.6 Arborescence du code

```
assets/
├── app.js                  Point d'entrée : Alpine, suivi de navigation, Stimulus, styles
├── controllers/            Contrôleurs Stimulus (un fichier = un data-controller)
├── lib/                    Modules partagés : realtime (Pusher), alpine, http, sounds, icon, skeleton, emoji…
└── styles/                 app.css (site : jetons @theme, composants), admin.css (EasyAdmin)
config/packages/            Configuration Symfony ; legal.yaml = informations légales
docs/                       Ce document, design system, roadmap, workflow/
migrations/                 Migrations Doctrine écrites à la main
public/                     index.php, favicon, images/ (logos, fond d'accueil), uploads/ (non versionné)
src/
├── Account/                Suppression de compte, purge des comptes inactifs
├── Army/                   Listes d'armée : composition, règles, format texte, formats, catégories, statistiques
├── BsData/                 Extraction des fichiers BSData
├── Command/                Commandes console (§7)
├── Controller/             Un contrôleur par rubrique ; Admin/ (EasyAdmin), Dev/ (styleguide)
├── Entity/, Repository/    Modèle de données
├── EventSubscriber/        Suspension, gamification, présence, en-têtes
├── Feed/                   Fil d'actualité
├── Form/                   Formulaires
├── Forum/                  Rendu Markdown
├── Gamification/           Catalogue, rareté et historique des badges et de l'XP
├── Group/                  Annuaire des groupes, invitations
├── Http/, Navigation/      Page précédente sûre, liens « Retour »
├── Image/                  Recadrage
├── Mailer/                 Envoi de tous les e-mails
├── Moderation/             Décisions, sanctions, réclamations, statistiques, conservation
├── Notification/           Sons de notification
├── Profile/                Images de profil
├── Security/               Voters, politique de mot de passe, limites anti-spam, vérification d'e-mail
├── Service/                Gamification, notifications, présence, Pusher, uploads, statistiques, BSData…
├── Statistics/             Graphiques du tableau de bord
├── Text/                   Mentions @pseudo
├── Todo/                   Assignations de tâches
├── Tour/                   Visites guidées
└── Twig/                   Fonctions et filtres Twig
templates/                  Un dossier par rubrique ; _partials/ et _macros/ partagés ; email/ ; base.html.twig
tests/                      Functional/ (parcours complets), Unit/
```

---

## 6. Modifier le contenu du site

Après toute modification : vider le cache (`php bin/console cache:clear`) ; après une modification de CSS ou d'une classe Tailwind dans un template : `php bin/console tailwind:build`. Les textes des pages sont directement dans les templates Twig indiqués.

### Informations et textes

| Je veux modifier… | Fichier | Remarque |
|---|---|---|
| Éditeur, SIRET, adresse, téléphone, e-mail de contact, hébergeur, sous-traitants, âge minimum, durées de conservation, date de mise à jour des pages légales | `config/packages/legal.yaml` | Utilisé par les pages légales, l'inscription, les e-mails et les purges |
| Textes des mentions légales, confidentialité, conditions | `templates/legal/notice.html.twig`, `privacy.html.twig`, `terms.html.twig` | Mettre à jour `updated_at` dans `legal.yaml` |
| Page d'accueil (visiteur et membre) | `templates/home/index.html.twig`, `_first_steps.html.twig` | Image de fond : `public/images/bgHome.webp` |
| En-tête, barre du bas, menu du compte, bouton « Publier », pied de page | `templates/_partials/shell/_header.html.twig`, `_bottom_bar.html.twig`, `_account_items.html.twig`, `_publish_items.html.twig`, `_footer.html.twig` | |
| Titre des onglets du navigateur, balises du `<head>`, favicon | `templates/base.html.twig` | Icônes : `public/images/SprueHub-*-1-min.ico`, `public/favicon.ico` |
| Logo de l'en-tête | `public/images/SprueHub-W-0.png` | |
| Présentation guidée (4 étapes) | `templates/onboarding/step1.html.twig` à `step4.html.twig` | Logique : `src/Service/OnboardingService.php` |
| Visites guidées du didacticiel | `src/Tour/TourCatalog.php` | Textes, étapes et éléments ciblés |
| E-mails | `templates/email/*.html.twig` (gabarit commun `layout.html.twig`) | Expéditeur : `MAILER_FROM_ADDRESS`, `MAILER_FROM_NAME` dans `.env.local` |
| Textes des notifications | `src/Service/NotificationRenderer.php` | Icône et couleur par type au même endroit |
| Messages d'erreur des formulaires | `src/Form/*.php` | Règle du mot de passe : `src/Security/PasswordPolicy.php` |

### Règles et valeurs

| Je veux modifier… | Fichier |
|---|---|
| Badges (nom, description, seuils, XP, icône) | `src/Gamification/BadgeCatalog.php`, puis `app:gamification:sync-badges` |
| XP quotidienne, bonus de série, XP d'une solution | `src/Service/GamificationService.php` (constantes en tête) |
| Filtres et taille du classement | `src/Service/LeaderboardService.php` |
| Icônes de l'interface et des badges | `templates/_partials/_icon.html.twig` |
| Sons de notification (liste) | `src/Notification/NotificationSound.php` ; synthèse : `assets/lib/sounds.js` |
| Catégories de référence du forum | `src/Command/SeedForumCategoriesCommand.php`, puis `app:forum:seed-categories` ; au quotidien, dans l'administration |
| Factions proposées (profil, listes d'armée) | `src/Service/BsDataFetcher.php` (`FACTION_GROUPS`) |
| Formats de listes officielles | `src/Army/BattleSize.php` |
| Règles des listes officielles | `src/Army/ArmyListRules.php` |
| Catégories et ordre des unités | `src/Army/UnitCategory.php` |
| Motifs de signalement | `src/Moderation/ReportReason.php` |
| Durées de suspension | `src/Moderation/SuspensionDuration.php` |
| Limites anti-spam | `config/packages/rate_limiter.yaml` |
| Nombre de photos, taille maximale | `src/Service/GalleryPhotoUploader.php` ; albums : `src/Entity/GalleryAlbum.php` |
| Taille des pages du fil | `src/Feed/FeedService.php` (`PER_PAGE`) |
| Délais de présence (en ligne, signal) | `src/Service/PresenceService.php` |
| Ancienneté d'un sujet « ancien » | `src/Entity/Thread.php` (`STALE_AFTER`) |

### Apparence

| Je veux modifier… | Fichier |
|---|---|
| Couleurs, polices, rayons, ombres (clair et sombre) | `assets/styles/app.css`, bloc `@theme` et variables `light-dark()` |
| Composants (boutons, cartes, champs, onglets…) | `assets/styles/app.css`, `@layer components` ; documentés dans `docs/design-system.md` |
| Macros d'interface (icône, avatar, badge…) | `templates/_macros/ui.html.twig` |
| Formulaires (rendu commun) | `templates/form/theme.html.twig` |
| Administration | `assets/styles/admin.css`, `src/Controller/Admin/` |

---

## 7. Commandes console et tâches planifiées

| Commande | Rôle | Planification |
|---|---|---|
| `army:sync-bsdata [--force]` | Synchronise factions, unités, détachements et améliorations depuis BSData | Chaque semaine |
| `army:audit-bsdata` | Contrôle des données extraites par faction | À la demande |
| `army:inspect-unit` | Détail d'une unité (`--tree` : arbre brut BSData) | À la demande |
| `app:forum:seed-categories [--dry-run]` | Crée les catégories de référence manquantes, sans toucher à l'existant | À la demande |
| `app:gamification:sync-badges` | Aligne la table des badges sur `BadgeCatalog` | Après modification des badges |
| `app:gamification:recompute [--resum]` | Réévalue badges et XP ; `--resum` recalcule l'XP depuis le grand livre | À la demande |
| `app:notifications:purge` | Supprime les notifications lues depuis 90 jours | Chaque nuit |
| `app:accounts:purge-inactive` | Prévient puis supprime les comptes inactifs | Chaque nuit |
| `app:moderation:purge` | Supprime les signalements et réclamations dont la conservation est écoulée | Chaque nuit |

La mise en place des tâches planifiées sur alwaysdata est décrite dans [workflow/03-mise-en-production.md](workflow/03-mise-en-production.md).

---

## 8. Environnements

| | Développement | Test | Production |
|---|---|---|---|
| Machine | PC Windows, XAMPP | PC (PHPUnit) | alwaysdata |
| `APP_ENV` | `dev` | `test` | `prod` |
| Base | `highlightforge` | `highlightforge_test` | `hforge_prod` |
| Secrets | `.env.local` | `.env.test.local` | `.env.local` du serveur |

- Le `.env` du dépôt ne contient aucun secret (valeurs de développement, e-mails désactivés).
- **E-mails** : `MAILER_DSN=mailjet+api://CLE_API:CLE_SECRETE@default` ; `MAILER_FROM_ADDRESS` doit être une adresse validée dans Mailjet ; `null://null` n'envoie rien.
- **Temps réel** : `PUSHER_APP_ID`, `PUSHER_KEY`, `PUSHER_SECRET`, `PUSHER_CLUSTER`.
- Récupération, envoi et mise en production : [workflow/](workflow/README.md).

---

## 9. Conventions de développement

- **Code et commentaires en anglais**, textes de l'interface en français. Les commentaires décrivent le fonctionnement, jamais l'historique des modifications.
- **Aucun JavaScript dans les templates** : pas de `<script>` en ligne ni d'attribut `on…=`.
- **Événements plutôt que minuteries** : pas d'attente arbitraire ; un minuteur n'existe que pour un besoin réellement temporel (signal de présence, anti-rebond de la saisie).
- **Logique critique centralisée** : un service par règle, réutilisé partout (voir 5.2) ; on refactorise plutôt que de dupliquer.
- **Migrations écrites à la main**, relues et décrites en tête de fichier.
- **CSRF sur chaque POST** et droits vérifiés par les Voters.
- **Interface** : classes du design system et macros `ui.*` (voir `docs/design-system.md` et `/_styleguide` en développement).
- **Vocabulaire** : Salon (channel), Propriétaire (owner), Tâches (todo), Sujet (thread), Réponse (post), Liste d'armée.
- **Commits** thématiques en français, préfixés `AJOUT -`, `MODIF -` ou `SÉCURITÉ -`.
- **Avant chaque commit** : `lint:twig`, `lint:container`, `doctrine:schema:validate`, `phpunit` (détails dans [workflow/02-envoyer-vers-git.md](workflow/02-envoyer-vers-git.md)).
