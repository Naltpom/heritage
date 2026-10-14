#!/usr/bin/env bash
# =============================================================================
#  Heritage — déploiement sur le VPS, avec retour arrière automatique
# =============================================================================
#
# Ce script s'exécute SUR LE SERVEUR, dans le clone (`~/apps/heritage`), appelé
# par `.github/workflows/deploy.yml` à travers SSH. Il est versionné avec le code
# qu'il déploie : la procédure de déploiement d'un commit est une propriété de ce
# commit, pas une propriété de la machine.
#
#   bash .github/scripts/deployer-sur-le-vps.sh production <sha> [<sha-precedent>]
#   bash .github/scripts/deployer-sur-le-vps.sh retour-arriere
#
# -----------------------------------------------------------------------------
#  CE QU'IL GARANTIT, ET CE QU'IL NE GARANTIT PAS
# -----------------------------------------------------------------------------
#
# GARANTI : si la nouvelle version ne répond pas, ou répond avec une AUTRE
# empreinte que celle du commit demandé, la pile est remise sur le commit
# précédent et sur SON image, sans reconstruction, et le script sort en erreur.
#
# NON GARANTI : le premier déploiement n'a pas de précédent. En cas d'échec il
# n'y a rien où revenir, et le script le dit au lieu de faire semblant.
#
# NON GARANTI NON PLUS, ET C'EST LA VRAIE LIMITE : la base de données ne revient
# pas en arrière. Les migrations Doctrine sont jouées par l'entrypoint de l'image
# api, et une migration qui supprime une colonne ne se défait pas en redémarrant
# l'image précédente — l'ancien code y cherchera une colonne disparue. Une
# migration destructrice se déploie donc en DEUX temps : ajouter au déploiement
# n, supprimer au déploiement n+1, quand on ne peut plus vouloir revenir avant.
# Voir docs/deploiement/premiere-mise-en-ligne.md, § « Ce que le retour arrière
# ne rattrape pas ».
#
# CE QUI RESTE POSSIBLE À LA MAIN : en mode production, la base est vidée
# (`pg_dump -Fc`) dans `backup/` APRÈS la construction et AVANT le démarrage,
# donc avant que l'entrypoint ne joue la moindre migration. Pas de vidage, pas de
# déploiement : la pile en service n'est alors pas touchée. Le retour arrière,
# lui, ne vide rien. La restauration n'est jamais automatique — voir § 11.6 de
# docs/deploiement/premiere-mise-en-ligne.md.
#
# -----------------------------------------------------------------------------
#  POURQUOI UNE ÉTIQUETTE D'IMAGE PAR COMMIT
# -----------------------------------------------------------------------------
#
# `IMAGE_TAG` vaut l'empreinte courte du commit déployé. Une étiquette fixe
# (`prod`) serait réécrite par chaque construction : l'image précédente
# deviendrait anonyme (`<none>`), puis serait balayée par le premier
# `docker image prune`. Il n'y aurait plus rien où revenir, et on ne s'en
# apercevrait qu'au moment d'en avoir besoin.
#
# L'étiquette n'est PAS écrite dans `.env` : ce fichier est écrit à la main sur le
# serveur, il porte les secrets, et un script n'a pas à y toucher. Elle vit dans
# `.deploiement/env`, passé à Compose comme second `--env-file` — le dernier
# l'emporte. (Vérifié : `docker compose --env-file .env --env-file autre.env
# config` rend bien l'étiquette du second fichier.)
#
# -----------------------------------------------------------------------------
#  ÉTAT CONSERVÉ ENTRE DEUX DÉPLOIEMENTS
# -----------------------------------------------------------------------------
#
# Tout est dans `.deploiement/`, un répertoire NON SUIVI par Git. C'est ce qui le
# rend fiable : `git reset --hard` ne touche pas aux fichiers non suivis, donc
# l'état survit exactement au geste qui le rendrait le plus utile.
#
#   .deploiement/env         IMAGE_TAG=<sha court> — lu par Compose
#   .deploiement/courant     le SHA complet actuellement déployé
#   .deploiement/precedent   le SHA complet d'avant, cible du retour arrière
#   .deploiement/journal     une ligne par déploiement, en ajout
#
# Et, à part parce que ce n'est pas un état mais de la donnée personnelle :
#
#   backup/deploiement-<AAAAMMJJTHHMMSSZ>-avant-<etiquette>.dump
#                            la base telle qu'elle était juste avant le
#                            démarrage de <etiquette> ; les 10 plus récents
#                            sont gardés. Répertoire en 700, fichiers en 600,
#                            ignoré par Git (`/backup/` dans `.gitignore`) —
#                            donc, lui aussi, intact après `git reset --hard`.
#
# (À SIGNALER au lot qui tient `.gitignore` : y ajouter `/.deploiement/` éviterait
#  que ce répertoire apparaisse en « non suivi » dans un `git status` sur le VPS.
#  Ce n'est pas bloquant — `git reset --hard` l'ignore de toute façon.)
# =============================================================================

set -euo pipefail

MODE="${1:-production}"
SHA_CIBLE="${2:-}"
SHA_PRECEDENT_ANNONCE="${3:-}"

# -----------------------------------------------------------------------------
# Réglages
# -----------------------------------------------------------------------------

# Attente de la pile : 60 tentatives espacées de 5 s, soit 5 minutes au plus. La
# première montée d'une base neuve joue toutes les migrations ; 5 minutes est
# large pour ce que fait l'entrypoint, et court pour un humain qui regarde.
TENTATIVES_MAX=60
DELAI_ENTRE_TENTATIVES=5

# Étiquettes d'images conservées par dépôt. En dessous de 3, le retour arrière
# n'a plus de marge : le courant et le précédent en occupent déjà deux.
ETIQUETTES_CONSERVEES=5

ETAT=".deploiement"
JOURNAL="$ETAT/journal"

# Vidages de la base pris à chaque déploiement de production, à la racine du
# clone. Dix suffisent à remonter de plusieurs déploiements ; ce ne sont pas les
# sauvegardes quotidiennes (service `sauvegarde`, `${BACKUP_DIR}`), qui restent
# la seule copie à faire partir hors site.
SAUVEGARDES="backup"
SAUVEGARDES_CONSERVEES=10

dire()  { printf '[deploiement] %s\n' "$*"; }
crier() { printf '[deploiement] %s\n' "$*" >&2; }

# -----------------------------------------------------------------------------
# Lire UNE clé d'un fichier d'environnement — sans le « sourcer »
# -----------------------------------------------------------------------------
#
# `source .env` EXÉCUTERAIT le contenu du fichier. Une valeur contenant `$(...)`,
# une apostrophe mal fermée ou un point-virgule s'exécuterait avec les droits de
# ce script, et ce fichier porte les secrets de production. On lit donc une
# ligne, on retire les guillemets éventuels, et rien d'autre.
#
# LE `|| true` FINAL N'EST PAS DE LA PARESSE, il répare un défaut mesuré. Sous
# `set -euo pipefail`, un `grep` qui ne trouve rien sort 1, `pipefail` propage ce
# 1 à toute la chaîne, l'affectation `VAR="$(lire_cle …)"` échoue, et `set -e`
# arrête le script SUR-LE-CHAMP, sans un mot. Une variable absente de `.env`
# donnait donc une sortie 1 muette, là où le contrôle juste en dessous existe
# précisément pour dire laquelle manque et pourquoi. Une clé absente doit rendre
# la chaîne vide, pas tuer le script.
lire_cle() {
    local fichier="$1" cle="$2"
    [ -f "$fichier" ] || return 0
    grep -E "^${cle}=" "$fichier" 2>/dev/null | tail -n 1 | cut -d= -f2- \
        | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' \
              -e "s/^'\(.*\)'\$/\1/" -e 's/^"\(.*\)"$/\1/' || true
}

# -----------------------------------------------------------------------------
# Contrôles préalables — on refuse d'avancer sur un terrain qu'on ne reconnaît pas
# -----------------------------------------------------------------------------

if [ ! -f compose.yaml ] || [ ! -f compose.prod.yaml ]; then
    crier "compose.yaml ou compose.prod.yaml absent : ce n'est pas la racine du clone."
    exit 2
fi

if [ ! -f .env ]; then
    crier ".env absent. Il s'écrit A LA MAIN sur le serveur, il n'est pas dans le dépôt."
    crier "Voir docs/deploiement/premiere-mise-en-ligne.md, étape 5."
    exit 2
fi

mkdir -p "$ETAT"

DOMAINE="$(lire_cle .env HERITAGE_DOMAINE_PUBLIC)"
PORT="$(lire_cle .env APP_PORT)"
PREFIXE="$(lire_cle .env IMAGE_PREFIX)"
: "${PREFIXE:=heritage}"

if [ -z "$DOMAINE" ]; then
    crier "HERITAGE_DOMAINE_PUBLIC absente de .env : le front fermerait en 444 sur le domaine reel."
    exit 2
fi
if [ -z "$PORT" ]; then
    crier "APP_PORT absent de .env. Sur ce serveur, heritage a la paire 5511/5512 : APP_PORT=5511."
    exit 2
fi

# -----------------------------------------------------------------------------
# Choix de la cible selon le mode
# -----------------------------------------------------------------------------

SHA_AVANT="$(git rev-parse HEAD)"
TAG_AVANT="$(lire_cle "$ETAT/env" IMAGE_TAG)"

case "$MODE" in
    production)
        if [ -z "$SHA_CIBLE" ]; then
            crier "mode production : le SHA a deployer est obligatoire."
            exit 2
        fi
        RECONSTRUIRE=1
        # Le SHA d'avant vient d'abord de ce que le flux a relevé AVANT son
        # `git reset --hard` : à ce stade, « git rev-parse HEAD » rend déjà la
        # nouvelle valeur. On retombe sur SHA_AVANT si rien n'a été transmis.
        SHA_RETOUR="${SHA_PRECEDENT_ANNONCE:-$SHA_AVANT}"
        TAG_RETOUR="$TAG_AVANT"
        ;;
    retour-arriere)
        SHA_CIBLE="$(cat "$ETAT/precedent" 2>/dev/null || true)"
        if [ -z "$SHA_CIBLE" ]; then
            crier "Aucun deploiement precedent enregistre ($ETAT/precedent absent ou vide)."
            crier "Il n'y a rien ou revenir. Deployez un commit explicitement."
            exit 2
        fi
        # Pas de reconstruction : l'intérêt du retour arrière est justement de
        # remettre en service une image DÉJÀ construite et déjà vue fonctionner.
        # La reconstruire rejouerait le risque qu'on cherche à fuir.
        RECONSTRUIRE=0
        SHA_RETOUR="$SHA_AVANT"
        TAG_RETOUR="$TAG_AVANT"
        ;;
    *)
        crier "Mode inconnu : « $MODE ». Attendu : production | retour-arriere."
        exit 2
        ;;
esac

TAG_CIBLE="$(printf '%s' "$SHA_CIBLE" | cut -c1-7)"

dire "mode ............. $MODE"
dire "commit vise ...... $SHA_CIBLE (etiquette $TAG_CIBLE)"
dire "retour possible .. ${SHA_RETOUR:-aucun} (etiquette ${TAG_RETOUR:-aucune})"
dire "domaine .......... $DOMAINE"
dire "port hote ........ 127.0.0.1:$PORT"

# -----------------------------------------------------------------------------
# La commande Compose, une fois pour toutes
# -----------------------------------------------------------------------------
#
# Deux `--env-file` : le premier porte les secrets écrits à la main, le second la
# seule étiquette d'image.
#
# ATTENTION : dès qu'on passe `--env-file`, Compose CESSE de charger `.env` tout
# seul. Les deux doivent donc être nommés, et `.env` en premier.
pile() {
    docker compose \
        --env-file .env --env-file "$ETAT/env" \
        -f compose.yaml -f compose.prod.yaml "$@"
}

ecrire_etiquette() {
    printf 'IMAGE_TAG=%s\n' "$1" > "$ETAT/env"
}

# -----------------------------------------------------------------------------
# Sauvegarde de la base, avant que l'entrypoint ne joue les migrations
# -----------------------------------------------------------------------------
#
# Rend 0 si un vidage complet est en place, OU s'il n'existe encore aucune base
# (premier déploiement) ; 1 dans tous les autres cas, et l'appelant arrête le
# déploiement. Un déploiement sans sa sauvegarde ne part pas.
#
# ATTENTION, `set -e` NE JOUE PAS ICI : la fonction est appelée dans un `if`, et
# bash suspend `set -e` pour tout ce qu'elle exécute. Chaque étape qui compte
# porte donc son propre `|| return 1` — sans eux, un `mkdir` raté passerait.
#
# « Aucune base » se décide sur le VOLUME, pas sur le conteneur : un conteneur
# `db` arrêté au-dessus d'un volume plein n'est pas un premier déploiement, c'est
# une base qu'on ne sait pas sauvegarder — donc un refus.
sauvegarder_la_base() {
    local en_service projet volume horodatage nom final partiel octets

    # Une commande Docker qui ÉCHOUE ne doit jamais se lire « pas de base » : ce
    # serait déployer sans sauvegarde sur une panne du démon. D'où les `if !`.
    if ! en_service="$(pile ps --status running --quiet db)"; then
        crier "sauvegarde : « docker compose ps » a echoue — etat de la base inconnu."
        return 1
    fi

    if [ -z "$en_service" ]; then
        # `name:` est la première ligne de `config` ; elle tient compte d'un
        # COMPOSE_PROJECT_NAME posé dans `.env`. Le reste de la sortie (secrets
        # compris) ne quitte pas le tube.
        projet="$(pile config 2>/dev/null | sed -n 's/^name:[[:space:]]*//p' | head -n 1 || true)"
        if [ -z "$projet" ]; then
            crier "sauvegarde : nom du projet Compose illisible — impossible de dire s'il existe une base."
            return 1
        fi
        if ! volume="$(docker volume ls --quiet \
                --filter "label=com.docker.compose.project=$projet" \
                --filter "label=com.docker.compose.volume=base-de-donnees")"; then
            crier "sauvegarde : « docker volume ls » a echoue — impossible de dire s'il existe une base."
            return 1
        fi
        if [ -n "$volume" ]; then
            crier "sauvegarde : le volume $volume existe mais le conteneur db ne tourne pas."
            crier "sauvegarde : la base ne peut pas etre videe, et on ne la demarre pas d'office."
            crier "Demarrez-la (avec son garde-fou garde-secrets), puis relancez le flux :"
            crier "    docker compose --env-file .env --env-file $ETAT/env \\"
            crier "        -f compose.yaml -f compose.prod.yaml up -d db"
            return 1
        fi
        crier "sauvegarde : AUCUNE BASE — ni conteneur db en service, ni volume base-de-donnees"
        crier "sauvegarde : pour le projet « $projet ». C'est le cas d'un PREMIER deploiement :"
        crier "sauvegarde : rien a sauvegarder, le deploiement continue SANS vidage."
        printf '%s\tSAUVEGARDE\tavant=%s\taucune base (premier deploiement)\n' \
            "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$TAG_CIBLE" >> "$JOURNAL"
        return 0
    fi

    mkdir -p "$SAUVEGARDES" || return 1
    chmod 700 "$SAUVEGARDES" || return 1

    # Restes d'un passage interrompu (SSH coupé, délai du flux dépassé). Seul
    # NOTRE motif temporaire est visé, rien d'autre de ce répertoire.
    find "$SAUVEGARDES" -maxdepth 1 -type f -name '.deploiement-*.dump.partiel' -delete || true

    horodatage="$(date -u +%Y%m%dT%H%M%SZ)"
    nom="deploiement-${horodatage}-avant-${TAG_CIBLE}.dump"
    final="$SAUVEGARDES/$nom"
    # Nom caché et suffixe distinct : un vidage en cours d'écriture ne ressemble
    # jamais à un vidage fini, ni pour un humain qui liste, ni pour le ménage.
    partiel="$SAUVEGARDES/.$nom.partiel"

    # `-T` : sans terminal, sinon les octets du format custom passeraient par un
    # pseudo-terminal qui les altère. `umask 077` dans le sous-shell : le fichier
    # naît en 600, il porte l'arbre entier, personnes vivantes comprises.
    # Les variables sont celles du CONTENEUR db (apostrophes) ; la connexion passe
    # par le socket local, sans mot de passe — comme la restauration écrite au-
    # dessus du service `sauvegarde` de compose.prod.yaml.
    dire "sauvegarde : pg_dump -Fc de la base vers $final"
    # SC2016 écarté : les apostrophes sont voulues, les variables se développent
    # dans le conteneur et non ici.
    # shellcheck disable=SC2016
    if ! ( umask 077 && pile exec -T db \
            sh -c 'exec pg_dump --format=custom -U "$POSTGRES_USER" -d "$POSTGRES_DB"' \
            > "$partiel" ); then
        rm -f "$partiel"
        crier "sauvegarde : ECHEC du pg_dump (sortie d'erreur ci-dessus)."
        return 1
    fi

    # Un code 0 ne suffit pas à croire le fichier : vide, ou sans l'en-tête du
    # format custom (`PGDMP`), il ne se restaurerait pas.
    if [ ! -s "$partiel" ] || [ "$(head -c 5 "$partiel")" != "PGDMP" ]; then
        rm -f "$partiel"
        crier "sauvegarde : le vidage est vide ou n'est pas au format custom de pg_dump."
        return 1
    fi

    # Même répertoire, donc renommage atomique : le nom final n'existe que complet.
    mv -f "$partiel" "$final" || { rm -f "$partiel"; return 1; }
    octets="$(wc -c < "$final" | tr -d '[:space:]')"
    dire "sauvegarde : $final ($octets octets)"
    printf '%s\tSAUVEGARDE\tavant=%s\tfichier=%s\toctets=%s\n' \
        "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$TAG_CIBLE" "$final" "$octets" >> "$JOURNAL"

    # Rétention — APRÈS le succès, jamais avant : un vidage raté ne doit coûter
    # aucun des anciens. Seuls les noms de NOTRE motif exact sont comptés et
    # supprimés ; l'horodatage en tête rend l'ordre alphabétique chronologique.
    find "$SAUVEGARDES" -maxdepth 1 -type f -name 'deploiement-*-avant-*.dump' -printf '%f\n' \
        | grep -E '^deploiement-[0-9]{8}T[0-9]{6}Z-avant-[0-9a-f]+\.dump$' \
        | sort -r \
        | tail -n "+$((SAUVEGARDES_CONSERVEES + 1))" \
        | while read -r ancien; do
            rm -f -- "$SAUVEGARDES/$ancien"
            dire "sauvegarde : $ancien supprime (au-dela des $SAUVEGARDES_CONSERVEES plus recents)"
        done || true

    return 0
}

# -----------------------------------------------------------------------------
# Sondes
# -----------------------------------------------------------------------------

# L'empreinte que la pile SERT, lue à travers le front, avec le vrai `Host`.
#
# Passer par le front et non par l'API en direct est délibéré : c'est le chemin
# du navigateur. Une API saine derrière un front qui ferme tout en 444 est une
# panne totale que la sonde interne de l'API ne verrait pas. Cette sonde-ci
# prouve donc deux choses d'un coup : l'API répond, ET le `server_name` du front
# a bien reçu le domaine.
#
# N'écrit QUE l'empreinte sur la sortie standard : elle est capturée par
# substitution de commande, tout message irait se mêler à la valeur.
empreinte_servie() {
    local json
    json="$(curl -fsS --max-time 10 -H "Host: $DOMAINE" \
        "http://127.0.0.1:$PORT/sante.php" 2>/dev/null)" || return 1
    printf '%s' "$json" | sed -n 's/.*"empreinte":"\([^"]*\)".*/\1/p'
}

# L'empreinte que le DÉPÔT devrait produire, par la même formule.
# `api/docker/empreinte.sh` est la source unique : l'image la grave à la
# construction, on la rejoue ici sur l'arbre de travail.
empreinte_attendue() {
    ( cd api && sh docker/empreinte.sh )
}

# Messages sur la sortie d'ERREUR — voir la note d'empreinte_servie.
attendre_la_pile() {
    local i empreinte
    for i in $(seq 1 "$TENTATIVES_MAX"); do
        empreinte=""
        if empreinte="$(empreinte_servie)" && [ -n "$empreinte" ]; then
            crier "pile joignable au bout de ~$(( (i - 1) * DELAI_ENTRE_TENTATIVES )) s"
            printf '%s' "$empreinte"
            return 0
        fi
        sleep "$DELAI_ENTRE_TENTATIVES"
    done
    return 1
}

# Le bloc par défaut d'app/nginx.conf doit fermer tout `Host` inconnu.
#
# Ce n'est pas un ornement : c'est la moitié du garde-fou contre le `Host`
# falsifié (l'autre moitié étant `framework.trusted_hosts` côté API). nginx ferme
# la connexion sans rien envoyer — « 444 » n'est pas un code HTTP, c'est la
# convention interne de nginx pour « raccroche ».
#
# curl le signale par le code 52 (« empty reply from server ») ou 56 (« recv
# failure »). On exige l'un des deux : un 0 signifierait qu'on a RÉPONDU à un
# hôte inconnu, et tout autre code signifierait qu'on ne sait pas ce qui s'est
# passé — dans les deux cas on ne valide pas.
hote_inconnu_ferme() {
    local code=0
    curl -sS --max-time 10 -o /dev/null \
        -H 'Host: heritage.hote-non-declare.invalid' \
        "http://127.0.0.1:$PORT/" >/dev/null 2>&1 || code=$?
    crier "sonde Host inconnu : curl sort $code (52 ou 56 = connexion fermee, attendu)"
    [ "$code" = "52" ] || [ "$code" = "56" ]
}

# -----------------------------------------------------------------------------
# Retour arrière
# -----------------------------------------------------------------------------
#
# Rend TOUJOURS 1 : appelée, c'est que quelque chose a échoué. L'appelant écrit
# donc « … || revenir_en_arriere "motif" || exit 1 », et la sortie est non nulle
# quoi qu'il arrive — un retour arrière réussi reste un déploiement raté.
revenir_en_arriere() {
    local motif="$1"
    crier "ECHEC : $motif"

    if [ -z "${SHA_RETOUR:-}" ] || [ -z "${TAG_RETOUR:-}" ]; then
        crier "AUCUN RETOUR ARRIERE POSSIBLE : pas de deploiement precedent enregistre."
        crier "C'est le cas normal d'un PREMIER deploiement. La pile reste en l'etat,"
        crier "dans la version qui vient d'echouer, pour qu'elle soit lisible :"
        crier "    docker compose --env-file .env --env-file $ETAT/env \\"
        crier "        -f compose.yaml -f compose.prod.yaml logs --tail=200"
        return 1
    fi

    crier "retour sur $SHA_RETOUR (etiquette $TAG_RETOUR), SANS reconstruction."
    if ! git reset --hard "$SHA_RETOUR" >/dev/null 2>&1; then
        crier "le retour de l'arbre git vers $SHA_RETOUR a echoue — intervention manuelle."
        return 1
    fi
    ecrire_etiquette "$TAG_RETOUR"
    if ! pile up -d --remove-orphans; then
        crier "le redemarrage de la version precedente a echoue — intervention manuelle."
        return 1
    fi

    if attendre_la_pile >/dev/null; then
        crier "version precedente ($TAG_RETOUR) de nouveau en service."
        printf '%s\tRETOUR-ARRIERE\tvers=%s\tmotif=%s\n' \
            "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$SHA_RETOUR" "$motif" >> "$JOURNAL"
    else
        crier "LA VERSION PRECEDENTE NE REPOND PAS NON PLUS : le probleme n'est pas dans le code."
        crier "Regardez la base et le reseau avant de redeployer quoi que ce soit."
    fi
    return 1
}

# -----------------------------------------------------------------------------
# Déploiement
# -----------------------------------------------------------------------------

# Le mode « production » suppose que l'appelant a déjà fait le `git reset --hard`
# (il a besoin de CE script, qui vient du commit visé). On le vérifie plutôt que
# de le croire : un décalage ici construirait l'image d'un autre commit sous
# l'étiquette du commit visé — exactement le défaut que l'empreinte sert à voir.
if [ "$MODE" = "production" ]; then
    SHA_ARBRE="$(git rev-parse HEAD)"
    if [ "$SHA_ARBRE" != "$SHA_CIBLE" ]; then
        crier "l'arbre est sur $SHA_ARBRE alors que le commit vise est $SHA_CIBLE."
        crier "Le flux doit faire le « git reset --hard » AVANT d'appeler ce script."
        exit 2
    fi
else
    dire "retour arriere : git reset --hard $SHA_CIBLE"
    git reset --hard "$SHA_CIBLE"
fi

ecrire_etiquette "$TAG_CIBLE"

if [ "$RECONSTRUIRE" -eq 1 ]; then
    dire "construction des images sous l'etiquette $TAG_CIBLE"
    pile build || revenir_en_arriere "la construction des images a echoue" || exit 1
fi

# Le vidage vient APRÈS la construction, qui peut durer longtemps, et juste
# avant le `up` : ce qu'on écrit dans la base pendant la construction y figure
# donc. Rien n'a encore touché la pile en service — on peut s'arrêter net.
if [ "$MODE" = "production" ]; then
    if ! sauvegarder_la_base; then
        crier "DEPLOIEMENT INTERROMPU AVANT LE DEMARRAGE : pas de sauvegarde, pas de migration."
        crier "La pile en service n'a pas ete touchee. Le clone, lui, est deja sur $SHA_CIBLE"
        crier "(le flux a fait son « git reset --hard ») : relancez le flux une fois la cause reparee."
        # L'étiquette écrite pour la construction ne décrit pas ce qui tourne :
        # un `up` manuel démarrerait sinon la nouvelle version, migrations comprises,
        # sans sauvegarde.
        if [ -n "$TAG_AVANT" ]; then
            ecrire_etiquette "$TAG_AVANT"
            crier "$ETAT/env remis sur l'etiquette en service ($TAG_AVANT)."
        fi
        exit 1
    fi
fi

dire "demarrage"
pile up -d --remove-orphans || revenir_en_arriere "le demarrage de la pile a echoue" || exit 1

dire "attente d'une reponse sur http://127.0.0.1:$PORT/sante.php (Host: $DOMAINE)"
EMPREINTE_SERVIE=""
EMPREINTE_SERVIE="$(attendre_la_pile)" \
    || revenir_en_arriere "la pile n'a pas repondu en $((TENTATIVES_MAX * DELAI_ENTRE_TENTATIVES)) s" \
    || exit 1

# -----------------------------------------------------------------------------
# LE CONTRÔLE QUI DISTINGUE « DÉPLOYÉ » DE « CRU DÉPLOYÉ »
# -----------------------------------------------------------------------------
#
# Une pile qui répond 200 ne prouve rien sur le code qu'elle sert : la pile
# locale de ce projet a servi pendant des heures du code vieux d'une journée, en
# répondant parfaitement, sans que rien ne le signale. Ce qui le prouve, c'est de
# comparer l'empreinte gravée dans l'image à celle que le dépôt produit
# maintenant, par la même formule — `api/docker/empreinte.sh`, appelée des deux
# côtés.
# -----------------------------------------------------------------------------

EMPREINTE_ATTENDUE="$(empreinte_attendue)"
dire "empreinte attendue (depot) : $EMPREINTE_ATTENDUE"
dire "empreinte servie   (image) : $EMPREINTE_SERVIE"

if [ "$EMPREINTE_SERVIE" != "$EMPREINTE_ATTENDUE" ]; then
    revenir_en_arriere \
        "la pile sert l'empreinte $EMPREINTE_SERVIE alors que le commit $TAG_CIBLE en produit $EMPREINTE_ATTENDUE : l'image ne contient pas ce code" \
        || exit 1
fi

hote_inconnu_ferme || revenir_en_arriere \
    "le front a REPONDU a un Host inconnu : le bloc par defaut en 444 ne joue plus" \
    || exit 1

CODE_RACINE="$(curl -sS --max-time 10 -o /dev/null -w '%{http_code}' \
    -H "Host: $DOMAINE" "http://127.0.0.1:$PORT/" || true)"
if [ "$CODE_RACINE" != "200" ]; then
    revenir_en_arriere "la racine du front rend « $CODE_RACINE » et non 200" || exit 1
fi
dire "racine du front : 200."

# -----------------------------------------------------------------------------
# LE MÊME CONTRÔLE, POUR LE FRONT — et il manquait
# -----------------------------------------------------------------------------
#
# Une racine qui rend 200 ne prouve rien sur le CODE qu'elle sert, exactement comme
# une pile qui répond 200 ne prouvait rien sur celui de l'API. Et l'empreinte
# ci-dessus n'en dit rien non plus : elle ne hache que des fichiers de `api/`.
#
# Mesuré le 20 septembre 2026, un déploiement de front pur a franchi les trois
# portes du flux sans qu'aucune n'ait regardé le front. Trois calculs indépendants
# de part et d'autre de ce déploiement rendaient tous la même valeur : la porte
# publique a comparé une égalité garantie d'avance.
#
# `app/empreinte.sh` est la source unique de l'autre moitié : l'image la grave dans
# `dist/paquet.txt` à la construction, le dépôt la recalcule ici.
# -----------------------------------------------------------------------------

PAQUET_ATTENDU="$( cd app && sh empreinte.sh )"
PAQUET_SERVI="$(curl -fsS --max-time 10 -H "Host: $DOMAINE" \
    "http://127.0.0.1:$PORT/paquet.txt" 2>/dev/null | tr -d '\r\n' || true)"

dire "paquet attendu (depot) : $PAQUET_ATTENDU"
dire "paquet servi   (image) : ${PAQUET_SERVI:-<absent>}"

if [ -z "$PAQUET_SERVI" ]; then
    # **Absent n'est pas faux, et ne déclenche donc PAS le retour arrière.** Une
    # image construite avant que ce fichier n'existe ne porte rien : revenir à une
    # image ENCORE plus ancienne ne réparerait rien et casserait un déploiement
    # sain. On échoue en le disant, et c'est au lecteur de reconstruire.
    crier "le front ne sert pas /paquet.txt : rien a comparer."
    crier "LE REMEDE EST UNE RECONSTRUCTION, PAS UN RETOUR ARRIERE."
    crier "Relancez le flux « Deploiement » en mode « production » sur ce commit : ce mode"
    crier "reconstruit toujours (RECONSTRUIRE=1). Un retour arriere remettrait en service une"
    crier "image ENCORE plus ancienne, qui ne porte pas davantage ce fichier."
    crier "Si cela se repete, une couche de cache a ete reprise a tort — reconstruire sans cache :"
    crier "  docker compose --env-file .env --env-file $ETAT/env -f compose.yaml -f compose.prod.yaml build --no-cache app"
    exit 1
fi

if [ "$PAQUET_SERVI" != "$PAQUET_ATTENDU" ]; then
    revenir_en_arriere \
        "le front sert le paquet $PAQUET_SERVI alors que le commit $TAG_CIBLE en produit $PAQUET_ATTENDU : l'image du front ne contient pas ce code" \
        || exit 1
fi
dire "le front sert bien le paquet de ce commit."

# -----------------------------------------------------------------------------
# Succès — on enregistre, puis on fait le ménage
# -----------------------------------------------------------------------------

if [ -n "${SHA_RETOUR:-}" ] && [ "$SHA_RETOUR" != "$SHA_CIBLE" ]; then
    printf '%s\n' "$SHA_RETOUR" > "$ETAT/precedent"
fi
printf '%s\n' "$SHA_CIBLE" > "$ETAT/courant"
printf '%s\t%s\tsha=%s\tetiquette=%s\tempreinte=%s\n' \
    "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$MODE" "$SHA_CIBLE" "$TAG_CIBLE" "$EMPREINTE_SERVIE" \
    >> "$JOURNAL"

# Ménage des images. `prune` seul ne suffirait pas : les nôtres sont étiquetées,
# donc jamais « pendantes ». On garde les N plus récentes par dépôt — `docker
# image ls` les rend déjà de la plus récente à la plus ancienne — et JAMAIS celle
# qui tourne ni celle du retour arrière. C'est la seule règle qui compte ; le
# reste n'est que de la place disque.
for depot in api app; do
    docker image ls --format '{{.Tag}}' "$PREFIXE/$depot" 2>/dev/null \
        | grep -v '^<none>$' \
        | tail -n "+$((ETIQUETTES_CONSERVEES + 1))" \
        | while read -r etiquette; do
            if [ "$etiquette" = "$TAG_CIBLE" ]; then
                continue
            fi
            if [ -n "${TAG_RETOUR:-}" ] && [ "$etiquette" = "$TAG_RETOUR" ]; then
                continue
            fi
            docker image rm "$PREFIXE/$depot:$etiquette" >/dev/null 2>&1 || true
        done
done
docker image prune -f >/dev/null 2>&1 || true

# -----------------------------------------------------------------------------
# Situer les lieux nouveaux — APRÈS tous les contrôles, et sans pouvoir échouer
# -----------------------------------------------------------------------------
#
# `heritage:resoudre-les-lieux --en-ligne`, sans `--forcer` : seuls les lieux
# jamais situés sont traités, en local puis, pour ceux que les référentiels ne
# connaissent pas, auprès d'OpenStreetMap Nominatim (seule la chaîne du lieu
# sort ; `HERITAGE_GEOCODAGE_EN_LIGNE=0` dans `.env` coupe cette partie).
#
# POURQUOI ICI, ET PAS DANS L'ENTRYPOINT NI AVANT LES SONDES. Parce que cette
# étape dépend d'un tiers, et qu'un tiers ne doit JAMAIS pouvoir faire échouer ni
# ralentir un déploiement :
#   - elle vient APRÈS l'enregistrement du succès et le ménage des images : rien
#     de ce qui décide du retour arrière ne l'attend, et elle ne peut plus en
#     déclencher un ;
#   - son échec est ABSORBÉ par le `if` (`set -e` ne s'applique pas à une
#     condition) : Nominatim tombé, base lente, commande en erreur — on le dit,
#     et le déploiement reste réussi. Le service `lieux` rejouera cette nuit ;
#   - elle est BORNÉE par `timeout` : au plus 200 requêtes à une par seconde,
#     trois à quatre minutes dans le pire cas normal ; cinq minutes coupent un
#     tiers qui répondrait lentement à chacune. Le flux dispose de 35 minutes
#     (`command_timeout` de deploy.yml). Si `timeout` coupe, c'est le client
#     Compose qui s'arrête ; la commande peut finir dans le conteneur, et ce
#     qu'elle écrit reste juste — elle n'écrit qu'au `flush` final ;
#   - l'entrypoint aurait été le pire endroit : il est sur le chemin de la sonde
#     de santé, et une minute de géocodage y aurait été une minute de 502.
#
# Sa sortie reste dans le journal du flux : c'est là qu'on lit, après un
# réimport, combien de lieux restent hors de la carte.
dire "lieux : resolution des lieux non situes (--en-ligne), hors du chemin critique"
if timeout 300 docker compose \
        --env-file .env --env-file "$ETAT/env" \
        -f compose.yaml -f compose.prod.yaml \
        exec -T api php bin/console heritage:resoudre-les-lieux --en-ligne --no-interaction; then
    dire "lieux : resolution terminee."
else
    crier "lieux : la resolution a echoue ou depasse 5 min (code $?) — SANS EFFET sur ce deploiement."
    crier "lieux : le service « lieux » la rejouera cette nuit ; journal : logs --tail=100 lieux"
fi

dire "DEPLOIEMENT REUSSI — $SHA_CIBLE, empreinte $EMPREINTE_SERVIE"
dire "retour arriere disponible vers : $(cat "$ETAT/precedent" 2>/dev/null || echo aucun)"
