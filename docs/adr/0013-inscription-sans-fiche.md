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

## Addendum du 2 octobre 2026 — dire sa famille au lieu de l'écrire

« Décrivez votre famille » s'ouvre d'emblée quand la recherche ne rend personne (la décision
ne lit que le nombre de candidats : elle est la même pour toutes les causes d'un résultat
vide), et une **consigne parlée** l'explique, une fois par onglet. Le candidat peut écrire,
**ou enregistrer un message vocal** (`MediaRecorder`), ou les deux.

- **Texte OU vocal OU les deux.** Un vocal valide rend la description facultative ; sans lui,
  60 caractères au moins, comme avant.
- **Dans la même requête**, en `multipart` — pas de dépôt préalable : aucun fichier orphelin
  d'un parcours abandonné, aucune route de dépôt anonyme de plus. Jeton contrôlé d'abord,
  limiteur `inscription_sans_fiche`, 10 Mio, type lu sur les octets (WebM, Ogg, MP4).
- **Des colonnes, pas un média.** Le fichier n'appartient à aucune fiche et n'entre dans
  aucun circuit de validation : il vit sous `messages-vocaux/` du volume des téléversements,
  hors racine web, et ses quatre colonnes `voice_message_*` suivent les règles de la
  déclaration — purgées, **fichier compris**, au rattachement comme au refus.
- **Écouté par l'administrateur seul**, par une route `ROLE_ADMIN` refusée sous la bascule. Le
  modèle de langage n'en reçoit **pas** le son, seulement le fait qu'il existe (une
  transcription automatique serait une nouvelle sortie de données de vivants vers un tiers :
  elle demanderait sa propre décision). — *Cette décision a été prise le soir même : voir
  l'addendum suivant.*

## Addendum du 2 octobre 2026, soir — le message vocal est transcrit

Le propriétaire a tranché : **le vocal doit servir à l'analyse**, comme la description
écrite. Il est transcrit par **Gemini** — le fournisseur de l'analyse, pas un tiers de plus —,
et la transcription nourrit l'agent de rattachement pour proposer la fiche, les proches et la
demande de création, exactement comme le texte.

- **Après coup, à la demande de l'écran.** Comme l'analyse : rien n'est appelé à
  l'inscription. La carte « À rattacher » d'un vocal non transcrit fait d'abord
  `POST …/{memberId}/transcription` (module `Assistant`, `TranscriptionDesMessagesVocaux`),
  puis demande sa suggestion, **dans la même file** — une carte à la fois. Pas de file de
  messages : le projet n'en a pas, et une transcription d'un appel ne justifie pas d'en
  introduire une.
- **Pas de conversion.** La documentation de Gemini (« Audio understanding », relue ce jour)
  admet `audio/webm`, `audio/ogg`, `audio/m4a`… : les trois formats de `MediaRecorder` partent
  tels quels, le MP4 de Safari sous le nom `audio/m4a`. **Pas de `ffmpeg`** dans l'image de
  l'API. Si un format venait à être refusé, l'échec se lirait sur la carte (« Le fournisseur a
  refusé cet enregistrement ») et la conversion serait à reprendre ici.
- **En ligne, pas par la File API** : 10 Mio au plus, 13,4 une fois en base64, sous la limite
  de 20 Mo d'une requête — et la File API garderait le fichier 48 heures chez le fournisseur.
- **Un modèle léger** (`HERITAGE_GEMINI_MODELE_TRANSCRIPTION`, défaut `gemini-2.5-flash`) :
  recopier n'est pas raisonner. Consigne de **verbatim** en français, noms et dates tels
  qu'entendus, `[?]` sur un nom incertain ; température nulle ; 60 s au plus.
- **L'échec ne bloque rien.** Délai, quota, refus, réponse vide : le motif est **rangé sur le
  compte**, la carte dit « échec — Réessayer », l'analyse se fait sans la transcription, et
  l'audio reste écoutable. La route rend `200` dans les deux cas : l'état est la ressource.
- **Trois colonnes de plus**, sur la même ligne (`Version20261002220000`) : le texte, le motif
  du dernier échec, la date de la dernière tentative. Elles suivent les règles du vocal —
  rien sans lui (`ck_mbr_member_transcription_avec_vocal`), purgées avec lui au rattachement
  comme au refus — et le texte est une **colonne sensible** du journal mécanique.
- **L'analyse la lit, étiquetée.** Le premier message de l'agent cite la transcription comme
  « transcription automatique d'un message vocal », en précisant que l'orthographe des noms
  peut différer de l'arbre. La suggestion gardée note `transcriptionPriseEnCompte` ; une
  suggestion calculée **avant** la transcription n'est plus servie du cache : la lecture
  suivante la recalcule. C'est la relance automatique, sans bouton.
- **La personne est prévenue**, sous le micro, avant d'enregistrer : « Votre message est
  écouté par l'administrateur et transcrit automatiquement (Google Gemini) pour retrouver
  votre fiche. » C'est la même base que pour la description (ADR 0008, addendum du 1er
  octobre) : une information préalable, sur un envoi facultatif — écrire reste possible.
- **Même quota** que l'analyse (`assistant.rattachements`, global) : une transcription est un
  appel payant au même fournisseur, pour la même décision. Une carte avec vocal coûte donc
  deux coups.

## Addendum du 3 octobre 2026 — l'IA à la demande, la transcription corrigée à la main

Le propriétaire, à l'usage : « si transcription, est-ce que je peux modifier le texte moi
avant ? et ne pas lancer la recherche IA directement au chargement de la page, me mettre le
bouton lancer la recherche moi-même ». Deux décisions, qui remplacent les puces « Après
coup, à la demande de l'écran » et « L'analyse la lit, étiquetée » ci-dessus là où elles
disaient « automatiquement » :

- **Rien ne part à l'ouverture de l'écran.** L'onglet « À rattacher » ne fait plus que
  **lire** : la liste, le quota global (`GET /api/administration/quotas`, pour afficher ce
  qu'il en reste à côté du bouton — rien si la lecture échoue : on n'invente pas un
  compteur), et la suggestion **déjà calculée** des seules cartes qui en ont une.
  `GET …/{memberId}/suggestion` devient une **lecture pure** : `200` avec la suggestion
  gardée, ou `204` s'il n'y en a pas — jamais un calcul, jamais de quota, pas de clé exigée.
  Chaque appel au modèle part d'un clic : « Transcrire le message vocal » (`POST
  …/transcription`), « Lancer la recherche » / « Relancer la recherche » (`POST
  …/suggestion`, toujours une à la fois côté écran). Les gestes manuels — rattacher à une
  fiche, créer, refuser — ne dépendent d'aucun des deux.
- **La transcription se corrige avant la recherche.** Elle s'affiche dans un champ
  modifiable ; « Enregistrer » fait `PATCH …/{memberId}/transcription` `{"texte": "…"}`
  (administrateurs seulement, refusé sous la bascule, `422` vide ou au-delà de 16 000
  caractères, `404` sans vocal ou compte décidé). Ni modèle, ni quota. Une colonne de plus,
  `voice_message_transcript_edited_at` (`Version20261003080000`) : la date de la dernière
  correction, `null` tant que le texte est celui du modèle — une retranscription la remet à
  `null` ; pas de date sans texte (`ck_mbr_member_transcription_corrigee`) ; purgée avec le
  vocal. Quand le modèle a échoué ou qu'il n'y a pas de clé, l'administrateur peut aussi
  **écrire** la transcription lui-même après écoute (même route). Le journal mécanique
  garde **qui** et **quand** (`voiceMessageTranscriptEditedAt`), jamais le texte
  (`voiceMessageTranscript` reste un champ sensible).
- **La recherche sait que le texte a été relu.** Le premier message de l'agent étiquette une
  transcription corrigée « relue et corrigée par l'administrateur après écoute (les noms
  propres et les dates y ont été vérifiés) » au lieu de « tels qu'entendus ». L'audit de
  l'appel note `transcription_corrigee`.
- **Une suggestion devient « périmée » au lieu d'être recalculée en douce.** La suggestion
  gardée porte l'**empreinte** (SHA-256, jamais le texte) de la transcription qu'elle a lue.
  Si la transcription a changé depuis — arrivée, retranscrite, corrigée —, la suggestion
  reste montrée mais `perimee: true`, et l'écran dit « La transcription a changé depuis cette
  recherche : relancez la recherche ». Revenir exactement au texte lu la rend de nouveau
  valable. Les suggestions gardées avant ce jour, sans empreinte, retombent sur l'ancienne
  règle (transcription présente et non lue, ou corrigée après le calcul).
- **La description écrite n'est pas modifiable.** C'est la parole du candidat ; seule la
  transcription automatique — le travail d'une machine — se corrige.

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
