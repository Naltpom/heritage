# API Symfony servie par FrankenPHP (Caddy + PHP embarqué).
#
# Construction en plusieurs étages. Deux cibles utiles :
#
#   docker build --target dev  .   → paquets de développement inclus
#   docker build                .   → production, sans paquets de développement
#
# L'étage `prod` est VOLONTAIREMENT le dernier du fichier : c'est lui que Docker
# choisit quand aucune `target` n'est demandée. `compose.prod.yaml` se superpose à
# `compose.yaml` et hérite de son `build:` sans redéclarer de cible — si `dev`
# était le dernier étage, la production partirait avec maker-bundle, phpunit et
# php-cs-fixer sans que personne ne l'ait décidé. L'ordre de ce fichier est donc
# une garantie, pas une mise en page.

# =============================================================================
#  Socle commun aux deux cibles
# =============================================================================
FROM dunglas/frankenphp:1-php8.4 AS base

# pdo_pgsql : PostgreSQL.
# intl      : tri et comparaison des noms français (accents), formats de dates.
# gd        : vignettes et ré-encodage des images, qui purge au passage les
#             métadonnées EXIF — une photo de famille non nettoyée publie les
#             coordonnées GPS du domicile.
# exif      : lecture de l'orientation avant ré-encodage.
# zip       : archives d'export GEDCOM + médias.
# opcache   : performances en production.
RUN install-php-extensions \
    pdo_pgsql \
    intl \
    gd \
    exif \
    zip \
    opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# LES LIMITES DE PHP, ÉCRITES PLUTÔT QUE SUBIES.
#
# L'image de base n'active aucun php.ini : elle livre `php.ini-development` et
# `php.ini-production` sans en choisir un. Relevé dans le conteneur en service,
# le 16 septembre 2026 : « Loaded Configuration File => (none) », memory_limit
# 128M, upload_max_filesize 2M, post_max_size 8M — les valeurs COMPILÉES de PHP,
# que personne n'a décidées. Quatre dépôts de preuves sur dix échouaient en 500.
#
# Le fichier va dans le répertoire additionnel que PHP balaie
# (`/usr/local/etc/php/conf.d`), sous un nom qui passe APRÈS les
# `docker-php-ext-*.ini` déjà présents : le balayage est alphabétique, et le
# dernier lu gagne.
#
# Dans `base`, donc dans `dev` ET dans `prod` : un défaut de limite qui ne se
# reproduit pas en développement est un défaut qu'on ne diagnostique qu'en
# production.
#
# Ce fichier entre dans `docker/empreinte.sh` depuis le 17 septembre 2026 : une
# image reconstruite avec d'autres limites PHP ne peut plus rendre la même
# empreinte. Cela prouve que ces octets sont partis dans l'image — pas que PHP
# les a lus. Le contrôle qui l'atteste, lui, interroge `php -i` dans le
# conteneur : `.github/scripts/verifier-les-limites-php.sh`. Les deux sont
# nécessaires, et ils ne répondent pas à la même question.
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-heritage.ini

WORKDIR /app

COPY docker/entrypoint.sh /usr/local/bin/heritage-entrypoint
RUN chmod +x /usr/local/bin/heritage-entrypoint

# 8000 et non 8080 : `app/nginx.conf` écrit `proxy_pass http://api:8000` en dur,
# et `app/.env.example` pointe le proxy de Vite au même endroit. Le front est
# câblé sur 8000 à deux endroits ; c'est l'image qui doit s'aligner, pas
# l'inverse. Un écart ici donne un 502 sur tout /api, sans autre indice.
EXPOSE 8000

# L'image dunglas/frankenphp hérite d'une sonde de son image de base :
# `curl -f http://localhost:2019/metrics`, le point d'administration de Caddy.
# Or `frankenphp php-server` le désactive — le journal de démarrage dit
# « admin endpoint disabled ». Cette sonde-là échoue donc toujours, et le
# conteneur bascule `unhealthy` alors que l'API répond parfaitement : une alarme
# permanente qu'on apprend à ignorer, et tout `service_healthy` qui viserait
# l'API attendrait indéfiniment. On la redéfinit ici pour que l'image soit juste
# toute seule, et pas seulement sous un compose.yaml particulier.
#
# La cible est `public/sante.php`, PAS la racine de l'application.
#
# La sonde visait `http://localhost:8000/`, où aucune route ne répond : elle
# fabriquait une NotFoundHttpException toutes les dix secondes, et ces fausses
# erreurs étaient le seul contenu du journal — deux cents lignes à traverser pour
# en trouver une utile. Une sonde qui remplit le journal d'erreurs qu'elle a
# elle-même provoquées rend illisible ce qu'on essaie d'y lire.
#
# `sante.php` est servi SANS amorcer le noyau : il atteste que Caddy écoute et que
# PHP s'exécute, et rien d'autre. `curl -f` redevient donc légitime — la cible rend
# vraiment 200 —, alors que l'ancienne obligeait à accepter n'importe quel code.
#
# À RESSERRER vers `/api/sante` quand le module Core l'exposera, si l'on veut une
# sonde qui atteste aussi que le noyau démarre et que la base répond — en sachant
# qu'elle fera alors redémarrer le conteneur pour des pannes qui ne sont pas les
# siennes. Le choix devra être explicite.
#
# start-period large : l'entrypoint attend la base (jusqu'à 60 s) puis joue les
# migrations avant que le serveur se mette à écouter.
HEALTHCHECK --interval=10s --timeout=3s --start-period=90s --retries=6 \
    CMD curl -fsS -o /dev/null http://localhost:8000/sante.php || exit 1

ENTRYPOINT ["heritage-entrypoint"]
CMD ["frankenphp", "php-server", "--listen", ":8000", "--root", "public/"]

# =============================================================================
#  Cible de développement — garde les paquets de développement
# =============================================================================
#
# Sert à travailler et à outiller dans le conteneur : maker-bundle, phpunit,
# phpstan, php-cs-fixer. À demander explicitement par `target: dev`.
#
# Note : `.dockerignore` exclut `tests/`, `phpunit.dist.xml` et `phpstan.neon`
# du contexte de construction — il est commun à tous les étages, on ne peut pas
# l'assouplir pour le seul étage de développement sans alourdir aussi l'image de
# production. Les binaires de test sont donc là, pas les fichiers de test : on
# lance la suite depuis l'hôte, ou par un montage de l'arborescence.
FROM base AS dev

ENV APP_ENV=dev

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-scripts --no-interaction --prefer-dist --no-progress

COPY . .

# CE QUE CETTE IMAGE CONTIENT, GRAVÉ DEDANS.
#
# Le conteneur sert le code de son image, pas celui du dépôt. Les deux divergent
# dès qu'on modifie un fichier sans reconstruire, et RIEN ne le signale : on lit un
# correctif dans l'éditeur, on interroge l'API, elle répond avec le code d'avant.
# Deux heures y sont passées, deux fois — une configuration de journalisation absente
# de l'image, puis un conteneur compilé périmé.
#
# On ne peut pas rendre la divergence détectable par le conteneur : il n'a pas accès
# au dépôt. On la rend COMPARABLE EN UNE COMMANDE. L'entrypoint annonce ces deux
# valeurs au démarrage, `public/sante.php` les expose, et `sh docker/empreinte.sh`
# calcule la même chose sur le dépôt. Deux valeurs différentes = image périmée.
RUN composer dump-autoload \
    && mkdir -p var uploads /usr/local/share/heritage \
    && sh docker/empreinte.sh > /usr/local/share/heritage/empreinte \
    && date -u +%Y-%m-%dT%H:%M:%SZ > /usr/local/share/heritage/construite-le

# =============================================================================
#  Cible de production — DERNIER ÉTAGE, donc cible par défaut
# =============================================================================
FROM base AS prod

ENV APP_ENV=prod

# Dépendances d'abord : le cache Docker évite de réinstaller à chaque changement de code.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress

COPY . .

# CE QUE CETTE IMAGE CONTIENT, GRAVÉ DEDANS.
#
# Le conteneur sert le code de son image, pas celui du dépôt. Les deux divergent
# dès qu'on modifie un fichier sans reconstruire, et RIEN ne le signale : on lit un
# correctif dans l'éditeur, on interroge l'API, elle répond avec le code d'avant.
# Deux heures y sont passées, deux fois — une configuration de journalisation absente
# de l'image, puis un conteneur compilé périmé.
#
# On ne peut pas rendre la divergence détectable par le conteneur : il n'a pas accès
# au dépôt. On la rend COMPARABLE EN UNE COMMANDE. L'entrypoint annonce ces deux
# valeurs au démarrage, `public/sante.php` les expose, et `sh docker/empreinte.sh`
# calcule la même chose sur le dépôt. Deux valeurs différentes = image périmée.
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p var uploads /usr/local/share/heritage \
    && sh docker/empreinte.sh > /usr/local/share/heritage/empreinte \
    && date -u +%Y-%m-%dT%H:%M:%SZ > /usr/local/share/heritage/construite-le
