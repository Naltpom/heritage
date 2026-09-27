# ADR 0009 — Les tirages au sort récurrents

Date : 2026-09-27 · Statut : acceptée

## Contexte

Chaque année, de chaque côté de la famille, on tire au sort à qui chacun offre un cadeau :
le 24 décembre côté père, le 25 côté mère. Le tirage dit à chacun qui il doit gâter, et le
cadeau doit être au lieu dit le jour J — apporté, ou transmis quand on ne vient pas.

La question posée était : **une application à part, ou un module de Heritage ?**

Les participants sont la famille, c'est-à-dire des personnes **de l'arbre**. Heritage a
déjà ce qu'une application à part devrait refaire et garder cohérent : les comptes, le
rattachement d'un compte à une personne (`Membership`), les couples (`Tree`), les
notifications (`Core`), et un parcours d'invitation fermé. Le commanditaire a confirmé
qu'aucun participant n'est hors de l'arbre — c'est la seule condition qui aurait justifié
une application séparée, puisque Heritage n'a volontairement aucune inscription libre.

## Décision

Un **module `Tirage`**, préfixe `trg_`, selon l'ADR 0002 : ses entités, ses routes, ses
services, et une surface publique `Tirage\Contract\PortArbrePourTirages` que `Tree`
implémente (`Tree\Service\VueDeLArbrePourTirages`).

### Le modèle

| Table | Ce qu'elle porte |
|---|---|
| `trg_tirage` | Le tirage récurrent : nom, jour et mois, lieu habituel, organisateur, et les deux règles — mémoire (N) et exclusion des conjoints. Ouvert ou non à la famille. |
| `trg_participant` | Une **personne de l'arbre** (`person_id` + libellé dénormalisé), pas un compte. Un **référent** facultatif : un autre participant qui lit son tirage pour lui — l'enfant sans compte. |
| `trg_edition` | Une année : sa date, son statut (`ouverte` / `tiree`), la mémoire réellement appliquée. |
| `trg_presence` | Qui vient cette année : sur place, à distance (cadeau transmis), ou pas du tout. Seules les valeurs différentes du défaut « sur place » sont écrites. |
| `trg_attribution` | Qui offre à qui. Le secret de l'année **et** la mémoire des suivantes. |

### Le tirage

Trois règles, de la plus dure à la plus souple (`Tirage\Service\TirageAuSort`) :

1. personne ne se tire soi-même, et chacun reçoit exactement un cadeau ;
2. les conjoints ne se tirent pas, si le tirage le demande — lus dans `tree_family`, divorcés
   exclus ;
3. **pas la même personne pendant N éditions.** C'est la seule qui se relâche : sur un petit
   groupe elle devient vite intenable, et plutôt que de refuser de tirer, on oublie d'abord
   l'année la plus ancienne. L'édition garde la mémoire appliquée, et l'écran le dit à
   l'organisateur.

Au moins trois participants présents : à deux, chacun sait qui l'a tiré. La recherche est un
retour sur trace borné, sur un générateur fourni par l'appelant, pour que le test puisse
fixer la graine.

### Le secret

**Chacun ne lit que son attribution, et celle des participants dont il est le référent.**

- L'organisateur ne voit pas les paires, ni un administrateur : ils participent le plus
  souvent eux-mêmes. Aucune route ne liste les attributions d'une édition.
- La bascule « voir comme un membre » **n'est pas rejouée** pour la lecture d'un tirage : elle
  donnerait à tout administrateur le moyen de lire l'attribution de n'importe qui. Les
  écritures restent refusées sous la bascule, comme partout.
- La notification `tirage_effectue` ne nomme personne.
- La liste des tirages ne nomme jamais le receveur, et l'écran du tirage le masque jusqu'au
  geste « Découvrir » : il s'ouvre volontiers devant d'autres, un soir de famille.

Le filtre vit **à un seul endroit**, `Tirage\Service\SerialiseurDeTirage`. Le jour où l'on
voudra dévoiler le tirage après la fête, c'est là qu'il faudra l'écrire.

### Qui peut quoi

| | Voit | Règle, gère les participants, ouvre l'année, tire, annule |
|---|---|---|
| Organisateur, administrateur | le tirage | oui |
| Participant, référent | le tirage, son attribution | règle sa présence, se retire (pas après un tirage à venir qui l'inclut) |
| Tout membre rattaché, tirage **ouvert** | le tirage | le rejoint |
| Tout autre compte | rien : `404` | — |

Toutes les routes exigent un compte **actif** (`RegimeVoter::MEMBRE`).

## Ce que cette décision n'est pas

**Ce n'est pas une écriture dans l'arbre.** Un tirage n'est pas une donnée généalogique ;
il ne passe donc pas par la file de l'ADR 0004. Le module **lit** l'arbre — des noms et des
couples, c'est-à-dire la liste blanche que l'ADR 0003 sert déjà à tout compte connecté pour
une personne vivante hors cercle — et n'y écrit rien.

**Ce n'est pas un tirage automatique à date fixe.** L'organisateur ouvre l'année et lance le
tirage. Le dépôt n'embarque ni Messenger ni Scheduler, et les ajouter pour un geste annuel
serait disproportionné.

## Conséquences

- Neuf modules au lieu de huit ; 32 tables ; 50 colonnes d'énumération. Les tests de garde
  (`SqueletteTest`, `SchemaSynchroniseTest`, `LongueurDesEnumerationsTest`) le disent.
- `Core\Enum\NotificationKind` gagne `tirage_effectue`, et le front la route
  `tirage.detail` que ces notifications citent — elle ne se renomme pas.
- Pistes laissées ouvertes : une liste d'idées de cadeaux par participant, un rappel avant
  le jour J, le dévoilement après la fête, et l'interdiction des tirages croisés (A → B et
  B → A la même année).
