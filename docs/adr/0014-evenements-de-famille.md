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
