# ADR 0002 — Monolithe modulaire et frontières entre modules

Date : 2026-09-15 · Statut : acceptée

## Contexte

Sept domaines à couvrir, d'évolutions indépendantes : comptes et sécurité, l'arbre lui-même,
l'adhésion à l'arbre, les propositions de modification, les médias, la chronologie, l'import
GEDCOM. Un développeur, une dizaine d'agents qui implémenteront en parallèle.

C'est ce dernier point qui décide : **des frontières floues entre modules produisent des
conflits d'écriture entre agents**, pas seulement de la dette. Le découpage n'est pas une
élégance, c'est la condition pour que plusieurs auteurs travaillent en même temps.

Le dépôt de référence impose la règle : aucune relation Doctrine inter-modules, référence par
identifiant + libellé dénormalisé. On la reprend. Elle a ici deux cas gênants qu'il faut
traiter en face plutôt que découvrir à l'implémentation.

## Décision

`api/src/Module/<Domaine>/{Controller,Entity,Enum,Repository,Service,Security,EventSubscriber}`

| Module | Responsabilité | Préfixe |
|---|---|---|
| `Core` | comptes, sécurité, rôles, journal d'audit, notifications, réglages | `core_` |
| `Tree` | personnes, familles, sources, citations, moteur de placement | `tree_` |
| `Membership` | invitations, inscription, rattachement compte↔personne (`Member`, pas `Account` : `Core\User` est déjà le compte), cercle de visibilité | `mbr_` |
| `Proposal` | demandes, diff, validation/refus, révisions | `prp_` |
| `Media` | portraits et preuves, téléversement, vignettes | `med_` |
| `Timeline` | vue chronologique | `tml_` (une seule table : `tml_preference`) |
| `Gedcom` | import / export GEDCOM — **supprimé le 2026-10-06 (ADR 0018)**, avec ses tables | `ged_` (plus aucune table) |
| `Almanach` | carte des lieux, anniversaires | `alm_` (réservé — **aucune table dans cette livraison**) |
| `Assistant` | l'assistant IA : propositions, rattachement assisté, questions sur l'arbre | `ast_` (depuis le 2026-10-01 : les conversations de « Poser une question ») |
| `Statistiques` | Explorer › Stats : l'arbre en chiffres, le chantier (ADR 0015) | `stt_` (réservé — **aucune table**) |

Règles de frontière :

1. **Chaque module possède ses entités, ses tables, son fichier de routes
   `config/routes/<module>.yaml` et ses services `config/services/<module>.yaml`.** On ne
   partage pas un gros fichier de configuration : c'est la première source de conflits.
2. **Aucune relation Doctrine entre modules.** La référence inter-module est un identifiant
   plus un libellé dénormalisé : `Membership\Member.personId` + `personLabel`, jamais un
   `ManyToOne` vers `Tree\Person`. *(Le dossier de mission écrit `Account` ; **l'entité
   s'appelle `Member`**, décision du commanditaire — `Core\User` est déjà le compte, et la
   valeur ajoutée de `mbr_member` est le **rattachement**. Il n'existe ni classe `Account` ni
   table `mbr_account` : toute occurrence est un résidu. Voir `docs/modele-donnees.md` § 4.2
   et `docs/arbitrages.md` § 3.)*
3. **`Core` est le noyau partagé, et il est le seul.** Aucun autre module n'a le droit d'être
   « un peu noyau ». Si deux modules ont besoin de la même chose, elle monte dans `Core` ou
   elle est dupliquée — elle ne s'installe pas dans le plus gros des deux.
4. **Un module en appelle un autre par son service applicatif**, jamais par son repository ni
   par ses entités. La surface publique d'un module est l'ensemble de ses services déclarés
   comme tels ; le reste est interne.
5. **Lectures transverses assumées, écritures strictement dans leur module.** Valider une
   proposition écrit dans `Tree` **en appelant un service de `Tree`**, pas en manipulant
   `Tree\Person` depuis `Proposal`.

## La surface publique d'un module : `Contract/`

*Section ajoutée le 2026-09-15, après un recensement des références réelles — voir
« Révision » plus bas. Les cinq règles ci-dessus restent vraies ; celle-ci dit **où** passe
la frontière, de façon qu'un script puisse la vérifier.*

La règle 4 pose déjà que « la surface publique d'un module est l'ensemble de ses services
déclarés comme tels ; le reste est interne ». Elle n'était vérifiée par rien, parce que rien
dans le code ne disait *lesquels*. On le dit maintenant, par le rangement :

> **La surface publique d'un module est son dossier `<Module>/Contract/`.** Il contient les
> interfaces de port, les objets de transfert qui figurent dans leurs signatures, et les
> énumérations que ces signatures portent. **Tout le reste d'un module est interne** :
> `Entity/`, `Repository/`, `Service/`, `Security/`, `Controller/`.
>
> **Un `use` inter-modules n'est licite que vers `Core\…` ou vers `<Autre>\Contract\…`.**

C'est la règle que le contrôle en CI vérifie, et elle a la propriété qui manquait : elle se
décide en lisant un chemin, sans juger d'intention. `Core\Contract\` existait déjà et servait
de modèle ; les six autres modules l'adoptent.

### Ce qu'un contrat a le droit d'être

Poser un dossier ne suffit pas, parce qu'une interface peut être un contrat de nom et une
fenêtre de fait. La qualification est la moitié qui compte :

> **Un contrat dont la signature nomme une `Entity/` ou un `Repository/` du module qui le
> déclare n'est pas un contrat.** Il oblige quiconque l'implémente à franchir la frontière
> pour s'y conformer ; l'interface ne fait alors que déplacer la violation dans un fichier
> qui a l'air propre.

Le projet en porte les deux faces, dans le même module et sous la même plume :

| Contrat déclaré par `Proposal` | Signature | Effet sur l'implémenteur |
|---|---|---|
| `PortArbre::creerPersonne(PersonneAcreer): LigneCreee` | ses propres objets de transfert | `Tree` l'implémente **sans voir une seule entité de `Proposal`** |
| `ApplicateurDOperation::appliquer(ChangeRequest, ChangeRequestOp[], …)` | **deux entités de `Proposal`** | `Media` ne *peut pas* l'implémenter sans référencer `Proposal\Entity\…` |

Le second explique à lui seul la majorité des références inter-modules constatées. Ce n'est
pas une indiscipline d'implémenteur : **c'est une signature qui oblige.** Corriger la
signature — passer des objets de transfert plutôt que les entités — supprime les références
sans qu'on ait à discipliner qui que ce soit. C'est la forme de correction qu'on préfère
toujours ici : rendre la faute impossible plutôt que la défendre.

### Quand un contrat monte dans `Core`

Deux seuils, et ils ne portent pas sur la même chose. Les confondre est ce qui a fait de
`Tree` un second noyau sans que personne ne le décide.

- **Vocabulaire partagé** — énumération, objet valeur — : **deux** modules suffisent, c'est
  la règle 3 sans changement. Précédent : `UncertainDate`, `DateQualifier`, `Sex`, `Branch`,
  montés dans `Core` (`docs/modele-donnees.md` § 2.8).
- **Contrat** — une interface avec **un** déclarant et des implémenteurs — : il reste chez
  son déclarant tant qu'il n'est consommé que par **deux** modules. Au-delà, il monte dans
  `Core`. Précédents : `CercleDeVisibiliteInterface`, `CalqueEnAttenteInterface`.

La différence tient à ceci : un vocabulaire n'appartient à personne, un contrat appartient à
celui qui en a besoin. Faire monter dans `Core` un port qui ne relie que deux modules y
installerait le métier de ces deux-là, et `Core` cesserait d'être minimal — ce que la règle 3
interdit précisément.

**Le seuil compte les modules qui en ont *besoin*, pas ceux qui le *nomment*.** La nuance
n'est pas scolastique : elle est ce qui sépare la règle d'une formalité. Un port qui rendrait
la **valeur de *backing*** d'une énumération au lieu de l'énumération permettrait à son
appelant d'en avoir besoin sans jamais l'écrire — et le seuil ne serait **jamais atteint, par
construction**. D'où la formulation par le symptôme, qui se vérifie en relecture :

> **Un contrat ne transporte pas un vocabulaire déguisé.** Si la valeur qui traverse la
> frontière *est* une valeur d'énumération d'un module — parce que l'appelant devra la
> comparer à des constantes, la traduire, ou la lui rendre telle quelle —, le contrat porte
> **l'énumération**, pas son `string`. Le `string` à la frontière n'est pas une conformité :
> c'est la même dépendance, non typée, donc pire.

Pire en trois points : le compilateur ne vérifie plus rien à la frontière ; une valeur ajoutée
à l'énumération ne prévient aucun consommateur ; et celui-ci finit par comparer à des
littéraux, c'est-à-dire par **redéclarer le vocabulaire en clandestin**. Ce dernier signe est
le détecteur en relecture : *le consommateur compare-t-il la chaîne à des valeurs
littérales ?* Si oui, il avait besoin du type.

Cette règle-ci, contrairement à celle du dossier `Contract/`, **n'est pas automatisable** — et
il vaut mieux le dire que le promettre. Les deux ne font pas le même office : le contrôle en
CI ne juge que des chemins ; ce seuil est une règle de conception, qui s'applique au moment où
l'on dessine un port.

**Corollaire à appliquer, et déjà dû avant d'être posé** : `FactType` est nécessaire à `Tree`,
`Gedcom` et `Timeline` — trois modules. Il monte dans `Core\Enum\`, avec les cinq autres
énumérations de `Tree` que `Gedcom` consomme (`NoteScope`, `SourceKind`, `CitationQuality`,
`CitationContext`, `ChildRelation`), et pour la même raison que `Sex`, `Branch`,
`DateQualifier` et `UncertainDate` avant elles.

> **Déplacer un enum persisté ne demande aucune migration, mais se vérifie.** Doctrine ne
> stocke que la valeur de *backing*, jamais le nom de classe : la colonne reste un `VARCHAR`
> de même contenu. Le contrôle est qu'après le déplacement, plus aucun mapping `enumType` ne
> désigne l'ancien nom pleinement qualifié.
>
> **Et le test de réflexion sur les colonnes `enumType` ne doit pas devenir vacuous.** C'est
> lui qui garde les `VARCHAR` trop courts ; s'il découvre ses colonnes par réflexion et que
> le déplacement casse cette découverte, il parcourra un ensemble **vide** et restera vert en
> ne vérifiant plus rien. La garde tient en une ligne : **affirmer le nombre de colonnes
> inspectées** et échouer s'il est inférieur à l'attendu. Règle générale, qui dépasse ce
> cas : *tout test qui itère sur un ensemble découvert doit affirmer la taille de cet
> ensemble.*

### `Gedcom → Tree` n'est pas une exception

> **Remplacé par l'[ADR 0018](0018-suppression-de-l-import-gedcom.md) (2026-10-06)** : le module `Gedcom` est supprimé. Cette section et la
> suivante (la dette `EcrivainTree`) restent comme raisonnement ; la dette n'a pas été soldée
> par `PortImportArbre`, elle a disparu avec son code.

L'adaptateur d'import écrit des lignes de `Tree`, et il est tentant de le nommer comme une
exception légitime : *un adaptateur construit par définition les entités du module qu'il
peuple.* **On l'écarte**, pour trois raisons dans cet ordre.

1. **Ce serait le contraire de ce qu'on a demandé à `Proposal`.** Valider une proposition
   écrit aussi dans `Tree` ; on a refusé que `Proposal` manipule `Tree\Person` et exigé un
   port — `PortArbre`, déclaré par `Proposal`, implémenté par `Tree\Service\PortArbreDoctrine`.
   Deux précédents opposés pour le même geste, c'est une règle morte : le prochain
   adaptateur invoquera celui qui l'arrange.
2. **Le « par définition » ne tient pas.** Un adaptateur d'import construit des **données**,
   pas nécessairement des entités. Rien ne l'empêche d'analyser le fichier, de produire ses
   propres objets de transfert — `Gedcom\Dto\PersonneLue`, `FamilleLue`, `CitationLue`… qui
   **existent déjà** — et de les remettre au module propriétaire. Aucun argument
   d'impossibilité ne soutient l'exception : à 274 personnes et 103 familles, il n'y a pas
   d'obstacle de volume ; l'idempotence sur `gedcom_xref` et les trois passes d'insertion
   sont la compétence de `Tree`, pas celle du lecteur de GEDCOM.
3. **Le modèle de données l'avait déjà tranché.** Son § 8.3 écrit : « L'import **écrit** dans
   `Tree`, `Media` et `Core`, mais il le fait **par appel de service**, pas par relation. »

Le sens de la dépendance est donné par là, et il évite le cycle : **`Tree` déclare
`Tree\Contract\PortImportArbre`**, avec ses propres objets de transfert ; `Gedcom` convertit
ses `…Lue` et appelle. `Gedcom` référence `Tree\Contract\`, `Tree` ne référence jamais
`Gedcom`.

Ce que cela coûte, dit franchement : **une classe change de module.** Le constat est qu'un
seul fichier concentre toutes les vraies violations de ce groupe — il s'appelle
`Gedcom\Service\EcrivainTree`, et son nom dit déjà où il devrait vivre. Un service qui écrit
`Tree` et le proclame, rangé ailleurs que dans `Tree`, n'est pas une exception à documenter :
c'est un fichier à déplacer.

### La dette nommée : `Gedcom\Service\EcrivainTree`

Le déplacement est **ajourné**, et il est inscrit ici comme **dette**, pas comme exception.
La distinction n'est pas rhétorique : une exception nommée dans un ADR est permanente par
construction, une dette porte une date, un propriétaire et un geste. Les deux se ressemblent
le jour où on les écrit, et plus du tout trois mois après.

| | |
|---|---|
| **Inscrite le** | 2026-09-15 |
| **Propriétaire** | l'agent de l'import GEDCOM |
| **Portée** | 10 références de `Gedcom` vers l'interne de `Tree` : 7 vers `Entity\{Person, Family, Child, Fact, Note, Source, Citation}` dans `Gedcom\Service\EcrivainTree` (632 lignes) ; 3 vers `Service\CalculAscendance`, `Service\NormalisateurNom` et `ValueObject\FamillePlacable` dans `Gedcom\Service\AnalyseurGedcom` |
| **Geste** | `Tree` déclare `Tree\Contract\PortImportArbre` avec ses propres objets de transfert ; la logique d'écriture d'`EcrivainTree` remonte dans `Tree` derrière ce port ; `Gedcom` convertit ses `PersonneLue`, `FamilleLue`, `CitationLue`, `NoteLue`, `FaitLu`, `SourceLue` — **qui existent déjà** — et appelle. Les trois services internes reçoivent une interface dans `Tree\Contract\` ou remontent avec le port |
| **Sens de la dépendance** | `Gedcom` → `Tree\Contract\` uniquement. `Tree` ne référence jamais `Gedcom` : pas de cycle |
| **Motif de l'ajournement** | les trois derniers adaptateurs s'écrivent au moment où la dette est inscrite, et la pile est en cours de remontage. Déplacer 632 lignes qui tiennent le cœur de l'import mettrait en risque un pipeline prouvé de bout en bout, pour un gain d'architecture qui ne se périme pas |
| **Levée** | quand les adaptateurs sont stabilisés. Le compte du contrôle tombe alors de 11 à 1 |

**Tant qu'elle n'est pas levée, elle est nommée dans le message d'échec du contrôle** — pas
tue. Un seuil non nul sans explication est un seuil que le prochain relèvera d'un cran ; un
seuil qui dit « 11 références restantes, dont 10 sont la dette `EcrivainTree`, voir ADR 0002 »
est un seuil qui se défend tout seul.

## Le cas gênant : ce qu'on perd, et comment on le compense

La règle 2 supprime l'intégrité référentielle en base sur toutes les références
inter-modules. Deux endroits font mal.

**`Membership\Member` → `Tree\Person`.** La base ne garantit plus qu'un compte pointe vers
une personne existante, ni que `personLabel` soit à jour. Elle garantit encore l'unicité
(« une personne ne peut être réclamée que par un compte ») : c'est un `UNIQUE(person_id)`
sur `mbr_member`, contrainte intra-module, elle survit.

**`Proposal` référence à peu près tout** : une personne, une famille, une citation, un média,
un compte auteur, un compte validateur. Cinq modules. Et dans le cas d'une **création**
(§ 6.4), la demande référence une personne **qui n'existe pas encore**, sous un identifiant
provisoire résolu à la validation.

Ce dernier cas est l'argument qui rend la règle tenable plutôt que dogmatique : **une clé
étrangère y serait de toute façon impossible.** La contrainte référentielle n'est pas
sacrifiée par doctrine d'architecture, elle est structurellement inapplicable à une demande
portant sur une entité future. Si on gardait les FK ailleurs, `Proposal` aurait deux
mécanismes de référence au lieu d'un — le pire des deux mondes.

Compensation, en quatre mesures **toutes obligatoires** :

1. **Résolution au point d'entrée.** Toute référence inter-module est validée par le service
   du module propriétaire *avant* écriture. `Membership` ne stocke un `personId` qu'après que
   `Tree` le lui a confirmé et lui a rendu le libellé. Aucun identifiant n'entre en base sans
   être passé par son propriétaire.
2. **Suppression et fusion par événement, jamais par cascade.** `Tree` publie un événement à
   la suppression ou à la fusion d'une personne ; `Membership`, `Proposal` et `Media` s'y
   abonnent et traitent le cas explicitement — le compte repasse en attente de rattachement,
   la demande en cours devient `caduque` (ADR 0004), le média est réaffecté ou orphelinisé
   avec trace. Il n'y a pas de `ON DELETE CASCADE` inter-module parce qu'il n'y a pas de FK ;
   il y a une décision métier écrite pour chaque cas.
   *Articulation avec l'ADR 0004, qui écrit « la caducité est détectée à la validation, pas
   par une tâche de fond » : les deux sont vrais et ne parlent pas du même déclencheur. La
   **disparition d'une cible** est traitée ici, immédiatement, sur événement ; le **décalage
   de version** ordinaire est constaté là-bas, à la validation. Ni l'un ni l'autre n'est un
   balayage périodique — c'est ce que l'ADR 0004 interdit.*
3. **Rafraîchissement des libellés par le même canal.** Un libellé dénormalisé n'est **jamais**
   une source de vérité : il sert à l'affichage et au tri, jamais à une décision, jamais à une
   comparaison. Un renommage publie un événement, les abonnés se mettent à jour ; si l'un rate
   l'événement, l'affichage est périmé — pas la logique.
4. **Commande `heritage:verifier-integrite`**, exécutée en CI sur les fixtures et disponible
   en local. Elle parcourt toutes les références inter-modules et signale les pendantes.
   C'est le filet que la base ne tend plus. Sans elle, la règle 2 est un pari ; avec elle,
   c'est un choix.

## Alternatives considérées

- **Relations Doctrine inter-modules**, le réflexe : la base garantirait l'intégrité et
  `Proposal` aurait des jointures confortables. Écartée — le cas de la création à identifiant
  provisoire n'est pas exprimable en FK (voir ci-dessus), et la règle héritée du dépôt de
  référence tranche le reste.
- **Un schéma PostgreSQL par module** au lieu d'un préfixe de table : frontière plus dure,
  mais complique migrations, sauvegardes et lectures transverses pour un gain nul à cette
  taille. Préfixe de table.
- **Microservices, bus de messages** : hors de question à 274 personnes et un développeur.
- **Fusionner `Proposal` dans `Tree`** pour supprimer le nœud de références. Écartée :
  `Proposal` porte aussi des demandes sur les médias et les citations, et sa file de
  validation est une fonction transverse. Le nœud de références est **la nature du module**,
  pas un défaut de découpage — le déplacer ne le dissout pas, il le cache dans `Tree`.
- **Supprimer `Timeline` puisqu'il n'a presque aucune table.** Écartée : il a des
  contrôleurs, un service d'agrégation et un cercle de visibilité à appliquer. Un module
  sans table resterait un module — et il en a finalement une, `tml_preference`, parce que
  la largeur de bande et l'orientation doivent suivre l'utilisateur d'un appareil à l'autre,
  ce qu'un `localStorage` ne fait pas (`docs/modele-donnees.md` § 7).

## Conséquences

- Dénormalisation assumée, et un peu plus de code au point d'écriture. C'est le prix.
- **`Proposal` est le module le plus exposé ; il est donc le plus testé.** Ses tests couvrent
  en priorité les références pendantes et la résolution d'identifiant provisoire.
- La composition — fixtures, commande de vérification d'intégrité — vit **au-dessus** des
  modules, à la racine de `api/src/`. C'est la seule composition autorisée.
- Un contrôle en CI vérifie qu'aucune annotation de relation Doctrine ne cite une classe d'un
  autre `Module\`. Une frontière qui ne se vérifie pas automatiquement sera franchie.
- **Le même contrôle porte sur les `use` inter-modules**, avec `Core` en liste blanche. Ce
  n'est pas un raffinement : la règle 3 se franchit par un `use`, pas par une relation
  Doctrine. `use App\Module\Tree\Enum\Sex;` dans `Proposal` passerait au vert d'un contrôle
  qui ne regarde que les annotations — et c'est exactement ce qui était arrivé à quatre
  types partagés (`UncertainDate`, `DateQualifier`, `Sex`, `Branch`), remontés depuis dans
  `Core` (`docs/modele-donnees.md` § 2.8). La règle 3 était vérifiée par rien.
- **Révision du 2026-09-15 — la frontière passe par `Contract/`.** Le contrôle élargi
  ci-dessus a été écrit, et il a compté les références réelles : **54**, réparties en cinq
  couples de modules. Leur lecture une par une a montré que la règle telle qu'elle était
  écrite ne permettait pas de les trancher — elle disait « par son service applicatif »
  sans dire lesquels sont publics, et elle aurait dû à la fois autoriser et interdire le plus
  gros groupe, selon qu'on lisait « un module peut référencer les contrats qu'il implémente »
  ou « jamais les `Entity/` d'un autre ». D'où la section « La surface publique d'un
  module », qui pose la frontière là où un script peut la lire, et la qualification qui
  empêche un contrat de transporter des entités. **Aucune exception n'a été créée**, `Gedcom`
  compris ; le raisonnement est dans cette section et le journal dans
  `docs/arbitrages.md` (point **HH**).
- **Le compte doit être reproductible avant d'être une porte.** Deux recensements ont donné
  **46** et **54**. L'écart n'est pas anodin : c'est le nombre qui sert de seuil au test
  permanent, et un test qui gèle un chiffre que deux scripts ne retrouvent pas est un test
  qu'on désactivera au premier désaccord. **Spécification du contrôle**, à réconcilier avant
  de figer le seuil :
  1. il compte des **occurrences de `use`**, pas des fichiers ;
  2. il balaie **toutes les couches**, `Dto/` et `ValueObject/` comprises ;
  3. il ignore les `use` vers son propre module et vers `Core\…` ;
  4. il tient pour **licite** toute cible dont le chemin commence par `Contract\`, pour
     **illicite** tout le reste, et il rapporte par couple de modules **et par couche
     ciblée** — c'est cette seconde ventilation qui rend un écart diagnosticable plutôt que
     mystérieux ;
  5. son résultat est rejouable à la main.

  **Et le contrôle échoue sur la règle, jamais sur un compte.** Rejoué à une heure
  d'intervalle, le recensement est passé de 54 à 55 — trois adaptateurs s'écrivaient. Un
  seuil numérique figé sur un code en mouvement est faux le lendemain, et il a un second
  défaut, indépendant : **il est compensable**. Une violation ajoutée ici, une retirée là, le
  total ne bouge pas, le test reste vert — aveugle à exactement ce qu'il devait voir. Toute
  référence qui ne vise ni `Core\…` ni `<Autre>\Contract\…` fait donc échouer le contrôle,
  **sauf** si elle figure dans une **liste d'exemptions nommées**, fichier par fichier et
  cible par cible, où la dette ci-dessous entre comme une ligne explicite. Une violation
  nouvelle échoue alors immédiatement ; solder la dette consiste à retirer des lignes, et
  personne ne peut les remettre sans que cela se lise en revue. Le compte reste une
  **mesure** de progression — pas une porte.

  **La trajectoire, mesurée le 2026-09-16 sur `ExemptionsDeFrontiere::toutes()`** (et non
  estimée : le recensement complet rend 55 références inter-modules hors `Core`, dont 9
  licites et **46 illicites**, toutes couvertes par une exemption nommée, 0 découverte) :

  | Étape | Ce qu'on retire | Exemptions restantes |
  |---|---|---|
  | aujourd'hui | — | **46** |
  | après les déplacements **mécaniques** | vocabulaire à monter dans `Core` (18) + ports à descendre dans `Contract\` (10) | **18** |
  | après la **dette** datée | import GEDCOM (10) | **8** |
  | après les **surfaces publiques à dessiner** | 8 | **0** |

  > **Remplacé par l'[ADR 0018](0018-suppression-de-l-import-gedcom.md) (2026-10-06)** : les 22 exemptions du module `Gedcom` (la dette
  datée, 10, et 12 lignes de vocabulaire) sont parties avec lui. La ventilation est désormais
  vocabulaire 6, ports 10, surfaces 8 : **24 → 8 → 0**, ce que vérifie le même test.

  Soit **46 → 18 → 8 → 0**. Une version antérieure de ce paragraphe annonçait
  « 55 → 11 → 1 → 0 » : elle partait du recensement **total** (55) au lieu du nombre
  d'**illicites** (46), et ses deux étapes intermédiaires ne correspondaient à aucune
  ventilation par motif. Les quatre nombres ci-dessus somment à 46 (10 + 18 + 10 + 8), ce
  que `FrontieresInterModulesTest::testLaTrajectoireDeLADRResteCelleQueLaListePorte()`
  vérifie désormais à chaque exécution — un chiffre écrit dans un document ne prévient
  jamais qu'il a vieilli.
- **Les dossiers `Contract/` sont le travail des modules qui *déclarent*, pas de celui qui
  référence.** Les 12 références de `Tree` vers `Membership`, `Proposal` et `Media` se
  régularisent en déplaçant des fichiers **dans ces trois modules-là** ; `Tree` n'y change
  que des lignes `use`. Router ce geste à `Tree` enverrait son auteur chercher le travail
  dans le seul module où il n'est pas.
- Le schéma de données lui-même (tables, colonnes, index) n'est pas décidé ici : voir
  `docs/modele-donnees.md`.
