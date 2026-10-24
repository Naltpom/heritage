# ADR 0019 — L'affiche généalogique, dessinée dans le navigateur

Date : 2026-10-22 · Statut : acceptée

## Contexte

Le commanditaire tire des affiches à accrocher : un couple au centre, ses deux lignées en
éventail au-dessus, toute sa descendance en dessous. Elles existaient hors du dépôt, en
Python (« l'affiche d'origine » : environ 1 800 lignes, PyMuPDF pour mesurer, Chrome pour
imprimer), lues directement dans la base de production. Il veut un bouton « Générer
l'affiche » sur la fiche d'une personne, pour tous les membres, avec les mêmes affiches.

Trois contraintes : **ne montrer à personne plus que ce qu'il voit déjà** (ADR 0003, 0016) ;
**aucune donnée réelle dans le dépôt** (ADR 0006), y compris la signature de l'affiche
d'origine ; et **la fidélité** — c'est la mise en page du commanditaire, réglée affiche par
affiche, qu'on porte, pas une interprétation.

## Décision

### Le dessin se fait dans le navigateur, sur une route de lecture

Le serveur sert **l'extrait du graphe** dont l'affiche a besoin —
`GET /api/arbre/familles/{id}/affiche`, § 5.4 bis du contrat : le couple, toute son
ascendance connue (une filiation par ancêtre, `naissance` d'abord), sa descendance sur six
générations, mariages et divorces. Le front (`app/src/affiche/`) place et dessine.

Le serveur applique ses filtres **comme partout** et dans la sérialisation
(`NormaliseurDAffiche`) : le périmètre (une personne d'un autre arbre n'est pas servie), le
cercle (un vivant hors cercle à sa place, sous le masque, cinq clés), la réciprocité (une
naissance réduite par `UncertainDate::reduite()`, ou masquée sans l'année). Le front ne
filtre rien.

### Le portage est fidèle, et c'est vérifiable

- Le placement est **pur** (sans DOM) : il remplit une scène en millimètres ; le rendu SVG
  est un module séparé. La scène se teste sous Node.
- Les textes se mesurent **sur les avances des glyphes** des fichiers de police — le calcul
  de PyMuPDF, flottants simple précision compris —, jamais dans le DOM : le placement est
  déterministe, et le même sous vitest et dans tous les navigateurs.
- Sur un arbre fictif, la sortie TypeScript et la sortie Python coïncident au millième de
  millimètre élément par élément, pour l'éventail comme pour les deux sabliers ; les écarts
  restants sont nommés (ci-dessous). Les dimensions des affiches Python sur cet arbre sont
  figées dans les tests.

### Les polices sont dans le dépôt

Neuf instances statiques de Source Serif 4 et Cormorant Garamond (SIL OFL 1.1), réduites aux
écritures latines (~560 Ko), servies comme actifs de Vite et lues au premier clic. Le script
qui les refait depuis les polices variables publiées est versionné
(`app/scripts/polices-affiche.py`). « Source » étant un nom réservé, les noms internes des
sous-ensembles sont remplacés ; la licence est livrée à côté.

### Trois fichiers, sans dépendance

- **SVG**, polices embarquées en `data:` ;
- **PNG**, le SVG dessiné dans un canevas à la résolution choisie (borné à 120 millions de
  pixels) ;
- **PDF par l'impression du navigateur**, sur une page de la taille de l'affiche
  (`@page`) : c'est le chemin de l'affiche d'origine (Chrome `--print-to-pdf`), vectoriel,
  polices embarquées par le navigateur, sans bibliothèque. Le prix est un geste —
  « Enregistrer au format PDF » —, dit dans la modale.

Les trois passent la CSP de production : les polices sont déclarées par `FontFace` à partir
des octets, jamais par une `@font-face` en `data:` dans la page.

### « Masquer les dates des vivants » est une mise en page

Cochée par défaut. Elle porte sur des dates que le lecteur voit déjà : ce n'est pas un
filtre, et elle vit au front. « Vivant » est l'état calculé par le serveur (§ 9.2 du modèle)
— la seule définition du produit —, et non « sans décès enregistré » comme dans l'affiche
d'origine, qui aurait effacé les dates d'ancêtres du XVIIIᵉ siècle sans acte de décès.

### Les écarts avec l'affiche d'origine, tous voulus

- le pied ne nomme personne : « Arbre établi avec Heritage — mois année » ;
- une égalité d'angle tranchée au dernier bit (l'original coupait parfois des prénoms qui
  tenaient, puis serrait la ligne des dates) prend un millionième de millimètre de
  tolérance ;
- l'ordre des enfants et des unions, et le veuvage, se calculent sur les vraies dates même
  quand le masque les retire ;
- une naissance dont seuls le jour et le mois sont partagés s'écrit (« ° 27 novembre ») ;
- un couple sans descendance se dessine (l'original divisait par zéro) ;
- le nombre de générations d'ascendants se règle (toutes, par défaut).

## Alternatives écartées

- **Dessiner au serveur** (PHP, ou le script Python appelé par l'API) : un second moteur de
  rendu à héberger — Chrome sans tête dans l'image de l'API —, et l'aperçu dans la modale
  serait une image figée au lieu du dessin lui-même. Le navigateur a déjà tout : un moteur
  SVG, des polices, l'impression.
- **Nourrir l'affiche par le graphe** : il n'a ni prénoms entiers, ni dates en clair, ni
  mariages ; les lui ajouter alourdirait chaque chargement de l'arbre pour un usage rare.
- **Une fiche par personne** : deux cents requêtes par affiche.
- **Un PDF généré au front** (jsPDF et svg2pdf) : 400 Ko de dépendances et un troisième
  rendu dont chaque écart avec l'aperçu serait un défaut de plus.
- **Mesurer avec `canvas.measureText()`** : dépendant du navigateur et de l'état de
  chargement des polices, intestable sans DOM.

## Conséquences

- Une route de lecture de plus dans `Tree`, avec sa liste blanche testée.
- Trois vues, comme l'affiche d'origine : l'éventail, le sablier et le sablier dense,
  sur la même scène et les mêmes boîtes de descendants.
- Le test de fidélité tient à l'arbre fictif (`app/src/affiche/essai/arbreFictif.ts`) :
  changer le générateur oblige à refaire la mesure contre le script Python, qui vit hors du
  dépôt.
- Le jour où `tree_family.married` existera (mariage attesté sans date, union libre), le
  front le lit déjà ; il restera à le servir dans `NormaliseurDAffiche`.
- **Révision du 2026-10-24 — l'éventail n'est plus celui de l'original par défaut.** Le
  commanditaire a demandé un centre plus petit, des générations lointaines plus grandes, et
  des cases égales dans une génération. Sur son affiche de onze générations (870 ancêtres),
  le disque central prenait 58 % du rayon, l'éventail 2,26 m de large, et le texte des
  anneaux rayonnants tombait à 3,8 pt sur un A0. Le disque n'est que du remplissage : il
  grandit jusqu'à ce que l'anneau le plus contraint tienne sur le demi-cercle. Trois leviers,
  tous choisis par le moteur pour donner le plus petit éventail (`app/src/affiche/eventail.ts`) :
  - **une ligne** — dans les anneaux serrés, « Prénoms NOM  ° … † … » bout à bout : la
    hauteur du texte se prend sur l'arc, l'angle de chaque case est divisé par deux ;
  - **moins d'anneaux tangentiels** — quatre, trois ou deux : seize arrière-grands-parents
    écrits le long de l'arc fixaient seuls le disque d'une affiche plus petite ;
  - **les anneaux qui ont de la place grossissent** tant que l'éventail ne grandit pas.
  Résultat sur les deux affiches réelles mesurées : disque à 31 % et 23 % du rayon, texte
  plus grand d'un tiers et de 7 % sur la même feuille. L'**égalisation** complète est
  impossible : une branche qui monte à la onzième génération réclame plus d'angle que ses
  voisines, et leur donner la même part demandait un disque de plus de deux mètres. Elle est
  donc une option de la modale (« Les cases : régulières »), qui donne au disque une marge
  de 20 % du rayon et la consacre à égaliser, du centre vers l'extérieur. Le partage de la
  place libre entre père et mère passe à parts égales (`gamma` 0 au lieu de 0,6).
  La fidélité reste vérifiable : `Reglages.ORIGINE` rend la mise en page de l'original, et
  les tests de fidélité la demandent. Un arbre fictif « touffu » (`arbreTouffu()`), complet
  sur sept générations puis clairsemé, a la forme de l'affiche réelle et garde la nouvelle.
