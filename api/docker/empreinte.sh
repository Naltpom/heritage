#!/bin/sh
# Empreinte des sources applicatives — la même formule dans l'image et sur le poste.
#
# RAISON D'ÊTRE. Le conteneur sert le code de son IMAGE, pas celui du dépôt. Les deux
# divergent dès qu'on modifie un fichier sans reconstruire, et RIEN ne le signale : on
# lit un correctif dans l'éditeur, on interroge l'API, elle répond avec le code d'avant.
# Ce projet a perdu deux heures là-dessus, deux fois — une fois sur une configuration de
# journalisation absente de l'image, une fois sur un cache compilé périmé.
#
# On ne peut pas rendre la divergence détectable par le conteneur : il n'a pas accès au
# dépôt. On la rend COMPARABLE EN UNE COMMANDE, ce qui est le mieux atteignable :
#
#     sh docker/empreinte.sh                  # sur le poste, depuis api/
#     curl -s http://127.0.0.1:8000/sante.php # ce que sert le conteneur
#
# Deux valeurs différentes = l'image est périmée, il faut reconstruire.
#
# Ne portent que sur ce que l'image ET le dépôt possèdent : `tests/` est exclu du
# contexte de construction par .dockerignore, il ne peut donc pas entrer dans le calcul.
# `public/bundles/` est produit par `assets:install` à la construction : exclu aussi,
# sinon l'empreinte différerait toujours.
#
# POURQUOI `docker/php.ini` EN FAIT PARTIE (ajouté le 17 septembre 2026)
#
# Il n'y était pas, et c'était un angle mort de l'instrument lui-même : une image
# reconstruite avec d'AUTRES limites PHP rendait la MÊME empreinte. Or ces limites
# décident du sort d'un dépôt — `memory_limit`, `upload_max_filesize`, `post_max_size` ;
# quatre dépôts de preuves sur dix ont échoué en 500 pour cette seule raison. L'outil
# censé répondre « l'image sert-elle bien le dépôt ? » était aveugle à la correction qui
# venait d'être faite.
#
# Il est légitime de l'inclure parce qu'il remplit la condition ci-dessus : il est dans
# l'image ET dans le dépôt, au MÊME chemin relatif. `.dockerignore` n'exclut pas
# `docker/`, le `COPY . .` l'embarque, et c'est d'ailleurs depuis `/app` que le Dockerfile
# exécute ce script. Les octets hachés sont donc les mêmes des deux côtés.
#
# CE QUI RESTE HORS DU CALCUL, ET QU'IL FAUT SAVOIR
#
# `docker/entrypoint.sh` — il est dans l'image, il décide de l'attente de la base et du
# jeu des migrations, et le modifier ne change toujours pas l'empreinte. Le motif de
# recherche est nommément `php.ini` et non `*.ini` ni `docker/*` : élargir est une
# décision, pas un réflexe, et elle n'a pas été prise ici.
set -e

cd "$(dirname "$0")/.."

{
    [ -f composer.lock ] && cat composer.lock
    # Un seul flux, un seul tri : `docker/php.ini` se range à sa place alphabétique
    # comme les autres, et l'ordre reste indépendant de celui que rend `find`.
    find src config public docker -type f \
        \( -name '*.php' -o -name '*.yaml' -o -name 'php.ini' \) \
        ! -path 'public/bundles/*' \
        | LC_ALL=C sort \
        | while IFS= read -r fichier; do
            printf '%s\n' "$fichier"
            cat "$fichier"
        done
} | sha256sum | cut -c1-12
