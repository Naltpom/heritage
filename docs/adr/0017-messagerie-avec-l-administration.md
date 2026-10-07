# ADR 0017 — La messagerie avec l'administration

Date : 2026-10-07 · Statut : acceptée

## Contexte

Une question sur l'application, une idée, un bogue : jusqu'ici, tout cela partait par SMS
ou de vive voix, et se perdait. L'administrateur ne savait plus qui avait signalé quoi ; le
membre ne savait pas si on l'avait lu, ni s'il devait relancer. Le commanditaire demande,
depuis l'accueil, un bouton « une question, une suggestion, un bug ? » qui ouvre une
conversation avec les administrateurs — **en créer une, ou poursuivre** celle déjà ouverte —
avec ce qu'on attend d'une messagerie : « envoyé », « vu à ».

## Décision

Un module **`Messagerie`** (préfixe `msg_`, deux tables), qui ne lit rien de l'arbre.

- **Un fil** (`msg_fil`) appartient au membre qui l'a ouvert. Il porte une **nature** —
  `question`, `suggestion`, `bug`, choisie d'un geste à l'ouverture et fixe ensuite, pour
  que l'administration trie sans tout relire —, un **sujet** (facultatif à la saisie : la
  première ligne du message en tient lieu) et un **statut** : `ouvert` ou `clos` (« Réglé »).
- **Lu par son membre et par les administrateurs**, par personne d'autre. Le fil d'un autre
  et un fil inexistant rendent `404`, administrateur compris côté « Mes messages ».
- **L'administration répond d'une seule voix** : n'importe quel administrateur répond, et ce
  qu'un administrateur a lu est lu pour tous. Chaque réponse est signée de son auteur.
- **L'accusé de lecture est par message** (`msg_message.vu_le`) : posé **une fois**, à la
  première ouverture du fil par **l'autre côté** — le membre pour une réponse, un
  administrateur pour un message du membre (qui est alors nommé, `vu_par_libelle`). Le
  premier regard compte ; les suivants ne déplacent pas l'heure. « Envoyé » est l'absence
  d'accusé. Une lecture sous la bascule « voir comme » n'en pose aucun : ce n'est pas le
  membre qui a lu.
- **Un fil réglé se rouvre** dès que le membre y écrit : la suite d'une conversation se dit
  dans la conversation, pas dans une nouvelle qui aurait perdu le début.
- **Ouvert à tout compte**, en attente compris (`ROLE_USER`) : c'est souvent quand
  l'inscription traîne qu'on a une question, et rien ici ne lit l'arbre — la règle de
  l'ADR 0003 (un compte en attente ne lit rien de l'arbre) n'est pas en jeu.
- **Notifications** (cloche et téléphone, par `Core\NotificationService`) :
  `message_d_un_membre` aux administrateurs, `message_de_l_administration` au membre.
  **Aucune ne porte le texte d'un message** — une notification est persistée et se relit
  hors du fil. Le membre lit le sujet qu'il a écrit ; les administrateurs, le nom du compte
  et la nature. **Une seule notification tant que la précédente n'est pas lue** : dix
  messages d'affilée ne font pas sonner dix fois le même téléphone.
- **Pas de temps réel** : ni WebSocket ni file (pas de Redis, § 3 du dossier). Une
  conversation ouverte se relit toutes les quinze secondes tant que la page est visible ;
  la cloche et le téléphone font le reste.

## Conséquences

- 47 tables (`SchemaSynchroniseTest`), 64 colonnes d'énumération
  (`LongueurDesEnumerationsTest`).
- `msg_*.membre_user_id` et `auteur_user_id` sont des identifiants nus vers `core_user`, avec
  leur libellé dénormalisé (ADR 0002, règle 2) : un compte supprimé laisse ses fils lisibles.
- Le front : une carte sur l'accueil (trois boutons — question, idée, bug — et
  « Poursuivre » avec le nombre de réponses non lues), une entrée dans « Moi », l'écran
  `/messages`, et l'onglet « Messages » de l'administration.
