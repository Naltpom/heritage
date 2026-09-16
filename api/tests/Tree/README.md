# Tests du moteur de placement

Le moteur (`Tree\Service\MoteurDePlacement`) est un **portage** de
`docs/moteur-retrouve/graphe.py`, lignes 391-612. Ses tests se répartissent en deux
familles, qui ne prouvent pas la même chose.

| Test | Tourne en CI | Ce qu'il prouve |
|---|---|---|
| `PlacementNonRegressionTest` | **non** | l'égalité **exacte** avec `graphe.json` — le **moteur** est un portage fidèle |
| `PlacementFournisseurDoctrineTest` | **non** | le **fournisseur** lit et ordonne juste (base reconstruite depuis `graphe.json`) |
| `PlacementBaseImporteeTest` | **non** | l'**import** écrit ce que le placement attend (base issue du GEDCOM) |
| `PlacementInvariantsTest` | oui | des invariants sur une topologie inventée — un filet, pas une preuve |
| `PlacementProjectionTest` | oui | l'étage 2 n'insère que localement et ne réorganise rien |

Les trois premiers ne se remplacent pas : chacun isole une couche, et c'est leur
superposition qui prouve la chaîne, du fichier GEDCOM aux coordonnées du dessin.

Ce que couvre le second et que le premier ne voit pas : l'ordre des lignes, la
reconstruction des adjacences, l'étiquette des dates et le calcul de l'état vivant. C'est
ainsi qu'a été trouvée la collision entre `tree_person.id` et `tree_family.id`, invisible
sur les références GEDCOM `@I…@` / `@F…@`.

Ce que couvre le troisième et que les deux autres ne voient pas : un écart entre ce que
l'import **écrit** et ce que `graphe.json` **contient**. Il échapperait aux totaux de
contrôle de l'import — qui comptent des lignes, pas des largeurs — comme au test de
non-régression, qui ne lit jamais la base.

Le troisième a besoin de PostgreSQL et de `pdo_pgsql`, que le PHP de la machine ne charge
pas par défaut :

```
php -d extension=pdo_pgsql vendor/bin/phpunit tests/Tree/PlacementBaseImporteeTest.php
```

Il vise la base de `compose.yaml` ; `HERITAGE_DSN_REEL` en désigne une autre. **Lecture
seule** : il n'écrit rien, ne crée aucun schéma, ne vide aucune table.

> **La CI peut passer au vert sur un portage faux.** Elle n'a aucun étalon à comparer :
> `graphe.json` n'est pas versionné (ADR 0006). C'est écrit ici parce que personne ne le
> devinera en lisant un tableau de bord vert.

## Relancer le test de non-régression

```
cd C:/www/heritage/api
vendor/bin/phpunit --filter Placement
```

Il lit `graphe.json` dans l'atelier généalogique, **en lecture seule**, à l'emplacement
conventionnel voisin de la racine du projet : `C:\www\myHeritage\graphe.json`.

Si l'atelier est ailleurs, le chemin se configure — jamais en dur, l'atelier n'existe pas
sur toutes les machines :

```
HERITAGE_GRAPHE_REFERENCE=/chemin/vers/graphe.json vendor/bin/phpunit --filter Placement
```

Quand le fichier est absent, les tests de non-régression sont **sautés** et non en échec,
et les tests d'invariants tournent quand même. C'est le comportement attendu hors de la
machine du commanditaire.

Pour n'exécuter que ce qui dépend des données réelles :

```
vendor/bin/phpunit --group donnees-reelles
```

## Résultat consigné

ADR 0005 : *« Le test d'égalité exacte doit être lancé au moins une fois avant la mise en
service, sur la machine du commanditaire, et son résultat consigné. »*

**2026-09-15, poste du commanditaire, PHP 8.4.4, PHPUnit 13.3.4 :**

```
Placement — depuis la base importée : 377/377 nœuds exacts.
Placement — via le fournisseur Doctrine : 377/377 nœuds exacts.
Placement — non-régression : 377/377 nœuds exacts.
                                                  39 / 39 (100%)

OK (39 tests, 3238 assertions)
```

377 nœuds exacts sur 377 (`x`, `y`, `w`, `h`), **par les trois chemins** — depuis
`graphe.json`, depuis une base reconstruite, et depuis la base réellement produite par
`heritage:import-gedcom`. 377 arêtes identiques dans l'ordre, boîte englobante identique au
bit près (`-7770.390869140625 -1730 15824 1810`), ordre des nœuds identique.

Vérifié au passage sur les 274 personnes réelles : la règle « personne vivante » du § 9.2
du modèle de données reproduit exactement les 18 `RESN privacy` du GEDCOM, les douze
médianes par génération qu'elle publie sont retrouvées, et la fourchette de naissance de
Marne Dolomite Sureau (`@I500017@`) traverse l'import sans être aplatie.

> **La référence GEDCOM est réelle et littérale, à dessein.** Le brief d'anonymisation
> demandait de renuméroter les xrefs ; **cette consigne a été annulée** (§ 9.1) : un xref est
> un numéro opaque, il ne désignait quelqu'un qu'accolé à une date, et cette date a quitté le
> dépôt par variable d'environnement. Si vous trouvez le brief et croyez à un oubli : ce n'en
> est pas un. Ne pas « réparer ».

**La chaîne serveur est donc prouvée de bout en bout**, du fichier GEDCOM aux coordonnées
du dessin.

## En cas d'écart

**Ne pas élargir la tolérance.** Les 0,01 du test ne couvrent que la représentation des
flottants ; un écart réel se compte en dizaines de pixels. C'est exactement ce que
l'ADR 0005 cherche à empêcher : un test vert obtenu en desserrant le seuil ne prouve plus
rien.

Les quatre causes connues, dans l'ordre où il faut les regarder — elles sont détaillées
en tête de `Tree\Service\CalculDePlacement` :

1. **l'ordre de parcours**, qui départage deux nœuds à égalité de position ;
2. **la mesure du texte en octets au lieu de caractères** ;
3. **un identifiant relu depuis une clé de tableau**, que PHP a converti en entier ;
4. **`an_nais or 9999` traduit en `?? 9999`**, qui diffère quand l'année vaut `0`.

Reste la division flottante du barycentre : elle doit accumuler dans le même ordre et ne
diviser **qu'une fois, à la fin**.
