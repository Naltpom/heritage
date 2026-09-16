# Portage du moteur de placement — Python → PHP

Compte rendu du portage de `docs/moteur-retrouve/graphe.py` (624 lignes, algorithme de
placement aux lignes 391-612) vers `App\Module\Tree`.

**Résultat : 377/377 nœuds exacts.** Le critère d'acceptation de l'ADR 0005 est atteint.

```
### vendor/bin/phpunit --filter Placement
PHPUnit 13.3.4 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.4.4
Configuration: C:\www\heritage\api\phpunit.dist.xml

..........
  Placement — non-régression : 377/377 nœuds exacts.
.................                                       27 / 27 (100%)

Time: 00:00.092, Memory: 24.00 MB

OK (27 tests, 2460 assertions)
```

377 nœuds exacts sur `x`, `y`, `w`, `h` ; 377 arêtes identiques **dans l'ordre** ; ordre
des nœuds identique ; boîte englobante identique au bit près
(`-7770.390869140625 -1730 15824 1810`). `vendor/bin/phpstan analyse` : **0 erreur**.

---

## 1. Le cache à deux étages

Trois décisions ont été arbitrées en amont. Leur violation ne se verrait pas — c'est
pourquoi elles sont écrites ici *et* en commentaire à l'endroit du code qui les applique.

### 1.1 L'empreinte ne porte que les données structurantes

**`tree_person`, `tree_family`, `tree_child`. Ces trois tables, et aucune autre.**

Ni `med_media`, ni `tree_citation`, ni `tree_source`, ni `tree_fact`, ni `tree_note` :
rien de tout cela ne déplace un nœud ni ne modifie une parenté. **Une photo téléversée ne
bouge aucune fiche** ; inclure `med_media` dans l'empreinte ferait recalculer les
377 nœuds *et* tous les cercles de visibilité à chaque envoi de fichier, pour un graphe
rigoureusement identique.

C'est le défaut symétrique de celui du compteur : non plus invalider trop peu, mais
**trop**. « Élargir l'empreinte par prudence » est le réflexe naturel de qui
l'implémente ; c'est ici une erreur, et elle se déguise en rigueur.

> **Le test à s'appliquer avant d'ajouter une table à cette requête** : *cette écriture
> peut-elle déplacer un nœud ou changer une parenté ?* Si non, elle n'a rien à y faire.

Où c'est appliqué : `Tree\Service\TreeFingerprint::REQUETE` — la requête du § 2.3 du
modèle de données, littéralement, sur trois tables.

Elle hache `p::text`, c'est-à-dire la **ligne entière**, toutes colonnes comprises. Aucune
écriture ne peut donc lui échapper — pas même un correctif SQL à chaud qui oublierait de
toucher `updated_at`. Une liste de colonnes tenue à la main perdrait cette garantie à la
première migration.

### 1.2 L'invalidation se fait par recalcul de l'empreinte, jamais par un compteur

**Il n'existe ni table, ni colonne, ni compteur d'invalidation de cache.**

> *Une empreinte ne peut pas se désynchroniser ; un compteur qu'on oublie d'incrémenter
> dans un chemin d'écriture donne un graphe faux qu'on met des semaines à comprendre.*

Ce projet a **cinq chemins d'écriture**, et c'est ce qui condamne le compteur :

1. la validation d'une `prp_change_request` ;
2. l'édition directe d'un administrateur (une demande auto-validée, § 9.4) ;
3. l'import GEDCOM ;
4. le changement de `tree.personne_racine_id`, qui recalcule `branch` et `generation` ;
5. toute correction faite directement en base.

Un compteur exigerait que les cinq pensent à l'incrémenter — et que le sixième, le jour où
il sera ajouté, y pense aussi. Cette liste exhaustive à tenir à jour *était* la dette :
chaque nouveau chemin d'écriture est une occasion d'oubli, et un oubli produit un cache
périmé silencieux, c'est-à-dire une fuite vers un compte qui n'aurait plus dû voir la
fiche.

**Aucun de ces cinq chemins n'a besoin de penser à quoi que ce soit** : l'empreinte est
dérivée de la donnée, donc elle a déjà changé quand ils ont écrit.

L'atomicité, qui était l'argument du compteur, est acquise autrement. Tant que la
transaction n'est pas validée, les autres connexions lisent l'ancien état, donc l'ancienne
empreinte, donc l'ancien cache — qui est juste. Au `commit`, elles lisent le nouvel état,
donc une nouvelle clé. Il n'y a pas de fenêtre intermédiaire parce qu'il n'y a **rien à
synchroniser**. Si la transaction est annulée, il n'y a rien à annuler côté cache.

Corollaire : **on ne supprime jamais une clé de cache à la main.** Un balayage de clés
n'est pas atomique sur un adaptateur système de fichiers. Les entrées devenues
inatteignables sont ramassées par le TTL de 24 h.

Où c'est appliqué : `Tree\Service\PlacementPartage::cle()` rend
`placement.{empreinte}` — **aucun identifiant de lecteur dans la clé**. Le pool
`cache.arbre` est déclaré dans `api/config/services/tree.yaml`, avec le module qui le
possède.

### 1.3 L'étage 2 n'est jamais mis en cache

La projection pour un lecteur — filtre de sérialisation + calque « en attente » — n'est
pas mise en cache, et pas seulement parce qu'elle est bon marché.

**Une clé par lecteur serait fausse.** Elle ne changerait qu'avec l'empreinte,
c'est-à-dire à une modification **validée** de l'arbre. Or déposer un brouillon n'en est
pas une : `prp_draft_person` et `prp_draft_link` ne sont pas des tables `tree_*`, donc
l'empreinte ne bouge pas. L'auteur d'une proposition conserverait son entrée de cache
antérieure et **ne verrait pas son propre nœud en attente** — c'est-à-dire précisément la
seule chose que ce calque existe pour produire.

C'est le genre d'erreur qu'un cache rend invisible en développement, où l'on vide le cache
sans y penser, et systématique en service.

Le coût, accessoirement, ne la justifierait pas non plus : un parcours de 377 nœuds et
quelques insertions, de l'ordre de 0,02 ms. La mettre en cache par compte multiplierait le
stockage par le nombre de comptes pour économiser moins d'une milliseconde.

Où c'est appliqué : `Tree\Service\ProjecteurDePlacement` n'a **aucune dépendance de
cache** — ni `CacheInterface`, ni pool, ni TTL. L'absence est structurelle, pas une
omission à combler.

C'est l'**`ETag`** qui porte le lecteur, et non la clé de cache :
`sha256(empreinte || ':' || empreinte des demandes en attente du lecteur)`, la seconde
valant la chaîne vide pour qui n'a aucune demande en cours — il partage alors l'`ETag` de
tout le monde. Implémenté dans `TreeFingerprint::etag()`.

### 1.4 Pourquoi l'étage 1 peut être partagé

Parce que **le cercle de visibilité masque des champs, pas des nœuds** (ADR 0003). Le
placement ne dépend donc pas du lecteur.

L'objet `Placement` ne porte d'ailleurs **aucune donnée personnelle** : des identifiants
et des coordonnées, rien d'autre. Il n'y a littéralement rien à y filtrer.

Deux garde-fous à connaître :

- **L'objet de l'étage 1 ne sort jamais tel quel de l'API.** C'est une structure interne,
  servie uniquement à travers l'étage 2. Le « calculé sans filtre de confidentialité »
  porte sur le calcul ; il n'ouvre aucun mode « sans filtre » dans la sérialisation, que
  l'ADR 0003 interdit.
- **L'état vivant est une propriété de la personne, jamais du lecteur.** La largeur d'une
  fiche est donc identique pour tous. Si quelqu'un se retrouve à vouloir une largeur qui
  dépende du cercle du lecteur, il faut s'arrêter : ce serait un placement par compte, et
  l'étage 1 disparaîtrait avec.

---

## 2. Relancer le test de non-régression

```
cd C:/www/heritage/api
vendor/bin/phpunit --filter Placement
```

Le test lit `graphe.json` dans l'atelier généalogique, **en lecture seule**, à
l'emplacement conventionnel voisin de la racine du projet :
`C:\www\myHeritage\graphe.json`.

Si l'atelier est ailleurs — jamais de chemin en dur, il n'existe pas sur toutes les
machines :

```
HERITAGE_GRAPHE_REFERENCE=/chemin/vers/graphe.json vendor/bin/phpunit --filter Placement
```

Pour n'exécuter que ce qui dépend des données réelles :

```
vendor/bin/phpunit --group donnees-reelles
```

Quand le fichier est absent, les tests de non-régression sont **sautés et non en échec**,
et les tests d'invariants tournent quand même. C'est le comportement attendu hors de la
machine du commanditaire.

### Pourquoi il ne tourne pas en CI — et ce que ça coûte

`graphe.json` décrit 274 personnes réelles, dont des vivantes. Il **n'est pas versionné**
(ADR 0006, § 9 du dossier de mission). La CI n'en dispose donc pas.

> ### ⚠️ La CI peut passer au vert sur un portage faux.
>
> Elle n'a **aucun étalon** à comparer. Elle ne peut vérifier que des invariants sur une
> topologie anonymisée : générations aux bons rangs, ordre des fratries préservé, aucun
> chevauchement sur une rangée, ego à `x = 0`, boîte cohérente. C'est une couverture plus
> faible, assumée — et c'est le seul endroit où les invariants subsistent : ils ne sont
> **plus le critère d'acceptation du portage**, seulement le filet de la CI.
>
> Un tableau de bord vert ne prouve donc pas que le moteur est juste. **Le test d'égalité
> exacte doit être lancé au moins une fois avant la mise en service**, sur la machine du
> commanditaire, et son résultat consigné. C'est écrit ici parce que personne ne le
> devinera en lisant la CI.

| Test | En CI | Ce qu'il prouve |
|---|---|---|
| `PlacementNonRegressionTest` | **non** | l'égalité **exacte** avec `graphe.json` — la preuve du portage |
| `PlacementInvariantsTest` | oui | des invariants sur une topologie inventée — un filet, pas une preuve |
| `PlacementProjectionTest` | oui | l'étage 2 n'insère que localement et ne réorganise rien |

### Résultat consigné

**2026-09-15, poste du commanditaire, PHP 8.4.4, PHPUnit 13.3.4 :** 377/377 nœuds exacts,
377 arêtes identiques dans l'ordre, boîte englobante identique, ordre des nœuds identique.
27 tests, 2 460 assertions, `OK`. Sortie complète en tête de ce document.

### En cas d'écart

**Ne pas élargir la tolérance.** Les 0,01 du test ne couvrent que la représentation des
flottants ; le plus petit écart réel observé pendant le portage se comptait en dizaines de
pixels, jamais en centièmes. Un test vert obtenu en desserrant le seuil ne prouve plus
rien — c'est exactement ce que l'ADR 0005 cherche à empêcher.

Les causes connues sont en § 3, et en tête de `Tree\Service\CalculDePlacement`.

---

## 3. La méthode, et les cinq pièges de portage

### 3.1 Rejouer le script en Python avant d'écrire du PHP

Avant toute ligne de PHP, l'algorithme a été **rejoué en Python en ne lisant que
`graphe.json`**. Ça a séparé deux questions qui se seraient confondues — « le JSON
contient-il assez d'entrée ? » et « mon PHP est-il juste ? » — et ça a produit la trouvaille
qui rendait l'exactitude atteignable :

> **`gens[k]["enfants"]` et `gens[k]["conjoints"]` sont écrits `sorted(set(…))` à la fin du
> script, APRÈS le placement.** Ce ne sont pas les tables que le moteur a utilisées.

Les lire à la place des adjacences brutes déplace des nœuds, parce que le repli « je me
raccroche à `conjoints[k][0]` » de la phase 2 lit la **première union rencontrée**, pas la
première par identifiant. Le débogage aurait été cauchemardesque : la donnée *paraît* être
la bonne.

La conséquence pour l'implémentation : les trois tables d'adjacence sont **reconstruites
depuis `familles`, dans leur ordre**, exactement comme le fait `graphe.py`.

Ça a aussi établi le contrat d'entrée : `gens` et `familles` sont sérialisés **dans l'ordre
du GEDCOM** (`json.dump` conserve l'ordre d'insertion des dictionnaires Python), et cet
ordre est une **entrée à part entière** du moteur.

### 3.2 Les cinq pièges

Chacun déplace réellement des nœuds. **Aucun ne serait vu par un test à ± 10 %** — c'est
pour eux que le critère est exact.

**1. L'ordre de parcours départage les égalités.** Deux nœuds à la même position sont
départagés par l'ordre du GEDCOM. Python trie de façon *stable* et itère ses dictionnaires
dans l'ordre d'insertion ; PHP aussi, mais s'y fier revient à parier sur un détail
d'implémentation. Les deux tris que le script laisse à la stabilité
(`sorted(reste, key=gen)` et `sorted(par_gen[g], key=posx)`) sont écrits avec **le rang
d'origine en critère de départage explicite**.

**2. La division flottante du barycentre** (`sum / len`) accumule **dans le même ordre** et
ne divise **qu'une fois, à la fin**. Arrondir à chaque passe ne donne pas le même résultat
après 12 itérations.

**3. `len()` compte des caractères, `strlen()` compte des octets.** « vivant·e » fait
8 caractères et 9 octets ; « 1.1.1900 – 31.12.1980 » en fait 21 et 23. En octets, ces deux
fiches sortent à **133 et 161 au lieu de 113 et 149**, et personne ne saurait pourquoi.

> On mesure avec **`iconv_strlen()` et non `mb_strlen()`** : `ext-iconv` est une dépendance
> ferme déclarée dans `composer.json`, `ext-mbstring` ne l'est pas. Sur une machine sans
> mbstring, le moteur tomberait au lieu de se tromper — ce qui est mieux, mais évitable.

Quatre valeurs sont verrouillées par `testLaLargeurSeMesureEnCaracteresPasEnOctets`.

**4. Ne jamais relire un identifiant depuis une clé de tableau.** PHP convertit
silencieusement la clé `"12"` en entier `12`, et les identifiants de l'application seront
ceux de `tree_person`, donc numériques. Un `strcmp()` recevrait alors un entier et lèverait
une `TypeError` en mode strict. On indexe par identifiant pour la recherche en O(1), mais
l'**ordre** est toujours porté par une liste de chaînes séparée, jamais par `array_keys()`.

**5. `an_nais or 9999` n'est pas `an_nais ?? 9999`.** En Python, `0` est faux autant que
`None`. Le cas est improbable, la traduction paresseuse est fausse, et elle ne se verrait
que sur une donnée aberrante.

### 3.3 Le bug que le test a attrapé

Le test de projection, écrit **avant** le code, a trouvé un défaut que personne n'aurait
cherché après coup : le filtre de poussée de l'étage 2 était `x > nouveau->x`, **strict**.
Deux nœuds tombant **exactement** à la même abscisse — c'est-à-dire précisément le cas où
ils se superposent — échappaient à la poussée. Corrigé en partageant la rangée en deux
camps disjoints autour de l'abscisse visée.

---

## 4. La largeur des fiches : reproduire fidèlement *est* la mesure de sécurité

Une revue de confidentialité a soupçonné une fuite : `w` étant calculée sur le texte
affiché, la largeur d'une fiche de personne vivante trahirait la longueur de ses dates
masquées.

**La fuite n'existe pas.** `ligne_dates()` court-circuite sur le drapeau privé et rend la
**constante littérale « vivant·e »**. La largeur ne dépend donc que du nom, qu'on envoie de
toute façon à tout le monde.

> **Ne rien « corriger » à cet endroit.** Conserver la constante exactement comme
> l'original la produit ferme la fuite **et** satisfait le critère d'égalité exacte par le
> même geste. Un agent qui aurait voulu assainir ce calcul aurait cassé les 377/377 en
> croyant bien faire.

C'est le cas général du portage : **l'original fait autorité**. Ce qui ressemble à une
maladresse — un arrondi curieux, un tri instable, une constante magique — peut porter une
décision qu'on ne voit pas, et le critère d'égalité exacte existe pour que ces décisions
survivent. Si quelque chose semble franchement faux : le signaler, pas le corriger en
silence.

Verrouillé par `testUnePersonneVivanteNExposePasLaLongueurDeSesDates`, qui vérifie le
**comportement** (deux personnes vivantes aux dates différentes ont la même largeur) et non
la constante.

`MesureDeFiche::ligneDates()` est **publique** : le sérialiseur peut émettre le
`ligneDates` du nœud — l'étiquette sur laquelle `w` a été mesurée — sans jamais le
recalculer, ce qui garde les deux cohérents.

---

## 5. Ce qui n'a pas été reproduit à la lettre

Trois écarts, tous couverts par les 377/377 :

1. **`rangFratrie()` est mémoïsée**, là où le script recalcule à chaque appel. Fonction
   pure sur des entrées figées : même résultat, beaucoup moins de tris.
2. **Un filtre défensif `connus()`** ignore un identifiant de famille qui ne correspond à
   aucune personne, là où le script lèverait une `KeyError`. No-op sur les données réelles
   — tous les identifiants de `familles` existent dans `gens`, vérifié — il évite qu'une
   fixture mal construite fasse tomber le moteur.
3. **`MesureDeFiche` est extraite du moteur**, pour que les nœuds en attente de l'étage 2
   soient mesurés par le même code : deux formules de largeur dans le produit finiraient
   par diverger. Pur déplacement de code, test relancé avant et après.

Et deux endroits où la tentation d'« améliorer » a été écartée, commentés sur place comme
volontaires :

- **`parents[c] = …` écrase** si un enfant appartient à deux familles : la dernière
  rencontrée gagne. Reproduit à l'identique.
- **`largBloc` ne mémoïse pas le retour de son garde-fou** de consanguinité : il dépend du
  chemin parcouru, pas de l'identifiant seul. Le mémoïser figerait une valeur partielle.

---

## 6. Points à traiter hors du périmètre du portage

### 6.1 ~~`FournisseurDeJeuDePlacement` n'a pas d'implémentation~~ — livrée depuis

Voir le § 8. Les deux règles qui bloquaient — le rendu compact des dates et le calcul
« personne vivante » — ont été portées et vérifiées contre les données réelles.

### 6.2 PHPStan dépasse la limite mémoire par défaut

`vendor/bin/phpstan analyse` sort désormais « Result is incomplete because of severe
errors » : le processus atteint le `memory_limit` de 128 Mo à mesure que le dépôt grossit.
**Ce n'est pas une erreur de code.** Avec `--memory-limit=1G` : `[OK] No errors`.

À figer dans `phpstan.dist.neon` ou dans la CI **avant que quelqu'un ne le prenne pour un
vrai échec**.

### 6.3 Imprécision dans `docs/api-contract.md`

Ligne 933, le contrat écrit que `w` est « **arrondi** à l'entier ». `graphe.py` fait
`int()`, c'est-à-dire une **troncature vers zéro**. Ce n'est pas la même opération :

> « Éon Célestène » → `13 × 6,6 + 28 = 113,8` → **troncature 113**, arrondi **114**.

113 est la valeur du fichier de référence, et celle que le test verrouille. Un second
implémenteur qui lirait « arrondi » et écrirait `round()` produirait des fiches d'un pixel
trop larges sur une bonne partie de l'arbre, silencieusement. Le mot juste est « tronqué à
l'entier ».

Le reste du § 933-941 du contrat est **conforme** à `graphe.py`, y compris les deux points
délicats : `nom` est *premier prénom + nom* et non `displayName` entier, et une personne
vivante rend la constante `"vivant·e"`.

### 6.4 Suite de tests complète

66 échecs au dernier relevé, **aucun dans `Tree`** : 34 `Core`, 32 `Proposal`,
essentiellement des tables absentes (`no such table: core_user`, `prp_draft_person`) sur des
modules encore en cours d'écriture. `tests/Media/VisibiliteDeTest.php` déclare une classe
qui n'étend pas `TestCase` (avertissement PHPUnit).

---

## 7. Fichiers livrés

**`api/src/Module/Tree/Service/`**

| Fichier | Rôle |
|---|---|
| `MoteurDePlacement.php` | l'API publique et les constantes ; sans état |
| `CalculDePlacement.php` | **le portage** ; les cinq pièges sont documentés en tête de classe |
| `MesureDeFiche.php` | la largeur d'une fiche, partagée avec l'étage 2 |
| `ProjecteurDePlacement.php` | **étage 2** : insertion locale des nœuds en attente |
| `PlacementPartage.php` | **étage 1** : le cache partagé |
| `TreeFingerprint.php` | l'empreinte de l'arbre et l'`ETag` |
| `FournisseurDeJeuDePlacement.php` | le contrat de chargement (implémentation à venir) |

**`api/src/Module/Tree/ValueObject/`** — `JeuDePlacement`, `PersonnePlacable`,
`FamillePlacable`, `Placement`, `NoeudPlace`, `AreteDePlacement`, `BoiteEnglobante`,
`NoeudEnAttente`, `AncrageEnAttente`.

**`api/tests/Tree/`** — `PlacementNonRegressionTest`, `PlacementInvariantsTest`,
`PlacementProjectionTest`, plus `ChargeurDeGrapheDeReference` et `ArbreSynthetique`, et un
`README.md` qui reprend la procédure du § 2.

**`api/config/services/tree.yaml`** — le pool `cache.arbre` et la liaison `$cacheArbre`.

Commentaires en français. `graphe.json` n'a jamais été écrit.

---

## 8. Le chargement depuis la base — `JeuDePlacementDoctrine`

Ajouté après le portage du moteur. Le moteur savait calculer ; il ne savait pas d'où
venaient les données. C'est ce que ce fournisseur apporte, et c'est le seul endroit du
chemin de placement qui touche la base.

**Résultat : 377/377 nœuds exacts, par le chemin réel.**

```
### vendor/bin/phpunit tests/Tree
PHPUnit 13.3.4 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.4.4

  Placement — via le fournisseur Doctrine : 377/377 nœuds exacts.
.................
  Placement — non-régression : 377/377 nœuds exacts.
.................                                34 / 34 (100%)

OK (34 tests, 3225 assertions)
```

### 8.1 Le défaut que ce test a trouvé, et que rien d'autre n'aurait vu

**`tree_person.id` et `tree_family.id` sont deux séquences indépendantes.** La personne 1
et la famille 1 existent toutes les deux. Un graphe indexé par le seul entier en perd une :
les nœuds vivent dans un même tableau, la seconde écrase la première.

Le symptôme est net et trompeur — le graphe sort avec **274 nœuds au lieu de 377**, comme
si aucune famille n'avait été calculée, alors qu'elles l'ont toutes été et que la base
contient bien 274 + 103 + 174 lignes.

Le fichier de référence n'avait pas le problème : le GEDCOM nomme `@I500001@` et
`@F500001@`, deux espaces de noms distincts. **La collision n'apparaît qu'en passant aux
identifiants de base**, donc après le portage du moteur — hors de portée de tous les tests
écrits jusque-là.

Le format retenu est celui que `docs/api-contract.md` prescrivait déjà : `p1`, `f28`,
`#12/p1`. Il est centralisé dans `Tree\ValueObject\IdentifiantDeNoeud` pour ne pas être
réinventé à chaque usage.

### 8.2 L'étiquette des dates : deux objets, un seul nom

C'est la première des deux règles qui avaient été laissées en suspens, et elle méritait de
l'être.

| | `UncertainDate::format()` | `EtiquetteDeDateGedcom` |
|---|---|---|
| destination | la **fiche** (§ 5.3 du contrat) | l'**étiquette du nœud** |
| `BET 1901 AND 1909` | « entre 1901 et 1909 » (20 car.) | `1901-1909` (9 car.) |
| `ABT 1934` | « vers 1934 » | `~1934` |
| `22 MAY 1949` | « 22 mai 1949 » | `22.5.1949` |

**`w` est mesurée sur cette chaîne.** Servir la prose de `format()` au moteur allonge
chaque fiche et fait tomber le critère d'égalité exacte. Le contrat d'API distingue
d'ailleurs explicitement les deux (§ 941) : « même nom de clé, deux objets différents — le
confondre produit un nœud dont l'étiquette déborde de sa boîte ».

`EtiquetteDeDateGedcom` est le portage de `jolie()` et `annee()` (`graphe.py` 64-98). Il
part de la **chaîne GEDCOM verbatim** (`birth_raw`), parce que c'est elle que le script
lisait : `annee()` y cherche un millésime **où qu'il soit**, ce que les bornes analysées ne
disent pas toujours. `BEF 1920` en est le cas d'école — `birth_min` est nul, mais
l'étiquette vaut bien `<1920`.

Une seule branche de ce fichier est une décision et non une traduction : le repli pour une
personne **créée dans l'application**, qui n'a pas de chaîne GEDCOM. Elle ne s'exécute sur
aucune des 274 personnes de référence.

### 8.3 La règle « personne vivante », vérifiée et non supposée

Seconde règle en suspens. Elle est calculée, **jamais stockée** : il n'existe aucune
colonne `is_living`, parce que ce serait stocker le résultat d'un calcul dont toutes les
entrées bougent, et parce qu'une tâche nocturne de recalcul qui échoue en silence rend
publique une fiche de personne vivante.

Les trois affirmations du § 9.2 du modèle de données ont été **recalculées sur les
274 personnes**, et les trois tiennent :

| Variante | Personnes masquées | Verdict |
|---|---:|---|
| **Règle du § 9.2** (seuil 110 + repli par médiane) | **18** | exactement les 18 `RESN privacy`, 0 manque, 0 faux positif |
| « sans date, donc vivante » (règle naïve) | 45 | **27 faux positifs** — des ancêtres du XVIII<sup>e</sup> siècle |
| « sans date, donc décédée » | 11 | **7 personnes vivantes exposées** |

La troisième ligne n'était pas dans le § 9.2 et mérite d'y figurer : **le repli par médiane
se trompe des deux côtés quand on l'enlève.** Ce n'est pas seulement qu'il évite de
sur-masquer ; sans lui, sept personnes réellement vivantes sortiraient en clair. C'est le
sens de l'erreur qu'on ne peut pas se permettre.

Les **douze médianes par génération** publiées au § 9.2 ont également été reproduites et
sont verrouillées par un test. Au passage, la définition compte : la médiane **au sens
strict** — moyenne des deux valeurs centrales quand l'effectif est pair — retombe sur les
12 valeurs publiées, là où la variante « valeur basse » n'en retrouve que 10.

### 8.4 Les trois contraintes du fournisseur

1. **L'ordre fait foi, et il est explicite** : `ORDER BY p.id`, `ORDER BY f.id`,
   `sibling_order` puis `id` pour les enfants. Une requête sans `ORDER BY` rendrait
   PostgreSQL libre de son ordre, et le graphe changerait au gré des `VACUUM`.
2. **Les adjacences se reconstruisent depuis `tree_family` et `tree_child`**, jamais depuis
   une liste dénormalisée. Le fournisseur ne rend que `epoux` et `enfants` par famille ; le
   moteur en dérive lui-même parents, enfants et conjoints. C'est le même piège que dans
   `graphe.json`, où les listes triées écrites *après* le placement n'étaient pas celles
   que le moteur avait utilisées — **la base a exactement la même capacité à mentir**.
3. **Aucun filtre de confidentialité** : l'étage 1 est calculé sur l'arbre complet.

### 8.5 Un contrat de réglages plutôt qu'un appel direct à `Core`

`Tree\Service\ReglagesDeLArbre` déclare les deux réglages dont le calcul a besoin — le
seuil et la personne racine — et `ReglagesDeLArbreParSettings` les sert depuis
`core_setting`. Même idiome que `Core\Contract\SessionPayloadContributorInterface`.

Ce n'est pas de la cérémonie : sans cette interface, éprouver le fournisseur demanderait
`core_setting`, donc les entités de `Core`, donc un noyau Symfony — **pour lire deux
entiers**.

Un point à connaître, écrit dans la docbloc de l'interface : **changer le seuil ne change
pas l'empreinte de l'arbre**, alors qu'il peut changer une largeur de fiche (une personne
qui cesse d'être « vivant·e » retrouve ses vraies dates). Le cas courant est couvert — le
passage à décédé écrit sur `tree_person`, donc change l'empreinte — mais **le changement du
seuil lui-même demande un vidage du pool `cache.arbre`**. C'est écrit pour que personne ne
le découvre devant un arbre aux fiches mal dimensionnées.

### 8.6 Ce que le test de bout en bout ne couvre pas

La base est peuplée **depuis `graphe.json`**, en reconstituant les chaînes GEDCOM verbatim
qu'un import écrirait. La reconstitution est elle-même vérifiée : chaque chaîne reconstruite
repasse dans `EtiquetteDeDateGedcom` et doit redonner l'étiquette d'origine, sur les
548 dates. Sans ce garde-fou, le test pourrait se tromper de la même façon des deux côtés
et rester vert.

> **La chaîne complète GEDCOM → `heritage:import-gedcom` → base → placement n'était pas
> couverte** au moment de cette tâche. **Elle l'est depuis** : voir le § 9.

La base de test est un SQLite **en mémoire**, monté par le test lui-même, sans noyau
Symfony ni fichier partagé. Les bases SQLite partagées entre processus produisent des
erreurs fantômes, et ce test doit pouvoir accuser le fournisseur sans qu'on se demande si
c'est l'infrastructure.

Détail qui vaut d'être noté : seuls `Tree\Entity` et `Core\ValueObject` sont déclarés au
mapping. **Aucune entité de `Core` n'est nécessaire** — c'est une conséquence observable de
la frontière du § 0.4. Si `Tree` avait une relation Doctrine vers `Core`, ce test ne
pourrait pas exister sous cette forme.

---

## 9. Le dernier maillon — le placement sur la base réellement importée

Ajouté une fois l'import GEDCOM opérationnel. C'est le seul trou qui restait côté serveur,
et il est fermé.

**Résultat : 377/377 nœuds exacts, calculés sur la base que produit `heritage:import-gedcom`
depuis `arbre-romarin-v5.ged`.**

```
### php -d extension=pdo_pgsql vendor/bin/phpunit tests/Tree/Placement*.php
PHPUnit 13.3.4 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.4.4

  Placement — depuis la base importée : 377/377 nœuds exacts.
  Placement — via le fournisseur Doctrine : 377/377 nœuds exacts.
  Placement — non-régression : 377/377 nœuds exacts.
                                                      39 / 39 (100%)

OK (39 tests, 3238 assertions)
```

### 9.1 Trois tests qui ne se remplacent pas

Chacun isole une couche différente, et c'est leur superposition qui prouve la chaîne. Un
seul d'entre eux ne dirait pas grand-chose.

| Test | Alimenté par | Ce qu'il isole |
|---|---|---|
| `PlacementNonRegressionTest` | `graphe.json` | le **moteur** est un portage fidèle |
| `PlacementFournisseurDoctrineTest` | base reconstruite depuis `graphe.json` | le **fournisseur** lit et ordonne juste |
| `PlacementBaseImporteeTest` | base issue du **GEDCOM** | l'**import** écrit ce que le placement attend |

Le troisième est le seul endroit où un écart entre ce que l'import écrit et ce que
`graphe.json` contient deviendrait visible. Il échapperait aux **totaux de contrôle de
l'import** — qui comptent des lignes, pas des largeurs — comme au **test de
non-régression**, qui ne lit jamais la base. C'est le genre d'écart qui ne se voit qu'à
l'intersection de deux chemins.

### 9.2 Ce que la base importée a confirmé au passage

- **274 personnes, 103 familles, 174 filiations** — les comptes du dossier de mission.
- **La correspondance par `gedcom_xref`** fonctionne comme prévu : `@I500001@` → `p1`,
  `@F500001@` → `f28`. C'est à cela que sert la conservation de la référence GEDCOM en base
  (§ 9.8) ; sans elle, aucun moyen de rapprocher une ligne importée de la référence.
- **`tree.personne_racine_id = 1`** et **`tree.seuil_vivant_annees = 110`** sont bien en
  base, lus par le fournisseur à travers `ReglagesDeLArbre`.
- **La règle « personne vivante » retombe sur les 18 `RESN privacy`** en partant des
  colonnes que l'import a écrites — donc l'import renseigne `deceased` et `birth_max`
  comme `graphe.json` les décrit. Si ce n'était pas le cas, les lignes de dates
  changeraient, donc les largeurs, donc les 377 positions.
- **La fourchette de Marne Dolomite Sureau (`@I500017@`) traverse la chaîne sans être
  aplatie** : `BET 1901 AND 1909` en base, `entre`, `1901-01-01` → `1909-12-31`, et
  l'étiquette compacte `1901-1909`. C'est le cas d'école du § 9.1 du modèle de données —
  MyHeritage y avait écrit `1987`, son année de décès, ce qui la faisait naître après ses
  propres enfants et générait six incohérences en cascade. Un test dédié la surveille.

### 9.3 Lancer ce test

```
cd C:/www/heritage/api
php -d extension=pdo_pgsql vendor/bin/phpunit tests/Tree/PlacementBaseImporteeTest.php
```

**`-d extension=pdo_pgsql` est nécessaire** : le PHP de la machine ne charge pas cette
extension par défaut, alors qu'elle est présente sur le disque. On l'active à la ligne de
commande plutôt que dans `php.ini` — ce test est le seul du projet à parler à PostgreSQL
directement, et modifier la configuration de la machine pour lui serait disproportionné.

La base visée est celle de `compose.yaml`. Pour en désigner une autre :

```
HERITAGE_DSN_REEL=postgresql://user:pass@hote:5432/base \
    php -d extension=pdo_pgsql vendor/bin/phpunit tests/Tree/PlacementBaseImporteeTest.php
```

Le test se **saute** proprement si `pdo_pgsql` manque, si la base est injoignable, si elle
est vide, ou si `graphe.json` est absent. Il ne tourne donc pas en CI, comme les deux
autres tests de données réelles.

**Lecture seule, du début à la fin.** Il n'écrit rien, ne crée aucun schéma et ne vide
aucune table : il se branche sur une base de travail et se contente de la lire.

---

## 10. L'empreinte rendue portable

Signalé après coup par l'agent de l'API de l'arbre : `TreeFingerprint` portait la requête
du § 2.3 telle quelle, **du PostgreSQL pur** — `sha256()`, `convert_to()`, `string_agg()`,
`E'
'`, `p::text`. Or la suite de tests tourne sur SQLite. `GET /api/arbre/graphe` levait
donc une `DBALException` avant même d'atteindre le placement, et c'était **la seule route
du module non couverte de bout en bout**.

### 10.1 Pourquoi pas une branche par dialecte

Parce que **deux implémentations qui doivent produire le même haché finissent par
diverger**, et que la divergence ne se verrait qu'en service : un cache qui ne s'invalide
jamais, ou qui s'invalide toujours. C'est exactement le raisonnement qui a fait écarter le
compteur au profit de l'empreinte — on préfère ce qui ne peut pas se désynchroniser.

Le haché est donc calculé **en PHP**, une seule fois pour tous les moteurs. Le volume rend
le choix gratuit : 551 lignes courtes.

### 10.2 Ce qui a été conservé du § 2.3, et comment

La garantie qui comptait était « `p::text` rend la ligne entière, donc aucune écriture ne
peut lui échapper ». Une liste de colonnes écrite à la main l'aurait perdue à la première
migration. Les colonnes sont donc **découvertes dans les métadonnées Doctrine** — clés
étrangères comprises, puisque ce sont elles qui portent la parenté — et triées par nom,
pour que l'empreinte ne bouge pas parce que quelqu'un a déplacé un accesseur.

Seule la **liste des trois tables** reste écrite à la main. C'est voulu : c'est la décision
de périmètre de l'ADR 0005, elle doit rester un acte délibéré. Un test la garde —
`testElleNeChangePasQuandUneCitationOuUneSourceApparait`.

### 10.3 L'indifférence au moteur est prouvée, pas supposée

Les pilotes ne rendent pas les mêmes valeurs pour la même donnée :

| Colonne | PostgreSQL | SQLite |
|---|---|---|
| `deceased` | `t` / `f` | `1` / `0` |
| `created_at` | `2026-09-15 18:24:05+00` | `2026-09-15 18:24:05` |

`EmpreinteEntreMoteursTest` lit les 274 + 103 + 174 lignes réellement importées depuis
PostgreSQL, les recopie dans un SQLite en mémoire **en les réécrivant à la façon de
SQLite**, et exige la même empreinte. Écrire les valeurs verbatim n'aurait rien prouvé :
SQLite les aurait rendues telles quelles et on aurait comparé PostgreSQL à lui-même — d'où
un second test qui vérifie que les deux moteurs stockent bien **différemment**, sans quoi
le premier passerait quoi qu'il arrive.

**PostgreSQL est lu, jamais écrit.**

### 10.4 Deux effets de bord observés

- **`GET /api/arbre/graphe` passe maintenant de bout en bout.** Le test qui l'attendait
  était écrit pour se réveiller seul — il se sautait tant que l'empreinte était en SQL
  PostgreSQL, et sortait par un chemin d'attente tant que le fournisseur manquait. Les deux
  conditions levées, il vérifie le parcours nominal : `200`, `ETag` fort,
  `Cache-Control: private, no-cache`.
- **Un ré-import idempotent change l'empreinte.** Observé en direct : l'import a été rejoué
  pendant la tâche, les données structurantes sont restées identiques — 274/103/174, et le
  placement toujours exact à 377/377 — mais les horodatages ont changé, donc l'empreinte
  aussi. C'est `updated_at` faisant partie de « la ligne entière ». Le comportement est
  conforme au § 2.3 et va dans le sens prudent : on recalcule un graphe identique plutôt
  que de risquer de servir un graphe périmé. À connaître, pas à corriger.

