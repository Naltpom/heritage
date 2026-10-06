# ADR 0004 — Toute écriture passe par une proposition

Date : 2026-09-15 · Statut : acceptée

## Contexte

Le produit veut deux choses qui s'opposent : que la famille **contribue** (c'est la raison
d'être de l'application, sinon un PDF suffirait), et que les données restent **justes et
protégées** — elles concernent des personnes vivantes et reposent sur des actes.

Le dossier de mission tranche : un membre ne modifie jamais directement. Il propose,
l'administrateur valide ou refuse avec un message obligatoire. Reste à écrire ce que ce
circuit implique, et pourquoi c'est une file de validation plutôt qu'un wiki.

## Décision

- Toute écriture d'un membre crée une **`ChangeRequest`** portant des **`ChangeRequestOp`** —
  **un op par champ**, avec ancienne et nouvelle valeur.
- **Un seul mécanisme**, quelle que soit la nature. Les six valeurs de `RequestKind`
  (`docs/modele-donnees.md` § 5.7) : `modification_personne`, `modification_famille`,
  `modification_citation`, `creation_personne`, `ajout_lien`, `ajout_media`. Une création
  est une demande comme une autre : même file, même diff, même refus motivé.
- La table `tree_person` n'est **pas touchée** tant que ce n'est pas validé.
- **Calque « en attente »** : l'API renvoie les valeurs validées plus un calque des valeurs
  proposées, **au seul auteur et aux administrateurs**. Les autres membres voient les données
  validées, point.
- **Refus : message obligatoire**, transmis à l'auteur dans sa notification. Une demande
  refusée reste consultable par son auteur, avec le motif.
- **Validation** : application transactionnelle, **verrou optimiste**, écriture d'une
  `Revision`, invalidation du cache de placement et des cercles de visibilité — qui ne
  demande aucun geste, l'écriture changeant d'elle-même l'empreinte qui sert de clé
  (ADR 0005).
- **Le verrou optimiste, c'est deux verrous et non un** — précision reportée ici après
  rédaction du modèle de données, parce que la formulation initiale (« verrou optimiste sur
  `Person.version` ») n'est pas implémentable seule :
  1. `#[ORM\Version]` sur `version`, présent sur `tree_person`, `tree_family` **et**
     `tree_citation` — les trois cibles possibles d'une demande. Il protège deux
     transactions **concurrentes** ;
  2. `prp_change_op.base_version`, la version de la ligne telle que l'auteur l'a vue à la
     soumission. Il protège les **trois jours** entre le dépôt et la validation, que le
     premier ne peut structurellement pas voir passer : l'entité est rechargée fraîche au
     moment de valider, et `#[ORM\Version]` ne remarquerait rien. Il est porté par l'op et
     non par la demande, parce qu'une demande touche plusieurs lignes et qu'une colonne
     unique sur l'en-tête ne saurait pas dire **laquelle** a bougé.
  Détail : `docs/modele-donnees.md` § 9.6.
- **L'administrateur édite directement**, mais son écriture crée quand même une
  `ChangeRequest` auto-validée dans la même transaction et une `Revision`. **Il n'existe aucun
  chemin d'écriture qui contourne le mécanisme.** Conséquence à ne pas manquer côté schéma :
  `prp_revision.request_id` n'est `NULL` que pour l'import GEDCOM, jamais pour une édition
  d'administrateur.

  > **Remplacé par l'[ADR 0017](0017-suppression-de-l-import-gedcom.md) (2026-10-06)** : l'import est supprimé — et il n'écrivait d'ailleurs
  aucune révision. Aucun chemin d'écriture ne produit plus de `request_id` nul ; il ne peut
  venir que d'une demande effacée hors de l'application.

## Pourquoi une file de validation plutôt qu'un wiki

Le wiki — chacun écrit, on révoque après coup — est le modèle de Wikipédia, de Geneanet et de
webtrees en mode collaboratif. Il est écarté, pour trois raisons, dans cet ordre.

1. **La révocation ne répare pas une fuite.** Un membre qui écrit directement la date de
   naissance exacte ou le lieu de résidence d'un cousin vivant la publie à tout le cercle de
   ce cousin pendant le temps que dure l'erreur. Annuler la modification n'annule pas la
   lecture. Sur des données de personnes vivantes, seul le contrôle *a priori* protège.
2. **Il n'y a pas de communauté de relecteurs.** Un wiki tient parce que des centaines d'yeux
   surveillent les modifications récentes. Ici : quelques dizaines de comptes dont la plupart
   consulteront sans jamais contribuer, et **un** administrateur. La révocation a posteriori
   suppose quelqu'un qui relise — c'est la même personne que le validateur, sauf qu'elle relit
   après le dégât.
3. **La qualité en généalogie est une question de sources, pas de consensus.** Le chantier
   en a déjà fait la démonstration coûteuse : **161 citations requalifiées en `QUAY 0`** parce
   que MyHeritage appelait « preuve directe et primaire » de simples recopies d'arbres ; une
   année de décès recopiée dans le champ naissance de Marne Dolomite Sureau, qui la faisait
   naître après ses propres enfants et générait six incohérences en cascade. Ces erreurs
   viennent d'un système qui accepte ce qu'on lui donne. Le seul mécanisme qui ait corrigé
   quoi que ce soit dans ce chantier, c'est quelqu'un qui regarde un diff et demande « sur
   quoi tu t'appuies ? ».

Le coût est assumé : l'administrateur est un goulot d'étranglement. C'est acceptable parce que
le volume attendu est de quelques demandes par semaine, et parce que le calque « en attente »
rend l'attente indolore pour le demandeur — il voit sa contribution à son écran
immédiatement, ce qui est justement ce que le wiki apportait.

## Conséquences à traiter

### Deux demandes touchent la même personne

On ne verrouille pas et on ne sérialise pas les demandes : deux membres peuvent proposer en
parallèle. Une file qui refuserait les demandes concurrentes découragerait la contribution,
qui est le but du produit. L'arbitrage se fait à la validation.

- À la création d'une demande, si une autre demande **en attente** touche la même personne,
  l'auteur est **informé sans être bloqué** (« une modification est déjà en attente sur cette
  fiche »).
- La file d'administration **regroupe les demandes par personne** : les deux diffs sont vus
  côte à côte avant de trancher.
- Le verrou optimiste porte sur `Person.version` : **la seconde validation part
  systématiquement en conflit**, même sur des champs disjoints. La règle de résolution est
  donc explicite : au conflit, on compare **op par op** l'ancienne valeur portée par la
  demande à la valeur courante en base.
  - **Toutes les anciennes valeurs correspondent encore** → la demande est **rejouée**
    automatiquement sur la nouvelle version. C'est le cas des champs disjoints, qui est le cas
    courant.
  - **Une ancienne valeur a changé** → la demande passe en **`caduque`** (ci-dessous). On
    n'écrase jamais.

  Il n'existe **pas** d'action « rebaser la demande » à la main : le rejeu automatique
  couvre exactement le cas qu'un rebasage aurait traité, et le reste est une caducité. Deux
  mécanismes pour un cas, ce serait deux écrans et deux sources de bogue. Le rejeu est
  tracé au journal d'audit (`demande_rejouee`) : une écriture qu'aucun humain n'a
  réexaminée doit se voir.

### Une demande devient caduque

`caduque` est un **statut distinct de `refusée`** : personne ne l'a refusée, le monde a bougé.
Une demande devient caduque quand, au moment de la validation, l'ancienne valeur d'un de ses
ops ne correspond plus à la base, ou quand la personne visée a été supprimée ou fusionnée
(événement de `Tree`, ADR 0002), ou — cas propre à la création — quand l'identifiant
provisoire référence une personne disparue entre-temps.

- **La caducité est détectée à la validation, pas par une tâche de fond.** À ce volume, un
  balayage périodique des demandes serait de la mécanique pour rien, et la file est courte.
- **Une exception, et une seule : la suppression ou la fusion d'une personne.** Elle est
  traitée **immédiatement, par abonnement à l'événement que publie `Tree`** (ADR 0002,
  compensation 2), et non à la prochaine validation. Ce n'est pas une contradiction avec la
  ligne ci-dessus, et les deux textes doivent se lire ensemble : un **décalage de version**
  ordinaire se constate à la validation ; une **cible qui disparaît** est un fait rare et
  destructeur, qui laisse une demande pointant vers le vide, et qu'on ne laisse pas dormir
  dans la file jusqu'à ce que quelqu'un l'ouvre. Ce n'est toujours pas une tâche de fond :
  c'est une réaction à un événement précis.
- L'auteur est notifié **avec le diff de ce qui a changé sous ses pieds**, sa demande reste
  consultable, et un bouton « refaire cette demande » la recrée pré-remplie sur les valeurs
  courantes. Une caducité sans ce bouton est une contribution perdue.

### L'administrateur est aussi l'auteur

Deux situations qu'il ne faut pas confondre.

- **Il édite directement.** L'écriture est auto-approuvée, mais passe par le mécanisme :
  `ChangeRequest` (statut `validée`, auteur = validateur) et `Revision`. Aucun chemin
  parallèle. C'est ce qui garantit que l'historique est complet et que le calque « en
  attente » n'a pas de cas particulier à gérer.
- **Il valide une demande qu'il avait déposée plus tôt.** Autorisé, et **marqué comme tel** :
  la colonne `prp_change_request.auto_validated` passe à `true`, et le journal d'audit le
  reprend (`auto_validation = true`). En colonne et pas seulement dans le `JSONB` d'audit,
  pour que « montre-moi toutes les auto-validations » soit une requête et non une fouille.
  L'édition directe ci-dessus est le cas particulier de celui-ci : elle porte le même
  marqueur. On ne l'interdit pas : il n'y a qu'un
  administrateur, l'interdire bloquerait le produit. On le rend visible, parce qu'une décision
  d'administration indistinguable d'une contribution ordinaire est exactement ce qu'un journal
  d'audit doit éviter.
- Si un second administrateur apparaît un jour, la règle ne change pas. On n'introduit pas de
  séparation des rôles pour un besoin qui n'existe pas.

### Plusieurs demandes visent la même personne manquante

Deux cousins peuvent proposer le même arrière-grand-oncle. L'écran de validation rapproche les
doublons probables par **nom + année de naissance** (avec la tolérance qu'impose une fourchette
de dates), et l'administrateur peut refuser l'une **en renvoyant à l'autre** — le motif
obligatoire sert exactement à ça.

Depuis le 2026-10-06, le doublon est aussi signalé **avant** le dépôt, au membre lui-même
(contrat d'API § 6.3 ter) : contre l'arbre — sous le filtre de la recherche, cercle et
réciprocité compris —, contre **ses** demandes en attente, et, pour les demandes des **autres**,
par un simple drapeau sans contenu, levé pour une personne décédée seulement. Le contenu d'une
demande en attente reste l'affaire de son auteur et des administrateurs ; le rapprochement de
l'administrateur à la validation reste le filet, en particulier pour les vivants.

## Alternatives considérées

- **Le wiki** : traité ci-dessus.
- **Deux mécanismes, un pour la modification et un pour la création.** Écarté explicitement au
  § 6.4 du dossier de mission, et à raison : deux files, c'est deux écrans d'administration,
  deux formats de diff, deux chemins de notification, et deux endroits où oublier le message
  obligatoire au refus.
- **Application immédiate avec fenêtre d'annulation de 24 h.** C'est le wiki avec un délai :
  la fuite a lieu pendant la fenêtre.
- **Validation par quorum de membres** (deux cousins valident). Séduisant, inapplicable à
  cette échelle, et déplace la responsabilité d'une donnée personnelle vers des gens qui ne
  l'ont pas demandée.
- **Verrou pessimiste** sur la fiche tant qu'une demande est en attente. Garantit l'absence de
  conflit, au prix d'une fiche bloquée par la première demande déposée, éventuellement pendant
  des jours. À quelques demandes par semaine, le conflit est rare et le verrou optimiste le
  traite proprement.
- **Ne pas tracer les écritures d'administrateur** (« c'est lui le patron »). Écarté : le
  journal d'audit sans les actions de l'administrateur ne sert à rien, et l'historique d'une
  fiche avec des trous est pire qu'aucun historique.

## Conséquences

- Le **calque « en attente » se calcule à la sérialisation**, comme le cercle de visibilité et
  pour le même lecteur. Les deux filtres sont composés dans le même normalizer (ADR 0003).
- C'est ce qui impose au moteur de placement de savoir produire un graphe **pour un lecteur
  donné** (ADR 0005).
- **Une demande n'est jamais supprimée**, qu'elle soit refusée ou caduque : elle est
  l'historique de la discussion familiale autour d'une fiche.
- Le journal d'audit trace **toute décision** — validation, refus avec motif, caducité
  constatée — avec l'acteur et l'horodatage.
- Une validation invalide le cache de placement **et** tous les cercles de visibilité. Les
  deux, systématiquement : un ajout de personne change la topologie, donc potentiellement des
  cercles. « Invalider » ne demande aucune action : les deux clés portent l'empreinte de
  l'arbre, que l'écriture change d'elle-même (ADR 0005).
- **Révision du 2026-09-15 — deux verrous au lieu d'un.** La décision ci-dessus disait
  « verrou optimiste sur `Person.version` ». Elle est amendée : il en faut deux, et sur
  trois tables. Motif : un `#[ORM\Version]` seul ne peut pas détecter une écriture survenue
  entre la soumission et la validation, l'entité étant rechargée entre les deux — la
  position initiale n'était pas implémentable telle quelle. Le second verrou
  (`prp_change_op.base_version`) est aussi ce qui rend possible la règle de rejeu ci-dessus,
  qui suppose de comparer op par op. Détail dans `docs/modele-donnees.md` § 9.6, journal dans
  `docs/arbitrages.md`.
- **Révision du 2026-10-06 — une création se complète avant décision.** Jusque-là, une demande
  déposée ne changeait plus que de statut (soumise, retirée, tranchée). Le commanditaire veut
  qu'un membre qui s'apprête à reproposer une personne qu'il a déjà proposée puisse **mettre à
  jour sa demande existante** plutôt que d'en déposer une seconde. D'où
  `POST /api/demandes/{id}/brouillons/{ref}/completer` (contrat d'API § 6.3 ter), borné à ce
  qui ne fait pas de tort au circuit :
  - **l'auteur seulement**, administrateur compris, et **avant toute décision**
    (`brouillon` ou `en_attente`) — une demande tranchée est l'historique d'une discussion,
    elle ne se réécrit pas ;
  - **une `creation_personne` seulement** : une modification de fiche porte des `oldValue` et
    une `baseVersion` que compléter fausserait ; elle se retire et se redépose ;
  - **on ajoute, on ne retire pas** : ce qui est saisi complète ou remplace, ce qui est laissé
    vide ne vide rien, la note et le message s'ajoutent ; seule la filiation saisie remplace
    celle de la fiche, parce qu'une personne n'a qu'un couple de parents ;
  - **les mêmes contrôles qu'au dépôt**, par le même constructeur, et la clé de doublon
    recalculée ; la demande **garde sa place** dans la file (`submittedAt` inchangé) ;
  - **tracé** comme toute écriture, par le journal mécanique : chaque colonne de la fiche
    proposée, chaque lien ajouté ou remplacé, avec l'acteur.
  Ce n'est pas le « rebasage » écarté plus haut : celui-là réécrivait une demande sur une
  version de l'arbre qui avait bougé ; celui-ci laisse l'auteur préciser **sa propre
  proposition** tant qu'elle n'est pas tranchée. L'administrateur lit la demande
  telle qu'elle est quand il l'ouvre, comme toute demande.
