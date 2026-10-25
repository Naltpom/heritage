# Sécurité, comptes et journal d'audit — module `Core`

> Ce document décrit ce qui est **posé** dans `api/src/Module/Core/`, les invariants que
> les autres modules doivent respecter, et ce qui reste à faire. Il ne remplace ni
> `docs/api-contract.md`, qui fait foi sur les routes et les charges utiles, ni
> `docs/adr/0003-cercle-de-visibilite.md`, qui fait foi sur la règle de confidentialité.
> En cas de divergence entre ce fichier et l'un des deux, ce sont eux qui gagnent.

---

## 1. Ce que `Core` garantit aux six autres modules

Quatre garanties, et ce sont elles qui expliquent pourquoi ce module existe avant les
autres :

1. **Aucune route n'est ouverte par défaut.** `access_control` couvre `^/api` en entier ;
   une route nouvelle est protégée avant d'être écrite.
2. **L'API ne rend jamais de HTML**, pas même pour dire « non », pas même sur une `404`
   de routeur.
3. **Toute écriture laisse une trace**, sans qu'aucun service ait à y penser.
4. **Le cercle de visibilité échoue fermé.** Sans adaptateur branché, rien ne sort.

---

## 2. Authentification

`api/config/packages/security.yaml`.

| Élément | Valeur | Motif |
|---|---|---|
| Provider | `entity` sur `Core\Entity\User`, propriété `email` | — |
| Authentificateur | `json_login` sur la route `core_connexion` | § 2 du contrat |
| Authentificateur (clé d'accès) | `Core\Security\CleDAccesAuthenticator` sur `core_connexion_cle_d_acces` | § 2.4 du contrat, § 2 bis ci-dessous |
| Clés du corps | `email` / `password` | fixées par `username_path` / `password_path` ; ce sont aussi des noms de propriété (§ 1.2), il ne faut pas les « franciser » |
| Anti-force brute | `login_throttling`, 5 tentatives / 15 min | par couple identifiant + IP ; Symfony double d'un compteur par IP seule à 5 × le plafond |
| Point d'entrée | `Core\Security\JsonAuthenticationEntryPoint` | sans lui, Symfony redirige vers un formulaire |
| Hachage | `auto` | le meilleur standard courant du composant, et la migration transparente des empreintes |
| Rôles | `ROLE_ADMIN` → `ROLE_USER` | deux rôles, pas trois — voir § 3 |

### Les routes

| Méthode | Chemin | Nom | Accès |
|---|---|---|---|
| `POST` | `/api/connexion` | `core_connexion` | public |
| `POST` | `/api/deconnexion` | `core_deconnexion` | authentifié |
| `GET` | `/api/session` | `core_session` | authentifié |
| `POST` | `/api/session/mot-de-passe` | `core_session_mot_de_passe` | authentifié |
| `POST` | `/api/connexion/cle-d-acces/options` | `core_connexion_cle_d_acces_options` | public |
| `POST` | `/api/connexion/cle-d-acces` | `core_connexion_cle_d_acces` | public |
| `POST` | `/api/cles-d-acces/options-d-enregistrement` | `core_cles_d_acces_options` | authentifié, hors incarnation |
| `POST` | `/api/cles-d-acces` | `core_cles_d_acces_enregistrer` | authentifié, hors incarnation |
| `GET` | `/api/cles-d-acces` | `core_cles_d_acces_lister` | authentifié |
| `PATCH` | `/api/cles-d-acces/{id}` | `core_cles_d_acces_renommer` | authentifié, hors incarnation |
| `DELETE` | `/api/cles-d-acces/{id}` | `core_cles_d_acces_supprimer` | authentifié, hors incarnation |
| `GET` | `/api/notifications` | `core_notifications` | authentifié |
| `GET` | `/api/notifications/compteur` | `core_notifications_compteur` | authentifié |
| `POST` | `/api/notifications/{id}/lue` | `core_notification_lue` | authentifié |
| `POST` | `/api/notifications/lues` | `core_notifications_lues` | authentifié |
| `GET` | `/api/administration/journal` | `core_admin_journal` | `ROLE_ADMIN` |
| `GET` | `/api/administration/reglages` | `core_admin_reglages` | `ROLE_ADMIN` |
| `PATCH` | `/api/administration/reglages/{cle}` | `core_admin_reglage_modifier` | `ROLE_ADMIN` |
| `GET` | `/api/administration/utilisateurs` | `core_admin_utilisateurs` | `ROLE_ADMIN` |
| `GET` | `/api/administration/utilisateurs/compteurs` | `core_admin_utilisateurs_compteurs` | `ROLE_ADMIN` |
| `GET` | `/api/administration/utilisateurs/{id}` | `core_admin_utilisateur` | `ROLE_ADMIN` |
| `PATCH` | `/api/administration/utilisateurs/{id}` | `core_admin_utilisateur_modifier` | `ROLE_ADMIN` |
| `POST` | `/api/administration/voir-comme` | `core_admin_voir_comme` | `ROLE_ADMIN` |
| `DELETE` | `/api/administration/voir-comme` | `core_admin_voir_comme_quitter` | `ROLE_ADMIN` |

### Le compte suspendu — deux pièges, deux corrections

**Le refus est en `checkPostAuth`, pas en `checkPreAuth`.** Symfony appelle `checkPreAuth`
*avant* la vérification du mot de passe (priorité 256 sur `CheckPassportEvent`, contre 128
pour `CheckCredentialsListener`). Y placer le refus ferait de `POST /api/connexion` un
**oracle** : n'importe qui apprendrait, sans connaître le mot de passe, quelles adresses
correspondent à un compte suspendu. Sur une application dont l'invitation est le seul
verrou, c'est la fuite à ne pas ouvrir. En `checkPostAuth`, le contrat est respecté pour le
titulaire légitime et tous les autres reçoivent `identifiants_invalides`.

**Une session déjà ouverte est fermée à chaud.** Le `user_checker` ne couvre que
l'authentification *interactive* : suspendre un compte connecté n'aurait rien fait jusqu'à
sa prochaine connexion. `Core\EventSubscriber\AccountStatusSubscriber` (priorité 5 sur
`kernel.request`, donc après le pare-feu) vide le jeton, invalide la session et rend
`401 compte_suspendu`. Les deux chemins publics en sont exclus explicitement, sinon
l'interrogation forcerait l'authentification et annulerait le `lazy: true` du pare-feu.

## 2 bis. Clés d'accès (passkeys, WebAuthn)

Depuis le 1er octobre 2026 : se connecter avec Face ID, Touch ID, l'empreinte d'un
téléphone Android ou Windows Hello. Le contrat (routes, corps, réponses) est au § 2.4 de
`docs/api-contract.md` ; ici, les décisions et leurs raisons.

**Bibliothèque : `web-auth/webauthn-lib` 5.3** (Symfony 8 et PHP 8.4 pris en charge). Toute
la cryptographie est la sienne — attestation, signature COSE, empreinte de la partie de
confiance, origine, drapeaux. Pas de bundle Symfony : la politique tient dans une classe
(`Core\Service\ClesDAcces`), et le bundle aurait apporté ses propres routes et son propre
stockage là où le dépôt veut les siens. Aucune extension PHP de plus dans l'image (bcmath,
openssl, mbstring y sont déjà) ; EdDSA, qui exigerait sodium, n'est pas proposé.

**Ce qui est gardé : la moitié publique.** `core_passkey` (`Core\Entity\Passkey`) porte
l'identifiant de la clé, la clé publique COSE, le compteur de signatures, les transports,
l'AAGUID et un nom. La clé privée ne quitte jamais l'appareil : une fuite de la table
n'ouvre aucun compte. `ON DELETE CASCADE` vers `core_user`.

**Un authentificateur du pare-feu, pas un contrôleur.** `CleDAccesAuthenticator` est déclaré
en `custom_authenticators` à côté de `json_login`. Il hérite ainsi, sans rien redire, du
limiteur, du `UserChecker` (compte suspendu refusé **après** vérification de la signature —
même raisonnement anti-oracle qu'au § 2), de la migration de session et du journal. Il
délègue ses réponses aux gestionnaires de `json_login` : succès et échecs sont
**identiques au caractère près** à ceux du mot de passe.

**Le limiteur est partagé.** L'identifiant que le passeport présente au limiteur est le
courriel du compte quand la clé est connue : on ne double pas son quota en alternant les
portes. Une clé inconnue compte sous l'identifiant fixe `cle_d_acces_inconnue` — un
balayage de clés inventées bute au cinquième essai par IP.

**Les défis.** 32 octets, gardés **en session** avec les options qu'ils accompagnent,
**consommés avant toute vérification** (un rejeu trouve la place vide, que la première
tentative ait réussi ou non), valables **5 minutes** (horloge `symfony/clock`, pilotable en
test). Cinq au plus par session et par cérémonie ; la réponse désigne le sien par
`clientDataJSON.challenge`, ce qui laisse coexister le défi de l'autoremplissage et celui
du bouton.

**Les exigences.** `userVerification: required` (une présence seule ne vaut pas un mot de
passe), `residentKey: required` (clé découvrable, connexion sans courriel), `attestation:
none` (pas de liste d'appareils agréés à tenir). Le compteur de signatures qui **recule**
trahit un clone : refus. Un compteur à zéro (Apple, Google) n'est pas contrôlé.

**La partie de confiance** est l'hôte de `HERITAGE_URL_PUBLIQUE`, jamais celui de la requête
(§ 8 quater). Changer de domaine rend les clés existantes inutilisables — elles sont liées
à l'hôte par l'appareil lui-même — sans toucher au mot de passe.

**Incarnation.** Enregistrer, renommer, supprimer une clé est refusé pendant une
incarnation (`409 incarnation_active`) : comme le mot de passe et le courriel, c'est un
secret du compte (ADR 0010).

**Une session ne suffit pas pour ajouter une clé.** Une clé d'accès est un moyen de
connexion **permanent** : ajoutée depuis une session volée (onglet oublié, poste
partagé), elle transformerait un accès temporaire en accès durable, et rouvrirait le
compte juste après un changement de mot de passe fait en urgence. Trois mesures :

- **Preuve d'identité récente** (`Core\Service\PreuveDIdentiteRecente`) pour obtenir un
  défi d'enregistrement : connexion de moins de **10 minutes** — l'heure en est notée en
  session à chaque `LoginSuccessEvent` (`PreuveDIdentiteSubscriber`), mot de passe comme
  clé —, ou `motDePasseActuel` juste dans le corps (`422` sinon, comme au § 2.1). Sans
  défi, aucune réponse d'enregistrement ne passe.
- **Avis par courriel** à chaque ajout (`Core\Service\AvisDeCleDAcces`), au mieux : sans
  SMTP rien ne part, une panne est journalisée sans faire échouer l'ajout.
- **Révocation** : changer son mot de passe et la réinitialisation par l'administrateur
  retirent **toutes** les clés du compte (`ClesDAcces::retirerToutes()`), dans la même
  écriture que le mot de passe, une ligne de suppression au journal par clé. On ne sait
  pas distinguer la clé plantée des légitimes ; le titulaire réenregistre les siennes.

Limite connue : une session volée **dans les dix minutes** d'une connexion peut encore
ajouter une clé sans mot de passe. L'avis par courriel et la révocation au changement de
mot de passe sont là pour ce cas.

**Journal.** `changes.methode` (`mot_de_passe` / `cle_d_acces`) sur `connexion` et
`connexion_echouee`. `publicKey` et `credentialId` sont dans `CHAMPS_SENSIBLES`,
`lastUsedAt` et `signCount` dans `CHAMPS_TECHNIQUES` : une connexion par clé ne laisse pas
de ligne `modification`.

**Tests.** `api/tests/Core/ClesDAccesTest.php`, avec `Double\AuthentificateurSimule` : une
vraie paire P-256, de vrais `authenticatorData` / `attestationObject` CBOR, de vraies
signatures ECDSA. La bibliothèque les vérifie sans rien savoir du test — aucun contrôle
n'est doublé.

---

## 3. Rôles et régimes — ce ne sont pas la même chose

**Deux rôles, et rien de plus** : `ROLE_ADMIN`, `ROLE_USER`.

> **`ROLE_MEMBER` n'existe pas, et c'est délibéré.** La consigne initiale de la tâche le
> demandait ; le § 1.6 du contrat d'API l'interdit explicitement : « un `ROLE_MEMBRE` qu'on
> retire à l'activation serait une seconde source de vérité à côté de `core_user.status` ».
> Les deux se seraient désynchronisés au premier bogue d'activation. Le contrat a été suivi.
> **Si c'est l'inverse qui est voulu, il faut d'abord corriger le contrat**, pas le code.

Le **régime** est porté par `core_user.status`, lu par `Core\Security\RegimeVoter` :

| Attribut | Vrai quand | Usage |
|---|---|---|
| `RegimeVoter::ATTENTE` | compte authentifié non suspendu | lecture : l'arbre ancien suffit |
| `RegimeVoter::MEMBRE` | `status = actif` | écriture : proposer, téléverser |

`RegimeVoter::regimeDe(User): Regime` est **l'unique** correspondance statut → régime du
projet. Un second endroit qui la referait, même trivialement, finirait par ne plus dire la
même chose.

### Le régime « en attente » n'est pas un cercle

`Core\Enum\Regime` a **deux** valeurs — `aucun`, `cercle` — et **aucune profondeur**.

> Par la formule de l'ADR 0003, `N = 0` donne `A(u) = { u }`, puis la passe des conjoints y
> ajoute le conjoint : soit exactement les deux fiches de personnes vivantes qu'on veut
> fermer. **Un `N = 0` n'est pas un cercle vide, c'est une implémentation fausse
> immédiatement.** C'est pourquoi la distinction est portée par une énumération et non par
> un entier : il n'existe aucune valeur de `N` qui produise le régime « aucun ».

`Core\Service\SessionPayloadBuilder` applique la règle en deux temps :

1. **le défaut est fermé pour tout le monde** — `cercle = {"regime":"aucun"}`, `membre =
   null` — de sorte qu'un `Membership` absent ou en panne retire de la visibilité au lieu
   d'en accorder ;
2. **la charge d'un compte `en_attente` est refermée après coup**, quoi qu'aient rendu les
   contributeurs : régime `aucun`, `personId` et `personLabel` **retirés** de `membre`.

Le second point n'est pas théorique : `mbr_member` existe dès l'inscription, pré-lié à une
personne. Un contributeur écrit sans y penser rendrait donc le nom de la personne réclamée à
un compte dont le rattachement n'est justement pas établi. *Confirmer une date de naissance
n'est pas la lire, et le pré-lien n'ouvre aucun accès* (§ 12.1 du contrat,
`docs/arbitrages.md` § 3). `tests/Core/RegimeEnAttenteTest.php` injecte un contributeur
volontairement bavard et vérifie que le nom ne sort pas.

---

## 4. Le cercle de visibilité — le contrat partagé

`App\Module\Core\Security\CercleDeVisibiliteInterface`

```php
public function regime(): Regime;                          // Aucun | Cercle
public function voitPersonne(int $personId): bool;
/** @param list<int> $personIds @return list<int> */
public function filtrerPersonnes(array $personIds): array;
```

Consommé par `Media`, `Tree`, `Timeline` et `Proposal` ; implémenté par `Membership`.

**Pourquoi dans `Core` et non un port par module.** Quatre interfaces déclarées séparément,
ce sont quatre définitions de « visible » qui divergeront — et une règle de confidentialité
ne se trompe qu'une fois pour fuir. L'ADR 0002 interdit les *relations Doctrine*
inter-modules et prescrit un noyau partagé **minimal** ; un contrat d'interface est
exactement ce qu'un tel noyau a vocation à porter. Aucune entité ne franchit la frontière.

Trois décisions de signature, toutes opposables :

- **Le sujet est un `int $personId`, jamais une entité.** `Core` est référencé par les six
  autres modules et n'en référence aucun (§ 2.6 du modèle). Les modules au vocabulaire plus
  riche — « ce média porte sur une union », « cette citation étaye une fiche » — traduisent
  **chez eux** et n'envoient ici que des identifiants de personnes. Le port local de `Media`
  garde donc tout son sens : il traduit, il ne juge pas.
- **Le régime est dans la signature**, pas dans un commentaire. Aucune méthode n'accepte ni
  ne rend de profondeur : « cercle avec `N = 0` » n'est pas *exprimable* dans ce contrat.
  `tests/Core/CercleFermeTest.php` le vérifie par réflexion, pour que ça reste vrai si
  quelqu'un ajoute une méthode.
- **Le lecteur est implicite.** L'implémentation le résout elle-même, session et bascule
  « voir comme un membre » comprises. Motif : un lecteur passé par chaque appelant est un
  lecteur qu'un appelant finira par construire sans la bascule, et *une bascule qu'on oublie
  de rejouer sur un appel ne vérifie plus rien* (§ 10.4). Corollaire pour les consommateurs
  qui calculent déjà un régime de leur côté — `Media\Security\FabriqueDeLecteur` — : le
  **lire** ici plutôt que le recalculer, sinon les deux divergeront pendant la bascule.

### Les règles que toute implémentation doit respecter

1. Une personne **décédée** est un fait historique : visible de tout compte connecté.
2. Une personne **vivante** n'est visible que dans le cercle du lecteur, et **jamais** en
   régime `Aucun` — y compris celle à laquelle il prétend se rattacher.
3. **Un décès inconnu vaut vivant, donc masqué.** *« On ne sait pas si elle est morte » n'est
   pas une autorisation de publier ses dates.*
4. Ces méthodes ne distinguent pas « n'existe pas » de « pas le droit » : l'appelant rend
   `404` dans les deux cas (§ 15.3), sinon la réponse dit à un lecteur hors cercle quelles
   personnes existent.
5. **Le normaliseur n'a pas de mode « sans filtre ».** Un administrateur passe par un cercle
   complet, jamais par un `if ($estAdmin) { return true; }`.

### Le défaut fermé — une règle permanente, pas un bouchon

`App\Module\Core\Security\CercleFerme` refuse tout, **y compris les personnes décédées**,
faute de pouvoir établir le décès.

> Un défaut **ouvert** transforme une erreur de câblage — service non déclaré, alias mal
> écrit, module désactivé, refonte de la configuration dans deux ans — en **fuite de données
> de personnes vivantes, silencieuse et sans message d'erreur**. Un défaut **fermé**
> transforme la même erreur en fiches vides : visible immédiatement, corrigé dans l'heure, et
> jamais au détriment de quelqu'un. Les deux pannes ne coûtent pas le même prix.

Elle journalise en `warning` **une fois par requête** et non par appel : le graphe pose la
question 274 fois, et 274 lignes identiques noieraient le message au lieu de le porter.

**Câblage.** Alias dans `config/services/core.yaml`. `Membership` prend la main en le
redéclarant dans `config/services/membership.yaml` — le glob `services/*.yaml` est trié
alphabétiquement et `membership` passe après `core`. Si cet ordre changeait un jour, l'alias
resterait sur `CercleFerme` : l'application se **refermerait** au lieu de s'ouvrir. Le mauvais
côté de ce pari n'existe pas. Vérification :
`bin/console debug:container "App\Module\Core\Security\CercleDeVisibiliteInterface"`.

L'alias est déclaré **public en `when@test`** parce qu'aucun module ne le consomme encore et
que le conteneur optimisé supprime un alias que personne n'injecte. **À retirer dès qu'un
module l'injecte pour de bon** ; c'est écrit dans le fichier.

---

## 5. Journal d'audit

Deux voies alimentent `core_audit_log`, et elles ne font pas le même travail.

**La voie mécanique** — `Core\EventSubscriber\AuditDoctrineSubscriber` : toute création,
modification et suppression d'entité, sans qu'aucun service ait à y penser. Un journal
alimenté à la main a des trous, et ses trous sont exactement les chemins d'écriture qu'on a
oubliés — c'est-à-dire ceux qu'on aurait eu le plus besoin de relire.

| Règle | Détail |
|---|---|
| Diff | `{"surname": {"avant": …, "apres": …}}` — clés **françaises** ; le dépôt de référence écrit `from`/`to`, ne pas le recopier |
| Champs sensibles | `password`, `totpSecret`, `tokenHash` → `"***"`, **jamais écrits** — ni l'ancienne valeur, ni la nouvelle |
| Champs techniques | `updatedAt`, `lastLoginAt` ignorés ; si le diff est vide, **pas de ligne** |
| Inaltérabilité | un `UPDATE` ou un `DELETE` sur `AuditLog` lève une `LogicException` |
| Hors HTTP | pas de journalisation automatique — une commande ou un chargement de fixtures écrirait des centaines de lignes sans acteur ni IP ; les commandes consignent ce qui les concerne par `AuditService::enregistrer()` |

La règle des champs techniques n'est pas cosmétique : sans elle, **chaque connexion**
laisserait une ligne `modification` vide à côté de sa ligne `connexion`, et le journal
deviendrait illisible là où il sert le plus.

**La voie des décisions** — `Core\Service\AuditService::enregistrer()` : validation, refus,
invitation, changement de rôle, réglage modifié, bascule « voir comme un membre ». Une
décision n'est pas déductible d'un diff de colonnes : refuser une demande et la retirer
touchent la même colonne `status`.

> **Les deux voies coexistent volontairement.** Un `PATCH` de réglage laisse une ligne
> `reglage_modifie` (la décision, filtrable à l'écran) *et* une ligne `modification` (le diff
> exact, qu'aucune relecture n'a pu omettre). L'écran filtre par `action` ; on préfère une
> ligne de trop à un trou.

**Connexions** — `Core\EventSubscriber\LoginAuditSubscriber` : `connexion`,
`connexion_echouee`, `deconnexion`. Le mot de passe tenté n'est écrit sous aucune forme. Une
tentative bloquée par le limiteur passe aussi par `LoginFailureEvent` et est donc consignée :
c'est le pic d'échecs qui rend un balayage visible, pas son interruption.

**Lecture** — `GET /api/administration/journal`, `ROLE_ADMIN`, paginé. **C'est toute la
surface HTTP du journal** : aucune route ne le modifie, ne l'efface ni ne le purge. Un test
parcourt le routeur et vérifie qu'aucune route touchant « journal » n'accepte autre chose
qu'un `GET`. `changes` est rendu **tel qu'il est en base** — un journal qu'on filtre à la
lecture est un journal qui contient encore le secret.

---

## 6. Enveloppe d'erreur unique

Le dépôt de référence émet deux formes ; **Heritage n'en a qu'une** (§ 15.1).

`Core\Http\ErrorEnvelope` la construit, et elle seule. `Core\Exception\DomainException` et
ses huit filles sont **le seul mécanisme par lequel un service dit « non »** :

| Classe | HTTP | Code |
|---|---|---|
| `ValidationException` | 422 | `donnees_invalides`, ou un code nommé (`valeur_hors_bornes`) |
| `NotFoundException` | 404 | `introuvable` |
| `ForbiddenException` | 403 | `acces_refuse` / `regime_en_attente` |
| `ConflictException` | 409 | passé au constructeur — le § 15.2 en compte une douzaine |
| `TooManyRequestsException` | 429 | `trop_de_tentatives` + en-tête `Retry-After` |
| `FichierTropVolumineuxException` | 413 | `fichier_trop_volumineux`, `{maxOctets}` |
| `TypeDeFichierRefuseException` | 415 | `type_de_fichier_refuse`, `{mimesAutorises}` |

Les deux dernières ont été écrites par l'agent `Media` et montées ici : tout autre module qui
recevra un fichier aura le même besoin, et deux exceptions au même code d'erreur dans deux
modules est le doublon qui finit par produire deux enveloppes pour la même panne.

> `TypeDeFichierRefuseException` **ne dit pas quel type a été détecté**, seulement ce qui est
> accepté (§ 7.1). Renvoyer le type reniflé d'un fichier refusé, c'est offrir un service
> d'identification de format gratuit et non journalisé. **Propriété à conserver.**

`Core\EventSubscriber\ApiExceptionSubscriber` traduit, en un seul endroit, à la priorité 0 —
donc **après** le `ExceptionListener` du pare-feu (priorité 1), qui décide d'abord si un accès
refusé devient une `401` par le point d'entrée ou une `403`. Il couvre aussi les erreurs **du
routeur** : une `404` de routeur qui rendrait du HTML casserait le front sur exactement le cas
où il a le plus besoin d'un JSON. Une `BadRequestHttpException` — corps JSON illisible — sort
en `422 donnees_invalides`, le § 15.2 ne connaissant pas de `400`.

**Aucun contrôleur ne compose d'enveloppe à la main.** C'est la règle, et elle vaut pour les
six autres modules.

---

## 7. En-têtes et durcissement

`Core\EventSubscriber\SecurityHeadersSubscriber`, sur les réponses `/api` :
`Content-Security-Policy: default-src 'none'`, `X-Content-Type-Options: nosniff`,
`X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, COOP et CORP `same-origin`,
`X-Permitted-Cross-Domain-Policies: none`, `Permissions-Policy`.

**Cache** : `Cache-Control: no-store` + `Vary: Cookie`, sauf sur la route porteuse d'un
`ETag` — le graphe, seule route du contrat à en avoir un — reconnue à cet `ETag` plutôt qu'à
une liste de chemins que personne ne penserait à tenir à jour. `no-store` et non `no-cache` :
ces réponses contiennent des données de personnes vivantes, elles n'ont rien à faire dans le
cache disque d'un navigateur familial partagé.

**Priorité −1100, et ce nombre vaut −1000 moins un.** `AbstractSessionListener` s'exécute à
−1000 et pose `Cache-Control: max-age=0, must-revalidate, private` dès qu'une session est
démarrée. En passant avant lui, `no-store` se noyait au milieu de directives de revalidation ;
en passant après, c'est nous qui écrivons en dernier. La valeur finale observée est
`no-store, private`, et c'est **correct** : le `private` restant ne vient pas du listener de
session mais de `ResponseHeaderBag::computeCacheControlValue()`, qui l'ajoute à toute valeur
ne portant ni `public` ni `s-maxage`. Sur une route dont la charge dépend du lecteur, il va
dans le même sens — on ne le combat pas.

> C'est la même famille de défaut que le **piège nginx** du dépôt de référence : un
> `add_header` placé dans un bloc `location` **remplace** le jeu hérité du bloc `server` au
> lieu de s'y ajouter, et le `location /assets/` de la référence y perd silencieusement sa
> CSP sur tous les actifs. Deux composants qui écrivent le même en-tête sans se connaître, et
> un résultat qui dépend de l'ordre. **Toute modification d'un bloc `location` doit réémettre
> l'intégralité des en-têtes.** La configuration nginx est hors du périmètre de ce module.

Le test vérifie les **directives** (`no-store` présente, `public` et `max-age` absentes) et
non une chaîne exacte : une chaîne exacte sur un en-tête composite recasse au prochain
composant qui y touche.

**IP client** : `trusted_proxies: private_ranges` était déjà posé dans
`config/packages/framework.yaml` (`when@prod`) — rien à changer. C'est de là que vient l'IP du
journal d'audit, pas de `REMOTE_ADDR`.

---

## 8. Réglages

`Core\Service\SettingService` + `Core\Controller\SettingController`.

Toute la table est chargée **d'un coup** et gardée en mémoire pour la durée de la requête : une
vingtaine de lignes, et une lecture de réglage se produit dans presque tous les chemins de
code. **Pas de cache persistant** — il faudrait l'invalider, et une invalidation oubliée sur
`cercle.generations` est une fuite, pas un ralentissement.

Le service **caste à la lecture** selon `valueType` et **refuse à l'écriture** une valeur qui
ne caste pas. `PATCH` rend `404` sur clé inconnue (on n'apprend pas à l'appelant quelles clés
existent), `422 donnees_invalides` sur cast impossible, `422 valeur_hors_bornes` avec
`{minValue, maxValue}` dans `details` hors bornes.

`cercle.generations` — le `N` du cercle — vaut **3** par défaut, borné à 1–6, et se change
**sans redéploiement**, daté, tracé et réversible. La borne haute n'est pas décorative : au-delà
de 6, le cercle couvre l'arbre entier et la règle ne protège plus personne. La borne basse non
plus : `N = 0` n'est pas « pas de cercle », c'est un cercle qui contient encore `u` et son
conjoint — le régime `aucun` ne s'obtient pas par ce réglage.

**Rien n'est à invalider après un `PATCH`** : `{N}` est un segment de la clé de cache du
cercle, les anciennes entrées deviennent inatteignables d'elles-mêmes (§ 2.3 du modèle). En
revanche le **front** doit recharger la session et le graphe, sans quoi il affichera l'ancien
filtrage jusqu'au prochain rechargement de page.

En lecture, `SettingService::entier()` prend un défaut : une lecture de réglage ne doit jamais
faire tomber une requête, et surtout pas en ouvrant le cercle.

---

## 8 bis. Notifications, comptes et bascule

### Notifications — `Core\Service\NotificationService`

C'est la pièce qui fait exister la promesse du produit : un membre propose une correction,
l'administrateur la **refuse avec un message**, et ce message doit **parvenir à l'auteur**.
Sans elle, le § 6.3 du dossier est une intention sans effet.

Deux propriétés qui ne se devinent pas :

- **`notifier()` ne lève jamais.** Une notification est produite *après* le `commit` de
  l'écriture qui l'a causée. Si elle levait, elle ferait échouer un appel dont le travail
  est déjà validé : l'administrateur verrait une erreur sur une validation qui a bel et
  bien eu lieu, et il recommencerait. Une notification perdue est un dommage borné — la
  décision reste au journal d'audit. En cas d'échec : `error` dans les journaux, `null` en
  retour.
- **Les titres et les corps se composent à l'écriture**, jamais à la lecture (§ 16.2). Une
  notification se relit des mois plus tard, quand le cercle du destinataire a pu changer ;
  une composition paresseuse rouvrirait la question de la confidentialité à chaque
  affichage. **C'est à l'appelant de composer le texte** en appliquant le cercle du
  destinataire — ce service ne nomme personne de lui-même.

`notifierLesAdministrateurs()` existe pour que les autres modules n'aient pas à savoir qui
est administrateur : `Proposal` ne tient pas la liste des comptes, et une requête sur
`core_user` depuis un autre module rouvrirait la frontière de l'ADR 0002.

> **Où vit l'adaptateur de `Proposal`.** Son port `NotificateurDeDemandes` demande « une
> classe de `Core` qui implémente cette interface ». **Elle doit vivre dans `Proposal`, pas
> ici** : son type-hint est `Proposal\Entity\ChangeRequest`, et le § 2.6 du modèle est
> catégorique — « `Core` est le noyau : il est référencé, il ne référence pas ». L'adaptateur
> est une délégation de quatre méthodes vers `NotificationService`, et c'est précisément
> parce que `notifier()` ne lève jamais qu'il n'a aucun `try/catch` à recopier.

### Comptes — `Core\Service\UserAdminService`

`roles`, `status`, `displayName`, appliqués ensemble. Deux règles à ne pas relâcher :

- **`en_attente` n'est pas un statut qu'on repose.** « On ne remet pas un compte en
  attente, on le suspend » : y revenir après coup serait une troisième histoire, avec un
  `mbr_member` déjà activé.
- **`409 dernier_administrateur`.** Le compte comparé est le dernier administrateur
  **actif** ; un administrateur suspendu ne compte pas, il ne s'authentifie pas. Se
  verrouiller dehors n'a pas d'autre issue que la console (§ 10).

Une **suspension** est journalisée `compte_suspendu`, un **changement de rôle**
`role_modifie`. Une réactivation n'a pas d'action dédiée dans `AuditAction` : elle reste
couverte par la ligne `modification` automatique, qui porte le diff `status`. La voie
mécanique n'a donc pas de trou.

### Bascule « voir comme un membre » — `Core\Service\BasculeVoirComme`

Commodité, et **seul moyen de vérifier la règle de confidentialité de l'extérieur**. Trois
pièges, chacun la viderait de son sens sans rien casser de visible.

1. **L'`ETag` du graphe doit incorporer `memberEmprunte()`.** Sans cela, l'administrateur
   revalide, reçoit `304`, et regarde **son propre graphe non filtré** en croyant tester
   celui de quelqu'un d'autre : l'outil censé vérifier la confidentialité affirme alors que
   tout va bien, quoi qu'il arrive. C'est la panne la plus coûteuse du fichier, parce
   qu'elle est silencieuse et qu'elle ment dans le sens rassurant. Le calcul de l'`ETag`
   appartient à `Tree` ; `memberEmprunte()` est publique **pour cela**.
2. **Aucune écriture sous identité empruntée.** `refuserSiActive()` est à appeler en tête
   de **toute** route d'écriture — demande, validation, refus, invitation, téléversement,
   réglages, rôles. Déjà branché sur `PATCH /reglages/{cle}` et
   `PATCH /utilisateurs/{id}` ; **les autres modules doivent le brancher chez eux**.
3. **Ce n'est pas un contournement du filtre.** La bascule remplace un cercle par un
   autre ; elle n'en supprime jamais un.

Elle est portée par la **session** et journalisée **une fois**, à l'activation : une
bascule par requête est une bascule qu'on oublie de rejouer, et un appel qui l'oublie ne
vérifie plus rien.

---

## 8 ter. Deux remontées dans le noyau

### Le calque « en attente » — `Core\Contract\CalqueEnAttenteInterface`

Deux modules avaient écrit **deux interfaces pour la même couture** : `Tree` déclarait
`PortDesPropositions`, `Proposal` déclarait `PropositionsEnAttente`. Aucune des deux ne
pouvait être branchée sans qu'un module en importe un autre (ADR 0002, règle 3) — le même
problème que le cercle de visibilité, et la même solution : le contrat monte dans `Core`,
`Proposal` l'implémente, `Tree`, `Media` et `Timeline` le consomment.

Le contrat est **celui de `Proposal`, déplacé au mot près** : il était déjà écrit, éprouvé
et vérifié contre le contrat d'API. Trois propriétés qu'il ne faut pas perdre en le
relisant :

1. `calque()` **omet** les lignes sans proposition — une clé absente et une clé vide ne
   disent pas la même chose au front ;
2. `noeudId` vaut `#<demandeId>/<localRef>`, mais `demandeId` et `brouillonRef` sont rendus
   **séparément, sans dièse** : le front ne doit jamais découper `noeudId`, le `#` étant le
   séparateur de fragment d'URL — tout ce qui le suit ne quitterait pas le navigateur, et
   la panne serait silencieuse ;
3. le nœud ne porte **ni coordonnées, ni `generation`, ni `branch`, ni `visibilite`**, et
   `displayName` n'est qu'un repli : les trois parties du nom sont rendues telles quelles
   pour que `Tree` compose selon **sa** règle, sinon un nœud proposé serait nommé autrement
   que ses voisins.

Et le partage de responsabilité, qui appartient à l'appelant : **`Tree` compose son cercle
par-dessus**. Une personne vivante hors cercle n'a pas de clé `enAttente` du tout, et c'est
`Tree` qui n'appelle pas le port pour ces lignes-là. *Le port ne le sait pas et n'a pas à
le savoir.*

> **L'implémentation de `Proposal` n'utilise pas `#[AsAlias]`, et c'est un piège inversé.**
> Les attributs sont traités avec `_autoload.yaml`, chargé **avant** `core.yaml` où vit le
> repli fermé : un `#[AsAlias]` serait donc écrasé par le repli, et l'application
> tournerait sans calque, sans erreur et sans test rouge. L'alias est en YAML dans
> `proposal.yaml`, chargé après `core.yaml`.

`Core\Enum\SubjectKind` a suivi, pour la même raison que le contrat lui-même : elle est
nommée dans la signature. Les valeurs stockées ne changent pas — la colonne est un
`VARCHAR`, seuls les `use` ont bougé.

### Le repliement des accents — `Core\Service\CleDeRecherche`

Il existait **en double** : dans le service qui **écrivait** `tree_person.search_key` à
l'import (module `Gedcom`, supprimé depuis — ADR 0018), et dans celui qui la **cherche**. La règle 3 interdisait à l'un d'importer
l'autre, donc la table était recopiée.

> Le jour où les deux auraient divergé d'un seul caractère, la recherche aurait cessé de
> trouver les noms qui le portent — **sans erreur et sans test rouge**. Sur un arbre plein
> de *Épine*, *Sarriette*, *Génévrolle* et *Chardon*, la panne était garantie, et elle
> n'aurait pas ressemblé à une panne : juste une personne qu'on ne retrouve plus.

**N'employez pas `iconv //TRANSLIT`.** Son résultat dépend de la bibliothèque du système :
glibc rend `é` → `e`, la libiconv de Windows rend `'e`, et musl — **l'image Alpine du
projet** — rend `?`. Développement sous Windows, exécution sous Alpine : la clé écrite et
la clé cherchée seraient calculées par deux bibliothèques différentes, soit exactement la
panne que la remontée supprime, réintroduite par le moyen choisi pour l'empêcher. La table
est donc explicite. C'est verbeux et déterministe.

Deux familles de tests, et il faut les deux : la **propriété de sortie** (`[A-Z0-9 ]`,
rien d'autre — elle attrape ce qu'aucune liste d'exemples ne couvre, et c'est elle qui
verrait un `'e` ou un `?`) et l'**aller-retour** écrire-puis-chercher. Plus une assertion
structurelle : les deux consommateurs n'ont plus de table à eux.

---

## 8 quater. Les comptes, et l'adresse publique

### Un compte par personne, jamais partagé

C'est la règle, et elle est aussi dans l'en-tête de `compose.yaml` — **si les deux
divergent, c'est celle-ci qui est périmée** : l'en-tête de `compose.yaml` porte les
valeurs courantes.

Le motif n'est pas la discipline, c'est l'audit : **un compte partagé rend
`core_audit_log` muet sur l'auteur.** Ce n'est pas théorique — trois intervenants se sont
confondus sous une même adresse au cours d'une soirée de travail, au point que celui qui
enquêtait n'a pas pu dater son propre relevé. Le journal a continué d'enregistrer
fidèlement : il ne savait simplement plus *qui*, et c'est la seule chose qu'on lui
demandait.

Le compte d'amorçage du commanditaire est décrit par l'environnement et reposé par
`bin/console heritage:amorcer-comptes` (§ 10). **Les comptes de sondage créés à la main
pour vérifier un écran sont à supprimer avant toute mise en ligne** : un administrateur
actif dont personne ne se souvient est le pire des comptes partagés.

**Les agents n'ont pas de compte : ils ont un jeton.** Un jeton d'API (ADR 0021) est rattaché
à l'utilisateur du commanditaire et **nomme** son porteur dans `core_audit_log`
(`api_token_label`) : l'audit sait *qui* a agi sans qu'il existe un compte de plus. C'est la
raison pour laquelle on ne crée pas de compte de service.

### L'adresse publique est configurée, jamais déduite de la requête

`HERITAGE_URL_PUBLIQUE`, lue par `Core\Service\UrlPublique`. C'est sur elle que sont
bâtis les liens d'invitation, **et ces liens partent par courriel**.

> **La vulnérabilité que ce réglage supprime.** Le lien était bâti sur
> `Request::getSchemeAndHttpHost()`. Or l'en-tête `Host` et les `X-Forwarded-*` viennent
> du client, l'API leur fait confiance (`trusted_proxies: private_ranges`), et aucun
> `trusted_hosts` ne les bornait. Une requête au `Host` falsifié faisait donc fabriquer
> **par nous** un lien vers un domaine attaquant, expédié **dans un courriel authentique,
> depuis notre adresse**, à une personne réelle de la famille — qui n'avait aucun moyen
> de le distinguer d'un vrai. C'était la première demande du commanditaire retournée
> contre ses destinataires.

**Configurer plutôt que valider**, et c'est l'arbitrage à retenir : il n'y a plus rien qui
vienne de l'extérieur, donc plus rien à contrôler — ni à oublier de contrôler au prochain
lien qu'on ajoutera. Le VPS devait de toute façon connaître son adresse.

Deux conséquences à ne pas relâcher :

- **Aucun repli sur la requête.** Sans configuration, `UrlPublique` lève. Un repli
  rouvrirait le trou au moment précis où personne ne regarde.
- **Toute URL sortante future passe par là** — réinitialisation de mot de passe,
  confirmation d'adresse, lien de notification. Au moment d'écrire ces lignes, le lien
  d'invitation était la seule ; un `grep getSchemeAndHttpHost` sur `src/` doit rester
  vide.

`framework.trusted_hosts` (`HERITAGE_HOTES_AUTORISES`) est la **défense en profondeur**,
pas le correctif. Son défaut est la **liste blanche du développement local**, déclarée en
un seul endroit — le paramètre `app.hotes_autorises_defaut` de
`api/config/packages/framework.yaml` :

```
^(localhost|127\.0\.0\.1|\[::1\]|api)(:\d+)?$
```

> **Ce défaut valait `.*` — plus maintenant.** Le raisonnement d'alors se tenait : une
> expression régulière **vide** ne correspond à aucun hôte, l'application aurait rendu
> `400` sur toutes les requêtes, et entre un garde-fou inactif et une panne totale on
> avait choisi le premier. Le choix était faussé parce qu'il n'avait que deux termes. Le
> troisième est un repli **non vide et permissif pour le développement seul** : rien ne
> tombe en local, et un `Host` étranger est refusé dès la première requête — donc le
> garde-fou est **mesurable**, ce que `.*` ne serait jamais.

Les quatre entrées, et pourquoi chacune y est : `localhost` (le navigateur, le proxy Vite
et la sonde de l'image), `127.0.0.1` (le front publié par compose et les appels directs à
l'API), `[::1]` (la même chose en IPv6 — `getHost()` conserve les crochets), `api` (le nom
de service compose, pour un appel depuis le réseau interne). Le port n'a pas à être listé :
`Request::getHost()` le retire avant de comparer ; le `(:\d+)?` est une ceinture, pas une
nécessité.

**En production, ce repli ne doit jamais servir** — il refuserait le domaine réel, donc
toutes les requêtes. C'est voulu, et c'est pourquoi le service `garde-secrets` de
`compose.prod.yaml` exige `HERITAGE_HOTES_AUTORISES` au même titre qu'`APP_SECRET` et
refuse de démarrer la pile sans elle : le défaut est **intercepté avant le premier
démarrage**, pas découvert par un `400`.

Et le troisième verrou n'est pas ici : **le proxy amont doit refuser un `Host` inconnu
plutôt que de le relayer** (`server_name` explicite et un `server` par défaut qui rend
`444`). Il appartient à la configuration nginx, hors de ce module.

---

## 9. Les coutures pour les autres modules

| Contrat | Qui implémente | Comment c'est branché |
|---|---|---|
| `Core\Security\CercleDeVisibiliteInterface` | `Membership` | alias dans `config/services/membership.yaml` ; défaut fermé `CercleFerme` |
| `Core\Contract\CalqueEnAttenteInterface` | `Proposal` | alias dans `config/services/proposal.yaml` ; défaut fermé `CalqueFerme`. Consommé par `Tree`, `Media`, `Timeline` |
| `Core\Service\CleDeRecherche` | — | **une seule** définition du repliement d'accents, appelée par `Tree\Service\NormalisateurNom` qui écrit `search_key` et par la recherche qui la lit |
| `Core\Enum\SubjectKind` | — | montée de `Proposal` : elle est nommée dans la signature du calque, et `Core` ne peut pas référencer un module |
| `Core\Contract\AnnuaireDesMembresInterface` | `Membership` | idem ; défaut fermé `AnnuaireVide` — libellé d'un membre, charge `membre` d'un compte |
| `Core\Contract\SessionPayloadContributorInterface` | `Membership` | tag `core.contributeur_session`, collecté par `#[AutowireIterator]` |
| `Core\Service\NotificationService` | — | appelé par l'adaptateur de `Proposal`, qui vit **dans `Proposal`** (voir § 8 bis) |
| `Core\Service\BasculeVoirComme` | — | `memberEmprunte()` pour l'`ETag` de `Tree` ; `refuserSiActive()` en tête de chaque écriture |
| `Core\Exception\*` | tous | on lève, on ne compose jamais d'enveloppe |
| `Core\Service\AuditService::enregistrer()` | tous | pour les **décisions** ; le diff de ligne est automatique |
| `Core\Security\RegimeVoter::{MEMBRE, ATTENTE}` | tous | `#[IsGranted(RegimeVoter::MEMBRE)]` sur une écriture |

`Core` ne référence aucun autre module. Toute la configuration du module est portée par des
**attributs**, au plus près des classes : `#[AsDoctrineListener]`, `#[AsEventListener]`,
`#[AutoconfigureTag]`, `#[AutowireIterator]`, `#[Autowire('%kernel.debug%')]`. Seul l'alias du
cercle est en YAML, parce qu'il doit pouvoir être remplacé par un autre module.

---

## 10. Amorçage

### Le compte du commanditaire, reposé depuis l'environnement

```
bin/console heritage:amorcer-comptes                            # le compte, SANS purger
bin/console doctrine:fixtures:load --append --group=amorcage    # + le rattachement à l'ego
```

> **⚠ `doctrine:fixtures:load` sans `--append` purge la base.** C'est le piège central :
> la fixture d'amorçage existe pour *reposer* un compte sur une base garnie, et la jouer nue
> détruirait l'arbre entier — qui ne revient plus que par la restauration d'une sauvegarde,
> l'import GEDCOM ayant été supprimé (ADR 0018). La commande fait le même travail sans rien
> purger ; les deux appellent le **même** service, donc elles ne peuvent pas diverger.

Variables, toutes dans **`api/.env.local`** (ignoré par Git ; `api/.env` est *suivi* et ne
porte que des valeurs vides) : `HERITAGE_ADMIN_EMAIL`, `HERITAGE_ADMIN_PASSWORD`,
`HERITAGE_ADMIN_NOM`, `HERITAGE_ADMIN_PERSONNE_XREF`.

Trois règles, chacune payée par un incident :

1. **Idempotent** — rejouable sans effet de bord, sinon on n'ose pas le rejouer, et une
   commande qu'on n'ose pas lancer ne répare rien.
2. **Le mot de passe existant n'est jamais réécrit.** Rôle et statut, eux, sont réalignés :
   un administrateur suspendu ou rétrogradé par erreur ne peut plus rien réparer, et le
   `409 dernier_administrateur` n'a d'autre porte de secours que la console.
3. **Sans les variables, il ne crée rien et le dit.** Un administrateur par convention est
   un administrateur que personne n'a décidé ; un mot de passe par convention est public.

> **Le rattachement se fait par `gedcom_xref`, jamais par `tree_person.id`.** Poser
> l'identifiant numérique produisait, au premier réimport, un rattachement silencieusement
> dirigé vers **quelqu'un d'autre** — pire que de l'avoir perdu.

L'import GEDCOM est supprimé (ADR 0018), mais la règle reste : `gedcom_xref` est
l'identifiant stable d'une fiche, `tree_person.id` une identité qu'un repeuplement
renumérote. Le tableau ci-dessous garde la mesure qui l'a fondée, du temps de l'import.
Trois gestes de réimport, trois conséquences, et le troisième est le plus méchant :

| geste | identifiants | effet sur `mbr_member.person_id` |
|---|---|---|
| ré-import (mise à jour par `xref`) | **stables** | intact |
| `DROP SCHEMA` + migrate + import | repartent de 1 | même fichier ⇒ intact ; **fichier différent ⇒ désigne quelqu'un d'autre** |
| **`TRUNCATE` + import** | **continuent la séquence** | **cassé immédiatement, même avec le même fichier** |

`TRUNCATE` ne remet pas la séquence à zéro : les 274 personnes réinsérées reçoivent des
identifiants neufs pendant que les rattachements pointent sur les anciens. Il **casse
toujours**, indépendamment du fichier — et c'est le geste naturel de qui veut « juste
rafraîchir l'arbre ». `gedcom_xref` protège des trois cas.

La réparation, quand elle a eu lieu, s'est faite **par le libellé dénormalisé** — qui
survit à une réattribution d'identifiants là où l'entier ne survit pas. C'est l'usage exact
pour lequel il existe (ADR 0002).

Ce rattachement actif est aujourd'hui la donnée la plus utile de la base : sans un seul,
ni la bascule « voir comme un membre », ni le cercle d'un membre réel, ni le
`409 vue_comme_membre_active` ne sont vérifiables — les dispositifs qui contrôlent la
confidentialité restent eux-mêmes incontrôlables.

`src/DataFixtures/AmorcageFixtures.php` référence `Core`, `Tree` et `Membership`. Aucun
module n'en a le droit ; c'est pourquoi le travail est là : **`src/DataFixtures/` n'est pas
un module, c'est une racine de composition**, au même titre que `config/`.

### Le premier administrateur d'une base neuve

```
php bin/console heritage:creer-administrateur <courriel> "<nom affiché>"
```

Sans elle, une base neuve n'a aucun administrateur pour émettre la première invitation.
C'est aussi la porte de secours du `409 dernier_administrateur` (§ 10.1) : se verrouiller
dehors n'a pas d'autre issue. Le mot de passe est demandé de façon masquée — il n'apparaît ni
dans l'historique du terminal, ni dans la liste des processus. **La création en console n'est
pas journalisée** : la voie mécanique ne journalise pas hors requête HTTP.

---

## 11. Tests

`api/tests/Core/` — 49 cas. Ils vérifient ce qui se passe, jamais l'existence d'une route.

| Fichier | Ce qu'il prouve |
|---|---|
| `AuthentificationTest` | connexion réussie et sa charge ; cookie `HttpOnly` + `SameSite=Lax` ; échec **indiscernable** d'un compte inexistant ; les six codes exacts `[401,401,401,401,401,429]` avec `Retry-After` ; le blocage ne déborde pas sur un autre compte ; `401` JSON sans `Location` sur trois routes anonymes ; compte suspendu ; **le statut suspendu n'est pas un oracle** ; session fermée à chaud ; corps illisible en `422` |
| `JournalAuditTest` | connexion et tentative échouée tracées ; diff avec `password` à `"***"` et le nouveau mot de passe **absent de la base** ; forme `avant`/`apres` ; décision `reglage_modifie` distincte ; journal refusé à un membre ; filtre par action ; **aucune route n'écrit dans le journal** ; le souscripteur lève sur une modification ; une connexion ne laisse pas de `modification` technique |
| `CercleFermeTest` | le défaut refuse toute personne ; décès inconnu masqué ; avertissement émis **une seule fois** ; le conteneur rend bien l'implémentation fermée ; aucune profondeur dans le contrat ; gardes de signature |
| `RegimeEnAttenteTest` | le régime n'est pas un `N = 0` ; un contributeur bavard **ne peut pas** ouvrir le régime en attente ; le défaut du socle est fermé ; `displayName` retombe sur le courriel |
| `ReglagesTest` | valeur castée ; `N` changé sans redéploiement ; hors bornes avec les bornes ; casts refusés ; clé inconnue en `404` ; un membre ne touche à rien ; le repli de lecture n'ouvre pas le cercle |
| `EnTetesDeSecuriteTest` | en-têtes posés y compris sur les erreurs ; pas de mise en cache ; aucun en-tête CORS ; `404` et `405` en JSON |
| `NotificationsTest` | **un refus motivé parvient à son auteur avec son message entier** ; une notification n'appartient qu'à son destinataire (`404`, pas `403`) ; compteur et marques de lecture ; un compte en attente lit les siennes ; les administrateurs se notifient sans que l'appelant sache qui ils sont ; **`notifier()` ne lève pas quand le dépôt échoue** |
| `AdministrationDesComptesTest` | liste réservée et sans `password` ni `totpSecret` ; `role_modifie` et `compte_suspendu` journalisés comme décisions ; suspension effective immédiatement ; **`409 dernier_administrateur`**, levé par un second administrateur ; `en_attente` et rôles inventés refusés |
| `VoirCommeTest` | membre inconnu refusé ; réservée à l'administrateur ; `voirComme` à `null` hors bascule ; **activation journalisée une seule fois** ; `memberEmprunte()` exposé pour l'`ETag` ; **`409 vue_comme_membre_active`** sur écriture |

### Deux recettes d'exécution, apprises à la dure

**Une base et un pool de cache par processus.** `vendor/bin/phpunit` sans argument exécute
toute la suite ; quand deux agents la lancent en même temps, les deux processus reconstruisent
le même fichier SQLite et le même pool. Symptômes trompeurs : « table already exists » ici,
« no such table » là, un test de limiteur qui passe une fois sur deux, et un ordre de tests qui
semble compter. C'est désormais réglé globalement dans `api/tests/bootstrap.php`.

**`SchemaTool::dropSchema()` avale ses exceptions.** Une suppression qui heurte une clé
étrangère laisse des tables debout sans rien signaler, et le `createSchema()` suivant échoue à
mi-chemin. `CoreWebTestCase` vide donc la base avec `PRAGMA foreign_keys = OFF` puis
`DROP TABLE IF EXISTS`, jamais `dropSchema()`.

**Une IP client tirée au hasard par test.** Les compteurs du limiteur sont indexés par
identifiant + IP ; une IP propre à chaque test lui donne sa propre fenêtre quoi qu'il arrive au
pool partagé.

### Commandes

```
cd C:/www/heritage/api
php bin/console debug:router | grep -i -E "connexion|session|journal|reglages"
php bin/console debug:firewall main
vendor/bin/phpstan analyse --memory-limit=1G     # [OK] No errors
vendor/bin/phpunit tests/Core                    # OK (49 tests, 290 assertions)
vendor/bin/php-cs-fixer check --config=.php-cs-fixer.dist.php src/Module/Core tests/Core
```

`phpstan` réclame `--memory-limit=1G` quand la machine porte plusieurs suites en parallèle ; au
calme, la limite par défaut suffit. `phpstan.neon` est repassé à
`reportUnmatchedIgnoredErrors: true`, l'entrée `property.onlyWritten` retirée — confirmé sans
objet — et `property.unusedType` conservée.

---

## 12. Ce qui n'est pas fait, et par qui

Les trois routes `Core` qui n'avaient pas de propriétaire — `/api/notifications`,
`/api/administration/utilisateurs`, `/api/administration/voir-comme` — **sont posées**
(§ 8 bis). Restent :

**À brancher par d'autres modules**, et ce sont eux qui savent où :

| À faire | Qui | Pourquoi ça compte |
|---|---|---|
| `memberEmprunte()` dans l'`ETag` du graphe | `Tree` | sinon la bascule rend `304` et ne vérifie plus rien, **en silence** |
| `refuserSiActive()` en tête des écritures | `Proposal`, `Media`, `Membership` | `409 vue_comme_membre_active` (§ 10.4, règle 2) |

L'adaptateur `NotificateurDeDemandes` → `NotificationService` est **branché**
(`Proposal\Service\Port\NotificateurParNotifications`) : le motif d'un refus part tel quel
dans le corps de la notification de son auteur.

### Un fichier reconstruit, à relire par son auteur

`api/src/Module/Gedcom/Service/NormalisateurNom.php` a été **vidé par erreur** pendant la
remontée du repliement — un `preg_replace` en ligne de commande dont le motif a échoué,
rendu `null`, écrit tel quel. Le fichier n'était pas suivi par Git : pas de récupération.

Il a été **reconstruit** à partir de son contenu lu quelques minutes plus tôt —
`decouper()`, `composerAffichage()`, `cleDeRecherche()`, en-têtes compris — et ses 15 tests
d'origine passent, ce qui est la meilleure preuve disponible de la fidélité de la
reconstruction. Ce n'en reste pas moins une reconstruction : **son auteur devrait la
relire**. Le seul changement voulu est la délégation du repliement à `Core`.

*(Le service a depuis été déplacé de `Gedcom` vers `Tree` par un autre agent, et le module
`Gedcom` a été supprimé avec l'import — ADR 0018.)*

**MFA TOTP administrateur** : les colonnes `totp_secret` et `totp_enabled_at` existent sur
`Core\User` et la charge de session expose `totpEnabledAt`. Le second facteur lui-même n'est pas
implémenté — « souhaitable, pas bloquant pour la première livraison » (§ 8 du dossier), à
inscrire en feuille de route.

**Autres sessions du même compte** non invalidées au changement de mot de passe : Symfony ne le
fait pas par défaut et il n'existe pas de table de session (§ 2.1). À noter en feuille de route,
pas à improviser.

### Corrigé depuis

`Core\Enum\UserStatus` affirmait qu'un compte `EnAttente` « n'a qu'un cercle de visibilité
vide » et que « c'est le cercle qui est vide » — exactement la formulation que l'ADR 0003, le
§ 12 du contrat et `docs/arbitrages.md` § 3 interdisent. Le code faisait la bonne chose ;
c'est le commentaire qui disait le contraire, et un commentaire faux à cet endroit est ce qui
fait commettre l'erreur au prochain remaniement — c'est même la fonction d'un commentaire que
de dispenser d'aller voir la source. Réécrit avec son motif.

### Un fil-piège qui a servi

`CercleFermeTest` assertait que le conteneur rend `CercleFerme`. Il est tombé le jour où
`Membership` a branché son adaptateur — **c'était le but**. Le ré-armer sur l'implémentation
réelle appartient à `Membership`, qui seul sait ce que la sienne doit garantir ; ce qui reste
dans `Core` est ce qu'il peut garantir pour toujours : le contrat est lié et injectable, et le
défaut fermé est prouvé contre `CercleFerme` directement, sans dépendre d'aucun câblage.
