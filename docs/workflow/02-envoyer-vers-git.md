# 2. Envoyer vers Git (PC → GitHub)

Commandes **PowerShell**, dans le dossier du projet, **une par ligne**.

## 1. Partir d'une base à jour

Avant de modifier quoi que ce soit : [Récupérer depuis Git](01-recuperer-depuis-git.md), section « Mettre à jour le PC ».

Pour un travail long ou risqué, le faire sur une branche à part :

```powershell
git switch -c feature/nom-court
```

Pour une petite correction, travailler directement sur `main` suffit.

## 2. Vérifier avant de committer

```powershell
php bin/console lint:twig templates
php bin/console lint:container
php bin/console doctrine:schema:validate
php bin/phpunit
```

- `doctrine:schema:validate` doit répondre que le mapping et la base sont synchronisés. Si une entité a changé, écrire la migration **à la main** dans `migrations/` (`doctrine:migrations:diff` peut servir de brouillon), puis l'appliquer avec `doctrine:migrations:migrate`.
- Les tests utilisent la base `highlightforge_test`. Après une nouvelle migration, mettre cette base à jour : `php bin/console doctrine:schema:update --env=test --force`.

## 3. Committer

```powershell
git status
git add -A
git commit -m "MODIF - Forum : tri des sujets par activité"
```

Règles des messages :
- en français, préfixés par `AJOUT -` (nouvelle fonctionnalité), `MODIF -` (changement ou correction) ou `SÉCURITÉ -` ;
- un commit par thème : mieux vaut trois commits clairs qu'un seul fourre-tout. Pour n'ajouter que certains fichiers : `git add chemin\du\fichier` au lieu de `git add -A`.

Vérifier qu'aucun secret n'est ajouté : `git status` ne doit jamais lister `.env.local`.

## 4. Pousser

Sur `main` :

```powershell
git pull origin main
git push origin main
```

`git pull` avant `git push` intègre ce qui a été poussé entre-temps depuis une autre machine. En cas de conflit, Git liste les fichiers concernés : les corriger (chercher les marqueurs `<<<<<<<`), puis `git add` et `git commit`.

Sur une branche :

```powershell
git push -u origin feature/nom-court
```

Quand elle est terminée, la fusionner dans `main` :

```powershell
git switch main
git pull origin main
git merge feature/nom-court
git push origin main
```

## 5. Annuler

| Besoin | Commande |
|---|---|
| Abandonner les modifications d'un fichier non commité | `git restore chemin\du\fichier` |
| Retirer un fichier du prochain commit (sans perdre les modifications) | `git restore --staged chemin\du\fichier` |
| Corriger le message du dernier commit **pas encore poussé** | `git commit --amend -m "nouveau message"` |
| Annuler un commit **déjà poussé** | `git revert <identifiant>` puis `git push origin main` (crée un commit inverse, l'historique reste intact) |
