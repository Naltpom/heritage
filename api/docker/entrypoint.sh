#!/bin/sh
# Ce fichier doit rester en fins de ligne LF. En CRLF, le noyau lit « /bin/sh^M »
# comme interpréteur et le conteneur meurt sur « bad interpreter », erreur qui ne
# se voit pas à l'écran. Le .gitattributes du dépôt impose eol=lf pour cette raison.
set -e

# Nombre de tentatives et intervalle. 30 × 2 s = 60 s, le temps qu'un cluster
# PostgreSQL neuf s'initialise. Réglables depuis l'orchestration sans reconstruire.
ATTENTE_MAX="${HERITAGE_ATTENTE_MAX:-30}"
ATTENTE_PAS="${HERITAGE_ATTENTE_PAS:-2}"

# -----------------------------------------------------------------------------
#  Attente de la base — et rien d'autre
# -----------------------------------------------------------------------------
#
# CE QUE CE BLOC NE DOIT PLUS FAIRE, parce que ça a coûté deux minutes de fausse
# piste : la version précédente lançait `dbal:run-sql "SELECT 1" > /dev/null 2>&1`.
# Le `2>&1` jetait l'erreur réelle. N'IMPORTE QUELLE panne — classe introuvable,
# conteneur non compilable, configuration cassée — était donc annoncée comme
# « base injoignable après 30 tentatives », après 60 secondes d'attente inutile.
# Le cas vécu : un « Cannot autowire … NormalisateurNom, classe introuvable »
# présenté comme une panne de base parfaitement saine. On a cherché du mauvais
# côté, et le script affirmait avec aplomb quelque chose de faux.
#
# La commande sert à deux choses à la fois, et c'est là qu'est le piège : elle
# amorce le noyau Symfony *puis* interroge la base. Un échec peut donc venir des
# deux, et il faut savoir lequel avant de parler.
#
# Le critère de tri est la classe SQLSTATE 08 — « connection exception » de la
# norme SQL — complétée par les formulations que les pilotes produisent avant
# même d'avoir un SQLSTATE (résolution de nom, port fermé, cluster en cours
# d'initialisation). Tout le reste n'est pas un problème de connexion : on le
# montre IMMÉDIATEMENT et on s'arrête, au lieu d'attendre une minute pour mentir.
motif_de_connexion='SQLSTATE\[08|Connection refused|could not connect|could not translate host name|Name or service not known|No such host|Connection timed out|system is starting up|server closed the connection unexpectedly|Temporary failure in name resolution'

# -----------------------------------------------------------------------------
#  Ce que sert ce conteneur — annoncé avant toute chose
# -----------------------------------------------------------------------------
#
# Première ligne du journal, volontairement. Un conteneur qui sert une image
# périmée répond faux sans que rien ne le dise : on lit un correctif dans
# l'éditeur et l'API répond avec le code d'avant. Comparer avec
# `sh docker/empreinte.sh` lancé sur le dépôt : si les deux diffèrent,
# l'image est périmée et il faut reconstruire.
EMPREINTE="$(cat /usr/local/share/heritage/empreinte 2>/dev/null || echo inconnue)"
CONSTRUITE_LE="$(cat /usr/local/share/heritage/construite-le 2>/dev/null || echo inconnue)"
echo "[heritage] image construite le ${CONSTRUITE_LE}, empreinte des sources ${EMPREINTE}"
echo "[heritage] comparer avec : sh docker/empreinte.sh (depuis api/ sur le dépôt)"

echo "[heritage] attente de la base de données…"

tentatives=0
derniere_erreur=''
base_prete=0

while [ "$tentatives" -lt "$ATTENTE_MAX" ]; do
  tentatives=$((tentatives + 1))

  # On CAPTURE la sortie au lieu de la jeter : c'est toute la correction.
  if derniere_erreur=$(php bin/console dbal:run-sql 'SELECT 1' 2>&1); then
    base_prete=1
    break
  fi

  if ! printf '%s' "$derniere_erreur" | grep -qiE "$motif_de_connexion"; then
    echo "[heritage] ÉCHEC : la base n'est pas en cause." >&2
    echo "[heritage] La commande a échoué dès la tentative ${tentatives}, pour un motif" >&2
    echo "[heritage] étranger à la connexion. Erreur réelle :" >&2
    echo >&2
    printf '%s\n' "$derniere_erreur" >&2
    exit 1
  fi

  # Un échec de connexion pendant que la base démarre est NORMAL : on se tait.
  # Sauf à mi-parcours, où l'attente cesse d'être normale et commence à être un
  # symptôme — autant dire pourquoi on patiente plutôt que de laisser un écran
  # muet pendant une minute.
  if [ "$tentatives" -eq $((ATTENTE_MAX / 2)) ]; then
    echo "[heritage] toujours en attente après ${tentatives} tentatives. Dernier motif :"
    printf '%s\n' "$derniere_erreur" | head -n 3
  fi

  sleep "$ATTENTE_PAS"
done

if [ "$base_prete" -ne 1 ]; then
  echo "[heritage] ÉCHEC : base injoignable après ${ATTENTE_MAX} tentatives." >&2
  echo "[heritage] Dernière erreur observée — c'est elle qui dit la cause :" >&2
  echo >&2
  printf '%s\n' "$derniere_erreur" >&2
  exit 1
fi

# -----------------------------------------------------------------------------
#  Migrations
# -----------------------------------------------------------------------------
echo "[heritage] migrations…"
# --allow-no-migration : le squelette démarre avant que la première migration
# existe ; sans ce drapeau, le conteneur refuserait de se lancer.
#
# Pas de redirection ici non plus : si une migration échoue, on veut voir laquelle
# et pourquoi. `set -e` arrête le conteneur, la sortie de Doctrine reste visible.
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "[heritage] démarrage du serveur."
exec "$@"
