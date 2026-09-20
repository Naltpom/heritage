#!/usr/bin/env bash
# =============================================================================
#  Les trois appuis de la porte du paquet — gardés, pas commentés.
# =============================================================================
#
# `deploy.yml` compare l'empreinte des sources du front à ce que la pile sert sur
# `/paquet.txt`. C'est la porte qui attrape le cas que la sonde `sante.php` ne
# voit pas : un front qui se construit, démarre, et sert du contenu périmé.
#
# Elle repose sur trois choses, et **aucune n'appartient au flux de déploiement** :
# un script dans `app/`, une ligne du `Dockerfile`, une `map` de `nginx.conf`.
# Toutes trois peuvent disparaître par un remaniement parfaitement raisonnable,
# sans que rien ne s'allume — et la porte deviendrait alors muette ou menteuse.
#
# Le précédent est dans ce même fichier de CI, qui garde déjà
# `location = /sante.php` avec ce motif : « Sans elle, le déploiement ne peut
# plus vérifier ce qu'il déploie. » C'est le même argument, appliqué à la
# seconde sonde. On n'invente pas une convention, on l'étend.
#
# ── Pourquoi la troisième garde évalue au lieu de lire ──────────────────────
#
# C'est celle qui compte, et c'est la seule dont l'échec serait SILENCIEUX.
#
# Si `/paquet.txt` tombait un jour sous le cache long, la pile servirait une
# valeur figée — donc **identique quoi qu'on déploie**. La porte comparerait
# alors cette valeur à elle-même et rendrait **vert sur exactement le cas
# qu'elle existe pour attraper**. Une porte qui se trahit ainsi est pire que pas
# de porte : elle certifie le défaut.
#
# Un `grep` sur le texte de la `map` ne protégerait pas de ça. Il dirait qu'une
# chaîne est présente, pas qu'un chemin est épargné — et la réécriture qu'on
# craint est justement celle qui change l'effet sans toucher aux mots qu'on
# aurait cherchés. On **évalue** donc la `map` comme nginx le ferait : chaînes
# exactes d'abord, puis expressions dans l'ordre du fichier, repli sur
# `default`. Ce qui est vérifié est la valeur qui sortirait réellement.
#
# ── Et pourquoi la première LANCE au lieu de constater ──────────────────────
#
# Un `test -f` dirait qu'`empreinte.sh` existe. Un fichier présent mais cassé —
# renommé à l'intérieur, dépendance disparue, `sha256sum` absent — rendrait
# pourtant du vert. On le lance, et on exige les douze hexadécimaux que
# `deploy.yml` compare.
# =============================================================================
set -euo pipefail

racine="$(cd "$(dirname "$0")/../.." && pwd)"
nginx="$racine/app/nginx.conf"
dockerfile="$racine/app/Dockerfile"
sonde="/paquet.txt"
manquements=0

echouer() {
    printf '::error::%s\n' "$1" >&2
    manquements=$((manquements + 1))
}

# Le dialecte d'expressions réellement disponible, décidé une fois.
#
# nginx compile ses motifs avec **PCRE**, donc `-P` est le dialecte juste. Mais
# il n'est pas partout : le git-bash du poste rend « -P supports only unibyte
# and UTF-8 locales » même sous `LC_ALL=C`, faute d'un grep compilé avec PCRE.
# On retombe alors sur `-E` (POSIX étendu), qui traite correctement tout ce
# qu'une map de cache écrit en pratique — ancrages, classes, alternatives — et
# qui ne diffère de PCRE que sur des constructions (`\d`, anticipations) qu'on
# ne trouve pas ici.
#
# **Le repli est ANNONCÉ.** Une garde qui change discrètement de précision est
# une garde dont on ne sait plus ce qu'elle prouve.
if printf 'x' | LC_ALL=C grep -qP -- 'x' 2>/dev/null; then
    DIALECTE='-P'
elif printf 'x' | grep -qE -- 'x' 2>/dev/null; then
    DIALECTE='-E'
    printf 'Note — grep -P indisponible ici : les motifs de la map sont évalués en POSIX étendu (-E).\n'
else
    printf '::error::Ni grep -P ni grep -E ne fonctionnent : la map de cache ne peut pas être évaluée.\n' >&2
    exit 1
fi

# `$sonde` correspond-elle à cette expression nginx ?
#
# **Un grep qui ÉCHOUE n'est pas un grep qui ne trouve rien**, et la distinction
# a été mesurée ici même : un `-P` refusé sort en **code 2**, et un `if grep …`
# lit ce 2 comme un faux — c'est-à-dire « la sonde n'est pas couverte ».
# L'instrument est cassé et la garde annonce que tout va bien. C'est exactement
# la maladie que cette garde existe pour soigner, attrapée dans la garde
# elle-même, et c'est le sabotage délibéré qui l'a révélée.
#
# On distingue donc les trois codes : 0 trouvé, 1 non trouvé, le reste **panne**.
correspond() {
    local expression="$1"
    shift
    local code

    printf '%s' "$sonde" | LC_ALL=C grep -q "$@" "$DIALECTE" -- "$expression" && code=0 || code=$?

    case "$code" in
        0) return 0 ;;
        1) return 1 ;;
        *)
            echouer "Impossible d'évaluer le motif « ${expression} » de la map de cache (grep a rendu ${code}). La garde ne peut pas conclure, et elle refuse de conclure au vert."
            return 1
            ;;
    esac
}

# ─────────────────────────────────────────────────────────────────────────────
# Garde 1 — `app/empreinte.sh` existe ET rend douze hexadécimaux.
# ─────────────────────────────────────────────────────────────────────────────
if [ ! -f "$racine/app/empreinte.sh" ]; then
    echouer "app/empreinte.sh a disparu. La porte du paquet de deploy.yml n'a plus de quoi calculer le côté « attendu » : elle ne peut plus rien comparer."
else
    empreinte="$(cd "$racine/app" && sh empreinte.sh 2>/dev/null || true)"

    if printf '%s' "$empreinte" | grep -qE '^[0-9a-f]{12}$'; then
        printf 'Garde 1 — app/empreinte.sh rend « %s », douze hexadécimaux.\n' "$empreinte"
    else
        echouer "app/empreinte.sh existe mais ne rend pas douze hexadécimaux (obtenu : « ${empreinte} »). Un fichier présent et cassé rend du vert : c'est pour ça qu'on le LANCE au lieu de constater sa présence."
    fi
fi

# ─────────────────────────────────────────────────────────────────────────────
# Garde 2 — le `Dockerfile` écrit bien `dist/paquet.txt` dans l'image.
#
# Sans cette ligne, l'image se construit, nginx démarre, tout est vert — et
# `/paquet.txt` rend 404. La porte ne lirait plus rien à comparer.
# ─────────────────────────────────────────────────────────────────────────────
if [ ! -f "$dockerfile" ]; then
    echouer "app/Dockerfile est introuvable."
elif grep -qE '>[[:space:]]*dist/paquet\.txt' "$dockerfile"; then
    printf 'Garde 2 — app/Dockerfile écrit bien dist/paquet.txt.\n'
else
    echouer "app/Dockerfile n'écrit plus dist/paquet.txt. L'image se construira, nginx démarrera, et /paquet.txt rendra 404 : la porte du paquet n'aurait plus rien à lire."
fi

# ─────────────────────────────────────────────────────────────────────────────
# Garde 3 — `/paquet.txt` n'est PAS mis en cache.
#
# On évalue la `map $uri $cache_actifs` à la façon de nginx et on regarde la
# valeur qui sortirait pour `/paquet.txt`.
# ─────────────────────────────────────────────────────────────────────────────
if [ ! -f "$nginx" ]; then
    echouer "app/nginx.conf est introuvable."
else
    bloc="$(sed -n '/map \$uri \$cache_actifs[[:space:]]*{/,/^}/p' "$nginx")"

    if [ -z "$bloc" ]; then
        echouer "La map « \$uri \$cache_actifs » a disparu de app/nginx.conf. Sans elle, on ne sait plus ce qui est mis en cache — et /paquet.txt pourrait l'être."
    else
        valeur=""
        defaut=""

        # Chaque entrée : « motif  "valeur"; ». On retire guillemets et
        # point-virgule, puis on classe : exact, expression, ou défaut.
        while IFS= read -r ligne; do
            ligne="$(printf '%s' "$ligne" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
            case "$ligne" in
                ''|'#'*|'map '*|'}'*) continue ;;
            esac

            motif="$(printf '%s' "$ligne" | awk '{print $1}' | tr -d '"')"
            rendu="$(printf '%s' "$ligne" | sed -e 's/^[^[:space:]]*[[:space:]]*//' -e 's/;[[:space:]]*$//' | tr -d '"')"

            if [ "$motif" = 'default' ]; then
                defaut="$rendu"
                continue
            fi

            case "$motif" in
                '~*'*)  # expression, casse ignorée
                    if correspond "${motif#~\*}" -i && [ -z "$valeur" ]; then
                        valeur="$rendu"
                    fi
                    ;;
                '~'*)   # expression, casse respectée
                    if correspond "${motif#\~}" && [ -z "$valeur" ]; then
                        valeur="$rendu"
                    fi
                    ;;
                *)      # chaîne exacte : elle prime sur les expressions
                    if [ "$motif" = "$sonde" ]; then
                        valeur="$rendu"
                    fi
                    ;;
            esac
        done <<EOF
$bloc
EOF

        [ -z "$valeur" ] && valeur="$defaut"

        # Ce qui est refusé, c'est un cache qui SURVIT à un déploiement. Une
        # entrée explicite « no-cache » pour /paquet.txt est donc parfaitement
        # acceptable — c'est l'effet qu'on vérifie, pas la forme.
        if printf '%s' "$valeur" | grep -qE 'max-age=[1-9]|immutable'; then
            echouer "La map de cache de app/nginx.conf couvre désormais ${sonde} : elle rendrait « ${valeur} ». La sonde serait servie depuis le cache, donc IDENTIQUE quoi qu'on déploie — et la porte du paquet rendrait vert sur exactement le cas qu'elle existe pour attraper."
        else
            printf 'Garde 3 — %s rend « %s » : la sonde n%s pas mise en cache.\n' "$sonde" "$valeur" "'est"
        fi
    fi
fi

if [ "$manquements" -gt 0 ]; then
    printf '::error::%d appui(s) de la porte du paquet ne sont plus gardés.\n' "$manquements" >&2
    exit 1
fi

printf 'Les trois appuis de la porte du paquet sont en place.\n'
