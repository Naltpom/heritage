# ADR 0012 — Les sources sont gérées par l'administration, hors propositions

Date : 2026-09-30 · Statut : acceptée

## Contexte

Une citation (`ajout_citation`, § 6.4 bis du contrat) désigne une **source existante** par
son `sourceId`. Or rien, jusqu'ici, ne permettait de créer une source : les 28 lignes de
`tree_source` venaient toutes de l'import GEDCOM. Le 30 septembre 2026, pour citer des
actes reçus de l'état civil de Paris et du Nord, il a fallu insérer deux sources **à la main
en base de production** (ids 29 et 30). Un geste qu'on refait à la main en production est un
geste qui finira par se tromper.

La question était : la création d'une source passe-t-elle par la file de l'ADR 0004, comme
toute écriture dans l'arbre ?

## Décision

**Non.** Les sources se créent et se corrigent depuis un écran d'administration
(« Administration › Sources »), par quatre routes réservées à `ROLE_ADMIN`, dans le module
`Tree` qui possède la table (ADR 0002) :

```
GET  /api/administration/sources?q=&page=&parPage=   liste, recherche par titre, citationCount
POST /api/administration/sources                      créer
GET  /api/administration/sources/{id}                 détail
PUT  /api/administration/sources/{id}                 corriger la notice
```

### Pourquoi hors de la file

Une source est une **métadonnée bibliographique**, pas une donnée de l'arbre. « État civil
de Paris — archives.paris.fr » ne dit rien de vrai ni de faux sur une personne : c'est la
notice d'un fonds. Ce qui engage l'arbre, c'est la **citation** — « cet acte, à cette page,
établit cette naissance » — et elle, continue de passer par une demande validée.

Faire passer la notice par la file ne protégerait rien : il n'y a ni personne vivante à
masquer (le cercle de l'ADR 0003 ne s'applique pas à une notice), ni fiche dont la version
bougerait sous une demande concurrente — `tree_source` n'a d'ailleurs pas de colonne
`version`. Cela coûterait en revanche un aller-retour à chaque citation d'un fonds nouveau :
proposer la source, attendre, puis proposer la citation.

C'est la même ligne de partage que pour les modules `Question`, `Tirage` et `Demarche` :
ce qui n'affirme rien sur une personne n'entre pas dans la file.

### Ce qui l'encadre

- **Administrateurs seulement.** Un membre ne crée pas de source ; il cite celles qui
  existent, et demande à un administrateur d'ajouter la notice qui manque. Les sources sont
  peu nombreuses et partagées par tout l'arbre : un doublon (« AD Nord », « Archives du
  Nord ») coûte plus cher à défaire qu'une demande à l'administrateur.
- **Tracé.** Chaque création ou correction laisse sa ligne au journal d'audit, par le
  journal mécanique de `Core` (`AuditDoctrineSubscriber`). Refusée sous la bascule « voir
  comme un membre ».
- **`gedcom_xref` reste `null`** pour une source créée à l'écran, et n'est jamais modifiable
  par l'API. C'est la clé d'idempotence du ré-import : `EcrivainTree::ecrireSources()`
  retrouve une source par son xref et **l'écrase**. Inventer `@S500916@` à la suite du
  maximum actuel reviendrait à parier que la prochaine exportation MyHeritage ne réutilisera
  jamais ce numéro ; le jour où elle le ferait, le ré-import remplacerait notre notice par
  une autre, et ses citations changeraient de source sans bruit. La colonne est
  `NULL`-able et son index `UNIQUE` admet plusieurs `NULL` : rien ne manque au modèle.
- **Corriger une source importée** est permis, mais un ré-import GEDCOM la réécrira depuis
  le fichier. L'écran le dit sur toute source qui porte un xref.
- **Pas de suppression.** `tree_citation.source_id` est en `RESTRICT`, ce qui protège une
  source citée ; mais une source jamais citée peut être désignée par une demande
  `ajout_citation` **en attente**, que `Tree` ne voit pas (ADR 0002), et dont la validation
  tomberait alors en erreur. Une suppression sûre demanderait un port vers `Proposal` ; elle
  n'a pas encore de besoin qui la justifie.

## Alternatives considérées

- **Une nouvelle nature de demande, `creation_source`.** Refusée : toute la mécanique de la
  file (diff, calque, caducité) sert à protéger des fiches, et une notice n'en a pas besoin.
- **Créer la source à la volée dans la demande `ajout_citation`.** Refusée : chaque membre
  créerait sa variante du même fonds, et le doublonnage des sources est exactement ce qui
  rend illisible « ces 40 fiches reposent sur la même source » (§ 9.7 du modèle).
- **Continuer en SQL, à la main.** C'est ce qui a motivé cet ADR.

## Conséquences

- La compétence `famille` et les administrateurs ajoutent une source par l'écran, puis la
  citent par une demande ordinaire.
- Le § 10.7 du contrat décrit les routes ; le § 1.7 attribue `/api/administration/sources`
  au module `Tree`.
