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
