# ADR 0007 — Rendu SVG : les trois règles de performance

Date : 2026-09-15 · Statut : acceptée

## Contexte

Le canevas à porter fait **377 nœuds et 377 arêtes**, soit ~750 éléments SVG, sur une boîte de
**15 824 × 1 810** unités (`viewBox="-7770 -1730 15824 1810"` dans `arbre.html`).

Deux des trois règles qui suivent sont écrites dans le `README.md` du chantier **parce qu'elles
ont coûté cher** : le viewer a été lent, on a cherché, on a trouvé. La troisième est propre au
portage vers Vue et relève du même mécanisme.

Un ADR qui dit « ne fais pas ça » sans dire **pourquoi** sera enfreint dans six mois, par un
agent qui trouvera son cas particulier légitime. D'où le détail des mécanismes.

## Décision

### Règle 1 — Le zoom passe par le `viewBox`, jamais par `transform: scale()`

**Mécanisme.** Un SVG est rastérisé. Quand on applique `transform: scale(k)` au groupe racine,
le moteur de rendu doit produire l'image du contenu transformé : il rastérise **la surface
entière du calque** à l'échelle `k`. À `k = 4` sur un canevas de 15 824 unités de large, il
tente de peindre et de conserver en mémoire de texture ~63 000 px de large — pour un viewport
de 1 600 px. Chrome plafonne, décharge, repeint, et le zoom devient saccadé. **Le navigateur ne
sait pas que 96 % du résultat est hors écran : la transformation est appliquée avant le
découpage.**

En modifiant le `viewBox`, on ne transforme rien. On redéfinit quelle portion du système de
coordonnées correspond à la surface du `<svg>`. Le découpage a lieu **avant** la rastérisation,
et seul le contenu visible est peint. Le coût devient proportionnel à ce qu'on voit, pas à ce
qui existe.

**Application.** Pan et zoom modifient les quatre nombres du `viewBox`, en une seule écriture
par frame (`setAttribute('viewBox', …)`), calée sur `requestAnimationFrame`. Le `viewBox`
initial vient de la boîte englobante rendue par le moteur de placement (ADR 0005) ; il n'est
jamais recalculé côté client.

### Règle 2 — Jamais `opacity` sur un élément SVG ; `fill-opacity` / `stroke-opacity`

**Mécanisme.** Une `opacity` inférieure à 1 crée un **contexte d'empilement**. Le groupe doit
alors être composé isolément : peint dans sa propre surface hors écran, puis mélangé au reste
avec le facteur alpha. C'est obligatoire pour obtenir le bon résultat quand les éléments du
groupe se recouvrent — sinon les recouvrements cumuleraient leurs alphas. Le navigateur alloue
donc **une couche de composition par élément portant `opacity`**. Sur 377 arêtes mises en
retrait, cela fait 377 surfaces allouées et 377 compositions par frame.

`fill-opacity` et `stroke-opacity` ne sont pas des propriétés de composition : ce sont des
**paramètres de la peinture elle-même**. L'alpha est appliqué à la couleur au moment où le
trait est tracé, dans la même passe que le reste. Pas de couche, pas d'isolement, pas de
composition — un repaint ordinaire.

Le viewer actuel applique déjà la règle partout (`.ar{stroke-opacity:.42}`,
`.ar.on{stroke-opacity:.75}`) : le portage la reprend telle quelle, et la vue chronologique
l'applique aussi pour mettre en retrait les barres hors filtre.

**Corollaire** : `filter`, `mask`, `mix-blend-mode` et `will-change` créent le même contexte
d'empilement. La règle n'est donc pas « évite `opacity` », elle est **« ne crée pas un contexte
d'empilement par élément sur un canevas de 750 éléments »**. `opacity` est simplement la façon
la plus facile de l'enfreindre.

### Règle 3 — Le canevas est piloté impérativement, hors de la réactivité de Vue

Le `<svg>` des 377 nœuds est obtenu par `ref` et manipulé en DOM direct. Vue tient le cadre :
panneaux, fiche latérale, formulaires, barre de recherche, réglages. **Il ne tient pas un seul
nœud du graphe.**

**Mécanisme.** Une frappe dans la barre de recherche change un état réactif. Si le graphe est
rendu par `v-for` sur 750 éléments, chaque frappe déclenche un cycle de rendu du composant, la
comparaison de 750 vnodes et autant de patches DOM potentiels — pour une mise en surbrillance
qui ne touche en réalité qu'une classe sur quelques éléments. **Le coût par frappe est
proportionnel à la taille du graphe, pas à la taille du changement.** C'est exactement le
mécanisme des deux règles précédentes sous un autre nom : on paye le tout quand on ne change
qu'une partie.

En impératif, la recherche fait `el.classList.toggle('on')` sur les seuls nœuds concernés. Le
coût redevient proportionnel au changement.

**Frontière explicite**, à respecter dans le composant du canevas :

- **de Vue vers le canevas** : un `watch` sur les seuls réglages qui changent le dessin
  (filtres, personne focalisée, tranche de temps), qui appelle une méthode impérative. **Jamais
  de `v-for`, jamais de `:class` lié, jamais de `v-if` à l'intérieur du `<svg>`.**
- **du canevas vers Vue** : **un seul** écouteur d'événement délégué sur le `<svg>` — pas 377
  écouteurs — qui remonte l'identifiant cliqué et met à jour l'état Vue de la fiche latérale.
- Le SVG est construit **une fois au montage** à partir du graphe reçu, puis muté. Un
  changement de données (validation d'une proposition, changement de filtre) **reconstruit le
  canevas entier** : c'est rare, et ça évite d'écrire une réconciliation maison qui serait pire
  que celle de Vue.

### Les pointillés de distance disparaissent

`.ar.long { stroke-opacity:.16; stroke-dasharray:2 5 }` est supprimée : **trait plein**,
opacité alignée sur les autres arêtes (`stroke-opacity:.42`). On ne garde aucune signalétique
de « ce lien va loin ».

Sont **conservés** : `.ar.divorce` (tirets + couleur d'alerte) et `.fiche.prive` (contour en
tirets). La règle qui permet de trancher les cas futurs sans revenir demander : **le tiret code
un fait, jamais une distance.**

`.fiche.prive` se pose à partir du booléen **`vivant`** de la charge utile. Ce booléen est
**calculé à la sérialisation** — il n'existe aucune colonne `prive` en base, et c'est
délibéré (`docs/modele-donnees.md` § 9.2). Le canevas ne le recalcule pas et ne se fie pas
à l'absence de dates pour le deviner. Et il faut le savoir : ce contour en tirets **est** le
« masquage est son propre indice » que l'ADR 0003 accepte comme risque résiduel — il annonce
que la personne est vivante. C'est voulu, pas un oubli.

## Alternatives considérées

- **Canvas 2D ou WebGL** au lieu du SVG. Plus rapide dans l'absolu. Écartée : on perd
  l'accessibilité, la sélection de texte, le ciblage CSS, l'export SVG, et le viewer existant
  qu'on porte. À 750 éléments avec les trois règles ci-dessus, le SVG tient très largement —
  **la lenteur constatée venait des règles enfreintes, pas du SVG.**
- **Virtualisation du SVG** (ne monter que les nœuds visibles). Écartée : ajoute une logique de
  fenêtrage et de remontage à chaque pan, pour refaire ce que le découpage par `viewBox` fait
  déjà gratuitement dans le moteur de rendu.
- **Rendre le graphe avec Vue** en optimisant (`v-memo`, `shallowRef`). Écartée : ça marche
  jusqu'au jour où quelqu'un ajoute un `:class` calculé. « Vue ne touche pas le canevas » est
  une règle **vérifiable en relecture** ; « Vue touche le canevas mais avec précaution » n'en
  est pas une.
- **Une bibliothèque de pan/zoom** (`d3-zoom`, `svg-pan-zoom`). Écartée : `d3-zoom` applique par
  défaut un `transform` sur un groupe — soit la règle 1 enfreinte dès la première ligne. On
  écrirait autant de code pour la contourner que pour écrire le pan/zoom, qui tient en une
  cinquantaine de lignes et **existe déjà** dans `arbre.html`.
- **Garder `.ar.long` en le rendant plus discret.** Écartée : le commanditaire a tranché, les
  pointillés de distance partent.

## Conséquences

- **Aucune dépendance front pour le rendu** : ni d3, ni bibliothèque de pan/zoom, ni moteur de
  graphe.
- Les trois règles s'appliquent **à l'identique à la vue chronologique**, qui partage le
  pan/zoom et la mise en retrait.
- Un commentaire en tête du composant du canevas renvoie à cet ADR, avec les deux premières
  règles résumées en une ligne chacune. **Un ADR qu'il faut aller chercher ne sera pas lu par
  l'agent qui touchera ce fichier dans six mois** — c'est précisément le scénario qu'il doit
  empêcher.
- La performance se mesure : un protocole manuel documenté dans le `README.md` du front — zoom
  ×8 au clavier sur le graphe complet, puis recherche saisie caractère par caractère, sur un
  arbre de fixtures à pleine taille. Sans repère chiffré, la règle sera enfreinte et personne
  ne s'en apercevra avant que tout soit lent.
- Les fixtures doivent contenir au moins un divorce, sans quoi `.ar.divorce` n'est jamais rendu
  et sa conservation n'est jamais vérifiée (ADR 0006).
