# Roadmap SprueHub

Document de suivi des améliorations prévues. Cocher les cases au fur et à mesure.

**Légende**
- Priorité : 🔴 haute · 🟠 moyenne · 🟢 basse
- Effort estimé : **S** (moins d'une demi-journée) · **M** (1 à 2 jours) · **L** (3 jours et plus)

---

## Phase 1 — Fondations d'un site public 🔴

Objectif : sécuriser le site et le rendre utilisable sans intervention manuelle de l'administrateur.

### Comptes et sécurité
- [x] **Mot de passe oublié** (S/M) : `symfonycasts/reset-password-bundle`, lien à usage unique valable 1 heure, page de demande (e-mail ou pseudo) et page de réinitialisation.
- [x] **Envoi d'e-mails** (S) : Mailjet (offre gratuite) via `symfony/mailjet-mailer`, envoi synchrone, modèles Twig communs (`templates/email/`). Reste à renseigner `MAILER_DSN` et l'expéditeur validé dans `.env.local` et le `.env` du serveur.
- [x] **Vérification de l'adresse e-mail** (S) : `App\Security\EmailVerifier` branché à l'inscription, bandeau « Confirme ton adresse » et bouton pour renvoyer le lien.
- [x] **Limite de tentatives de connexion** (S) : `login_throttling` dans `config/packages/security.yaml` (5 essais par minute).
- [x] **Limite d'envoi** (S) : `symfony/rate-limiter` sur l'inscription, les messages privés, les commentaires, les sujets, les réponses, les signalements et les e-mails (anti-spam).
- [ ] **Rotation des secrets** (S) : nouvelle clé `PUSHER_SECRET`, nouveaux mots de passe SSH et base de données, `APP_SECRET` propre à la production.

### Modération
- [x] **Signalements** (M) : entité `Report` (auteur, cible polymorphe : post, sujet, photo, commentaire, message, profil ; motif ; statut), bouton « Signaler » sur chaque contenu.
- [x] **File de modération EasyAdmin** (M) : liste des signalements, actions masquer, supprimer, avertir et classer sans suite ; badge du nombre en attente sur le dashboard.
- [x] **Sanctions** (M) : suspension temporaire ou définitive d'un compte, avec un message affiché à la connexion.
- [x] **Blocage entre membres** (S) : un membre bloqué ne peut plus écrire en privé, envoyer de demande d'ami ni inviter dans un groupe.

### Base de données
- [x] **Supprimer les tables orphelines** (S) : `announcement`, `announcement_image`, `faction`, `game_system` (migration `Version20261002100000`). Le projet de vente d'occasion qu'elles préparaient est repris en phase 6.

---

## Phase 2 — Rétention et vie sociale 🔴

Objectif : donner une raison de revenir chaque jour.

### Fil d'actualité
- [x] **Fil personnalisé sur l'accueil** (L) : activité des amis et des groupes (nouvelles photos, listes d'armée publiques, sujets, badges débloqués), paginé et filtrable (« Tout », « Amis », « Groupes », « Communauté »).
- [x] **Réactions sur le fil** (M) : réutiliser les likes et commentaires existants.

### Forum
- [x] **Abonnement aux sujets** (M) : suivre un sujet (automatique pour l'auteur et les personnes qui répondent), avec une notification à chaque nouvelle réponse.
- [x] **Mentions `@pseudo`** (M) : autocomplétion, lien vers le profil, notification de la personne mentionnée.
- [x] **Citer une réponse** (S).
- [x] **Sujet résolu** (S) : l'auteur marque la meilleure réponse, qui rapporte de l'XP à son auteur.
- [x] **Éditeur Markdown avec aperçu** (M), et **images dans les réponses** (M).

### Notifications
- [x] **Son de notification** (S) : 6 sons originaux synthétisés dans le navigateur, choix dans les paramètres du profil (ou aucun son).
- [ ] **Préférences par type** (M, reporté à l'application mobile) : choisir, pour chaque type, entre site, e-mail ou aucun.
- [ ] **Résumé hebdomadaire par e-mail** (M) : activité manquée, nouveaux sujets dans les catégories suivies, progression XP.
- [ ] **Notifications push** (M) : voir la PWA en phase 5.

---

## Phase 3 — Fonctionnalités cœur « hobby » 🟠

Objectif : se différencier des réseaux sociaux génériques.

### Listes d'armée
- [x] **Validation des règles** (L), facultative : liste **libre** (aucune vérification) ou **officielle** avec un format Incursion (1000), Force de frappe (2000) ou Assaut (3000). Une action interdite est refusée dans l'éditeur avec une fenêtre qui en donne la raison ; le serveur applique les mêmes règles :
  - format de points (1000, 2000, 3000) ;
  - maximum 3 exemplaires d'une même fiche (6 pour les troupes de ligne et les transports assignés) ;
  - un seul Seigneur de guerre, obligatoirement un personnage ;
  - un seul exemplaire des personnages épiques ;
  - améliorations du détachement uniquement, une fois chacune, sur un personnage non épique (améliorations synchronisées depuis BSData).
- [x] **Profils d'unités** (M) : fiche technique complète (caractéristiques en encadrés, armes de tir et de mêlée avec leurs mots-clés, aptitudes, mots-clés), dans une fenêtre, sur la page de la liste comme dans l'éditeur.
- [x] **Export texte** (S) : format de l'application officielle (copie, téléchargement `.txt`).
- [x] **Export PDF imprimable** (M) : page d'impression (composition et fiches techniques), « Enregistrer au format PDF » du navigateur.
- [x] **Import** d'une liste au format texte (M) : faction, format, détachement, unités, taille, Seigneur de guerre et améliorations reconnus.
- [x] **Page « Explorer »** (M) : listes publiques filtrables par faction, détachement, format et recherche ; lien de partage ; bouton « Dupliquer dans mes listes ».
- [x] **Ajouter les 10 nouvelles factions** à la liste des factions favorites (S) : la liste reprend désormais celle de l'éditeur (`BsDataFetcher::FACTION_GROUPS`).

### Pile de la honte (inventaire de figurines) — reportée
- [ ] **Inventaire** (L) : figurines possédées, par faction et par unité (liées à `FactionUnit`), avec quantité et statut (non monté → monté → sous-couché → peint → socle terminé).
- [ ] **Statistiques** (M) : pourcentage peint, progression mensuelle, graphiques sur le profil.
- [ ] **Lien avec les listes d'armée** (M) : indiquer les unités de la liste qui sont possédées ou peintes.

### Galerie
- [x] **Albums** (M) : fonctionnent comme des dossiers (une photo dans un album au plus). Photos cochées : « Créer un album », ou « + » sur un album existant ; retrait, déplacement, renommage et suppression.
- [x] **Page photo** : album affiché, agrandissement plein écran quand la photo est réduite, photo précédente / suivante (boutons et flèches du clavier).
- [ ] **Suivi d'un projet de peinture** (M) : plusieurs étapes photographiées d'une même figurine, affichées en frise. Reporté.
- [x] **Tendances** (S) : photos les plus aimées de la semaine, mises en avant sur l'accueil.

### Parties et résultats — reporté
- [ ] **Enregistrer une partie** (M) : adversaire (membre ou invité), listes jouées, mission, scores, vainqueur, confirmation par l'adversaire.
- [ ] **Statistiques de jeu** (M) : victoires, défaites et nuls par faction ; adversaires fréquents.
- [ ] **Classement ELO** entre membres (M).

---

## Phase 4 — Communauté et événements 🟠

- [ ] **Événements de groupe** (L) : partie, tournoi ou soirée peinture, avec date, lieu, places, inscriptions, rappel la veille et export vers un calendrier (`.ics`).
- [ ] **Galerie partagée du groupe** (M).
- [ ] **Listes d'armée partagées dans un groupe** (S).
- [ ] **Tournois** (L) : inscriptions avec liste d'armée, rondes, appariements suisses, saisie des résultats, classement final et badge pour le vainqueur.
- [ ] **Joueurs à proximité** (M) : ville ou département facultatif sur le profil, recherche « joueurs près de chez moi », respect de la confidentialité (visibilité réglable).

---

## Phase 5 — Engagement et monétisation 🟢

### Gamification
- [ ] **Défis hebdomadaires** (M) : par exemple « poste 3 photos », « réponds à 5 sujets », avec un bonus d'XP et une progression visible.
- [ ] **Saisons mensuelles** (M) : classement remis à zéro, badge pour le podium, archives des saisons.
- [ ] **Nouveaux badges hobby** (S) : première liste d'armée, 10 et 50 photos, projet de peinture terminé, organisateur d'événement, vainqueur de tournoi.

### Offre premium
- [ ] **Définir l'offre** (S) : titres et cadres de profil exclusifs, plus d'espace dans la galerie, export PDF avancé, statistiques de parties détaillées, badge « Soutien ».
- [ ] **Paiement** (L) : Stripe Checkout et webhooks, rôle `ROLE_PREMIUM` avec une date d'expiration, page de gestion de l'abonnement.

### Application mobile (PWA)
- [ ] **Manifeste et icônes** (S) : le site devient installable sur mobile.
- [ ] **Service worker** (M) : cache des pages statiques et page hors ligne.
- [ ] **Notifications push** (M) : Web Push avec les clés VAPID, relié au système de notifications existant.

---

## Phase 6 — Vente d'occasion 🟢

Objectif : un espace d'annonces entre membres, type Leboncoin ou Vinted, spécialisé dans le hobby (figurines, peintures, matériel, livres de règles).

### Annonces
- [ ] **Annonce** (L) : titre, description, prix, état (neuf sous blister, monté, peint, pièces manquantes…), catégorie (figurines, peinture, outillage, décors, livres), système de jeu et faction (données BSData), jusqu'à 6 photos, mode de remise (en main propre, envoi) et ville ou département.
- [ ] **Cycle de vie** (M) : disponible → réservé → vendu, retrait par le vendeur, expiration automatique après 60 jours sans mise à jour.
- [ ] **Recherche et filtres** (M) : texte, catégorie, système de jeu, faction, fourchette de prix, état, distance.
- [ ] **Profil vendeur** (S) : onglet « Ventes » sur le profil, avec les annonces en cours et vendues.

### Échanges
- [ ] **Contacter le vendeur** (M) : conversation liée à l'annonce, sans obligation d'amitié ; le blocage entre membres s'applique.
- [ ] **Favoris et alertes** (M) : suivre une annonce (notification si le prix baisse) et enregistrer une recherche (notification des nouvelles annonces).
- [ ] **Évaluations** (M) : note et commentaire après une vente confirmée par les deux membres, moyenne affichée sur le profil.

### Confiance et modération
- [ ] **Signalement des annonces** (S) : nouveau type de cible dans `App\Moderation\ReportTargetType`, masquage et suppression depuis la file de modération.
- [ ] **Prévention des arnaques** (S) : conseils affichés au contact, limite d'annonces par jour pour les comptes récents ou non vérifiés.
- [ ] **Paiement sécurisé** (L, plus tard) : aucun paiement sur le site au départ (remise en main propre, paiement hors site). À étudier ensuite : Stripe Connect, frais de service et obligations déclaratives des plateformes (DAC7).

---

## Chantier continu — Qualité technique 🟠

### Tests
- [ ] **Tests fonctionnels des parcours critiques** (L) : inscription, connexion, mot de passe oublié, messages privés, amitiés. Déjà couverts (`tests/Functional/`) : inscription et vérification d'e-mail, mot de passe oublié, limite de connexion, blocage, signalements, masquage, suspension, règles des listes officielles, export / import, Explorer, albums de la galerie, tendances.
- [ ] **Tests des Voters** (M) : `GroupVoter` et `TodoNodeVoter` (tous les rôles et réglages de la todo).
- [ ] **Tests unitaires de la gamification** (M) : XP quotidienne, série de 7 jours, protection de série de 3 jours, déblocage des badges.
- [ ] **Tests de l'extraction BSData** (M) : jeu de fichiers figé ; vérifier le nombre d'unités, l'exclusion des Crucible et la présence des Legends.
- [ ] **Intégration continue** (S) : GitHub Actions avec `lint:twig`, `lint:container`, `doctrine:schema:validate` et PHPUnit.

### Architecture
- [ ] **Découper `GroupController`** (745 lignes) (M) : paramètres, messages et épingles, membres.
- [x] **Alléger `ArmyListController`** (M) : construction des listes dans `App\Army\ArmyListComposer`, règles dans `ArmyListRules`, format texte dans `ArmyListTextFormat`.

### Performances
- [ ] **Audit des requêtes SQL** (M) : profil, forum, groupes et galerie avec le profiler ; ajouter des jointures là où c'est nécessaire.
- [ ] **Index SQL** (S) : `user.last_activity_at` et `created_at` des posts, messages et photos.
- [ ] **Cache applicatif** (S) : classement (5 minutes) et statistiques admin (10 minutes).

### Exploitation
- [ ] **Script de déploiement** (M) : `deploy.sh` qui enchaîne sauvegarde de la base, mise en place du code, `composer install`, migrations, CSS construit sur le PC (le serveur gratuit manque de mémoire pour Tailwind), `asset-map:compile` et `cache:clear`.
- [ ] **Tâches planifiées alwaysdata** (S) :
  - synchronisation BSData chaque semaine ;
  - purge des notifications chaque nuit ;
  - sauvegarde de la base chaque nuit, en gardant 7 jours.
- [ ] **Surveillance des erreurs** (S) : alerte par e-mail sur les erreurs critiques de `var/log/prod.log` (Monolog), ou un service externe.

### Référencement et accessibilité
- [ ] **Balises `description` et Open Graph** (S) : sujets, profils, listes publiques et photos, pour un aperçu dans les liens partagés sur Discord ou Facebook.
- [ ] **`sitemap.xml` et `robots.txt`** (S).
- [ ] **Audit d'accessibilité** (S) : Lighthouse et axe sur les pages principales, puis corrections.

---

## Ordre recommandé

1. **Phase 1** : fondations (comptes, e-mails, modération).
2. **Phase 2** : fil d'actualité et abonnements au forum, le plus gros effet sur la rétention.
3. **Phase 3** : listes d'armée et galerie faites ; pile de la honte, suivi de peinture et parties reportés.
4. **Phases 4 et 5**, selon les retours des membres.
5. **Phase 6** : vente d'occasion, une fois la communauté assez active pour faire vivre les annonces.
6. **Qualité technique** : en continu, avec les tests de chaque nouvelle fonctionnalité écrits en même temps qu'elle.
