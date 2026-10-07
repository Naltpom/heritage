# ADR 0016 — La réciprocité des vivants (date de naissance et portrait)

Date : 2026-10-03 · Statut : acceptée · Révisée le 2026-10-07 (**trois niveaux de partage :
tous, proches, personne — proches par défaut**)

> **À lire d'abord — révision du 2026-10-07, « les proches se voient ».** Chaque composante
> de la date de naissance ne se partage plus par oui ou non, mais **avec qui** : `tous` les
> vivants de l'arbre, ses `proches`, ou `personne`. Le défaut est `proches`. La règle
> « on ne voit que ce que les deux partagent » devient « on ne voit que ce que **chacun montre
> à l'autre** ». Partout où ce texte écrit « V partage c », lire : *V montre c à T*. Le détail,
> le motif et ce qui ne change pas : section « Révision du 2026-10-07 », en fin de document.

## Contexte

Depuis la révision du 2026-10-01 de l'ADR 0003, un membre validé voit **tous** les vivants
non confidentiels, date de naissance complète comprise. Le commanditaire veut se mettre en
règle avec le RGPD (recommandations de la CNIL sur les données de personnes vivantes) :

- la date de naissance d'une personne vivante est une donnée personnelle, et la partager
  avec la famille demande son **consentement** — explicite, granulaire, révocable à tout
  moment, et **prouvable** ;
- par défaut, rien n'est partagé, y compris pour les comptes créés avant la fonctionnalité ;
- un vivant **sans compte** ne peut consentir dans l'application : son accord écrit (un
  courrier signé, un SMS) doit pouvoir être déposé par un administrateur.

Il ajoute une règle de réciprocité : **on ne voit des autres que ce qu'on accepte de
montrer**, et l'étend aux photos — sans photo de soi, les photos des vivants sont floues.

## Décision

### La règle

Pour un lecteur V et une personne **vivante** T que le cercle montre déjà :

- **date de naissance** — V voit la composante c (année, mois, jour, **indépendantes**)
  si et seulement si V partage c **et** T partage c. Ce que T partage : le consentement de
  son compte s'il en a un (quel que soit son statut), sinon l'autorisation signée active,
  sinon rien ;
- **portrait** — net si V a une photo de lui (portrait de sa fiche actif ou en attente, ou
  portrait de compte en attente de rattachement), flou sinon.

Toujours en entier : une personne **décédée**, et **sa propre fiche**.

L'option d'administration « ignorer la restriction de date de naissance », posée compte par
compte, donne toutes les composantes. **Elle ne couvre pas les photos** (décision du
commanditaire). Le lieu de naissance, le nom et les autres événements ne sont pas concernés.

### L'équité : ce qui est partagé reste visible 30 jours

« Je partage, je regarde, je retire » rendrait la réciprocité bidon pour ceux qui partagent
vraiment. La règle, **décidée par le commanditaire** :

- **partager** une composante est toujours immédiat ;
- une composante **partagée reste visible au moins 30 jours** (`Member::DUREE_MINIMALE_JOURS`),
  composante par composante (`mbr_member.birth_share_*_since`) : le titulaire ne la retire pas
  avant — `409 partage_verrouille` ;
- **la soupape est le formulaire RGPD** de l'application (`mbr_demande_rgpd`) : le membre
  demande le retrait (ou une rectification, ou autre chose), un administrateur l'applique **sans
  délai** (source `administration`, qui ignore le verrou) ou la refuse avec une réponse ; les
  deux sont notifiés, tout est journalisé (`demande_rgpd`).

**Écart assumé au RGPD.** L'article 7.3 veut qu'un consentement se retire « à tout moment »,
aussi simplement qu'il s'est donné. Le verrou de 30 jours s'en écarte : la décision a été prise
**en connaissance de cause**, après avertissement, et le retrait reste possible à tout moment
par la demande RGPD. Si l'écart devait être levé, il suffit de faire appliquer les demandes de
retrait automatiquement, ou de supprimer le contrôle dans `ConsentementDeNaissance::enregistrer()`.

### Deux filtres empilés, le cercle d'abord

La réciprocité ne remplace pas le cercle : `CercleDuLecteur::voit()` décide toujours de la
forme de la fiche (complète ou réduite), puis la réciprocité **réduit** la naissance et le
portrait d'un vivant déjà visible. Même mécanique que le cercle :

- un contrat `Core\Security\ReciprociteInterface`, au lecteur implicite (bascule « voir
  comme » comprise : c'est le membre emprunté qui lit — ses choix, son option, sa photo) ;
- une implémentation unique, `Membership\Service\Reciprocite` ;
- un défaut fermé, `Core\Security\ReciprociteFermee` : aucune composante, aucun portrait net.

### Le filtrage est fait au serveur, et ne laisse rien passer

Une composante retenue **n'est pas envoyée**, sous aucune forme :

- la naissance passe par `UncertainDate::reduite()` — une date valide, **sans `raw`** (qui
  porte toutes les composantes d'un coup), bornes élargies à l'année ou au mois — et par
  `formatPartiel()` pour sa prose (« 1985 », « mars 1985 », « 12 mars ») ;
- la fiche sert un bloc `qualifier: "masquee"` (valeur propre à l'API) même quand la date
  est inconnue, pour qu'« inconnue » et « non partagée » ne se distinguent pas ;
- les âges, les doyens, les mois des naissances (statistiques), la chronologie, la carte,
  le calendrier, la recherche par période de l'assistant ne voient que la date réduite ;
- le calendrier n'affiche un anniversaire que si le jour **et** le mois sont partagés, sans
  clé `annee` quand l'année ne l'est pas ; il ne compte pas les autres (le compte dirait
  qu'ils existent) ;
- une modification de la naissance d'un vivant qu'on ne voit pas entière est refusée
  (`422 naissance_masquee`) **avant** le contrôle de l'ancienne valeur, dont le `409`
  rendrait sinon la date complète ; l'historique la retire de même.

La photo floue est **fabriquée sur le serveur** (`Media\Service\GenerateurDeFlou`) : réduite
à une douzaine de pixels — l'information se perd là, pour de bon — puis ré-agrandie et
lissée, toujours en JPEG. Les deux routes (`/fichier`, `/vignette`) servent le même fichier
flou, sous un `ETag` distinct (`f…`) ; l'URL porte un suffixe `-f`, simple clé de cache que
le serveur ignore. La charge JSON d'une photo floue ne dit rien de plus que ses pixels :
ni dimensions, ni empreinte, ni nom d'origine. Exceptions qui ne touchent pas à la règle :
le **déposant** voit sa propre photo, et l'**administrateur** voit nette une photo **en
attente** de validation (on ne modère pas une image qu'on ne voit pas).

### Le stockage

- `mbr_member.birth_share_year|month|day` (défaut `false`) + `birth_share_updated_at`,
  `birth_share_source` (`inscription` ou `profil`) : le choix du titulaire du compte ;
- `core_user.ignore_birth_restriction` : l'option d'administration ;
- `mbr_autorisation_naissance` : l'autorisation signée d'un vivant sans compte, avec son
  document (hors de `med_media` : ni cercle, ni vignette, ni circuit ; servi aux seuls
  administrateurs, `Cache-Control: no-store`). **Archivée, jamais supprimée** : remplacée,
  retirée, ou rendue caduque au rattachement d'un compte (`compte_rattache`) — dès qu'un
  compte désigne la fiche, **seul son choix compte**, ce que la lecture garantit et que
  l'archivage trace.

### La preuve

Chaque geste laisse une ligne au journal d'audit, en plus du diff mécanique :
`partage_naissance` (avant, après, source ; le compte est l'acteur à l'inscription, et
l'incarnant est posé sous une incarnation), `autorisation_naissance` (déposée ou retirée,
composantes, empreinte du document), `restriction_naissance` (l'option d'administration).

## Ce que ce n'est pas

- **Pas un second cercle.** La réciprocité ne cache aucune fiche ni aucun nom.
- **Pas une règle sur les défunts**, ni sur le lieu de naissance, ni sur le baptême.
- **Pas une option d'administration sur les photos.**

## Conséquences

- L'**ordre relatif** des naissances d'une fratrie reste lisible dans la topologie du
  graphe (les fratries y sont triées par année). C'est la topologie que l'ADR 0003 accepte
  déjà comme risque résiduel. La **largeur** d'une fiche vivante, elle, ne dit rien : elle
  est constante (`MesureDeFiche::LARGEUR_AFFICHEE_VIVANT`), et le cache de placement reste
  partagé.
- Sans l'année, un vivant n'a pas de barre dans la chronologie : sa position *est* l'année.
- Le corpus envoyé au modèle de langage (ADR 0008) ne porte **plus aucune naissance de
  vivant**, quels que soient les consentements : il est commun à tous les lecteurs, et la
  réciprocité ne se découpe pas dans un texte partagé. Les questions sur la naissance d'un
  vivant ne sont plus posées.
- L'`ETag` du graphe intègre l'empreinte de la réciprocité (choix du lecteur, de chaque
  personne, option, photo) dans sa composante « cercle effectif ».
- Un administrateur d'amorçage, sans rattachement, ne partage rien : il voit les dates des
  vivants en posant l'option sur son propre compte, et leurs photos floues tant que son
  compte n'a pas de photo. Le rattachement assisté (ADR 0013), qui compare des naissances,
  suppose l'option posée.

## Révision du 2026-10-07 — trois niveaux, les proches par défaut

### Le motif

Sous la règle d'origine, un parent qui n'avait rien coché ne voyait pas la date de naissance de
son propre enfant, ni l'enfant celle de ses parents. C'était le cas de presque tous les comptes :
le partage était `false` par défaut et peu de membres l'avaient changé. Le commanditaire veut que
**l'arbre de chacun montre ses proches**, et que pour les autres vivants la réciprocité joue comme
avant.

### La règle

Chaque personne vivante partage l'année, le mois et le jour, **chacun à son niveau** :

- `tous` — tous les vivants de l'arbre ;
- `proches` — ses proches seulement — **le défaut** ;
- `personne`.

Les **proches** de u (`Membership\Service\CalculDesProches`) :

```
P(u) = Asc(u) ∪ Desc(u) ∪ Fratrie(u) ∪ Conj(u) ∪ Asc(Conj(u)) ∪ Conj(Desc(u)) \ { u }
```

ascendants et descendants à toute profondeur, frères et sœurs (demi-frères et demi-sœurs
compris), conjoints **actuels** — une union rompue (`tree_family.divorced`) n'en fait plus, ni de
ses parents —, ascendants des conjoints, conjoints des descendants. La relation est
**symétrique** (`v ∈ P(u) ⇔ u ∈ P(v)`), et c'est voulu : « si je vois ta date, tu vois la
mienne ». Ajouter un terme exige d'ajouter son miroir ; un test le vérifie sur tous les couples.

Pour un lecteur V et un vivant T, V voit la composante c si **chacun la montre à l'autre** :

| T est… | V voit c si… |
|---|---|
| un proche de V | V **et** T sont au moins à `proches` |
| un autre vivant | V **et** T sont à `tous` |
| V lui-même, ou décédé | toujours |

Ce que T partage : le choix de son compte ; sinon l'autorisation signée active ; sinon **le
défaut, `proches`** — un vivant sans compte est donc vu de ses proches. Un lecteur sans fiche
(compte d'amorçage) ne montre rien et ne voit rien, sauf l'option d'administration.

### Ce qui change ailleurs

- **Le stockage** : `mbr_member.birth_share_*` et `mbr_autorisation_naissance.share_*` passent
  de `BOOLEAN` à `VARCHAR(8)` (`NiveauDePartage`), avec `CHECK`. La migration
  (`Version20261007090000`) passe **ce qui était partagé à `tous`, le reste à `proches`** :
  personne ne perd ce qu'il voyait.
- **Le verrou de 30 jours ne porte que sur `tous`** : passer à `tous` le pose, en redescendre
  avant rend `409 partage_verrouille`. Entre `proches` et `personne`, on va et vient librement —
  les proches connaissent déjà la date, et un membre qui découvre le nouveau défaut doit pouvoir
  le refuser tout de suite. Les dates `birth_share_*_since` existantes gardent leur sens.
- **La demande RGPD de retrait** passe les composantes visées à `personne`, sans verrou : c'est
  le retrait du consentement, entier.
- **L'empreinte** de la réciprocité intègre les proches du lecteur : un mariage ou une naissance
  dans l'arbre change ce qu'il voit.
- **Une naissance qu'on ne voit pas entière se propose à l'aveugle** : le `422
  naissance_masquee` disparaît. Il empêchait d'ajouter la date d'une personne vivante qu'on
  venait soi-même de créer, dès sa fiche validée. Le champ part vide ; l'`oldValue` du client
  est ignoré et le serveur pose la valeur courante, sans contrôle d'ancienne valeur (son `409`
  rendrait la date) ni refus d'une valeur identique (il dirait qu'on a deviné juste). La vue
  auteur ne rend jamais cette valeur d'avant ; l'administrateur, qui voit tout, tranche.

### L'écart au principe d'origine, et comment il se dit

« Par défaut, rien n'est partagé » ne tient plus : `proches` est un partage que la personne n'a
pas choisi elle-même. Le commanditaire l'a décidé **en connaissance de cause** : entre très
proches, la date de naissance n'est pas une information nouvelle, et ce partage reste dans le
cercle familial le plus étroit. En contrepartie :

- **chaque compte rattaché reçoit une notification** (`partage_naissance_revu`), posée par la
  migration, qui dit le changement et renvoie à « Mon compte » ;
- **le journal d'audit** porte, pour chaque compte, une ligne `partage_naissance` de source
  `migration`, avec l'avant et l'après ;
- **`personne` est toujours à un clic**, sans verrou ;
- la **fiche confidentielle** (`force_privacy`) passe toujours avant : le cercle s'applique
  d'abord.

### Ce qui ne change pas

Le cercle d'abord, la réciprocité ensuite ; le filtrage au serveur ; l'option d'administration ;
la règle des photos ; le corpus du modèle de langage, qui ne porte toujours aucune naissance de
vivant.
