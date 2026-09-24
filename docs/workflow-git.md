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