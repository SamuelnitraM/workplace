# Design system SprueHub

Référence pour restyler les pages. Démo vivante (dev uniquement) : **`/_styleguide`**.
Source : `assets/styles/app.css` (tokens `@theme` + `@layer components`), icônes `templates/_partials/_icon.html.twig`,
macros `templates/_macros/ui.html.twig`, shell `templates/base.html.twig` + `templates/_partials/shell/`.
Après modification du CSS : `php bin/console tailwind:build`.

## Principes

1. **Hiérarchie** : un seul `text-h1` par page (dans `.page-header`), puis `.section-title` (h2), puis `text-h3`. Le texte courant est en `text-fg-secondary`, les métadonnées en `text-muted`.
2. **Une action primaire par écran** (`.btn-primary`). Le reste : `.btn-secondary`, `.btn-ghost` ou `.btn-link`. Actions destructrices : `.btn-danger` (ou `.menu-item-danger` dans un menu).
3. **Espacements** : échelle de 4 px de Tailwind. Rythme : `gap-2` entre éléments liés, `gap-4` entre cartes, `space-y-8`/`--spacing-section` (2 rem) entre sections. Padding de carte : `.card-body` (16 px mobile, 20 px ≥ 640 px).
4. **Surfaces** : `bg-canvas` (fond) → `.card` / `bg-surface` → `bg-surface-raised` (survol, bloc imbriqué) → `bg-overlay` (menus, toasts). Jamais plus de 2 niveaux de cartes imbriquées.
5. **Couleurs sémantiques** : indigo = marque et actions ; laiton (`accent`) = récompenses, XP, premium — avec parcimonie ; `success` / `warning` / `danger` / `info` uniquement pour un état. Toujours doubler la couleur d'un texte ou d'une icône (pas d'information par la couleur seule).
6. **Accessibilité** : focus visible hérité (`:focus-visible`, anneau `--color-focus`) — ne jamais mettre `outline-none` sans remplacement ; cibles ≥ 44 px sur mobile (les `.btn`, `.menu-item`, `.tab` s'agrandissent en `pointer: coarse`) ; `.btn-icon` toujours avec `aria-label` ; icônes décoratives en `aria-hidden` (défaut du macro) ; mouvement réduit respecté globalement.
7. **Mobile d'abord** : la barre d'onglets du bas occupe 60 px + safe-area ; le `body` a déjà le padding nécessaire (`.has-bottom-bar`). Ne pas créer d'autres éléments `fixed bottom-0` sur mobile.

## Tokens

### Couleurs (utilitaires : `bg-*`, `text-*`, `border-*`)

| Token | Valeur | Usage |
|---|---|---|
| `canvas` | #0d1016 | fond de l'application |
| `surface` | #151a23 | cartes, panneaux |
| `surface-raised` | #1c222d | survol, bloc sur carte |
| `overlay` | #242b38 | menus, toasts, feuilles |
| `line` | #2c3444 | séparateurs, bordures de cartes |
| `line-strong` | #5a647a | bordures de champs (3,1:1 sur surface) |
| `fg` / `fg-secondary` / `fg-muted` (= `muted`) | #e9ecf2 / #b3bccb / #8e98aa | texte principal / secondaire / métadonnées |
| `primary` (+`-hover`, `-fg`, `-text`, `-soft`) | #4f46e5 | marque (texte sur fond sombre : `text-primary-text` #a5b4fc) |
| `accent` (+`-hover`, `-fg`, `-text`, `-soft`) | #d4a24c | laiton : XP, récompenses |
| `success` · `warning` · `danger` · `info` (+`-hover`, `-text`, `-soft`) | #15803d · #f59e0b · #dc2626 · #0369a1 | états |
| `notify` | #e11d48 | pastilles de compteur |
| `focus` | #a5b4fc | anneau de focus |

**Contrastes mesurés** (WCAG 2.x, script node + recalcul PHP dans `/_styleguide`) :

| Texte | canvas | surface | surface-raised | overlay |
|---|---|---|---|---|
| text-fg | 16,09 | 14,74 | 13,48 | 12,01 |
| text-fg-secondary | 9,95 | 9,11 | 8,34 | 7,43 |
| text-fg-muted | 6,55 | 6,00 | 5,49 | 4,89 |
| text-primary-text | 9,55 | 8,75 | 8,00 | 7,13 |
| text-accent-text | 9,70 | 8,88 | 8,12 | 7,24 |
| text-success-text | 10,93 | 10,01 | 9,16 | 8,16 |
| text-warning-text | 11,41 | 10,45 | 9,56 | 8,51 |
| text-danger-text | 7,34 | 6,72 | 6,15 | 5,48 |
| text-info-text | 11,42 | 10,46 | 9,57 | 8,52 |

Aplats (texte dessus) : primary 6,29 · primary-hover 5,25 · accent 8,05 · success 5,02 · success-hover 7,13 · warning 8,67 · danger 4,83 · danger-hover 6,47 · info 5,93 · notify 4,70.
Fonds `-soft` : texte `-text` correspondant ≥ 4,8:1 (sur surface et overlay). `line-strong` / surface : 3,1:1 ; focus / canvas : 9,55:1.

> Les anciennes classes `text-gray-500` (#6b7280 : 3,6:1 sur gray-800) et `text-gray-600` ne passent **pas** AA : remplacer par `text-muted`.

### Typographie (`text-*` : taille + interligne + graisse)

| Classe | Taille | Usage |
|---|---|---|
| `text-display` | 30→42 px, 800 | accroche d'accueil uniquement |
| `text-h1` | 24→30 px, 700 | titre de page (`.page-header-title` l'applique) |
| `text-h2` | 20 px, 650 | titre de section (`.section-title`) |
| `text-h3` | 17 px, 600 | titre de carte |
| `text-body` | 16 px / 1,6 | texte courant |
| `text-small` | 14 px | métadonnées, boutons |
| `text-caption` | 12 px | horodatages, compteurs ; `.eyebrow` = caption en capitales |

Police : `Inter` si installée, sinon police système (aucun téléchargement).

### Autres tokens

- Rayons : `rounded-control` (8 px : boutons, champs), `rounded-card` (12 px : cartes, menus, toasts), `rounded-pill`.
- Ombres : `shadow-raised` (cartes), `shadow-overlay` (menus, toasts), `shadow-modal` (feuilles).
- Largeurs : `max-w-form` (32 rem), `max-w-reading` (48 rem), `max-w-app` (80 rem) ; `container-app` = max-w-app centré + gouttière (déjà appliqué à `<main>`).
- Z-index (variables, pas de valeur en dur) : `--z-sticky` 20, `--z-header`/`--z-bottom-bar` 40, `--z-messenger` 45, `--z-dropdown` 50, `--z-toast` 60, `--z-modal` 70 → `class="z-(--z-dropdown)"`.
- Mouvement : `--duration-fast` 120 ms, `--duration-base` 180 ms, `--duration-slow` 300 ms, `ease-standard` ; tout est neutralisé par `prefers-reduced-motion`.
- Shell : `--header-h` (56/64 px), `--bottom-bar-h` (60 px).

## Composants (extraits)

```twig
{% import '_macros/ui.html.twig' as ui %}

{# En-tête de page : 1 action primaire #}
<header class="page-header">
  <div>
    <p class="page-header-eyebrow"><a class="link" href="…">Forum</a>{{ ui.icon('chevron-right', 'size-4') }}Peinture</p>
    <h1 class="page-header-title">Peinture</h1>
    <p class="page-header-subtitle">Techniques et schémas de couleurs.</p>
  </div>
  <div class="page-header-actions">
    <a class="btn btn-ghost" href="…">{{ ui.icon('filter') }}Filtrer</a>
    <a class="btn btn-primary" href="…">{{ ui.icon('plus') }}Nouveau sujet</a>
  </div>
</header>

{# Boutons : .btn + variante (+ taille) #}
<button class="btn btn-primary">Enregistrer</button>
<button class="btn btn-secondary btn-sm">Annuler</button>
<button class="btn btn-ghost btn-icon" aria-label="Modifier">{{ ui.icon('pencil') }}</button>
<button class="btn btn-danger">{{ ui.icon('trash') }}Supprimer</button>

{# Carte + liste #}
<section class="card">
  <div class="card-header"><h2 class="text-h3">Sujets récents</h2><a class="btn btn-link btn-sm" href="…">Tout voir</a></div>
  <a class="list-row" href="…">
    {{ ui.avatar(thread.author, 'sm') }}
    <div class="list-row-main"><p class="font-medium truncate">{{ thread.title }}</p><p class="text-caption text-muted">par …</p></div>
    <span class="chip">{{ ui.icon('message-circle') }}12</span>
  </a>
</section>
<a class="card card-interactive block" href="…"><div class="card-body">…</div></a>

{# Formulaire #}
<div class="field">
  <label class="label" for="email">E-mail</label>
  <input class="input" id="email" aria-invalid="true" aria-describedby="email-err">
  <p class="field-error" id="email-err">{{ ui.icon('alert-circle') }}Adresse invalide.</p>
</div>
<label class="check"><input type="checkbox" class="checkbox"> Afficher mon activité</label>

{# Onglets (tabs_controller : aria-selected suffit, plus besoin de data-tabs-active-class) #}
<div data-controller="tabs">
  <div class="tabs" role="tablist">
    <button type="button" role="tab" class="tab" aria-selected="true" data-tab="gallery" data-tabs-target="tab" data-action="tabs#select">{{ ui.icon('camera') }}Galerie</button>
  </div>
  <div data-tabs-target="panel" data-panel="gallery">…</div>
</div>

{# Retours #}
<div class="alert alert-warning">{{ ui.icon('alert-triangle') }}<p>E-mail non vérifié.</p></div>
<span class="count-badge">3</span> · <span class="chip chip-accent">{{ ui.icon('crown') }}Premium</span>
<div class="progress" role="progressbar" aria-valuenow="40" aria-valuemin="0" aria-valuemax="100" aria-label="Progression"><div class="progress-bar" style="width:40%"></div></div>
<div class="skeleton skeleton-text w-1/2"></div>

{# État vide (icône = nom du jeu d'icônes) #}
{{ include('_partials/_empty_state.html.twig', {icon: 'camera', title: 'Galerie vide', text: '…',
   cta: {label: 'Ajouter une photo', url: path('…'), icon: 'image-plus'}}) }}
```

Liste complète : bloc de commentaire en tête de la section « 5. COMPOSANTS » d'`app.css` — boutons, cartes, champs, chips/pills, count-badge, avatars (xs→xl), tabs (+ `.tabs-pills`), page-header, section-title, eyebrow, list-row, dropdown/menu, sheet (feuille mobile), alert, toast, skeleton, divider, kbd, progress, empty-state, link, skip-link.

Les composants sont dans `@layer components` : **tout utilitaire Tailwind les surcharge** (`class="btn btn-primary w-full"`). Ne pas écrire de nouveau CSS hors couche (il écraserait les utilitaires).

## Icônes

`{{ ui.icon('bell') }}` · `{{ ui.icon('trash', 'size-4') }}` · `{{ ui.icon('lock', 'size-4', 'Groupe privé') }}` (libellé → `role="img"`).
Include direct possible : `{{ include('_partials/_icon.html.twig', {name: 'home', class: 'size-5'}, with_context = false) }}` (garder `with_context = false`, sinon une variable `label`/`class` de la page fuit dans l'icône).

69 icônes (style Lucide, ISC) : home, compass, search, menu, plus, x, check, chevron-down/up/left/right, arrow-left/right, external-link, more-horizontal/vertical, filter, log-in, log-out, settings, user, user-plus, user-check, users, bell, mail, message-circle, message-square, messages-square, send, heart, thumbs-up, star, pin, trophy, medal, crown, flame, sparkles, sword, swords, shield, shield-check, paintbrush, list-checks, clipboard-list, file-text, folder, image, image-plus, camera, upload, pencil, trash, eye, eye-off, lock, globe, calendar, clock, bar-chart, layout-grid, check-circle, x-circle, alert-circle, alert-triangle, info, help-circle, loader. Alias : edit, trash-2, house, ellipsis, close, brush, circle-check…
Taille : `size-4` dans le texte courant et les chips, `size-5` dans les boutons-icônes (géré par `.btn > svg`).
Ajouter une icône : copier les éléments SVG de lucide.dev dans le tableau `_icons` du partial.

## À faire / à éviter

| À faire | À éviter |
|---|---|
| `btn btn-primary` | `bg-indigo-600 hover:bg-indigo-700 px-4 py-2 rounded-lg …` |
| `ui.icon('trophy')` | emojis comme icônes (rendu variable selon l'OS) — gardés seulement dans le contenu utilisateur ou les badges |
| `text-muted` pour les métadonnées | `text-gray-500/600` (contraste insuffisant) |
| `card` / `bg-surface` | `bg-gray-800/50`, `bg-gray-700/30`… (fonds disparates) |
| un seul `.btn-primary` visible par zone | plusieurs boutons indigo côte à côte |
| `rounded-control` / `rounded-card` | `rounded`, `rounded-md`, `rounded-2xl` mélangés |
| `z-(--z-dropdown)` | `z-50` en dur |
| `aria-current="page"` / `aria-selected` pour l'état actif | classes d'état dupliquées en JS |

## Carte de migration (ancien → nouveau)

| Ancien | Nouveau |
|---|---|
| `bg-indigo-600 hover:bg-indigo-700 (text-white) px-4 py-2 rounded-lg` | `btn btn-primary` |
| `… px-3 py-1.5 text-sm rounded` (petit bouton) | `btn btn-primary btn-sm` |
| `bg-gray-700 hover:bg-gray-600 px-4 py-2 rounded-lg` | `btn btn-secondary` |
| `text-indigo-400 hover:text-indigo-300` (bouton texte) | `btn btn-link` ; lien dans un texte : `link` |
| `bg-green-600 hover:bg-green-700 …` | `btn btn-success` (validation) — ou `btn btn-primary` si c'est l'action principale |
| `bg-red-600 hover:bg-red-700 …` / `bg-red-600/30 text-red-300` | `btn btn-danger` / `btn btn-ghost text-danger-text` |
| `text-gray-400 hover:text-white p-2 rounded` (icône seule) | `btn btn-ghost btn-icon` + `aria-label` |
| `bg-gray-800 border border-gray-700 rounded-xl (p-6)` | `card` (+ `card-body`) |
| `bg-gray-800 … hover:bg-gray-700 hover:border-gray-600` (carte cliquable) | `card card-interactive` |
| `bg-gray-900` / `bg-gray-700/50` (bloc dans une carte) | `card-inset` ou `bg-surface-raised rounded-control` |
| `w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:…` | `input` (`select`, `textarea`) |
| `block text-sm text-gray-300 mb-1` (label) | `label` dans un `field` |
| `text-red-400 text-sm` (erreur de champ) | `field-error` |
| `text-xs text-gray-500` | `text-caption text-muted` |
| `text-sm text-gray-400` | `text-small text-fg-secondary` (ou `text-muted` pour une métadonnée) |
| `text-3xl font-bold` / `text-2xl font-bold` (titre de page) | `page-header` > `page-header-title` |
| `text-xl font-semibold mb-4` (titre de section) | `section-title` |
| `bg-red-500 text-white text-xs rounded-full px-1.5` | `count-badge` |
| `bg-indigo-900/40 text-indigo-300 text-xs px-2 py-0.5 rounded-full` | `chip chip-primary` (et `chip-accent`, `chip-success`…) |
| `w-10 h-10 rounded-full bg-indigo-600 flex items-center justify-center font-bold` + `<img>` | `ui.avatar(user, 'md')` |
| `px-4 py-3 border-b-2 border-indigo-500 text-white` / `border-transparent text-gray-400` (onglets) | `tab` dans `tabs` (état via `aria-selected`) |
| `bg-green-700 / bg-red-700 / bg-blue-700 px-4 py-3 rounded` (message) | `alert alert-success|danger|info` + icône |
| `absolute right-0 mt-2 w-48 bg-gray-800 border … rounded-xl shadow-lg` + liens | `menu` > `menu-item` (+ `menu-separator`) |
| `animate-pulse bg-gray-700 h-4 rounded` | `skeleton skeleton-text` |
| `h-2 bg-gray-700 rounded-full` + barre `bg-indigo-500` | `progress` > `progress-bar` |
| `border-t border-gray-700 my-4` | `divider` |
| `max-w-md mx-auto` (formulaire) / `max-w-3xl mx-auto` (lecture) | `container-form` / `container-reading` |
| emoji `👥 📷 ⚔️ 🏆 🔔 💬 ✏️ 🗑️ 📌 🔒` | `users`, `camera`, `swords`, `trophy`, `bell`, `message-circle`, `pencil`, `trash`, `pin`, `lock` |

## Shell (déjà en place — ne pas dupliquer dans les pages)

- `base.html.twig` calcule `shell_section` (home, forum, groups, leaderboard, messages, notifications, login, register, me) pour `aria-current` ; nouvelle rubrique = ajouter un préfixe de route dans ce bloc.
- En-tête desktop : logo, Forum / Groupes / Classement, recherche, **Publier** (menu : nouveau sujet, photo → `profil#gallery`, liste d'armée), messages (compteur), notifications, menu du compte (XP, profil, tâches, listes d'armée, amis, messages, classement, paramètres, administration, déconnexion).
- Mobile : barre du haut (logo, loupe, messages, notifications) + barre d'onglets du bas (Accueil, Forum, Publier, Groupes, Menu — visiteur : Accueil, Forum, Groupes, Classement, Connexion). Le messenger flottant est masqué < 768 px.
- Messages flash : toasts (`_partials/shell/_toasts.html.twig`), types `success|error|warning|info`.
- Pages : ne pas remettre de `<main>` ni de conteneur `max-w-7xl mx-auto px-*` — `<main id="main" class="container-app py-8">` est fourni ; utiliser `container-reading`/`container-form` à l'intérieur si besoin.

## Fenêtres modales

`<dialog class="modal">` ouvert par `showModal()` (contrôleur Stimulus `modal`, ou Alpine via `$refs`). Structure : `.modal-header` (`.modal-title` + bouton de fermeture), `.modal-body` (défile), `.modal-footer` (actions). Variantes : `.modal-wide` (fiches techniques), `.modal-media` (visionneuse d'image : fond noir, image à sa taille maximale, bouton `.modal-close`). Échap ferme la fenêtre ; un clic sur le fond aussi (`click->modal#closeOnBackdrop`).

```twig
<div data-controller="modal">
  <button data-action="modal#open" data-modal-id-param="ma-fenetre">Ouvrir</button>
  <dialog id="ma-fenetre" class="modal" aria-labelledby="ma-fenetre-titre" data-action="click->modal#closeOnBackdrop">
    <div class="modal-header"><p class="modal-title" id="ma-fenetre-titre">Titre</p>
      <button class="btn btn-ghost btn-icon btn-sm" data-action="modal#close" aria-label="Fermer">{{ ui.icon('x') }}</button></div>
    <div class="modal-body">…</div>
    <div class="modal-footer"><button class="btn btn-primary" data-action="modal#close">Compris</button></div>
  </dialog>
</div>
```

## Cartes de choix avec description

`.choice-card` accepte une icône, un titre et une ligne d'explication (formulaire de signalement, type de liste d'armée) ; le bouton radio peut être masqué (`sr-only`), l'état coché reste visible par la bordure et le fond.

## Infobulles

`data-tooltip="Texte"` sur un bouton ou un lien : bulle affichée au survol et au focus clavier (masquée sur les écrans tactiles). Garder un `aria-label` : l'infobulle est purement visuelle.

## Sélecteur d'émoticônes

`<div data-controller="emoji-picker" data-emoji-picker-input-value="id-du-champ"></div>` : le contrôleur construit lui-même le bouton (casque de Space Marine) et le panneau. `data-emoji-picker-placement-value="down"` ouvre le panneau vers le bas (barre d'outils en haut d'un champ). Sans `input`, l'émoticône va dans le premier champ texte du formulaire parent. La liste est dans `assets/lib/emoji.js`.

## Réactions

`.reaction .reaction-positive` (bleu *primary*) et `.reaction .reaction-helpful` (rouge) sur un `.btn-toggle` ou un `.chip` : icône et compteur seuls, le libellé passe dans `aria-label`.

## Carrousel de photos

`templates/home/_photo_carousel.html.twig` (contrôleur `carousel`) : liste à défilement horizontal aimanté, trois photos visibles, boutons précédent / suivant désactivés aux extrémités.

## Paliers de badges

`.badge-tier-bronze|silver|gold|premium|honorary` : les badges honorifiques (sans XP) ont leur propre couleur, turquoise.

## Suggestions de mention

`<div data-controller="mention-suggest">` autour d'un champ `data-mention-suggest-target="input"` avec les actions `keydown->mention-suggest#onKeydown input->mention-suggest#onInput blur->mention-suggest#close`, déclarées **avant** les autres actions du champ (Entrée choisit le membre au lieu d'envoyer). Liste au-dessus du champ par défaut, `data-mention-suggest-placement-value="inside"` pour une grande zone de texte.

## Recadrage d'image

`templates/_partials/_crop_dialog.html.twig` dans un bloc `data-controller="profile-image"` (valeur `aspect-ratio`) : le fichier choisi s'ouvre dans la fenêtre de recadrage, le cadre part avec le formulaire (`<type>_crop`).


## Interrupteur

`<button type="submit" role="switch" aria-checked="true|false" class="switch"><span class="switch-thumb"></span></button>` dans un formulaire POST, avec un libellé relié par `aria-labelledby` : tri des groupes, sourdine d'un groupe.

## Liste réordonnable

`stimulus_controller('sortable', {url, token})` sur la liste, `data-sortable-target="item"`, `data-id` et `draggable="true"` sur chaque ligne, poignée `.sortable-handle` (icône `grip-vertical`) et boutons `sortable#moveUp` / `sortable#moveDown` pour le clavier et le tactile. Chaque déplacement envoie l'ordre complet (`ids[]`) en POST.

## Pile d'avatars

`.avatar-stack` : avatars `xs` qui se chevauchent (suggestions de groupes, assignés d'une catégorie). Trois au plus, complétés par un texte (« 4 amis sont dans ce groupe »).
