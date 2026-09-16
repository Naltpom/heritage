#!/usr/bin/env bash
# =============================================================================
#  Heritage — les limites PHP sont-elles celles du dépôt, DANS le conteneur ?
# =============================================================================
#
#   bash .github/scripts/verifier-les-limites-php.sh <commande qui rend `php -i`>
#
# Exemples :
#
#   # sur un runner de CI, contre l'image qui vient d'être construite
#   bash .github/scripts/verifier-les-limites-php.sh \
#       docker compose run --rm --no-deps --entrypoint php api -i
#
#   # sur le VPS, contre le conteneur RÉELLEMENT EN SERVICE
#   bash .github/scripts/verifier-les-limites-php.sh \
#       docker exec heritage-api-1 php -i
#
#   # depuis le poste, à travers SSH
#   bash .github/scripts/verifier-les-limites-php.sh \
#       ssh ubuntu@le-vps 'docker exec heritage-api-1 php -i'
#
# -----------------------------------------------------------------------------
#  POURQUOI CE CONTRÔLE N'EST PAS « LE FICHIER EST-IL LÀ ? »
# -----------------------------------------------------------------------------
#
# Un artefact dérivé n'est jamais corrigé par la correction de sa source. L'image
# fige le code et la configuration au moment où elle est construite : `api/docker/
# php.ini` peut être parfait dans le dépôt pendant que le conteneur en service
# tourne sur les défauts compilés de PHP, et RIEN ne le signale. C'est arrivé —
# c'est même l'état dans lequel ce contrôle a été écrit : memory_limit 128M,
# upload_max_filesize 2M, post_max_size 8M, « Loaded Configuration File => (none) ».
#
# Et `docker/empreinte.sh` ne rattrape pas ça : il ne hache que composer.lock et
# les *.php / *.yaml de src, config et public. Une image reconstruite avec un
# php.ini différent rend LA MÊME empreinte. Le seul témoin est PHP lui-même.
#
# -----------------------------------------------------------------------------
#  CE QUI EST VÉRIFIÉ — DEUX CHOSES DISTINCTES
# -----------------------------------------------------------------------------
#
#   1. Le conteneur applique EXACTEMENT ce que `api/docker/php.ini` déclare.
#      C'est le contrôle de non-divergence entre le dépôt et l'image.
#   2. Ces valeurs respectent le plancher que le projet a payé pour établir
#      (voir les commentaires de api/docker/php.ini). C'est le contrôle qui
#      empêche de « réparer » une CI rouge en baissant le fichier.
#
#   Plus une cohérence : post_max_size > upload_max_filesize, sans quoi le second
#   garde-fou refuse ce que le premier venait d'accepter, et la réponse ne dit
#   pas lequel a parlé.
#
# Le code de sortie est celui de ce script, en commande nue. Il ne traverse aucun
# tuyau : la commande donnée en argument est exécutée par ce script, et son propre
# échec est rapporté comme tel — un `CODE=0` qui serait celui de `tail` a déjà été
# payé sur ce projet.
# =============================================================================

set -euo pipefail

# Plancher exigé, en octets. Ce sont les valeurs de api/docker/php.ini ; elles
# sont répétées ici volontairement, pour que baisser le fichier ne suffise pas à
# faire taire le contrôle.
declare -A PLANCHER=(
    [memory_limit]=536870912        # 512 Mio
    [upload_max_filesize]=20971520  #  20 Mio
    [post_max_size]=26214400        #  25 Mio
)

RACINE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FICHIER_INI="$RACINE/api/docker/php.ini"

dire()  { printf '[limites] %s\n' "$*"; }
crier() { printf '[limites] %s\n' "$*" >&2; }

if [ "$#" -eq 0 ]; then
    crier "Usage : $0 <commande qui rend la sortie de « php -i »>"
    crier "Exemple : $0 docker compose run --rm --no-deps --entrypoint php api -i"
    exit 2
fi

if [ ! -f "$FICHIER_INI" ]; then
    crier "$FICHIER_INI absent : l'image repartirait sur les défauts compilés de PHP."
    exit 2
fi

# -----------------------------------------------------------------------------
# « 512M » → 536870912. Un seul endroit où cette conversion est écrite.
# -----------------------------------------------------------------------------
en_octets() {
    local valeur="${1//[[:space:]]/}"
    local nombre="${valeur%[KkMmGg]}"
    local suffixe="${valeur#"$nombre"}"

    case "$nombre" in
        ''|*[!0-9-]*) printf 'NaN\n'; return 0 ;;
    esac

    case "$suffixe" in
        K|k) printf '%s\n' "$((nombre * 1024))" ;;
        M|m) printf '%s\n' "$((nombre * 1048576))" ;;
        G|g) printf '%s\n' "$((nombre * 1073741824))" ;;
        '')  printf '%s\n' "$nombre" ;;
        *)   printf 'NaN\n' ;;
    esac
}

# -----------------------------------------------------------------------------
# Ce que le DÉPÔT déclare
# -----------------------------------------------------------------------------
lire_du_depot() {
    # Début de ligne exigé : le fichier explique le calcul en commentaire, et un
    # motif lâche capterait « memory_limit » écrit dans une phrase.
    sed -n -E "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*([0-9]+[KMGkmg]?)[[:space:]]*$/\1/p" "$FICHIER_INI" \
        | tail -n 1
}

# -----------------------------------------------------------------------------
# Ce que le CONTENEUR applique
# -----------------------------------------------------------------------------
#
# La sortie est capturée dans un fichier, et le code de la commande est relevé
# SÉPARÉMENT. Une commande qui échouerait en rendant une sortie vide doit être
# distinguée d'un conteneur qui répond sans ces directives : ce sont deux pannes
# différentes, et une seule des deux se corrige dans le Dockerfile.
SORTIE="$(mktemp)"
# shellcheck disable=SC2064  # on veut le chemin figé maintenant, pas à la sortie
trap "rm -f '$SORTIE'" EXIT

dire "commande interrogée : $*"

CODE=0
"$@" > "$SORTIE" 2>/dev/null || CODE=$?

if [ "$CODE" -ne 0 ]; then
    crier "La commande a échoué (code $CODE). Rien n'est vérifié — ce n'est PAS un vert."
    exit 1
fi

if ! grep -q '^memory_limit' "$SORTIE"; then
    crier "La sortie ne ressemble pas à celle de « php -i » : aucune ligne « memory_limit »."
    crier "Premières lignes reçues :"
    head -n 5 "$SORTIE" >&2
    exit 1
fi

# `php -i` rend « directive => valeur locale => valeur maître ». On lit la valeur
# LOCALE, celle qui s'applique vraiment à l'exécution en cours.
lire_du_conteneur() {
    sed -n -E "s/^$1[[:space:]]*=>[[:space:]]*([^ ]+)[[:space:]]*=>.*/\1/p" "$SORTIE" | head -n 1
}

# -----------------------------------------------------------------------------
# Confrontation
# -----------------------------------------------------------------------------
ECHECS=0
EXAMINEES=0

for directive in memory_limit upload_max_filesize post_max_size; do
    EXAMINEES=$((EXAMINEES + 1))

    declare_depot="$(lire_du_depot "$directive")"
    valeur_conteneur="$(lire_du_conteneur "$directive")"

    if [ -z "$declare_depot" ]; then
        crier "$directive : aucune directive active dans api/docker/php.ini."
        ECHECS=$((ECHECS + 1))
        continue
    fi
    if [ -z "$valeur_conteneur" ]; then
        crier "$directive : introuvable dans la sortie de php -i."
        ECHECS=$((ECHECS + 1))
        continue
    fi

    octets_depot="$(en_octets "$declare_depot")"
    octets_conteneur="$(en_octets "$valeur_conteneur")"

    if [ "$octets_conteneur" = "NaN" ]; then
        crier "$directive : valeur illisible dans le conteneur (« $valeur_conteneur »)."
        ECHECS=$((ECHECS + 1))
        continue
    fi

    printf '[limites] %-20s conteneur=%-6s dépôt=%-6s plancher=%s\n' \
        "$directive" "$valeur_conteneur" "$declare_depot" "${PLANCHER[$directive]}"

    if [ "$octets_conteneur" -ne "$octets_depot" ]; then
        crier "  ÉCART : le conteneur applique « $valeur_conteneur », le dépôt déclare « $declare_depot »."
        crier "  L'image n'a pas été reconstruite avec ce php.ini, ou le Dockerfile ne le copie plus."
        ECHECS=$((ECHECS + 1))
    fi

    if [ "$octets_conteneur" -lt "${PLANCHER[$directive]}" ]; then
        crier "  SOUS LE PLANCHER : $octets_conteneur octets < ${PLANCHER[$directive]} octets exigés."
        crier "  Voir api/docker/php.ini : quatre dépôts de preuves sur dix ont échoué en dessous."
        ECHECS=$((ECHECS + 1))
    fi
done

# post_max_size doit englober upload_max_filesize, enrobage multipart compris.
televersement="$(en_octets "$(lire_du_conteneur upload_max_filesize)")"
enveloppe="$(en_octets "$(lire_du_conteneur post_max_size)")"
EXAMINEES=$((EXAMINEES + 1))

if [ "$televersement" != "NaN" ] && [ "$enveloppe" != "NaN" ] && [ "$enveloppe" -le "$televersement" ]; then
    crier "post_max_size ($enveloppe) n'est pas supérieur à upload_max_filesize ($televersement)."
    crier "Un fichier à la limite haute passerait le premier garde-fou et serait refusé par le second."
    ECHECS=$((ECHECS + 1))
fi

dire "contrôles examinés : $EXAMINEES — en échec : $ECHECS"

if [ "$ECHECS" -ne 0 ]; then
    crier "Les limites PHP du conteneur ne sont pas celles du dépôt."
    exit 1
fi

dire "Le conteneur applique bien les limites déclarées par api/docker/php.ini."
