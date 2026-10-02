# ADR 0014 — Les événements de famille

Date : 2026-10-02 · Statut : acceptée

## Contexte

La demande du commanditaire, mot pour mot : *« une fonctionnalité "événement" un peu comme
tirage au sort mais genre "cousinade été 2027" — date de début, de fin, descriptif — on
sélectionne les vivants, puis on crée l'événement et ça envoie une notif aux gens pour dire
présent ou absent, avec une mention "annonce vue" aussi pour savoir si les gens la voient,
et donc juste les fantômes. »*

Trois besoins, donc : **annoncer** (titre, dates, lieu, description), **inviter** des
personnes de l'arbre et les **prévenir**, puis **suivre** — qui vient, qui ne vient pas, et
surtout qui a vu l'annonce sans répondre : les « fantômes ».

Comme pour les tirages (ADR 0009), les invités sont la famille, c'est-à-dire des personnes
**de l'arbre**, dont certaines n'ont pas de compte. Tout ce qu'il faut existe déjà : les
comptes, le rattachement, les notifications et leur relais sur le téléphone, le lien
personnel des tirages.

## Décision

Un **module `Evenement`**, préfixe `evt_`, selon l'ADR 0002, bâti sur le modèle du module
`Tirage` : ses entités, ses routes, ses services, et une surface publique
`Evenement\Contract\PortArbrePourEvenements` (des noms, et l'état « vivante ») que `Tree`
implémente (`Tree\Service\VueDeLArbrePourEvenements`).

### Le modèle

| Table | Ce qu'elle porte |
|---|---|
| `evt_evenement` | L'annonce — titre, date de début, date de fin (égales pour un jour), lieu, description — l'organisateur, l'annulation, la dernière relance. |
| `evt_invitation` | Une **personne de l'arbre** invitée (`person_id` + libellé dénormalisé) ; sa **première vue**, sa **réponse** (`present` / `absent`, nulle tant qu'elle n'a pas répondu) et sa date, sa dernière relance, l'empreinte de son lien personnel. |

« Vu, sans réponse » n'est pas une colonne : c'est `vu_le` posé et `reponse` nulle. Le compte
de l'invité n'est **pas** figé dans la ligne : il se lit au rattachement de `Membership`, au
moment voulu — une personne invitée aujourd'hui peut ouvrir son compte demain.

### Les règles

- **Qui crée : tout membre**, comme pour un tirage (`ReserveAuxMembres`). Une cousinade
  s'organise entre cousins.
- **Qui est invité : des vivants du cercle de l'organisateur.** Le même filtre que les
  tirages (`dansLeCercle()` puis les vivants) : une invitation enregistre et rend un nom.
- **Deux réponses, pas de « peut-être »** — c'est la demande. On répond, et l'on change
  d'avis, jusqu'au dernier jour de l'événement.
- **La vue se pose sans geste** : à l'ouverture de la page de l'événement
  (`GET /api/evenements/{id}`, une lecture qui écrit, dite comme telle), de son lien
  personnel, ou de la carte de l'accueil. Une seule fois — la première. Jamais sous la
  bascule « voir comme un membre » ; jamais quand c'est l'organisateur qui ouvre le lien
  qu'il s'apprête à envoyer.
- **On annule, on ne supprime pas** : des invités ont été prévenus, la notification doit
  encore mener quelque part.

### Ce que chacun voit

- **L'organisateur** (et un administrateur) voit le **suivi** : les invités en quatre
  groupes — présents, absents, vus sans réponse, pas encore vus —, chacun avec ses
  horodatages, et s'il a un compte ou un lien.
- **Un invité** voit l'annonce, sa réponse, et **qui vient** — les noms des présents qu'il
  voit dans l'arbre (ADR 0003), les autres comptés sans nom. Il ne voit **ni qui a décliné**
  (un nombre seulement), **ni qui a vu sans répondre, ni aucun horodatage de vue.**

C'est la règle du secret des tirages, transposée : chacun voit ce qui le regarde, et
l'organisateur ce qu'il lui faut pour organiser. « Vu à 23 h 14, pas de réponse » est un
suivi acceptable de la part de qui invite et compte ses couverts ; de la part de toute la
famille, c'est une surveillance. Et afficher les refus à tous ferait de chaque « non » une
déclaration publique — qui pousserait à ne pas répondre plutôt qu'à décliner, c'est-à-dire
à fabriquer des fantômes.

### Prévenir

Trois notifications (`Core\Enum\NotificationKind`), aux seuls invités qui ont un compte, et
jamais à l'auteur du geste : `evenement_invitation` (à la création et à l'ajout après coup),
`evenement_relance`, `evenement_annule` (à ceux qui avaient vu ou répondu). Elles nomment
l'organisateur et l'événement, jamais un autre invité, et mènent à `evenement.detail`.

**La relance** notifie les invités avec un compte qui n'ont pas répondu — fantômes et
non-vus confondus —, **une fois par 24 heures au plus** pour tout l'événement. Les invités
sans compte ne peuvent rien recevoir de l'application : l'écran rappelle à l'organisateur
de leur renvoyer leur lien.

### Le lien personnel

Un invité **sans compte** répond par un lien `/e/<clé>` que l'organisateur crée et lui
envoie (copier, ou la feuille de partage du téléphone). C'est le lien des tirages : 256 bits,
**seule l'empreinte est stockée**, la clé ne s'affiche qu'à sa création, un seul refus
(`404 lien_invalide`) pour toutes les clés qui n'ouvrent rien, un limiteur par IP. Il ne
montre que l'annonce et la réponse de cette personne — ni les autres invités, ni qui vient,
ni l'organisateur.

Le lien est **refusé pour un invité qui a son compte**, et cesse de valoir le jour où il en
ouvre un. Il n'y a pas de secret à protéger ici, contrairement aux tirages : la raison est
que tenir la clé d'un invité, c'est pouvoir répondre et « voir » à sa place, donc fausser
exactement ce que le suivi mesure.

Le générateur de clé, recopié dans `Tirage` en attendant « un troisième module », est monté
dans `Core` (`Core\Service\GenerateurDeCle`) : ce module est le troisième. Le limiteur reste
propre à chaque module (identifiants et compteurs séparés).

## Conséquences

- Deux tables de plus (43), une colonne d'énumération de plus (`evt_invitation.reponse`,
  56), trois natures de notification de plus.
- `GET /api/evenements/{id}` écrit. C'est assumé et borné : idempotent, une écriture au
  plus par invité et par événement, aucune sous la bascule.
- Le front choisit les invités dans **le graphe** (les nœuds `vivant` et
  `visibilite: complete`), parce que « tous les vivants » demande la liste entière, ce que
  la recherche ne rend pas à dessein. Il ne propose donc que ce que l'arbre montre déjà en
  clair au lecteur — et l'API refuse le reste.
- Ce qui n'est **pas** fait, et pourrait l'être : prévenir les invités quand l'annonce
  change de dates ; laisser l'organisateur noter la réponse de quelqu'un qui l'a donnée de
  vive voix ; supprimer un événement créé par erreur (on l'annule).

## Addendum du 2026-10-02 — les tirages sont des événements ; noter une réponse ; changer les dates

Trois demandes du commanditaire, le jour même.

### 1. « Le tirage au sort, c'est un événement en soi »

Mot pour mot : *« on a créé événement et tirage au sort… une fusion serait plus judicieuse :
le tirage au sort c'est un événement en soi. Lors de la création d'un événement on choisit
Rassemblement ou Tirage au sort. Tout fusionner serait plus simple pour les utilisateurs. »*

**Décision : la fusion se fait là où l'utilisateur la voit, et nulle part ailleurs.**

- **Côté front**, une seule entrée « Événements » (Moi, l'accueil), une seule liste
  `/evenements` qui mêle rassemblements et tirages — chaque ligne porte sa pastille
  « Rassemblement » ou « Tirage au sort », filtrable par `?type=` —, un seul bouton « Créer
  un événement » qui demande d'abord le type (deux grandes cartes), puis montre le formulaire
  du rassemblement ou celui du tirage. Les pages de détail restent celles de chaque module.
- **Les anciennes adresses marchent** : `/tirages` redirige vers `/evenements?type=tirage`,
  `/tirages/nouveau` vers la création, type tirage ; `/tirages/<id>` et le nom
  `tirage.detail` ne bougent pas — les notifications `tirage_effectue` déjà écrites y mènent.
- **Côté API, rien n'est fusionné** : ni les modules, ni les tables, ni les routes. Des
  données vivent en production dans les deux, et les deux modules n'ont pas les mêmes règles
  de lecture (le **secret** des attributions d'un tirage, le **suivi** d'un rassemblement) :
  une table commune ferait porter à chaque requête une distinction que les modules tiennent
  aujourd'hui par construction.
- **La liste commune est faite par le front**, à partir de `GET /api/evenements` et
  `GET /api/tirages` appelés en parallèle (`components/evenements-familiaux/liste-commune.ts`).
  Un point d'entrée qui agrège côté API a été écarté : il ne ferait que recopier les deux
  sérialisations — chaque module resterait maître de la sienne, donc il faudrait les tenir
  synchrones —, et deux appels parallèles coûtent le temps d'un. Une panne de l'un laisse
  l'autre s'afficher, et la page le dit.
- **Un tirage n'est jamais « passé »** : il revient chaque année. Il se range à sa prochaine
  date — l'édition ouverte quand elle est à venir, sinon le jour habituel.

**L'accueil** montre désormais **tous** mes événements à venir, rassemblements et tirages
mêlés, le plus proche d'abord, dans une seule section « Mes événements » : les
rassemblements où je suis invité **ou que j'organise** (la carte dit alors « Vous
organisez » et n'a pas de boutons), les tirages dont je suis ou que j'organise. Chaque carte
de rassemblement où je suis invité pose la vue, comme la carte unique d'avant.

### 2. Noter la réponse de quelqu'un à sa place

« Grand-mère a répondu par téléphone. » L'organisateur — et un administrateur — note la
réponse d'un invité depuis le suivi : `PUT /api/evenements/{id}/invites/{personId}/reponse`.

- Elle est **tracée** : `evt_invitation.reponse_notee_par_user_id` et
  `reponse_notee_par_libelle` (dénormalisé, pour l'affichage discret « noté par Nathan »
  dans le suivi, « Réponse notée par Nathan » chez l'invité).
- Elle **ne compte pas comme une vue** : `vu_le` n'est pas posé. L'invité n'a rien ouvert,
  et le suivi ne doit pas le prétendre.
- Dès que l'invité répond lui-même, la trace s'efface : la réponse redevient la sienne.
- L'invité n'est pas notifié : il a donné cette réponse lui-même, de vive voix.
- Mêmes bornes que la réponse de l'invité : pas sur un événement annulé ou terminé
  (`409 reponse_figee`), rien sous la bascule.

### 3. Changer les dates prévient les invités

Décision du commanditaire : quand l'organisateur change le **début ou la fin**, une
notification `evenement_dates_modifiees` part à **tous les invités qui ont un compte** —
vus ou non, répondus ou non —, jamais à l'auteur du geste : « Cousinade été 2027 : nouvelles
dates », « … a changé les dates : désormais du … au … ».

Les réponses déjà données **restent** : « je viens » vaut souvent encore, et les effacer
ferait de chaque correction de date une avalanche de fantômes. Elles deviennent **à
confirmer** : `evt_evenement.dates_modifiees_le` garde le dernier déplacement, et une réponse
dont `repondu_le` le précède est à confirmer (`reponseAConfirmer` pour l'invité,
`aConfirmer` dans le suivi et sur le lien personnel). L'écran de l'invité affiche « Les
dates ont changé : confirmez votre réponse », et toucher la réponse déjà marquée la
confirme. Un horodatage sur l'événement plutôt qu'un drapeau par invité : une seule écriture
au déplacement, et la règle se lit en comparant deux dates.

Le titre, le lieu ou la description changés seuls ne préviennent personne.

### Conséquences de l'addendum

- Migration `Version20261002233000` : trois colonnes nullables, deux `CHECK`
  (`ck_evt_invitation_notee_par`, `ck_evt_invitation_notee_avec_reponse`). Les lignes
  existantes ne bougent pas.
- Une nature de notification de plus (`evenement_dates_modifiees`, étiquette « Nouvelles
  dates » dans la cloche).
- Le résumé gagne `maReponseNoteePar` et `reponseAConfirmer` ; la ligne du suivi `noteePar`,
  `aConfirmer`, `peutNoterReponse` ; le lien personnel `invite.aConfirmer` — listes blanches
  mises à jour, et éprouvées par les tests.
- Des trois pistes laissées ouvertes plus haut, deux sont faites ; reste la suppression d'un
  événement créé par erreur.
