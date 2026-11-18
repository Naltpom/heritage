# ADR 0024 — La fiche tenue par son titulaire : un compte actif vaut vérification

Date : 2026-11-18 · Statut : acceptée

## Contexte

« Vérifié » n'avait qu'une définition dans tout le produit : **`bestQuay ≥ 2`**, au moins
une citation qui est un acte ou un registre. Elle sert le bandeau de la fiche
(`regimeDocumentaire`), le filtre « Vérifiés » de `/arbre`, la colonne « Vérifiée » du
tableau, la part vérifiée des statistiques et les listes du chantier.

Les fiches des personnes inscrites n'y entraient pas. Ce sont des vivants, dont les actes
de moins de cent ans ne sont pas consultables : le 18/11/2026, les 16 comptes actifs
avaient tous une fiche « Sans source » (14) ou « Non vérifiée » (2), alors que chacune de
ces personnes s'était connectée et reconnue dans sa fiche.

Le commanditaire, le même jour : « Les fiches rattachées à une personne doivent être des
fiches validées, étant donné que la personne s'est connectée et est rattachée à sa fiche. »

## Décision

**Une fiche dont un compte actif (`mbr_member.status = actif`) est le titulaire compte
« Vérifiée »**, au même titre qu'une fiche tenue par un acte :

> Vérifiée ⇔ `bestQuay ≥ 2` **ou** un compte actif est le titulaire de la fiche.

Un compte en attente n'a pas encore été reconnu, ni par l'invitation ni par un
administrateur ; un compte suspendu ne vaut plus attestation. Ni l'un ni l'autre ne
compte.

### Un contrat, pas une colonne

Le rattachement vit dans `mbr_member`, que `Tree` n'a pas le droit de lire (ADR 0002).
Recopier un drapeau dans `tree_person` obligerait à le tenir à jour à chaque activation,
suspension, re-rattachement ou suppression de compte, et chaque oubli ferait dériver la
copie. `Core` publie donc une question, `FichesTitulairesInterface::fichesTitulaires()` :
les `tree_person.id` dont un compte actif est le titulaire, avec la date depuis laquelle il
l'est (l'activation, à défaut le rattachement). `Membership` y répond
(`FichesTitulairesDesMembres`). Le repli `AucuneFicheTitulaire` ne connaît personne : sans
`Membership`, la règle retombe sur les seuls actes. Une fiche peut alors paraître moins
tenue qu'elle ne l'est, jamais l'inverse.

### Ce qui sort, et où

- **Graphe** : `titulaire: true` sur le nœud, **seulement quand c'est vrai** (inutile de
  charger 5 000 nœuds d'un `false`), et **jamais sur un nœud restreint** : la branche
  `if (!$visible)` est passée, comme pour `bestQuay` (§ 16.2). Sinon on apprendrait qu'une
  personne vivante hors du cercle a un compte.
- **Fiche** et **tableau** : `titulaire` (booléen) à côté de `bestQuay`, dans la charge
  complète seulement. Absent de la fiche réduite, ce que garde la liste blanche.
- **Statistiques** : `PersonneDuReleve::$titulaireDepuis`. La fiche entre dans la part
  vérifiée, et dans les séries (mois, jours) à la date d'activation si celle-ci précède le
  premier acte. Elle sort des listes « Sans source » et « Non vérifiées » du chantier : il
  n'y a plus rien à y chercher.

### Côté front, les trois filtres restent disjoints

`estVerifie(n) = bestQuay ≥ 2 || titulaire`. Une fiche titulaire est dans « Vérifiés »,
et sort de « Non vérifiés » et de « Sans source ». Le bandeau de la fiche dit pourquoi :
« Vérifié : la personne s'est elle-même rattachée à sa fiche. » Quand un acte existe aussi,
c'est l'acte qu'on cite (« Vérifié sur un acte ou un registre. »).

### Les caches suivent

Activer un compte ne touche aucune table `tree_*`. Deux clés de cache devaient donc
apprendre à le voir :

- l'**`ETag` du graphe** prend une **sixième composante**, obligatoire comme les cinq
  autres : la liste des fiches titulaires, hachée. Sans elle, chaque lecteur revalidait,
  recevait `304`, et gardait la fiche « Non vérifiée » ;
- le **jeton du relevé des statistiques** liste les fiches titulaires et leur date.

## Conséquences

- Les 16 fiches des comptes actifs passent « Vérifiées » dès le déploiement, sans
  migration ni écriture en base.
- Le filtre « Vérifiés » ne veut plus dire « appuyé sur un acte » seulement. Celui qui
  cherche les fiches tenues par un acte lit la colonne « Meilleure source » du tableau, qui
  n'a pas changé.
- Les outils de recherche hors de l'application (la boucle d'élargissement) lisent
  `best_quay` en SQL. Ils ne voient pas les titulaires, et n'en ont pas besoin : ce sont
  des vivants, que la recherche d'archives ne cible pas.
