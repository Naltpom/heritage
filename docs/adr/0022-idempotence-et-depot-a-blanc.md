# ADR 0022 — L'idempotence du dépôt et le dépôt à blanc

Date : 2026-10-28 · Statut : acceptée

## Contexte

Depuis l'[ADR 0021](0021-jetons-d-api.md), des agents déposent des demandes par l'API, sans
navigateur. Deux trous restaient pour eux dans `POST /api/demandes` :

- **une réponse perdue** (délai dépassé, coupure, processus relancé) laisse l'agent sans
  savoir si la demande est entrée dans la file. La redéposer crée un doublon que
  l'administrateur devra refuser ; ne pas la redéposer perd la contribution. Le serveur MCP
  de l'étape suivante rejouera ses plans d'un bout à l'autre : il lui faut une réponse sûre à
  « l'ai-je déjà déposée ? » ;
- **vérifier avant de déposer** n'était possible qu'en déposant.
  `DepotDeDemande::verifierSansEcrire()` existait (l'assistant de proposition s'en sert pour
  ne jamais présenter au membre une demande cassée), mais aucune route ne l'exposait, et elle
  s'arrêtait à la première erreur. Un agent qui corrige ses erreurs une par une paie un tour
  de modèle par champ.

## Décision

### 1. `Idempotency-Key`, facultatif, sur `POST /api/demandes` seulement

Le client choisit la clé : 16 à 128 caractères `[A-Za-z0-9_-]`. Le serveur la garde avec la
demande (`prp_change_request.idempotency_key`), ainsi que l'empreinte sha256 du corps
(`idempotency_fingerprint`). Sans l'en-tête, rien ne change, et le front ne l'envoie pas.
Une clé mal formée rend `422`, avant toute recherche de rejeu. Une valeur entre guillemets (la
*sf-string* du brouillon IETF `draft-ietf-httpapi-idempotency-key-header`) est refusée
plutôt que lue de deux façons : le seul client prévu n'en envoie pas.

### 2. La clé appartient au compte auteur

L'index unique partiel `uniq_prp_request_idempotence (author_user_id, idempotency_key)
WHERE (idempotency_key IS NOT NULL)` porte la règle. Le cookie et chaque jeton d'un même
compte partagent les mêmes clés : un plan rejoué par un autre jeton retrouve sa demande au
lieu d'en créer une seconde. Le jeton n'entre pas dans la clé ; il est déjà nommé par le
journal (ADR 0021 § 7). La clé d'un autre compte n'est jamais trouvée : le dépôt est alors
neuf, et rien ne révèle qu'elle existe ailleurs.

La clé ne s'efface pas : elle vit avec la demande, qui n'est jamais supprimée (ADR 0004).
Seul un dépôt réussi consomme une clé. Refaire une demande refusée demande une nouvelle clé.

### 3. Même clé : même corps, ou `422`

Le corps est comparé par son empreinte : sha256 du JSON reçu, clés d'objet triées, ordre
des listes gardé. Un corps différent sous une clé déjà employée rend
`422 idempotence_corps_different`, qui nomme la demande existante (`details.demandeId`).
Rejouer à l'aveugle aurait fait disparaître en silence une seconde demande différente.

### 4. Le rejeu rend l'état courant, en `200`

Même clé et même corps : `200`, la demande sérialisée comme au `201`, **à son état
courant**, avec l'en-tête `Idempotency-Replayed: true`. Rien n'est écrit, rien n'est
notifié, aucune ligne de journal. On ne stocke pas la réponse d'origine : elle deviendrait
fausse dès la première décision. Ce que l'appelant veut savoir, c'est où en est sa demande.

Le rejeu repasse par tous les garde-fous d'une écriture : portée du jeton, bascule
« voir comme » (`409`), régime du compte (`403`).

### 5. Deux dépôts de même clé au même instant

Les deux manquent la recherche et construisent leur demande. Au `flush()`, PostgreSQL fait
attendre le second jusqu'à la validation du premier, puis refuse son insertion. La
transaction du perdant est annulée : rien n'est écrit, et la notification, qui suit le
flush, ne part pas. Le perdant reçoit `409 depot_concurrent`, `rejouable: true`, avec
l'identifiant du gagnant, lu par la connexion seule parce que l'EntityManager est fermé.
Renvoyée, sa requête reçoit le `200`. On ne rend pas le `200` dans la requête perdante : il
faudrait un nouvel EntityManager en cours de requête, et des services qui gardent des
entités de l'ancien.

### 6. Le dépôt à blanc : `POST /api/demandes/verifier`

Les contrôles du dépôt, **les mêmes** (constructeurs, périmètre, complétude), sans rien
écrire. `204` si le dépôt passerait. Sinon, **le statut que rendrait le dépôt** : les
contrôles se jouent dans son ordre, et la première erreur rencontrée décide. `soumettre` est
lu comme au dépôt. La bascule « voir comme » rend `409`, comme pour toute écriture : la route
n'écrit pas, mais elle prédit une écriture. `Idempotency-Key` y est ignoré. Pour les jetons,
elle est ouverte à la portée `contribution`.

Rien n'est journalisé. Ce que la vérification fait entrer dans l'unité de travail est
détaché, pour qu'aucun `flush()` ultérieur de la même requête n'écrive une demande fantôme.

### 7. « Toutes les erreurs » : une passe, une erreur par élément

Sur une `422`, la réponse porte toutes les erreurs qu'une passe établit : une par champ de
l'enveloppe (`kind`, `complementDe`, `message`, `soumettre`), et une par élément des listes
(`ops[n]`, `brouillons[n]`, `liens[n]`, `retraits[n]`). Dans un élément, la première seule :
les contrôles y dépendent les uns des autres (la nature de la valeur découle du champ). Ce
qui dépend de tous les éléments (la cible tirée des ops, les liens qui désignent les fiches,
la complétude) n'est jugé que si tous sont justes.

Le mécanisme est un recueil (`Proposal\Contract\RecueilDErreurs`) que `verifierSansEcrire()`
passe aux constructeurs. Le dépôt n'en passe pas : il s'arrête toujours à la première erreur.
Une erreur seule sort intacte, avec son code et son message. Plusieurs prennent leur code
commun, ou `donnees_invalides`, et leurs `champs` réunis dans l'ordre. L'assistant lit
toujours la première : rien ne change pour lui.

## Conséquences

- Une migration (`Version20261028151742`) : deux colonnes, l'index unique partiel, et la
  contrainte `ck_prp_request_idempotence` (une clé ne va jamais sans son empreinte).
- Le contrat gagne une route, deux en-têtes et deux codes (§ 1.8, § 6.1 bis, § 6.1 ter,
  § 15.2). Le § 1.1 (« aucune réponse sans corps ») porte l'exception du dépôt à blanc.
- `ConstructeurDeDemande::construire()` gagne un paramètre facultatif, le recueil. Il vit
  dans `Contract\`, parce que le constructeur d'ajout de média (module `Media`) le reçoit.
- L'ADR 0021 § 2 est amendée : la portée `contribution` ouvre le dépôt à blanc.
- Ni le front ni l'assistant ne changent.

## Refusé

- **Un rejeu aveugle, sans empreinte** : une seconde demande différente disparaîtrait en
  silence.
- **Une clé par jeton** : un plan rejoué par l'autre jeton du même compte redéposerait.
- **Des clés qui expirent** (24 heures, comme chez la plupart des API de paiement) : rejouer
  un plan des semaines plus tard doit encore être sans effet, et la demande existe de toute
  façon pour toujours.
- **Rendre le `200` du gagnant dans la requête perdante** : il faudrait réinitialiser
  l'EntityManager en cours de requête.
- **Toutes les erreurs de chaque élément** : il faudrait réécrire les contrôles des
  constructeurs, qui dépendent les uns des autres. Le dépôt et le dépôt à blanc finiraient
  par diverger.
- **Ouvrir `retirer` aux jetons** pour qu'un agent nettoie ses essais : aucun outil des
  agents ne retire (ADR 0021 § 2).
