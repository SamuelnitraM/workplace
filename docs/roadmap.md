# Roadmap SprueHub

Ce qui reste à construire, par ordre de priorité. Le fonctionnement actuel du site est décrit dans [recapitulatif.md](recapitulatif.md).

**Légende**
- Priorité : 🔴 haute · 🟠 moyenne · 🟢 basse
- Effort : **S** (moins d'une demi-journée) · **M** (1 à 2 jours) · **L** (3 jours et plus)

---

## Déjà en place

Comptes (inscription, e-mail vérifié, mot de passe oublié, suppression du compte), profils et galerie avec albums, forum hiérarchique (Markdown, mentions, abonnements, sujets résolus), amis et messagerie privée en temps réel, groupes (salons, épingles, invitations, tâches partagées avec assignations), tâches personnelles, listes d'armée Warhammer 40k (BSData, règles officielles, fiches techniques, import, export texte et PDF, Explorer), gamification (XP, badges, titres, classement), notifications en temps réel avec sons, fil d'actualité, recherche, présentation guidée et didacticiel, thème clair / sombre, modération complète (signalements, décisions combinées, sanctions, réclamations, statistiques), pages légales et purges RGPD.

---

## 1. Mise en production sereine 🔴

- [ ] **Rotation des secrets** (S) : nouvelle clé `PUSHER_SECRET`, nouveaux mots de passe SSH et base de données, `APP_SECRET` propre à la production.
- [ ] **Tâches planifiées alwaysdata** (S) : purges de nuit, synchronisation BSData hebdomadaire, sauvegarde quotidienne de la base (voir [workflow/03-mise-en-production.md](workflow/03-mise-en-production.md)).
- [ ] **Script de déploiement** (M) : `deploy.sh` sur le serveur qui enchaîne les commandes de l'étape 3 de la mise en production, avec arrêt à la première erreur.
- [ ] **Surveillance des erreurs** (S) : e-mail sur les erreurs critiques de `var/log/prod.log` (Monolog `fingers_crossed` + `symfony_mailer`).
- [ ] **Coordonnées de l'éditeur** (S) : remplacer les coordonnées personnelles par celles d'une structure professionnelle ou d'une domiciliation dans `config/packages/legal.yaml` quand le site passera en version publique.

## 2. Qualité technique 🟠

### Tests
- [ ] **Voters** (M) : `GroupVoter` et `TodoNodeVoter`, pour tous les rôles et réglages des tâches.
- [ ] **Gamification** (M) : XP quotidienne, bonus de série, protection de série, déblocage des badges.
- [ ] **Extraction BSData** (M) : jeu de fichiers figé ; nombre d'unités, exclusion des Crucible, présence des Legends.
- [ ] **Intégration continue** (S) : GitHub Actions avec `lint:twig`, `lint:container`, `doctrine:schema:validate` et PHPUnit sur MariaDB.

### Architecture et performances
- [ ] **Découper `GroupController`** (M) : paramètres, messages et épingles, membres.
- [ ] **Cache applicatif** (S) : classement (5 minutes) et statistiques du tableau de bord (10 minutes).
- [ ] **Audit des requêtes** (M) : profil, forum, groupes et galerie avec le profiler Symfony, sur une base remplie de données réalistes.

### Référencement et accessibilité
- [ ] **Balises `description` et Open Graph** (S) : sujets, profils, listes publiques et photos, pour un aperçu dans les liens partagés sur Discord ou Facebook.
- [ ] **`sitemap.xml` et `robots.txt`** (S).
- [ ] **Audit d'accessibilité** (S) : Lighthouse et axe sur les pages principales, puis corrections.

## 3. Communauté et événements 🟠

- [ ] **Événements de groupe** (L) : partie, tournoi ou soirée peinture, avec date, lieu, places, inscriptions, rappel la veille et export vers un calendrier (`.ics`).
- [ ] **Galerie partagée d'un groupe** (M).
- [ ] **Listes d'armée partagées dans un groupe** (S).
- [ ] **Tournois** (L) : inscriptions avec liste d'armée, rondes, appariements suisses, résultats, classement final et badge du vainqueur.
- [ ] **Joueurs à proximité** (M) : ville ou département facultatif sur le profil, recherche « joueurs près de chez moi », visibilité réglable.

## 4. Hobby 🟠

- [ ] **Pile de la honte** (L) : inventaire des figurines par faction et par unité (liées aux unités BSData), quantité et statut (non monté → monté → sous-couché → peint → socle terminé).
- [ ] **Statistiques de peinture** (M) : pourcentage peint, progression mensuelle, graphiques sur le profil.
- [ ] **Lien pile / listes d'armée** (M) : unités de la liste possédées ou peintes.
- [ ] **Suivi d'un projet de peinture** (M) : étapes photographiées d'une même figurine, affichées en frise.
- [ ] **Parties et résultats** (M) : adversaire (membre ou invité), listes jouées, mission, scores, confirmation par l'adversaire.
- [ ] **Statistiques de jeu et classement ELO** (M).

## 5. Engagement 🟢

- [ ] **Résumé hebdomadaire par e-mail** (M) : activité manquée, nouveaux sujets suivis, progression XP ; désinscription en un clic.
- [ ] **Préférences de notification par type** (M) : site, e-mail ou aucune.
- [ ] **Défis hebdomadaires** (M) : « poste 3 photos », « réponds à 5 sujets », bonus d'XP et progression visible.
- [ ] **Saisons mensuelles** (M) : classement remis à zéro, badge du podium, archives.
- [ ] **Nouveaux badges hobby** (S) : première liste d'armée, 10 et 50 photos, projet de peinture terminé, organisateur d'événement, vainqueur de tournoi.

## 6. Application mobile (PWA) 🟢

- [ ] **Manifeste et icônes** (S) : site installable sur mobile.
- [ ] **Service worker** (M) : cache des pages statiques et page hors ligne.
- [ ] **Notifications push** (M) : Web Push (clés VAPID) branché sur le système de notifications existant.

## 7. Offre premium 🟢

- [ ] **Définir l'offre** (S) : titres et cadres de profil exclusifs, galerie plus grande, statistiques de parties détaillées, badge « Soutien ».
- [ ] **Paiement** (L) : Stripe Checkout et webhooks, rôle `ROLE_PREMIUM` avec date d'expiration, page de gestion de l'abonnement. Implique des CGV et une mise à jour des pages légales.

## 8. Vente d'occasion 🟢

Un espace d'annonces entre membres, spécialisé dans le hobby, à lancer quand la communauté sera assez active pour le faire vivre.

- [ ] **Annonces** (L) : titre, description, prix, état, catégorie, système de jeu et faction, 6 photos, mode de remise, localisation ; cycle disponible → réservé → vendu, expiration après 60 jours.
- [ ] **Recherche et filtres** (M) : texte, catégorie, faction, prix, état, distance.
- [ ] **Contact du vendeur** (M) : conversation liée à l'annonce, sans amitié requise, blocage respecté.
- [ ] **Favoris, alertes et évaluations** (M).
- [ ] **Modération** (S) : nouveau type de signalement, limite d'annonces pour les comptes récents, conseils anti-arnaque.
- [ ] **Paiement sécurisé** (L, plus tard) : Stripe Connect, frais de service, obligations déclaratives des plateformes (DAC7).
