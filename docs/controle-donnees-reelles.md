# Contrôler qu'aucune donnée réelle n'est dans le dépôt

Procédure à rejouer **avant tout envoi**, et à chaque fois qu'on ajoute un test, une fixture
ou un exemple de documentation. L'ADR 0006 dit que les données réelles n'entrent pas dans le
dépôt ; ce document dit **comment on le vérifie**.

Il n'existe pas encore de script qui l'exécute. Les commandes ci-dessous sont la procédure :
elles se rejouent telles quelles contre la pile de développement.

---

## 1. Le corpus se construit depuis la base, jamais depuis une liste de mots

Une table de substitutions est une liste de ce que **quelqu'un a reconnu**. Elle ne peut pas
contenir `maison 12` ni `9 E 118`, parce que personne ne les aurait nommés. La base, elle,
sait ce qui est réel.

Et il faut **toutes** les tables qui portent de la donnée, pas seulement `tree_person` :

| table | ce qu'elle porte |
|---|---|
| `tree_person` | noms, surnoms, noms d'usage, dates et lieux de naissance et de décès |
| `tree_family` | dates et lieux de mariage et de divorce |
| `tree_fact` | dates, lieux et libellés des faits |
| `tree_citation` | `page` — **cotes d'archives, vues, maisons, ménages, individus** |
| `med_media` | dates et lieux de prise de vue |

Un corpus bâti sur `tree_person` seul rend 478 dates ; sur les cinq tables, **566**. Les 88
manquantes sont celles des mariages, des faits et des médias — et c'est dans la table oubliée,
`tree_citation`, que vivaient les cotes d'archives.

```sh
docker compose exec -T db psql -U heritage -d heritage -At -c "
SELECT DISTINCT v FROM (
  SELECT birth_min::text AS v FROM tree_person UNION ALL SELECT birth_max::text FROM tree_person
  UNION ALL SELECT death_min::text FROM tree_person UNION ALL SELECT death_max::text FROM tree_person
  UNION ALL SELECT marriage_min::text FROM tree_family UNION ALL SELECT marriage_max::text FROM tree_family
  UNION ALL SELECT divorce_min::text FROM tree_family UNION ALL SELECT divorce_max::text FROM tree_family
  UNION ALL SELECT happened_min::text FROM tree_fact UNION ALL SELECT happened_max::text FROM tree_fact
  UNION ALL SELECT taken_min::text FROM med_media UNION ALL SELECT taken_max::text FROM med_media
) q WHERE v IS NOT NULL;"
```

Le même geste pour les chaînes GEDCOM brutes (`*_raw`), les noms, les lieux et les `page` de
citation. **Les fichiers à balayer sont ceux du commit**, pas ceux du disque :

```sh
git ls-files > liste.txt && git ls-files --others --exclude-standard >> liste.txt
```

## 2. On cherche la forme, pas le mot

C'est le point qui a fait échouer trois passes successives : **elles cherchaient ce qu'elles
savaient déjà nommer.**

`maison 12` ne se trouve pas en cherchant le nom du département. `9 E 118` ne se trouve pas en
cherchant « archives ». Une docstring Python de normalisation de cotes ne se trouve pas en
cherchant un patronyme — et elle portait une cote présente dans 29 citations réelles.

Il faut donc extraire du dépôt **toutes les expressions ayant la forme d'une donnée**, puis
confronter chacune à la base :

- cote d'archives : `\b(\d{1,3})\s*([A-Z])\s*(\d{1,4})\b` — attention, la série `T` attrape les
  horodatages ISO et `L`/`Q`/`M`/`V` les commandes de tracé SVG ; il faut trier ;
- date exacte : `\b\d{4}-\d{2}-\d{2}\b`, et les formes `11 MAR 1930`, `11.3.1930`,
  `11/03/1930`, `11 mars 1930` — **une seule oubliée suffit** ;
- coordonnées : sexagésimales, décimales, et `pack('NNNNNN'` dans les fixtures EXIF ;
- numéros de vue, de maison, de ménage, d'individu.

## 3. Une année nue n'identifie personne ; une date exacte, si

Le critère « cette année existe-t-elle en base ? » ne discrimine rien. Mesuré : **toute année
testée entre 1823 et 1974 correspond à quelqu'un** — 279 personnes réparties sur près de
quatre siècles, la coïncidence est certaine.

C'est ce critère-là qui fait tomber une date exacte (`11 MAR 1930` : une personne, une seule,
`qualifier = exacte`, `min = max`) et qui laisse passer à juste titre une borne d'axe — le
premier millésime de l'arbre — ou une borne dérivée d'une année seule (`ABT 1802` donne
`1802-01-01`, qui n'est le jour de naissance de personne).

**Corollaire pour la correction** : quand une date exacte doit être remplacée, décaler **le
jour seulement**, à année constante. Les contrastes des documents survivent — « `11 MAR 1930`
exacte » contre « `1930` année seule » restent dans la même année — et le jour de
remplacement se vérifie absent du corpus avant d'être écrit.

## 4. Un nom substitué au-dessus d'une donnée réelle ne protège rien

Deux fois le même défaut, deux fois passé inaperçu :

- un **lieu inventé** posé au-dessus de ses propres coordonnées archivistiques réelles — cote,
  vue, maison, ménage, individu ;
- un **domaine d'archives inventé** posé au-dessus de la vraie cote et de ses millésimes.

La substitution doit porter sur **la donnée**, pas sur l'étiquette qui la présente.

## 5. Un `grep` propre ne prouve pas un fichier propre

Un test peut tirer ses noms d'une **graine** qui les porte tous : le fichier ne contient alors
aucun nom, et sa sortie les contient tous. Il faut **exécuter et regarder ce qui sort** —
`app/dist/` en est le cas type, et il doit être régénéré ou supprimé avant tout envoi.

Symétriquement, un contrôle qui interroge un serveur sans regarder le **type de la réponse**
est vert par construction : un repli d'application à page unique rend `200 text/html` pour
n'importe quel chemin, y compris inventé.

**Et un artefact dérivé n'est jamais corrigé par la correction de sa source.** Corriger un
`.py` ne corrige pas son `.pyc` ; corriger `app/src` ne corrige pas `app/dist`. Il faut donc
recenser les artefacts dérivés **suivis par git** — le contrôle utile est la liste des
fichiers suivis qui ne se lisent pas en UTF-8 — et les sortir du suivi plutôt que de les
corriger.

## 6. Un balayage doit nommer ce qu'il n'a pas pu lire

**Un fichier sauté en silence est indiscernable d'un fichier propre.**

Un balayage de texte ignore les fichiers binaires — il ne peut pas les décoder, donc il les
passe. S'il n'annonce que le nombre de fichiers *lus*, la différence avec le nombre de
*chemins* ne se voit pas, et c'est exactement là que se cache un artefact dérivé.

Mesuré : un balayage annonçant « 737 fichiers lus » sur 744 chemins taisait 5 fichiers de
**bytecode Python suivis par git**, dont un portait encore une date de naissance réelle et
deux cotes d'archives réelles — figées avant la correction de la source. Aucune règle de
`.gitignore` ne les couvrait.

Le balayage doit donc rendre **deux nombres et une liste** :

```
fichiers lus : N   |   non lus : M
  <la liste des M, avec la raison : binaire, vide, illisible>
```

Et chaque fichier non lu doit être jugé : ou bien il ne peut rien porter (un `.gitkeep`
vide), ou bien il faut l'ouvrir autrement — pour du bytecode, chercher les motifs dans les
**octets** :

```python
b = open(chemin, "rb").read()
trouve = [m for m in motifs if m.encode("utf-8") in b]
```

## 7. Tout balayage assertionne sa taille et dit ce qu'il n'a pas trouvé

- Le nombre de fichiers parcourus est **assertionné**, jamais tronqué : ni `head`, ni `LIMIT`.
- Le rapport dit **ce qui a été cherché et non trouvé**, pas seulement ce qui a été trouvé.
  « 0 date exacte sur 566 cherchées, dans 736 fichiers » est une mesure ; « rien trouvé » n'en
  est pas une.
- Les codes de sortie se lisent en **commande nue**. Un code qui traverse un tuyau est celui du
  dernier maillon — `git grep … | head` rend le code de `head`, donc toujours 0.

## 8. Les exemptions légitimes, et l'obligation d'écrire la raison dans le fichier

Sont exemptés **après mesure**, pas par principe :

| exemption | raison |
|---|---|
| Bornes d'axe et médianes par génération | agrégats ; les médianes sont d'ailleurs **calculées à l'exécution**, aucune valeur figée dans le dépôt |
| Années nues, comptages de corpus, identifiants numériques | n'identifient personne (§ 3) |
| Coordonnées GPS de la fixture EXIF | la fixture existe pour prouver que le GPS est **purgé** ; les retirer viderait le test de son objet. Elles pointent un lieu inventé |
| Xrefs jouets (`@I1@`, `@F1@`, `@S1@`…) | vérifiés absents de la base |
| Prénoms français courants accolés à un patronyme inventé | ne désignent personne ; un patronyme, lui, désigne |
| Bornes dérivées `AAAA-01-01` / `AAAA-12-31` | produites par l'encodage d'une année seule (§ 0.5 du modèle) |
| Noms de personnes dans `composer.lock` / `symfony.lock` | **mainteneurs des paquets amont**, publiés par eux-mêmes sur Packagist et écrits par Composer. Un prénom du corpus peut y coïncider — c'est une homonymie, pas une fuite. Ces fichiers sont générés : on ne les édite pas |

**La raison s'écrit dans le fichier**, en commentaire, à côté de la valeur exemptée — pas
seulement ici. Sans quoi le prochain lecteur « répare » une exemption délibérée, ou en crée
une nouvelle sans la mesurer. Une exemption dont la raison ne se lit pas sur place n'est pas
une exemption : c'est un oubli qui a survécu.

## 9. Ce que la procédure ne couvre pas

L'historique Git. Un fichier commité une fois **y reste, même supprimé au commit suivant** —
l'ADR 0006 le dit. Si un contrôle trouve une donnée réelle déjà partie à l'envoi, un nouveau
commit ne la retire pas : il faut une réécriture d'historique, et c'est une décision du
commanditaire, jamais d'un agent.
