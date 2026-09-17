# Gazetteer — la résolution des lieux en coordonnées

Ces fichiers sont de la **donnée publique de référence, délibérément versionnée**.
Ils ne nomment personne, ne contiennent aucune donnée familiale, et l'ADR 0006 ne
les vise pas — il vise le GEDCOM. **Ne les supprimez pas par réflexe de prudence :**
sans eux, `heritage:resoudre-les-lieux` ne résout rien et la carte reste vide.

Ils sont lus **uniquement par la commande**, jamais sur un chemin de lecture HTTP.

## Pourquoi ils sont dans le dépôt et non téléchargés

Parce qu'une résolution de lieu ne doit dépendre ni du réseau ni d'un tiers. Le
dossier n'est exclu ni par `.gitignore` ni par `api/.dockerignore`, donc il est
**dans l'image de production** : la commande tourne sur le VPS sans montage, sans
téléchargement et sans qu'un nom de commune de la famille ne sorte de la machine.
C'est très exactement ce qui rend ce montage compatible avec l'ADR 0006.

## Pourquoi `ressources/` et pas `data/`

`data/` est le mot interdit du dépôt : `/data/` est ignoré à la racine parce qu'il
désigne les données d'instance, GEDCOM compris. Le jour où quelqu'un désancre cette
règle, un gazetteer rangé là disparaîtrait de tous les clones **en silence**.

## Les fichiers

| Fichier | Lignes | Contenu |
|---|---|---|
| `communes-fr.csv` | 114 838 | `nom;departement;region;lat;lon;population;rang` — `rang` vaut 0 pour une commune (GeoNames `ADM4`, 34 742 lignes) et 1 pour un lieu habité (`PPL*` : hameaux et lieux-dits). Les hameaux sont là **exprès** : un `PLAC` de GEDCOM en nomme souvent un. |
| `villes-monde.csv` | 34 145 | `nom;pays_iso2;lat;lon;population` — villes de plus de 15 000 habitants. |
| `departements.csv` | 109 | `code;nom` — pour rapprocher « Dordogne » du code `24`. |
| `regions.csv` | 26 | `code;nom` — idem pour les régions. |

`departement` et `region` dans `communes-fr.csv` sont les **codes INSEE**. Vérifié à
la capture : le champ `admin1` de GeoNames coïncide bien avec le code INSEE de région,
et `admin2` avec celui de département — les 34 742 communes en portent un.

Les lignes commençant par `#` sont des commentaires : provenance, licence, date de
capture et SHA-256 de l'archive amont. Le séparateur est `;` et un `;` présent dans
un nom a été remplacé par `,` à la capture.

## Licences — à ne pas perdre

- **GeoNames** (`communes-fr.csv`, `villes-monde.csv`) — **CC BY 4.0**. L'attribution
  est **obligatoire** : « © GeoNames » figure dans l'en-tête de chaque fichier, et doit
  rester lisible quelque part si ces données venaient à être affichées telles quelles.
- **Etalab `@etalab/decoupage-administratif` 6.0.0** (`departements.csv`, `regions.csv`)
  — **Licence Ouverte 2.0**.

## La limite connue : `villes-monde.csv` est en anglais

GeoNames écrit le nom **principal** d'une ville, qui est l'anglais : `Algiers`,
jamais `Alger` ; `Naples`, jamais `Napoli`. Un `PLAC` français nommant une ville
étrangère — « Alger, Algérie » — **ne se résout donc pas**, et retombe dans les
non situés.

Ce n'est pas rattrapé par un troisième fichier de données : les noms alternatifs
de GeoNames pèsent plusieurs centaines de mégaoctets pour un gain qui, sur cet
arbre, se compte en unités. La réponse est une **correction manuelle** en base
(`source = 'manuel'`), que la commande ne réécrit plus jamais. La commande liste
les non résolus par nombre d'occurrences, précisément pour qu'on sache lesquels
valent la peine.

## Régénérer

```bash
curl -O https://download.geonames.org/export/dump/FR.zip           # sha256 6440bc7ec69c7c76549ade2cbf38a22f249debc8dbaf4d5e410aed3049310750
curl -O https://download.geonames.org/export/dump/cities15000.zip  # sha256 41ab6152eed8d36c4a0ce033bef9a03c97d8edb1f4ee5898e942c2b83059f734
unzip FR.zip cities15000.zip
npm pack @etalab/decoupage-administratif && tar -xzf etalab-*.tgz package/data/

# communes et lieux habités : nom;dept;region;lat;lon;pop;rang
awk -F'\t' '
  ($8=="ADM4"||$8=="PPL"||$8=="PPLX"||$8=="PPLL"||$8=="PPLA"||$8=="PPLA2"||$8=="PPLA3"||$8=="PPLQ") && $12!="" {
    r = ($8=="ADM4") ? 0 : 1; n=$2; gsub(/;/,",",n);
    printf "%s;%s;%s;%.5f;%.5f;%s;%d\n", n, $12, $11, $5, $6, ($15==""?0:$15), r
  }' FR.txt

# villes du monde : nom;pays;lat;lon;pop
awk -F'\t' '$5!="" && $6!="" { n=$2; gsub(/;/,",",n); printf "%s;%s;%.5f;%.5f;%s\n", n, $9, $5, $6, ($15==""?0:$15) }' cities15000.txt
```

Remettre ensuite les en-têtes `#` (provenance, licence, date, SHA-256 de l'archive
**amont**, pas du CSV produit). Capture courante : **2026-09-17**.

⚠️ Le paquet Etalab `decoupage-administratif` **ne porte aucune coordonnée** — il ne
sert qu'à la table code → nom des départements et des régions. Les coordonnées viennent
de GeoNames, et de lui seul. C'est une erreur qui a été commise une fois.
