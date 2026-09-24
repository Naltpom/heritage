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

**Une exception, bornée et optionnelle : `--en-ligne`.** Pour les seuls lieux que ces
fichiers ne savent pas lire (passe F, au moins deux morceaux), la commande peut
interroger OpenStreetMap Nominatim — voir « La limite connue » plus bas. Ces fichiers
restent la première source, et la seule qui tourne sans réseau.

## Pourquoi `ressources/` et pas `data/`

`data/` est le mot interdit du dépôt : `/data/` est ignoré à la racine parce qu'il
désigne les données d'instance, GEDCOM compris. Le jour où quelqu'un désancre cette
règle, un gazetteer rangé là disparaîtrait de tous les clones **en silence**.

## Les fichiers

| Fichier | Lignes | Contenu |
|---|---|---|
| `communes-fr.csv` | 114 838 | `nom;departement;region;lat;lon;population;rang` — `rang` vaut 0 pour une commune (GeoNames `ADM4`, 34 742 lignes) et 1 pour un lieu habité (`PPL*` : hameaux et lieux-dits). Les hameaux sont là **exprès** : un `PLAC` de GEDCOM en nomme souvent un. |
| `villes-monde.csv` | 34 145 | `nom;pays_iso2;lat;lon;population` — villes de plus de 15 000 habitants. |
| `villes-canada.csv` | 25 086 | **Même forme, fondu dans le même index** au chargement. Voir ci-dessous. |
| `departements.csv` | 109 | `code;nom` — pour rapprocher « Dordogne » du code `24`. |
| `regions.csv` | 26 | `code;nom` — idem pour les régions. |

`departement` et `region` dans `communes-fr.csv` sont les **codes INSEE**. Vérifié à
la capture : le champ `admin1` de GeoNames coïncide bien avec le code INSEE de région,
et `admin2` avec celui de département — les 34 742 communes en portent un.

Les lignes commençant par `#` sont des commentaires : provenance, licence, date de
capture et SHA-256 de l'archive amont. Le séparateur est `;` et un `;` présent dans
un nom a été remplacé par `,` à la capture.

## Licences — à ne pas perdre

- **GeoNames** (`communes-fr.csv`, `villes-monde.csv`, `villes-canada.csv`) — **CC BY 4.0**. L'attribution
  est **obligatoire** : « © GeoNames » figure dans l'en-tête de chaque fichier, et doit
  rester lisible quelque part si ces données venaient à être affichées telles quelles.
- **Etalab `@etalab/decoupage-administratif` 6.0.0** (`departements.csv`, `regions.csv`)
  — **Licence Ouverte 2.0**.

## Pourquoi un fichier pays entier pour le Canada

Parce que **cet arbre a une branche canadienne**, et que le fichier mondial ne la voit
pas. `villes-monde.csv` s'arrête à 15 000 habitants ; les naissances de cet arbre sont
à **Castor** (Alberta, ~900 habitants) et autour de **Trochu** (Alberta, ~1 000).

Vérifié, et c'est ce qui a tranché : Castor et Trochu sont absents de `cities15000`
**et** de `cities500`. Seul le fichier pays les porte — et il pèse **six fois moins**
que `cities500`, qui ne les aurait pas eus.

C'est exactement la même logique que pour la France, dont on prend `FR.txt` entier et
non les seules grandes villes : une généalogie se passe dans des villages.

**Le jour où l'arbre gagne une branche dans un autre pays**, c'est le même geste :
`<ISO2>.zip` chez GeoNames, la même commande `awk`, le même en-tête, et une ligne dans
`AppariementDesLieux::charger()`. Le fichier porte `;CA;` en deuxième colonne comme le
fichier mondial porte son ISO2 : rien d'autre à changer, la passe « ville du monde »
filtre déjà là-dessus.

## La limite connue : `villes-monde.csv` est en anglais — et ce qui la rattrape

GeoNames écrit le nom **principal** d'une ville, qui est l'anglais : `Algiers`,
jamais `Alger` ; `Naples`, jamais `Napoli`. Un `PLAC` français nommant une ville
étrangère — « Alger, Algérie » — ou un village hors de France et du Canada ne se
résout donc **pas localement**, et tombe en passe F.

Les noms alternatifs de GeoNames pèsent plusieurs centaines de mégaoctets pour un
gain qui, sur cet arbre, se compte en unités : ils ne sont toujours pas là. Le
rattrapage se fait **en ligne**, par `heritage:resoudre-les-lieux --en-ligne` :

- **OpenStreetMap Nominatim**, pour les seules passes F dont la chaîne a au moins
  deux morceaux (« Liège, Belgique ») — **jamais** pour une passe D (homonymes), où
  un géocodeur choisirait à notre place ;
- **seule la chaîne du lieu sort**, avec un `User-Agent` qui identifie l'instance ;
  une requête par seconde au plus (politique d'usage de Nominatim) ;
- n'est retenu qu'un résultat **au niveau d'une commune ou plus fin** — jamais le
  pays ou la région seuls. Le lieu porte alors `source = 'nominatim'`, confiance 50 ;
- un lieu introuvable garde `source = 'nominatim'` **sans coordonnées** : c'est la
  mémoire de l'échec, et il n'est pas redemandé avant **trente jours** ;
- `HERITAGE_GEOCODAGE_EN_LIGNE=0` coupe tout cela pour l'instance.

En production, la commande tourne ainsi après chaque déploiement et chaque nuit
(service `lieux` de `compose.prod.yaml`). Ce qui reste est une **correction
manuelle** en base (`source = 'manuel'`), que la commande ne réécrit jamais. Elle
liste les non résolus par nombre d'occurrences, précisément pour qu'on sache
lesquels valent la peine.

## Les noms de pays

Le pays cité au bout d'une chaîne se reconnaît sous **son nom français, quel qu'il
soit** : `Tree\Service\NomsDesPays` porte les 249 territoires du CLDR (ICU 78.3,
recopiés de `symfony/intl`, Unicode License v3), plus des alias historiques que le
CLDR ne dira jamais (`Angleterre`, `Écosse`, `Hollande`, `Zaïre`…). Ce n'est pas un
fichier de ce dossier parce qu'il n'appartient à aucun millésime GeoNames ; sa
provenance et sa régénération sont écrites en tête de la classe.

## Régénérer

```bash
curl -O https://download.geonames.org/export/dump/FR.zip           # sha256 6440bc7ec69c7c76549ade2cbf38a22f249debc8dbaf4d5e410aed3049310750
curl -O https://download.geonames.org/export/dump/cities15000.zip  # sha256 41ab6152eed8d36c4a0ce033bef9a03c97d8edb1f4ee5898e942c2b83059f734
curl -O https://download.geonames.org/export/dump/CA.zip           # sha256 e70ca11115a1585abb37d17447ab0bf52b31568b490da4ceea27c352030ff945
unzip FR.zip cities15000.zip CA.zip
npm pack @etalab/decoupage-administratif && tar -xzf etalab-*.tgz package/data/

# communes et lieux habités : nom;dept;region;lat;lon;pop;rang
awk -F'\t' '
  ($8=="ADM4"||$8=="PPL"||$8=="PPLX"||$8=="PPLL"||$8=="PPLA"||$8=="PPLA2"||$8=="PPLA3"||$8=="PPLQ") && $12!="" {
    r = ($8=="ADM4") ? 0 : 1; n=$2; gsub(/;/,",",n);
    printf "%s;%s;%s;%.5f;%.5f;%s;%d\n", n, $12, $11, $5, $6, ($15==""?0:$15), r
  }' FR.txt

# villes du monde : nom;pays;lat;lon;pop
awk -F'\t' '$5!="" && $6!="" { n=$2; gsub(/;/,",",n); printf "%s;%s;%.5f;%.5f;%s\n", n, $9, $5, $6, ($15==""?0:$15) }' cities15000.txt

# lieux habités du Canada : la MÊME forme, pays forcé à CA
awk -F'\t' '($7=="P"||$8=="ADM3"||$8=="ADM2") && $5!="" && $6!="" { n=$2; gsub(/;/,",",n); printf "%s;CA;%.5f;%.5f;%s\n", n, $5, $6, ($15==""?0:$15) }' CA.txt | sort -u
```

Remettre ensuite les en-têtes `#` (provenance, licence, date, SHA-256 de l'archive
**amont**, pas du CSV produit). Capture courante : **2026-09-17**.

⚠️ Le paquet Etalab `decoupage-administratif` **ne porte aucune coordonnée** — il ne
sert qu'à la table code → nom des départements et des régions. Les coordonnées viennent
de GeoNames, et de lui seul. C'est une erreur qui a été commise une fois.
