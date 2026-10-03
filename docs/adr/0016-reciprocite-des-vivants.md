# ADR 0016 — La réciprocité des vivants (date de naissance et portrait)

Date : 2026-10-03 · Statut : acceptée

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

### L'équité : une carence après un retrait

« Je partage, je regarde, je retire » rendrait la réciprocité bidon. Le commanditaire voulait
interdire le retrait pendant une durée minimale ; **ce n'est pas possible** : le RGPD
(art. 7.3) veut qu'un consentement se retire à tout moment, aussi facilement qu'il s'est
donné. La règle retenue :

- **ajouter** une composante est toujours libre ;
- **retirer** est toujours immédiat — la date disparaît aussitôt pour les autres, et le
  lecteur cesse de voir la leur ;
- une composante **retirée ne se remet qu'après 30 jours** (`Member::CARENCE_JOURS`), **par
  composante** : retirer le jour ne bloque ni l'année ni le mois. Une remise anticipée rend
  `409 partage_en_carence`.

La carence ne retient la donnée de personne : elle ne fait que différer l'accès aux dates des
autres, et l'astuce ne sert plus qu'une fois par mois. Chaque changement reste au journal.

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
