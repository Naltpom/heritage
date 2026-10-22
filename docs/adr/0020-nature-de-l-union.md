# ADR 0020 — La nature d'une union : mariés, union libre, on ne sait pas

Date : 2026-10-22 · Statut : acceptée

## Contexte

Une union (`tree_family`) porte une date de mariage — le bloc `marriage_*`, dont le
qualificatif vaut `inconnue` quand rien n'est connu — et le fait d'une rupture, `divorced`,
avec sa date `divorce_*`. « Une union sans date reste une union » (§ 6.4 du dossier de
mission), mais **rien ne dit si elle est un mariage**. Mesuré en production le 2026-10-22 :
sur 1 951 familles, 1 018 portent une date ou un lieu de mariage, 933 n'en portent aucun —
et dans ces 933 se mêlent des couples mariés dont l'acte n'a pas été retrouvé et des
unions libres, sans qu'on puisse les distinguer.

Deux symptômes à l'écran :

- la fiche affichait « Mariage : date inconnue » sous **toute** union, union libre comprise —
  une affirmation que la base ne portait pas ;
- `divorced` couvre déjà **toute** rupture (§ 3.2 du modèle : « séparation comme divorce » ;
  ADR 0016 : « une union rompue »), et l'interface hésitait : « Divorce » sur la fiche,
  « Séparés » dans le diff d'administration, « Divorce prononcé » dans le libellé du serveur.

Le déclencheur : le commanditaire atteste que quatre couples de sa famille se sont mariés —
trois ont divorcé depuis —, dates inconnues. L'arbre ne pouvait pas l'enregistrer.

## Décision

### 1. Une colonne tri-état, `married`

`tree_family.married BOOLEAN NULL` — et son jumeau `prp_draft_link.married`, la nature d'un
lien `conjoint_de` proposé :

| Valeur | Sens |
|---|---|
| `true` | mariage attesté, **daté ou non** |
| `false` | union sans mariage — union libre, PACS |
| `NULL` | on ne sait pas |

Même raisonnement que `deceased` / `death_*` et `divorced` / `divorce_*` : **le fait et sa date
sont deux choses.** Trois valeurs et non un booléen à défaut : `false` par défaut aurait
déclaré « union libre » les 933 unions dont on n'a pas retrouvé l'acte, `true` par défaut les
aurait toutes mariées.

### 2. Un mariage daté ou situé est un mariage

Une date (`marriage_qualifier <> 'inconnue'`, ou une chaîne `marriage_raw`) ou un lieu de
mariage impliquent `married = true`. La règle est tenue à trois étages, chacun pour une
raison :

- **En base**, `ck_tree_family_mariage_coherent` :
  `married IS TRUE OR (marriage_qualifier = 'inconnue' AND marriage_raw IS NULL AND
  marriage_place IS NULL)`. `IS TRUE` et non `= true` : un `CHECK` passe quand son expression
  vaut `NULL`, et `married = true OR …` aurait laissé passer une nature nulle sous une date.
- **À l'écriture**, l'entité `Tree\Family` normalise : poser une date ou un lieu de mariage
  pose `married = true`, et la nature ne redescend pas tant qu'ils sont là
  (`Family::setMarried()`). Le service d'écriture pose la date **avant** la nature, quel que
  soit l'ordre des ops — « retirer la date et dire union libre » ne dépend pas de l'ordre
  d'arrivée. C'est la normalisation, et non une exception, parce qu'elle doit satisfaire la
  contrainte sur **tous** les chemins (validation, arbre d'essai des fixtures) ; le critère
  est écrit une fois, `UncertainDate::estRenseignee()`, recopié terme pour terme du `CHECK`.
- **Au dépôt**, `Proposal` contrôle, devant l'auteur :
  - une `modification_famille` qui date (ou situe) une union de nature **inconnue** sans rien
    dire de sa nature reçoit l'op `married : null → true`, avec la `baseVersion` de l'op qui
    date. Un op et non une écriture muette de `Tree`, pour trois raisons : l'administrateur
    le lit dans le diff (« Mariés : on ne sait pas → oui »), la révision le journalise, et le
    **rejeu** le confronte — une union dite libre entre-temps rend la demande caduque au lieu
    d'être requalifiée sans que personne l'ait décidé ;
  - une demande qui **contredit** sa propre date (« union libre » ou « on ne sait pas » sous
    une date de mariage), ou qui date une union enregistrée **libre** sans la requalifier, est
    refusée en `422` : une pièce et une déclaration ne se départagent pas à la place de
    l'auteur ;
  - un lien `conjoint_de` daté reçoit `married = true` ; `false` sous une date est refusé.

On **déduit** ici alors qu'on **refuse** pour le décès (`ConstructeurDeModification::
verifierQueLeDecesEstCoherent()`) : `deceased` ouvre la visibilité d'une fiche (ADR 0003), la
nature d'une union n'ouvre rien, et la déduction est certaine.

### 3. `divorced` reste toute rupture ; le libellé suit la nature

`divorced` garde son sens — **toute** rupture, divorce ou séparation —, et c'est `married` qui
dit laquelle :

| `divorced` | `married` | Libellé |
|---|---|---|
| `true` | `true` | **Divorcés** |
| `true` | `false` ou `NULL` | **Séparés** |

On ne divorce que d'un mariage, et l'on n'affirme pas un mariage qu'on ignore. Écarté :
« `divorced` implique `married` », vrai en français courant mais faux ici — une contrainte
aurait requalifié en mariages les 6 ruptures de la production sans aucune trace de mariage,
et refusé toute séparation d'une union libre. La règle retenue ne change le sens d'aucune
donnée existante.

### 4. La reprise

`married = true` là où une date ou un lieu de mariage existent (1 018 lignes le 2026-10-22),
`NULL` partout ailleurs — ruptures comprises. Rien d'autre ne bouge : `prp_draft_link.married`
naît nul, et un lien en attente qui porte une date sera normalisé à sa validation par
l'entité — comme une `modification_famille` déposée avant l'ADR qui date une union : elle
n'a pas d'op `married`, et c'est `Family` qui pose la nature en écrivant la date.
Migration `Version20261022090000`.

### 5. Ce qui se voit

- **La fiche** : « Mariés le 23 septembre 1927 — Saule », « Mariés — date inconnue »,
  « Union libre », rien quand on ne sait pas ; « Divorcés » ou « Séparés », avec la date de
  rupture quand elle existe.
- **L'édition d'une union** : un choix « Mariés : oui / non (union libre) / on ne sait pas » ;
  « oui » s'impose, les deux autres s'éteignent, tant qu'une date ou un lieu de mariage est
  saisi. La date de rupture s'intitule « divorce » ou « séparation » selon ce choix.
- **Le diff d'administration** : « Mariés : on ne sait pas → oui », « Divorcés : non → oui »
  ou « Séparés : non → oui » selon la nature **après** la demande.
- **L'arbre** (`/arbre`) : aucun tracé ne change. Une union libre se dessine comme un mariage,
  sans pointillés (§ 7.3 du dossier, ADR 0007) ; le nœud de famille porte `married` pour qui
  voudra le dire.

## Conséquences

- Deux colonnes nullables, une contrainte nouvelle (`ck_tree_family_mariage_coherent`), une
  contrainte redéfinie (`ck_prp_link_dates_union`, la nature ne vaut que sur `conjoint_de`).
- Le contrat d'API gagne `married` sur la fiche d'une famille, les unions d'une fiche de
  personne, les nœuds de famille du graphe et les liens d'une demande ; la surface éditable
  d'une famille gagne `married`, **le seul booléen tri-état** (`ValueKind::Booleen`, `null`
  admis pour ce champ seulement — `SurfaceEditable::admetNul()`).
- `porterUnion()` du port `PortArbre` prend la nature en plus des dates.
- L'empreinte de l'arbre change d'elle-même (les colonnes de `tree_family` sont découvertes,
  pas énumérées) ; l'empreinte d'une demande intègre désormais l'union de chaque lien.
- Une famille que seule sa nature distingue reste **vide** au sens d'un retrait de lien : la
  nature qualifie le couple, et un « marié » sans conjoint ne se garde pas.
- Ce qui n'est **pas** fait, et pourrait l'être : compter « mariés, date inconnue » parmi les
  mariages à vérifier dans les statistiques (elles comptent les mariages renseignés) ;
  distinguer le PACS de l'union libre.
