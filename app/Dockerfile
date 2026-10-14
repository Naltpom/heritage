# Étape 1 : construction du front avec Vite.
FROM node:26-alpine AS build

WORKDIR /app

# package.json et package-lock.json d'abord : tant qu'ils ne changent pas, la
# couche d'installation des dépendances reste en cache.
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .
RUN npm run build

# L'empreinte des sources du front, gravée DANS ce que nginx servira.
#
# C'est le pendant de `api/docker/empreinte.sh`, et il a fallu le 20 septembre 2026
# pour s'apercevoir qu'il manquait : un déploiement de front pur passait les trois
# portes du flux sans qu'aucune n'ait regardé le front, parce que `sante.php` ne
# porte que l'empreinte de l'image de l'API. Trois calculs indépendants de part et
# d'autre de ce déploiement rendaient la même valeur.
#
# `dist/` est exclu du contexte par `.dockerignore` : le fichier écrit ici ne peut
# donc pas entrer dans le calcul qu'il contient, et il n'y a pas de cycle.
RUN sh empreinte.sh > dist/paquet.txt

# Étape 2 : nginx sert les fichiers statiques et relaie /api vers l'API.
# C'est cette étape qui donne au front et à l'API une origine unique — voir les
# commentaires de nginx.conf, la politique de sécurité en dépend.
FROM nginx:1.27-alpine

COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80
