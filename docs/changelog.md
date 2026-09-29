# Changelog SprueHub

Toutes les évolutions du site, de la plus récente à la plus ancienne. Chaque version regroupe les changements par nature : **Ajouté**, **Modifié**, **Corrigé**, **Sécurité**, **Supprimé**.

Le fonctionnement détaillé du site est décrit dans [recapitulatif.md](recapitulatif.md), la suite du projet dans [roadmap.md](roadmap.md).

---

## [1.4] — 29 septembre 2026 — Audit complet, cohérence et mise en production

### Sécurité
- Mot de passe affiché remis en masqué avant la mise en cache d'une page : il ne restait plus lisible après un retour arrière.
- Identifiants numériques exigés dans les adresses (tâches, amitiés, invitations, galerie, votes) : une adresse invalide renvoie une page introuvable au lieu d'une erreur 500.
- Nouvelles limites anti-spam : messages de salon (20 par minute), envoi de photos (20 par heure), import de listes d'armée (20 par heure).
- Longueurs maximales : messages du forum (20 000 caractères), description d'un groupe (2 000).
- Le compte « Membre supprimé » ne peut plus être ajouté en ami ni bloqué.

### Corrigé
- Vues des sujets et compteurs des listes d'armée (vues, exports, duplications) : plus aucun incrément perdu quand plusieurs membres ouvrent la même page au même moment.
- Export texte des listes d'armée : les points d'une amélioration ne sont plus comptés sur chaque exemplaire d'une unité.
- Purge des comptes inactifs : un compte redevenu actif entre la prévenance et la suppression n'est jamais supprimé.
- E-mails : une erreur de rendu ou d'adresse n'interrompt plus l'action qui les envoie (par exemple une suppression de compte).
- Un membre exclu d'un groupe ne garde plus les notifications non lues de ses salons.
- Couleur de survol des boutons, menus et liens rétablie en thème sombre ; barres de progression lisibles en thème clair.
- Retour arrière dans le navigateur : plus de sélecteur d'émoticônes ni de liste de mentions en double, sélection de la galerie cohérente, messagerie qui ne reste plus bloquée sur « Chargement… ».
- Messagerie : un message dont l'envoi échoue est remis dans le champ au lieu d'être perdu.
- Aperçu Markdown, mentions, notifications, messagerie, fiches techniques : une réponse arrivée en retard n'écrase plus une réponse plus récente.
- Ordre personnalisé des groupes : les enregistrements successifs arrivent dans l'ordre.
- Mobile : plus aucune page ne défile horizontalement (onglets du profil, info-bulles de l'éditeur, pages légales, version imprimable, bouton de suppression du compte).
- Réglages de rôle des groupes tous validés ; date de fin des tâches toujours cohérente avec leur état.

### Modifié
- **Interface entièrement au tutoiement** (notifications, didacticiel, formulaires, messages, administration), hors pages légales ; descriptions des catégories de référence du forum mises à jour en base.
- Requêtes regroupées au lieu d'une requête par élément : profil, accueil, liste d'amis, conversations, salons, galerie, synchronisation BSData.
- Minuteries remplacées par des événements (fin d'animation, chargement de page) quand le temps n'était pas le sujet.
- Messagerie flottante : en-tête utilisable au clavier, flèches remplacées par des icônes.
- Zone tactile agrandie pour le signalement d'un message de salon ; titre de page sur la page photo.
- Icônes dessinées par JavaScript tirées d'une source unique ; utilitaires JavaScript partagés.
- Image d'accueil en WebP : 2,8 Mo → 235 Ko.
- Documentation réécrite : récapitulatif (fonctionnement, plan du site, architecture, fichiers à modifier pour changer le contenu), roadmap tournée vers la suite, dossier `docs/workflow/` (récupérer depuis Git, envoyer vers Git, mettre en production par SSH ou par FileZilla seul, dépannage).

### Supprimé
- Cahier des charges spécifique (entièrement réalisé), ancien mémo Git.
- Configuration Docker PostgreSQL inutilisée, fichier de données jamais lu (1,6 Mo), logos et favicons PNG non affichés.
- Code mort : une cinquantaine de méthodes jamais appelées, classes CSS et variables inutilisées, colonne `user.profile_bonus_awarded`.
- Action « Supprimer » de la liste des utilisateurs de l'administration (elle échouait) : un compte se supprime depuis sa fiche Sanctions.

---

## [1.3] — 28 septembre 2026 — Modération avancée, thèmes et conformité RGPD

### Ajouté
- **Réclamations** : un membre suspendu ou banni conteste la sanction depuis la page de connexion ; l'équipe la lève ou la maintient et répond par e-mail. Menu Réclamations dans l'administration.
- **Membres bannis définitivement** masqués partout (fil, classement, suggestions, recherche, carrousels, Explorer), leurs messages du forum et des groupes restant visibles avec la mention « Banni ».
- Avertissement avant de répondre à un sujet sans activité depuis 6 mois.
- **Thème clair ou sombre**, par défaut celui du système, avec un interrupteur à trois positions.
- Lignes squelettes pendant les chargements et barre de progression entre les pages.
- **Pages légales** : mentions légales, confidentialité et cookies, conditions d'utilisation ; informations centralisées dans `config/packages/legal.yaml`.
- **Suppression du compte** par le membre (mot de passe et confirmation) ou par un administrateur : messages du forum et des groupes anonymisés (« Membre supprimé »), groupes transmis, tout le reste effacé, e-mail de confirmation.
- **Purges automatiques** : comptes inactifs depuis 3 ans (après un e-mail de prévenance 30 jours avant), signalements et réclamations 12 mois après la décision.
- Liens **« Retour »** vers la page d'où l'on vient, avec retour à la même position de défilement.
- Favicon adapté au thème du navigateur.

### Modifié
- Règle de mot de passe : 8 caractères minimum avec majuscule, minuscule, chiffre et symbole ; lien « Mot de passe oublié » sous le champ.
- Icônes SVG dessinées pour le site à la place des émoticônes d'interface, y compris pour chaque badge.
- Tableau de bord de l'administration réorganisé : à traiter, chiffres clés, graphiques, modération, activité.
- Motif de suspension affiché sur la page de connexion tant que la sanction dure ; sanctions visibles en couleur dans la liste des utilisateurs.
- Alpine.js et Pusher servis par le site lui-même : plus aucune bibliothèque chargée depuis un site tiers.
- Messagerie flottante sans ombre, arrêtée au-dessus du pied de page.
- Pages de l'accueil, du classement et des tâches allégées ; bouton de partage réparé.

---

## [1.2] — 26 septembre 2026 — Cahier des charges spécifique

### Ajouté
- **Didacticiel** « Je suis perdu » : une visite guidée par fonctionnalité.
- **Traitement combiné des signalements** : sort du contenu, avertissement, suspension et note interne validés ensemble par un seul bouton « Traiter ».
- **Statistiques** : temps moyen de traitement, récapitulatif des signalements, graphiques sur 30 jours (membres actifs, inscriptions, rétention).
- **Groupes** : page en trois colonnes (invitations, mes groupes, suggestions), tri par activité ou personnalisé par glisser-déposer, fenêtre d'invitation multiple, droit d'inviter réglable, sourdine, signalement du groupe et des messages, mentions avec notification.
- **Assignations de tâches** : jusqu'à 3 membres par tâche, demandes à valider ou mode libre, notifications.
- Recadrage des photos de profil et bannières dans le navigateur.
- Suggestions de mention `@pseudo` dans le forum, la messagerie et les salons.

### Modifié
- Forum : réactions en icônes, signalement par un drapeau, images en plein écran, éditeur avec infobulles de syntaxe et émoticônes, catégories en lecture seule.
- Accueil : carrousels côte à côte, fil à chargement automatique, filtre « Actualités ».
- Profil, messagerie (conversations remontées en direct), recherche (sections et sujets du forum), suggestions d'amis par amis en commun.
- Commentaires du code rédigés en anglais.

### Sécurité
- `.env` du dépôt sans aucun secret ; secrets de production dans le `.env.local` du serveur.

---

## [1.1] — 24 septembre 2026 — Refonte et site public

### Ajouté
- **Design system** (couleurs, composants, icônes) et nouveau cadre de navigation : en-tête, barre du bas sur mobile, bouton « Publier ».
- **Inscription, présentation guidée en 4 étapes** et nouvelle page d'accueil.
- **E-mails** (Mailjet) : confirmation d'adresse, mot de passe oublié, messages de la modération.
- **Modération** : signalement de tout contenu, file de modération, masquage, suppression, avertissements, suspensions, rôle modérateur distinct de l'administrateur, blocage entre membres.
- **Limites anti-spam** sur les inscriptions, messages, sujets, réponses, commentaires et signalements.
- **Forum** : catégories hiérarchiques, option de création de sujets par catégorie, abonnements, mentions, citations, sujets résolus, éditeur Markdown avec aperçu et images.
- **Galerie** : descriptions, likes, commentaires, albums, visionneuse, photo précédente / suivante, tendances de la semaine.
- **Centre de notifications** en temps réel, avec 6 sons au choix.
- **Gamification** : XP, niveaux, 17 badges, titres, classement.
- **Listes d'armée** : liste officielle (règles vérifiées) ou libre, fiches techniques complètes, export texte et PDF, import, page Explorer, duplication.
- **Groupes** : salons avec droits de lecture et d'écriture, messages épinglés, droits des tâches.
- **Tableau de bord** de l'administration : statistiques et membres connectés.
- Synchronisation BSData complète et catégories de référence du forum.
- Roadmap et plan du site.

### Sécurité
- Messagerie privée réservée aux amis, canaux temps réel autorisés un par un, vérifications des droits centralisées.
- Nettoyage du dépôt : secrets retirés de Git.

---

## [1.0] — Septembre 2026 — Gamification et temps réel

### Ajouté
- Système d'XP et de badges, y compris des badges d'exploration.
- Votes « Positif » et « Aide » sur les réponses du forum.
- Optimisation des images envoyées, barre de recherche.
- Contenus de la page d'accueil, rôle affiché sur le profil.
- Nom du site SprueHub et gestion de la photo de profil.

### Modifié
- Temps réel assuré par Pusher.
- Système de notifications, page profil (onglet galerie, e-mail masqué, options d'affichage), liste de tâches.
- Commandes de synchronisation et amélioration des listes d'armée.
- Optimisation de l'authentification et des badges ; corrections d'affichage et CRUD de l'administration.

---

## [0.1] — Mai à août 2026 — Fondations

### Ajouté
- Authentification complète (inscription, connexion).
- Forum : catégories, sujets, réponses, pagination.
- Profil public.
- Amis : demandes, blocage, déblocage.
- Liste de tâches personnelle.
- Administration EasyAdmin.
- Groupes : membres, invitations, paramètres, liste de tâches avec assignations, discussion en temps réel dans des salons.
- Messagerie privée : page des messages et messagerie flottante.
- Listes d'armée : création, affichage, modification, suppression ; unités synchronisées depuis BSData.
- Formulaires d'inscription et de connexion retravaillés.
