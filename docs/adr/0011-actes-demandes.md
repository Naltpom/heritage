# ADR 0011 — Les actes demandés et leur suivi

Date : 2026-09-30 · Statut : acceptée

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
