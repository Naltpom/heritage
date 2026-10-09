# ADR 0017 — Compléter une proposition en attente

Date : 2026-10-10 · Statut : acceptée · Décisions du commanditaire du 2026-10-09

## Contexte

Le formulaire « Ajouter une personne » déposait une `creation_personne` sans rien vérifier.
Il produisait donc des doublons de deux sortes :

- d'une **personne déjà dans l'arbre** — il aurait fallu compléter sa fiche ;
- d'une **proposition déjà en attente** — il aurait fallu la compléter.

La seconde n'avait **aucune issue**. Depuis l'ADR 0004, aucune demande enregistrée ne se
modifiait, même par son auteur : il pouvait la soumettre, la retirer, la refaire après un refus,
rien d'autre. Celui qui retrouvait l'acte de décès de sa grand-tante une semaine après l'avoir
proposée devait retirer sa proposition et tout ressaisir, ou en déposer une seconde. Un autre
membre ne voyait pas les propositions des autres (contrat d'API, § 13.3) : il déposait la même
personne sans le savoir. Seul l'administrateur voyait un « doublon probable », sur une clé
exacte, trop tard — et cette clé était cassée en production (`iconv` sous Alpine, corrigé le
09/10).

La détection a été livrée d'abord (« une proposition semblable attend déjà », contrat
§ 6.6 quater). Cet ADR consigne ce qu'on fait **ensuite** de ce qu'elle trouve.

## Décisions du commanditaire (2026-10-09)

1. **Les propositions des autres membres.** Celle d'un **défunt** se montre en clair — nom,
   dates, lieux, auteur. Celle d'un **vivant** ne donne que « une proposition semblable attend
   déjà la validation », sans dates : il ne faut pas pouvoir deviner une date de naissance en
   tâtonnant. Les siennes, et toutes pour l'administrateur, sont en clair. Les brouillons non
   envoyés ne sont visibles que de leur auteur.
2. **Compléter une proposition en attente.** L'**auteur** et l'**administrateur** la
   complètent **sur place**. Un **autre membre** dépose un **complément** rattaché, que
   l'administrateur **fusionne** en un clic.

(La troisième décision du même jour — compléter depuis le formulaire une personne **déjà dans
l'arbre** — passe par les demandes existantes, `modification_personne` et `ajout_lien`, et n'a
rien à consigner ici.)

## Décision

### 1. Compléter sur place — `PUT /api/demandes/{id}/brouillons/{ref}`

| Lecteur | `brouillon` | `en_attente` | tranchée |
|---|---|---|---|
| l'auteur | oui | oui — les administrateurs sont prévenus | `409` |
| un administrateur, sur la demande d'un autre | **non** (`403`) — un brouillon est le travail en cours de son auteur | oui — l'auteur est prévenu | `409` |
| un autre membre | non | non : il dépose un complément | non |

- `fiche` **remplace tous les champs** — une clé absente vaut `null`, comme au dépôt. Une
  complétion qui rapiècerait laisserait le client deviner si « absent » veut dire « inchangé »
  ou « effacé », et la réponse dépendrait de l'écran.
- `liens` **ajoute**, et seulement ; le **rang est attribué par le serveur**. Retirer un lien,
  c'est faire une autre proposition.
- **La fiche est réécrite en place, jamais recréée.** D'autres demandes la citent par
  l'identifiant de sa ligne, sans clé étrangère (`*_draft_person_id`) : recréée, elle les
  laisserait pendantes en silence, et leur validation tomberait en `dependance_morte` sur une
  personne qui existe.
- **Une fiche citée par un lien ajouté doit être visible de l'auteur de la demande**, pas du
  lecteur : sans quoi l'administrateur glisserait dans la demande d'un membre une fiche que ce
  membre ne voit pas.
- `updated_at` bouge à chaque complétion — l'`ETag` du graphe en dépend — et la clé de doublon
  est recalculée.

### 2. L'empreinte d'une demande

Deux personnes peuvent désormais écrire dans la même demande à quelques minutes d'intervalle.
Toute écriture d'après lecture — compléter, fusionner, **valider** — accepte une **empreinte**
facultative, rendue par chaque lecture d'une demande : un sha256 de son statut, de son
`updated_at` à la seconde, de chaque champ de chaque fiche et de chaque lien. Périmée, la route
rend `409 demande_modifiee_entre_temps` **sans rien écrire**.

**Calculée, jamais stockée.** `prp_change_request` n'a pas de colonne de version, et en ajouter
une coûterait une migration pour un besoin que la donnée couvre déjà. **Facultative** : un client
qui ne l'envoie pas écrit comme avant — l'ajout est compatible.

### 3. Le complément — `prp_change_request.complement_de_id`

Une `creation_personne` ordinaire — une fiche, au moins un lien — qui porte `complementDe`,
l'identifiant de la **fiche** complétée. Une fiche et non une demande : une création peut en
porter plusieurs.

- **Une fiche, au moins un lien** : le complément doit rester **validable seul**, parce que
  l'origine peut mourir avant la fusion — il devient alors une création autonome.
- **Origine en attente** au dépôt **et** à la soumission (`409 proposition_plus_en_attente`,
  sans dire laquelle des issues l'a tranchée : le déposant ne voit pas la proposition d'un
  autre).
- **Pas sur sa propre proposition** (elle se complète sur place), **pas sur un complément**
  (pas de chaîne).
- `refaire` ne reprend pas le rattachement : un complément refusé se refait en création
  ordinaire.
- La ressemblance ne parcourt pas les compléments ; `doublonsProbables` ne les oppose pas à leur
  origine.

### 4. Deux validations bloquées

| La demande | `409` | Écrit |
|---|---|---|
| une proposition qui a des compléments en attente | `complements_en_attente` (`rejouable: true`) | rien |
| un complément dont l'origine est en attente ou validée | `complement_a_fusionner` (`rejouable: false`) | rien |

Une origine refusée, retirée ou caduque rend le complément **autonome**. Ce qui condamne
(caducité) l'emporte sur ce qui fait attendre ; parmi ce qui fait attendre, le complément passe
avant la dépendance — la fusion est un geste de l'écran qu'on regarde.

### 5. La fusion — `POST /api/administration/demandes/{id}/fusionner/{complementId}`

En **une transaction**, précédée d'un **aperçu** calculé par le même code (`GET` même chemin,
et `complements[].apercu` sur la vue de l'origine) :

- **l'origine l'emporte, le complément remplit ce qui est vide** ; un désaccord est un conflit,
  tranché pour l'origine sauf `retenir[champ] = "complement"` ; « décédé » ne se contredit pas,
  et un décès recopié le pose ; deux notes s'ajoutent, elles ne se disputent pas ;
- les liens sont **traduits** (`#p1` du complément → la référence de la fiche d'origine) et
  ajoutés, sauf ceux déjà présents et ceux qui désignent une fiche **d'une autre demande** —
  l'auteur de l'origine n'a pas forcément le droit de la citer ;
- les **pièces** suivent la fiche d'origine, sans changer de statut ni de déposant ;
- les demandes qui citaient la fiche du complément sont **repointées** sur l'origine ;
- le complément est clos `refusee`, `duplicate_of_id` = l'origine, motif « Fusionné dans la
  demande n° O. » — **directement**, pas par le refus, qui enverrait « votre demande a été
  refusée » à un auteur dont le travail vient d'être repris.

### 6. Ce qu'on ne laisse pas fuir

La règle n° 1 du commanditaire ne s'arrête pas à la ressemblance. Elle vaut pour tout ce que le
complément touche ensuite :

- la réponse au dépôt ne donne de l'origine que l'identifiant de fiche déjà connu ;
- un nom de **vivant proposé par un autre auteur** ne se recopie pas dans la demande d'un autre
  (libellés stockés), et ne s'y **relit** pas (sérialisation, libellé des dépendances, donc
  notification de caducité) : c'est le nom que l'auteur avait lui-même saisi qui sert ;
- les notifications (`demande_completee`, `complement_fusionne`) nomment la **demande** et les
  **champs**, jamais la personne, jamais le contributeur.

Le **numéro** de la demande d'origine, lui, est rendu à l'auteur du complément — dans sa liste,
puis par `duplicate_of_id` et le motif de clôture. Un numéro n'apprend rien d'une personne, et
la demande lui reste fermée (`403`).

### 7. Les pièces d'une proposition se lisent par son auteur

Jusqu'ici, une pièce jointe à une personne proposée ne se lisait que par **son déposant** et
l'administration — c'était la même personne que l'auteur de la demande. La fusion verse dans la
proposition des pièces déposées par un autre membre, et l'administrateur peut en joindre : leur
auteur les verrait listées, et leurs octets lui répondraient `404`. `CatalogueMedia::lisible()`
admet donc aussi **l'auteur de la demande**, qu'il demande à `Proposal` par le noyau
(`FichesProposeesInterface::estAuteurDe()`, repli fermé).

## Alternatives écartées

- **Laisser tout membre modifier la proposition d'un autre.** Contredit la règle n° 1 — il
  faudrait la lui montrer — et l'ADR 0004 : la proposition d'Opale deviendrait celle de Tuf
  sans décision de personne.
- **Fusionner automatiquement à la validation.** L'administrateur validerait des valeurs qu'il
  n'a pas vues ; les conflits n'auraient personne pour les trancher.
- **Refuser le complément « en renvoyant à l'autre » (§ 6.9).** C'est ce que l'écran proposait
  aux doublons probables : les liens et les pièces du second étaient perdus, et son auteur
  recevait un refus. La fusion les reprend, et le dit.
- **Pointer une demande plutôt qu'une fiche.** Ambigu dès qu'une création porte plusieurs
  fiches.
- **Supprimer puis recréer la fiche à la complétion.** Le plus simple à écrire, et il casse en
  silence chaque demande qui la cite par sa ligne.
- **Une colonne de version sur `prp_change_request`.** Une migration et un verrou à entretenir
  pour ce qu'une empreinte calculée dit aussi bien.

## Conséquences

- Une demande **en attente n'est plus figée** : ce que l'administrateur a lu peut changer. Les
  écrans qui écrivent après lecture renvoient l'empreinte ; ceux qui ne le font pas écrivent
  comme avant, sur l'état courant.
- Un complément apparaît comme un nœud en attente **dans le graphe de son auteur et de
  l'administrateur**, jusqu'à la fusion. L'administrateur voit donc, le temps de la fusion, deux
  nœuds pour la même personne — l'origine et le complément ; la fusion clôt le second.
- `ResolutionPrevue` gagne deux valeurs, `NotificationKind` deux natures (sans migration :
  `core_notification.kind` est une chaîne). Le front doit les traiter — un `switch` exhaustif
  sur `resolutionPrevue`, deux libellés de notification.
- La file d'administration montre `complementDeDemandeId` : un complément s'y signale comme tel,
  et son geste est la fusion.

Le détail des routes est au contrat d'API, § 6.3 ter, § 6.5, § 6.6 ter, § 6.7, § 6.7 bis, § 6.8
et § 8 ; le schéma, au modèle de données, § 5.1.
