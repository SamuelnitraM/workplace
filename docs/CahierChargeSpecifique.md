# Cahier des charges — Spécifications et améliorations du site

## 1. Forum

* **Optimisation de l'affichage des sujets :**
  * Supprimer le bouton « Répondre » situé en haut des sujets pour alléger l'interface.
  * Remplacer l'icône `#` (« Lien vers ce message ») par l'icône de drapeau pour le signalement.
  * Supprimer le bouton de signalement doublon placé à côté du bouton « Citer ».

* **Éditeur de texte (Textarea) :**
  * Supprimer le texte d'aide brut sous le champ (`Markdown : **gras**, _italique_...`).
  * Intégrer les indications de formatage sous forme de *tooltips* (au survol des boutons d'action).
  * Ajouter un bouton ouvrant un sélecteur/tableau d'émoticônes.
  * Supprimer la mention d'aide « Ctrl+Entrée pour envoyer ».
  * Déplacer le texte « Restez courtois et constructif » juste à côté du label « Votre réponse ».

* **Boutons de réaction (« Positif » et « Aide ») :**
  * Masquer les libellés textuels pour ne conserver que l'icône et le compteur (à l'image de l'affichage des messages de l'utilisateur connecté).
  * Appliquer la charte graphique : bleu *primary* pour le bouton « Positif », rouge ou rouge clair pour le bouton « Aide ».

* **Gestion des médias :**
  * Rendre les images intégrées dans les réponses cliquables afin de les ouvrir dans une vue plein écran (*lightbox*) au format original.

* **Arborescence et navigation des catégories :**
  * **Affichage conditionnel :** 
    * Si la catégorie parente autorise la création de sujets : afficher les sous-catégories dans une colonne de gauche et la liste des sujets au centre.
    * Si la catégorie parente n'autorise pas la création de sujets : afficher la liste des sous-catégories au centre.
  * **Ergonomie des clics :** Corriger la zone cliquable des éléments `<li>` contenant un sous-menu `<ul>`. L'élément parent doit être entièrement cliquable sans que l'élément enfant n'interfère avec son clic.
  * **Gestion des icônes :** Supprimer les icônes de dossier génériques ou les personnaliser selon la catégorie. Masquer les icônes des sous-catégories dans les vues parentes.
  * **Nettoyage des en-têtes :** Sur les catégories sans création de sujet, supprimer le sous-titre inutile « Sous-catégorie ». Ne conserver que le titre principal, sa description et la liste des sous-catégories.

---

## 2. Groupes

* **Refonte de la page d'accueil des groupes :**
  * Supprimer le système d'onglets au profit d'un affichage en trois colonnes :
    * **Colonne de gauche :** Liste des invitations reçues.
    * **Colonne centrale :** Liste des groupes dont l'utilisateur est membre.
    * **Colonne de droite :** Suggestions de groupes à rejoindre (ex. : groupes rejoints par des amis avec affichage de 2 ou 3 miniatures de profil et mention « - *N* amis sont dans ce groupe »).
  * **Tri des groupes (colonne centrale) :**
    * Tri par défaut par dernière activité (du plus récent au plus ancien).
    * Ajouter un commutateur (*switch*) permettant de basculer entre le tri par activité et un tri personnalisé fixe (réorganisable en *drag-and-drop*).
  * **Gestion des notifications d'invitation :** Rediriger la notification d'invitation vers la page des groupes en ciblant la colonne de gauche (suppression définitive de la page dédiée `groupe-invitation`).

* **Affichage des rôles :**
  * Supprimer le libellé textuel des rôles dans la liste des groupes.
  * Afficher uniquement une icône de couronne à côté du nom du groupe pour le propriétaire. N'afficher aucun indicateur pour les administrateurs et membres.

* **Système d'invitation et modération :**
  * Ajouter un bouton « Inviter » dans la page du groupe ouvrant une fenêtre modale avec barre de recherche et liste d'amis sélectionnables (*multi-selection*).
  * Rendre la liste scrollable tout en gardant le bouton « Inviter » fixe en bas de la modale.
  * Intégrer un paramètre de gestion des droits d'invitation (si le groupe est en accès libre, l'ensemble des membres peut inviter).
  * Ajouter un bouton de signalement pour le groupe entier (responsabilité incombant au propriétaire) ainsi qu'un bouton de signalement individuel sur chaque message.

* **Modules intégrés à la page de groupe :**
  * **Vue synthétique ToDo :** Ajouter une colonne à droite affichant l'avancement global des projets et de leurs catégories via des barres de progression.
  * **Espace de discussion :**
    * Intégrer un sélecteur d'émoticônes représenté par un casque de Space Marine en SVG à droite de la zone de saisie.
    * Activer les notifications lors de l'utilisation de la mention `@pseudo`.
    * Ajouter un commutateur (*switch*) pour rendre le groupe muet (désactivation de toutes les notifications, hors mentions directes `@`).

* **Gestion avancée de la ToDo de groupe :**
  * **Barre de progression des catégories :** Calcul automatique et non modifiable manuellement, basé sur l'état de complétion des tâches.
  * **Affichage des membres assignés :** Afficher le nombre de tâches à côté de miniatures de profil des utilisateurs assignés aux tâches de la catégorie.
  * **Workflow de demande d'assignation :**
    * Lorsqu'un utilisateur clique sur le bouton d'assignation, sa photo apparaît en grisé (demande en attente).
    * Un administrateur ou le propriétaire valide ou refuse l'assignation via une pop-up dédiée déclenchée au clic sur la photo.
    * Ajouter un réglage d'autorisation pour la gestion des assignations (Droits : *Propriétaire*, *Admin*, ou *Libre*).
    * **Mode *Libre* :** tout membre peut s'assigner directement à une tâche, sans demande à valider. Seuls les administrateurs et le propriétaire peuvent retirer un membre assigné.
  * **Nombre d'assignés par tâche :** jusqu'à 3 membres par tâche. Un paramètre du groupe fixe la limite (1, 2 ou 3) ; les demandes au-delà de la limite sont refusées.
  * **Ergonomie :** Rendre le menu déroulant d'assignation scrollable en cas de nombre élevé de membres. Replier (*collapse*) les projets par défaut (appliquer la même règle à la ToDo personnelle).

* **Paramètres :**
  * Rendre la liste des salons scrollable dans les paramètres pour éviter un déroulement excessif de la page.

---

## 3. Accueil

* **Reconfiguration visuelle de l'en-tête :**
  * Placer côte à côte les sections « Dernières créations de la communauté » et « Tendances de la semaine ».
  * Transformer ces deux sections en carrousels de 10 photos chacune (avec 3 photos visibles simultanément).
  * Positionner le bouton « Publier une photo » à proximité immédiate de ces deux carrousels.

* **Fil d'actualité et performances :**
  * Rendre la section du fil d'actualité scrollable indépendamment (ou intégrer un chargement dynamique/défilement infini) pour limiter la hauteur de la page.
  * Aligner parfaitement les boutons « J'aime » et « Commentaire ».
  * Supprimer le texte dynamique du bouton « J'aime » (conserver uniquement l'icône/état visuel).

* **Système d'actualités et catégories en lecture seule :**
  * Ajouter un filtre « Actualités » dans le fil d'actualité pour diffuser les *changelogs* et nouveautés du site.
  * Ajouter une option dans la création/édition de catégorie forum pour la passer en « Lecture seule ». Seuls les administrateurs pourront y publier des sujets ; les membres et modérateurs conservent uniquement un accès en lecture.

* **Responsive mobile & Nettoyage UI :**
  * Masquer sur mobile les blocs « Dernières discussions », « Mes derniers sujets » et « Explorer les sections ».
  * Supprimer les boutons doublons de la page d'accueil (« Nouveau sujet », « Liste d'armée », « Groupes », « Classement ») déjà présents dans la barre de navigation (*navbar*).

* **Footer et Onboarding :**
  * Ajouter un bouton « Je suis perdu » déclenchant un didacticiel interactif.
  * **Rôles distincts :** la présentation (onboarding existant) sert uniquement à compléter le profil. Le didacticiel est une visite guidée (librairie de *guided tour*) qui explique le fonctionnement de chaque fonctionnalité du site (forum, groupes, galerie, listes d'armée, messagerie, etc.).
  * Ajuster la présentation initiale du site avec la mention finale : *« Si vous êtes perdu, lancez le didacticiel qui vous guidera à travers le site »* (incluant le lien vers le didacticiel).
  * **Logique du bouton de présentation dans le footer :** Masquer le bouton si la présentation a été complétée. Si elle est incomplète, afficher le libellé « Continuer la présentation ».

---

## 4. Profil

* **Épurage de l'interface :** Supprimer les boutons « Invitations » et « Mes listes d'armée ».
* **Galerie photo :** 
  * Effectuer le masquage des photos de manière asynchrone (AJAX) sans rechargement de page.
  * Supprimer le texte explicatif sous la galerie (*« Coche des photos pour les ranger... »*).
* **Interactions sociales :**
  * Permettre la consultation de la liste d'amis d'un tiers en cliquant sur « Amis » depuis son profil public.
  * Définir une couleur unique et distincte réservée aux badges honorifiques.
* **Personnalisation :**
  * Intégrer la gestion d'une image de bannière dans les paramètres (`profil-cover`).
  * Utiliser le visuel par défaut actuel si aucune bannière n'est définie ou si elle est supprimée.

---

## 5. Listes d'armée

* **Statistiques et compteurs :**
  * Ajouter un compteur de vues, un compteur d'exports et un compteur de duplications (en excluant les propres actions de l'auteur de la liste).
* **Sidebar :**
  * Ajouter une colonne à droite affichant les blocs « Listes les plus dupliquées » et « Listes les plus exportées ».

---

## 6. Liste d'amis

* **Suggestions :**
  * Intégrer une colonne de droite proposant 5 à 10 suggestions d'amis basées sur les amis en commun.

---

## 7. Messagerie privée

* **Tri et affichage :**
  * Sur la page listant les conversations, trier les conversations par activité la plus récente (dernier message en haut). La liste doit se réordonner en temps réel à la réception d'un nouveau message, sans rechargement. L'ordre des messages à l'intérieur d'une conversation reste chronologique.
  * Adapter la fenêtre de messagerie instantanée (*sticky* en bas à droite) pour éviter tout chevauchement avec le *footer*.
* **Fonctionnalités de saisie :**
  * Intégrer un sélecteur d'émoticônes (icône SVG casque Space Marine) dans la zone de texte.
  * Permettre la mention d'utilisateurs (`@pseudo`) générant un lien cliquable vers leur profil (sans déclencher de notification push).

---

## 8. Barre de recherche

* **Extension du périmètre :**
  * Inclure l'indexation et la restitution des catégories, sous-catégories et sujets du forum dans les résultats de recherche.

---

## 9. Administration (EasyAdmin)

* **Gestion des signalements :**
  * Rendre l'intégralité de la ligne d'un signalement cliquable pour ouvrir la fiche de détail.
  * **Vue détaillée :** Afficher l'historique complet des signalements concernant l'utilisateur mis en cause, avec accès cliquable au détail de chaque dossier passé.

* **Tableau de bord et statistiques :**
  * Intégrer un indicateur du temps moyen de traitement des signalements.
  * Ajouter un tableau récapitulatif des signalements : motifs fréquents, types de contenus les plus signalés (photos, discussions, autres) et réparations/décisions prises.
  * Appliquer le code couleur suivant pour les sanctions :
    * **Rouge :** Bannissement définitif.
    * **Orange :** Bannissement temporaire.
    * **Jaune :** Contenu supprimé sans bannissement.
  * Afficher les graphiques en bâtons sur 30 jours pour : la rétention, les utilisateurs actifs quotidiennement et les nouvelles inscriptions.

* **Workflow de traitement combiné (Actions multiples) :**
  * Permettre la préparation de plusieurs actions sur une même fiche de signalement (ex. : rédiger un avertissement, définir une durée de suspension avec motif, sélectionner le sort du contenu) sans validation immédiate.
  * Valider l'ensemble des décisions saisies via un bouton unique « Traiter » en bas de page, assorti d'une fenêtre de confirmation.

---

## Explications des modifications et clarifications apportées

1. **Corrections d'incohérences et d'erreurs :**
   * **EasyAdmin :** Rectification de la couleur orange (qui était en doublon avec le rouge pour le bannissement définitif) pour désigner spécifiquement le *bannissement temporaire*.
   * **Numérotation :** Restructuration des sections numérotées en doublon (notamment la section 4) en un fil conducteur fluide de 1 à 9.
   * **Rôle dans les groupes :** Clarification du fait que les rôles sous forme de texte sont supprimés au profit d'un indicateur visuel unique (icône de couronne pour le propriétaire).

2. **Clarifications techniques et ergonomiques :**
   * **Sujets et catégories du forum :** Structuration de la logique d'affichage dynamique (colonne de gauche / zone centrale) selon le statut de la catégorie parente.
   * **Asynchronisme (AJAX) :** Explicitante du comportement sans rechargement de page pour la galerie de profil.
   * **Workflow ToDo et Modération :** Définition étape par étape des interactions complexes (demande d'assignation en attente et validation groupée des signalements).

3. **Optimisation de la forme :**
   * Correction systématique des coquilles et fautes de frappe.
   * Mise en conformité de la typographie et de la structure Markdown.

---

## Découpage en lots

1. **Lot 1 — Finition (fait) :** sections 1, 3 (hors didacticiel), 4, 5, 6, 7 et 8 ; lignes cliquables et code couleur des sanctions en administration. Le sélecteur d'émoticônes et la détection des mentions `@pseudo` sont réalisés une seule fois sous forme de composants communs (forum, groupes, messagerie). La table d'activité quotidienne des membres est créée dès ce lot afin d'alimenter les statistiques du lot 3.
2. **Lot 2 — Groupes :** page en trois colonnes, tri personnalisé, invitations, assignations multiples avec demande et validation, groupe muet.
3. **Lot 3 — Chantiers lourds :** didacticiel en visite guidée, traitement combiné des signalements, statistiques d'activité (rétention, actifs quotidiens, inscriptions).

