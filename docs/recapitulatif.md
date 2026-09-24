# SprueHub — Récapitulatif complet du site

> Réseau social francophone dédié au hobby de la figurine : Warhammer, autres wargames, peinture et maquettes.
> Ce document décrit l'état du site : fonctionnalités, règles de fonctionnement, arborescence des pages, architecture technique et exploitation.
> Voir aussi : [design-system.md](design-system.md) (interface) et [roadmap.md](roadmap.md) (évolutions prévues).

---

## Sommaire

1. [Vue d'ensemble](#1-vue-densemble)
2. [Arborescence du site](#2-arborescence-du-site)
3. [Fonctionnalités](#3-fonctionnalités)
4. [Administration (EasyAdmin)](#4-administration-easyadmin)
5. [Architecture technique](#5-architecture-technique)
6. [Modèle de données](#6-modèle-de-données)
7. [Sécurité et droits](#7-sécurité-et-droits)
8. [Temps réel](#8-temps-réel)
9. [Commandes console](#9-commandes-console)
10. [Arborescence du code](#10-arborescence-du-code)
11. [Déploiement et exploitation](#11-déploiement-et-exploitation)
12. [Conventions de développement](#12-conventions-de-développement)

---

## 1. Vue d'ensemble

| Élément | Valeur |
|---|---|
| Nom | **SprueHub** (dépôt : HighlightForge) |
| Public | Joueurs de wargame, peintres de figurines, maquettistes |
| Langue | Français, avec tutoiement dans les parcours d'accueil |
| Production | alwaysdata (offre gratuite), base MariaDB `hforge_prod` |
| Développement | XAMPP sous Windows (PHP 8.2+, MariaDB) |

**Les 9 grands blocs fonctionnels :**
1. Comptes et profils (avec galerie photo) ;
2. Forum à catégories hiérarchiques ;
3. Amis et messagerie privée en temps réel ;
4. Groupes (salons de discussion, messages épinglés, liste de tâches partagée) ;
5. Listes d'armée Warhammer 40k (données BSData) ;
6. Gamification (XP, niveaux, badges, titres, classement) ;
7. Notifications en temps réel ;
8. Présentation guidée à l'inscription (onboarding) ;
9. Modération (signalements, blocage entre membres, sanctions).

---

## 2. Arborescence du site

### Pages publiques
```
/                                   Accueil (catégories racines du forum, vitrine de photos, preuve sociale)
/login                              Connexion
/register                           Inscription
/mot-de-passe-oublie                Mot de passe oublié (e-mail ou pseudo)
├── /mot-de-passe-oublie/verifier   Confirmation d'envoi (identique que le compte existe ou non)
└── /mot-de-passe-oublie/reinitialiser/{token}   Nouveau mot de passe (lien à usage unique, 1 heure)
/verify/email?id=…                  Confirmation de l'adresse e-mail (lien signé reçu par e-mail)
/mentions-legales                   Mentions légales
/conditions-utilisation             Conditions d'utilisation
/forum/                             Forum : catégories racines
├── /forum/category/{slug}          Catégorie : sous-catégories et sujets
│   └── /forum/category/{slug}/new-thread   Nouveau sujet (si la catégorie l'autorise)
└── /forum/thread/{slug}            Sujet et réponses (Markdown, citations, solution, suivi)
/profil/{username}                  Profil public (onglets Galerie, Armées, Badges, Activité)
└── /profil/{username}/photo/{id}   Photo : description, likes, commentaires
/classement                         Classement général (filtres)
/groups/                            Liste des groupes
└── /groups/{slug}                  Groupe (selon visibilité et adhésion)
```

### Pages membres (connexion requise)
```
/bienvenue                          Présentation guidée (4 étapes)
└── /bienvenue/etape/{1..4}
/profil/settings/edit               Modifier mon profil (avatar, bio, faction favorite, visibilité de l'activité)
/profil/settings/change-password    Changer mon mot de passe
/friendship/list                    Mes amis, demandes reçues ou envoyées, bloqués
/messages/                          Messagerie privée
└── /messages/{username}            Conversation avec un ami
/notifications                      Toutes mes notifications
/group-invitation/list              Mes invitations de groupe
/groups/new                         Créer un groupe
/groups/{slug}/edit                 Paramètres du groupe (admin ou propriétaire)
/groups/{slug}/todo/                Tâches du groupe
/todo/                              Mes tâches personnelles
/signaler/{type}/{id}               Signaler un contenu (sujet, reponse, photo, commentaire, message, profil)
/army/                              Mes listes d'armée
├── /army/new                       Créer une liste
├── /army/{id}                      Voir une liste (par catégorie d'unités)
└── /army/{id}/edit                 Modifier une liste
```

### Administration
```
/admin                              Tableau de bord (statistiques)
├── /admin/report                   File de modération (signalements)
├── /admin/moderation/report/{id}   Décision sur un signalement
├── /admin/moderation/member/{id}   Sanctions d'un membre
├── /admin/user                     Utilisateurs
├── /admin/friendship               Amitiés
├── /admin/badge                    Badges (lecture seule, catalogue géré dans le code)
├── /admin/category                 Catégories du forum (parent/enfant, création de sujets autorisée ou non)
├── /admin/thread                   Sujets
├── /admin/post                     Réponses
└── /admin/todo-node                Tâches
```

### Points d'accès techniques (AJAX, sans page)
| Route | Rôle |
|---|---|
| `POST /heartbeat` | Signal de présence (utilisateurs en ligne) |
| `POST /verify/email/resend` | Renvoyer le lien de confirmation de l'e-mail (bandeau) |
| `POST /friendship/block/{username}`, `POST /friendship/unblock/{username}` | Bloquer ou débloquer un membre |
| `POST /pusher/auth` | Autorisation d'accès aux canaux privés Pusher |
| `GET /search/api` | Recherche globale (barre de recherche de l'en-tête) |
| `GET /fil?fil=…&apres=…` | Pages suivantes du fil d'actualité (« Voir plus », Turbo Frame) |
| `POST /forum/editor/preview`, `POST /forum/editor/image`, `GET /forum/editor/mentions` | Éditeur Markdown : aperçu, envoi d'image, suggestions de mention |
| `POST /forum/thread/{slug}/subscription`, `POST /forum/thread/{slug}/solution/{postId}` | Suivre un sujet, choisir la solution |
| `GET /notifications/recent`, `POST /notifications/{id}/read`, `POST /notifications/read-all` | Menu des notifications |
| `GET /messages/ajax/conversations`, `GET /messages/ajax/messages/{username}`, `POST /messages/ajax/send/{username}`, `POST /messages/ajax/read/{id}`, `GET /messages/ajax/notification-context` | Messagerie flottante |
| `GET /army/units/{faction}`, `GET /army/detachments/{faction}` | Données du constructeur de listes |
| `POST /gamification/activity/{key}` | Enregistrement d'une activité (badges d'exploration) |
| `GET /profil/{username}/xp-history` | Historique d'XP (bandeau déroulant) |
| `/_styleguide` | Catalogue des composants d'interface (**développement uniquement**) |

### Navigation
- **En-tête (ordinateur) :**
  - logo et liens Accueil, Forum, Groupes, Classement ;
  - recherche ;
  - bouton « Publier » (nouveau sujet, photo, liste d'armée, groupe) ;
  - messages, notifications ;
  - menu du compte.
- **Barre du bas (mobile) :** les rubriques principales, toujours accessibles au pouce.
- **Pied de page :**
  - liens légaux ;
  - bouton **« Relancer la présentation »** qui rejoue l'onboarding.
- **Messagerie flottante :** une fenêtre de discussion ouverte depuis n'importe quelle page.
- **Toasts :** les messages flash et les notifications en direct.

---

## 3. Fonctionnalités

### 3.1 Comptes et authentification
- **Inscription :**
  - pseudo, e-mail, mot de passe d'**au moins 12 caractères** (`RegistrationFormType::PASSWORD_MIN_LENGTH`) ;
  - acceptation des conditions ;
  - bouton pour afficher ou masquer le mot de passe.
- **Connexion :**
  - formulaire protégé par CSRF, option « Se souvenir de moi » ;
  - la connexion quotidienne rapporte de l'XP (voir 3.8).
- **Déconnexion :** marque l'utilisateur hors ligne immédiatement (`PresenceLogoutSubscriber`).
- **Changement de mot de passe :** dans les paramètres du profil.
- **Mot de passe oublié :** demande par e-mail ou pseudo, lien à usage unique valable 1 heure (`symfonycasts/reset-password-bundle`, jetons hachés en base). La réponse est la même que le compte existe ou non ; une seule demande par compte toutes les 10 minutes. Suivre le lien confirme aussi l'adresse e-mail.
- **Vérification de l'e-mail :** un lien signé (valable 1 heure) est envoyé à l'inscription. Tant que l'adresse n'est pas confirmée, un bandeau s'affiche en haut de chaque page avec un bouton « Renvoyer le lien ». Le lien fonctionne même déconnecté. Le compte reste utilisable sans confirmation.
- **Limite de connexion :** 5 échecs par minute pour un même identifiant et une même adresse IP (`login_throttling`).
- **Suspension :** un membre suspendu ne peut plus se connecter (message avec la date de fin et le motif) et sa session en cours est fermée dès la page suivante (voir 3.14).

### E-mails
- **Envoi :** Mailjet (offre gratuite, 200 e-mails par jour) via `symfony/mailjet-mailer`. Tous les envois passent par `App\Mailer\TransactionalMailer` ; un échec est journalisé sans bloquer l'action.
- **Envoi synchrone :** les e-mails partent pendant la requête (pas de file Messenger), car l'hébergement gratuit n'a pas de worker permanent.
- **Modèles :** `templates/email/` (gabarit commun `layout.html.twig`, bouton `_button.html.twig`) : confirmation d'adresse, mot de passe oublié, message de la modération, suspension.

### 3.2 Présentation guidée (onboarding)
L'onboarding se lance après l'inscription (`/bienvenue`). Il compte **4 étapes**, avec un indicateur de progression :
1. **Ta faction principale** ;
2. **Ton profil** : avatar et bio ;
3. **Rejoins des groupes** : jusqu'à 6 groupes suggérés, rejoignables en un clic ;
4. **Prêt pour la bataille !**

Fonctionnement :
- l'avancement est mémorisé (`onboardingStep`, `onboardingCompletedAt`) ;
- chaque étape peut être passée ;
- le bouton du pied de page la relance à tout moment.

### 3.3 Profil
- **En-tête :**
  - avatar, pseudo, **titre** (un badge choisi, affiché à côté du pseudo) ;
  - niveau et barre d'XP, série de connexion ;
  - faction favorite et bio.
- **Onglets** (contrôleur Stimulus `tabs`) :
  - **Galerie** : jusqu'à **10 photos** par membre, voir 3.4 ;
  - **Armées** : les listes d'armée publiques du membre ;
  - **Badges** :
    - explication du gain quotidien ;
    - badges regroupés par catégorie, avec progression et rareté (% des membres qui les possèdent) ;
    - les badges non obtenus sont affichés en gris ;
    - un **historique d'XP** en bandeau déroulant, replié par défaut, séparé de l'activité du compte ;
  - **Activité** : les 10 dernières actions. Le membre peut masquer cet onglet (`showActivity`).
- **Paramètres :** avatar (redimensionné et optimisé par `AvatarUploader` et `ImageOptimizerService`), bio, faction favorite, visibilité de l'activité.
- **Choix du titre :** parmi les badges obtenus (`POST /profil/settings/title`).

### 3.4 Galerie photo
- **Envoi :** depuis son profil, 10 photos maximum. Les images sont converties et optimisées (`GalleryPhotoUploader`).
- **Description :** facultative, 500 caractères maximum, modifiable par le propriétaire.
- **Visibilité :** chaque photo peut être masquée ou affichée sans être supprimée.
- **Page photo** (`/profil/{username}/photo/{id}`) :
  - grande image et description ;
  - **likes** (une fois par membre, bouton sans rechargement) ;
  - **commentaires** : ajout, et suppression par l'auteur ou le propriétaire de la photo.
- **Notifications :** le propriétaire est notifié des likes (regroupés : « X et 3 autres ont aimé… ») et des commentaires.
- **Vitrine :** 12 photos sont mises en avant sur la page d'accueil.

### Fil d'actualité (accueil des membres)
- **Contenu** (`App\Feed\FeedService`) : activité **publique** des autres membres — photos visibles, nouveaux sujets, listes d'armée publiques, badges obtenus (regroupés par membre et par jour).
- **Filtres** : Tout (amis + membres de mes groupes), Amis, Groupes, Communauté (tout le monde). Par défaut « Tout », ou « Communauté » tant que le membre n'a ni ami ni groupe.
- **Réactions** : « J'aime » sur les photos directement depuis le fil, nombre de commentaires et de réponses, bouton « Répondre » sur les sujets.
- **Pagination** : 12 éléments, puis « Voir plus d'activité » (Turbo Frame, curseur de date sans doublon ni oubli). Le changement de filtre ne recharge que le fil.
- La vitrine de photos passe à une rangée pour les membres ; « Dernières discussions » passe dans la colonne de droite.

### 3.5 Forum
- **Catégories hiérarchiques** (parent → enfant → petit-enfant, par exemple Warhammer → 40k → Space Marines) :
  - gérées dans EasyAdmin : parent, position, description ;
  - l'option **« Création de sujets autorisée »** (`allowThreads`) : si elle est désactivée, la catégorie ne sert qu'à regrouper et affiche ses sous-catégories sous forme de liste ;
  - **88 catégories** couvrent Warhammer (40k, Age of Sigmar, Horus Heresy, Old World, jeux spécialistes), les autres wargames, la peinture, le modélisme et maquettes, et la vie de la communauté. La commande `app:forum:seed-categories` les crée.
- **Sujets :** titre et premier message ; création uniquement dans les catégories qui l'autorisent.
- **Réponses :**
  - deux types de vote, **« Positif »** et **« Aide »** (une fois par membre et par réponse) ;
  - ces votes alimentent les badges Populaire et Dévoué.
- **Éditeur Markdown** (sujets et réponses, contrôleur Stimulus `markdown-editor`) :
  - barre d'outils (gras, italique, barré, citation, liste, lien, code, mention, image), raccourcis Ctrl+B / Ctrl+I / Ctrl+K, Ctrl+Entrée pour envoyer ;
  - onglet **Aperçu** rendu par le serveur (même rendu que le message publié) ;
  - **images** : bouton, coller ou glisser-déposer ; ré-encodées en WebP 1600 px dans `public/uploads/forum/`, 30 par jour et par membre ;
  - **brouillon** enregistré dans le navigateur, effacé à l'envoi.
- **Rendu** (`App\Forum\ForumMarkdown`, filtre Twig `forum_markdown`) : HTML saisi échappé, liens externes en `nofollow` dans un nouvel onglet, seules les images envoyées sur le site sont affichées (une image externe devient un lien : pas de pistage des lecteurs). Les sauts de ligne sont conservés : les anciens messages s'affichent comme avant.
- **Mentions `@pseudo`** : suggestions pendant la saisie, lien vers le profil, notification de la personne mentionnée (10 au plus par message).
- **Citer** : le bouton « Citer » d'un message insère la citation (sans les citations imbriquées) dans la réponse.
- **Abonnements** (`ThreadSubscription`) : l'auteur du sujet et chaque membre qui répond suivent automatiquement le sujet ; bouton « Suivre / Suivi » pour les autres. Chaque nouvelle réponse notifie les abonnés (un membre mentionné reçoit la mention plutôt que la réponse).
- **Sujet résolu** : l'auteur du sujet (ou un administrateur) choisit la réponse « solution » (`ThreadVoter::SOLVE`). Le sujet affiche « Résolu » (liste du forum, fil d'actualité, en-tête avec lien vers la solution) ; l'auteur de la réponse gagne **+50 XP** (une fois par sujet) et reçoit une notification.
- **Accueil :** affiche les catégories racines. Le compteur de membres n'apparaît qu'à partir de 100 membres (preuve sociale).

### 3.6 Amis et messagerie privée
- **Amitiés :**
  - envoi d'une demande depuis le profil ;
  - accepter, refuser, retirer un ami, bloquer ou débloquer ;
  - notifications pour une demande reçue et une demande acceptée ;
  - page `/friendship/list` avec les onglets amis, demandes et bloqués.
- **Blocage** (`UserBlock`, `App\Service\MemberBlocker`) :
  - depuis le menu « … » du profil ; refuser une demande d'ami bloque aussi le demandeur ;
  - bloquer supprime l'amitié ou la demande en cours, donc la messagerie privée entre les deux membres ;
  - dans les deux sens : ni demande d'ami, ni message privé, ni invitation de groupe ;
  - seul le membre qui a bloqué peut débloquer (profil ou onglet « Bloqués »).
- **Messages privés :**
  - **réservés aux amis** ;
  - pages `/messages` et `/messages/{username}`, plus une **messagerie flottante** ouvrable partout ;
  - envoi et réception **en temps réel** (Pusher, canaux privés), accusés de lecture, compteur de non-lus ;
  - protégés par CSRF (identifiant `private_message`).

### 3.7 Groupes
- **Création :** nom, description, public ou privé. Le créateur en devient **propriétaire**.
- **Rôles :** `member` (1), `admin` (2), `owner` (3). Les droits s'appliquent au rôle choisi et aux rôles supérieurs.
- **Adhésion :**
  - on rejoint librement un groupe public ;
  - un groupe privé se rejoint sur **invitation** (invitation envoyée depuis le profil d'un membre et page `/group-invitation/list`) ;
  - on peut quitter un groupe ;
  - les admins et le propriétaire peuvent exclure un membre.
- **Salons (channels) :**
  - plusieurs salons par groupe, chacun avec des droits de lecture et d'écriture (`canRead` / `canWrite`) par rôle ;
  - messages **en temps réel** ;
  - suppression d'un salon par les admins.
- **Messages épinglés :**
  - épingler ou désépingler un message, avec un bandeau des épinglés en haut du salon ;
  - l'épinglage est diffusé en direct ;
  - réglage **« Qui peut épingler »** (`pinRole`).
- **Tâches du groupe** (`/groups/{slug}/todo/`) :
  - arborescence **liste → catégorie → tâche**, avec une progression par tâche (augmenter, diminuer, valider) ;
  - réglage **« Qui peut écrire »** (`todoWriteRole`, par défaut admin) : ces rédacteurs ont tous les droits (créer, renommer, supprimer, assigner) ;
  - réglage **« Qui peut tout voir »** (`todoViewRole`) : ces lecteurs voient toute la liste sans pouvoir la modifier ;
  - un membre **assigné à une catégorie** fait avancer toutes les tâches de cette catégorie et peut y créer des tâches ;
  - un membre **assigné à une tâche** ne fait avancer que cette tâche et ne peut pas en créer ;
  - les autres membres ne voient que ce qui leur est assigné ;
  - les règles sont centralisées dans `GroupVoter` et `TodoNodeVoter`.
- **Paramètres** (`/groups/{slug}/edit`) : informations, salons et leurs droits, réglages des tâches et des épingles, membres, suppression du groupe (propriétaire uniquement).

### 3.8 Tâches personnelles
- **Page :** `/todo/` suit la même structure que les tâches de groupe (liste, catégorie, tâche, progression), mais pour soi seul.

### 3.9 Listes d'armée (Warhammer 40k)
- **Données :**
  - synchronisées depuis le dépôt GitHub **BSData/wh40k-11e** avec `army:sync-bsdata` ;
  - **36 factions et 3171 unités**, avec points, figurines, **armes** (profils), **aptitudes** et mots-clés ;
  - seules les unités jouables en règles actuelles et les unités **Legends** sont retenues. Les unités spéciales (par exemple **Crucible**) sont exclues ;
  - les détachements sont proposés par faction.
- **Création et modification :**
  - nom, faction, détachement, description, publique ou privée ;
  - ajout d'unités via un sélecteur **classé par catégorie** ;
  - total des points calculé automatiquement.
- **Affichage** (`/army/{id}`) : les unités sont **rangées par catégorie**, dans le même ordre qu'à la création (`App\Army\UnitCategory`) :
  - Héros épiques, Personnages ;
  - Troupes de ligne, Infanterie, Unités montées ;
  - Bêtes, Nuées, Monstres ;
  - Véhicules, Aéronefs, Transports assignés ;
  - Fortifications, Autres.
- **Visibilité :** une liste privée n'est visible que par son auteur. Les listes publiques apparaissent dans l'onglet Armées du profil.

### 3.10 Gamification
- **XP et niveaux :**
  - l'XP nécessaire pour atteindre un niveau N vaut `100 × (N−1)^1,5` ;
  - **niveau maximum 50** ;
  - toute l'XP gagnée est inscrite dans un **grand livre** (`experience_award`) avec une clé unique, ce qui empêche de gagner deux fois la même récompense.
- **Gains :**
  - **+20 XP** par jour de connexion ;
  - **+100 XP** de bonus tous les **7 jours** de série ;
  - l'XP de chaque badge obtenu.
- **Protection de série :** la série est conservée tant que l'absence ne dépasse pas **3 jours consécutifs**.
- **17 badges**, en 4 catégories :

| Catégorie | Badges | Condition |
|---|---|---|
| Forum | Pionnier I / II / III | 1 / 50 / 100 sujets |
| Forum | Populaire I / II / III | 1 / 50 / 100 votes « Positif » reçus |
| Forum | Dévoué I / II / III | 1 / 50 / 100 votes « Aide » reçus |
| Forum | Maître forgeron | 10 sujets ayant chacun 10 réponses d'autres membres |
| Exploration | Curieux | Visiter les 9 rubriques du site |
| Exploration | Juriste | Lire les mentions légales et les conditions d'utilisation |
| Exploration | Archéologue | Ouvrir un sujet de plus d'un an |
| Niveau | Héroïque / Légendaire / Immortel | Niveau 10 / 25 / 50 |
| Spécial | Avant-garde | Faire partie des 1000 premiers inscrits |

- **Paliers de couleur** selon l'XP du badge : bronze (jusqu'à 100), argent (101 à 200), or (201 à 300), premium (plus de 300).
- **Rareté :** le pourcentage des membres qui possèdent chaque badge.
- **Titres :** un badge obtenu peut être affiché à côté du pseudo.
- **Classement** (`/classement`) :
  - 25 membres par page, 100 membres classés au maximum ;
  - filtres : Plus haut niveau, XP de la semaine, XP du mois, Plus de badges, Meilleure série, Plus de sujets, Plus de votes reçus ;
  - la semaine et le mois sont calculés à l'heure de Paris.
- **Notifications :** badge obtenu, niveau atteint.

### 3.11 Notifications
- **13 types :**
  - amis : demande d'ami, ami accepté ;
  - groupes : invitation de groupe, invitation acceptée, message de groupe ;
  - forum : réponse sur le forum, mention `@pseudo`, réponse choisie comme solution ;
  - galerie : like de photo, commentaire de photo ;
  - gamification : badge obtenu, niveau atteint ;
  - modération : message de la modération (contenu masqué ou supprimé, avertissement).
- **Diffusion en temps réel** sur le canal privé de l'utilisateur :
  - le compteur se met à jour ;
  - un toast s'affiche ;
  - le menu déroulant se charge sans rechargement de page.
- **Regroupement :** les likes d'une même photo sont regroupés en une seule notification, avec la liste des auteurs.
- **Lecture :** au clic, ou « tout marquer comme lu ».
- **Purge :** les notifications lues de plus de 90 jours sont supprimées par `app:notifications:purge`.

- **Son de notification** : joué à chaque nouvelle notification ou message privé (hors conversation ouverte). 6 sons originaux **synthétisés dans le navigateur** (`assets/lib/sounds.js`, aucun fichier audio) : Auspex (défaut), Enclume, Jet de dés, Cor de guerre, Cristal psychique, Servo-crâne, ou aucun son. Choix et écoute dans les paramètres du profil (`User::notificationSound`). Un seul son même avec plusieurs onglets ouverts (Web Locks), une seule fois par rafale. Le navigateur n'autorise le son qu'après une première interaction avec la page.

### 3.12 Présence (utilisateurs en ligne)
- **Signal de présence :** envoyé toutes les **30 s** par le contrôleur Stimulus `heartbeat`, et suspendu quand l'onglet est caché.
- **Écriture en base :** au plus une fois toutes les 25 s.
- **Statut en ligne :** un membre est considéré en ligne s'il a donné signe de vie depuis moins de **90 s**.
- **Affichage :** dans la carte « Utilisateurs actifs » du tableau de bord admin, avec un **rond vert**.

### 3.13 Recherche
- **Barre de recherche** dans l'en-tête, résultats instantanés (`/search/api`).

### 3.14 Modération
- **Signaler :** bouton « Signaler » (drapeau) sur les sujets, réponses, photos, commentaires de photo, messages privés reçus et profils (menu « … »). Formulaire `/signaler/{type}/{id}` : motif (spam, harcèlement, propos haineux, contenu choquant, arnaque, autre) et précisions facultatives. On ne signale ni son propre contenu ni un contenu qu'on ne peut pas voir ; un seul signalement en attente par membre et par contenu.
- **Signalement** (`Report`) : l'extrait du contenu, son auteur et son lien sont enregistrés au moment du signalement, pour garder un historique lisible même après modification ou suppression.
- **Décisions** (`App\Moderation\ModerationService`, point d'entrée unique) :
  - **Masquer** (réponse, sujet, photo) : les membres voient « masqué par la modération », l'équipe de modération (modérateurs et administrateurs) voit toujours le contenu. Un sujet masqué a son message d'ouverture masqué et est fermé. Une photo masquée n'est plus visible que par son propriétaire, qui ne peut pas la réafficher. Réversible (« Rétablir le contenu ») ;
  - **Supprimer** (tous les contenus sauf un profil) : définitif ; supprimer le message d'ouverture supprime tout le sujet ;
  - **Avertir** l'auteur avec un message ;
  - **Suspendre** l'auteur : 24 heures, 3 jours, 7 jours, 1 mois ou définitivement, avec un motif ;
  - **Classer sans suite**.
- Une décision clôt tous les signalements en attente du même contenu. L'auteur est prévenu par une notification et un e-mail (masquage, suppression, avertissement, suspension).
- **Sanctions :** depuis la fiche d'un membre (`/admin/moderation/member/{id}`, action « Sanctions » de la liste des utilisateurs) : suspendre ou lever la sanction. Une suspension temporaire expirée ne compte plus, sans tâche planifiée (`User::isSuspended()`).

### 3.15 Limites anti-spam
Chaque envoi passe par `App\Security\SubmissionThrottle` (`config/packages/rate_limiter.yaml`, fenêtre glissante) :

| Action | Limite | Clé |
|---|---|---|
| Inscription | 3 par heure | adresse IP |
| Demande de mot de passe oublié | 5 par heure | adresse IP |
| Renvoi du lien de confirmation | 3 par heure | membre |
| Message privé | 20 par minute | membre |
| Nouveau sujet | 5 par heure | membre |
| Réponse au forum | 6 par minute | membre |
| Commentaire de photo | 6 par minute | membre |
| Signalement | 10 par heure | membre |

Au-delà, le formulaire affiche un message d'erreur et conserve le texte saisi.

---

## 4. Administration (EasyAdmin)

**Accès :** `ROLE_ADMIN` pour tout le back-office ; `ROLE_MODERATOR` voit uniquement la section Modération (voir 7).

**Tableau de bord** (`/admin`, `ROLE_ADMIN`) :
- **Compteurs :** signalements en attente, utilisateurs, sujets, réponses, catégories, tâches, amitiés.
- **Statistiques d'activité** (`AdminStatsService`, `RetentionService`), sur **24 h / 7 j / 30 j** :
  - rétention des utilisateurs ;
  - utilisateurs actifs, avec les **connectés en ce moment** ;
  - nouvelles inscriptions ;
  - réponses publiées, messages envoyés ;
  - groupes (total et nouveaux) ;
  - listes d'armée créées.
- **Accès rapides :** nouvelle catégorie, badges, voir le forum, voir les groupes, retour au site.

**Menu :**
- Modération : Signalements (badge rouge du nombre en attente) ;
- Site : Utilisateurs (colonne « Sanction », action « Sanctions »), Amitiés, Badges ;
- Forum : Catégories, Sujets, Réponses ;
- Organisation : Tâches.

**Particularités :**
- Catégories : choix du parent, affichage du chemin complet (« Warhammer › 40k › Space Marines »), option de création de sujets.
- Sujets : on ne peut choisir qu'une catégorie qui autorise les sujets.
- Badges : **lecture seule**. La source de vérité est `App\Gamification\BadgeCatalog`, synchronisé par `app:gamification:sync-badges`.
- Signalements : **lecture seule**, filtres par statut, type et motif ; l'action « Traiter » ouvre la page de décision (voir 3.14).

---

## 5. Architecture technique

| Couche | Technologie |
|---|---|
| Framework | Symfony 7.4, PHP ≥ 8.2 |
| Base de données | MariaDB, Doctrine ORM 3, migrations écrites à la main |
| Gabarits | Twig, thème de formulaire global `templates/form/theme.html.twig` |
| Styles | Tailwind CSS v4 (`symfonycasts/tailwind-bundle`), design system documenté dans `docs/design-system.md` |
| JavaScript | AssetMapper et importmap (sans Node), Stimulus, Turbo Drive, Alpine (déclaratif uniquement) |
| Temps réel | Pusher (canaux privés, `pusher/pusher-php-server`) |
| Administration | EasyAdmin 5 |
| Pagination | KnpPaginator |
| Images | `ImageOptimizerService` (redimensionnement et compression) |
| E-mails | Symfony Mailer + Mailjet (`symfony/mailjet-mailer`), envoi synchrone |
| Anti-abus | `symfony/rate-limiter` (connexion et envois), `symfonycasts/reset-password-bundle`, `symfonycasts/verify-email-bundle` |
| Tests | PHPUnit 11, tests fonctionnels `WebTestCase` (`tests/Functional/`) |

**Contrôleurs Stimulus** (`assets/controllers/`) :

| Contrôleur | Rôle |
|---|---|
| `army_form` | Constructeur de listes d'armée |
| `avatar` | Aperçu de l'avatar |
| `chat` | Salons de groupe |
| `messenger` | Messagerie flottante |
| `notifications` | Menu des notifications |
| `todo` | Tâches |
| `pinned` | Messages épinglés |
| `like` | Likes des photos |
| `profile_gallery` | Galerie du profil |
| `tabs` | Onglets |
| `heartbeat` | Signal de présence |
| `confirm` | Confirmation des actions destructrices |
| `counter` | Compteur de caractères |
| `field_rules` | Règles des champs de formulaire |
| `flash` | Messages flash |
| `password_visibility` | Afficher ou masquer le mot de passe |
| `csrf_protection` | Jetons CSRF |

`assets/lib/realtime.js` centralise la connexion Pusher. Il lit sa configuration dans les balises `<meta name="hf-…">` de `base.html.twig`.

---

## 6. Modèle de données

| Domaine | Entités |
|---|---|
| Comptes | `User` (XP, série, onboarding, titre, dernière activité, son de notification, suspension), `ResetPasswordRequest` (liens de mot de passe oublié) |
| Forum | `Category` (arbre `parent`/`children`, `allowThreads`), `Thread` (`solutionPost`), `Post` (Markdown, masquage par la modération), `PostVote` (positive / helpful), `ThreadSubscription` (suivi des sujets), `ForumImage` (images des messages) |
| Social | `Friendship` (en attente, acceptée), `UserBlock` (blocage entre membres), `PrivateConversation`, `PrivateMessage` |
| Galerie | `GalleryPhoto` (description, visibilité, masquage par la modération), `GalleryPhotoLike`, `GalleryPhotoComment` |
| Groupes | `Group` (public, `todoWriteRole`, `todoViewRole`, `pinRole`), `GroupMember` (rôle), `GroupChannel` (`canRead`/`canWrite`), `GroupMessage` (épinglé), `GroupInvitation` |
| Tâches | `TodoNode` (arbre liste, catégorie, tâche ; progression ; assignation ; personnel ou de groupe) |
| Armées | `ArmyList`, `ArmyUnit`, `FactionUnit` (unités BSData), `FactionDetachement`, `FactionSyncState` (suivi de synchronisation par faction) |
| Gamification | `Badge`, `UserBadge`, `ExperienceAward` (grand livre d'XP), `GamificationActivity` (visites pour les badges d'exploration) |
| Notifications | `Notification` (type, données, auteurs regroupés, lue) |
| Modération | `Report` (signalement : cible polymorphe type + id, motif, extrait, statut, décision) |

Le schéma de la base correspond exactement aux entités (plus aucune table orpheline).

---

## 7. Sécurité et droits

- **Rôles globaux :**
  - `ROLE_USER` : tout membre ;
  - `ROLE_MODERATOR` : accès à la modération seulement (file des signalements, décisions, sanctions des membres). `/admin` le redirige vers la file ; les autres CRUD lui répondent 403 ;
  - `ROLE_ADMIN` : tout le back-office, et hérite de `ROLE_MODERATOR` (`role_hierarchy`).
- **Sanctions** (`MemberSanctionVoter`) : un modérateur sanctionne les membres ordinaires, un administrateur sanctionne aussi les modérateurs ; personne ne sanctionne un administrateur ni soi-même.
- **Connexion :** `App\Security\UserChecker` refuse les comptes suspendus (formulaire et cookie « Se souvenir de moi ») ; `SuspendedUserSubscriber` ferme la session d'un membre suspendu pendant qu'il est connecté ; 5 échecs de connexion par minute au maximum.
- **Anti-spam :** limites d'envoi centralisées (voir 3.15).
- **Voters** (`src/Security/Voter/`) : ils centralisent les règles d'accès, au lieu de vérifications répétées dans les contrôleurs.
  - `GroupVoter` : `VIEW`, `MEMBER`, `MANAGE`, `OWNER`, `INVITE`, `JOIN`, `TODO_WRITE`, `TODO_VIEW_ALL` ;
  - `TodoNodeVoter` : `TODO_VIEW`, `TODO_EDIT`, `TODO_DELETE`, `TODO_PROGRESS`, `TODO_ASSIGN`, `TODO_CREATE_CHILD` ;
  - `GroupMembershipResolver` met en cache les adhésions pendant la requête.
- **CSRF** sur **toutes** les actions POST, formulaires et AJAX.
- **Canaux Pusher privés :** chaque abonnement est autorisé côté serveur par `/pusher/auth`, qui vérifie l'appartenance à la conversation ou au groupe.
- **Messages privés :** réservés aux amis ; le blocage est respecté (voir 3.6).
- **Envois de fichiers :** type et taille vérifiés, noms de fichiers générés. `public/uploads` n'est pas versionné.
- **Secrets :**
  - dans `.env.local`, jamais commité (le `.env` du dépôt a des secrets vides) ;
  - en production, le `.env` du serveur est conservé lors des mises à jour.

---

## 8. Temps réel

| Canal | Événements | Utilisation |
|---|---|---|
| `private-user-{id}` | `notification`, `private-message` | Notifications et messages reçus |
| `private-conversation-{id}` | `new-message` | Conversation privée ouverte |
| `private-group-channel-{id}` | `new-message`, `message-pinned`, `message-unpinned` | Salons de groupe |

`PusherService` n'interrompt jamais une action si Pusher est indisponible : l'erreur est journalisée et l'action continue.

---

## 9. Commandes console

| Commande | Rôle |
|---|---|
| `army:sync-bsdata [--force]` | Synchronise factions, unités, armes, aptitudes et détachements depuis BSData. Un seul appel à l'API GitHub, cache par empreinte de fichier ; `--force` retraite tout. |
| `army:audit-bsdata` | Audit par faction : nombre d'unités, Legends, unités sans figurine, arme, aptitude ou points |
| `army:inspect-unit` | Détail extrait pour une unité ; `--tree` affiche l'arbre brut BSData |
| `app:forum:seed-categories [--dry-run]` | Crée les catégories de référence manquantes. Ne modifie jamais l'existant et peut être relancée sans risque. |
| `app:gamification:sync-badges` | Aligne la table `badge` sur `BadgeCatalog` |
| `app:gamification:recompute [--resum]` | Réévalue badges et XP (rattrapage) ; `--resum` recalcule l'XP à partir du grand livre |
| `app:notifications:purge` | Supprime les notifications lues de plus de N jours (90 par défaut) |

Les liens de mot de passe oublié expirés sont supprimés automatiquement à chaque nouvelle demande (pas de commande à planifier).

---

## 10. Arborescence du code

```
HighlightForge/
├── assets/
│   ├── controllers/        Contrôleurs Stimulus (voir §5)
│   ├── lib/realtime.js     Connexion Pusher partagée
│   ├── styles/app.css      Tailwind v4 : @theme (jetons) + @layer components (.btn, .card, .field…)
│   └── app.js              Point d'entrée importmap
├── bin/console
├── config/                 Paquets Symfony (security, twig, doctrine, tailwind…)
├── docs/
│   ├── design-system.md    Guide de l'interface
│   ├── roadmap.md          Évolutions prévues
│   └── recapitulatif.md    Ce document
├── migrations/             Migrations Doctrine écrites à la main
├── public/
│   ├── index.php
│   ├── images/             Logo et visuels
│   └── uploads/            avatars/ et gallery/ (non versionnés)
├── src/
│   ├── Army/               UnitCategory (classement des unités)
│   ├── BsData/             CatalogueGraph, ConditionEvaluator, EvalContext, UnitExtractor
│   ├── Command/            Commandes console (§9)
│   ├── Controller/
│   │   ├── Admin/          Dashboard et CRUD EasyAdmin
│   │   ├── Dev/            Styleguide (développement uniquement)
│   │   └── *.php           Un contrôleur par rubrique (Forum, Thread, Group, GroupTodo, ArmyList, Profil…)
│   ├── Entity/             Entités Doctrine (§6)
│   ├── EventSubscriber/    Connexion quotidienne (XP), déconnexion (présence), membres suspendus…
│   ├── Form/               Types de formulaires
│   ├── Gamification/       BadgeCatalog
│   ├── Mailer/             TransactionalMailer (envoi de tous les e-mails)
│   ├── Moderation/         ModerationService, ReportTargetResolver, énumérations (types, motifs, décisions, durées)
│   ├── Repository/
│   ├── Security/           Voters, EmailVerifier, UserChecker, SubmissionThrottle, GroupMembershipResolver
│   └── Service/            Gamification, Leaderboard, Notification(Renderer), Presence, Pusher, MemberBlocker,
│                           AdminStats, Retention, Onboarding, BsDataFetcher, uploaders, ImageOptimizer
├── templates/
│   ├── _macros/ui.html.twig     Macros d'interface (icon, avatar…)
│   ├── _partials/shell/         En-tête, barre du bas, pied de page, recherche, toasts, messagerie
│   ├── form/theme.html.twig     Thème de formulaire global
│   ├── email/                   Modèles d'e-mails (gabarit commun layout.html.twig)
│   ├── admin/ army/ forum/ friendship/ gallery/ gamification/ group/ group_invitation/
│   ├── group_todo/ home/ leaderboard/ legal/ notification/ onboarding/ private_message/
│   ├── profil/ registration/ report/ reset_password/ security/ styleguide/ thread/ todo/
│   └── base.html.twig           Gabarit principal
├── tests/Functional/        Tests fonctionnels (comptes, blocage, modération, rôle modérateur)
├── tests/Unit/              Tests unitaires (droits de sanction)
├── translations/
├── var/                    Cache, journaux, tailwind/app.built.css (non versionné)
└── vendor/                 Dépendances Composer (non versionnées)
```

---

## 11. Déploiement et exploitation

### Environnements
| | Développement | Production |
|---|---|---|
| Hébergement | XAMPP (Windows) | alwaysdata, offre gratuite |
| `APP_ENV` | `dev` | `prod` |
| Secrets | `.env.local` | `.env` du serveur (conservé à chaque mise à jour) |
| E-mails | `MAILER_DSN=mailjet+api://CLE_API:CLE_SECRETE@default` | idem, dans le `.env` du serveur |

**Variables e-mail** (à définir dans les deux environnements) :
- `MAILER_DSN` : clés API Mailjet (compte Mailjet > Paramètres du compte > Clés API REST). `null://null` n'envoie rien ;
- `MAILER_FROM_ADDRESS` : adresse d'expédition, **validée dans Mailjet** (Paramètres du compte > Adresses d'expédition), sinon Mailjet refuse l'envoi ;
- `MAILER_FROM_NAME` : nom affiché (SprueHub par défaut).
- Les liens des e-mails reprennent l'adresse du site de la requête en cours : rien à configurer.

### Procédure de mise à jour (FileZilla et SSH)
1. **Sur le PC :**
   - exporter le code propre (sans `.env`, `vendor`, `var`, `uploads`) ;
   - construire le CSS avec `php bin/console tailwind:build --minify`.
2. **Envoyer** le dossier exporté dans `~/sprue-deploy` sur le serveur.
3. **Sauvegarder la base :**
```bash
mysqldump --no-tablespaces -h mysql-hforge.alwaysdata.net -u hforge -p hforge_prod > ~/backup-db-$(date +%F).sql
```
4. **Mettre l'ancien code de côté** dans `~/ancien`, puis copier la nouvelle version :
```bash
cp -a ~/sprue-deploy/. . && rm -rf var/cache/*
```
5. **Installer les dépendances :**
```bash
composer install --no-dev --optimize-autoloader
```
6. **Mettre à jour la base :**
```bash
php bin/console doctrine:migrations:migrate --no-interaction
```
7. **Envoyer `var/tailwind/app.built.css`** construit sur le PC. ⚠️ `tailwind:build` est interrompu sur le serveur (manque de mémoire de l'offre gratuite).
8. **Compiler les assets et vider le cache :**
```bash
php bin/console asset-map:compile && php bin/console cache:clear
```
9. **Selon les changements :** `app:gamification:sync-badges`, `app:gamification:recompute --resum`, `app:forum:seed-categories`, `army:sync-bsdata --force`.

**Retour en arrière :** remettre `~/ancien` en place, relancer `composer install` et vider le cache. Si besoin, restaurer la sauvegarde SQL.

### Maintenance régulière (à planifier)
| Tâche | Fréquence conseillée |
|---|---|
| `army:sync-bsdata` | Chaque semaine, ou après une mise à jour des règles |
| `app:notifications:purge` | Chaque nuit |
| Sauvegarde de la base (`mysqldump`) | Chaque nuit, en gardant 7 jours |

---

## 12. Conventions de développement

- **Aucun JavaScript dans les templates :** pas de `<script>` en ligne ni d'attribut `on…=` ; tout passe par des contrôleurs Stimulus.
- **Migrations écrites à la main :** `doctrine:migrations:diff` peut servir de brouillon (le schéma correspond aux entités), mais chaque migration est relue et nommée à la main, avec un bloc de description en tête. L'historique des migrations ne se rejoue pas sur une base vide : pour une base neuve, utiliser `doctrine:schema:create` puis `doctrine:migrations:version --add --all`.
- **CSRF sur chaque POST**, et vérification des droits par les **Voters** (`isGranted` / `denyAccessUnlessGranted`).
- **Interface :** utiliser les classes du design system (`.btn`, `.card`, `.field`, `.input`…) et les macros `ui.icon` / `ui.avatar`. Voir `docs/design-system.md` et `/_styleguide` en développement.
- **Vocabulaire de l'interface :** Salon (channel), Propriétaire (owner), Tâches (todo), Sujet (thread), Réponse (post), Liste d'armée.
- **Commits thématiques** préfixés `AJOUT -`, `MODIF -` ou `SÉCURITÉ -`.
- **Vérifications avant commit :**
  - `php bin/console lint:twig templates` et `php bin/console lint:container` ;
  - `php bin/console doctrine:schema:validate` (aucun écart attendu) ;
  - `php bin/phpunit` : tests fonctionnels sur la base `highlightforge_test`, à créer une fois avec `php bin/console doctrine:database:create --env=test` et `php bin/console doctrine:schema:create --env=test` (connexion dans `.env.test.local`).
