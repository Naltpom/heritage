# ADR 0011 — Les actes demandés et leur suivi

Date : 2026-09-30 · Statut : acceptée · Révisée le 2026-10-01 (voir en fin de document)

## Contexte

Une copie d'acte se demande à une mairie ou à des archives, par courriel, sur
service-public.gouv.fr ou par courrier, et la réponse met de quelques heures à plusieurs
semaines. Le 30 septembre 2026, sept actes étaient en cours auprès de quatre mairies, tous
partis de la même boîte. **Rien dans l'arbre ne le disait** : une recherche a failli faire
redemander un acte de mariage envoyé le matin même, et la note d'une fiche annonçait
encore « deux actes prêts à être demandés » alors qu'ils avaient été demandés et reçus.

Le commanditaire a posé trois exigences :

1. **tout membre** peut enregistrer une démarche et la faire avancer ;
2. le **texte complet** de chaque message est gardé — celui qui reprend une démarche n'a
   pas la boîte d'où elle est partie, et c'est ce texte qui dit ce qui a été demandé, à
   qui, et ce qu'on peut compléter ;
3. on voit d'un coup d'œil **ce qui attend une réponse**.

## Décision

Un **module `Demarche`**, préfixe `dmr_`, selon l'ADR 0002, avec une surface publique
`Demarche\Contract\PortArbrePourDemarches` que `Tree` implémente
(`Tree\Service\VueDeLArbrePourDemarches`).

| Table | Ce qu'elle porte |
|---|---|
| `dmr_demarche` | L'acte visé (nature, forme, commune, date telle qu'on la connaît), le service à qui on le demande, le **statut** — envoyée, relancée, reçue, refusée, abandonnée — et le résultat. |
| `dmr_personne` | Les personnes de l'arbre concernées (`person_id` + libellé dénormalisé). Un acte de mariage en concerne deux. |
| `dmr_echange` | Chaque message, envoyé ou reçu : canal, date, expéditeur, destinataire, objet, référence, **texte entier**. |

### Ce n'est pas une donnée de l'arbre

Une démarche ne dit rien de vrai sur une personne : elle dit ce qu'on a fait pour le
savoir. Elle **ne passe donc pas par la file de l'ADR 0004** et n'écrit que dans ses
tables, comme les modules `Question` et `Tirage`. Quand la pièce arrive, ce qu'elle établit
entre dans l'arbre par une demande ordinaire ; la démarche passe à « reçue ».

### Qui voit

**Une démarche se montre à qui voit toutes les personnes qu'elle concerne**, par le cercle
du noyau (ADR 0003). Le texte d'une demande d'acte nomme ces personnes, donne leurs dates,
leurs lieux, parfois un ex-époux : c'est exactement ce que le cercle protège. Une personne
masquée suffit à masquer la démarche ; masquée et inexistante rendent la même chose.

### Qui écrit

Tout membre qui la voit peut ajouter un message, changer le statut, corriger la
description — la réponse d'une mairie arrive chez celui dont l'adresse figurait sur la
demande, qui n'est pas forcément celui qui l'a saisie. **Seuls les gestes qui effacent sont
réservés** : supprimer une démarche à son auteur, retirer un message à qui l'a saisi, et aux
administrateurs dans les deux cas. Toute écriture est refusée sous la bascule « voir comme
un membre ».

### Le statut est posé, pas déduit

Une réponse de mairie peut dire « nous transmettons au service compétent » sans que rien
ne soit reçu : seul celui qui l'a lue sait si la pièce est arrivée. La seule transition
automatique est celle qui ne peut pas mentir — une **seconde demande envoyée** fait passer
une démarche « envoyée » à « relancée ».

## Alternatives considérées

- **Une note sur la fiche.** Une note ne se modifie jamais : le statut, lui, change. Et
  une note ne se liste pas à l'échelle de la famille.
- **Un lien vers le fil de messagerie plutôt que le texte.** Refusé par le commanditaire :
  il ne vaut que pour le titulaire de la boîte.
- **Réserver le texte aux administrateurs**, parce qu'il porte l'adresse et le téléphone du
  demandeur. Refusé pour la même raison ; c'est le demandeur qui choisit ce qu'il écrit, et
  le cercle borne déjà qui lit.

## Conséquences

- Avant d'écrire à une mairie, on consulte la page « Actes demandés » : la recherche
  d'archives (compétence `famille`) le fait aussi, pour ne pas redemander une pièce en route.
- Une démarche en attente depuis trente jours est signalée « à relancer ».
- Aucune intégration à une messagerie : les messages se saisissent. C'est un choix de
  périmètre, pas une impossibilité ; il se rouvre si la saisie se révèle être le frein.

## Révision du 2026-10-01 — « expédiée par courrier », filtres par statut, « refaire autrement »

Trois manques relevés à l'usage de la page « Actes demandés ».

### Un sixième statut : `expediee`

Une mairie répond « votre demande est traitée, l'acte part par la poste ». La démarche
n'est pas reçue, et pas non plus sans réponse : la laisser « envoyée » la faisait signaler
**« à relancer »** au bout d'un mois, alors qu'il n'y a plus personne à relancer — on
attend le facteur. Le statut `expediee` (« Expédiée par courrier, en attente de
réception ») distingue les deux attentes :

- `enAttente()` — la pièce n'est pas là : `envoyee`, `relancee`, **`expediee`**. C'est ce
  que la page montre par défaut, et ce que `?enAttente=1` rend.
- `attendUneReponse()` — le service n'a pas répondu : `envoyee`, `relancee` seulement.
  C'est la seule attente qui se relance.

**`aRelancer` est désormais calculé par le serveur** (`attendUneReponse` et trente jours
depuis le dernier envoi), et non plus par l'écran : la règle dépend du statut autant que des
jours, et deux écrans qui la recalculeraient finiraient par diverger. La contrainte
`ck_dmr_demarche_statut` est reposée avec la nouvelle valeur
(`migrations/Version20261001170000.php`). Le statut reste **posé** : aucune transition
automatique ne mène à `expediee`, et une démarche expédiée ne repasse pas « relancée »
quand on écrit encore à la mairie.

### Des filtres par statut

« En attente / Toutes » ne suffisait plus — on voulait voir « les refusées, à refaire », ou
« ce qui est parti par la poste ». La page propose **tous les statuts, cochables
ensemble**, chacun avec son compteur, et deux raccourcis : « En attente » (envoyée,
relancée, expédiée — la sélection par défaut) et « Toutes ». La sélection **vit dans
l'adresse** (`?statuts=recue,refusee`, `?statuts=toutes`, absente pour le défaut) : un lien
partagé montre la même chose à qui l'ouvre. Le filtrage reste côté écran — la liste
entière est chargée une fois, sans le texte des messages —, et la recherche s'applique
par-dessus. Le compteur « N à relancer » demeure.

### Refaire autrement : le lien « suite de »

Une mairie refuse les demandes par courriel ; on lui écrit par la poste. C'est une
**nouvelle démarche** — autre canal, autre texte, autre attente —, pas un message de plus
sur la première : la première est bel et bien refusée, et l'historique doit le dire. Mais
sans lien, la première reste « refusée » sans suite visible, et quelqu'un la refait une
troisième fois.

- `dmr_demarche.precedente_id`, **auto-référence nullable en `ON DELETE SET NULL`** :
  supprimer la démarche reprise ne supprime pas celle qui la reprend, qui perd seulement le
  lien. Une clé étrangère interne au module (ADR 0002, règle 2). Une démarche ne peut pas se
  reprendre elle-même (`CHECK`).
- `POST /api/demarches` accepte `precedenteId`, **sous la règle de visibilité** : une
  démarche que le lecteur ne voit pas, ou qui n'existe pas, est refusée en `422` sur ce
  champ, avec le même message — comme une personne masquée dans `personIds`. Le lien se
  fixe à la création et ne se modifie pas.
- La démarche sérialisée porte `precedente` (`{id, statut, demandeeA}` ou `null`) et
  `suites` (`[{id, statut}]`), **filtrés par la même règle** : un lien vers une démarche
  masquée n'apparaît pas.
- À l'écran, une démarche refusée ou abandonnée propose **« Refaire autrement »** : le
  formulaire de création s'ouvre prérempli (acte, forme, intitulé, commune, date, service,
  personnes) ; la carte dit « Suite de la démarche n° X » et « Refaite : démarche n° Y »,
  liens qui font défiler jusqu'à la carte visée et l'ouvrent.

**Alternative écartée** : rouvrir la démarche refusée et y ajouter le nouveau courrier.
Elle mêlerait deux demandes dans un même fil, et ferait perdre le refus — qui est
justement l'information utile à qui écrira la prochaine fois à cette mairie.
