# ADR 0015 — Les statistiques de l'arbre (Explorer › Stats)

Date : 2026-10-02 · Statut : acceptée

## Contexte

Le commanditaire veut un quatrième onglet d'Explorer : l'arbre en chiffres (taille,
période, part vérifiée et son évolution, branches), la vie d'avant (âges au décès et au
mariage, enfants par couple, mois des naissances, mortalité infantile…), des records, les
prénoms et les noms, les lieux et les métiers — et, pour l'administration seulement, le
chantier (fiches à compléter, activité).

Deux contraintes se croisent. **Les vivants comptent partout** (décision du
commanditaire). Mais **un membre ne doit jamais voir le nom ni les dates d'une personne
hors de son cercle** — depuis la révision du 2026-10-01 de l'ADR 0003, les fiches à
confidentialité forcée hors de son cercle généalogique, bascule « voir comme » comprise.

## Décision

### Les calculs se font côté serveur

Un module `Statistiques` (préfixe `stt_`, **aucune table**) sert deux routes :

```
GET /api/explorer/stats                   membre  (compte en attente : 403)
GET /api/administration/stats/chantier    admin   (membre : 403)
```

Le front ne calcule rien : il reçoit des chiffres déjà passés par le cercle. Calculer à
partir du graphe chargé côté client était possible pour les comptes, pas pour le reste —
il faudrait au client les dates de toutes les fiches, confidentielles comprises, ce qui
est exactement ce que le cercle interdit d'envoyer. Le contrôle d'accès reste donc
entièrement côté serveur, et en un seul point : `StatistiquesVisibles`, seul fichier du
module qui nomme `CercleDeVisibiliteInterface` (un test le vérifie).

Les lectures traversent des ports (ADR 0002) : `PortStatistiquesArbre` (implémenté par
`Tree`), `PortActiviteDesDemandes` (`Proposal`), `PortActesDemandes` (`Demarche`). Les
comptes de membres lisent `Core\Entity\User`, noyau partagé.

### Le cache

Le **relevé** de l'arbre (complet, non filtré, le même pour tous) est mis en cache sous une
clé qui porte un **jeton de fraîcheur** — `COUNT` et horodatage maximal de chaque table lue,
plus le jour et les réglages qui changent la lecture —, une heure au plus. Toute écriture
validée change le jeton. Les **statistiques**, qui dépendent du lecteur, se recalculent à
chaque requête sur ce relevé (quelques millisecondes pour un millier de personnes) : les
mettre en cache exigerait une clé portant le cercle, et une clé qui l'oublierait servirait
les chiffres de l'administrateur à un membre. Réponse `private, no-store`, `Vary: Cookie`.

### La règle des dates

| date | âges, siècles, générations | mois des naissances / décès |
|---|---|---|
| exacte (`14 MAR 1850`) | oui | oui |
| au mois (`MAR 1850`) | oui | oui |
| année seule (`1850`) | oui | **non** |
| floue (`ABT`, `BEF`, `AFT`, `BET`, `FROM`, `TO`) ou inconnue | **non** | **non** |

Le cas « au mois » n'était pas nommé dans la décision : ce n'est pas une date floue, elle
compte au mois. Âge entre deux dates exactes : âge révolu ; sinon différence des années,
corrigée du mois quand les deux en ont un. Une incohérence (âge négatif, plus de 120 ans)
ne compte pas. Écrit une fois : `Statistiques\Service\DateRetenue`.

### La confidentialité, en trois traitements

1. **Comptes simples** (personnes, familles, branches, part vérifiée) : tout le monde. Un
   compte ne dit rien d'une fiche, et le graphe publie déjà chaque nœud (ADR 0003, « la
   topologie reste visible »).
2. **Moyennes et palmarès anonymes** (âges, mois, communes, métiers, pays) : tout le monde,
   sous un **seuil de discrétion** — une case (une barre, un mois, une commune) qui compte
   une fiche invisible du lecteur et moins de **5** contributions est calculée sans les
   fiches invisibles. Sans lui, « âge au mariage des femmes nées au XXIᵉ siècle : 27 ans,
   sur 1 personne » publierait une date masquée.
3. **Ce qui nomme** (records, prénoms, noms de famille, patronyme de tête d'une branche) :
   les seules fiches visibles. **Un record dont le titulaire est invisible passe au
   suivant** — l'afficher sans nom publierait encore sa valeur (un âge, une durée), calculée
   sur des dates masquées, avec sa branche : de quoi le reconnaître. Pour une union ou une
   fratrie, il faut voir les deux époux (la règle du « ET » d'`Almanach`).

### Ce que « Vérifié » veut dire

La règle unique du produit : `best_quay ≥ 2`. La part vérifiée est celle des fiches ;
le détail par événement lit la portée des citations (naissance, décès, mariage).
L'**évolution par mois** est reconstruite à partir des dates de saisie des citations et des
fiches : il n'existe pas de journal de « Vérifié ». Ce qu'elle ne voit pas, et que l'écran
dit : les suppressions disparaissent du passé, une qualité relevée après coup compte depuis
la saisie, et tout ce qui vient de l'import porte la date de l'import.

### Les branches et les générations

Elles sont lues **depuis la racine de l'arbre** (`tree.personne_racine_id`, colonnes
dérivées `branch` et `generation`) : les statistiques décrivent l'arbre de la famille, pas
le point de vue de chaque lecteur. Le patronyme d'un quart est celui de son grand-parent de
tête (sosa 4 à 7), comme les bandes de l'arbre.

## Conséquences

- Pas de bibliothèque de graphiques : des colonnes en HTML (texte jamais déformé, jetons
  CSS de thème clair et sombre), une courbe en SVG, un tableau `sr-only` par graphique.
  Chaque graphique dit son échantillon (« sur 412 personnes »).
- Le chantier a sa route d'administration : il n'est jamais envoyé à un membre.
- Lectures interprétées, à valider : « plus grand écart de générations » est lu comme le
  plus grand âge d'un parent à la naissance d'un enfant ; « enfants par couple » ne compte
  que les couples ayant au moins un enfant connu ; le premier prénom inscrit est le prénom
  retenu pour les palmarès et les transmissions.
