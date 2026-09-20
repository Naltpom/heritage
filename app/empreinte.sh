#!/bin/sh
# Empreinte des sources du FRONT — la même formule dans l'image et sur le poste.
#
# RAISON D'ÊTRE, ET ELLE A UNE DATE. Le 20 septembre 2026, un déploiement de front pur
# est parti, a réussi, et les trois portes du flux l'ont validé — sans qu'aucune n'ait
# regardé le front. `sante.php` sert l'empreinte de l'IMAGE DE L'API : elle ne bouge pas
# d'un caractère quand seul `app/` change. Mesuré ce jour-là, trois calculs indépendants
# de part et d'autre du déploiement rendaient tous `f73c498b467f`. La porte publique a
# donc comparé une valeur à elle-même, c'est-à-dire une égalité garantie d'avance.
#
# La couverture de l'empreinte de l'API, mesurée le même jour : 411 fichiers hachés, tous
# sous `api/`. **Zéro des 263 fichiers de `app/`.**
#
# Ce script est l'autre moitié. Il répond à la seule question que l'autre ne pose pas :
# « le front servi est-il celui que ce dépôt produit ? »
#
#     sh empreinte.sh                        # sur le poste, depuis app/
#     curl -s https://<domaine>/paquet.txt   # ce que sert la pile
#
# Deux valeurs différentes = l'image du front est périmée, ou ce n'est pas elle qu'on sert.
#
# ⚠ SUR UN POSTE WINDOWS, LA PREMIÈRE COMMANDE MENT — ET C'EST MESURÉ
#
# Cette formule hache les **octets tels que `git` les a écrits**. Sur un poste où
# `core.autocrlf` rend les fichiers texte en CRLF, ce ne sont pas les mêmes octets
# que dans l'image, qui est construite sur Linux. Relevé le 20/09/2026 sur le poste
# du commanditaire : **quatre des fichiers hachés portaient des CRLF**, la commande
# rendait `d15a8c54f877` quand l'image et le dépôt Linux s'accordaient sur
# `0f40734b54af`. **Un écart de cent pour cent, sur une pile parfaitement saine.**
#
# Depuis un tel poste, comparer avec les fins de ligne normalisées :
#
#     { for f in package-lock.json package.json index.html vite.config.ts \
#               tsconfig.json nginx.conf; do
#           printf '%s\n' "$f"; tr -d '\r' < "$f"
#       done
#       find src public -type f ! -name '*.sh' | LC_ALL=C sort \
#       | while IFS= read -r f; do printf '%s\n' "$f"; tr -d '\r' < "$f"; done
#     } | sha256sum | cut -c1-12
#
# **La formule n'est délibérément PAS normalisée**, et ce n'est pas un oubli : un
# `tr -d '\r'` appliqué à tout mangerait aussi les octets `0x0D` des fichiers
# binaires de `public/` — polices, images —, et deux binaires qui ne diffèrent que
# par ces octets rendraient la même empreinte. Les trois calculs qui décident
# (l'image, le clone du VPS, le coureur d'intégration) tournent tous sur Linux et
# s'accordent ; c'est le mode d'emploi manuel qui devait porter la réserve, pas la
# formule.
#
# ── Et cet écart a prouvé quelque chose qu'aucun vert n'aurait prouvé ──────────
#
# Si la valeur du flux avait été recopiée depuis l'image au lieu d'être recalculée,
# la divergence Windows/Linux n'aurait **jamais pu apparaître**. Elle est la preuve
# que les deux côtés lisent bien des octets, chacun les siens.
#
# POURQUOI LES SOURCES ET NON LE PAQUET CONSTRUIT
#
# Le nom du paquet (`index-BhpKRW2Z.js`) est déjà une empreinte de contenu, et il aurait
# suffi à voir ce défaut-là. Mais le dépôt ne peut pas le PRÉDIRE sans construire : il
# faudrait installer les dépendances et lancer Vite dans le flux, une minute de plus et
# une source de faux rouges le jour où deux versions mineures de Node ne rendent pas le
# même octet. Hacher les sources donne la même garantie et se calcule en une seconde, des
# deux côtés, sans rien installer.
#
# CE QUI EST HACHÉ, ET POURQUOI CHAQUE ENTRÉE
#
#   package-lock.json  les versions exactes des dépendances — deux arbres de modules
#                      différents produisent deux paquets différents ;
#   package.json       les scripts de construction ;
#   index.html         la coque, que Vite réécrit mais dont il part ;
#   vite.config.ts     la configuration de construction, y compris le découpage ;
#   tsconfig.json      la compilation ;
#   nginx.conf         il est DANS l'image finale et décide de ce qui est servi. Même
#                      raison que `docker/php.ini` côté API : un front reconstruit avec
#                      d'autres en-têtes ou une autre racine rendrait sinon la MÊME
#                      empreinte ;
#   src/ public/       le code et les actifs.
#
# CE QUI RESTE HORS DU CALCUL, ET QU'IL FAUT SAVOIR
#
# `Dockerfile`, `.dockerignore` et `README.md` sont exclus du contexte de construction
# par `.dockerignore` : ils ne sont PAS dans l'image, ils ne peuvent donc pas entrer dans
# une formule qui doit rendre la même valeur des deux côtés. Modifier le `Dockerfile` ne
# change donc pas cette empreinte — c'est la même réserve que `docker/entrypoint.sh` côté
# API, et elle est écrite ici pour la même raison : une limite tue devient une surprise.
#
# Ce script lui-même n'est pas haché : le motif ne retient aucun `.sh`.
set -e

cd "$(dirname "$0")"

{
    for fichier in package-lock.json package.json index.html vite.config.ts tsconfig.json nginx.conf; do
        [ -f "$fichier" ] && printf '%s\n' "$fichier" && cat "$fichier"
    done
    # Un seul flux, un seul tri : l'ordre ne dépend pas de celui que rend `find`.
    find src public -type f ! -name '*.sh' \
        | LC_ALL=C sort \
        | while IFS= read -r fichier; do
            printf '%s\n' "$fichier"
            cat "$fichier"
        done
} | sha256sum | cut -c1-12
