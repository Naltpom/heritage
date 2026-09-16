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
set -e

cd "$(dirname "$0")/.."

{
    [ -f composer.lock ] && cat composer.lock
    find src config public -type f \( -name '*.php' -o -name '*.yaml' \) \
        ! -path 'public/bundles/*' \
        | LC_ALL=C sort \
        | while IFS= read -r fichier; do
            printf '%s\n' "$fichier"
            cat "$fichier"
        done
} | sha256sum | cut -c1-12
