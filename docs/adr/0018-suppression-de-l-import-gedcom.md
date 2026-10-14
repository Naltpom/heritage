# ADR 0018 — Suppression de l'import GEDCOM (et de l'export jamais écrit)

Date : 2026-10-06 · Statut : acceptée

## Contexte

L'application a été peuplée une fois, à sa mise en service, par la commande
`heritage:import-gedcom` (module `Gedcom`, ADR 0002) depuis `arbre-romarin-v5.ged`. Depuis,
la vie de l'arbre est ailleurs : les membres proposent, les administrateurs valident
(ADR 0004), et la base de production porte des fiches, des familles, des citations, des
notes et des médias **qui n'existent dans aucun fichier GEDCOM**. Les données réelles sont
**déjà en production**, et c'est leur seul exemplaire à jour.

L'import, lui, restait une porte ouverte vers l'écrasement :

- il était **idempotent par `gedcom_xref`** — ce qui veut dire qu'un second passage retrouvait
  chaque personne, famille et source par sa référence et **la réécrivait depuis le fichier**.
  Tout ce qu'une demande validée avait corrigé sur une fiche venue du GEDCOM (un nom, une
  date, une source) revenait à la valeur du fichier, **sans demande, sans révision, sans
  ligne au journal** — le souscripteur d'audit ne journalise pas hors requête HTTP ;
- ses gardes (`GardeDesComptes`, les totaux de contrôle de `TotauxAttendus`) protégeaient
  contre un fichier incomplet ou un schéma vidé sous des comptes vivants, **pas contre un
  fichier plus ancien que la base** — le cas le plus probable, puisque le GEDCOM n'est plus
  tenu à jour ;
- l'**export**, annoncé dès l'ADR 0001 (« l'application l'importe et sait le
  ré-exporter ») et réservé dans le schéma (`ged_run.direction = 'export'`), n'a jamais été
  écrit, pas plus que les routes d'administration `/api/administration/gedcom/*` décrites
  au § 10.6 du contrat.

Le commanditaire a tranché : *« Import GEDCOM, supprime-le. L'export n'est pas utile du coup.
Garde bien cela dit les références GEDCOM. »*

## Décision

1. **Plus d'import GEDCOM.** Le module `Gedcom` est supprimé en entier : la commande
   `heritage:import-gedcom`, l'analyseur, l'écrivain, les gardes, les entités `Run` et `Issue`,
   leurs dépôts, objets de transfert et énumérations, ses fichiers `config/routes/gedcom.yaml`
   et `config/services/gedcom.yaml`, son espace de mapping Doctrine, la variable
   `GEDCOM_FILE`, les actions d'audit `import_gedcom` / `export_gedcom` qu'aucun code
   n'écrivait, et les méthodes `Sex::gedcom()` / `FactType::gedcom()` qui ne servaient qu'à
   l'import et à l'export.
2. **Pas d'export non plus.** Ni commande ni route ; les routes d'administration
   `/api/administration/gedcom/*` et le code d'erreur `import_en_cours` sortent du contrat.
3. **Les tables `ged_run` et `ged_issue` sont supprimées** par une migration nouvelle,
   `Version20261014090000` — jamais en retouchant `Version20260915170602`, déjà jouée. Elles ne
   portaient que le journal des exécutions et des anomalies de lecture ; aucune clé étrangère
   n'y entrait depuis un autre module.
4. **Les références GEDCOM restent**, comme identifiants stables :
   - les colonnes `tree_person.gedcom_xref`, `tree_family.gedcom_xref`,
     `tree_source.gedcom_xref` et leurs contraintes d'unicité (`uniq_tree_*_xref`), ainsi que
     `gedcom_uid` / `gedcom_rin` ;
   - l'attribution d'une référence à toute personne ou famille **créée dans l'application**
     (`@I9xxxxx@`, `@F9xxxxx@`, § 9.8 du modèle — `Tree\Service\EcritureDeLArbrePourProposal`) ;
     une source créée à l'écran garde, elle, `gedcom_xref = null` (ADR 0012) ;
   - leur sérialisation (`gedcomXref` dans la fiche, la famille, la source) et leur affichage
     au front (« Référence GEDCOM », réservé à l'administrateur) ;
   - le rattachement d'amorçage par référence (`HERITAGE_ADMIN_PERSONNE_XREF`).

   Une référence n'est plus la clé d'un ré-import : c'est **le nom stable d'une ligne**, qui
   ne change ni à la restauration d'une sauvegarde ni d'une base à l'autre, là où
   `tree_person.id` est une identité qu'un rechargement sur base neuve renumérote.

## Alternatives considérées

- **Garder l'import derrière une garde plus forte** (refuser tout passage sur un arbre non
  vide). Écartée : la seule utilisation légitime restante serait le premier peuplement d'une
  base neuve, et la restauration d'un dump le fait mieux — elle rend l'arbre **tel qu'il est**,
  demandes validées comprises, au lieu de l'arbre tel qu'il était dans un fichier.
- **Écrire l'export.** Écarté par le commanditaire : sans import, un GEDCOM sortant ne
  servirait qu'à alimenter webtrees ou Gramps Web, dont la saisie ne revient plus dans
  l'application (ADR 0001, Conséquences). Le jour où un export servira, il s'écrira contre
  le modèle de ce jour-là, sans dette héritée.
- **Garder `ged_run` / `ged_issue` comme archive.** Écarté : un journal d'exécutions d'une
  commande qui n'existe plus n'a ni lecteur ni écran ; le dump antérieur à la migration le
  garde pour qui en aurait besoin.

## Conséquences

- **Où vivent les données réelles.** Dans la base PostgreSQL de production, et dans ses
  sauvegardes — le service `sauvegarde` de `compose.prod.yaml` écrit un `pg_dump` et une
  archive des téléversements au même horodatage. Le GEDCOM d'origine n'est plus une source :
  c'est une archive, hors dépôt (`*.ged` reste ignoré par `.gitignore`).
- **Comment on reprend les données réelles** sur une machine neuve, ou après un incident :
  on **restaure un dump** (procédure écrite au-dessus du service `sauvegarde`), puis on
  redémarre — l'entrypoint rejoue les migrations manquantes, dont celle qui supprime
  `ged_run` et `ged_issue` si le dump est plus ancien qu'elle. Il n'y a pas d'autre chemin
  pour re-peupler l'arbre réel.
- **Une installation neuve part de l'arbre d'essai anonymisé** : onze personnes inventées,
  cinq familles (`App\DataFixtures\ArbreDEssaiFixtures`, groupe `essai`), chargées par
  `doctrine:fixtures:load --group=essai --append` sur une base migrée. Il remplace le fichier
  `api/tests/Gedcom/fixtures/arbre-essai.ged` que la CI passait jusqu'ici à l'import, et
  ensemence désormais la base PostgreSQL de la CI. **Il ne remplit pas le cahier des charges
  de l'ADR 0006** (274 personnes, 12 générations, pathologies mesurées) : ce générateur
  n'existe toujours pas, et cet arbre n'en tient pas lieu. La fixture **refuse un arbre déjà
  peuplé** : elle ne peut pas mêler des personnes inventées aux vraies.
- **Ce qui disparaît avec l'import et ne se rattrape pas** : les totaux de contrôle de
  l'import (ADR 0006, Conséquences), le test `PlacementBaseImporteeTest` (le placement sur la
  base produite par l'import) et le journal `ged_issue` des anomalies du fichier. La base de
  production n'est de toute façon plus l'image du GEDCOM : elle a évolué par les demandes.
- **Frontières (ADR 0002).** La dette datée `Gedcom\Service\EcrivainTree` n'est pas soldée
  par le port `Tree\Contract\PortImportArbre` : elle disparaît avec son code. Les 22
  exemptions du module `Gedcom` quittent `tests/Frontieres/ExemptionsDeFrontiere.php`, et la
  trajectoire passe de « 46 → 18 → 8 → 0 » à **« 24 → 8 → 0 »**. Le schéma compte 43 tables
  au lieu de 45 ; les modules sont `Core`, `Tree`, `Membership`, `Proposal`, `Media`,
  `Timeline`, `Question`, `Tirage`, `Demarche`, `Evenement`, `Almanach`, `Assistant`,
  `Statistiques`.
- **Une révision sans demande** (`prp_revision.request_id` nul) ne signale plus « l'import » :
  aucun chemin d'écriture n'en produit — l'import n'en écrivait pas non plus. Elle ne peut
  venir que d'une demande effacée hors de l'application ; l'historique d'une fiche l'affiche
  « Sans demande associée ».
- **Les ADR antérieurs ne sont pas réécrits.** Les passages qu'il remplace — ADR 0001 (source
  de vérité amont, ré-export), 0002 (module `Gedcom`, dette `EcrivainTree`, trajectoire),
  0004 (`request_id` nul), 0006 (import par commande locale, totaux de contrôle) et 0012
  (`gedcom_xref` clé du ré-import) — portent une note qui renvoie ici.
