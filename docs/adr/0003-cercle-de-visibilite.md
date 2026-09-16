# ADR 0003 — Confidentialité : le cercle de visibilité

Date : 2026-09-15 · Statut : acceptée

## Contexte

L'arbre compte **274 personnes dont 18 vivantes**. Ces 18 personnes n'ont pas demandé à
figurer dans une application ; elles y sont parce qu'elles sont de la famille. Le principal
risque du produit n'est pas qu'on lise une fiche de 1791, c'est qu'un compte serve d'oracle
pour énumérer et documenter les vivants.

Le commanditaire a formulé la règle ainsi : *« on remonte à l'arrière-grand-parent, du coup
voir les enfants de mes tantes et tontons et leurs enfants, voir les frères et sœurs de ma
grand-mère leurs enfants et leurs petits-petits-enfants etc. 3 parents au-dessus et on
descend. »*

## Décision

Soit `u` la personne rattachée au compte du lecteur, et `N` le réglage
`core_setting['cercle.generations']`, **valeur par défaut 3**, borné à
`1 ≤ N ≤ 6` (table et clé exactes : `docs/modele-donnees.md` § 2.2).

```
A(u) = { u } ∪ { a : a ancêtre de u, distance(a, u) ≤ N }
D(u) = { d : d descendant, à toute profondeur, d'un membre de A(u) }
C(u) = A(u) ∪ D(u) ∪ { c : c conjoint d'un membre de A(u) ∪ D(u) }
```

**Les conjoints sont ajoutés en une seule passe, en dernier, et ne propagent rien.** Le
conjoint d'un conjoint n'entre pas dans le cercle ; les ancêtres d'un conjoint non plus.
Ce n'est pas un point fixe. Aucune des deux formulations — celle du commanditaire ni
l'écriture ensembliste ci-dessus prise naïvement — ne dit cela à voix haute, et c'est
pourtant ce qui sépare un cercle de trente personnes d'un cercle qui avale la moitié de
l'arbre au premier remariage. Un agent qui itère jusqu'à stabilité implémente autre chose
que ce qui est demandé.

Règles d'application :

- **La règle ne concerne que les personnes vivantes** (`mort = false`). Une personne décédée
  est visible de tout membre connecté, sans restriction.
- Hors cercle, une personne vivante n'expose que : son **identifiant**, son **nom
  d'affichage**, son **sexe**, sa **génération**, sa **branche**, le booléen **`vivant`**, sa
  **position dans le graphe** et ses **arêtes**. Ni date, ni lieu, ni source, ni citation, ni
  média, ni portrait, ni rattachement à un compte. En colonnes et champs calculés : `id`,
  `display_name`, `sex`, `generation`, `branch`, `vivant`. C'est la liste blanche que
  recopient les tests des portes de sortie.
  *La branche et `vivant` ont été ajoutés à cette liste après coup, pour deux motifs
  différents — voir « Conséquences ».*
- **Tout champ dérivé d'une donnée masquée est lui-même une donnée masquée.** Une liste
  blanche raisonne champ par champ ; elle ne voit pas un champ *légitime* dont la **valeur**
  est calculée sur un champ interdit. Cas concret : la largeur d'un nœud du graphe se calcule
  sur le texte affiché — calculée sur les vraies dates, elle encode leur longueur pour une
  personne masquée. La règle se relit à chaque champ calculé : largeur, compte de caractères,
  ordre de tri, nombre de résultats.
- **Le filtrage est appliqué dans la sérialisation**, côté serveur. Un champ masqué n'est pas
  envoyé puis caché : il n'est pas envoyé.
- **La recherche ne renvoie jamais une personne vivante hors cercle.** Ni en résultat, ni en
  suggestion, ni en libellé de rapprochement de doublon.
- Un compte **sans rattachement validé** (inscription en attente) **n'a pas de cercle** : il
  ne voit que les personnes décédées, et **aucune personne vivante — pas même celle à
  laquelle il prétend se rattacher**. Il obtient un cercle à l'activation par
  l'administrateur. *Décision du commanditaire ; motif : l'attente peut durer des jours, un
  écran vide pousse à l'abandon, et l'arbre ancien n'expose rien de sensible.*
  **Ce n'est pas un cercle réduit, c'est un régime distinct** : ni une liste vide, ni
  surtout « un cercle avec `N = 0` » — un `N = 0` reste un cercle, il contient encore `u` et
  ses conjoints, donc il laisserait sortir une fiche vivante. La nuance disparaîtrait au
  premier remaniement si elle n'était pas écrite ici.
- L'administrateur voit tout, via une bascule **« voir comme un membre »** qui applique le
  cercle d'un compte choisi — commodité et moyen de vérifier la règle.
- Le cercle est **calculé puis mis en cache par compte**, invalidé à toute modification validée
  de l'arbre, à tout import et à tout changement de `N`. L'invalidation ne passe par aucun
  compteur : la clé porte une **empreinte recalculée depuis la base** (ADR 0005), et `N`.

## Justification

**Pourquoi les personnes décédées échappent à la règle.** Sur 274 personnes, 256 sont
décédées. Une règle qui les masquerait couperait l'arbre en morceaux et rendrait le produit
inutile — pour un gain de confidentialité nul : l'état civil de plus de 75 ans est en ligne
et gratuit sur les sites d'archives départementales, c'est même d'où viennent la plupart de
ces fiches. On protège les 18 personnes qu'on peut réellement protéger.

**Pourquoi les conjoints sont inclus.** Un couple ne se lit pas à moitié. Un nœud de famille
avec un époux nommé et l'autre muet est **plus bavard** que les deux nommés : il désigne
précisément « le conjoint de X », ce qui suffit souvent à identifier la personne, tout en
rendant la fiche illisible. Inclure le conjoint ne révèle presque rien de neuf — il est déjà
dans l'entourage du lecteur par alliance — et rend l'arbre lisible.

**Pourquoi `N` est un réglage et non une constante.** Le chiffre 3 est une intuition, formulée
avec hésitation. Il sera confronté à la réalité au premier membre invité : soit un cousin qui
ne voit presque rien, soit une branche entière ouverte à quelqu'un qu'on n'attendait pas. Un
chiffre qu'on découvre faux et qu'on ne peut changer qu'en redéployant sera changé en dur dans
le code par le premier qui passe, sans trace et sans discussion. En réglage d'administration,
le changement est daté, tracé au journal d'audit, réversible. La borne haute à 6 n'est pas
décorative : à 12 générations, au-delà, le cercle couvre l'arbre entier et la règle ne veut
plus rien dire.

**Pourquoi le filtrage est dans la sérialisation et pas dans le contrôleur.** Les données
d'une personne sortent de l'API par **sept portes** : la fiche, le graphe, la recherche, la
chronologie, les métadonnées d'un média, une notification, le diff d'une proposition — sans
compter les réponses d'erreur et les journaux. Un contrôleur filtre une route ; il en oublie
une, et ce sera la septième. Le seul point traversé par les sept est le normalizer. On y écrit
le filtre une fois, on l'audite une fois.

**Il y a une huitième porte, et elle ne traverse pas ce normalizer : les libellés
dénormalisés.** La règle de frontière de l'ADR 0002 supprime les jointures inter-modules et
les remplace par un identifiant plus un libellé recopié. Résultat : neuf colonnes `*_label`
sur six tables de `Membership`, `Proposal` et `Media` stockent le nom d'une personne
potentiellement vivante, et une file de demandes sérialise ces colonnes sans jamais passer
par `Tree\Person`. C'est la contrepartie structurelle de la modularité, elle est assumée,
mais elle impose une règle :

> **Toute colonne `*_label` désignant une personne est filtrée comme la personne
> elle-même.** Les tests de liste blanche ci-dessous couvrent les réponses de `Proposal`,
> `Membership` et `Media`, pas seulement celles de `Tree`.

Liste des colonnes concernées : `docs/modele-donnees.md` § 0.4.

Corollaire non négociable : **le normalizer n'a pas de mode « sans filtre »**. La vue
administrateur passe par un cercle complet, pas par un contournement. Une branche
`if ($estAdmin) { return $tout; }` est exactement le bug qu'on découvrira dans deux ans, le
jour où un rôle sera attribué par erreur.

## Le risque résiduel : la topologie reste visible

Il faut le nommer, parce qu'il est réel et qu'il est accepté.

Hors cercle, un lecteur voit tout de même **qu'un nœud existe, comment il s'appelle, et où il
se rattache**. Et comme seules les fiches vivantes sont masquées, **le masquage est son propre
indice** : une fiche muette est une fiche de personne vivante. Un lecteur hors cercle apprend
donc, pour chaque nœud muet : cette personne est vivante, elle porte ce nom, elle est l'enfant
d'untel et le conjoint d'untel.

**C'est accepté**, pour deux raisons.

1. **Le produit est un arbre.** Un arbre dont on retire des nœuds n'est plus un arbre : les
   branches flottent, les fratries sont fausses, le calcul de parenté est faux. Livrer un
   graphe amputé, c'est livrer un graphe mensonger — et un lecteur qui compte six enfants dans
   un acte et en voit quatre à l'écran conclura à un bug, pas à une règle de confidentialité.
2. **Le public est une famille invitée nommément.** Aucune inscription ouverte, un compte par
   personne, chaque accès journalisé. Le modèle de menace est « un cousin curieux », pas « un
   inconnu qui aspire l'arbre ».

Ce qui n'est **pas** accepté, et qui est la vraie frontière : que ce lecteur puisse
**découvrir** des vivants qu'il ne connaît pas, et les documenter. D'où les deux garde-fous
qui encadrent le risque :

- à l'inscription (§ 6.1) : au plus 3 candidats renvoyés, affichage minimal, 5 tentatives par
  heure et par jeton ;
- ici : **le graphe montre, il n'énumère pas.** Pas de liste, pas d'export, pas de recherche
  sur les vivants hors cercle. Voir le nœud d'une personne qu'on connaît déjà est acceptable ;
  obtenir la liste des vivants ne l'est pas.

Limite restante, écrite noir sur blanc : un lecteur patient peut parcourir le graphe nœud par
nœud et reconstituer cette liste à la main. C'est vrai. On la rend coûteuse et on la rend
visible — l'accès au graphe est journalisé. On ne va pas plus loin dans cette livraison. Si la
famille s'élargit au point que ce ne soit plus tenable, la décision sera révisée par un
nouvel ADR ; elle ne l'est pas ici.

## Alternatives considérées

- **Masquer entièrement le nœud hors cercle.** Écartée : casse l'arbre (voir ci-dessus), et
  le trou est lui-même une information — un enfant manquant entre deux frères se voit à l'œil
  nu, sans même le nom.
- **Filtrer côté client** : le champ arrive et l'interface le cache. Écartée, définitivement.
  Il suffit d'ouvrir l'onglet réseau du navigateur.
- **Liste blanche manuelle** (l'administrateur choisit qui voit qui). Plus exact, et
  ingérable : quelques dizaines de comptes × 274 personnes à maintenir à la main, et chaque
  personne ajoutée rouvre le travail. Une règle calculée se vérifie ; une liste se périme.
- **Cercle symétrique « parenté ≤ k »**, plus simple à écrire. Écartée : ce n'est pas la
  demande, qui est explicitement **asymétrique** — on remonte de `N`, on redescend sans
  limite. Et l'asymétrie est juste : mes cousins, même éloignés, sont ma famille ; les
  ancêtres de mon beau-frère ne le sont pas.
- **Drapeau « privé » par fiche**, à la webtrees ou à la Gramps. Écartée : c'est du binaire,
  il ne dépend pas du lecteur, et il faudrait le maintenir à la main à chaque décès et à
  chaque naissance.

## Conséquences

- **Cache par compte**, clé = (compte, `N`, empreinte de l'arbre) — soit
  `cercle.{empreinte}.{memberId}.{N}` (`docs/modele-donnees.md` § 9.3). **Invalidation
  globale, pas ciblée** : à cette échelle, recalculer tous les cercles coûte moins cher que
  raisonner juste sur une invalidation fine — et un raisonnement faux sur l'invalidation
  d'un cercle est une fuite, pas un ralentissement.
- **Révision du 2026-09-15 — la branche entre dans la liste blanche.** La rédaction
  initiale listait la génération et la position dans le graphe, mais pas `branch`. C'est une
  omission, pour une raison de rendu : le canevas **colore** les nœuds par quart
  d'ascendance, et la vue chronologique en fait ses colonnes. Un nœud servi sans sa branche
  est un nœud que le canevas partagé ne sait pas colorer — et qui se singularise donc à
  l'écran, ce qui est l'inverse du but.
  Ce que `branch` révèle est **déjà déductible** de ce qu'on envoie : la branche est
  déterminée par le chemin qui relie la personne à la racine, et ce chemin, ce sont les
  arêtes — que tout lecteur reçoit. Un client pourrait la recalculer lui-même. La révision
  n'élargit donc pas ce qui fuit ; elle rend explicite ce que le risque résiduel accepté
  plus haut couvrait déjà, et elle évite qu'un agent l'ajoute en silence en croyant
  enfreindre l'ADR.
  *Correction du raisonnement, même jour* : cette révision s'appuyait d'abord sur l'idée que
  `branch` était une **coordonnée du placement** (« l'ordre horizontal suit la branche »).
  La lecture du moteur retrouvé montre que c'est faux — le placement récurse sur
  l'ascendance depuis l'ego et ne lit jamais `branch` (ADR 0005). La conclusion tient, son
  motif est celui écrit ci-dessus.
- **Révision du 2026-09-15 — `vivant` entre dans la liste blanche, et *pas* pour la raison
  de `branch`.** Le rendu en a besoin : l'ADR 0007 conserve `.fiche.prive`, qui se pose à
  partir de ce booléen. Mais l'argument qui justifie `branch` — « elle se déduit des arêtes
  qu'on envoie déjà » — **ne se transpose pas** : l'état vivant ne se déduit d'aucune arête.
  Trois motifs propres le justifient. Le rendu **l'affiche déjà en toutes lettres** : la
  ligne de dates d'un nœud masqué vaut la constante « vivant·e ». Le **masquage le dit de
  lui-même**, et c'est le risque résiduel que cet ADR accepte plus bas — une fiche muette est
  une fiche de vivant. Et ne pas l'envoyer serait **pire** : le client devrait inférer l'état
  vivant de l'absence de champs, c'est-à-dire faire côté client exactement ce que cet ADR
  interdit. Le gain d'information pour un lecteur hors cercle est nul ; l'explicite vaut
  mieux que l'implicite.
  *À retenir de ces deux révisions* : deux ajouts à une liste blanche peuvent être tous deux
  justes et n'avoir aucun argument en commun. Un motif qui marche pour un champ n'autorise
  pas le suivant.
- La bascule « voir comme un membre » est **tracée au journal d'audit** : c'est une
  consultation de données personnelles sous une autre identité.
- **Un test fonctionnel par porte de sortie** (les sept ci-dessus, **plus les libellés
  dénormalisés**) : chacune est interrogée
  avec un compte hors cercle, et la réponse est comparée à une **liste blanche** de champs —
  pas à une liste noire, qui oublierait tout champ ajouté plus tard. C'est le garde-fou réel
  de cet ADR ; sans lui, le reste est une intention.
- Les fixtures doivent contenir des personnes vivantes réparties sur plusieurs branches,
  sinon ces tests ne prouvent rien (ADR 0006).
- Le partage du cache de placement dépend directement du risque résiduel accepté ici :
  voir ADR 0005.
