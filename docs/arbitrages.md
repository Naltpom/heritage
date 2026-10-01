# Arbitrages entre le modèle de données et les ADR

> **Ce document est une décision, pas une liste de travail.** Il recense **tous** les points
> sur lesquels `docs/modele-donnees.md` et les sept ADR ne disaient pas la même chose, ce qui
> a été retenu, et pourquoi. Les deux corpus ont été **appliqués** : ils disent désormais la
> même chose, à l'endroit où chacun est lu. Aucun renvoi du type « voir ADR 0005 » ne tient
> lieu de correction — un agent qui n'ouvrira que le modèle de données y trouve la bonne
> version.
>
> Il se lit dans six mois, quand quelqu'un demandera « pourquoi cette décision ».

**Règle d'arbitrage appliquée.** Par défaut, **l'ADR l'emporte** : il porte la décision
d'architecture, le modèle en est l'implémentation. Le modèle ne l'emporte que dans deux cas,
et alors **c'est l'ADR qui est amendé**, avec une ligne datée dans sa section
« Conséquences » : quand il s'appuie sur une **mesure faite sur les données réelles** que
l'ADR ignorait, ou quand la position de l'ADR **n'est pas implémentable** telle qu'écrite.
Un ADR qu'on corrige parce qu'on a mesuré est un ADR qui fonctionne ; un ADR qu'on contourne
en silence est un ADR mort.

**35 arbitrages**, répartis ainsi :

| Issue | Points | Nombre |
|---|---|---:|
| **ADR retenu**, modèle corrigé | A, B, C, D, E, F, K, L, N, O, T | 11 |
| **ADR amendé**, modèle retenu | H, I, M, S, U, V, W, Y, AA | 9 |
| **ADR amendé** sur un fait nouveau | Z, BB, CC, DD | 4 |
| **Remontés par le contrat d'API**, après coup | EE, FF, GG | 3 |
| **La règle d'architecture elle-même** | HH, II | 2 |
| **Manque commun** comblé dans les deux | J, P, Q, R, X | 5 |
| **Mixte** | G | 1 |

Soit **5 amendements d'ADR** — l'ADR 0002 s'ajoutant par le point **HH** — — 0003 (**M**), 0004 (**S**), 0005 (**Z**, **BB**, **CC**, **DD**),
0006 (**U**, **V**, **W**, **Y**) — et **5 erreurs de fait**, toutes vérifiées contre
`arbre-romarin-v5.ged`, `graphe.json` ou `docs/moteur-retrouve/graphe.py` **avant** d'être
corrigées. Une erreur de fait n'est pas un désaccord : un désaccord se voit à l'intégration,
une affirmation fausse que les deux corpus partagent se code sans que personne ne bronche.
C'est la catégorie qu'il fallait traiter en premier.

**Lettres de référence.** Les points **A** à **W** reprennent la numérotation de
`docs/frictions-modele-adr.md`, la liste de travail rédigée par l'auteur du modèle de
données après lecture des ADR — **document absorbé dans celui-ci et supprimé** (voir § 5).
Les points **X** à **DD** ont été trouvés au cours de cette mise en cohérence et ne
figuraient pas dans cette liste. Les points **EE** à **GG** ont été remontés plus tard par
l'écriture du **contrat d'API** (`docs/api-contract.md`, 54 routes) : une conception ne se
vérifie vraiment qu'en essayant de s'en servir.

**Où cet arbitrage s'écarte de cette liste de travail.** Sur ses 23 points, la conclusion
retenue est la sienne 23 fois sur 23 — c'est une liste lucide, écrite par une partie qui
s'est donné tort sur l'essentiel. Deux écarts méritent d'être signalés : sur **M**,
l'exécution diffère (l'ADR est amendé plutôt que le modèle amputé — c'est la seconde issue
que la liste désignait elle-même) ; et sur **M** toujours, **l'argument qui soutenait sa
recommandation est faux** — il s'appuyait sur « l'ordre horizontal suit la branche », que la
lecture du moteur retrouvé dément (**BB**). La conclusion tient, son motif est réécrit. Un
motif faux sous une conclusion juste est une dette : il tombe au premier lecteur attentif et
emporte la conclusion avec lui.

---

## 1. Le tableau

| # | Point en litige | Position du modèle | Position de l'ADR | Retenu | Motif | Fichiers modifiés |
|---|---|---|---|---|---|---|
| **A** | Invalidation des caches | Table `core_cache_stamp`, compteur incrémenté à la main dans la transaction de validation, + liste de sept déclencheurs | Empreinte **recalculée depuis la base** (0003, 0005) | **ADR** | *Une empreinte ne peut pas se désynchroniser ; un compteur qu'on oublie d'incrémenter dans un chemin d'écriture donne un graphe faux qu'on met des semaines à comprendre.* | `modele-donnees.md` §§ 0.6, 1, 2, **2.3 (réécrit)**, 5.5, 9.2, 9.3, 9.4, 9.5, 9.9, 10.1, 10.4, 11 · `adr/0003` · `adr/0005` · `adr/README.md` |
| **B** | Clé du cache de placement | `placement.{stamp}.{lecteurId}` — une entrée par lecteur | Cache à **deux étages** : étage 1 partagé et sans filtre, étage 2 par lecteur et **jamais mis en cache** (0005) | **ADR** | La clé du modèle est **fausse** : `{stamp}` ne bouge qu'à une modification *validée*, or déposer un brouillon n'en est pas une — l'auteur ne verrait pas son propre nœud en attente. Et le gain visé était de moins d'une milliseconde (0,02 ms mesurés). | `modele-donnees.md` **§ 9.9 (réécrit)**, §§ 9.3, 9.5 · `adr/0005` |
| **C** | Résolution d'un conflit | Verrou déclenché → statut `conflit`, l'administrateur **rebase à la main** ou refuse | Comparaison **op par op** : toutes les `old_value` concordent → **rejeu automatique** ; une seule a changé → `caduque` (0004) | **ADR** | Le modèle refusait même sur champs disjoints — c'est-à-dire le cas courant. Deux membres n'auraient pas pu proposer deux corrections la même semaine sans en perdre une, alors que contribuer est le but du produit. | `modele-donnees.md` §§ 5.2, 5.7, 9.4, **9.6 (réécrit)** · `adr/0004` |
| **D** | Nom du statut | `conflit` | `caduque`, **distinct de `refusee`** (0004) | **ADR** | Personne n'a refusé : le monde a bougé. Deux notifications différentes, deux écrans différents — et un bouton « refaire cette demande » sans lequel une caducité est une contribution perdue. | `modele-donnees.md` §§ 2.7, 5.6, 5.7, 9.4, 9.5, 9.6 |
| **E** | Types partagés (`UncertainDate`, `DateQualifier`, `Sex`, `Branch`) | Dans `Tree` | « `Core` est le noyau partagé, **et il est le seul** » (0002, règle 3) | **ADR** | Trois autres modules les instancient : `Tree` était devenu un second noyau sans que personne ne l'ait décidé. | `modele-donnees.md` **§ 2.8 (nouveau)**, §§ 0.5, 3.1, 3.9, 9.1 · `adr/0002` |
| **F** | Qui écrit dans `Tree` | Les algorithmes des §§ 9.4 et 9.5 insèrent directement des `tree_person`, `tree_family`, `tree_child` depuis `Proposal` | « Valider une proposition écrit dans `Tree` **en appelant un service de `Tree`** » (0002, règle 5) | **ADR** | Le schéma était bon, **la prose était fausse** — et c'est la prose qu'un agent recopie. Corollaire : le moteur de placement n'écrit pas `branch`/`generation`/`rank`, sans quoi il perd l'absence d'accès à la base qui le rend testable (0005). | `modele-donnees.md` §§ 3.1, 3.2, 9.4, 9.5 · `adr/0005` |
| **G** | Clé du réglage `N` | `core_setting['cercle.generations_ascendantes']` | `core_settings['cercle.generations']` | **Mixte : `core_setting` (modèle) + `cercle.generations` (ADR)** | Le singulier est la convention de **toutes** les tables du schéma ; la clé courte est celle de l'ADR et la règle par défaut s'applique — « ascendantes » n'ajoute rien, le cercle ne remonte que vers le haut. | `modele-donnees.md` §§ 2.2, 2.3, 9.2, 9.3 · `adr/0003` |
| **H** | Nom de l'entité de rattachement | `Membership\Member` / `mbr_member` | `Membership\Account` (repris du dossier de mission) | **Modèle** | `Core\User` **est déjà le compte**. Deux « comptes » dans deux modules garantissent qu'un agent ira chercher le mot de passe dans `mbr_account`. La valeur de cette table est le *rattachement*. | `adr/0002` |
| **I** | Valeurs de `RequestKind` | Six valeurs, dont trois `modification_*` distinctes | Cinq valeurs, dont un `modification` générique | **Modèle** | `kind` sert à filtrer et grouper la file d'administration ; un générique obligerait à lire `target_kind` pour savoir ce qui est modifié. Ce sont des chaînes qui partent en base : à acter avant la première validation, pas après. | `adr/0004` |
| **J** | Nom de l'entité des ops | Nomme la table `prp_change_op`, pas l'entité | Nomme l'entité `ChangeRequestOp`, pas la table | **Les deux, écrits ensemble** | Aucun désaccord : un manque. Sans la correspondance écrite, Doctrine laissé à lui-même produit `prp_change_request_op`. | `modele-donnees.md` § 5.2 |
| **K** | Édition directe d'administrateur | `prp_revision.request_id` est `NULL` pour une édition directe **ou** pour l'import | Elle crée **quand même** une `ChangeRequest` auto-validée : « aucun chemin d'écriture ne contourne le mécanisme » (0004) | **ADR** | Un chemin sans demande donnerait au calque « en attente » un cas particulier à gérer et à l'historique d'une fiche des trous que rien ne signale. **Exception écrite** : l'import GEDCOM, qui reste sans demande. | `modele-donnees.md` §§ 5.5, 9.4 · `adr/0004` |
| **L** | Marqueur d'auto-validation | Absent | `auto_validation = true` au journal d'audit (0004) | **ADR, en colonne** | Le modèle manquait la règle. Portée en **colonne** `prp_change_request.auto_validated` plutôt qu'en seul champ `JSONB` : « montre-moi toutes les auto-validations » doit être une requête. | `modele-donnees.md` §§ 5.1, 2.7, 9.4, 10.4 |
| **M** | `branch` hors cercle | Liste blanche : `id`, `display_name`, `sex`, `generation`, **`branch`**, arêtes | Liste blanche sans `branch` | **Modèle — et c'est l'ADR 0003 qui est amendé** | Le canevas **colore** les nœuds par quart d'ascendance et la chronologie en fait ses colonnes ; et `branch` **n'apprend rien** — elle se déduit du chemin vers la racine, c'est-à-dire des arêtes, que tout lecteur reçoit. Une liste blanche qui existe en deux versions n'est pas une liste blanche. *(Motif corrigé : voir **BB**.)* | `modele-donnees.md` §§ 3.1, 9.2 · `adr/0003` |
| **N** | Compte sans rattachement | Ne voit rien, sauf l'écran « rattachement en cours de vérification » | **Voit les personnes décédées** (0003) | **ADR — confirmé depuis par le commanditaire, § 3** | Les deux positions ne divergeaient que sur un mot : un compte `en_attente` est-il « connecté » au sens de la règle sur les décédés ? **Oui**, et il fallait l'écrire. La décision ajoute une précision qu'aucun corpus ne portait : ce compte **n'a pas de cercle du tout** — régime distinct, jamais « un cercle avec `N = 0` ». | `modele-donnees.md` §§ 2.1, 2.7, 4.5, 9.3 · `adr/0003` |
| **O** | Conjoints du cercle | Renvoie à la formule du dossier, qui se lit comme un point fixe | **Une seule passe, en dernier, ne propagent rien** (0003) | **ADR** | **Tous les chiffres du § 9.3 du modèle ont été mesurés avec la passe unique.** Si l'algorithme implémenté n'est pas celui qui les a produits, la décision « pas de table pour le cercle » n'est plus étayée. | `modele-donnees.md` § 9.3 |
| **P** | Libellés dénormalisés | Neuf colonnes `*_label` copient le nom d'une personne hors du point de filtrage | Sept portes de sortie, sans les libellés | **Manque commun, comblé** | La règle de frontière (0002) a supprimé les jointures, donc copié les libellés, donc multiplié les endroits où une donnée personnelle réside — hors du seul normaliseur qui porte le cercle. C'est le point de toute la liste le plus susceptible de produire une vraie fuite. | `modele-donnees.md` § 0.4 · `adr/0003` |
| **Q** | Le champ `vivant` en sortie d'API | `prive` est calculé, pas stocké — mais rien ne nomme le champ de sortie | `.fiche.prive` est **conservée** au rendu (0007) | **Manque commun, comblé** | Un agent qui lit l'ADR 0007 cherche `prive` dans le modèle, ne le trouve pas, et **ajoute une colonne** — exactement ce que le § 9.2 écarte. Le champ s'appelle `vivant` et il est calculé à la sérialisation. | `modele-donnees.md` § 9.2 · `adr/0007` |
| **R** | Déclenchement de la caducité | Suit l'ADR 0004 | **0002** : par événement de `Tree` · **0004** : « à la validation, pas par une tâche de fond » | **Les deux, articulés** | Contradiction **entre deux ADR**, pas avec le modèle. Ils ne parlent pas du même déclencheur : cible disparue → événement, immédiat ; décalage de version → à la validation. Ni l'un ni l'autre n'est un balayage périodique. | `adr/0002` · `adr/0004` |
| **S** | Portée du verrou optimiste | Deux verrous, sur trois tables | Un verrou, « sur `Person.version` » | **Modèle — et c'est l'ADR 0004 qui est amendé** | La position de l'ADR **n'est pas implémentable** : un `#[ORM\Version]` seul ne voit pas passer les trois jours entre soumission et validation, l'entité étant rechargée entre les deux. Et `tree_family` et `tree_citation` sont aussi des cibles de demande. | `modele-donnees.md` § 9.6 · `adr/0004` |
| **T** | Commande `heritage:verifier-integrite` | Absente | Décidée (0002, compensation 4) | **ADR** | Manque, pas contradiction — et les deux se complètent : l'ADR décide la commande, le § 10.8 du modèle lui donne son cahier des charges, table par table. | `modele-donnees.md` §§ 0.4, 12 |
| **U** | Nombre de sources (fixtures) | 28 (§ 3.6, § 9.7) | ~14 (0006) | **Modèle — ADR 0006 amendé** | **Mesuré** : 28 `0 @S` dans `arbre-romarin-v5.ged`. Le 14 est le chiffre exact d'`arbre-romarin.ged`, la première version réparée à 165 individus. | `adr/0006` |
| **V** | `quay` dans les fixtures | Aucun `quay = 3` dans le réel (§ 3.9) | « `quay` de 0 à 3 » (0006) | **Modèle, nuancé** | **Mesuré** : 242 / 64 / 177 / **0**. L'absence de preuve directe est un fait sur l'état du travail généalogique, pas un défaut. Les fixtures en portent deux ou trois pour que le libellé soit rendu une fois, pas davantage. | `adr/0006` |
| **W** | Critère « dates incertaines » | Encode toute date non exacte en `min` ≠ `max` (§ 0.5) | « ≥ 25 % des naissances avec `an_nais` ≠ `an_nais_max` » (0006) | **Reformulé** | Le critère de l'ADR, transposé aux colonnes du modèle, serait **vrai pour les trois quarts de l'arbre sans qu'une seule vraie fourchette existe** : `1930` donne déjà `1930-01-01` / `1930-12-31`. Remplacé par un plancher sur `birth_qualifier`. | `adr/0006` |
| **X** | `SubjectKind` | Six valeurs pour l'énum, quatre annoncées pour `target_kind` | Portées : « personne, famille, citation, média » | **Les deux, distingués** | Incohérence interne au modèle. Les six valeurs valent pour l'op et la révision ; `target_kind` n'en accepte que quatre — une note ou un fait n'est jamais *la cible* d'une demande. | `modele-donnees.md` § 5.7 |
| **Y** | Vivants dans les fixtures | 78 lignes à `deceased = false`, 18 calculées vivantes (§ 3.1, § 9.2) | « Personnes vivantes ~18 » (0006) | **Modèle — ADR 0006 amendé** | **Mesuré** : 78 sans décès enregistré. Un générateur qui pose `deceased = true` partout sauf sur 18 fiches n'exerce **jamais** le repli par génération du § 9.2, c'est-à-dire le cas difficile de la règle la plus sensible du produit. Trois autres planchers (fratries, branches ouvertes, personnes sans date) étaient trop bas pour reproduire la topologie qu'ils devaient garantir. | `adr/0006` |
| **Z** | « Les scripts sont perdus » | — | Prémisse du contexte de l'ADR 0005, héritée du dossier de mission | **Fait nouveau — ADR 0005 amendé, décision inchangée** | Les cinq scripts ont été retrouvés (`docs/moteur-retrouve/`), dont **`graphe.py`, 624 lignes**, qui est le moteur. Le travail n'est plus de reconstituer un algorithme mais de **porter** du code qu'on possède. Aucune décision de l'ADR n'en dépendait. | `adr/0005` · `adr/0001` · `modele-donnees.md` § 9.9 |
| **BB** | « L'ordre horizontal suit la branche puis la fratrie » | Reprend l'affirmation : index `(branch, generation)` justifié par « l'ordre dans lequel le moteur pose les nœuds » | Affirmé en 0005 (« Principes repris de la sortie existante ») | **Erreur de fait, corrigée des deux côtés** | `graphe.py` ne lit **jamais** `branch` pour placer : l'ordre vient d'une récursion d'ascendance depuis l'ego (`larg_bloc` / `pose_bloc`), puis d'un point fixe en 12 passes pour les non-ascendants. La branche était une **déduction faite en regardant la sortie**, où les deux se ressemblent. | `adr/0005` · `adr/0003` · `modele-donnees.md` § 3.1 |
| **CC** | Forme du nœud de jonction | « le petit cercle entre un couple et ses enfants » (§ 3.2) | « nœud de jonction (petit cercle) » (0005) | **Erreur de fait, corrigée des deux côtés** | `graphe.py` pose un **losange 22 × 14** au barycentre des époux, 26 px sous leur rangée. Les deux corpus recopiaient la même description approximative, tirée de l'apparence du rendu. | `adr/0005` · `modele-donnees.md` § 3.2 |
| **DD** | Critère de non-régression du moteur | « fixture de test », sans critère | **Invariants**, boîte englobante à **± 10 %**, au motif que « comparer au pixel près figerait une implémentation qu'on n'a plus » (0005) | **ADR 0005 amendé — critère durci à l'égalité exacte** | La justification de la tolérance **est tombée avec la prémisse** (**Z**) : on a l'implémentation. Un portage d'algorithme déterministe doit reproduire les positions exactement ; tout écart est un bug de portage. Les invariants ne subsistent qu'en CI, faute d'étalon. | `adr/0005` · `adr/0006` · `modele-donnees.md` § 9.9 |
| **AA** | Tables de `Timeline` | Une table, `tml_preference`, argumentée | « peut n'avoir aucune table » (0002) | **Modèle** | Pas un désaccord mais une formulation qui se périme : une préférence de lecture doit suivre l'utilisateur d'un appareil à l'autre, ce qu'un `localStorage` ne fait pas. | `adr/0002` |
| **EE** | `vivant` dans la liste blanche | Champ calculé, nommé au § 9.2, mais absent de la liste blanche | Liste blanche sans `vivant` (0003) ; l'ADR 0007 l'exige pourtant pour `.fiche.prive` | **ADR 0003 amendé** | Le rendu ne peut pas poser `.fiche.prive` sans lui, et le test de liste blanche échouait donc sur un champ obligatoire. **Motif distinct de celui de `branch`** : voir § 2. | `adr/0003` · `modele-donnees.md` § 9.2 |
| **FF** | `field` d'un bloc date | Cite `birthMin` en exemple (§ 5.2) | — | **Le modèle contre lui-même** | Le § 5.2 énonce deux paragraphes plus bas qu'« une date incertaine est **un seul op, pas cinq** », sous peine qu'un administrateur en valide trois et fabrique une date incohérente. `birthMin` contredisait cette règle et l'entité posée depuis (`UncertainDate $birth` embarqué). Le `field` nomme le **bloc** : `birth`, `death`, `marriage`, `divorce`, `happened`, `taken`. | `modele-donnees.md` § 5.2 |
| **GG** | Fuite par champ dérivé | — | Liste blanche champ par champ (0003) | **Règle générale ajoutée aux deux** | Un champ légitime peut fuir par sa **valeur** : la largeur d'un nœud, calculée sur le texte affiché, encode la longueur des dates d'une personne masquée. Aucune liste blanche ne le voit, puisque `w` est un nombre autorisé. **Tout champ dérivé d'une donnée masquée est lui-même une donnée masquée.** | `adr/0003` · `adr/0005` · `modele-donnees.md` § 9.2 |
| **HH** | La règle de frontière elle-même | — | « Aucune relation Doctrine entre modules » (0002), vérifiée par un contrôle qui ne regarde que les annotations | **ADR 0002 amendé — la frontière passe par `Contract/`** | La règle se franchit par un `use`, pas par une annotation : **54 références inter-modules** existaient sans qu'aucune porte ne s'ouvre. La règle révisée proposée se contredisait sur son plus gros groupe ; elle est remplacée par un critère qu'un script lit sans juger d'intention. **Aucune exception nommée**, `Gedcom` compris. | `adr/0002` |
| **II** | Le seuil des deux modules : besoin ou nommage ? | — | Règle posée en **HH** : un vocabulaire monte dans `Core` dès **deux** modules | **Le seuil compte le besoin, pas le nommage** | Un port qui rend la valeur de *backing* d'une énumération au lieu de l'énumération contourne le seuil **par construction** — et une règle qu'un contournement d'une ligne suffit à ne jamais déclencher n'est pas une règle. Corollaire appliqué : `FactType` monte dans `Core\Enum\`, et il y était déjà dû avant `Timeline`. | `adr/0002` |

**Corrections mineures faites au passage, sans arbitrage** : le renvoi `DateQualifier (§ 3.2)`
pointait vers la mauvaise section du modèle (c'est § 2.8 depuis **E**) ; le décompte de tables
passe de 24 à 23 et l'ordre de création de 24 à 23 lignes (**A**) ; la table de l'ADR 0006
est réécrite dans le vocabulaire de colonnes du modèle plutôt que dans celui de
`graphe.json`, qui désignait autre chose (**W**).

---

## 2. Les arbitrages non triviaux, en détail

### A — Pourquoi le compteur disparaît, et ce qui le remplace exactement

Le mécanisme du compteur était défendable, et il était bien défendu : incrémenter un entier
dans la transaction de validation, le faire entrer dans la clé de cache, et toutes les
entrées obsolètes deviennent inatteignables d'un coup — sans balayage de clés, opération
qu'un cache sur système de fichiers ne sait pas faire de façon atomique. L'argument
d'atomicité était le bon argument.

Il tombe pour une raison qui n'est pas de performance. **Un compteur est une donnée
parallèle à la donnée** : il faut que quelqu'un pense à l'incrémenter, à chaque chemin
d'écriture, pour toujours. Le modèle en avait tiré la conséquence honnête — une liste
exhaustive de sept déclencheurs, avec l'avertissement que « une omission produit un cache
périmé silencieux, c'est-à-dire une fuite de données vers un compte qui n'aurait plus dû
voir la fiche ». Cette liste est la dette : chaque chemin d'écriture ajouté plus tard est
une occasion de l'oublier, et l'oubli ne se manifeste pas — il montre des données privées à
quelqu'un qui ne devrait plus les voir.

L'empreinte n'a pas de liste. Elle est **dérivée de la donnée**, donc elle ne peut pas être
en retard sur elle. L'expression retenue (§ 2.3 du modèle) hache la **ligne entière**
(`p::text`) de `tree_person`, `tree_family` et `tree_child` : aucune écriture ne lui échappe,
pas même un correctif SQL à chaud qui oublierait de toucher `updated_at` — ce qui est
précisément l'un des quatre chemins d'écriture que l'ADR 0005 énumère.

Et l'atomicité, qui était l'argument du compteur, est acquise autrement, plus simplement :
tant que la transaction n'est pas validée, les autres connexions lisent l'ancien état, donc
l'ancienne empreinte, donc l'ancien cache — qui est juste. Au `commit`, elles lisent le
nouvel état, donc une nouvelle clé. Il n'y a pas de fenêtre intermédiaire **parce qu'il n'y
a rien à synchroniser**.

Trois choses n'altèrent pas l'empreinte, et il fallait vérifier une par une que le cache
reste juste : un changement de `N` (le segment `{N}` est dans la clé du cercle) ; un
changement de `tree.seuil_vivant_annees` (il ne change ni la topologie ni un cercle, il
change ce que la sérialisation masque — et la sérialisation n'est pas mise en cache) ; un
portrait, une citation, un nouveau membre (aucun ne déplace un nœud ni ne modifie une
parenté). C'est ce dernier point qui interdisait d'inclure `med_media` dans l'empreinte : on
recalculerait tout le graphe à chaque photo téléversée.

`prp_revision.tree_stamp_after` devient `tree_fingerprint_after CHAR(64)`. Le besoin qu'il
servait subsiste — situer un rendu servi dans l'historique — mais il change de forme : une
empreinte ne s'ordonne pas, elle s'identifie. La question qu'on pose n'est plus « ce cache
est-il postérieur à cette révision ? » mais « l'`ETag` que ce navigateur me renvoie
correspond à quel état de l'arbre ? », qui est celle qu'on se pose réellement en
diagnostic.

### B — Pourquoi la clé par lecteur n'était pas seulement chère, mais fausse

Deux raisons, et la seconde est décisive.

La première est un rapport coût/bénéfice : un parcours complet de la structure coûte
**0,02 ms** mesuré sur les données réelles (§ 9.3 du modèle, sur les 274 personnes de
`graphe.json`). Un cache par lecteur multiplie le stockage par le nombre de comptes pour
économiser moins d'une milliseconde. À 10 ou 30 comptes, ce n'est pas un drame ; ce n'est
simplement pas un gain.

La seconde est une erreur de correction. La clé `placement.{stamp}.{lecteurId}` ne change
que lorsque `{stamp}` change, c'est-à-dire **à une modification validée de l'arbre**. Or
déposer un brouillon n'en est pas une : `prp_draft_person` et `prp_draft_link` ne sont pas
des tables `tree_*`, et aucune validation n'a eu lieu. L'auteur d'une proposition
conserverait donc son entrée de cache antérieure — et **ne verrait pas son propre nœud en
attente**, qui est exactement et uniquement ce que ce calque existe pour produire. C'est le
genre d'erreur qu'un cache rend invisible en développement, où l'on vide le cache sans y
penser, et systématique en service.

La structure à deux étages de l'ADR 0005 supprime les deux problèmes à la fois, parce
qu'elle sépare ce qui dépend du lecteur de ce qui n'en dépend pas. L'étage 1 — le placement,
calculé sans filtre de confidentialité — ne dépend pas du lecteur, **parce que le cercle de
visibilité masque des champs, pas des nœuds** (ADR 0003). C'est une dépendance entre deux
décisions qu'il faut voir : si on avait choisi de masquer les nœuds hors cercle, il y aurait
un placement par compte et l'étage 1 n'existerait pas.

Restait l'`ETag`, qui doit bien distinguer deux lecteurs dont l'un a une demande en attente.
Il le fait sans toucher à la clé de cache : il combine l'empreinte de l'étage 1 et celle des
demandes en attente du lecteur. C'est l'`ETag` qui porte le lecteur, pas le cache.

### C et D — Le rejeu automatique, et pourquoi `caduque` n'est pas `refusée`

Le modèle et l'ADR décrivaient deux produits différents à partir de la même phrase du dossier
de mission (« si la personne a changé entre-temps, on refuse et on signale le conflit plutôt
que d'écraser »).

Le modèle la lisait comme : *toute* modification concurrente condamne la demande, et
l'administrateur rebase à la main. Il l'assumait explicitement — « le conflit est détecté
même si les champs ne se recouvrent pas […] Refuser, c'est demander à un humain de
regarder » — avec un argument réel : la cohérence généalogique est transverse, le nom
qu'Opale corrige peut venir d'un acte qui change aussi la lecture de la naissance.

L'ADR 0004 la lit comme : ne rien **écraser**, ce qui n'est pas la même chose que jeter la
demande. Et il donne la règle qui distingue les deux cas — comparer **op par op** la valeur
d'origine portée par la demande à la valeur courante en base.

L'ADR l'emporte, par la règle par défaut, mais aussi parce que la position du modèle a une
conséquence qu'il n'avait pas chiffrée : le verrou optimiste porte sur **la ligne**, donc la
seconde validation part systématiquement en conflit, **même sur des champs disjoints**. Deux
membres qui proposent deux corrections différentes sur la même fiche dans la même semaine —
ce qui est le comportement qu'on cherche à encourager — auraient vu la seconde demande
condamnée à chaque fois. Le cas « champs disjoints » n'est pas le cas rare : c'est le cas
courant.

Ce qui a été retiré du modèle en échange, c'est l'action « rebaser la demande ». Ce n'est pas
une perte : le rejeu automatique traite exactement le cas que le rebasage manuel traitait
(toutes les anciennes valeurs concordent), et ce qui reste est une caducité. Deux mécanismes
pour un cas auraient été deux écrans et deux sources de bogue. Le rejeu est tracé au journal
(`demande_rejouee`) — une écriture qu'aucun humain n'a réexaminée doit se voir.

`caduque` contre `conflit` n'est pas qu'un mot. Un conflit se résout ; une caducité se
constate. Le statut est distinct de `refusee` parce que **personne n'a refusé** : le monde a
bougé. Et la conséquence pratique est le bouton « refaire cette demande », pré-rempli sur les
valeurs courantes, sans lequel une caducité est une contribution perdue — et un membre dont
la première contribution se perd n'en dépose pas de seconde.

### E et F — `Tree` était devenu un noyau, et `Proposal` écrivait chez lui

Ces deux points ne sont pas des désaccords de conception : ce sont deux frontières franchies
sans que personne ne l'ait décidé, et deux textes qui en portaient la trace.

**E.** Quatre types — `UncertainDate`, `DateQualifier`, `Sex`, `Branch` — étaient déclarés
dans `Tree` et utilisés par `Proposal`, `Media`, `Timeline` et `Gedcom`. C'est la définition
même de ce que l'ADR 0002 règle 3 interdit : « aucun autre module n'a le droit d'être *un
peu* noyau ». Ils montent dans `Core`.

Le point qui dépasse la correction, et qui a été porté dans l'ADR 0002 : **cette frontière se
franchit par un `use`, pas par une relation Doctrine.** Le contrôle en CI prévu par l'ADR ne
regardait que les annotations de relation ; `use App\Module\Tree\Enum\Sex;` dans `Proposal`
serait passé au vert. La règle 3 n'était vérifiée par rien — et c'est justement celle qui
venait d'être enfreinte. Le contrôle est élargi aux `use` inter-modules, `Core` en liste
blanche.

**F.** Les algorithmes des §§ 9.4 et 9.5 du modèle décrivaient `Proposal` insérant
directement des `tree_person`, posant le `gedcom_xref` après `flush`, insérant des
`tree_family` et des `tree_child`. Le schéma était juste ; c'était la **prose** qui était
fausse — et l'algorithme en sept passes est écrit pour être implémenté tel quel, donc c'est
la prose qui aurait été recopiée.

La réécriture par appels de services rend d'ailleurs l'algorithme **plus juste**, pas
seulement plus conforme : c'est `Tree` qui sait normaliser un couple en
`(husband_id, wife_id)` selon le sexe, `Tree` qui sait attribuer le `gedcom_xref` entre deux
`flush`, `Tree` qui connaît `uniq_tree_family_couple`. En laissant `Proposal` faire ce
travail, le modèle dupliquait des règles de `Tree` dans un autre module — le mécanisme exact
par lequel une frontière se dissout.

Corollaire traité au même endroit, et qui touche l'ADR 0005 : le modèle écrivait que
`branch`, `generation` et `rank` sont « recalculées par le moteur de placement ». Or l'ADR
0005 impose au moteur d'être **sans état et sans aucun accès à la base** — « cette contrainte
n'est pas négociable », parce que c'est elle qui le rend testable contre `graphe.json`. Un
moteur qui écrit trois colonnes a un accès à la base. Ces colonnes sont donc persistées par
un service de `Tree` ; le moteur les **consomme** en entrée.

### G — Une clé de réglage, deux corpus, et pourquoi ce n'est pas cosmétique

`core_settings['cercle.generations']` contre
`core_setting['cercle.generations_ascendantes']` : deux écarts dans une seule référence.

Le nom de table va au modèle : **toutes** les tables du schéma sont au singulier
(`core_user`, `tree_person`, `med_media`), et le dépôt de référence aussi. `core_settings`
aurait été la seule au pluriel de tout le schéma.

Le nom de clé va à l'ADR, par la règle par défaut. C'est le point où la recommandation de la
liste de travail et la règle d'arbitrage coïncidaient, et où l'auteur du modèle proposait
lui-même la concession : `cercle.generations` est plus court, et « ascendantes » n'ajoute
rien puisque le cercle ne remonte que vers le haut.

Ce n'est pas cosmétique parce que c'est une **chaîne littérale** qui figure dans une
migration, dans un service de réglages, dans un écran d'administration et en base. Un écart
découvert après la première migration se corrige par une migration de données.

### H — `Member` contre `Account`, le point le plus discutable de la liste

C'est le seul arbitrage où le dossier de mission écrit un nom et où ce n'est pas celui qu'on
retient. La lettre du dossier (§ 2) est : « `Membership\Account.personId` + `personLabel` ».

Deux lectures sont possibles, et c'est ce qui tranche : le dossier cite cette classe **comme
exemple du motif de référence inter-module**, pas comme un choix de nommage argumenté. Il
illustre « identifiant + libellé dénormalisé, jamais un `ManyToOne` », et l'illustration
reste exacte avec `Member`.

L'argument de fond est que `Core\User` **est déjà le compte** : il porte le courriel, le mot
de passe, les rôles, et implémente `UserInterface`. Appeler `Account` l'entité de
`Membership` crée deux « comptes » dans deux modules, et garantit qu'un agent ira chercher le
mot de passe dans `mbr_account`. Ce que cette table apporte est le **rattachement** compte ↔
personne — d'où `Member`.

L'ADR 0002 a été amendé pour porter la correspondance en toutes lettres, afin que personne ne
lise `Account` dans un corpus et `Member` dans l'autre sans comprendre qu'il s'agit de la
même chose.

### M — Pourquoi `branch` est ajoutée à l'ADR plutôt que retirée du modèle

La liste de travail recommandait de retirer `branch` de la liste blanche du modèle, par
discipline : l'ADR 0003 impose un test par **liste blanche**, et une liste blanche qui existe
en deux versions dans deux documents n'en est plus une. L'argument est juste, et c'est
l'argument qui a été suivi — mais dans l'autre sens, celui que la liste de travail
elle-même désignait comme l'issue correcte : « si `branch` est nécessaire au rendu, il faut
l'ajouter **à l'ADR 0003**, pas au modèle seul ».

Elle l'est. L'ADR 0005 pose que l'ordre horizontal du placement **suit la branche** puis la
fratrie. `branch` et `generation` sont donc les deux coordonnées du graphe partagé, servi à
tout le monde : un nœud livré sans sa branche n'est ni posable ni colorable par un canevas
qui reçoit déjà sa position. Et la fuite marginale est nulle — la position **révèle déjà** le
quart d'ascendance, ce que le risque résiduel de l'ADR 0003 accepte explicitement.

Retirer `branch` aurait donc produit la situation que la liste blanche existe pour empêcher :
un test qui échoue, quelqu'un qui ajoute le champ à la liste du test sans revenir à l'ADR, et
une liste de référence qui cesse d'être la référence.

### N — Ce qu'un compte en attente a le droit de lire

Le modèle disait : rien. L'ADR disait : les personnes décédées. La divergence portait
entièrement sur un mot — *un compte `en_attente` est-il « connecté » au sens de la règle
« une personne décédée est visible de tout membre connecté » ?* — et il fallait y répondre
explicitement, parce que le code qui l'implémente ne pose pas la question.

L'ADR l'emportait par la règle par défaut, et **le commanditaire a depuis tranché dans le
même sens** : voir § 3, qui fait autorité sur ce paragraphe. Sa position se défend seule :
256 fiches sur 274 sont des personnes décédées, l'état civil de plus de 75 ans est public,
et un cousin qui attend son activation peut déjà explorer l'arbre historique — ce qui est
l'essentiel du contenu. Aucune personne vivante ne sort, dans aucune des portes de sortie :
le garde-fou réel du produit, celui du § 6.1 du dossier, porte sur l'énumération des
**vivants**, et il n'est pas entamé.

**Une nuance a été ajoutée par la décision, et elle corrige ce paragraphe.** Il était tentant
d'écrire — et une première rédaction de ce journal l'écrivait — que les deux positions
étaient « le même code, un cercle vide ». **Elles ne le sont pas.** Un compte en attente
n'a pas de cercle *du tout* : c'est un régime distinct, pas un cercle dont le résultat serait
vide, et surtout pas « un cercle avec `N = 0` » — qui contiendrait encore `u` et son
conjoint, donc laisserait sortir une fiche vivante. Le § 3 détaille pourquoi la confusion est
naturelle et ce qu'elle coûterait.

### P — La huitième porte de sortie, qu'aucun des deux corpus n'avait comptée

L'ADR 0003 énumère sept portes par lesquelles les données d'une personne sortent de l'API, et
fonde tout son dispositif sur le fait qu'elles passent toutes par un point unique, le
normaliseur : « on y écrit le filtre une fois, on l'audite une fois ».

Il y en a une huitième, et elle ne passe pas par là. La règle de frontière de l'ADR 0002
supprime les jointures inter-modules et les remplace par un identifiant plus un libellé
recopié. Résultat : **neuf colonnes `*_label`, sur six tables de trois modules, stockent le
nom d'une personne potentiellement vivante**. Un administrateur ouvre la file des demandes,
l'API sérialise `prp_change_request` avec son `target_label` — et le nom sort, sans que rien
dans le code n'ait eu l'occasion d'appliquer le cercle.

Ce n'est le défaut ni de l'un ni de l'autre corpus : c'est la **contrepartie structurelle**
de la modularité, et elle est assumée. Mais elle n'était écrite nulle part, et une règle non
écrite sur la fonction la plus sensible du produit est une règle qui sera enfreinte. Elle
l'est maintenant, dans les deux corpus : *toute colonne `*_label` désignant une personne est
filtrée comme la personne elle-même*, et les tests de liste blanche couvrent les réponses de
`Proposal`, `Membership` et `Media`.

En pratique, ces files sont réservées à l'auteur et aux administrateurs, donc le risque est
contenu. Mais « en pratique » n'est pas une garantie, et l'ADR 0003 exige justement un test
par porte.

### S — Un verrou dans l'ADR, deux dans le modèle, et pourquoi c'est l'ADR qui cède

L'ADR 0004 écrivait « verrou optimiste sur `Person.version` ». C'est la seule formulation de
tout le corpus qui soit **impossible à implémenter telle quelle**, et le modèle l'avait vu.

Un `#[ORM\Version]` protège deux transactions **concurrentes** : Doctrine ajoute
`AND version = ?` à l'`UPDATE` et lève si zéro ligne est touchée. Mais une demande créée
lundi et validée vendredi ne tient aucun objet Doctrine entre les deux : au moment de
valider, l'entité est rechargée fraîche, avec la version actuelle. Le verrou ne remarque
**rien** et écrase tranquillement le travail de mardi — c'est-à-dire exactement le cas que le
§ 6.3 du dossier de mission demande d'empêcher.

D'où le second verrou, `prp_change_op.base_version` : la version de la ligne telle que
l'auteur l'a vue au moment de déposer sa demande. Il est porté par l'**op** et non par la
demande, parce qu'une demande touche plusieurs lignes — une création touche une personne, une
famille et deux filiations — et qu'une colonne unique sur l'en-tête ne saurait pas dire
**laquelle** a bougé.

Ce second verrou est aussi ce qui rend possible la règle de rejeu de l'arbitrage **C** : sans
`old_value` et `base_version` stockés par op, on ne saurait rien dire de plus fin que « la
version a changé ».

Une limite reste, et elle est écrite dans le modèle plutôt que découverte en service : le
verrou protège **la ligne, pas la fiche au sens de l'écran**. Modifier `tree_child` ou
`tree_citation` n'incrémente pas `tree_person.version` ; une demande qui renomme une fiche et
une demande qui y ajoute une source ne se verront jamais. C'est acceptable — ce sont deux
modifications réellement indépendantes — mais c'est désormais une décision écrite. Le geste,
si on change d'avis un jour : incrémenter `tree_person.version` depuis le service qui touche
une ligne fille, surtout pas ajouter un second mécanisme.

### U, V, W, Y — Le cahier des charges des fixtures, recompté sur la donnée

Ce ne sont pas des désaccords de conception. Ce sont des nombres, dans un cahier des charges
que l'ADR 0006 déclare lui-même **vérifié par un test**. Un nombre faux y fait échouer le
test — ou, pire, le fait passer sur des fixtures qui ne prouvent rien.

Tout a été recompté sur `arbre-romarin-v5.ged` et `graphe.json`, et c'est à ce titre que le
modèle l'emporte : il s'appuie sur une mesure que l'ADR ignorait.

- **U — « ~14 sources » contre 28.** Le 14 est le chiffre exact d'`arbre-romarin.ged`, la
  première version réparée, à 165 individus. Des fixtures deux fois moins riches en sources
  que le réel ne testeraient jamais à l'échelle l'écran « d'où vient ce que je sais » ni la
  requête de fiabilité par source.
- **V — « `quay` de 0 à 3 ».** Mesuré : 242 / 64 / 177 / **0**. L'absence totale de `quay = 3`
  n'est pas une lacune de données, c'est le résultat de la requalification décrite dans le
  `README.md` du chantier : après correction, plus rien dans l'arbre ne repose sur une preuve
  directe. Conséquence faible, mais c'est un fait qui mérite d'être **su** plutôt que
  découvert.
- **W — « ≥ 25 % des naissances avec `an_nais` ≠ `an_nais_max` ».** C'est le piège le plus
  discret des quatre, parce qu'il se transpose mal. Dans le vocabulaire de `graphe.json`,
  `an_nais` et `an_nais_max` sont des **années**, et `1930` donne `an_nais = an_nais_max`.
  Dans l'encodage du § 0.5 du modèle, `1930` donne `birth_min = 1930-01-01` et
  `birth_max = 1930-12-31` : `min ≠ max`. Le critère, recopié tel quel dans les colonnes du
  modèle, serait donc **vrai pour les trois quarts des fiches sans qu'une seule vraie
  fourchette existe**, et le test passerait au vert sur des fixtures dépourvues de ce qu'il
  devait garantir. Il est remplacé par un plancher sur `birth_qualifier` : ≥ 10 en `entre`,
  ≥ 20 en `environ` (réel : 11 et 23).
- **Y — « Personnes vivantes ~18 ».** Juste, et insuffisant. Le vrai arbre porte **78 lignes
  à `deceased = false`**, dont 18 seulement sont **calculées** vivantes. Un générateur qui
  pose `deceased = true` partout sauf sur 18 fiches produirait des fixtures où le repli par
  génération du § 9.2 — la partie difficile de la règle la plus sensible du produit, celle
  qui empêche de masquer 27 fiches d'ancêtres du XVIII<sup>e</sup> siècle — n'est **jamais**
  exercé. Trois autres planchers étaient trop bas pour la même raison : fratries (1 à 9, six
  familles à ≥ 5, et non « 1 à 8 »), branches ouvertes (100 personnes sans parents connus, et
  non « ≥ 40 »), personnes sans aucune date de naissance (50, et non « ≥ 6 »).

S'ajoute une correction de vocabulaire qui a des conséquences : le dossier de mission et
l'ADR 0006 écrivent « 84 **faits** appuyés sur un acte ». Le comptage le tranche — 84 est le
nombre de **fiches** dont la meilleure citation atteint `QUAY ≥ 2`
(`count(*) FROM tree_person WHERE best_quay >= 2`), pas un nombre de citations ni de faits :
les citations à `quay ≥ 2` sont 177. La commande d'import doit vérifier le bon des deux.

### Z, BB, CC, DD — Le moteur n'était pas perdu, et trois choses en découlent

**Ce n'est pas une divergence entre corpus** — la distinction compte pour qui relira. C'est un
**fait nouveau** qui invalide une prémisse partagée par les deux textes et par le dossier de
mission, et qui entraîne un amendement d'ADR.

**Z — le fait.** L'ADR 0005 s'ouvrait sur « les scripts qui produisaient le viewer sont
perdus, il ne reste que leur sortie ». Les cinq scripts ont été retrouvés dans un scratchpad
jamais purgé et rangés dans `docs/moteur-retrouve/`. Le moteur est **`graphe.py`,
624 lignes**, écrit à la main, sans Graphviz ni aucune dépendance.

Il faut dire pourquoi l'erreur était plausible, sinon elle se refera : `build_viewer.py`, le
script qu'on aurait naturellement ouvert pour y chercher l'algorithme, **ne place rien**. Il
lit un SVG déjà produit par Graphviz — `arbre-complet.svg`, **229 nœuds** — et n'y greffe que
l'interactivité. À ce stade du chantier, le placement était délégué à `dot`. Les **377 nœuds**
de `graphe.json` viennent d'une génération ultérieure, celle de `graphe.py`. Chercher au bon
endroit et n'y trouver que du Graphviz mène raisonnablement à conclure que le code a disparu.

**Ce que la décision devient.** Le travail n'est plus de **reconstituer** un algorithme à
partir de sa sortie, mais de **porter 624 lignes de Python en PHP**. Aucune décision de
l'ADR 0005 ne bouge pour autant — réécriture côté serveur, service sans état, deux étages de
cache, invalidation par empreinte, insertion locale des nœuds en attente : aucune ne tenait à
l'indisponibilité du code, toutes tiennent mot pour mot.

**DD — le critère d'acceptation, en revanche, ne tient plus.** L'ADR comparait des
**invariants** — mêmes générations aux mêmes rangs, ordre des fratries préservé, boîte
englobante à **± 10 %** — et justifiait explicitement cette tolérance ainsi : « comparer les
coordonnées au pixel près figerait une implémentation qu'on n'a plus, au lieu de ses
propriétés ». La justification est tombée avec la prémisse : **on l'a.** Un portage
d'algorithme déterministe dont on possède le code doit reproduire les positions
**exactement** ; tout écart est un bug de portage, pas une variation tolérable.

Le critère devient donc l'égalité des 377 nœuds (`x`, `y`, `w`, `h` au centième — la
tolérance ne couvre que la représentation des flottants), des 377 arêtes et de la boîte
englobante. Ce n'est pas du zèle : deux pièges de portage précis le rendent utile. La
**division flottante** du barycentre (`sum / len`), et surtout **l'ordre d'itération des
tableaux associatifs**, que PHP et Python ne traitent pas de la même façon dès qu'on
supprime une clé — or le point fixe en 12 passes et le décrochage des chevauchements rangée
par rangée sont sensibles à cet ordre. Un test à ± 10 % ne les aurait jamais vus.

Les invariants ne disparaissent pas : ils deviennent le filet de la **CI**, qui n'a pas
d'étalon puisque `graphe.json` n'est pas versionné (ADR 0006). Couverture plus faible, et
elle est assumée à voix haute dans les deux ADR : **la CI peut passer au vert sur un portage
faux**, donc le test exact doit être lancé au moins une fois avant la mise en service et son
résultat consigné.

**BB et CC — deux erreurs de fait sur l'algorithme, que la lecture du code révèle.** Les deux
corpus décrivaient le moteur en le **déduisant de sa sortie**, et se trompaient de la même
façon aux mêmes endroits :

- **BB — « l'ordre horizontal suit la branche (`pp`/`pm`/`mp`/`mm`/`tr`) puis la fratrie »**
  (ADR 0005), repris par le modèle pour justifier l'index `(branch, generation)` : « c'est
  l'ordre dans lequel il pose les nœuds ». **Faux.** `graphe.py` ne lit jamais `branch` pour
  placer. L'ordre vient d'une récursion d'ascendance depuis l'ego — `larg_bloc` mesure les
  sous-arbres de bas en haut, `pose_bloc` descend et recentre chaque fratrie sous le
  barycentre du couple parental — puis d'un point fixe en 12 passes pour les non-ascendants.
  La branche est calculée, mais pour la couleur et pour la chronologie. La confusion
  s'explique : dans la sortie, l'ordre par récursion d'ascendance **ressemble** à un ordre
  par branche, puisque les deux dérivent du même chemin vers la racine.
  Cette correction **atteint l'arbitrage M** : la révision de l'ADR 0003 s'appuyait d'abord
  sur l'idée que `branch` était une coordonnée du placement. Elle ne l'est pas. La conclusion
  tient — `branch` reste dans la liste blanche — mais pour les deux motifs réécrits depuis :
  le canevas colore les nœuds par quart d'ascendance, et la branche **n'apprend rien** qui ne
  se déduise déjà des arêtes qu'on envoie de toute façon. Un motif faux qui soutient une
  conclusion juste est une dette : il tombe au premier lecteur attentif, et emporte la
  conclusion avec lui.
- **CC — « chaque famille est un nœud de jonction (petit cercle) ».** C'est un **losange
  22 × 14**, posé au barycentre des époux, 26 px sous leur rangée. Anecdotique au premier
  regard, sauf que le viewer se porte à l'identique et qu'un cercle à la place d'un losange
  est un écart visible sur 103 nœuds. Au passage, le code confirme une chose que le modèle
  avait juste : les rangs sont **entrelacés**, personnes aux rangs pairs et familles aux
  rangs impairs — c'est l'origine du `rank = 2 × generation + 1` de `tree_family`.

**Ce qu'il ne faut pas conclure**, et qui est écrit dans l'ADR parce que quelqu'un le
conclura sinon : on n'exécute pas ces scripts en production, et on ne fait pas du portage une
traduction ligne à ligne aveugle. Le portage doit ajouter deux choses que `graphe.py` ne fait
pas — le graphe pour un lecteur donné avec ses nœuds en attente insérés localement, et la
disparition de la signalétique `.ar.long` — et `graphe.py` reste la source de vérité de
l'algorithme pour tout le reste : en cas d'écart entre le résumé de l'ADR et les 624 lignes,
**c'est le script qui a raison**.

---

### EE, FF, GG — Ce que l'écriture du contrat d'API a fait apparaître

Trois points remontés après les trente premiers, par la tâche qui a écrit les 54 routes. Ils
méritent d'être groupés, parce qu'ils illustrent la même chose : **une conception ne se
vérifie qu'en essayant de s'en servir.** Les trente premiers arbitrages venaient de la
comparaison de deux textes ; ceux-ci viennent d'un troisième texte qui devait les consommer.

**EE — `vivant` entre dans la liste blanche, et surtout pas pour la raison de `branch`.**

Le geste ressemble à l'arbitrage **M** : un attribut nécessaire au dessin d'un nœud, qu'on ne
peut pas ne pas envoyer. Le recopier mécaniquement aurait été une faute, parce que l'argument
de `M` **ne se transpose pas**. Pour `branch`, on a écrit qu'elle « ne s'apprend pas, elle se
déduit des arêtes qu'on envoie déjà » : c'est vrai, la branche est déterminée par le chemin
vers la racine, et ce chemin est visible. Pour `vivant`, **c'est faux** — aucune arête ne dit
qu'une personne est vivante, et c'est précisément l'information la plus sensible du produit.

L'ajout se justifie autrement, en trois temps :

1. **Le rendu l'affiche déjà en toutes lettres.** Le nœud d'une personne masquée porte, à la
   place de sa ligne de dates, la constante « vivant·e » — c'est ce que fait le moteur
   d'origine (`graphe.py`, `ligne_dates()`) et ce que le portage reproduit. Le mot est sur le
   nœud, envoyé à tout le monde.
2. **Le masquage le dit de lui-même**, et l'ADR 0003 accepte explicitement ce risque
   résiduel : une fiche muette est une fiche de personne vivante.
3. **Ne pas l'envoyer serait pire.** Le client devrait inférer l'état vivant de l'*absence*
   de champs — l'inférence côté client que le § 6.2 du dossier interdit, et qui casserait au
   premier champ ajouté.

Le gain d'information pour un lecteur hors cercle est donc **nul**. Ce qui vaut d'être retenu
dépasse ce champ : **deux ajouts à une liste blanche peuvent être tous deux justes sans
partager un seul argument.** Un motif qui marche pour un champ n'autorise pas le suivant, et
c'est exactement ainsi qu'une liste blanche se desserre — non par une décision, mais par une
analogie.

**FF — le `field` d'un bloc date.**

Pas une divergence entre corpus : **le modèle se contredisait lui-même**, à deux paragraphes
d'intervalle. Le § 5.2 donnait `birthMin` en exemple de `field`, puis énonçait qu'« une date
incertaine est un seul op, pas cinq », au motif qu'un administrateur pourrait sinon en valider
trois et fabriquer une date incohérente — l'aplatissement que le § 9.1 interdit. Les entités
posées depuis tranchent dans le même sens : un `UncertainDate $birth` embarqué, pas cinq
propriétés scalaires. Le `field` nomme donc le **bloc** — `birth`, `death`, `marriage`,
`divorce`, `happened`, `taken` — jamais une de ses cinq colonnes.

C'est une contradiction interne, et elle aurait survécu à toute relecture qui compare les
documents deux à deux : les deux phrases sont dans la même section, et chacune est juste
isolément. Il fallait quelqu'un qui écrive la charge utile du diff pour buter dessus.

**GG — la fuite par champ dérivé, que la liste blanche ne peut pas voir.**

C'est le point le plus intéressant des trois, et une catégorie de fuite qu'aucun des quatre
documents n'avait nommée. Le dispositif de l'ADR 0003 raisonne **champ par champ** : on
énumère ce qui sort, on teste la réponse contre cette liste. Ce dispositif est aveugle à un
champ *autorisé* dont la **valeur** est calculée sur un champ *interdit*.

Le cas concret : la largeur d'un nœud du graphe se calcule sur le texte affiché. Calculée sur
les vraies dates, **la largeur de la boîte encode la longueur des dates** d'une personne
masquée. `w` est un nombre parfaitement légitime, il figure dans toutes les listes blanches,
et la date, elle, n'est pas envoyée : le test passe au vert et l'information sort quand même.

D'où la règle, écrite dans les deux corpus : **tout champ dérivé d'une donnée masquée est
lui-même une donnée masquée.**

Deux choses rendent ce point heureux plutôt qu'inquiétant.

D'abord, **le moteur d'origine traitait déjà le cas** : sa ligne de dates vaut la constante
« vivant·e » pour une personne masquée, donc la largeur ne dépend que du nom, qu'on envoie de
toute façon. Le portage n'a rien à inventer — il doit conserver cette constante, et la
conserver est à la fois ce qui ferme la fuite **et** ce qui satisfait le critère d'égalité
exacte de l'arbitrage **DD**. Les deux exigences pointent dans le même sens, ce qui est assez
rare pour être noté : ici, reproduire fidèlement *est* la mesure de sécurité.

Ensuite, **l'état vivant est une propriété de la personne, pas du lecteur**. La largeur reste
donc identique pour tout le monde, le placement de l'étage 1 reste partagé, et le cache à
deux étages survit intact. Si la largeur avait dû dépendre du cercle du lecteur, il aurait
fallu un placement par compte — c'est-à-dire précisément ce que l'arbitrage **B** a écarté.
La fuite aurait alors coûté bien plus qu'une constante.

Les autres dérivés à surveiller par la même règle, sans prétendre les énumérer tous : un
compte de caractères, un ordre de tri, un nombre de résultats, une durée de réponse.

---

### HH — La règle de frontière, et pourquoi elle n'était vérifiée par rien

C'est le seul arbitrage de ce journal qui touche **la règle d'architecture elle-même** plutôt
que son application. Il est arrivé en dernier, ce qui n'est pas un hasard : une règle ne se
révèle défaillante qu'une fois qu'il y a assez de code pour l'enfreindre.

**Le constat.** L'ADR 0002 prévoyait un contrôle en CI vérifiant « qu'aucune annotation de
relation Doctrine ne cite une classe d'un autre `Module\` ». Or la frontière ne se franchit
presque jamais par une annotation : elle se franchit par un `use`. Un script qui regarde les
`use` a compté **54 références inter-modules** — aucune dans un `Entity/`, un `Repository/`
ou un `ValueObject/`, toutes dans des `Service/`, `Port/` et `Dto/`.

| Couple | Références | Nature |
|---|---:|---|
| `Gedcom` → `Tree` | 21 | 11 énumérations, 7 **entités**, 2 services internes, 1 objet valeur |
| `Media` → `Proposal` | 21 | 7 **entités**, 7 énumérations, 1 **repository**, 5 services, 1 exception |
| `Tree` → `Proposal` | 5 | 5 interfaces de port et leurs objets de transfert |
| `Tree` → `Membership` | 4 | 4 interfaces de port |
| `Tree` → `Media` | 3 | 2 interfaces, 1 énumération |

**Première observation, avant tout arbitrage : le compte ne se reproduit pas.** Un premier
recensement en donnait 46, celui-ci 54, et le détail ne coïncide pas non plus — le couple
`Tree → Proposal` manquait du premier. Ce n'est pas un détail comptable : ce nombre va servir
de **seuil à un test permanent**, et un test qui gèle un chiffre que deux scripts ne
retrouvent pas est un test qu'on désactive au premier désaccord. Le script retenu doit dire
ce qu'il compte — des `use` et non des fichiers, toutes les couches y compris `Dto/` — et son
résultat doit être rejouable à la main. C'est consigné dans l'ADR.

#### Pourquoi la règle proposée ne pouvait pas trancher

La règle soumise tenait en cinq points, dont deux se contredisent sur le plus gros groupe :

- *« Un module peut référencer les **contrats** d'un autre quand il les implémente. »*
- *« Un module ne peut **jamais** référencer les `Entity/`, `Repository/` ni les services
  internes d'un autre. »*

Les 21 références de `Media → Proposal` **relèvent des deux à la fois**. `Media` implémente
bien un contrat de `Proposal` — `ApplicateurDOperation`, le point de greffe des natures d'op.
Et il référence bien `Proposal\Entity\ChangeRequest`, `Proposal\Entity\ChangeRequestOp` et
`Proposal\Repository\ChangeRequestOpRepository`. La première règle l'autorise, la seconde
l'interdit, et rien ne dit laquelle gagne.

**La contradiction n'est pas dans les règles, elle est dans le contrat.** Voici la signature :

```php
ApplicateurDOperation::appliquer(
    ChangeRequest $demande, array $ops, Lecteur $lecteur, string $empreinte
): array;
```

Quiconque l'implémente **doit** référencer les entités de `Proposal` — l'interface ne lui
laisse pas le choix. Ce n'est pas une indiscipline de `Media` : c'est une signature qui
oblige. Et le même module, sous la même plume, porte la contre-épreuve :

```php
PortArbre::creerPersonne(PersonneAcreer $fiche): LigneCreee;
```

Celle-là ne nomme que des objets de transfert de `Proposal`. `Tree` l'implémente — c'est
`Tree\Service\PortArbreDoctrine`, les 5 références du couple `Tree → Proposal` — **sans voir
une seule entité**. Deux contrats déclarés par le même module ; l'un fait passer sept entités
la frontière, l'autre zéro.

D'où la qualification qui manquait, et qui est le cœur de cet arbitrage :

> **Un contrat dont la signature nomme une `Entity/` ou un `Repository/` de son déclarant
> n'est pas un contrat.**

Elle a une vertu que la discipline n'a pas : elle **supprime** les références au lieu de les
excuser. Corriger la signature d'`ApplicateurDOperation` fait tomber d'un coup les huit
références aux entités et au repository, sans qu'on ait rien demandé à personne.

#### Où passe la frontière, concrètement

La règle 4 de l'ADR 0002 disait déjà que « la surface publique d'un module est l'ensemble de
ses services déclarés comme tels ». Elle n'était vérifiable par rien, parce que **rien dans
le code ne disait lesquels**. La révision la rend lisible par un chemin :

> La surface publique d'un module est son dossier **`<Module>/Contract/`**. Un `use`
> inter-modules n'est licite que vers `Core\…` ou vers `<Autre>\Contract\…`.

Ce n'est pas une invention : `Core\Contract\` existait déjà — `Lecteur`,
`CalqueEnAttenteInterface`, `AnnuaireDesMembresInterface` — et servait de modèle sans que la
convention ait été énoncée. Les six autres modules l'adoptent.

Vérification faite : `Core` ne référence **aucun** module. La règle 3 tenait déjà.

#### Les 54, une par une, contre la règle révisée

| Couple | Devient | Geste |
|---|---|---|
| `Tree → Membership` (4) | **licites** | déplacer les ports de `Membership\Service\Port\` vers `Membership\Contract\` |
| `Tree → Proposal` (5) | **licites** | idem côté `Proposal` |
| `Tree → Media` (3) | **licites** | idem côté `Media` ; l'énumération `MediaSubject` figure dans la signature, elle suit |
| `Media → Proposal` (21) | **13 licites, 8 à supprimer** | corriger la signature d'`ApplicateurDOperation` : les 7 entités et le repository disparaissent d'eux-mêmes |
| `Gedcom → Tree` (21) | **14 licites, 7 à déplacer** | les 11 énumérations montent dans `Core\Enum\` (voir **II** — c'est du **vocabulaire**, pas un contrat) et les 3 services publics passent en `Tree\Contract\` ; les 7 références aux entités sont concentrées **dans un seul fichier** |

**54 références, 15 vraies infractions, et elles tiennent dans deux gestes** : une signature à
changer et une classe à déplacer. C'est le résultat qui justifie d'avoir regardé les 54 plutôt
que de raisonner sur les quatre groupes : trois des cinq couples sont entièrement légitimes et
n'attendaient qu'un dossier, et les infractions réelles ne sont ni nombreuses ni dispersées.

#### Pourquoi `Gedcom` n'obtient pas l'exception qu'on lui proposait

C'était le point le plus contestable de la règle soumise, et son auteur le disait. Il est
écarté, pour trois raisons dans cet ordre.

**Ce serait le contraire de ce qu'on a demandé à `Proposal`, il y a quelques heures.**
L'arbitrage **F** de ce journal a retiré du modèle de données une prose où `Proposal`
insérait directement des `tree_person`, et l'a remplacée par des appels de service — et
l'implémentation a suivi : `PortArbre` déclaré par `Proposal`, implémenté par `Tree`. Valider
une proposition écrit dans `Tree` exactement comme un import y écrit. Accorder à l'un ce
qu'on a refusé à l'autre laisse deux précédents opposés pour le même geste, et le prochain
adaptateur invoquera celui qui l'arrange. **Une exception nommée dans un ADR est permanente
par construction** ; c'est ce qui la distingue d'une dette, qui a une date et un propriétaire.

**Le « par définition » ne tient pas.** *Un adaptateur d'import construit par définition les
entités du module qu'il peuple* — non : il construit des **données**. Qu'il construise les
entités est un choix de conception, pas une nécessité. `Gedcom` produit déjà ses propres
objets de transfert — `PersonneLue`, `FamilleLue`, `CitationLue`, `NoteLue`, `FaitLu`,
`SourceLue` — qui sont exactement ce qu'un port d'import prendrait en entrée. Et aucun
argument d'impossibilité ne soutient l'exception : à 274 personnes il n'y a pas d'obstacle de
volume, et l'idempotence sur `gedcom_xref` comme les trois passes d'insertion sont la
compétence de `Tree`, pas celle du lecteur de GEDCOM.

**Le modèle de données l'avait déjà tranché**, et personne ne s'en était aperçu. Son § 8.3 :
« L'import **écrit** dans `Tree`, `Media` et `Core`, mais il le fait **par appel de service**,
pas par relation. » La règle existait ; il lui manquait d'être vérifiée.

Le sens de la dépendance s'en déduit, et il évite le cycle : `Tree` déclare
`Tree\Contract\PortImportArbre` avec ses propres objets de transfert, `Gedcom` convertit ses
`…Lue` et appelle. `Gedcom` référence `Tree\Contract\` ; `Tree` ne référence jamais `Gedcom`.

**Ce que cela coûte, dit franchement.** Une classe change de module. Les sept références aux
entités de `Tree` sont concentrées dans **un seul fichier**, `Gedcom\Service\EcrivainTree` —
632 lignes dont le nom annonce déjà où elles devraient vivre. Un service qui écrit `Tree` et
le proclame, rangé ailleurs que dans `Tree`, n'est pas une exception à documenter : c'est un
fichier à déplacer. Si le déplacement doit attendre, qu'il soit inscrit comme **dette datée**
et non comme exception — les deux se ressemblent le jour où on les écrit, et plus du tout
trois mois après.

#### Le chiffre attendu, et la dette qui explique pourquoi ce n'est pas zéro

Les cinq classes dont dépendait le compte ont été ouvertes, parce que le seuil d'un test
permanent ne se devine pas. Trois sont des **interfaces** — `ApplicateurDOperation`,
`ApplicateurDeDemande`, `ConstructeurDeDemande` — et se régularisent par un déplacement de
dossier. **Deux ne le sont pas**, et elles changent la réponse :

- `Proposal\Service\EcritureDeRevision` est une `final readonly class`. `Media` appelle donc
  un **service interne concret**. Aucun dossier ne rend cela licite : il faut en extraire une
  interface, ou l'accepter comme violation.
- `Proposal\Exception\DonneesInvalides` est une exception partagée par deux modules. Par le
  seuil du **vocabulaire** (deux modules suffisent), sa place est dans `Core\Exception\`, où
  `ValidationException` vit déjà — pas dans `Proposal\Contract\`.

De même côté `Gedcom` : `Tree\Service\CalculAscendance`, `Tree\Service\NormalisateurNom` et
`Tree\ValueObject\FamillePlacable` sont trois classes concrètes, appelées depuis
`AnalyseurGedcom`. Elles ne se régularisent pas par un dossier non plus.

D'où l'arithmétique, marche par marche :

| État | Références illicites |
|---|---:|
| Au recensement (le compte bouge, voir plus bas) | **54**, puis 55 une heure après |
| + signature d'`ApplicateurDOperation` corrigée | 46 |
| + `Contract/` déclarés par `Membership`, `Proposal` et `Media` (les 12 références de `Tree`) | 34 |
| + énumérations et interfaces restantes de `Proposal` en `Contract/`, `DonneesInvalides` en `Core` | 22 |
| + les 11 énumérations de `Tree` dans `Core\Enum\` (**II**) | **11** |
| **Cible après les gestes mécaniques** | **11** |
| — dont la dette `Gedcom → Tree` | 10 |
| — dont `EcritureDeRevision`, une interface à extraire | 1 |
| Après levée de la dette | **1**, puis **0** |

**Le seuil est donc 11, et non 34**, à condition que le second geste soit fait complètement.
La nuance vaut d'être dite parce qu'elle décide du travail : les trois couples `Tree → *` ne
sont que la moitié visible du geste `Contract/`. Les déclarer pour `Membership`, `Proposal` et
`Media` régularise 12 références ; les déclarer aussi pour le reste de `Proposal` et pour
`Tree` en régularise 23 de plus. Ce sont des **déplacements de fichiers, sans une ligne de
logique touchée** — il n'y a pas de raison de s'arrêter à la moitié.

Un point de routage, parce qu'il enverrait son auteur au mauvais endroit : **les dossiers
`Contract/` sont le travail des modules qui déclarent, pas de celui qui référence.** Les 12
références partent de `Tree`, mais les fichiers à déplacer sont dans `Membership`, `Proposal`
et `Media`. `Tree` n'y change que des lignes `use`.

#### Le seuil ne doit pas être un nombre

Découvert en rejouant le recensement une heure après le premier : **le compte était passé de
54 à 55**, une référence de plus dans `Gedcom → Tree`
(`Gedcom\Dto\ResultatAnalyse` → `Tree\ValueObject\PlacementLogique`). Rien d'anormal — trois
adaptateurs s'écrivent en ce moment. Mais cela règle une question qu'on allait trancher de
travers.

L'écart 46 / 54 n'était donc pas seulement méthodologique : **le code bouge pendant qu'on le
compte.** Un seuil absolu figé sur un tel chiffre est faux le lendemain, et son auteur passera
son temps à le relever d'un cran — ce qui est la mort lente de ce genre de contrôle.

Et un seuil par nombre a un défaut plus grave, indépendant du mouvement : **il est
compensable.** Quelqu'un ajoute une violation dans un module, en retire une dans un autre, le
total ne bouge pas, le test reste vert. Le contrôle est alors exactement aveugle à ce qu'il
est censé empêcher : l'apparition d'une frontière franchie.

D'où la forme retenue :

> **Le contrôle échoue sur la règle, pas sur un compte.** Toute référence inter-modules qui
> ne vise ni `Core\…` ni `<Autre>\Contract\…` fait échouer le test, **sauf** si elle figure
> dans une **liste d'exemptions nommées**, fichier par fichier et cible par cible.

La dette y entre comme une ligne explicite — `Gedcom\Service\EcrivainTree` → `Tree\Entity\*`,
`Gedcom\Service\AnalyseurGedcom` → trois classes internes de `Tree` — et non comme une marge
numérique. Trois propriétés en découlent, et ce sont elles qu'on voulait :

1. **une violation nouvelle échoue immédiatement**, même si le total n'a pas bougé ;
2. **la dette est lisible dans le fichier d'exemptions**, avec son renvoi à l'ADR — personne
   n'a besoin de deviner pourquoi le test tolère ces lignes-là ;
3. **solder la dette se voit** : on retire des lignes d'exemption, et l'on ne peut pas les
   remettre sans que cela se lise en revue.

Le compte reste utile, mais comme **mesure**, pas comme porte : 55 aujourd'hui, 11 après les
gestes mécaniques, 1 après la dette, 0 ensuite. C'est un indicateur de progression, et il n'a
pas à être exact pour être utile — ce qu'un seuil, lui, aurait dû être.

#### La dette, et pourquoi elle n'est pas une exception

Le déplacement d'`EcrivainTree` est ajourné. Il est inscrit comme **dette**, avec les trois
choses qu'une exception n'a pas : une date, un propriétaire, un geste.

| | |
|---|---|
| **Inscrite le** | 2026-09-15 |
| **Propriétaire** | l'agent de l'import GEDCOM |
| **Portée** | 10 références : 7 vers `Tree\Entity\…` dans `Gedcom\Service\EcrivainTree` (632 lignes), 3 vers des services et un objet valeur internes de `Tree` dans `Gedcom\Service\AnalyseurGedcom` |
| **Geste** | `Tree` déclare `Tree\Contract\PortImportArbre` avec ses propres objets de transfert ; la logique d'écriture remonte dans `Tree` derrière ce port ; `Gedcom` convertit ses `PersonneLue`, `FamilleLue`, `CitationLue`, `NoteLue`, `FaitLu`, `SourceLue` — qui existent déjà — et appelle |
| **Sens** | `Gedcom` → `Tree\Contract\` uniquement ; `Tree` ne référence jamais `Gedcom` |
| **Motif de l'ajournement** | les trois derniers adaptateurs s'écrivent, la pile est en remontage. Déplacer 632 lignes au cœur de l'import maintenant mettrait en risque un pipeline prouvé de bout en bout, pour un gain d'architecture qui ne se périme pas |
| **Levée** | adaptateurs stabilisés ; le compte tombe de 11 à 1 |

Et une condition d'inscription, qui est la raison d'être de la dette plutôt que du silence :
**elle est nommée dans le message d'échec du contrôle.** Un seuil non nul sans explication est
un seuil que le prochain relèvera d'un cran, puis d'un autre. Un seuil qui dit « 11
références restantes, dont 10 sont la dette `EcrivainTree`, voir ADR 0002 » se défend tout
seul, et se solde quand quelqu'un a le temps.

#### Les deux seuils de montée dans `Core`, qui ne sont pas le même

La règle soumise proposait : « quand un contrat est consommé par plus de deux modules, il
monte dans `Core` ». C'est juste pour les contrats et faux pour le vocabulaire, et l'ADR 0002
portait déjà l'autre moitié : « si **deux** modules ont besoin de la même chose, elle monte
dans `Core` ». Les deux coexistent parce qu'ils ne parlent pas de la même chose :

- **vocabulaire** — énumération, objet valeur — : **deux** modules suffisent. C'est le
  précédent de l'arbitrage **E** (`UncertainDate`, `DateQualifier`, `Sex`, `Branch`) ;
- **contrat** — un déclarant, des implémenteurs — : il reste chez son déclarant tant qu'il ne
  relie que deux modules ; au-delà, il monte. Précédents : `CercleDeVisibiliteInterface`,
  `CalqueEnAttenteInterface`.

La raison de la différence vaut d'être retenue : **un vocabulaire n'appartient à personne, un
contrat appartient à celui qui en a besoin.** Faire monter dans `Core` un port qui ne relie
que deux modules y installerait le métier de ces deux-là — et `Core` cesserait d'être
minimal, ce que la règle 3 interdit précisément. C'est d'ailleurs le mécanisme exact par
lequel `Tree` était devenu un second noyau sans que personne ne l'ait décidé (**E**).

---

### II — Le seuil compte le besoin, pas le nommage

Premier cas d'application de la règle de **HH**, arrivé deux heures après qu'elle a été
écrite. `Timeline` doit afficher les faits datés, donc leur **type**, donc
`Tree\Enum\FactType`. L'agent proposait que le port rende le `string` de la valeur de
*backing* — « le moins engageant si personne ne tranche ».

Comme position d'attente, c'était le bon réflexe : isoler derrière un point unique changeable
en une ligne est exactement ce qu'on fait pendant qu'un arbitrage se rend. Comme solution,
non — et la question dérivée qui l'accompagnait est la vraie question, meilleure que la
première :

> **Le seuil se compte-t-il sur les modules qui en ont *besoin*, ou sur ceux qui le *nomment*
> dans leur code ?**

Si le port rend un `string`, `Timeline` a besoin du vocabulaire sans jamais l'écrire, et le
seuil n'est jamais atteint **par construction**. Une règle qu'un contournement d'une ligne
suffit à désamorcer n'est pas une règle : c'est une formalité.

**Le seuil compte le besoin.** Et pour que ce ne soit pas une intention invérifiable, la règle
se dit par son symptôme :

> **Un contrat ne transporte pas un vocabulaire déguisé.** Si la valeur qui traverse la
> frontière *est* une valeur d'énumération d'un module — parce que l'appelant devra la
> comparer à des constantes, la traduire, ou la lui rendre telle quelle —, le contrat porte
> **l'énumération**, pas sa valeur de *backing*. Le `string` à la frontière n'est pas une
> conformité : c'est la même dépendance, non typée, et donc pire.

Pire en trois points concrets : le compilateur ne vérifie plus rien à la frontière ; une
valeur ajoutée à l'énumération ne prévient pas le consommateur ; et celui-ci finit par
comparer à des littéraux, c'est-à-dire par **redéclarer le vocabulaire en clandestin**.
Ce dernier signe est le détecteur en relecture : *le consommateur compare-t-il la chaîne à des
valeurs littérales ?* Si oui, il avait besoin du type.

Un point d'honnêteté sur la vérifiabilité, parce que **HH** vantait une règle qu'« un script
lit sans juger d'intention » : celle-ci n'en est pas une. Les deux cohabitent sans se
contredire parce qu'elles n'ont pas le même office — le **contrôle en CI** reste mécanique et
ne juge que des chemins (`Core\…` ou `<Autre>\Contract\…`) ; le **seuil**, lui, est une règle
de conception qui s'applique en relecture, au moment où l'on dessine un port. Prétendre
l'automatiser serait promettre ce qu'on ne peut pas tenir.

#### Le cas `FactType`, et une erreur de HH qu'il corrige

Comptage fait avant de répondre : `FactType` est nécessaire à **`Tree`, `Gedcom` et
`Timeline`**. Trois modules. **Il franchissait déjà le seuil avant que `Timeline` n'existe** —
la question n'était pas « faut-il le faire monter », mais « pourquoi ne l'a-t-on pas déjà
fait ». Réponse : parce que rien ne vérifiait la règle 3, ce qui est exactement le constat de
**HH**.

Et ce cas met au jour **une erreur dans HH lui-même**, qu'il vaut mieux corriger que laisser
vivre. HH écrivait que les onze énumérations de `Gedcom → Tree` devaient passer en
`Tree\Contract\`. C'est faux au regard de la règle des deux seuils, écrite dans le même
arbitrage : une énumération est du **vocabulaire**, pas un contrat, et la règle 3 de l'ADR
0002 est explicite — *« elle ne s'installe pas dans le plus gros des deux »*. `Tree` est le
plus gros. Ces énumérations vont donc dans **`Core\Enum\`**, comme `Sex`, `Branch`,
`DateQualifier` et `UncertainDate` avant elles (arbitrage **E**), et comme `FactType`
maintenant.

Le compte n'en est pas affecté — les deux destinations rendent la référence licite — mais la
destination, si. Une règle appliquée de deux façons dans le même document aurait été
retrouvée un jour par quelqu'un qui aurait choisi la moins gênante.

#### Les deux conditions posées avant le déplacement, et ce qu'elles valent

Les deux réserves qui accompagnaient la question étaient justes, et la seconde plus que la
première.

**Déplacer un enum persisté est sans danger — mais cela se vérifie.** Doctrine ne stocke que
la **valeur de *backing***, jamais le nom de classe : la colonne reste un `VARCHAR`, sa valeur
ne change pas, aucune migration n'est requise. La condition n'est pas l'intuition mais le
contrôle : après le déplacement, plus aucun mapping `enumType` ne doit désigner l'ancien nom
pleinement qualifié, et le jeu de données existant doit se recharger.

**Le test de réflexion sur les 44 colonnes `enumType` ne doit pas devenir vacuous**, et c'est
le point le plus fin des deux. Un test qui parcourt un ensemble devenu **vide** passe en
silence : si le déplacement casse la manière dont il découvre les colonnes, il continuera de
rendre « vert » en ne vérifiant plus rien — et c'est lui qui garde les deux `VARCHAR` trop
courts trouvés à l'import. La garde tient en une ligne à ajouter avant les assertions :
**affirmer le nombre de colonnes inspectées**, et faire échouer le test s'il est inférieur à
l'attendu. Un test qui ne dit pas combien d'objets il a examinés ne prouve pas ce qu'on croit.

C'est une règle qui dépasse ce cas : **tout test qui itère sur un ensemble découvert doit
affirmer la taille de cet ensemble.** La découverte par réflexion est précisément ce qui rend
un test silencieusement vide.

---

## 3. Les décisions prises, et par qui

**Ce ne sont pas des arbitrages sur pièces.** Les points du § 1 ont été tranchés en
comparant deux textes et, au besoin, en comptant sur la donnée réelle. Les deux points
ci-dessous ont été **tranchés par choix** : ils avaient été remontés comme dépassant ce
qu'un arbitre sur pièces pouvait décider, et ils font autorité au-dessus des deux corpus. La
distinction compte pour qui relira : on ne rediscute pas l'un comme l'autre.

Elle porte aussi sur **qui** a décidé, et les deux ne viennent pas du même endroit : le
premier point est une décision du **commanditaire**, le second une décision du **pilote de
la mission**. Un lecteur qui voudra rouvrir l'un ou l'autre ne s'adresse pas à la même
personne.

### Un compte en attente de validation voit l'arbre ancien, et rien d'autre

**Décidé** : il accède aux fiches des personnes **décédées uniquement** — 256 sur 274 — et à
**aucune personne vivante**, y compris celle à laquelle il prétend se rattacher.

**Motif du commanditaire** : l'attente peut durer des jours ; un écran vide pousse à
l'abandon, et l'arbre ancien n'expose rien de sensible.

Cela confirme la position de l'ADR 0003 contre la première rédaction du modèle, qui ne
laissait voir qu'un écran « votre rattachement est en cours de vérification » (point **N** du
§ 1). Mais la décision ajoute une précision d'implémentation que ni l'un ni l'autre corpus
ne portait, et qui est la vraie raison d'écrire ce paragraphe :

> **Un compte en attente n'a pas de cercle de visibilité. Il en obtient un à la validation.
> Ce n'est pas un cercle réduit, c'est un régime distinct.**

Le service de cercle ne rend donc pas « une liste vide » pour ce compte : il rend « **pas de
cercle** », un état que le code doit pouvoir distinguer d'un cercle calculé dont le résultat
serait vide. Et surtout, **il ne faut pas l'implémenter comme « un cercle avec `N = 0` »**.
Cette tentation est naturelle — elle réutilise le code existant et elle a l'air équivalente —
et elle est fausse deux fois. D'abord parce qu'un `N = 0` **reste un cercle** : par la
formule de l'ADR 0003, `A(u) = { u }`, donc le cercle contient encore `u` et, après la passe
des conjoints, son conjoint — soit exactement les fiches vivantes qu'on veut fermer. Ensuite
parce qu'un réglage à zéro est un état que le premier remaniement normalise : quelqu'un
bornera `N` à `1 ≤ N ≤ 6` comme le dit l'ADR, et le régime « en attente » disparaîtra en
silence.

Le corollaire à ne pas manquer concerne le **pré-lien d'invitation**. Le candidat a pu
confirmer la date de naissance d'une personne désignée par l'administrateur
(`mbr_invitation.person_id`, § 6.1 du dossier) ; si cette personne est vivante, sa fiche
reste fermée jusqu'à l'activation. **Confirmer une date n'est pas la lire**, et le pré-lien
n'ouvre aucun accès — sans quoi le garde-fou anti-oracle de l'inscription serait contourné
par la porte de sortie qui le suit immédiatement.

*Appliqué dans* : `modele-donnees.md` §§ 2.1, 2.7, 4.5, 9.3 · `adr/0003`.

### L'entité de rattachement s'appelle `Member`

**Décidé — par le pilote de la mission, et non par le commanditaire.** `Membership\Member`,
table `mbr_member`. Pas `Account`.

**Motif** : le module s'appelle `Membership`, et `Account` entrait en collision conceptuelle
avec `Core\User`, qui *est* le compte — courriel, mot de passe, rôles, `UserInterface`.

Cela confirme le point **H** du § 1, qui était l'arbitrage le plus discutable de la liste
puisqu'il s'écartait d'un nom écrit dans le dossier de mission. Il n'est plus discutable, et
l'attribution lève ce qui le rendait inconfortable : `Account` avait été écrit dans le
dossier **par le pilote lui-même**, pas par le commanditaire. Ce n'est donc pas un arbitre
qui s'écarte de la lettre de son mandant, c'est un auteur qui corrige son propre texte.

**Conséquence à propager** : toute mention d'`Account` rencontrée dans le code ou la
documentation est un **résidu**, y compris dans le dossier de mission — qui a été corrigé
depuis. Il n'existe ni classe `Membership\Account`, ni table `mbr_account`. Les deux corpus
ne conservent le mot que dans des phrases qui disent précisément qu'il ne s'emploie pas :
c'est délibéré, et c'est la seule forme de conservation utile — un lecteur qui croisera
`Account` dans un vieux document doit pouvoir comprendre en une ligne ce qu'il regarde,
plutôt que de créer l'entité.

*Appliqué dans* : `modele-donnees.md` §§ 2, 4.2 · `adr/0002`.

---

**Aucune de ces deux décisions ne change une colonne, une table ni une clé étrangère.** La
première porte sur le comportement d'un service. La seconde ne renomme rien : le modèle de
données n'a jamais écrit `Account` ailleurs que dans les phrases qui l'écartent — la table
s'appelait déjà `mbr_member` et l'entité `Member`. Le schéma reste à **23 tables**,
inchangé.

### Addendum du 2026-10-01 — le compte en attente peut n'avoir aucune fiche

**Décidé par le commanditaire** ([ADR 0013](adr/0013-inscription-sans-fiche.md)). La
première décision ci-dessus est **prolongée, pas rouverte** : un compte en attente voit
l'arbre ancien et rien d'autre — y compris quand il n'a **aucune** fiche, parce que le
candidat ne s'est pas trouvé et a décrit sa famille en texte libre (`match_kind =
a_rattacher`, `person_id` nul).

Deux conséquences qu'un relecteur doit trouver ici :

1. **Le point N du § 1 change de prémisse.** Voir les défunts n'exigeait qu'un lien et une
   reconnaissance de soi dans un candidat ; il n'exige plus qu'un lien. C'est assumé — l'état
   civil des morts « n'expose rien de sensible », c'était déjà le motif —, et c'est ce qui fait
   tomber l'« oracle inverse » qui gardait les défunts dans la recherche de rattachement : ils
   n'y sont plus proposés.
2. **Refuser une inscription, c'est suspendre et purger**, pas supprimer. Le courriel reste
   réservé ; la description, l'identité déclarée, la suggestion et la photo disparaissent.

*Appliqué dans* : `modele-donnees.md` §§ 4.2, 4.5, 6.1 · `api-contract.md` §§ 4, 7.1, 10.1, 12 ·
`adr/0003`, `adr/0013`.

---

## 4. Ce sur quoi les deux corpus étaient déjà d'accord

Pour que ce document ne donne pas une fausse impression de désordre : sur l'essentiel, les
deux textes décrivaient le même produit. Aucun désaccord sur les sept modules et leurs
préfixes ; sur l'absence de relation Doctrine inter-modules ; sur le mécanisme **unique** de
validation, créations comprises, avec message obligatoire au refus ; sur la représentation
des dates incertaines et l'interdiction d'aplatir une fourchette ; sur la séparation source /
citation et le `QUAY` porté par la citation ; sur l'absence de table pour les positions du
moteur comme pour le cercle de visibilité ; sur la borne `1 ≤ N ≤ 6` ; sur la nature dérivée
de `branch`, `generation` et `rank` ; sur le filtrage dans la sérialisation et jamais dans le
contrôleur ; sur les données réelles hors du dépôt et les fixtures à graine fixe.

Ce sont l'invalidation de cache, la résolution de conflit, la propriété de quatre classes
partagées, une poignée de noms et quatre nombres de fixtures qui divergeaient.

---

## 5. Où retrouver les traces

- Les quatre ADR amendés portent une ligne **« Révision du 2026-09-15 »** dans leur section
  « Conséquences » : [0003](adr/0003-cercle-de-visibilite.md) (**M**),
  [0004](adr/0004-ecriture-par-proposition.md) (**S**),
  [0005](adr/0005-moteur-de-placement-recalcule.md) (**Z**, **BB**, **CC**, **DD** — plus une
  **prémisse corrigée** en tête de son « Contexte »),
  [0006](adr/0006-donnees-reelles-non-versionnees.md) (**U**, **V**, **W**, **Y**).
- Les ADR [0001](adr/0001-choix-de-la-pile.md),
  [0002](adr/0002-monolithe-modulaire-et-frontieres.md) et
  [0007](adr/0007-rendu-svg-performance.md) ont été précisés sans que leur décision change.
- **`docs/frictions-modele-adr.md` a été absorbé dans ce document, puis supprimé.** C'était
  la liste de travail qui a alimenté les points **A** à **W** : l'inventaire des divergences
  vu depuis le modèle de données, avec ses décomptes rejoués sur les onze fichiers GEDCOM du
  dossier d'origine. Tout ce qu'il portait de décisif est ici — les arbitrages au § 1, les
  raisonnements au § 2, les décomptes dans les points **U**, **V**, **W** et **Y**. Il a été
  supprimé parce que **deux documents qui recensent les mêmes divergences, dont un périmé,
  sont exactement la situation que cette mise en cohérence existe pour faire disparaître** :
  un agent qui serait tombé sur lui y aurait lu 23 frictions « à arbitrer » déjà tranchées,
  et trois recommandations qui n'ont pas été suivies.
- Le code du moteur d'origine est dans **`docs/moteur-retrouve/`** — hors périmètre de ce
  document, mais c'est la source de vérité de l'algorithme de placement (**Z**).
