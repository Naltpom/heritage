# ADR 0006 — Les données réelles ne sont pas versionnées

Date : 2026-09-15 · Statut : acceptée

## Contexte

Le dépôt est privé, sous le compte `Naltpom`, nom `heritage`. Il serait commode d'y
mettre le GEDCOM : l'application démarrerait peuplée, les tests tourneraient sur les vraies
données, le moteur de placement se comparerait à `graphe.json` en CI.

Ce serait aussi publier, sur un serveur tiers, les dates de naissance, les lieux, les
filiations et les **actes d'état civil scannés** de 274 personnes dont 18 vivantes, qui n'ont
rien demandé. Un dépôt privé n'est pas un coffre : il est lisible par la plateforme, par tout
collaborateur ajouté, par toute action de CI, et par quiconque obtient un jeton d'accès. Et un
fichier commité une fois **reste dans l'historique**.

La règle n'est donc pas « le dépôt est privé, donc c'est bon ». C'est : **les données n'y
entrent pas.**

## Décision

Exclus par `.gitignore` : `*.ged`, `graphe.json`, `arbre-data.json`, `rendu-parts.json`,
`chantier.json`, `insee-brut.json`, `arbre.html`, `recherche/`, `vignettes/`, `data/`,
`uploads/`, `.env`.

- Les seules données du dépôt sont des **fixtures anonymisées, générées par Faker à graine
  fixe**. `docker compose up --build` doit suffire à obtenir une application fonctionnelle et
  peuplée.

  > **État au 2026-10-06 (ADR 0018)** : ce générateur n'existe toujours pas. La seule donnée
  > d'arbre du dépôt est l'**arbre d'essai** — onze personnes inventées, écrites à la main
  > (`App\DataFixtures\ArbreDEssaiFixtures`, groupe `essai`) —, qui remplace le fichier
  > `arbre-essai.ged` que chargeait l'import. Il ensemence la CI et une installation neuve ; il
  > ne remplit pas le cahier des charges ci-dessous, et n'est pas chargé au démarrage.
- **Une exception nommée, et elle va dans l'autre sens :
  `api/ressources/gazetteer/`.** Ce dossier porte de la **donnée publique de
  référence** — les communes et lieux-dits de France et les villes du monde, avec
  leurs coordonnées (GeoNames, CC BY 4.0 ; codes des départements et des régions,
  Etalab, Licence Ouverte 2.0). Elle est **délibérément versionnée**, et elle ne
  nomme personne.

  La raison tient en une phrase : **c'est ce qui permet à la carte des lieux de
  ne rien envoyer à personne.** L'alternative — appeler un service de géocodage —
  reviendrait à transmettre à un tiers les communes de naissance et de décès de
  la famille, c'est-à-dire exactement ce que cette ADR refuse. Le référentiel
  étant dans le dépôt, donc dans l'image de production, la commande
  `heritage:resoudre-les-lieux` tourne sur le VPS **sans réseau**.

  Corollaire : **ne le supprimez pas par réflexe de prudence.** Il ressemble à de
  la donnée volumineuse commitée, ce que cette ADR proscrit ; il est le contraire.
  Sans lui, la carte est vide.
- L'import des vraies données est une **commande locale** — `bin/console heritage:import-gedcom`
  — jamais une fixture, jamais une étape de démarrage.

  > **Remplacé par l'[ADR 0018](0018-suppression-de-l-import-gedcom.md) (2026-10-06)** : la commande est supprimée. Les vraies données vivent
  dans la base de production et ses sauvegardes ; elles se reprennent en **restaurant un
  dump**, jamais par une fixture ni par une étape de démarrage. La règle de fond — elles
  n'entrent pas dans le dépôt — est inchangée.
- `.env.example` est versionné, complet et commenté, sans aucune valeur secrète.

## Ce que cela impose aux fixtures

C'est le vrai sujet de cet ADR. Des fixtures Faker par défaut produiraient 274 personnes
plates : deux parents, deux enfants, des dates fermes, une source par fait. On testerait alors
sur un arbre qui n'a **aucun des défauts du vrai** — et chaque bug se découvrirait à l'import
des vraies données, c'est-à-dire chez le commanditaire, en production, sans filet.

Les fixtures doivent reproduire la **topologie et les pathologies**, pas seulement le volume.
Le tableau suivant est un cahier des charges, et **il est vérifié par un test** — sinon la
première évolution du générateur fera tomber une propriété sans que personne ne le voie.

Le tableau est écrit dans le **vocabulaire de colonnes** de `docs/modele-donnees.md`, et
non dans celui de `graphe.json` : c'est ce que le générateur écrit réellement. Les cibles
marquées « mesuré » viennent d'un comptage sur `arbre-romarin-v5.ged` ou `graphe.json`.

| Propriété | Cible | Pourquoi ça compte |
|---|---|---|
| Personnes / familles | ~274 / ~103 | ordre de grandeur du rendu et des requêtes |
| Générations | 12 (`generation` de 0 à −11) | le moteur de placement casse sur la profondeur, pas sur le nombre |
| Personnes sans décès enregistré | **~78 lignes à `deceased = false`** (mesuré), dont **~18 seulement** calculées vivantes | c'est le vrai piège : si le générateur met `deceased = true` partout sauf sur 18 fiches, la règle du § 9.2 du modèle n'est jamais exercée sur son cas difficile — les personnes sans date que le repli par génération doit rattraper. Les 18 vivantes sont concentrées sur les 3 générations récentes |
| Branches | `pp`/`pm`/`mp`/`mm`/`tr` **déséquilibrées** (20 / 134 / 51 / 64 / 5, mesuré) | une branche 7× plus grosse qu'une autre est ce qui fait diverger l'ordre horizontal |
| Fratries | de 1 à **9** enfants, dont **au moins 6 familles à ≥ 5** (mesuré : une à 9, une à 7, trois à 6, une à 5) | la grande fratrie est le cas qui déborde au placement |
| Remariages | ≥ 4 personnes présentes dans ≥ 2 familles (mesuré : 4) | un parent présent dans deux familles casse l'hypothèse « un nœud, un couple » |
| Divorces | ≥ 2 (mesuré : 2) | l'arête `.ar.divorce` doit exister quelque part dans les fixtures (ADR 0007) |
| Dates incertaines | **≥ 10 naissances en `birth_qualifier = 'entre'`** avec `birth_min` < `birth_max` d'au moins un an, et **≥ 20 en `'environ'`** (mesuré : 11 et 23) | la fourchette doit traverser rendu, chronologie, diff et formulaires. **Le critère ne peut pas être « `birth_min` ≠ `birth_max` »** : dans l'encodage du § 0.5 du modèle, une simple année (`1930`) donne déjà `1930-01-01` / `1930-12-31`, donc ce test serait vrai pour les trois quarts des fiches sans qu'aucune vraie fourchette existe |
| Personnes sans aucune date de naissance | **≥ 25** (mesuré : 50 sur 274) | elles cassent tout tri par date, et ce sont elles qui déclenchent le repli par génération du § 9.2 du modèle |
| Ancêtres sans nom | **≥ 4 à `surname` vide et ≥ 7 à `given_names` vide** (mesuré) | ils existent (`NAME` partiel) : le libellé dénormalisé doit y survivre (ADR 0002), et `display_name` doit savoir tomber sur `?` |
| Branches ouvertes | **≥ 90 personnes sans parents connus** (mesuré : 100, soit plus d'un tiers de l'arbre) | c'est l'état normal d'un arbre vivant, pas une anomalie. Un générateur qui n'en produit que 40 fabrique un arbre bien plus dense que le vrai, et le placement n'y rencontre jamais le cas courant |
| Sources / citations | **~28 sources** et **~483 citations** (mesuré), `quay` réparti ~50 / 15 / 35 % sur 0 / 1 / 2 | la fiabilité inégale est une fonctionnalité : filtres « non vérifié » et « sans source ». Le vrai arbre ne porte **aucun** `quay = 3` après requalification ; les fixtures en posent deux ou trois pour que le libellé « preuve directe » soit rendu au moins une fois, pas davantage |
| Personnes appuyées sur un acte | **~84 fiches à `best_quay >= 2`** (mesuré) | la proportion sourcé / non sourcé pilote l'affichage. **Ce sont 84 *fiches*, pas 84 citations** : le dossier de mission écrit « 84 faits », la vérification porte sur `SELECT count(*) FROM tree_person WHERE best_quay >= 2` |
| Doublons probables | ≥ 3 paires nom proche + année proche | le rapprochement de doublons de l'écran de validation (ADR 0004) ne se teste pas sans eux |
| Médias | quelques preuves et portraits, fichiers réellement générés | le contrôle des octets d'en-tête et la purge EXIF ont besoin d'un vrai fichier |

Trois règles s'ajoutent au tableau :

1. **Graine fixe** (`FIXTURE_SEED`, valeur par défaut constante dans le dépôt). Le même arbre à
   chaque chargement. Sans cela, on obtient un test qui passe une fois sur deux et personne ne
   sait pourquoi.
2. **Noms entièrement fictifs, pas des vrais noms déformés.** Pas de `Bourrache` → `Destombez`.
   Un patronyme rare, plus une commune, plus une année suffisent à identifier une famille : une
   anonymisation par déformation n'anonymise rien.
3. **Les fixtures sont générées, pas dérivées.** Un anonymiseur qui prendrait le vrai GEDCOM en
   entrée devrait tourner sur la machine du commanditaire, et son résultat serait commité —
   c'est-à-dire qu'on commiterait une **transformation** des vraies données, dont il faudrait
   alors prouver l'irréversibilité. On ne le fait pas.

### La règle 3 a été éprouvée le 23/09/2026, et elle tient

La piste qu'elle interdit — **partir du relevé réel et en remplacer les identités** — a été
mesurée ce jour-là, dans le cadre du chantier des nuages. Elle est enregistrée ici parce
qu'**une reconnaissance qui conclut « écarté » a autant de valeur qu'une qui conclut
« retenu »** : sans trace, quelqu'un la refera en croyant l'avoir inventée, et il n'aura pas
la règle 3 sous les yeux au moment de conclure.

**Ce qui a été construit**, hors dépôt : le graphe servi (empreinte `91ad68db`, 648 nœuds,
468 personnes, 180 familles), avec les 92 identités de personnes vivantes remplacées par des
chaînes d'un alphabet neutre **de même longueur**, et les lignes de dates de ces mêmes
personnes remplacées par des points de même longueur. Puis, en seconde variante, toutes les
largeurs arrondies à un pas de 20 unités.

**Ce que la mesure établit** — et c'est solide :

| | couples éligibles | largeurs distinctes | `ressorts.test.ts` |
|---|---|---|---|
| relevé réel | 8 | 27 | 1 échec (`:780`), 59 passés |
| identités remplacées | 8 | 27 | 1 échec (`:780`), 59 passés |
| + largeurs au pas de 20 | 8 | 6 | 1 échec (`:780`), 59 passés |

Sur la suite front entière, la variante arrondie rend **1289 cas, 1288 passés, 1 échec,
zéro saut** — à la ligne près ce que rend le relevé réel. Le troisième contrôle est celui
qu'on oublie et c'est lui qui compte : **aucun autre invariant ne tombe**, ni `:924` ni
`:932`. C'est ce qui distingue un arbre d'un artefact — un objet peut conserver le défaut
visé *et* en fabriquer d'autres.

**Et pourtant elle est écartée, par cet ADR même.** La règle 3 interdit la dérivation ; la
règle 2 refuse l'anonymisation par déformation, et préserver les longueurs en est une ; le
contexte nomme les **filiations** parmi ce qui n'entre pas — c'est-à-dire exactement la
structure que cette variante conserve intacte. Un relevé anonymisé est *un arbre dont on ne
peut plus nommer les personnes*, ce n'est pas *un arbre inventé*. L'argument « le dépôt est
privé » ne le sauve pas non plus : la **Décision** ci-dessus le pré-rejette en toutes lettres.

### Ce que la reconnaissance laisse d'utile à la piste conforme

Elle n'a pas été inutile : elle dit **ce qui porte le défaut**, donc ce qu'un générateur doit
reproduire.

- **Les rangs et les familles le portent ; les noms et les dates, non.** Le prédicat de
  `:780` ne lit que la position en rangée et la composition des fratries. C'est pourquoi le
  remplacement des identités ne change rien — et c'est la raison pour laquelle un arbre
  **inventé** peut le porter sans ressembler à personne.
- **La tolérance en position est large : ±100 unités par nœud.** Mesurée par secousse
  aléatoire déterministe sur les 648 abscisses : à ±1, ±5, ±20 et ±100, le seul échec reste
  `:780` ; à ±500 et au-delà, trois échecs — le relevé cesse d'être un arbre plausible. À
  comparer aux **20 unités** du plus petit écart entre deux suites voisines
  (`components/arbre/zones.ts`). **Une fixture n'a donc pas besoin de positions exactes.**
- **Le prédicat à reproduire est nommable** : huit couples dont les **deux** partenaires
  viennent d'une fratrie d'au moins trois, posés sur la même rangée, familles rompues
  exclues. Le titre du test promet plus large que ce qu'il vérifie.

**Le chemin conforme en découle** : générer l'arbre selon le tableau ci-dessus, faire tourner
le **vrai moteur de placement** dessus, et capturer sa sortie comme relevé. La disposition
vient alors du moteur — elle est une vraie disposition, et elle ne contient aucune donnée
réelle. C'était l'obstacle que l'on croyait rédhibitoire ; il ne l'est pas.

⚠ **Ce qu'il ne faut pas en conclure, et c'est le risque qui reste entier : rien ne garantit
qu'un arbre généré reproduise `:780`.** C'est le premier critère du cahier des charges, et il
ne se vérifie qu'une fois le générateur écrit. **Une tolérance large ne promet pas
l'apparition de la pathologie** — elle promet seulement qu'une fois apparue, elle survivra à
l'imprécision.

### Si une fixture porte un jour des largeurs dérivées d'étiquettes réelles

La largeur d'une fiche est déterministe :
`max(104, int(max(len(étiquette) × 6,6 , len(dates) × 5,8) + 28))`
(`Tree\Service\MesureDeFiche::largeur()`). Une largeur conservée est donc **une dérivée
de la longueur du nom**, et l'ADR 0003 tranche ce cas : *« tout champ dérivé d'une donnée
masquée est une donnée masquée »*. Ce dépôt a déjà appliqué cette phrase une fois — sans la
constante `vivant·e`, la largeur encoderait la longueur des dates d'une personne masquée
(`Tree\ValueObject\PersonnePlacable`).

**Arbitrage rendu : arrondir les largeurs à un pas de 20 unités.** Et la garantie s'écrit pour
ce qu'elle fait, pas plus : **elle ramène chaque largeur à l'une de six classes** (160, 180,
200, 220, 240, 260, pour 27 valeurs distinctes s'étalant de 152 à 260). Elle réduit
l'information, **elle ne l'annule pas**. Écrire « il n'y a plus de donnée dérivée à protéger »
serait une garantie plus forte que le geste, donc un ADR qu'on ne peut plus inspecter.

Le coût est nul : 20 unités contre une tolérance mesurée de ±100.

**Pourquoi pas une largeur unique**, qui annulerait l'information ? La mesure, et non la
déduction : `:780` **survit** à une largeur unique. Ce qui casse, ce sont deux invariants de
convergence du solveur — `ressorts.test.ts:924` et `:932`. On fabrique alors un graphe sur
lequel le moteur cesse de converger de façon monotone : un artefact, pas un arbre. Cet
argument est plus solide que « le défaut disparaîtrait », car il ne dépend pas du défaut
qu'on cherche à reproduire.

*(La tolérance de ±100 a été mesurée sur une secousse **aléatoire**. Un aplatissement des
largeurs est un décalage **systématique** : les deux ne se comparent pas, et c'est pourquoi le
cas systématique a été mesuré à part au lieu d'être extrapolé.)*

⚠ **Et il faut garder la hiérarchie dans le bon ordre.** L'arrondi ferme un canal
**secondaire**. Le canal principal est ailleurs : le relevé du 23/09/2026 portait, pour ses
92 personnes vivantes, un nom d'affichage, une étiquette de nom, une date de naissance et une
position, toutes en visibilité `complete` — la constante `vivant·e` ne neutralise que le côté
**décès**. Arrondir des largeurs ne rend pas un tel fichier publiable. **Ce qui l'en empêche,
ce sont les identités et les dates, pas les largeurs.**

**Deux nombres de personnes vivantes circulent, et les deux sont justes** — chacun pour son
objet, ce que le tableau ci-dessus fait déjà en nommant sa source :

```
graphe.json / arbre-romarin-v5.ged    274 personnes    ~18 vivantes
graphe servi, empreinte 91ad68db      468 personnes     92 vivantes
```

Les citer sans leur empreinte fabrique une contradiction qui n'existe pas. C'est arrivé trois
fois dans la même journée sur ce dépôt.

**Cette décision précède l'outil** : quiconque écrira la capture trouvera la règle avant
d'écrire la ligne, ce qui est l'ordre utile. Et elle est notée ici plutôt que dans le code
parce qu'au 23/09/2026 **il n'existe ni fixture ni outil qui en produise une** — les relevés
utilisés jusqu'ici sont posés à la main hors dépôt.

## Alternatives considérées

- **Dépôt privé avec les vraies données dedans.** L'option confortable. Écartée : historique
  permanent, surface d'accès large, et surtout **rien à y gagner** — l'application tourne sur
  la machine où le GEDCOM se trouve déjà.
- **Chiffrer les données dans le dépôt** (`git-crypt`, SOPS). Écartée : déplace le problème sur
  la gestion d'une clé que le commanditaire devra de toute façon sauvegarder ailleurs, et ne
  résout pas la CI — qui aurait besoin de la clé pour être utile, donc du secret dans le
  système d'intégration. Complexité réelle, gain nul.
- **Sous-module Git privé séparé** pour les données : même exposition, une indirection en plus.
- **Fixtures minimales**, une dizaine de personnes écrites à la main. Rapides, lisibles, et
  incapables de faire apparaître un seul des problèmes du tableau ci-dessus. Écartées : c'est
  exactement ce que cet ADR veut empêcher.
- **Se passer de fixtures** et exiger un import GEDCOM pour démarrer. Écartée : le dossier de
  mission demande que `docker compose up --build` suffise, et un projet qui ne démarre pas
  sans un fichier qu'on n'a pas est un projet que personne ne peut reprendre.

## Conséquences

- **L'import réel n'a aucune couverture CI.** On compense par des **chiffres de contrôle
  vérifiés par la commande d'import elle-même**, qui refuse de terminer si elle ne retombe pas
  dessus : 274 personnes, 103 familles, 12 générations, 18 vivantes, 224 années de naissance
  connues, **84 fiches appuyées sur un acte** — `count(*) FROM tree_person WHERE best_quay
  >= 2`, c'est ce que le dossier de mission appelle « 84 faits » —, 2 divorces, 4
  remariages, répartition par branche 134/64/51/20/5, racine `@I500001@`. S'y ajoutent, du
  même comptage : 174 filiations, 28 sources, 483 citations, 175 notes. Un import qui ne
  retombe pas exactement sur ces nombres est un import raté, et la commande doit le dire.

  > **Remplacé par l'[ADR 0018](0018-suppression-de-l-import-gedcom.md) (2026-10-06)** : ces totaux de contrôle sont partis avec l'import.
  Ils décrivent l'arbre **au jour de l'import initial**, pas la base de production, qui a
  évolué depuis par les demandes validées.
- Le test de non-régression du moteur de placement contre `graphe.json` **ne tourne qu'en
  local** (ADR 0005), et il y compare les positions **exactement** — on porte un algorithme
  déterministe dont on possède le code (`docs/moteur-retrouve/graphe.py`). La CI, elle, n'a
  pas d'étalon : elle ne vérifie que des **invariants** sur les fixtures. Couverture plus
  faible, assumée — et **la CI peut donc passer au vert sur un portage faux**.
- **Aucune capture d'écran de vraies personnes** dans une pull request, un ticket ou un message
  de commit. Une capture de l'arbre réel est une fuite, au même titre qu'un fichier commité.
- Les médias réels vivent dans `uploads/`, hors dépôt et hors racine web, et font partie de la
  sauvegarde au même titre que la base.
- Les fixtures sont du code de production au sens du soin qu'on leur porte : elles sont la
  seule chose sur laquelle la CI puisse juger l'application.
- **Révision du 2026-09-15 — le cahier des charges a été recompté sur les données réelles.**
  Six cibles étaient fausses ou ambiguës : « ~14 sources » (il y en a 28), « faits appuyés
  sur un acte » là où le contrôle porte sur des *fiches*, un critère de date incertaine
  (`an_nais` ≠ `an_nais_max`) qui, transposé aux colonnes du modèle, aurait été vrai pour
  les trois quarts de l'arbre sans qu'une seule vraie fourchette existe, et trois planchers
  (fratries, branches ouvertes, personnes sans date) trop bas pour reproduire la topologie
  qu'ils étaient censés garantir. Motif de la révision : ce sont des **mesures**, et un
  cahier des charges de fixtures dont les nombres ne viennent pas de la donnée ne reproduit
  pas ses pathologies — c'est-à-dire ne sert à rien. Détail dans `docs/arbitrages.md`.
