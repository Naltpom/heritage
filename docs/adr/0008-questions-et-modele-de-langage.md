# ADR 0008 — La page « Questions » et l'appel à un modèle de langage

Date : 2026-09-23 · Statut : acceptée

## Contexte

L'application veut que la famille **contribue** (ADR 0004, § Contexte : « sinon un PDF
suffirait »). Le circuit existe — une demande, un diff, une validation — mais il suppose
qu'un membre sache **quoi** proposer. Or personne n'ouvre une fiche de 1832 pour y chercher
un trou : on contribue quand on nous demande quelque chose qu'on sait.

D'où la page « Questions » : pour chaque compte, l'application cherche dans l'arbre les
trous que **cette personne-là** pourrait combler — sa grand-mère, sa tante, son cousin — et
lui pose la question. Sous chaque question : « Je ne sais pas », « Je vais me renseigner »,
un champ de texte, un dépôt de photo. Un modèle de langage (Gemini) **formule** ces
questions.

Trois faits cadrent la décision, et aucun n'est une opinion.

**1. L'API ne fait aujourd'hui aucun appel sortant.** Mesuré avant d'écrire cette ligne :

```
grep -rn "HttpClientInterface" api/src   →  aucune occurrence
```

Le mailer part par `MAILER_DSN`, et c'est tout. Gemini serait le **premier service externe
que cette application appelle**. Il n'existe donc aucun motif maison à recopier pour les
délais d'attente, les reprises, les pannes et les quotas : ils s'établissent ici.

**2. L'ADR 0003 nomme le risque principal du produit**, et c'est celui-ci :

> *« Le principal risque du produit n'est pas qu'on lise une fiche de 1791, c'est qu'un
> compte serve d'oracle pour énumérer et documenter les vivants. »*

Il l'encadre par une phrase qui, prise à la lettre, interdit ce qu'on s'apprête à faire :
*« le graphe montre, il n'énumère pas. Pas de liste, pas d'export, pas de recherche sur les
vivants hors cercle. »* Envoyer l'arbre entier à un tiers **est** une énumération, et c'est
**un export**.

**3. Le commanditaire a tranché en connaissance de cette réserve.** Gemini reçoit l'arbre
entier, personnes vivantes comprises. L'arbitrage n'est pas rouvert ici. Mais une décision
qui contredit une décision acceptée doit être **visible dans le dépôt**, sinon la
contradiction se découvre dans deux ans en lisant le code, et l'ADR 0003 passe pour avoir
été enfreint en silence. C'est l'objet de ce document.

## Décision

### 1. Le corpus part entier, et c'est le seul point qui contredit l'ADR 0003

Le corpus transmis au fournisseur du modèle contient **toutes les personnes de l'arbre, y
compris les vivantes**, avec leurs noms, leurs dates, leurs lieux et leurs liens.

Il n'est **pas** filtré par le cercle du destinataire de la question. La raison est celle
qui justifie l'appel : une question utile situe la personne pour celui qui la lit — *« Savez-
vous où est née Alberte, la sœur de votre grand-mère ? »* — et ce « la sœur de votre
grand-mère » se calcule sur la parenté, pas sur une fiche isolée.

Deux bornes, qui ne sont pas des atténuations mais des règles :

- **La décision autorise l'arbre entier ; elle n'oblige pas à l'envoyer en entier à chaque
  appel.** L'implémentation envoie ce que la formulation exige. Ce qui est tranché, c'est
  qu'une personne vivante n'est pas *exclue par principe* du corpus.
- **Les réponses des membres ne partent jamais.** Ni le texte libre, ni la photo, ni le fait
  qu'un membre ait répondu « je ne sais pas ». Le flux est à sens unique : l'arbre sort pour
  qu'une question soit écrite ; ce qui revient de la famille entre dans la file de
  validation et n'en ressort pas. C'est une frontière, pas une politique de confort : une
  réponse contient des propos sur des vivants que l'arbre ne contient pas.

### 2. Ce que cette décision amende dans l'ADR 0003, exactement

**Une seule phrase**, dans la section « Le risque résiduel : la topologie reste visible » :

> *« ici : **le graphe montre, il n'énumère pas.** Pas de liste, pas d'export, pas de
> recherche sur les vivants hors cercle. »*

Elle est amendée ainsi : **elle reste vraie pour tout compte**, membre ou administrateur, et
pour toute porte de sortie de l'API. Elle cesse d'être vraie pour **un tiers unique, nommé,
sous contrat** — le fournisseur du modèle —, qui reçoit l'énumération complète des vivants
de l'arbre.

Et le modèle de menace de l'ADR 0003 change avec elle. Il disait : *« un cousin curieux,
pas un inconnu qui aspire l'arbre »*. Il faut y ajouter une ligne : **un prestataire qui
reçoit l'arbre en entier, à qui l'on fait confiance par contrat et non par construction.**
C'est un changement de nature, et c'est pour cela qu'il a fallu un ADR et pas un commentaire.

**Ce qui n'est PAS amendé**, et qu'on ne relâche nulle part :

| Règle de l'ADR 0003 | État |
|---|---|
| `C(u) = A(u) ∪ D(u) ∪ conjoints`, conjoints en **une passe** | intacte |
| La liste blanche des 7 champs hors cercle | intacte |
| Le filtrage dans la sérialisation, jamais dans le contrôleur | intacte |
| Le normalizer n'a pas de mode « sans filtre » | intacte |
| Un compte en attente n'a **pas de cercle** | intacte |
| Les colonnes `*_label` filtrées comme la personne | intacte, **et elle s'applique à `qst_question.sujet_label` et à `qst_question.texte`** |

> *Révision du 2026-10-01 de l'ADR 0003* : un membre validé voit désormais tous les
> vivants, et le cercle ne ferme plus, pour lui, que les fiches à confidentialité forcée.
> Le tableau reste vrai mot pour mot — c'est la population « hors cercle » qui a rétréci,
> pas une règle qu'on relâche ici : le second contrôle de la lecture (§ 3) s'applique
> désormais aux seules questions dont le sujet est une fiche confidentielle hors du cercle
> du lecteur.

Le point le plus important de ce tableau est le dernier, et il mérite d'être écrit en clair
parce qu'un lecteur pressé conclurait l'inverse de ce qui est décidé :

> **Le corpus qui part chez le tiers et le corpus qui revient au lecteur sont deux choses
> différentes.** Gemini voit tout l'arbre. Un membre ne voit **que les questions dont il a le
> droit de voir le sujet**, et cette vérification se fait à la sérialisation, sur le même
> `CercleDeVisibiliteInterface` que partout ailleurs. Relâcher le premier ne relâche pas le
> second.

### 3. Le cercle décide **à qui** on pose la question — et il le décide deux fois

Une question porte sur une personne. On ne demande à personne un renseignement sur une
personne vivante qu'elle n'a pas le droit de voir : ce serait, littéralement, utiliser la
page comme l'oracle que l'ADR 0003 ferme — « documenter les vivants », avec en prime
l'aveu de leur existence.

Le contrôle est posé **deux fois, et ce n'est pas une ceinture doublée d'une bretelle** :

1. **à la détection**, parce qu'une question qu'on ne créerait pas n'a pas à exister ;
2. **à la lecture**, parce qu'un cercle **rétrécit**. Le réglage `cercle.generations` est
   modifiable par l'administrateur (ADR 0003 : « un chiffre qu'on découvre faux »), et le
   faire passer de 4 à 3 retire des personnes de tous les cercles d'un coup. Les questions
   déjà en base, elles, ne bougent pas. Sans le second contrôle, la page servirait le
   lendemain des questions nommant des vivants devenus hors cercle — et aucun test de porte
   de sortie ne sonnerait, parce que cette porte-là n'existait pas quand ils ont été écrits.

Le second contrôle est celui qui compte. Le premier est une économie.

**Conséquence qu'il faut voir tout de suite, parce qu'elle décide de l'architecture : la
détection tourne dans la requête du lecteur, pas dans une tâche de fond.**

`Core\Security\CercleDeVisibiliteInterface` ne prend **aucun lecteur en paramètre** :
l'implémentation le résout elle-même, depuis la session, bascule « voir comme un membre »
comprise. C'est un choix de l'ADR 0003, et son motif y est écrit — *« un lecteur passé par
chaque appelant est un lecteur qu'un appelant finira par construire sans la bascule »*. Il
n'existe donc pas, et il ne doit pas exister, d'API « le cercle du compte n° 12 » qu'une
tâche nocturne pourrait appeler pour tous les comptes.

Un balayage de nuit aurait donc supposé une **seconde implémentation** de la formule du
cercle — exactement ce que l'ADR 0003 interdit d'une phrase : *« une règle de
confidentialité ne se trompe qu'une fois pour fuir »*. Le prix de la détection en ligne
est connu et petit : 274 fiches lues, une fois par ouverture de la page. Celui d'un second
cercle ne l'est pas.

Ce n'est pas une contrainte subie : c'est aussi ce qui rend la détection **idempotente
utile**. Rejouée à chaque chargement, elle reflète l'arbre d'aujourd'hui — une lacune
comblée ce matin ne se redemande pas ce soir, sans qu'aucune ligne n'ait eu à être
nettoyée.

### 4. La détection est à nous ; le modèle ne fait que **formuler**

C'est la décision structurante du chantier, et elle se lit en une ligne :

> **Le modèle n'ouvre aucune question et n'en ferme aucune. Il met en français une lacune
> que le code a trouvée.**

La chaîne est donc :

```
détection (règles, déterministe)  →  lacune  →  formulation (Gemini, ou repli)  →  question
```

Trois conséquences, et chacune est la raison d'être de ce découpage.

- **La page fonctionne sans Gemini.** Une lacune détectée a toujours une phrase : celle du
  gabarit de sa nature. Gemini coupé, en panne, hors quota ou jamais configuré, la page sert
  les mêmes questions, moins bien écrites. **Une page vide parce qu'un tiers ne répond pas
  est un défaut de conception, pas un incident.**
- **Rien de ce qu'un modèle invente n'entre dans l'arbre.** Une question mal formulée est
  une phrase maladroite ; une question *inventée* serait une lacune qui n'existe pas,
  c'est-à-dire un membre à qui l'on demande de corriger une fiche juste. Le code sait
  toujours quelle colonne est vide, et c'est ce fait-là qui fait la question.
- **La dépense est bornée et prévisible.** Le nombre d'appels est le nombre de lacunes
  nouvelles, pas le nombre de chargements de page : un texte de question est **écrit une
  fois et stocké** (`qst_question.texte`). Recharger la page ne rappelle personne.

La colonne `qst_question.redacteur` garde qui a écrit la phrase — `regles` ou `gemini`. Sans
elle, « le repli a-t-il servi ? » se répondrait en relisant des logs, c'est-à-dire ne se
répondrait pas.

### 4 bis. La mémoire vivante : à qui la question peut encore servir

La détection trouve des lacunes **justes et inutiles**. « Qui était la mère de cet homme
né en 1743 ? » est un vrai trou dans l'arbre ; personne ne peut y répondre. Sur 274
fiches, ces questions-là sont l'immense majorité, et une page qui s'ouvre dessus ne se
rouvre pas.

> **On ne pose de question que sur quelqu'un dont la famille peut avoir entendu parler de
> vive voix.**

Deux cercles concentriques, **et pas un de plus** :

1. **la mémoire directe** — la personne est vivante, ou l'une de ses bornes de date tombe
   après l'année plancher. C'est « quelqu'un a pu la connaître » ;
2. **une seule passe de plus** — parent, enfant ou conjoint de quelqu'un du premier
   cercle. C'est « on m'en a parlé » : le grand-père mort avant ma naissance, dont mon
   père m'a parlé.

**Une seule passe, en dernier, et elle ne propage rien.** C'est mot pour mot la discipline
des conjoints de l'ADR 0003, et elle se casse de la même façon : itérer jusqu'à stabilité
remonterait l'arbre de proche en proche jusqu'à la racine, et le filtre ne filtrerait plus
rien. On relirait le code en croyant y lire une règle, et il n'en resterait rien. *Un test
le garde, et il a fallu le corriger : sa première version passait même avec le code
saboté, l'ordre d'itération masquant le point fixe.*

**Cette règle n'est pas une règle de confidentialité et ne la remplace pas.** Le cercle
protège ; celle-ci rend la page utile. Les deux s'appliquent, à deux endroits distincts,
et confondre les deux serait la façon la plus naturelle de croire protégé ce qui ne l'est
pas.

### 4 ter. Deux réglages, pour la raison qui a fait de `N` un réglage

| Clé `core_setting` | Défaut | Ce qu'elle décide |
|---|---|---|
| `questions.memoire_vivante_annees` | **90** | l'année plancher, calculée en retranchant ce nombre à l'année courante |
| `questions.maximum_par_page` | **20** | combien de questions la page sert d'un coup |

Les deux valeurs sont des **intuitions, pas des mesures** — exactement comme le `N = 3` de
l'ADR 0003, *« une intuition, formulée avec hésitation »*. Elles seront confrontées à la
réalité au premier membre qui ouvrira la page : soit un écran vide, soit un mur. Un
chiffre qu'on découvre faux et qu'on ne peut changer qu'en redéployant sera changé en dur
dans le code par le premier qui passe, sans trace et sans discussion.

**Un nombre d'années, jamais une année.** Une constante `1936` serait juste une fois et
vieillirait d'un an par an, en silence.

**Aucune migration ne les sème.** Une clé absente de `core_setting` vaut son défaut ;
semer la ligne à l'installation aurait figé la valeur du jour de l'installation, ce qui
est l'inverse du but d'un réglage.

### 4 quater. Ce qu'on refuse d'une réponse du modèle

Le modèle ne décide de rien, mais il **écrit**, et une phrase écrite est servie telle
quelle. Trois contrôles la filtrent avant qu'elle n'atteigne un membre, et un seul des
trois compte vraiment.

> **La phrase doit NOMMER la personne dont elle parle.** C'est le seul défaut qu'un
> modèle peut produire ici et qui **ne se voit pas** : une question parfaitement écrite,
> parfaitement plausible, qui porte sur quelqu'un d'autre. Le membre répond de bonne foi,
> la réponse entre dans la file au nom de la mauvaise fiche, et l'administrateur valide
> un fait juste sur la mauvaise personne.

Le contrôle est bête — le nom du sujet doit figurer dans le texte — et il tient. La
consigne l'exige déjà ; **une consigne n'est pas une garantie**. Il ne prouve pas que la
phrase pose la bonne *question* ; rien d'automatique ne le prouverait. Il attrape le cas
dont la conséquence est irréversible.

Les deux autres sont de forme : une phrase, pas un paragraphe — moins de 280 signes,
sans retour à la ligne. Toute phrase refusée retombe sur le gabarit, **silencieusement**,
et porte donc `redacteur = regles`.

*Une référence que le modèle rendrait sans qu'on la lui ait donnée est ignorée* : il ne
peut pas ouvrir de question.

### 4 quinquies. Le coupe-circuit, et pourquoi il n'alerte pas

Trois échecs consécutifs, et l'on cesse d'appeler pendant cinq minutes ; un succès remet
le compteur à zéro. Sans lui, un tiers tombé coûte **un aller-retour raté par lacune
nouvelle, à chaque ouverture, par chaque membre** — et le symptôme n'est pas « le tiers
est en panne », c'est « l'application est lente ».

Un compteur en cache, avec une durée de vie : ni table, ni tâche de fond, ni nettoyage.
Un cache vidé se lit « passant », ce qui est le bon défaut — perdre la mémoire d'une
panne coûte un aller-retour, garder par erreur la mémoire d'une panne réparée coûterait
des heures de repli silencieux.

**Il n'alerte jamais.** Ouvert ou fermé, la page sert des questions. Ce qui se lit, quand
on veut savoir, est `SELECT redacteur, count(*) FROM qst_question GROUP BY 1`.

### 5. Palier payant / Vertex AI, jamais le palier gratuit

L'accès se fait par l'**offre payante** de l'API Gemini (clé d'API payante ou Vertex AI sur
Google Cloud), **jamais par le palier gratuit**.

Le motif est unique et suffisant : les conditions du palier gratuit de Google AI Studio
prévoient que les contenus soumis peuvent être **examinés par des personnes et utilisés pour
améliorer les produits**, tandis que les offres payantes (clé payante et Vertex AI)
s'engagent à ne pas utiliser les données soumises pour entraîner des modèles. Pour un corpus
qui nomme dix-huit personnes vivantes, la différence n'est pas une nuance de facturation :
c'est la différence entre « un prestataire traite nos données » et « nos données entrent dans
un modèle ».

**Vertex AI est préféré à la clé payante simple** quand les deux sont disponibles, parce
qu'il expose la région de traitement et la journalisation des accès, qui sont exactement ce
qu'on veut pouvoir montrer à un membre qui demande où sont ses données. Ce n'est pas une
condition bloquante : la clé payante suffit à la décision ci-dessus.

**Ces conditions sont celles du fournisseur, et elles lui appartiennent.** Elles sont
résumées ici parce qu'elles motivent la décision, pas parce que ce document en fait foi :
elles se relisent chez Google **avant** de poser la clé, et de nouveau si le fournisseur
les modifie. Un ADR qui recopie les conditions d'un tiers en devient l'archive périmée —
ce qu'on grave ici, c'est la **règle** : *jamais le palier gratuit*, quelle que soit la
rédaction du moment. Si un jour le palier payant cessait d'offrir la clause de
non-entraînement, la décision à prendre ne serait pas de s'en accommoder, ce serait de
rouvrir cet ADR.

Conséquence opérationnelle, et c'est une porte : **tant que le palier n'est pas établi,
aucun appel ne part.** Le repli est le seul rédacteur. Échouer fermé coûte des questions
moins bien tournées ; échouer ouvert coûte un corpus familial versé au palier gratuit, et
cela ne se reprend pas.

### 6. Le secret se déclare par son nom, jamais par sa valeur

Les variables d'environnement du projet portent le préfixe `HERITAGE_*` (`api/.env`). Ce
chantier en déclare trois, et **aucune valeur n'est écrite nulle part dans le dépôt** — ni
dans `.env`, ni dans un test, ni dans un commentaire, ni dans un journal. C'est le
commanditaire qui les pose.

| Variable | Rôle | Sans elle |
|---|---|---|
| `HERITAGE_GEMINI_CLE_API` | le secret d'accès | **aucun appel ne part**, le repli rédige |
| `HERITAGE_GEMINI_MODELE` | l'identifiant du modèle | valeur par défaut du code |
| `HERITAGE_GEMINI_POINT_DACCES` | l'URL de base (Vertex ou API publique) | valeur par défaut du code |

`HERITAGE_GEMINI_CLE_API` **vide est un état normal**, pas une panne : c'est l'état d'un
déploiement qui n'a pas encore souscrit, et celui de l'intégration continue. Il ne produit
donc **aucune alerte** — un rouge permanent sur un état normal détruit l'alarme là où elle
sert. Ce que le produit affiche, c'est l'origine de chaque question, pas un avertissement.

### 7. Aucun nouveau chemin d'écriture vers l'arbre

Une réponse **ne touche pas `tree_person`**. Elle entre dans le circuit de l'ADR 0004, par
les natures qui existent déjà :

| Réponse du membre | Nature de demande | Ce qui est écrit |
|---|---|---|
| une photo | `ajout_media` | `prp_change_op` avec `op_kind = 'ajout_media'` |
| un fait qui vise un champ de la surface éditable | `modification_personne` | `prp_change_op` avec `op_kind = 'champ'` |
| un fait qui ne vise aucun champ (« sa mère s'appelait… ») | `ajout_note` | `prp_change_op` avec `op_kind = 'ajout_note'` |
| « Je ne sais pas » | *aucune* | l'état de la question, et rien d'autre |
| « Je vais me renseigner » | *aucune* | l'état de la question, et une date de relance |

Le module `Question` n'écrit **que ses deux tables**. Il dépose par un port, que `Proposal`
implémente, et qui appelle `DepotDeDemande` — le même service que `POST /api/demandes`,
avec les mêmes contrôles, le même verrou optimiste, la même file. *Si l'on se surprend à
écrire un `INSERT` vers l'arbre, on s'est trompé de route.*

### 8. « Je ne sais pas » et « Je vais me renseigner » sont deux états, pas un

C'est le point de conception qui décide si la page est utilisable au bout d'une semaine.

- **« Je ne sais pas » ferme la question définitivement** pour ce membre. Sans mémoire, la
  même question revient à chaque chargement, et la page devient un mur de choses auxquelles
  on a déjà répondu — c'est-à-dire un écran qu'on n'ouvre plus. L'état est `inconnue`.
- **« Je vais me renseigner » la repousse.** Elle doit revenir, plus tard : c'est une
  promesse, et une promesse qu'on n'aide pas à tenir est une contribution perdue. L'état
  est `differee`, avec `relancer_apres`.

Les confondre en un seul bouton « masquer » perd l'une des deux : soit on oublie une réponse
qui allait venir, soit on redemande à quelqu'un ce qu'il a déjà dit ne pas savoir.

La mémoire ne porte **pas sur la ligne de question**, elle porte sur la **lacune** :
`signature = sha256(nature : sujet_kind : sujet_id)`, unique par destinataire. La détection
peut donc être rejouée autant qu'on veut — elle retombe sur la même signature, retrouve la
ligne, et ne repose pas la question. C'est ce qui rend la détection **idempotente** sans
qu'aucun service n'ait à s'en souvenir.

### 9. Une lacune comblée ne se balaie pas, elle cesse d'être détectée

Quand quelqu'un ajoute enfin le lieu de décès, la question correspondante n'est pas
« périmée » par une tâche de fond : la détection ne la produit plus, et la page sert
l'intersection de *ce qui est détecté maintenant* et de *ce qui n'est ni fermé ni différé*.

C'est la même discipline que la caducité de l'ADR 0004 — *« constatée à la validation,
jamais par une tâche de fond : à ce volume, un balayage périodique serait de la mécanique
pour rien »*. La ligne reste en base, avec son état ; elle ne revient simplement plus.

## Alternatives considérées

- **Ne pas appeler de modèle du tout, et servir les gabarits.** C'est exactement le repli
  décidé au § 4, et il n'est donc pas écarté : il est livré et testé. Ce qu'il ne sait pas
  faire, c'est situer la personne pour son lecteur (« la sœur de votre grand-mère »), ni
  varier la formulation quand vingt questions se suivent. Une page de vingt phrases
  identiques à un mot près se referme.
- **N'envoyer que le sujet et son entourage immédiat.** Réduit fortement le corpus. Écarté
  par l'arbitrage du commanditaire, qui a préféré la qualité de formulation. Le coût de cet
  arbitrage est nommé ici plutôt que tu : le tiers reçoit des vivants dont aucune question
  ne parlera jamais.
- **N'envoyer que les personnes décédées.** Tentant — 256 fiches sur 274 —, et faux : les
  lacunes que la famille peut combler portent en majorité sur les personnes **récentes**,
  donc sur celles dont il reste quelqu'un pour se souvenir. Filtrer les vivants revient à
  ne poser que les questions auxquelles personne ne peut répondre.
- **Pseudonymiser le corpus** (jetons à la place des noms, restitués au retour). Techniquement
  faisable et la moins coûteuse des protections. Écartée par l'arbitrage du commanditaire, en
  connaissance de la réserve. Ce qu'elle aurait coûté, pour mémoire : les prénoms sont
  précisément ce qui rend une question reconnaissable (« Alberte » et non « la personne
  n° 214 »), et un jeton restitué après coup ne rattrape pas une phrase construite sans lui.
- **Un modèle exécuté localement.** Pas de corpus qui sort, aucun palier à choisir. Écarté :
  l'application tourne sur un VPS partagé avec 34 autres hôtes virtuels, et l'ADR 0001 écarte
  explicitement toute dépendance qui ne sert pas à l'échelle de 274 personnes. Un modèle local
  est une machine de plus à administrer pour formuler quelques dizaines de phrases.
- **Laisser le modèle détecter les lacunes lui-même**, en plus de les formuler. Écarté au
  § 4, et c'est l'écart le plus important de cette liste : on ne saurait plus distinguer une
  lacune d'une hallucination, la page n'aurait plus de repli, et chaque chargement serait un
  appel facturé.
- **Générer la question à chaque affichage** plutôt que la stocker. Écarté : le coût suit
  alors les rechargements et non les lacunes, et le texte d'une question changerait entre
  deux visites — y compris sous une réponse déjà rédigée.

## Conséquences

- **Deux tables, préfixe `qst_`** : `qst_question` et `qst_question_demande`. Le détail est
  dans `docs/modele-donnees.md`. Aucune relation Doctrine ne franchit la frontière du module
  (ADR 0002) : `destinataire_user_id`, `sujet_id` et `demande_id` sont des identifiants nus,
  et `sujet_label` est un libellé dénormalisé.
- **`qst_question.sujet_label` rejoint la liste des colonnes `*_label` du § 0.4 du modèle de
  données**, filtrées comme la personne qu'elles nomment. `qst_question.texte` l'est aussi,
  et c'est une **première** : jusqu'ici, une sortie de données personnelles était un nom
  recopié dans une colonne prévue pour ça. Ici c'est une phrase en français qui contient le
  nom, et aucune liste blanche champ par champ ne l'aurait vue. La règle qui l'attrape est
  celle, plus générale, de l'ADR 0003 : *« tout champ dérivé d'une donnée masquée est
  lui-même une donnée masquée »*. Le remède est le même que pour les autres : la question
  entière n'est pas servie quand son sujet ne l'est pas.
- **Une huitième porte de sortie** au sens de l'ADR 0003 — la liste des questions — avec son
  test de liste blanche, interrogée par un compte hors cercle.
- **Un appel sortant est journalisé** dans `core_audit_log` : l'horodatage, le nombre de
  personnes transmises, le nombre de vivantes parmi elles, le modèle appelé, l'issue. **Pas
  le corpus, pas la réponse, jamais la clé.** Sans ce compte, « combien de vivants sont
  partis chez le tiers ce mois-ci » n'a pas de réponse, et c'est la première question qu'un
  membre posera.
- **La page dit à qui lit qu'un modèle a écrit la question.** Ce n'est pas un ornement : un
  membre qui découvre par hasard que ses données familiales sont passées chez un tiers le
  vit autrement que celui à qui on l'a dit. C'est aussi ce qui rend la mention de
  `redacteur` utile ailleurs qu'en base.
- **Une question `repondue` ne revient pas, même si sa demande est refusée ou devient
  caduque.** C'est une limite assumée : le membre retrouve sa demande, son motif de refus et
  le bouton « refaire cette demande » dans « Mes demandes » (ADR 0004), qui est le circuit
  prévu pour ça. Rouvrir la question en plus donnerait deux endroits pour la même reprise,
  et deux endroits pour l'oublier.
- **Le quota et la panne sont traités par un coupe-circuit en cache**, pas par une table :
  au-delà d'un nombre d'échecs consécutifs, la formulation bascule sur le repli pour une
  durée, et se rouvre seule. Aucune tâche de fond, aucune ligne à nettoyer — même motif
  qu'au § 9.
- **Si la famille s'élargit** au point que l'envoi du corpus ne soit plus tenable, ou si le
  fournisseur change ses conditions, la décision est révisée par un nouvel ADR. Elle ne l'est
  pas ici.

## Addendum (1er octobre 2026) — la description de famille d'une inscription sans fiche

Le lien d'invitation illimité, partagé dans le groupe familial, laisse désormais s'inscrire
une personne **qui ne se trouve pas dans l'arbre** (ADR 0013) : son compte attend, sans
fiche, avec un texte libre qui décrit sa famille (« mon père est Jean Dupont, né vers 1960
à Lille… »). Pour aider l'administrateur à la rattacher, le module Assistant soumet ce texte
au modèle, qui le confronte à l'arbre et conclut « c'est probablement la fiche X » ou
« elle est absente ; voici la demande de création » (`Assistant\Service\AgentDeRattachement`,
`GET|POST /api/administration/rattachements/{memberId}/suggestion`).

C'est une **nouvelle donnée transmise au tiers**, et elle ne ressemble pas aux autres : ce
n'est pas l'arbre, c'est ce qu'une personne vivante a écrit d'elle-même et de ses proches,
souvent vivants. Ce qui est décidé :

- **Ce qui part** : le nom, les prénoms et la date de naissance déclarés, la description,
  et, à titre d'indice, l'identifiant de la personne que l'invitation utilisée pré-liait.
  Les outils de lecture sont ceux de l'assistant de proposition (`chercher_personnes`,
  `lire_personne`), sur l'arbre entier — la décision de l'ADR sur l'assistant vaut ici.
- **Ce qui ne part jamais** : le **courriel** du compte. Il n'aide en rien à reconnaître
  une fiche, et c'est un identifiant de vivant.
- **La personne est prévenue** avant d'écrire : le formulaire d'inscription dit que seuls
  les administrateurs lisent le texte, et qu'il peut être analysé automatiquement (Google
  Gemini). Même principe qu'au § « La page dit à qui lit qu'un modèle a écrit la question ».
- **L'appel est déclenché par un administrateur**, jamais par l'inscription elle-même :
  paresseusement, à la première consultation de la carte « À rattacher », puis sur « Relancer
  l'analyse ». Une inscription de spam ne coûte donc aucun appel tant que personne ne la
  regarde. Quota distinct : 40 analyses par 24 heures glissantes, tous administrateurs
  confondus (`assistant.limiteur_rattachements` ; depuis le 2026-10-01, quota
  `assistant.rattachements`, limite globale réglable — `api-contract.md` § 10.1).
- **Le résultat est gardé sur le compte** (`mbr_member.attachment_suggestion`), puis
  **purgé** avec la description au rattachement ou au refus. Il n'est jamais visible d'un
  autre que les administrateurs, jamais écrit dans le diff d'audit (colonnes sensibles de
  `AuditDoctrineSubscriber`).
- **Journal** : `AppelModeleDeLangage`, sujet `Assistant\Rattachement` — des comptes (tours,
  appels d'outils, verdict), jamais la description ni la réponse du modèle, jamais la clé.
- **Le modèle ne décide rien.** Aucun rattachement automatique : l'administrateur choisit la
  fiche, ou crée la fiche par une demande **validée** (ADR 0004). L'identité de la fiche
  créée vient toujours de la déclaration, jamais d'une « correction » du modèle.
- **Sans clé, ou en panne**, la carte reste utilisable à la main : rattacher à une fiche
  choisie, ou créer la fiche depuis la seule déclaration en choisissant ses parents.

## Addendum (1er octobre 2026, soir) — « Poser une question » à l'assistant

Un membre connecté interroge l'arbre en langage naturel, dans une conversation suivie
(`Assistant\Service\AgentDeConsultation`, `docs/api-contract.md` § 25). C'est une **troisième
sortie vers le tiers**, et elle diffère des deux autres sur le point qui compte.

- **Le modèle ne voit que le cercle du lecteur.** L'assistant de proposition reçoit l'arbre
  entier, vivants hors cercle compris, parce qu'il doit les reconnaître pour ne pas les
  proposer en double ; il rend des demandes, que le serveur filtre champ par champ. Ici, le
  modèle rend **une phrase libre** au lecteur, et une phrase ne se filtre pas : s'il
  connaissait le nom d'un vivant caché, il pourrait l'écrire, ou le laisser deviner. Les
  outils (`Assistant\Service\OutilsDeConsultation`) appliquent donc les portes de sortie de
  l'ADR 0003 **avant** de répondre au modèle : un vivant hors cercle est absent des
  recherches, et réduit partout ailleurs à son identifiant et à « Personne vivante ». La
  décision du § 1 (« le corpus part entier ») **ne s'applique pas** à ce mode.
- **Ce qui part** : la question du membre ; les derniers échanges de la conversation (six au
  plus, 9 000 signes au plus), réponses repassées au cercle du moment ; le numéro de la
  fiche du membre ; ce que les outils rendent — fiches visibles, faits, notes, sources avec
  leur niveau d'appui (acte, index, arbre en ligne), adresses d'arbres en ligne retirées.
- **Ce qui ne part jamais** : le courriel du membre, une donnée d'un vivant hors de son
  cercle, les réponses aux questions de la page « Questions » (§ 1, inchangé).
- **Ce qui est gardé** : la conversation, côté serveur, pour son auteur seul
  (`ast_conversation`, `ast_echange`), jusqu'à ce qu'il la supprime. Elle ne contient rien
  qu'il n'ait eu le droit de lire ; les noms des fiches citées sont relus à chaque lecture.
- **Journal** : `AppelModeleDeLangage`, sujet `Assistant\Question` — des comptes, jamais la
  question, la réponse ni un nom. Le journal mécanique n'écrit des deux tables que le type et
  l'identifiant : aucune colonne de contenu n'y est jamais modifiée.
- **Le modèle ne décide rien et n'écrit rien** : aucun outil d'écriture, aucune demande
  déposée. Il doit dire « je ne sais pas » plutôt qu'inventer, et distinguer ce qu'un acte
  établit de ce qu'un arbre en ligne affirme — c'est sa consigne, pas une garantie : la
  réponse cite ses fiches pour que le lecteur vérifie.
- **La page dit** que les questions partent chez Google Gemini.
- **Quota** : 60 questions par 24 heures glissantes et par compte
  (`assistant.limiteur_questions`), distinct des 20 analyses de proposition. Depuis le
  2026-10-01, ce sont des **défauts** : l'administrateur peut poser une limite par compte
  (`api-contract.md` § 10.1, « Quotas d'usage »).
