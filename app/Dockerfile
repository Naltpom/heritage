# Étape 1 : construction du front avec Vite.
FROM node:22-alpine AS build

WORKDIR /app

# package.json et package-lock.json d'abord : tant qu'ils ne changent pas, la
# couche d'installation des dépendances reste en cache.
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .
RUN npm run build

# Étape 2 : nginx sert les fichiers statiques et relaie /api vers l'API.
# C'est cette étape qui donne au front et à l'API une origine unique — voir les
# commentaires de nginx.conf, la politique de sécurité en dépend.
FROM nginx:1.27-alpine

COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80
