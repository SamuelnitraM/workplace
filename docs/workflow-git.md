# Workflow Git

Mémo des commandes pour une nouvelle fonctionnalité. Ce n'est pas un script : aucun fichier `git.bat` ne doit exister
à la racine du projet, car `cmd.exe` (utilisé par Composer et Symfony) exécuterait ce fichier à la place du vrai Git.

```bash
# 1. Toujours partir de main à jour
git checkout main
git pull origin main

# 2. Créer la nouvelle branche
git checkout -b feature/forum

# 3. Coder...

# 4. Commit régulièrement
git add .
git commit -m "Forum : ajout des entités Category et Thread"

# 5. Quand la feature est terminée, merger dans main
git checkout main
git merge feature/forum
git push origin main
```
