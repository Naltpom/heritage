# ADR 0005 — Le moteur de placement est recalculé, pas stocké

Date : 2026-09-15 · Statut : acceptée

## Contexte

> **Prémisse corrigée le 2026-09-15.** La première rédaction de cet ADR affirmait que les
> scripts d'origine étaient **perdus**, et qu'il fallait déduire l'algorithme de sa seule
> sortie. **C'était faux sur deux points**, et le § 7.1 du dossier de mission a été réécrit
> en conséquence. Ce qui suit est l'état exact.

**Le code source existe.** Retrouvé dans un scratchpad jamais purgé d'une session du
13-14 septembre 2026, et recopié dans **`docs/moteur-retrouve/`** :

| Fichier | Lignes | Rôle |
|---|---:|---|
| **`graphe.py`** | **624** | **le moteur de placement** — c'est celui-ci qui compte |
| `build_data.py` | 249 | GEDCOM → JSON, calcul des liens de parenté |
| `build_viewer.py` | 389 | greffe la couche interactive sur un SVG **déjà placé** |
| `rendu.py`, `interactif.py` | — | étapes intermédiaires du pipeline |

**Seconde erreur, qui explique la première** : `build_viewer.py` ne contient **pas**
l'algorithme de placement. Il lit un SVG produit par Graphviz (`arbre-complet.svg`,
**229 nœuds**) et n'y ajoute que l'interactivité — à ce stade du chantier, le placement
était délégué à `dot`. Les **377 nœuds** de `graphe.json` viennent d'une génération
ultérieure, calculée par `graphe.py`, **écrit à la main, sans Graphviz ni aucune
dépendance**. Chercher l'algorithme dans `build_viewer.py` et n'y trouver que du Graphviz
est très probablement ce qui a fait conclure qu'il était perdu.

Il reste vrai que la sortie existe et fait référence : `graphe.json`, soit **377 nœuds
positionnés, 377 arêtes**, une boîte englobante de **15 824 × 1 810** unités, racine
`@I500001@` — et le `arbre.html` qui la rend (`viewBox="-7770 -1730 15824 1810"`).

**Ce que la correction change, et ce qu'elle ne change pas.** Elle ne change **aucune** des
décisions de cet ADR : le moteur est réécrit en PHP, recalculé à chaque validation, mis en
cache à deux étages. Aucune de ces raisons ne tenait à l'indisponibilité du code. Elle
change la **nature du travail** — reconstituer devient **porter** — et, par voie de
conséquence, le **critère d'acceptation**, qui se durcit (voir plus bas).

Jusqu'ici, les données étaient figées : une sortie figée suffisait. Elles deviennent
modifiables (ADR 0004), donc le placement doit être **recalculé** à chaque validation. Il faut
réimplémenter le moteur.

Et il y a une difficulté que le contexte d'origine n'avait pas : le graphe doit pouvoir être
calculé **pour un lecteur donné**, puisqu'il inclut ses nœuds « en attente » (§ 6.4) et que
son cercle de visibilité masque des informations (ADR 0003). Un cache naïf donnerait un graphe
par compte.

## Décision

**Porter `docs/moteur-retrouve/graphe.py` en PHP, dans `Tree`, comme service sans état.**
Entrée : un jeu de personnes et de familles en mémoire. Sortie : `{pos, aretes, boite}`.
**Aucun accès à la base dans le calcul** — c'est cette contrainte qui le rend testable contre
`graphe.json`, et elle n'est pas négociable.

**`graphe.py` est la source de vérité de l'algorithme.** Pas `graphe.json`, qui en est la
sortie, ni `arbre.html`, qui en est le rendu. Ce qui suit résume ce que le script fait ; en
cas d'écart entre ce résumé et les 624 lignes, **c'est le script qui a raison**.

- **`y` est imposé** : `y = génération × 150`. Aucune liberté verticale.
- **Largeur de fiche mesurée sur le texte** : `max(104, max(len(nom) × 6.6,
  len(dates) × 5.8) + 28)`. Constantes : `LARG_CAR = 6.6`, `PAD = 14`, `H_FICHE = 40`,
  `H_RANG = 150`, `ESPACE = 20` (fratrie), `GAP_BLOC = 70` (bloc paternel ↔ bloc maternel).
  **Pour une personne vivante, la ligne de dates vaut la constante « vivant·e »** —
  `ligne_dates()` la court-circuite. Ce n'est pas un détail d'affichage : sans cette
  constante, **la largeur de la boîte encoderait la longueur des dates d'une personne
  masquée**, ce qu'aucun test de liste blanche ne verrait (ADR 0003 : tout champ dérivé d'une
  donnée masquée est une donnée masquée). Le portage la conserve — ce qui ferme la fuite
  **et** satisfait le critère d'égalité exacte ci-dessous, les deux exigences pointant ici
  dans le même sens. L'état vivant étant une propriété de la personne et non du lecteur, la
  largeur reste la même pour tous et **l'étage 1 reste partageable**.
- **L'ordre horizontal vient d'une récursion d'ascendance depuis l'ego**, pas de la branche.
  `larg_bloc(p)` mesure un sous-arbre de bas en haut (`bloc_père + 70 + bloc_mère`, ou la
  largeur de la fratrie si elle est plus grande), mémoïsée, avec un garde-fou `vus` **contre
  les boucles de consanguinité** — l'implexe existe dans cet arbre. Puis `pose_bloc(p, x0)`
  descend et **recentre la fratrie sous le barycentre du couple parental** : c'est ce
  recentrage qui produit les liens courts.
- **Ordre de fratrie** : tri par année de naissance, chaque conjoint accolé à son frère ou sa
  sœur ; le conjoint de `p` lui-même est exclu, il possède son propre bloc d'ascendance.
- **Non-ascendants** (cousins, conjoints, descendants) : **12 itérations de point fixe**,
  chacun se centrant sur le barycentre de ses parents déjà placés, avec un repli explicite —
  un conjoint d'abord, sinon le barycentre des enfants. Sans ce repli, une personne au lien
  uniquement descendant tombait à `x = 0` ; le commentaire d'origine parle de « pères à
  7 000 px de leur fils ».
- **Décrochage des chevauchements**, rangée par rangée, par poussée vers la droite
  (`x[b] ≥ x[a] + w/2 + 20 + w/2`), **sans jamais réordonner** : l'ordre vient de la
  récursion et porte la structure.
- **Nœuds-famille** : **losanges 22 × 14** au barycentre des époux, 26 px sous leur rangée.
  Les rangs sont entrelacés — personnes aux rangs pairs, familles aux rangs impairs — d'où
  le `rank = 2 × generation + 1` du modèle de données.
- Enfin **translation globale pour mettre l'ego à `x = 0`**, et boîte englobante avec une
  marge de 60.

**`branch` n'entre pas dans le placement.** Le script la calcule (pour la couleur et pour la
chronologie) mais ne la lit jamais pour poser un nœud. La première rédaction de cet ADR
écrivait « l'ordre horizontal suit la branche puis la fratrie » : c'est faux, et c'était une
déduction faite en regardant la sortie, où l'ordre par récursion d'ascendance *ressemble* à
un ordre par branche.

### Le critère d'acceptation : exact, pas « équivalent »

`graphe.json` est la **référence de non-régression**, et elle est exigeante : on porte un
algorithme **déterministe dont on possède le code**, donc le portage doit reproduire les
positions **exactement**. Tout écart est un bug de portage, pas une variation tolérable.

C'est un durcissement, et il faut savoir contre quoi : la première rédaction comparait des
**invariants** (mêmes générations aux mêmes rangs, ordre des fratries préservé, boîte à
± 10 %) et justifiait cette tolérance par « comparer au pixel près figerait une
implémentation qu'on n'a plus ». On l'a. La justification est tombée avec la prémisse.

Le test compare donc, pour les 377 nœuds : `x`, `y`, `w`, `h` **au centième près** — la
tolérance ne couvre que la représentation des flottants, pas une différence d'algorithme —,
les 377 arêtes à l'identique, et la boîte englobante à l'identique. Un écart d'un seul nœud
fait échouer le test et se diagnostique en relisant les deux implémentations côte à côte,
ce qui est désormais possible.

Deux pièges de portage connus, à cause desquels ce critère exact est utile plutôt que
pénible : la **division flottante** du barycentre (`sum / len`) et **l'ordre d'itération**
des dictionnaires. PHP et Python n'ordonnent pas leurs tableaux associatifs de la même façon
dès qu'on supprime une clé ; le point fixe en 12 passes et le décrochage rangée par rangée
sont sensibles à cet ordre. Un test à ± 10 % ne les aurait jamais vus.

`graphe.json` n'est pas versionné (ADR 0006). Le test d'égalité exacte est donc **marqué
`@group donnees-reelles`** : il ne tourne qu'en local, quand le fichier est présent, et il est
exclu en CI.

La CI, elle, n'a pas d'étalon à comparer — elle ne peut donc vérifier que des **invariants**
sur les fixtures anonymisées : générations aux mêmes rangs, ordre des fratries préservé, aucun
chevauchement de fiches sur une même rangée, ego à `x = 0`, boîte englobante cohérente. C'est
une couverture plus faible, assumée, et **c'est le seul endroit où les invariants subsistent**
— ils ne sont plus le critère d'acceptation du portage, seulement le filet de la CI.

> **Conséquence à ne pas manquer : la CI peut passer au vert sur un portage faux.** Le test
> d'égalité exacte doit être lancé **au moins une fois avant la mise en service**, sur la
> machine du commanditaire, et son résultat consigné. C'est écrit ici parce que personne ne
> le devinera en lisant un tableau de bord vert.

## Le cache : deux étages

Le problème est que le graphe semble dépendre du lecteur. La décision est de séparer ce qui en
dépend de ce qui n'en dépend pas.

### Étage 1 — le placement, commun à tous

Calculé sur **l'arbre validé complet, sans aucun filtre de confidentialité**. Un seul objet en
cache pour toute l'application.

Clé = `placement.{empreinte}` — **empreinte des données structurantes de l'arbre** :
personnes, familles, liens (`tree_person`, `tree_family`, `tree_child`). Ni médias, ni
citations, ni sources — ils ne déplacent aucun nœud, et les inclure ferait recalculer tout
le graphe à chaque photo téléversée. **Aucun identifiant de lecteur dans cette clé.** Le
calcul exact de l'empreinte est dans `docs/modele-donnees.md` § 2.3.

Corollaire : **cet objet ne sort jamais tel quel de l'API.** C'est une structure interne,
servie uniquement à travers l'étage 2. Le « sans filtre » porte sur le calcul du placement,
il n'ouvre aucun mode « sans filtre » dans la sérialisation — que l'ADR 0003 interdit.

Justification du « sans filtre » : **le cercle de visibilité masque des champs, pas des
nœuds.** C'est précisément le risque résiduel accepté en ADR 0003 — la topologie reste
visible. Le placement ne dépend donc pas du lecteur, et le cache peut être partagé. Les deux
décisions sont liées : si on avait choisi de masquer les nœuds hors cercle, il y aurait un
placement par compte et cet étage n'existerait pas. C'est le genre de dépendance qu'on ne voit
qu'une fois qu'un des deux ADR est enfreint.

### Étage 2 — la projection pour un lecteur

À la demande, on prend l'étage 1 et on y applique, **sans rien recalculer** :

- le filtre de sérialisation (cercle de visibilité), qui vide des champs ;
- le calque « en attente » du lecteur, qui **ajoute** ses nœuds proposés.

Seul le second déplace quelque chose. Décision : **les nœuds en attente ne réorganisent pas le
graphe.** Ils sont insérés par un calcul **local** — dans la fratrie ou le couple visé, avec
décalage des seuls voisins immédiats sur la même ligne — jamais par un recalcul global.

Conséquence assumée : un nœud en attente peut être légèrement mal placé, par exemple frôler
une arête lointaine. C'est le prix pour que le graphe reste un objet partagé, et c'est
visuellement acceptable pour un nœud qui porte déjà un traitement distinct « en attente ».

**La projection n'est pas mise en cache.** Elle coûte un parcours de 377 nœuds et quelques
insertions — un parcours complet de cette structure a été mesuré à **0,02 ms** sur les
données réelles (`docs/modele-donnees.md` § 9.3). La mettre en cache par compte
multiplierait le stockage par le nombre de comptes pour économiser moins d'une milliseconde,
et rouvrirait toute la difficulté d'invalidation : une proposition déposée invalide le
graphe d'un seul lecteur, une validation les invalide tous.

Il y a plus grave qu'un mauvais rapport coût/bénéfice, et c'est ce qui ferme le débat : une
clé par lecteur **serait fausse**. Elle ne changerait qu'avec l'empreinte, donc à une
modification *validée* de l'arbre — or déposer un brouillon n'en est pas une. L'auteur d'une
proposition garderait son entrée antérieure et **ne verrait pas son propre nœud en
attente**, c'est-à-dire la seule chose que ce calque existe pour produire.

L'`ETag` servi au client combine l'empreinte de l'étage 1 et celle des demandes en attente du
lecteur — `sha256(empreinte || ':' || empreinte_des_demandes_en_attente_du_lecteur)`, la
seconde valant la chaîne vide quand il n'en a aucune. Un lecteur sans demande en cours
partage donc l'`ETag` de tout le monde, et son navigateur ne retélécharge rien tant que
l'arbre ne bouge pas — c'est-à-dire presque toujours. C'est l'`ETag`, et non la clé de
cache, qui porte le lecteur.

### Invalidation

La clé de l'étage 1 est une **empreinte recalculée depuis la base**, pas un compteur
incrémenté à la main. Une empreinte ne peut pas se désynchroniser ; un compteur qu'on oublie
d'incrémenter dans un chemin d'écriture donne un graphe faux qu'on met des semaines à
comprendre — et il y a plusieurs chemins d'écriture : validation de proposition, édition
d'administrateur, import GEDCOM, correction en base. Le coût d'une agrégation sur 274 lignes
est nul à cette échelle.

**La même empreinte sert de clé aux cercles de visibilité** (ADR 0003) : les deux caches
dépendent de la même donnée, ils s'invalident donc ensemble, et il n'y a **qu'un seul**
mécanisme d'invalidation dans le produit. Il n'existe ni table `core_cache_stamp`, ni
colonne compteur, ni liste de déclencheurs à tenir à jour : c'est cette liste qui était le
défaut du compteur, chaque nouveau chemin d'écriture étant une occasion de l'oublier.
L'expression exacte est dans `docs/modele-donnees.md` § 2.3 ; elle hache la ligne entière
(`p::text`), de sorte qu'aucune écriture — pas même un correctif SQL à chaud — ne lui
échappe.

**Le périmètre de l'empreinte est aussi important que son principe, et il est étroit :
`tree_person`, `tree_family`, `tree_child`. Ces trois tables, et aucune autre.** Ni
`med_media`, ni `tree_citation`, ni `tree_source`, ni `tree_fact`, ni `tree_note` : rien de
tout cela ne déplace un nœud ni ne modifie une parenté. Les inclure serait le défaut
symétrique de celui du compteur — non plus invalider trop peu, mais **trop** : téléverser
une photo de famille recalculerait le placement des 377 nœuds et **tous** les cercles de
visibilité, pour un graphe rigoureusement identique. « Élargir l'empreinte par prudence »
est le réflexe naturel de qui l'implémente ; c'est ici une erreur, et l'ADR la nomme pour
qu'elle ne se prenne pas pour de la rigueur.

## Alternatives considérées

- **Stocker les positions en base** (colonnes `x`/`y`, recalculées à chaque validation).
  Écartée : les positions deviennent une donnée à migrer, à sauvegarder et à garder cohérente,
  alors qu'elles sont entièrement dérivées. Et changer une règle de placement demanderait une
  migration au lieu d'un vidage de cache.
- **Garder `graphe.json` comme source et n'y appliquer que des retouches.** Écartée : c'est
  une sortie figée. Toute modification structurelle — insérer une personne au milieu d'une
  fratrie, ajouter un remariage — demanderait de rejouer la logique qui l'a produite, sur un
  artefact qui ne la contient pas. (Le script qui l'a produite a été retrouvé depuis — voir
  « Contexte » — ce qui ne change rien à cette alternative : on ne retouche pas une sortie,
  on recalcule.)
- **Calculer le placement côté client** (d3-hierarchy, elkjs). Écartée pour trois raisons, dont
  une bloquante : le résultat dépendrait du navigateur, il ne serait pas testable en PHPUnit
  contre `graphe.json`, et surtout **il faudrait envoyer au client la topologie complète et
  les données brutes** — y compris ce que le cercle de visibilité doit masquer. La
  confidentialité l'interdit.
- **Un cache de graphe par compte** — clé `placement.{empreinte}.{lecteurId}`. Écartée deux
  fois : stockage multiplié et invalidation piégeuse pour un gain de moins d'une
  milliseconde, et surtout **résultat faux** — l'entrée d'un lecteur ne changerait pas quand
  il dépose un brouillon, donc il ne verrait pas son propre nœud en attente (voir
  « Étage 2 »). C'est la première rédaction du modèle de données qui proposait cette clé ;
  l'arbitrage est journalisé dans `docs/arbitrages.md`.
- **Recalcul global incluant les nœuds en attente.** Écartée : coût d'un placement complet par
  lecteur et par affichage, pour corriger un défaut visuel mineur sur un nœud provisoire.
- **Ne pas afficher du tout les nœuds en attente** dans le graphe, et les laisser dans un
  écran de suivi. Écartée : c'est la contrepartie directe de « le demandeur voit sa
  modification avant validation ». Une création qu'on ne voit pas dans l'arbre n'est pas une
  contribution, c'est un formulaire envoyé dans le vide.

## Conséquences

- Le moteur est un **service sans état, sans dépendance à Doctrine**, testable unitairement.
  C'est une contrainte de conception imposée par cet ADR, pas un commentaire de style.
- **Corollaire qu'il vaut mieux lire ici que découvrir : le moteur n'écrit pas `branch`,
  `generation` ni `rank`.** Ces trois colonnes dérivées sont persistées par un **service de
  `Tree`** après toute écriture structurante ; le moteur les reçoit en entrée, avec le reste
  du jeu de personnes et de familles en mémoire. Un moteur qui les écrirait aurait accès à
  la base, donc ne serait plus testable contre `graphe.json` — c'est la contrainte
  ci-dessus qui tombe. Détail dans `docs/modele-donnees.md` § 3.1 et § 3.2.
- **La vue chronologique n'utilise pas ce moteur** : elle place sur un axe de temps, pas sur
  des générations. Elle partage le cercle de visibilité et les règles de rendu (ADR 0007), pas
  le placement.
- **Le test de non-régression n'est exécutable que sur la machine du commanditaire.** C'est une
  dépendance connue et acceptée : sans lui, on n'a aucune preuve que le moteur réécrit vaut
  l'ancien. Il doit être lancé au moins une fois avant la mise en service, et son résultat
  consigné.
- Le moteur produit aussi la boîte englobante, qui alimente directement le `viewBox` initial du
  viewer (ADR 0007). Elle n'est jamais recalculée côté client.
- **Le portage doit ajouter deux choses que `graphe.py` ne fait pas**, et elles sont la
  seule liberté qu'on prenne avec lui : savoir produire un graphe **pour un lecteur donné**,
  en y insérant ses nœuds « en attente » **localement, sans réorganiser** (étage 2
  ci-dessus) ; et la disparition de la signalétique des arêtes longues `.ar.long`
  (ADR 0007). Tout le reste est une traduction.
- **Révision du 2026-09-15 — prémisse corrigée, critère durci.** Cet ADR affirmait que les
  scripts étaient perdus et comparait, en conséquence, des invariants à ± 10 %. Les scripts
  ont été retrouvés (`docs/moteur-retrouve/`, 5 fichiers, dont `graphe.py` — 624 lignes).
  Trois corrections en découlent : la **prémisse** (le travail est un portage, pas une
  reconstitution) ; le **critère d'acceptation**, qui devient l'égalité exacte des positions,
  parce qu'un algorithme déterministe dont on a le code n'admet pas d'écart ; et deux
  **erreurs de fait** sur l'algorithme lui-même, déduites autrefois de la sortie — l'ordre
  horizontal ne suit pas la branche mais une récursion d'ascendance depuis l'ego, et le nœud
  de jonction n'est pas un petit cercle mais un losange 22 × 14. La décision de réécrire en
  PHP, les deux étages de cache, l'invalidation par empreinte et l'insertion locale des
  nœuds en attente sont **inchangés** : aucun ne dépendait de l'origine de l'algorithme.
  Journal : `docs/arbitrages.md`, points **Z**, **BB** et **CC**.
