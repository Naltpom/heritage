# ADR 0003 — Confidentialité : le cercle de visibilité

Date : 2026-09-15 · Statut : acceptée · Révisée le 2026-10-01 (régime en attente : plus
rien de l'arbre ; vivants hors cercle anonymes ; **puis, le même jour, vivants visibles de
tout membre validé — le cercle ne ferme plus que les fiches confidentielles**)

> **À lire d'abord — révision du 2026-10-01, « les vivants visibles des membres ».** Depuis
> cette date, un membre validé (régime `Cercle`) et l'administrateur voient **toutes** les
> personnes de l'arbre, vivants compris. La formule `C(u)` ci-dessous est conservée, mais
> elle ne décide plus que du sort des fiches à **confidentialité forcée** (`force_privacy`).
> Le compte en attente ne voit toujours rien de l'arbre. Partout où ce texte écrit
> « personne vivante hors cercle », lire, pour un membre : *fiche confidentielle hors de
> son cercle*. Le détail, le motif et ce qui reste protégé : dernière révision de la
> section « Conséquences ».

## Contexte

L'arbre compte **274 personnes dont 18 vivantes**. Ces 18 personnes n'ont pas demandé à
figurer dans une application ; elles y sont parce qu'elles sont de la famille. Le principal
risque du produit n'est pas qu'on lise une fiche de 1791, c'est qu'un compte serve d'oracle
pour énumérer et documenter les vivants.

Le commanditaire a formulé la règle ainsi : *« on remonte à l'arrière-grand-parent, du coup
voir les enfants de mes tantes et tontons et leurs enfants, voir les frères et sœurs de ma
grand-mère leurs enfants et leurs petits-petits-enfants etc. 3 parents au-dessus et on
descend. »*

## Décision

Soit `u` la personne rattachée au compte du lecteur, et `N` le réglage
`core_setting['cercle.generations']`, **valeur par défaut 3**, borné à
`1 ≤ N ≤ 6` (table et clé exactes : `docs/modele-donnees.md` § 2.2).

```
A(u) = { u } ∪ { a : a ancêtre de u, distance(a, u) ≤ N }
D(u) = { d : d descendant, à toute profondeur, d'un membre de A(u) }
C(u) = A(u) ∪ D(u) ∪ { c : c conjoint d'un membre de A(u) ∪ D(u) }
```

**Les conjoints sont ajoutés en une seule passe, en dernier, et ne propagent rien.** Le
conjoint d'un conjoint n'entre pas dans le cercle ; les ancêtres d'un conjoint non plus.
Ce n'est pas un point fixe. Aucune des deux formulations — celle du commanditaire ni
l'écriture ensembliste ci-dessus prise naïvement — ne dit cela à voix haute, et c'est
pourtant ce qui sépare un cercle de trente personnes d'un cercle qui avale la moitié de
l'arbre au premier remariage. Un agent qui itère jusqu'à stabilité implémente autre chose
que ce qui est demandé.

Règles d'application :

- **La règle ne concerne que les personnes vivantes** (`mort = false`). Une personne décédée
  est visible de tout membre connecté, sans restriction.
- Hors cercle, une personne vivante n'expose que : son **identifiant**, son **sexe**, sa
  **génération**, sa **branche**, le booléen **`vivant`**, sa **parenté vue du lecteur**
  (`parente`, `pont`), sa **position dans le graphe** et ses **arêtes**. **Ni nom**, ni date,
  ni lieu, ni source, ni citation, ni média, ni portrait, ni rattachement à un compte. La clé
  `displayName` reste dans les charges, mais elle vaut le libellé neutre **« Personne
  vivante »** — partout où la personne apparaît, y compris dans la fiche visible d'un proche.
  En colonnes et champs calculés : `id`, `sex`, `generation`, `branch`, `vivant`. C'est la
  liste blanche que recopient les tests des portes de sortie.
  *La branche et `vivant` ont été ajoutés à cette liste après coup, pour deux motifs
  différents ; le nom en a été retiré le 2026-10-01 — voir « Conséquences ».*
- **Tout champ dérivé d'une donnée masquée est lui-même une donnée masquée.** Une liste
  blanche raisonne champ par champ ; elle ne voit pas un champ *légitime* dont la **valeur**
  est calculée sur un champ interdit. Cas concret : la largeur d'un nœud du graphe se calcule
  sur le texte affiché — calculée sur les vraies dates, elle encode leur longueur pour une
  personne masquée. La règle se relit à chaque champ calculé : largeur, compte de caractères,
  ordre de tri, nombre de résultats.
- **Le filtrage est appliqué dans la sérialisation**, côté serveur. Un champ masqué n'est pas
  envoyé puis caché : il n'est pas envoyé.
- **La recherche ne renvoie jamais une personne vivante hors cercle.** Ni en résultat, ni en
  suggestion, ni en libellé de rapprochement de doublon.
- Un compte **sans rattachement validé** (inscription en attente) **n'a pas de cercle**, et
  **ne lit rien de l'arbre** — ni vivants, ni défunts : toute route de lecture lui répond
  `403 regime_en_attente`. Il obtient l'arbre et son cercle à l'activation par
  l'administrateur. *Rédaction initiale : il voyait les personnes décédées (« l'attente peut
  durer des jours, un écran vide pousse à l'abandon ») ; révisée le 2026-10-01 — voir
  « Conséquences ».*
  **Ce n'est pas un cercle réduit, c'est un régime distinct** : ni une liste vide, ni
  surtout « un cercle avec `N = 0` » — un `N = 0` reste un cercle, il contient encore `u` et
  ses conjoints, donc il laisserait sortir une fiche vivante. La nuance disparaîtrait au
  premier remaniement si elle n'était pas écrite ici.
- L'administrateur voit tout, via une bascule **« voir comme un membre »** qui applique le
  cercle d'un compte choisi — commodité et moyen de vérifier la règle.
- Le cercle est **calculé puis mis en cache par compte**, invalidé à toute modification validée
  de l'arbre, à tout import et à tout changement de `N`. L'invalidation ne passe par aucun
  compteur : la clé porte une **empreinte recalculée depuis la base** (ADR 0005), et `N`.

## Justification

**Pourquoi les personnes décédées échappent à la règle.** Sur 274 personnes, 256 sont
décédées. Une règle qui les masquerait couperait l'arbre en morceaux et rendrait le produit
inutile — pour un gain de confidentialité nul : l'état civil de plus de 75 ans est en ligne
et gratuit sur les sites d'archives départementales, c'est même d'où viennent la plupart de
ces fiches. On protège les 18 personnes qu'on peut réellement protéger.

**Pourquoi les conjoints sont inclus.** Un couple ne se lit pas à moitié. Un nœud de famille
avec un époux nommé et l'autre muet est **plus bavard** que les deux nommés : il désigne
précisément « le conjoint de X », ce qui suffit souvent à identifier la personne, tout en
rendant la fiche illisible. Inclure le conjoint ne révèle presque rien de neuf — il est déjà
dans l'entourage du lecteur par alliance — et rend l'arbre lisible.

**Pourquoi `N` est un réglage et non une constante.** Le chiffre 3 est une intuition, formulée
avec hésitation. Il sera confronté à la réalité au premier membre invité : soit un cousin qui
ne voit presque rien, soit une branche entière ouverte à quelqu'un qu'on n'attendait pas. Un
chiffre qu'on découvre faux et qu'on ne peut changer qu'en redéployant sera changé en dur dans
le code par le premier qui passe, sans trace et sans discussion. En réglage d'administration,
le changement est daté, tracé au journal d'audit, réversible. La borne haute à 6 n'est pas
décorative : à 12 générations, au-delà, le cercle couvre l'arbre entier et la règle ne veut
plus rien dire.

**Pourquoi le filtrage est dans la sérialisation et pas dans le contrôleur.** Les données
d'une personne sortent de l'API par **sept portes** : la fiche, le graphe, la recherche, la
chronologie, les métadonnées d'un média, une notification, le diff d'une proposition — sans
compter les réponses d'erreur et les journaux. Un contrôleur filtre une route ; il en oublie
une, et ce sera la septième. Le seul point traversé par les sept est le normalizer. On y écrit
le filtre une fois, on l'audite une fois.

**Il y a une huitième porte, et elle ne traverse pas ce normalizer : les libellés
dénormalisés.** La règle de frontière de l'ADR 0002 supprime les jointures inter-modules et
les remplace par un identifiant plus un libellé recopié. Résultat : neuf colonnes `*_label`
sur six tables de `Membership`, `Proposal` et `Media` stockent le nom d'une personne
potentiellement vivante, et une file de demandes sérialise ces colonnes sans jamais passer
par `Tree\Person`. C'est la contrepartie structurelle de la modularité, elle est assumée,
mais elle impose une règle :

> **Toute colonne `*_label` désignant une personne est filtrée comme la personne
> elle-même.** Les tests de liste blanche ci-dessous couvrent les réponses de `Proposal`,
> `Membership` et `Media`, pas seulement celles de `Tree`.

Liste des colonnes concernées : `docs/modele-donnees.md` § 0.4.

Corollaire non négociable : **le normalizer n'a pas de mode « sans filtre »**. La vue
administrateur passe par un cercle complet, pas par un contournement. Une branche
`if ($estAdmin) { return $tout; }` est exactement le bug qu'on découvrira dans deux ans, le
jour où un rôle sera attribué par erreur.

## Le risque résiduel : la topologie reste visible

Il faut le nommer, parce qu'il est réel et qu'il est accepté.

Hors cercle, un lecteur voit tout de même **qu'un nœud existe, comment il s'appelle, et où il
se rattache**. Et comme seules les fiches vivantes sont masquées, **le masquage est son propre
indice** : une fiche muette est une fiche de personne vivante. Un lecteur hors cercle apprend
donc, pour chaque nœud muet : cette personne est vivante, elle porte ce nom, elle est l'enfant
d'untel et le conjoint d'untel.

**C'est accepté**, pour deux raisons.

1. **Le produit est un arbre.** Un arbre dont on retire des nœuds n'est plus un arbre : les
   branches flottent, les fratries sont fausses, le calcul de parenté est faux. Livrer un
   graphe amputé, c'est livrer un graphe mensonger — et un lecteur qui compte six enfants dans
   un acte et en voit quatre à l'écran conclura à un bug, pas à une règle de confidentialité.
2. **Le public est une famille invitée nommément.** Aucune inscription ouverte, un compte par
   personne, chaque accès journalisé. Le modèle de menace est « un cousin curieux », pas « un
   inconnu qui aspire l'arbre ».

Ce qui n'est **pas** accepté, et qui est la vraie frontière : que ce lecteur puisse
**découvrir** des vivants qu'il ne connaît pas, et les documenter. D'où les deux garde-fous
qui encadrent le risque :

- à l'inscription (§ 6.1) : au plus 3 candidats renvoyés, affichage minimal, 5 tentatives par
  heure et par jeton ;
- ici : **le graphe montre, il n'énumère pas.** Pas de liste, pas d'export, pas de recherche
  sur les vivants hors cercle. Voir le nœud d'une personne qu'on connaît déjà est acceptable ;
  obtenir la liste des vivants ne l'est pas.

  > ⚠ **Cette phrase est amendée depuis le 2026-09-23 par l'[ADR 0008](0008-questions-et-modele-de-langage.md).**
  > Elle reste vraie pour **tout compte**, membre ou administrateur, et pour toute porte de
  > sortie de l'API. Elle cesse d'être vraie pour **un tiers unique, nommé, sous contrat** :
  > le fournisseur du modèle de langage qui formule les questions reçoit l'arbre entier,
  > vivants compris — arbitrage du commanditaire, rendu en connaissance de cette réserve.
  > Le modèle de menace ci-dessus (« un cousin curieux, pas un inconnu qui aspire l'arbre »)
  > gagne donc un acteur. Lire l'ADR 0008 § 2 pour ce qui est amendé **exactement**, et
  > surtout pour ce qui ne l'est pas : aucune règle de filtrage servie à un lecteur n'est
  > relâchée.

Limite restante, écrite noir sur blanc : un lecteur patient peut parcourir le graphe nœud par
nœud et reconstituer cette liste à la main. C'est vrai. On la rend coûteuse et on la rend
visible — l'accès au graphe est journalisé. On ne va pas plus loin dans cette livraison. Si la
famille s'élargit au point que ce ne soit plus tenable, la décision sera révisée par un
nouvel ADR ; elle ne l'est pas ici.

## Alternatives considérées

- **Masquer entièrement le nœud hors cercle.** Écartée : casse l'arbre (voir ci-dessus), et
  le trou est lui-même une information — un enfant manquant entre deux frères se voit à l'œil
  nu, sans même le nom.
- **Filtrer côté client** : le champ arrive et l'interface le cache. Écartée, définitivement.
  Il suffit d'ouvrir l'onglet réseau du navigateur.
- **Liste blanche manuelle** (l'administrateur choisit qui voit qui). Plus exact, et
  ingérable : quelques dizaines de comptes × 274 personnes à maintenir à la main, et chaque
  personne ajoutée rouvre le travail. Une règle calculée se vérifie ; une liste se périme.
- **Cercle symétrique « parenté ≤ k »**, plus simple à écrire. Écartée : ce n'est pas la
  demande, qui est explicitement **asymétrique** — on remonte de `N`, on redescend sans
  limite. Et l'asymétrie est juste : mes cousins, même éloignés, sont ma famille ; les
  ancêtres de mon beau-frère ne le sont pas.
- **Drapeau « privé » par fiche**, à la webtrees ou à la Gramps. Écartée : c'est du binaire,
  il ne dépend pas du lecteur, et il faudrait le maintenir à la main à chaque décès et à
  chaque naissance.

## Conséquences

- **Cache par compte**, clé = (compte, `N`, empreinte de l'arbre) — soit
  `cercle.{empreinte}.{memberId}.{N}` (`docs/modele-donnees.md` § 9.3). **Invalidation
  globale, pas ciblée** : à cette échelle, recalculer tous les cercles coûte moins cher que
  raisonner juste sur une invalidation fine — et un raisonnement faux sur l'invalidation
  d'un cercle est une fuite, pas un ralentissement.
- **Révision du 2026-09-15 — la branche entre dans la liste blanche.** La rédaction
  initiale listait la génération et la position dans le graphe, mais pas `branch`. C'est une
  omission, pour une raison de rendu : le canevas **colore** les nœuds par quart
  d'ascendance, et la vue chronologique en fait ses colonnes. Un nœud servi sans sa branche
  est un nœud que le canevas partagé ne sait pas colorer — et qui se singularise donc à
  l'écran, ce qui est l'inverse du but.
  Ce que `branch` révèle est **déjà déductible** de ce qu'on envoie : la branche est
  déterminée par le chemin qui relie la personne à la racine, et ce chemin, ce sont les
  arêtes — que tout lecteur reçoit. Un client pourrait la recalculer lui-même. La révision
  n'élargit donc pas ce qui fuit ; elle rend explicite ce que le risque résiduel accepté
  plus haut couvrait déjà, et elle évite qu'un agent l'ajoute en silence en croyant
  enfreindre l'ADR.
  *Correction du raisonnement, même jour* : cette révision s'appuyait d'abord sur l'idée que
  `branch` était une **coordonnée du placement** (« l'ordre horizontal suit la branche »).
  La lecture du moteur retrouvé montre que c'est faux — le placement récurse sur
  l'ascendance depuis l'ego et ne lit jamais `branch` (ADR 0005). La conclusion tient, son
  motif est celui écrit ci-dessus.
- **Révision du 2026-09-15 — `vivant` entre dans la liste blanche, et *pas* pour la raison
  de `branch`.** Le rendu en a besoin : l'ADR 0007 conserve `.fiche.prive`, qui se pose à
  partir de ce booléen. Mais l'argument qui justifie `branch` — « elle se déduit des arêtes
  qu'on envoie déjà » — **ne se transpose pas** : l'état vivant ne se déduit d'aucune arête.
  Trois motifs propres le justifient. Le rendu **l'affiche déjà en toutes lettres** : la
  ligne de dates d'un nœud masqué vaut la constante « vivant·e ». Le **masquage le dit de
  lui-même**, et c'est le risque résiduel que cet ADR accepte plus bas — une fiche muette est
  une fiche de vivant. Et ne pas l'envoyer serait **pire** : le client devrait inférer l'état
  vivant de l'absence de champs, c'est-à-dire faire côté client exactement ce que cet ADR
  interdit. Le gain d'information pour un lecteur hors cercle est nul ; l'explicite vaut
  mieux que l'implicite.
  *À retenir de ces deux révisions* : deux ajouts à une liste blanche peuvent être tous deux
  justes et n'avoir aucun argument en commun. Un motif qui marche pour un champ n'autorise
  pas le suivant.
- **Amendement du 2026-09-23 — un tiers reçoit l'arbre entier ([ADR 0008](0008-questions-et-modele-de-langage.md)).**
  Ce n'est pas une révision comme les autres : les précédentes corrigeaient un texte qu'une
  mesure avait démenti ; celle-ci enregistre une **décision** qui contredit une règle de cet
  ADR. Une seule phrase tombe — « pas de liste, pas d'export » —, et **pour un seul
  destinataire**, qui n'est pas un compte. Ce qui reste intact, et qu'il faut relire comme
  tel : la formule `C(u)`, la liste blanche des sept champs, le filtrage dans la
  sérialisation, l'absence de mode « sans filtre », le régime `Aucun`. La règle des colonnes
  `*_label` s'étend même : `qst_question.sujet_label` **et** `qst_question.texte` en
  relèvent, le second étant le premier cas d'une phrase en français qui contient un nom —
  qu'aucune liste blanche champ par champ n'aurait attrapée, et que seule la règle « tout
  champ dérivé d'une donnée masquée est lui-même une donnée masquée » couvre.
- **Révision du 2026-10-01 — un compte en attente peut n'avoir aucune fiche
  ([ADR 0013](0013-inscription-sans-fiche.md)).** Un candidat qui ne se trouve pas dans
  l'arbre crée désormais un compte `a_rattacher`, dont `mbr_member.person_id` est **nul**. Il
  relève du régime `Aucun` **sans rien de plus ni de moins** : pas de cercle, les défunts,
  aucune écriture. Ce qui change est ailleurs : ce régime s'obtient maintenant **sans rien
  prouver** — il suffit du lien —, si bien que l'argument de l'« oracle inverse » qui gardait
  les défunts dans la recherche de rattachement ne tient plus, et ils en sont retirés. La
  formule, la liste blanche, le filtrage dans la sérialisation et l'absence de mode « sans
  filtre » sont intacts. Une nouvelle colonne relève de la règle des `*_label` sans en porter
  le nom : `mbr_member.family_description`, un texte libre qui nomme des vivants, réservé aux
  administrateurs — et refusé même à eux sous la bascule « voir comme un membre », faute de
  cercle capable de le filtrer.
- **Révision du 2026-10-01 — le régime en attente ne voit plus rien de l'arbre.** Décision
  du commanditaire, après un essai : un compte de test inscrit par le lien d'invitation, sous
  une fausse date de naissance, voyait l'arbre.
  *Ce qui fuyait.* Le régime `Aucun` filtrait bien les fiches, mais le graphe sert **chaque**
  personne en nœud — et un vivant hors cercle y garde, par la liste blanche, son **nom
  complet**, son sexe, sa génération et sa branche. Ce qui est acceptable pour un membre
  validé — un cousin dont le cercle s'arrête avant vous sait déjà que vous existez — ne l'est
  pas pour un inconnu. Or le lien d'invitation **circule dans un groupe de messagerie** :
  n'importe qui peut s'inscrire sous un faux nom, et lisait alors la liste des vivants de la
  famille, sans rien avoir prouvé (l'ADR 0013 a même retiré l'obligation de se trouver dans
  l'arbre). L'argument « l'arbre ancien n'expose rien de sensible » était juste pour les
  fiches, faux pour le graphe.
  *Ce qui change.* Un compte en attente — avec ou sans fiche, `a_rattacher` compris — reçoit
  `403 regime_en_attente` sur **toute** lecture de l'arbre : graphe, fiches, familles,
  recherche, chronologie et ses préférences, carte, anniversaires, médias d'une personne,
  historique d'une fiche, inventaire d'une suppression, questions, tirages, actes demandés.
  Le refus est rendu **avant** le contrôleur (`Core\Security\ReserveAuxMembres`), donc avant
  tout chargement et toute revalidation d'`ETag`. Seule exception : un média qu'il a
  lui-même déposé — sa photo d'inscription —, que l'écran d'attente lui remontre ; tout autre
  média lui rend le même `403`, qu'il existe ou non. Lui restent ouvertes les routes **du
  compte** : session, Mon compte, notifications, push, état de l'inscription, déconnexion.
  Côté front, l'écran d'attente devient sa page par défaut, et la navigation ne lui montre
  que son inscription, Mon compte et la déconnexion.
  *Ce qui ne change pas.* La formule `C(u)`, la liste blanche, le filtrage dans la
  sérialisation et le régime `Aucun` lui-même, qui sert encore au compte **actif sans
  rattachement** (le défaut fermé de `Membership`). Le filtrage des vivants pour un membre
  validé est inchangé.
  *Le prix, assumé.* L'écran d'attente est vide de l'arbre pendant plusieurs jours. C'est la
  notification « Votre compte est activé » (`compte_active`, envoyée aussi sur le téléphone,
  lien vers l'accueil) qui le fait revenir.
- **Révision du 2026-10-01 — le nom d'un vivant hors cercle ne sort plus.** Décision du
  commanditaire, en préparant l'ouverture aux membres invités : *« une personne vivante hors
  du cercle du lecteur n'expose plus son nom »*.
  *Ce qui sortait.* La liste blanche laissait passer `display_name`, et le graphe le
  servait sur chaque nœud restreint, avec sa troncature `etiquetteNom`. La justification
  d'origine — « un cousin dont le cercle s'arrête avant vous sait déjà que vous existez » —
  tient pour **l'existence**, pas pour le nom : le graphe est la liste nominative des
  vivants de toute la famille, cercle ou pas. Et la porte n'était pas seulement le graphe :
  la **fiche visible d'un défunt** nommait ses enfants, son conjoint, sa fratrie vivants
  (`parents`, `fratrie`, `unions[].conjoint`, `unions[].enfants`) sans consulter le cercle,
  la fiche de famille nommait ses enfants sans aucun contrôle, et l'assistant citait les
  personnes restreintes « par leur nom seul ».
  *Ce qui change.* Partout où une personne vivante hors cercle est sérialisée — nœud du
  graphe, fiche réduite, références d'une fiche visible, époux et enfants d'une famille,
  personnes citées par l'assistant —, `displayName` (et `etiquetteNom` sur un nœud) valent
  **« Personne vivante »** (`Core\Security\CercleDeVisibiliteInterface::NOM_MASQUE`). Le
  vrai nom ne quitte pas le serveur. La personne **reste** dans les listes et dans le
  graphe, à sa place : une fratrie amputée serait fausse, et le cercle masque des champs,
  pas des nœuds. La recherche, elle, l'omettait déjà ; inchangée.
  *Les noms enregistrés, pas seulement les noms servis.* Trois modules **recopient** un nom
  au moment d'écrire, et le rendaient ensuite tel quel : un **tirage** au sort enregistre
  le nom de chaque participant (`Participant.personLabel`), une **demande** ses libellés
  (`targetLabel`, l'extrémité de chaque lien, `familyLabel`, le sujet de ses révisions), et
  l'**assistant** laisse le modèle — qui connaît le vrai nom pour reconnaître la personne
  citée — rédiger titre, justification, résumé et avertissements. Désormais : un tirage
  n'inscrit que des personnes **du cercle** de qui les désigne (refus identique à celui
  d'une personne inconnue, pour ne pas servir d'oracle) ; une demande relue par un membre
  rend le masque à la place de chaque libellé d'un vivant hors de son cercle (le libellé
  stocké reste intact, l'administrateur le lit) ; la consigne de l'assistant lui interdit de
  nommer une personne confidentielle, et le serveur remplace dans ses textes libres le nom
  de chaque personne restreinte croisée pendant l'analyse. Et l'historique d'une fiche
  vivante hors cercle — `404` au contrat (§ 6.11) depuis l'origine — est enfin refusé par
  la route, qui le servait.
  *Le champ dérivé — la règle qu'il fallait relire.* Une fois le nom retiré, sa **longueur**
  restait servie : la largeur `w` d'une fiche était mesurée sur le nom (et, depuis
  l'affichage de la naissance des vivants, sur la date), et l'écart entre deux fiches
  voisines la redonnait par les `x`. La largeur affichée de **toute** fiche vivante est
  désormais une constante (215), pour tous les lecteurs — elle ne peut pas dépendre du
  lecteur, le placement est partagé (ADR 0005) —, et le moteur reçoit un jeu où les vivants
  n'ont ni nom ni date (`JeuDePlacement::sansTexteDesVivants()`), si bien que même ses
  abscisses de départ ne les lisent plus. Le moteur lui-même et sa preuve des 377 positions
  sont inchangés. Le prix : des fiches de vivants un peu plus larges pour tout le monde, et
  le nom d'un vivant **visible** tronqué au-delà de 21 signes.
  *Ce qui entre en même temps dans la liste blanche du graphe* : `parente` (famille,
  conjoint, alliance — vu du lecteur) et `pont`, qui rangent les **familles par alliance**
  (décision du même jour, contrat d'API § 5.2). Même motif que `branch` : ils se déduisent
  des arêtes que tout lecteur reçoit.
  *Ce qui ne change pas.* La formule `C(u)`, le filtrage dans la sérialisation, l'absence de
  mode « sans filtre » : l'administrateur hors bascule voit tous les noms, par un cercle
  complet. Sous la bascule « voir comme un membre », il voit ce que le membre voit — le
  masque.
- **Révision du 2026-10-01 — les vivants visibles des membres.** Décision du
  commanditaire, le même jour que les deux précédentes et après elles : **les membres
  sont de la famille.** Un compte validé a été invité nommément, rattaché à sa fiche et
  activé par l'administrateur ; lui cacher le nom et la date de naissance de la fille
  d'un petit-cousin, c'est traiter en suspect quelqu'un qu'on a choisi de faire entrer.
  Le modèle de menace écrit plus haut — « un cousin curieux » — est maintenu, et l'arbitrage
  est rendu en connaissance de cause : le cousin curieux lit désormais tout ce que l'arbre
  sait des vivants, sauf ce que l'administration a explicitement fermé.
  *Ce qui devient visible à un membre validé* (et à l'administrateur sous la bascule) :
  **toute personne vivante**, partout où elle sort — nom (`displayName`, `etiquetteNom`),
  dates, lieux, sources, citations, notes, fiche complète, références d'une fiche ou d'une
  famille, nœud complet du graphe, résultats de recherche, barres de la chronologie,
  anniversaires, carte, historique d'une fiche, médias et portraits, libellés `*_label`
  des demandes, des tirages et des actes demandés, personnes citées par l'assistant et
  textes qu'il rédige, questions.
  *Ce qui reste protégé, explicitement :*
  1. **Le compte en attente ne voit rien de l'arbre** — `403 regime_en_attente` avant le
     contrôleur, révision précédente du même jour, inchangée. Le régime `Aucun` lui-même
     non plus : aucune personne vivante, confidentielle ou non, pour quiconque y relève
     (compte actif sans rattachement valide, bascule sur un compte inactif).
  2. **La fiche à confidentialité forcée** (`tree_person.force_privacy`, avec son
     `privacy_note` obligatoire) reste **réduite** — sous « Personne vivante », liste
     blanche, `404` sur l'historique et les médias, absente de la recherche et de la
     chronologie — pour tout lecteur qui ne l'a pas dans son cercle `C(u)`. **Exactement
     comme avant** : `force_privacy` faisait déjà de la fiche une fiche « vivante » au sens
     du § 9.2, soumise au cercle ; la voient donc, comme hier, les membres dont le cercle
     généalogique la contient, et l'administrateur par son cercle complet.
  *Où c'est écrit, et pourquoi là.* Une ligne de
  `Membership\Service\CercleDeVisibilite::voitPersonne()` et de `filtrerPersonnes()` :
  en régime `Cercle`, un vivant **non confidentiel** est visible ; sinon, le cercle
  décide. Tous les modules — `Tree`, `Media`, `Timeline`, `Proposal`, `Assistant`,
  `Question`, `Tirage`, `Demarche`, `Almanach` — posent leur question à ce contrat-là et
  à lui seul ; la réponse change donc partout d'un coup, sans un `if` de plus chez eux.
  Le drapeau traverse le port de `Membership` (`PersonneDeLArbre::$confidentielle`,
  **sans valeur par défaut**) et se lit dans la vue de l'arbre, rechargée à chaque
  requête ; un identifiant inconnu vaut confidentiel. *Ce n'est pas un mode « sans
  filtre »* : la fiche confidentielle passe toujours par le cercle, le normaliseur reste
  le seul point de filtrage, `NOM_MASQUE` et toute la mécanique de masquage restent en
  service pour elle.
  *Les caches.* La valeur mise en cache sous `cercle.{empreinte}.{memberId}.{ancre}.{N}`
  reste le cercle **généalogique** : son sens ne change pas, les entrées écrites avant le
  déploiement restent justes, et il n'y a aucune version de clé à monter. Le drapeau
  `force_privacy` n'y entre pas : il est lu dans la vue de l'arbre, rechargée à chaque
  requête (et le poser change de toute façon l'empreinte, qui couvre toutes les colonnes
  de `tree_person`). L'`ETag` du graphe hache l'ensemble des fiches
  servies **complètes** (`CercleDuLecteur::empreinte()`) : il change de lui-même pour tout
  membre au déploiement — aucun `304` ne resservira une charge masquée. La mémoire de
  l'assistant (`MemoireDesAnalyses`, deux heures) garde des titres et justifications
  **déjà masqués** sous l'ancienne règle, sans rien dans la clé qui le dise : son préfixe
  passe à `assistant.analyse.v2`.
  *Ce qui ne change pas, et qu'il faut savoir.* La largeur constante (215) des fiches de
  vivants et le jeu de placement sans texte des vivants (`sansTexteDesVivants()`) sont
  conservés : le placement est partagé et doit le rester (ADR 0005), et la fiche
  confidentielle en a toujours besoin. Les **notifications déjà écrites** gardent le
  masque qu'elles portaient le jour de leur composition : elles sont composées à
  l'écriture (contrat d'API § 16.2), et on ne réécrit pas l'historique.
  *L'alternative « drapeau privé par fiche »*, écartée plus haut comme règle principale,
  devient ainsi la seule fermeture restante pour un membre. L'objection d'origine tient
  toujours — c'est un binaire, maintenu à la main — et c'est précisément ce que le
  commanditaire choisit : fermer à la main les rares fiches qui doivent l'être, plutôt que
  fermer par défaut toutes les autres.
- La bascule « voir comme un membre » est **tracée au journal d'audit** : c'est une
  consultation de données personnelles sous une autre identité.
- **Un test fonctionnel par porte de sortie** (les sept ci-dessus, **plus les libellés
  dénormalisés**) : chacune est interrogée
  avec un compte hors cercle, et la réponse est comparée à une **liste blanche** de champs —
  pas à une liste noire, qui oublierait tout champ ajouté plus tard. C'est le garde-fou réel
  de cet ADR ; sans lui, le reste est une intention.
- Les fixtures doivent contenir des personnes vivantes réparties sur plusieurs branches,
  sinon ces tests ne prouvent rien (ADR 0006).
- Le partage du cache de placement dépend directement du risque résiduel accepté ici :
  voir ADR 0005.
