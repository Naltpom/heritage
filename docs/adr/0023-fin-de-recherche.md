# ADR 0023 — La fin de recherche : clore un côté, faute d'archives

Date : 2026-10-30 · Statut : acceptée

## Contexte

Au sommet de chaque branche, l'arbre ne distingue pas deux situations que le généalogiste
distingue toujours : **personne n'a encore cherché** les parents de cette personne, ou
**on a cherché, et les archives s'arrêtent là** — les registres de la paroisse commencent
en 1668, ils ont brûlé en 1871, l'enfant a été trouvé sur le parvis, le père n'est pas
nommé. Le second cas est un résultat de recherche à part entière : il évite de rechercher
deux fois ce qui ne se trouvera pas, et il dit pourquoi une branche s'arrête.

Rien ne l'enregistrait. Une note libre (§ 3.5 du modèle) pouvait le raconter, mais une note
n'a ni côté ni motif : l'arbre ne peut pas la lire, une affiche non plus, et la boucle de
recherche qui complète l'arbre génération par génération reprend chaque fois les mêmes
sommets.

Le commanditaire décrit la fonction ainsi :

- sur une personne, une fin de recherche **par côté** (père, mère, les deux), avec un
  **motif** pris dans une liste — registres inexistants avant une année, registres
  détruits, lacune, père inconnu, enfant trouvé ou abandonné, lieu d'origine inconnu,
  homonymie impossible à trancher, autre —, un **texte libre**, la **date** et l'**auteur** ;
- elle passe par le **circuit des demandes** comme les autres champs : déposable par l'API
  sous la portée `contribution`, validée comme le reste ;
- elle suit la **même règle de visibilité que les notes** ;
- elle se voit sur la fiche (lecture et saisie), au sommet d'une branche de `/arbre` (trois
  états : parents connus, recherche close, pas encore cherché) et sur les affiches (une
  icône, une ligne de légende « recherche close faute d'archives »).

## Décision

### 1. Deux côtés indépendants ; « les deux » est un raccourci de saisie

Une fin de recherche porte sur **un** côté : `pere` ou `mere` (`Core\Enum\CoteDeRecherche`).
« Les deux » n'est pas une valeur stockée : c'est un geste de saisie, qui dépose **deux
ops dans la même demande** — validée ou refusée d'un bloc, comme toute demande.

Trois raisons, qui suffisent chacune :

- **l'arbre a besoin de l'état de chaque côté.** Une carte peut avoir un père connu et une
  mère introuvable ; l'état se juge côté par côté (§ 4) ;
- **un parent se retrouve d'un seul côté.** Avec une valeur `les_deux`, rouvrir le côté du
  père obligerait à couper la ligne en deux — une écriture que personne n'a demandée, sur
  une ligne dont l'auteur et la date ne seraient plus ceux de la levée ;
- **les motifs diffèrent d'un côté à l'autre** : « père inconnu » d'un côté, « registres
  détruits » de l'autre, pour le même enfant naturel.

Une valeur à trois modalités aurait de plus exigé une contrainte d'exclusion (`les_deux`
recouvre `pere` et `mere`) là où deux côtés indépendants n'ont besoin que d'un index unique.

### 2. Une table dédiée, `tree_research_closure`, une ligne par côté clos

| Colonne | Rôle |
|---|---|
| `person_id` | FK intra-module → `tree_person(id)`, `ON DELETE CASCADE` |
| `side` | `pere` \| `mere` |
| `reason` | le motif, `Core\Enum\MotifDeFinDeRecherche` |
| `before_year` | l'année avant laquelle les registres n'existent pas — pour `registres_inexistants`, et pour lui seul |
| `body` | le texte libre, `NULL` quand il n'y en a pas |
| `author_user_id`, `author_label` | le déposant (§ 5), inter-module vers `core_user` comme `tree_note` |
| `closed_at` | la date du dépôt (§ 5) |
| `created_at`, `updated_at` | l'horodatage de la ligne (§ 0.3 du modèle) |

**L'unicité est la règle « un côté, une fin de recherche »** :
`uniq_tree_research_closure_side (person_id, side)`. Une recherche close s'écrit par une
ligne ; rouvrir la recherche supprime la ligne — l'historique en reste dans `prp_revision`.

Les règles de forme sont tenues au dépôt, devant l'auteur (§ 3), et rappelées en base comme
filets (`ck_tree_research_closure_*`) :

- `side IN ('pere', 'mere')` ; `reason` dans la liste des huit motifs ;
- `before_year` renseignée **si et seulement si** `reason = 'registres_inexistants'`, entre
  1000 et 2999 en base (au dépôt : entre 1000 et l'année en cours) ;
- `reason = 'pere_inconnu'` seulement pour `side = 'pere'` ;
- `reason = 'autre'` exige un `body` non vide ; `body` fait au plus 4 000 caractères.

**Pourquoi pas des colonnes sur `tree_person`.** Deux côtés de six données chacun, ce sont
douze colonnes sur la table la plus lue de l'arbre, avec chaque `CHECK` écrit deux fois. Une
fin de recherche a de plus sa propre vie — son auteur et sa date, qui ne sont pas ceux de la
fiche —, exactement comme une note (§ 3.5) : c'est une ligne fille, et son absence dit
« pas de fin de recherche » sans tri-état à interpréter.

**Les types partagés montent dans `Core`** (règle 3 de l'ADR 0002, § 2.8 du modèle) :
`Core\Enum\CoteDeRecherche`, `Core\Enum\MotifDeFinDeRecherche` (avec `label()`) et l'objet
valeur `Core\ValueObject\FinDeRecherche` (`reason`, `beforeYear`, `body`). `Tree` les mappe,
`Proposal` les valide ; aucun des deux ne nomme l'autre.

Les libellés, au caractère près (le front les recopie dans `app/src/lib/finDeRecherche.ts`) :

| `reason` | `label()` | Côtés | Paramètre |
|---|---|---|---|
| `registres_inexistants` | Registres inexistants avant une année — en clair : « Registres inexistants avant 1668 » | les deux | `beforeYear`, obligatoire |
| `registres_detruits` | Registres détruits | les deux | — |
| `lacune` | Lacune dans les registres | les deux | — |
| `pere_inconnu` | Père inconnu | `pere` seulement | — |
| `enfant_trouve` | Enfant trouvé ou abandonné | les deux | — |
| `origine_inconnue` | Lieu d’origine inconnu | les deux | — |
| `homonymie` | Homonymie impossible à trancher | les deux | — |
| `autre` | Autre motif | les deux | `body` obligatoire |

### 3. Elle entre dans le circuit comme un champ de la personne

**Ni nouveau `RequestKind`, ni nouvel `OpKind`.** La surface éditable d'une personne
(`SurfaceEditable::PERSONNE`) gagne deux champs, **`finDeRecherchePere`** et
**`finDeRechercheMere`**, d'une nouvelle nature de valeur composite, **`fin_de_recherche`**
(`ValueKind::FinDeRecherche`) : un objet à **trois clés, toujours les trois**,
`{reason, beforeYear, body}` — ou `null`, « pas de fin de recherche ». Les trois clés sont
solidaires pour la raison qui rend un bloc date atomique : un administrateur qui validerait
le motif sans son année fabriquerait une fin de recherche qu'aucune main n'a écrite.

| Geste | Demande | `oldValue` | `newValue` |
|---|---|---|---|
| **poser** | `modification_personne` | `null` | `{reason, beforeYear, body}` |
| **modifier** | `modification_personne` | la valeur courante | la nouvelle |
| **lever** — rouvrir la recherche | `modification_personne` | la valeur courante | `null` |
| poser des deux côtés | **une** `modification_personne`, deux ops | — | — |

Le choix tient à ce que le circuit des champs fait déjà, et qu'un type dédié aurait dû
refaire un à un :

- **le calque « en attente »** est indexé par champ (§ 13 du contrat) :
  `enAttente.finDeRecherchePere` existe sans une ligne de code, pour l'auteur et pour
  l'administrateur, et seulement pour eux ; un op d'une autre nature n'y entre pas ;
- **la caducité** compare la valeur courante à `old_value`, op par op (§ 9.6 du modèle) :
  deux poses concurrentes du même côté, une modification croisée d'une levée, et la seconde
  devient caduque au lieu d'écraser la première. Une demande qui touche un autre champ de la
  fiche se **rejoue** ;
- **le diff** (`libelleDiff`), la révision, les notifications, « refaire cette demande »,
  l'idempotence (ADR 0022) et le dépôt à blanc la traitent comme un nom ou une date ;
- **la portée `contribution`** d'un jeton (ADR 0021) ouvre déjà `POST /api/demandes` : la
  liste blanche des routes ne change pas. Le **retrait** suit la règle des PR #231 et #232 :
  sous un jeton, un brouillon se retire, une demande en attente non ; sans jeton, son auteur
  retire sa demande tant qu'elle n'est pas tranchée. **Lever n'est pas retirer** : le retrait
  annule une demande que personne n'a encore tranchée, la levée est une demande qui efface
  une fin de recherche validée — et qui se valide comme telle.

`fin_de_recherche` tient exactement dans `prp_change_op.value_kind VARCHAR(16)`, et l'op
nomme son champ (`ck_prp_op_champ`) : **aucune migration de `prp_change_op`**.

**Les contrôles du dépôt**, en `422` sur le chemin de l'op (`ops[n].newValue.reason`…), dans
le recueil du dépôt à blanc comme les autres :

- l'objet porte les trois clés, et elles seules — `authorLabel` et `closedAt` sont en
  lecture seule, comme le `texte` d'un bloc date ;
- les règles de forme du § 2 ; `body` est rogné, et vide il vaut `null` ;
- **une pose ou une modification sur un côté dont un parent est déjà lié est refusée
  au dépôt** : la recherche n'y est pas close. La levée reste toujours possible. Le
  constructeur le demande à l'arbre par `PortArbre::cotesConnus()` (lecture, aucune
  écriture). **Ce refus n'a lieu qu'au dépôt** : un parent lié entre le dépôt et la validation
  ne rend pas la demande caduque, l'écriture a lieu (le contrat le dit), et l'affichage fait
  alors primer « parents connus » (§ 4) ;
- `oldValue` égal à `newValue` est refusé, comme pour tout champ.

**À la validation**, `Tree` écrit la ligne (insertion, mise à jour ou suppression) dans
`appliquerChamps()` et **touche la personne** : sa `version` monte, l'empreinte de l'arbre
change, l'`ETag` du graphe aussi (ADR 0005) — sans élargir l'empreinte, qui reste aux trois
tables structurantes. L'auteur et la date de la demande (§ 5) traversent le port par un
paramètre nouveau et facultatif, `SignatureDeDemande`, que le validateur passe toujours ; une
fin de recherche écrite sans signature lève, plutôt que de s'écrire sans auteur.

### 4. Un parent connu l'emporte ; la fin de recherche reste enregistrée

L'état d'un côté, au sommet d'une branche :

| Parent lié de ce côté | Fin de recherche | État | Ce qui se voit |
|---|---|---|---|
| oui | — ou oui | **`connus`** | la branche continue |
| non | oui | **`close`** | le mur, le bord brique, le motif |
| non | non | **`a-chercher`** | la carte telle quelle |

**Un parent lié du côté clos l'emporte à l'affichage, et la fin de recherche reste
stockée.** Elle ne disparaît que par sa levée — une demande, relue comme les autres. Aucune
levée implicite n'est injectée dans la demande qui lie le parent : il faudrait greffer un op
de champ dans une `creation_personne` ou un `ajout_lien`, qui n'en portent pas ; et le motif
garde sa valeur de trace — « registres détruits » reste vrai quand le père a été retrouvé
par un acte notarié. La fiche le dit à côté de la fin de recherche, et offre de la lever.

« Un parent lié de ce côté » se lit **à la place qu'il occupe** dans une famille dont la
personne est l'enfant (toute filiation) : l'époux (`husband_id`) est le père, l'épouse
(`wife_id`) la mère — la place que `Tree` donne déjà selon le sexe, un parent de sexe inconnu
prenant la place libre, celle du père d'abord. La fiche la sert (`parents[].cote`) ; le graphe
la laisse déduire de ses arêtes par la même règle (`cotesConnusDansLeGraphe()`) ; l'affiche
par `husband` et `wife` de la famille retenue.

### 5. L'auteur est le déposant, la date est celle du dépôt

- **L'auteur** est celui de la demande (`prp_change_request.author_user_id` et
  `author_label`), **pas l'administrateur qui la valide** — la règle des notes (§ 6.4 ter du
  contrat). C'est lui qui a cherché et conclu ; le validateur et sa date restent dans
  `prp_revision` et `decided_at`. Sous un jeton d'API, l'auteur est le compte du jeton
  (ADR 0021) : l'agent n'est jamais un auteur.
- **La date** est celle du **dépôt** (`submitted_at`), pas de la validation. Une fin de
  recherche date l'état des sources consultées : « à cette date, les archives ne permettent
  pas d'aller plus loin ». Des registres mis en ligne entre le dépôt et la validation sont
  précisément ce que cette date doit permettre de repérer.
- Une **modification** re-signe la ligne : l'auteur et la date sont ceux de la dernière
  demande validée qui l'a écrite.

### 6. Visibilité : exactement celle des notes

Il n'existe pas de règle propre aux notes : **une note est servie avec la fiche complète,
jamais avec la fiche réduite** (`CercleDuLecteur::voit()`, ADR 0003). La fin de recherche
reprend cette règle mot pour mot, aux trois points d'entrée que lit l'interface :

| Point d'entrée | Où | Servie quand |
|---|---|---|
| Fiche — `GET /api/arbre/personnes/{id}` | `NormaliseurDePersonne::ficheComplete()` | la fiche est complète ; **absente** de la fiche réduite (sept clés, § 11.1) |
| Graphe — `GET /api/arbre/graphe` | `NormaliseurDeGraphe::noeudDePersonne()`, **après** la branche `if (!$visible)` | le nœud est `complete` ; jamais sur un nœud `restreinte` (§ 11.2) |
| Affiche — `GET /api/arbre/familles/{id}/affiche` | `NormaliseurDAffiche::personne()`, branche complète | la personne est `complete` ; jamais sur ses cinq clés restreintes |

Le calque (`enAttente`) ne change pas de règle : il n'est servi qu'à l'auteur de la demande et
aux administrateurs (§ 13.3 du contrat), et seulement sur une fiche complète. La place d'un
parent (`parents[].cote`) est de la topologie, que le graphe expose déjà à tout lecteur : elle
n'apprend rien de plus.

### 7. Trois sérialisations, une forme

- **Fiche complète** : `finDeRecherchePere` et `finDeRechercheMere`, **toujours présentes**,
  `null` quand le côté n'est pas clos, sinon
  `{reason, beforeYear, body, authorLabel, closedAt}`. Ce sont des champs de la surface : leur
  clé est celle du calque, et leur valeur, privée de `authorLabel` et `closedAt`, est
  l'`oldValue` de l'op qui les modifie.
- **Nœud du graphe** et **personne d'affiche** : les mêmes clés, **absentes** quand le côté
  n'est pas clos, réduites au **résumé** `{reason, beforeYear}` — de quoi dessiner le mur et
  dire le motif ; le texte et l'auteur se lisent sur la fiche. Un nœud ne porte pas de note,
  il ne porte pas non plus le texte d'une fin de recherche.
- **Demandes** : `valueKind: "fin_de_recherche"`, valeurs à trois clés ; `libelleDiff` et la
  révision disent « Fin de recherche côté père : (aucune) → Registres inexistants avant
  1668 — Saint-Martin commence en 1668 ».

### 8. Ce qui se voit

- **La fiche** : un encart « Fin de recherche » dans l'onglet Identité, après la famille ; par
  côté, l'état, le motif, le texte, « close le … par … » ; « Clore la recherche », « Modifier »,
  « Rouvrir la recherche » pour qui peut proposer ; la valeur proposée en attente sous la valeur
  validée, comme tout champ.
- **`/arbre`** : la carte d'un côté `close` porte un **bord rouge brique** (jeton
  `--h-fin-de-recherche`, `#9b3426` en clair, distinct de l'orange brûlé de `--h-alerte`) et
  une icône **mur** ; le motif se lit au survol (`title`) et au clic (volet).
- **L'affiche** : le mur, imprimé en brique, sur la case de l'ancêtre clos, et la ligne de
  légende « recherche close faute d'archives » — seulement si l'affiche en dessine au moins un.
  Le couple porte son mur au premier anneau, ou au centre quand les quatre parents y sont
  dessinés. **Les conjoints des descendants n'en ont pas** : l'affiche ne sert pas leurs
  parents, il n'y a donc rien à clore sous leur case.

## Conséquences

- Une migration (`tree_research_closure`, son index unique, ses contraintes) ; aucune
  reprise : la table naît vide.
- `Core` gagne deux énumérations et un objet valeur ; `Proposal` gagne une nature de valeur,
  deux champs de surface et leurs libellés (« Fin de recherche côté père », « … côté mère ») ;
  `NormaliseurDeValeur` apprend la forme, et la comparaison de caducité cesse de supposer
  qu'un `old_value` objet est un bloc date (`AnalyseDeDemande::normaliserStocke()`).
- `PortArbre::appliquerChamps()` gagne un paramètre facultatif (`SignatureDeDemande`) et le
  port une lecture, `cotesConnus()` ; `ArbreNonBranche` et le double de test suivent.
- **Rien ne part en silence** : l'inventaire d'une suppression de fiche compte ses fins de
  recherche (`finsDeRecherche`, non bloquantes), et une fin de recherche posée entre le dépôt
  et la validation d'une suppression la rend caduque, comme une note.
- `tree_research_closure.author_user_id` rejoint les références inter-modules contrôlées par
  `heritage:verifier-integrite`.
- Le contrat gagne le § 6.2 bis et des clés aux § 5.2, 5.3, 5.4 bis, 6.4 quater, 11, 13 ; le
  modèle, la table (§ 3.5 bis), les types partagés (§ 2.8), la nature de valeur (§ 5.2, § 5.7)
  et la surface (§ 9.4). Le front gagne les types et un module pur partagé,
  `app/src/lib/finDeRecherche.ts`.
- **Aucun ADR n'est amendé** : la règle de visibilité est celle de l'ADR 0003, l'écriture celle
  de l'ADR 0004, la portée celle de l'ADR 0021 — reprises telles quelles.
- **Ce qui n'est pas fait, et pourrait l'être** : la page « Questions » demande encore « qui est
  son père ? » d'une personne dont la recherche est close de ce côté (`pere_inconnu`,
  `mere_inconnue` du module `Question`) ; les statistiques comptent toujours ces personnes
  « sans parents » ; l'assistant ne propose pas de fin de recherche (sa liste de champs est
  close) ; une fiche **proposée** (`creation_personne`) n'en porte pas ; `/arbre-2` ne la dessine
  pas.

## Refusé

- **Un `RequestKind` et un `OpKind` dédiés**, sur le modèle d'`ajout_note` : un constructeur,
  un applicateur, deux méthodes de port, une extension du calque, du diff, de « mes demandes »
  et de « refaire » — pour obtenir ce que la surface des champs donne déjà, caducité comprise.
- **Des colonnes sur `tree_person`** : voir le § 2.
- **Une valeur `cote` à trois modalités** : voir le § 1.
- **Le validateur comme auteur, la validation comme date** : voir le § 5.
- **Une levée automatique** quand un parent est lié du côté clos : voir le § 4.
- **`tree_research_closure` dans l'empreinte de l'arbre** : elle ne déplace aucun nœud, et
  l'écriture touche déjà la personne (ADR 0005).
