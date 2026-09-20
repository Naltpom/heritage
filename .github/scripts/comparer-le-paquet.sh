#!/usr/bin/env bash
# La comparaison de la porte 4 — le paquet servi contre celui du dépôt.
#
# ── POURQUOI ELLE EST DANS UN FICHIER ET NON DANS LE GABARIT ─────────────────
#
# Parce qu'une garde qu'on n'a pas vue rougir ne garde rien, et qu'une comparaison
# écrite en ligne dans un `run:` ne peut être éprouvée qu'en cassant un vrai
# déploiement. Ici, elle s'appelle à la main :
#
#     ATTENDU=abc123 SERVI=abc123 bash .github/scripts/comparer-le-paquet.sh   # vert
#     ATTENDU=abc123 SERVI=zzz999 bash .github/scripts/comparer-le-paquet.sh   # ROUGE
#     ATTENDU=abc123 SERVI=       bash .github/scripts/comparer-le-paquet.sh   # ROUGE
#
# C'est la leçon de la porte des tests du front, posée le même jour : on ne
# déclare pas une porte active sans l'avoir vue échouer.
#
# ── CE QU'ELLE FAIT, ET CE QU'ELLE NE FAIT PAS ───────────────────────────────
#
# Elle échoue bruyamment. **Elle ne déclenche PAS le retour arrière** — c'est la
# porte 2, sur le VPS, qui en dispose (`revenir_en_arriere`), et elle porte le même
# contrôle. Celle-ci est la seconde source : elle regarde depuis l'extérieur, par le
# DNS, le certificat et le nginx de l'hôte. Si la porte 2 a laissé passer et que
# celle-ci échoue, ce n'est pas l'image qui est en cause, c'est le chemin qui y mène.
set -eu

ATTENDU="${ATTENDU:-}"
SERVI="${SERVI:-}"

if [ -z "$ATTENDU" ]; then
    echo "::error::Le paquet attendu est vide : « app/empreinte.sh » n'a rien rendu."
    echo "C'est un défaut de l'instrument, pas du déploiement. Vérifier que le script"
    echo "existe et s'exécute : cd app && sh empreinte.sh"
    exit 1
fi

if [ -z "$SERVI" ]; then
    # **Absent n'est pas faux.** Une image construite avant que ce contrôle
    # n'existe ne porte pas ce fichier. On le dit sans accuser le code déployé.
    echo "::error::Le domaine ne sert pas /paquet.txt."
    echo "Trois causes, dans cet ordre de vraisemblance :"
    echo "  - l'image du front est antérieure à ce contrôle : la reconstruire suffit ;"
    echo "  - la racine servie n'est pas celle de l'image (volume, ou root nginx ailleurs) ;"
    echo "  - le vhost de l'hôte ne mène pas à cette pile."
    exit 1
fi

if [ "$SERVI" != "$ATTENDU" ]; then
    echo "::error::Le domaine sert le paquet « $SERVI » et non « $ATTENDU »."
    echo "Le front servi n'est PAS celui que ce commit produit. À regarder, dans cet ordre :"
    echo "  - le conteneur « app » tourne-t-il sur l'étiquette du commit déployé ?"
    echo "    docker compose ps --format '{{.Service}} {{.Image}}'"
    echo "  - un conteneur plus ancien serait-il resté debout (« up -d » sans recréation) ?"
    echo "  - le vhost /etc/nginx/sites-available/<domaine> mène-t-il bien à APP_PORT ?"
    echo "  - une couche de cache de construction aurait-elle été reprise à tort ?"
    echo "    Reconstruire sans cache tranche la question."
    exit 1
fi

echo "Le domaine public sert bien le paquet $SERVI."
