# ADR 0013 — L'inscription sans fiche, et son rattachement par un administrateur

Date : 2026-10-01 · Statut : acceptée

## Contexte

L'administrateur partage un **lien d'invitation illimité** dans le groupe WhatsApp de la
famille (liens multi-usages, `Version20260929161700`). Le parcours d'inscription du § 4 du
contrat demande au candidat son nom, ses prénoms et sa date de naissance, cherche sa fiche
(au plus trois candidats, en affichage minimal) et rattache le compte à celle qu'il choisit.

Il ne prévoyait pas le cas le plus fréquent d'un groupe familial : **le cousin que l'arbre ne
connaît pas exactement** — un prénom d'usage au lieu du prénom d'état civil, une date
approximative, ou pas de fiche du tout. `mbr_member.person_id` était obligatoire : sans
candidat, pas de compte. Le lien partagé butait sur la moitié de ceux qui l'ouvraient.

Deux demandes accompagnaient celle-ci : ne plus proposer de **personnes décédées** comme
candidats — « ça ne sert à rien » —, et demander la **photo de profil** dès l'inscription.

## Décision

### 1. Un compte peut naître sans fiche

« Je ne me trouve pas » (ou « Aucune de ces personnes ») ouvre un champ libre, **« Décrivez
votre famille »** — 60 à 4 000 caractères —, repris avec le nom, les prénoms et la date déjà
saisis. Le compte est créé :

- `core_user` `en_attente` ;
- `mbr_member` avec **`person_id` nul** et une nouvelle nature, **`match_kind = a_rattacher`** ;
- régime `aucun` (ADR 0003) : **pas de cercle**, il voit les défunts, n'écrit rien.

Pas de nouvelle table : la déclaration (`declared_surname`, `declared_given_names`,
`declared_birth_date`, `family_description`) et la suggestion de l'assistant
(`attachment_suggestion`, `attachment_suggestion_at`) sont des colonnes nullables de
`mbr_member`. Quatre `CHECK` tiennent l'ensemble :

- pas de fiche **si et seulement si** `a_rattacher` ;
- une fiche et son libellé vont ensemble ;
- un compte sans fiche **n'est jamais actif** — il n'y aurait pas d'ancre de cercle ;
- la déclaration n'existe que sur un compte `a_rattacher`.

Un limiteur par IP — **5 comptes sans fiche par heure** — borne la création en série. Il rend
un vrai `429` : il ne dit rien d'une personne de l'arbre.

### 2. L'administrateur rattache, crée ou refuse — jamais l'automate

Un onglet « À rattacher » liste ces comptes avec leur déclaration et leur photo. Trois gestes :

- **Rattacher** à une fiche existante (`POST .../membres/{id}/activer` avec `personId`,
  désormais obligatoire pour ce cas) : la fiche doit exister, être **vivante**, et n'être
  réclamée par personne ;
- **Créer la fiche et rattacher** (module `Assistant`) : une demande `creation_personne` est
  déposée **et validée** au nom de l'administrateur, puis le compte est rattaché et activé,
  **dans une seule transaction** — l'écriture dans l'arbre passe donc par la file de l'ADR
  0004 et laisse ses révisions ;
- **Refuser** (`POST .../membres/{id}/refuser`) : le compte est **suspendu** (§ 3 ci-dessous).

Une **suggestion** calculée par le modèle de langage (ADR 0008) aide à choisir : « c'est
probablement la fiche X », ou « absente de l'arbre » avec une demande de création
pré-remplie. Elle est paresseuse, mise en cache sur le compte, rejouable, et **jamais
appliquée d'elle-même**. Sans modèle configuré, la carte reste utilisable à la main.

### 3. Refuser, c'est suspendre et purger — pas supprimer

`Core` n'a aucun chemin de suppression de compte, et `core_user` est référencé par le journal
d'audit, les notifications et les sessions. Supprimer ouvrirait un chantier dans un module qui
n'est pas celui-ci. La **suspension** existe et fait ce qu'il faut : `UserChecker` refuse la
connexion (`401 compte_suspendu`), la décision est journalisée (`compte_suspendu`). Les
**données personnelles** partent quand même : déclaration, suggestion et photo en attente sont
purgées. Contrepartie assumée : **le courriel reste réservé**.

### 4. Les données de vivants ne vivent que le temps de la décision

La description parle de personnes vivantes, en clair. Elle est :

- lisible des **seuls administrateurs**, et refusée même à eux sous la bascule « voir comme un
  membre » — c'est la seule **lecture** refusée sous la bascule, parce qu'aucun cercle ne sait
  filtrer un texte libre ;
- **purgée** au rattachement comme au refus ;
- **absente** du journal d'audit (`AuditDoctrineSubscriber::CHAMPS_SENSIBLES` : sans cela, la
  purge elle-même écrirait l'ancienne valeur dans le diff `avant`/`apres`), des notifications
  (elles nomment le courriel), de la charge de session et du stockage du navigateur ;
- transmise au modèle de langage **sans le courriel** (addendum de l'ADR 0008).

`mbr_claim_attempt` reste haché : la recherche de l'étape 1b ne stocke toujours rien en clair.

### 5. La photo dès l'inscription

Juste après la création du compte, « Votre photo » (un lien discret « Passer cette étape »).
La même route qu'après l'activation — `POST /api/medias/portrait-du-compte` — dépose, pour un
compte sans fiche validée, une photo **sur le compte** : `med_media.subject_user_id`, statut
`en_attente`, libellé anonyme. Mêmes contrôles que tout dépôt (type réel, purge EXIF,
vignette). Visible de son titulaire et des administrateurs seulement ; **au plus une** par
compte. Au rattachement, elle passe sur la fiche **et en devient le portrait** par le seul
chemin où un portrait bascule (`CircuitDeValidationMedia::valider()`) ; au refus, elle est
supprimée, fichier compris. Elle n'entre ni dans la file de validation des médias, ni dans une
demande `ajout_media`.

### 6. Les défunts ne sont plus proposés à l'inscription

La recherche de rattachement exclut désormais les personnes **non vivantes**, en mode recherche
comme en confirmation (un pré-lien vers un défunt rend `candidats: []` et consomme sa
tentative).

## Justification

### Pourquoi l'« oracle inverse » ne tient plus

Les défunts étaient cherchables au nom d'un argument écrit en tête de
`RechercheDeRattachement` : les exclure aurait fait dire à une réponse vide « cette personne
est morte » plutôt que « elle n'existe pas » — un oracle inverse. **Cet argument tombe avec
la décision 1.** Tout porteur du lien peut désormais créer un compte en attente sans rien
prouver, et un compte en attente **voit tous les défunts**, en clair : c'est la conséquence
mécanique d'un cercle vide, décidée par le commanditaire (arbitrages, § 3). L'état civil des
morts n'est plus un secret que le formulaire aurait à garder.

C'est une décision de produit, **assumée** : un lien qui fuite du groupe familial donne accès
à l'arbre ancien — ce qu'il donnait déjà à quiconque se reconnaissait dans un candidat. Le
limiteur par IP borne le volume ; l'administrateur voit chaque compte créé et le refuse d'un
geste.

### Pourquoi les quatre règles de l'inscription ne bougent pas

`404` uniforme, plafond par jeton invisible, recherche exécutée puis jetée, pré-lien sans
lecture : rien de cela ne change. En particulier, **« Je ne me trouve pas » s'affiche à
l'identique** quelle que soit la cause d'un résultat vide — plafond, date fausse, personne
absente, défunt. Un bouton qui n'apparaîtrait que sous le plafond rouvrirait exactement le
canal que le § 4.2 ferme.

### Pourquoi des colonnes et pas une table

Une table `mbr_declaration` aurait été plus « propre » ; elle aurait aussi porté les mêmes
données, sous les mêmes règles, avec une jointure de plus et une ligne orpheline possible. Les
colonnes nullables, verrouillées par `ck_mbr_member_declaration`, ne peuvent pas survivre à un
rattachement, et `SchemaSynchroniseTest` — qui compte les tables — ne bouge pas.

### Pourquoi `subject_user_id` et pas un brouillon

Un compte en attente n'a pas le droit de déposer une demande (ADR 0004, régime `aucun`) : la
photo ne peut pas être la pièce jointe d'un brouillon `#p1`. Elle n'appartient pas non plus
à une fiche, qu'il n'a pas. Le troisième sujet est le seul qui dise la vérité : **cette photo
attend son compte**.

## Alternatives considérées

- **Ouvrir la recherche** (critères relâchés, plus de candidats). Rejetée : c'est précisément
  ce que le § 4.2 interdit, et cela n'aurait rien fait pour le cousin absent de l'arbre.
- **Créer la fiche à l'inscription**, depuis la déclaration. Rejetée : une écriture dans
  l'arbre sans validation (ADR 0004), par un porteur de lien anonyme.
- **Rattachement automatique** par le modèle de langage quand il est « sûr ». Rejetée : une
  erreur ancrerait un compte sur la fiche d'un vivant qu'il n'est pas, et lui ouvrirait le
  cercle de cette personne. La suggestion aide ; l'administrateur décide.
- **Supprimer** le compte refusé. Rejetée pour ce livrable (§ 3 de la décision) : le chantier
  appartient à `Core`.

## Conséquences

- `MatchKind` compte quatre valeurs ; `Member::getPersonId()` et `getPersonLabel()` sont
  nullables. Tous les lecteurs de `person_id` ont été relus : la fabrique du lecteur du cercle
  (chemin normal et bascule) ferme sur une ancre nulle, la charge de session ne lit la
  personne que d'un membre actif, `AnnuaireDesMembres::libelleDuMembre()` rend `null` — et
  « voir comme » un compte sans fiche rend donc `404`, ce qui est juste : il n'a pas de cercle
  à emprunter. `personneDuCompte()` (Media, Tirage, Tree, Assistant) ne rend une personne que
  d'un compte actif : rien n'y change.
- Deux contrats inter-modules : `Membership\Contract\ComptesARattacher` (consommé par
  `Assistant`) et `Membership\Contract\PortraitsDesComptes` (implémenté par `Media`).
  `Assistant\Contract\CreationDeFichePourLeRattachement` est implémenté par `Proposal`.
- L'activation rejoue un contrôle de plus pour **toute** fiche désignée : elle doit être
  vivante.
- `tests/Frontieres` tolère que `ComptesARattacher` transporte `Core\Entity\User`, au même
  titre que le contrat de session du noyau.
- Contrat d'API : § 4.2, § 4.3, § 4.4, § 7.1, § 10, § 10.1, § 12. Modèle de données : § 4.2,
  § 4.5, § 6.1.
