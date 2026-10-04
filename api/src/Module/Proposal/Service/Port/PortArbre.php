<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service\Port;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Contract\CitationAcreer;
use App\Module\Proposal\Contract\FiliationEtablie;
use App\Module\Proposal\Contract\FiliationIncompatible;
use App\Module\Proposal\Contract\FiliationPrevue;
use App\Module\Proposal\Contract\InventaireDeSuppression;
use App\Module\Proposal\Contract\LienARetirer;
use App\Module\Proposal\Contract\LienRetire;
use App\Module\Proposal\Contract\NoteAcreer;
use App\Module\Proposal\Contract\RetraitDeLien;
use App\Module\Proposal\Contract\SourceCitable;
use App\Module\Proposal\Contract\VocabulaireDAnnotation;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * POINT DE BRANCHEMENT DU MODULE `Tree` — à lire avant d'écrire ses services.
 *
 * `Proposal` doit lire et écrire des lignes `tree_person`, `tree_family` et
 * `tree_citation`, et il n'a le droit de faire ni l'un ni l'autre directement :
 *
 * - **règle 5 de l'ADR 0002** : « valider une proposition écrit dans `Tree` **en
 *   appelant un service de `Tree`**, pas en manipulant `Tree\Person` depuis
 *   `Proposal` » ;
 * - **règle 3 de l'ADR 0002**, vérifiée en intégration continue : un `use
 *   App\Module\Tree\…` dans `Proposal` est une infraction. Seul `Core` est en liste
 *   blanche.
 *
 * D'où cette interface, **déclarée ici et implémentée dans `Tree`**. Elle est le seul
 * chemin par lequel une validation touche l'arbre, et ce n'est pas une contrainte de
 * façade : c'est `Tree` qui sait normaliser un couple selon le sexe, attribuer un
 * `gedcom_xref` après le `flush`, recalculer `display_name`, `search_key`,
 * `best_quay`, `branch` et `generation` — écrire ces règles une seconde fois dans
 * `Proposal` est exactement le mécanisme par lequel une frontière se dissout.
 *
 * ── Où elle est branchée ─────────────────────────────────────────────────────────
 *
 * L'alias vit dans `config/services/proposal.yaml` ; **ce fichier ne dit pas sur quoi
 * il pointe aujourd'hui**, et c'est délibéré. Un commentaire qui décrit l'état du
 * câblage devient faux le jour où quelqu'un le change, sans que rien ne le signale — un
 * commentaire sert justement à dispenser d'aller voir, donc personne ne va voir. La
 * seule chose durable à écrire ici, c'est **où regarder** : `debug:container` tranche.
 *
 * {@see ArbreNonBranche} est le repli. Il **lève** à la première écriture, pour qu'une
 * validation ne réussisse jamais en silence sans rien écrire.
 *
 * ── Le contrat des valeurs, à respecter au caractère près ────────────────────────
 *
 * Les valeurs qui traversent ce port sont des **valeurs PHP**, pas du JSON : la
 * conversion depuis et vers la forme d'op (§ 5.2 du modèle) est faite par
 * `Proposal\Service\NormaliseurDeValeur`, parce que c'est `Proposal` qui a défini
 * cette forme. La correspondance, par `ValueKind` :
 *
 * | `ValueKind`       | Type PHP au bord du port                                  |
 * |-------------------|-----------------------------------------------------------|
 * | `texte`           | `string` \| `null`                                        |
 * | `entier`          | `int` \| `null`                                           |
 * | `booleen`         | `bool`                                                    |
 * | `enum`            | **`string` \| `null`** — la *valeur* de l'énumération      |
 * | `date_incertaine` | `Core\ValueObject\UncertainDate`, jamais `null`           |
 *
 * **`enum` traverse en chaîne et non en objet, délibérément** : `CitationQuality` est
 * une énumération de `Tree`, que `Proposal` ne peut pas nommer sans enfreindre la
 * règle 3. C'est l'implémentation, côté `Tree`, qui convertit la chaîne en cas
 * d'énumération — et qui lève si la chaîne n'en est pas une.
 *
 * ── Les deux règles de transaction ───────────────────────────────────────────────
 *
 * - **`appliquerChamps()` est appelée à l'intérieur de la transaction du validateur.**
 *   Elle doit donc `flush()` — pour que `#[ORM\Version]` s'exerce et que la nouvelle
 *   version soit connue — mais **ne jamais** ouvrir, valider ni annuler de transaction
 *   elle-même.
 * - **Elle doit laisser remonter `Doctrine\ORM\OptimisticLockException`.** C'est elle,
 *   et elle seule, qui devient le `409 verrou_concurrent` du § 6.8 du contrat d'API —
 *   celui qui dit « réessayer ». L'attraper et la traduire dans `Tree` ferait perdre
 *   la distinction avec `demande_caduque`, qui dit exactement l'inverse.
 */
interface PortArbre
{
    /**
     * Lit l'état courant d'une ligne visée par une demande.
     *
     * Rend `null` **quand la ligne n'existe plus** — supprimée ou fusionnée. Ce n'est
     * pas une erreur : c'est le cas `cible_disparue` du § 6.8, qui est une caducité et
     * non un refus. Une implémentation qui lèverait ici ferait passer pour une panne
     * un événement prévu.
     *
     * @param SubjectKind $kind `personne`, `famille` ou `citation` — les trois tables
     *                          qui portent un `#[ORM\Version]` (§ 9.6 du modèle)
     */
    public function chargerCible(SubjectKind $kind, int $id): ?EtatCible;

    /**
     * Applique **tous** les champs d'une même ligne, en un seul appel.
     *
     * Un appel par ligne et non par champ : c'est ce qui permet à `Tree` de recalculer
     * ses colonnes dérivées une fois, et c'est ce qui rend l'écriture atomique au
     * niveau où le verrou optimiste l'est — la ligne.
     *
     * @param array<string, mixed> $valeurs `field` (nom de propriété Doctrine) => valeur PHP.
     *                                      Les noms sont bornés par
     *                                      `Proposal\Service\SurfaceEditable` : jamais
     *                                      une colonne dérivée, jamais `version`.
     *
     * @throws \Doctrine\ORM\OptimisticLockException si une transaction concurrente a
     *                                               touché la ligne — à laisser remonter telle quelle
     */
    public function appliquerChamps(SubjectKind $kind, int $id, array $valeurs): EcritureAppliquee;

    /**
     * Insère une personne proposée, et rend son identifiant réel.
     *
     * C'est la passe 2 du § 9.5, et c'est `Tree` qui la fait de bout en bout : il copie
     * les colonnes, compose `display_name` et `search_key`, et pose lui-même
     * `gedcom_xref` **après le `flush`** — un identifiant GEDCOM se dérive de la clé
     * primaire, qui n'existe pas avant (§ 9.8).
     *
     * Appelée dans la transaction du validateur, jamais ailleurs : une personne créée
     * sans la famille qui la relie flotterait hors de l'arbre, et c'est exactement ce
     * que « tout ou rien » empêche.
     */
    public function creerPersonne(PersonneAcreer $fiche): LigneCreee;

    /**
     * Lit une source de l'arbre — **aucune écriture**, appelée au dépôt.
     *
     * Rend `null` quand la source n'existe pas, et c'est un `422` sur le chemin de l'op,
     * jamais une exception : on valide un formulaire, et un identifiant de source faux
     * est une erreur de saisie, pas une panne. Même forme que `chargerCible()`, pour la
     * même raison — un port qui lèverait sur l'absence ferait passer pour un incident un
     * cas que le contrat nomme.
     */
    public function chargerSource(int $id): ?SourceCitable;

    /**
     * Les deux vocabulaires fermés d'une citation — **aucune écriture**, appelée au dépôt.
     *
     * Elle existe pour que la `422` sur un `QUAY` hors norme ou une portée inconnue tombe
     * devant celui qui saisit, et non trois jours plus tard sur la validation d'un autre.
     * Le raisonnement complet, et le défaut qu'elle évite de rejouer, sont en tête de
     * {@see VocabulaireDAnnotation}.
     */
    public function vocabulaireDAnnotation(): VocabulaireDAnnotation;

    /**
     * Insère une citation proposée sur une personne existante, et rend son identifiant
     * réel — le pendant `création` de {@see appliquerChamps()} sur une citation.
     *
     * ── Ce que `Tree` fait ici et que `Proposal` ne peut pas faire ───────────────────
     *
     * Il retrouve les deux lignes, convertit le `QUAY` en `CitationQuality` et le `scope`
     * en `NoteScope` — deux énumérations de `Tree` que `Proposal` n'a pas le droit de
     * nommer (règle 3 de l'ADR 0002) —, puis **rafraîchit `best_quay` et
     * `citation_count` sur la personne**. Ces deux colonnes dérivées sont ce qui fait
     * basculer une fiche entre « appuyée sur un acte » et « recopie non vérifiée » :
     * les oublier laisserait une fiche nouvellement sourcée du mauvais côté de tous les
     * filtres, sans que rien ne le signale — exactement le mode de défaillance que
     * `appliquerChamps()` documente déjà pour la modification d'un `QUAY`.
     *
     * ── Les deux absences qui sont des erreurs, et non des caducités ─────────────────
     *
     * Appelée **dans la transaction** du validateur, après le contrôle préalable. La
     * disparition de la personne a donc déjà été tranchée par `AnalyseDeDemande`, qui en
     * fait un `cible_disparue` avant qu'on arrive ici ; celle de la source n'a pas de
     * pré-contrôle possible, aucune route ne supprimant de source. L'implémentation
     * **lève** dans les deux cas plutôt que de ne rien écrire : une validation qui
     * réussit sans écrire est le défaut que tout ce circuit a déjà payé une fois.
     *
     * `LigneCreee::$creee` vaut toujours `true` : une citation n'est jamais « retrouvée »
     * — deux contributeurs qui citent la même page du même registre sur la même fiche
     * font deux citations, et c'est juste, ils l'ont lue chacun.
     *
     * @throws \RuntimeException si la personne ou la source a disparu depuis le dépôt
     */
    public function creerCitation(CitationAcreer $citation): LigneCreee;

    /**
     * Insère une note de recherche proposée sur une personne existante.
     *
     * La moitié qui manquait : `creerPersonne()` sait poser la note d'un brouillon, et
     * rien ne savait en poser une sur une fiche déjà là. Les notes sont pourtant « les
     * lignes les plus précieuses du fichier » (§ 3.5 du modèle), et les 337 fiches déjà
     * en base n'avaient aucun moyen d'en recevoir.
     *
     * ── Ce qu'elle ne fait pas, et c'est une différence avec la citation ────────────
     *
     * **Aucun agrégat à rafraîchir.** `tree_note` n'alimente ni `best_quay` ni
     * `citation_count` : une note documente, elle n'étaye pas. Une fiche qui reçoit dix
     * notes n'est pas mieux sourcée pour autant, et faire croire le contraire serait
     * exactement l'inverse du but du § 9.7.
     *
     * **Aucun verrou optimiste non plus** — `tree_note` ne porte pas de `#[ORM\Version]`,
     * n'étant pas une cible de `ChangeRequest` (§ 9.6). Documenter une fiche ne peut donc
     * pas entrer en conflit avec sa correction.
     *
     * Appelée **dans la transaction** du validateur. L'absence de la personne a déjà été
     * tranchée par le contrôle préalable ; l'implémentation **lève** si elle a disparu
     * depuis, plutôt que de laisser une validation réussir sans écrire.
     *
     * @throws \RuntimeException si la personne a disparu depuis le dépôt
     */
    public function creerNote(NoteAcreer $note): LigneCreee;

    /**
     * Ce qu'une suppression de fiche emporterait — **aucune écriture**, appelée au dépôt
     * comme à la lecture d'administration.
     *
     * ## Pourquoi c'est l'arbre qui compte et pas `Proposal`
     *
     * Parce que le compte dépend de six clés étrangères et d'une contrainte `CHECK` que
     * seul `Tree` connaît. `tree_child`, `tree_citation`, `tree_fact` et `tree_note` partent
     * en `ON DELETE CASCADE` ; `tree_family.husband_id` et `wife_id` passent à `NULL` —
     * **sauf** quand la personne en est le seul époux, car `ck_tree_family_au_moins_un_epoux`
     * interdit alors une famille sans personne, et la ligne doit partir. Recopier cette
     * règle dans `Proposal` serait le mécanisme exact par lequel une frontière se dissout,
     * et elle se dissoudrait sur la seule opération du produit qui détruit.
     *
     * Rend `null` **quand la personne n'existe plus**, pour la même raison que
     * {@see chargerCible()} : c'est le cas `cible_disparue`, une caducité prévue et non une
     * panne. Une fiche déjà supprimée par une autre demande n'est pas une erreur, c'est le
     * résultat recherché obtenu par quelqu'un d'autre.
     *
     * `InventaireDeSuppression::$medias` vaut **toujours `0`** ici : `med_media` appartient
     * à `Media`, et l'arbre ne la lit pas. C'est `Proposal` qui complète, par
     * {@see PortMedias::compterLesMediasDe()}.
     */
    public function inventaireDeSuppression(int $personneId): ?InventaireDeSuppression;

    /**
     * Efface une fiche et ce qu'elle porte, et rend **l'inventaire de ce qui est
     * réellement parti**.
     *
     * ## Ce qu'elle rend, et pourquoi ce n'est pas `void`
     *
     * `prp_revision.changes` doit porter le décompte de ce qui a disparu, mesuré
     * **dans la transaction** — pas celui du dépôt, qui date de trois jours, ni celui de
     * la lecture d'administration, qui date du dernier rafraîchissement d'écran. C'est la
     * seule mesure qui puisse prétendre décrire ce qui a eu lieu, et l'historique d'une
     * fiche effacée est tout ce qu'il en restera.
     *
     * ## Les deux refus, qui sont des pannes et non des caducités
     *
     * - **la personne a disparu depuis le contrôle préalable** : même forme que
     *   {@see creerNote()}, l'implémentation **lève**. Une validation qui réussit sans
     *   écrire est le défaut que ce circuit a déjà payé une fois ;
     * - **l'inventaire courant est bloqué** — un descendant ou une preuve est apparu
     *   depuis. L'implémentation **lève** aussi, et c'est le dernier filet : le contrôle
     *   préalable a déjà rendu la demande caduque dans ce cas, et arriver ici signifie
     *   qu'un chemin l'a contourné. Effacer quand même emporterait une branche que personne
     *   n'a vue passer.
     *
     * Appelée **dans la transaction** du validateur, et elle `flush()` sans jamais ouvrir
     * de transaction — la règle commune à tout ce port.
     *
     * @throws \RuntimeException si la personne a disparu, ou si ce qu'elle emporte a changé
     */
    public function supprimerPersonne(int $personneId): InventaireDeSuppression;

    /**
     * Trouve **ou** crée la famille d'un couple — passe 4 du § 9.5.
     *
     * C'est `Tree` qui normalise le couple en `(husband_id, wife_id)` selon le sexe, qui
     * place une personne de sexe inconnu dans l'emplacement libre, qui interroge
     * `WHERE husband_id IS NOT DISTINCT FROM ? AND wife_id IS NOT DISTINCT FROM ?` et
     * qui connaît `uniq_tree_family_couple`. Réécrire ces règles ici serait le mécanisme
     * exact par lequel une frontière se dissout.
     *
     * **L'ordre des deux arguments n'est pas significatif** : c'est le sexe qui range,
     * pas la position. Un seul des deux peut être `null` — une famille d'un parent seul
     * est légitime, une famille sans personne ne l'est pas.
     *
     * `LigneCreee::$creee` dit si elle vient d'être insérée : une famille retrouvée ne
     * produit pas de révision.
     */
    public function trouverOuCreerFamille(?int $personneA, ?int $personneB): LigneCreee;

    /**
     * Les époux d'une famille, en identifiants — **au plus deux, parfois un seul**.
     *
     * Trois familles réelles de cet arbre n'ont qu'une `WIFE` : ce n'est pas une donnée
     * manquante, c'est l'état du travail généalogique. Une famille disparue rend un
     * tableau vide, jamais `null` — l'appelant boucle dessus, il n'a pas à tester.
     *
     * ── Pourquoi `Proposal` a besoin de cette lecture ──────────────────────────────
     *
     * Pour un seul contrôle, et il est réel. Une demande `ajout_lien` annonce la fiche
     * sur laquelle elle porte (`targetId`, § 6.4 du contrat), et cette cible doit être
     * **une personne que la demande touche** — sans quoi `targetId` serait un champ
     * libre permettant de faire apparaître n'importe quelle demande sur n'importe quelle
     * fiche de la file d'administration.
     *
     * Or le geste « ajouter un frère ou une sœur à Untel » ne cite pas Untel : une
     * fratrie s'écrit « enfant du même couple », donc `enfant_de` + `family_id`, dont le
     * sujet est **le frère**. La seule façon de reconnaître Untel dans ce corps est de
     * savoir qui compose la famille désignée — et c'est `Tree` qui le sait, parce que
     * c'est lui qui range `(husband_id, wife_id)` selon le sexe.
     *
     * @return list<int>
     */
    public function epouxDeFamille(int $familleId): array;

    /**
     * Porte les dates d'union sur une famille — passe 5 du § 9.5.
     *
     * **Toutes facultatives : une union sans date reste une union** (§ 6.4 du dossier).
     * Une date inconnue s'exprime par un `UncertainDate::inconnue()`, jamais par
     * l'absence d'appel : le demandeur peut vouloir effacer une date qu'il sait fausse.
     */
    public function porterUnion(int $familleId, UncertainDate $mariage, bool $divorce, UncertainDate $rupture): void;

    /**
     * Rattache un enfant à une famille — passe 5 du § 9.5.
     *
     * `$rangDansLaFratrie` vaut `null` quand le demandeur ne le connaît pas : c'est
     * alors à `Tree` de placer l'enfant à la fin, parce que lui seul sait combien la
     * famille en compte déjà.
     *
     * Rattacher deux fois le même enfant à la même famille ne doit **pas** lever : la
     * validation peut rejouer un lien que l'arbre porte déjà, et une demande refusée
     * pour cette raison serait refusée pour avoir eu raison.
     */
    public function rattacherEnfant(int $familleId, int $personneId, ?int $rangDansLaFratrie): void;

    /**
     * Ce que {@see etablirFiliation()} ferait — **aucune écriture**, appelée à la lecture
     * d'administration.
     *
     * `$enfantId` vaut `null` quand l'enfant est une fiche que la demande crée : il n'a
     * alors aucune famille de parents, et seule la règle 3 peut jouer.
     */
    public function prevoirFiliation(?int $enfantId, ?int $parentA, ?int $parentB, bool $unionUniqueAdmise): FiliationPrevue;

    /**
     * Rattache un enfant à ses parents **sans jamais empiler de familles parentales
     * incompatibles** — passes 4 et 5 du § 9.5 pour `enfant_de` et `parent_de`.
     *
     * ## Le défaut qu'elle corrige
     *
     * Le chemin d'avant, `trouverOuCreerFamille(P, null)` puis `rattacherEnfant()`, ne
     * regardait que le lien : « enfant de Jean-Marie » visait le couple `(450, ∅)`, ne le
     * trouvait pas, le créait. Il ignorait que l'enfant avait déjà une famille, et que
     * Jean-Marie en avait une avec Hélène. Trois demandes plus tard, Véronique avait trois
     * familles de parents, chacun en double sur sa fiche (demandes 714 → 727, corrigées à
     * la main le 01/10/2026).
     *
     * ## Les règles, dans l'ordre où elles sont essayées
     *
     * **Deux parents** (`A`, `B`) : leur famille de couple, trouvée ou créée — **ou** la
     * famille à un parent de l'enfant complétée, si elle porte l'un des deux et que
     * l'enfant y est seul. L'enfant quitte ensuite les familles à un parent de `A` ou de
     * `B` (règle 2).
     *
     * **Un parent** `P` :
     *
     * 1. l'enfant est déjà dans une famille dont `P` est l'époux → rien à faire ;
     * 2. l'enfant a une famille à un seul parent `Q` (règle 1) : si `Q × P` existe déjà,
     *    il y est rattaché et quitte celle de `Q` ; sinon la place libre reçoit `P` — si
     *    le sexe le permet, et si l'enfant est seul dans cette famille : compléter une
     *    famille qui compte d'autres enfants donnerait `P` pour parent à des frères qu'il
     *    n'a jamais été dit qu'il ait. Dans ce dernier cas, `Q × P` est créé ;
     * 3. l'enfant n'a aucune famille de parents, `P` a **une seule** union, à deux époux,
     *    et `$unionUniqueAdmise` (règle 3) → cette union ;
     * 4. sinon, la famille `(P, ∅)`, comme avant.
     *
     * **Règle 4, le garde** : si le résultat laissait l'enfant dans deux familles qui
     * partagent un parent, rien n'est écrit et {@see FiliationIncompatible} est levée.
     *
     * ## Ce qu'elle ne fait pas
     *
     * Elle ne supprime aucune famille : elle les vide. La suppression passe par
     * {@see supprimerFamilleVide()}, après que `Proposal` a vérifié ce que `Tree` ne voit
     * pas.
     *
     * @throws FiliationIncompatible
     */
    public function etablirFiliation(int $enfantId, ?int $parentA, ?int $parentB, bool $unionUniqueAdmise, ?int $rangDansLaFratrie): FiliationEtablie;

    /**
     * Supprime une famille **si et seulement si elle ne porte plus rien** : ni enfant, ni
     * date d'union ou de rupture, ni note, citation ou fait. Rend son libellé quand elle
     * est partie, `null` quand elle est restée.
     *
     * Le critère est vérifié ici, dans la transaction, et non sur la foi de l'appelant :
     * une famille qui porte des données ne se supprime jamais, quel que soit le chemin
     * qui le demande.
     */
    public function supprimerFamilleVide(int $familleId): ?string;

    /**
     * Ce que défaire ce lien ferait — **aucune écriture**, appelée au dépôt, à la lecture
     * d'administration et au contrôle préalable.
     *
     * ## Pourquoi c'est l'arbre qui mesure
     *
     * Parce que l'effet dépend de ce que seul `Tree` sait lire : qui sont les époux de
     * l'union et à quelle place, quels enfants elle compte dans `tree_child`, si elle
     * porte une date, une note, une citation ou un fait, si le couple restant entrerait en
     * collision avec une famille déjà là (`uniq_tree_family_couple`, `NULLS NOT
     * DISTINCT`), et où un enfant qui ne perd qu'un parent atterrirait chez l'autre — les
     * règles de {@see etablirFiliation()}, appliquées comme si l'enfant avait déjà quitté
     * la famille.
     *
     * `$familleProtegee` est ce que `Tree` ne voit pas : un média rattaché à l'union, ou —
     * à la validation seulement — une autre demande en attente qui la désigne. Une famille
     * protégée n'est jamais annoncée supprimée.
     *
     * Rend `null` quand la famille **ou** la personne n'existe plus : c'est le cas
     * `cible_disparue`, une caducité et non une panne. Un lien qui n'existe pas (ou plus)
     * entre les deux rend une mesure **bloquée**, pas `null` : c'est elle qui fait la
     * différence entre « la ligne a disparu » et « le lien a déjà été défait ».
     */
    public function prevoirRetraitDeLien(LienARetirer $lien, bool $familleProtegee): ?RetraitDeLien;

    /**
     * Défait le lien, et rend ce qui a été réellement fait.
     *
     * ## Ce qu'elle écrit
     *
     * - `enfant_de` : les filiations de l'enfant dans cette famille partent. Avec un
     *   `parentId` et un second époux, l'enfant est ensuite rattaché à ce dernier par
     *   {@see etablirFiliation()} — jamais par une famille créée à côté, ce qui
     *   reconstruirait les familles parentales doublées que cette méthode existe pour
     *   empêcher ;
     * - `conjoint_de` : la place de l'époux dans l'union est vidée, et l'union garde ses
     *   enfants, ses dates et ses notes ;
     * - dans les deux cas, l'union est **supprimée** si et seulement si elle ne porte plus
     *   rien — revérifié ici, dans la transaction.
     *
     * ## Les refus, qui sont des pannes et non des caducités
     *
     * La famille ou la personne a disparu, ou la mesure est bloquée : l'implémentation
     * **lève**. Le contrôle préalable a déjà rendu la demande caduque dans ces cas, et
     * arriver ici veut dire qu'un chemin l'a contourné. Elle laisse aussi remonter
     * {@see FiliationIncompatible}, que l'appelant traduit comme un ajout le fait.
     *
     * Appelée **dans la transaction** du validateur ; elle `flush()` sans en ouvrir.
     *
     * @throws \RuntimeException     si la ligne a disparu ou si le retrait est bloqué
     * @throws FiliationIncompatible si l'enfant ne peut pas rester celui de l'autre parent
     */
    public function retirerLien(LienARetirer $lien, bool $familleProtegee): LienRetire;

    /**
     * L'empreinte de l'arbre (§ 2.3 du modèle), recalculée depuis la base.
     *
     * Appelée **après** les écritures et **dans** la même transaction, pour être
     * reportée dans `prp_revision.tree_fingerprint_after`. Il n'y a rien à
     * incrémenter : les écritures l'ont déjà changée, et tous les caches qui
     * portaient l'ancienne sont déjà inatteignables. C'est aussi ce qui invalide
     * l'empreinte de l'arbre demandée au § 6.3 du dossier de mission — sans geste.
     */
    public function empreinteDeLArbre(): string;

    /**
     * Exécute toutes les écritures d'une validation **en un lot** : les colonnes dérivées
     * de l'arbre (`generation`, `branch`, `rank`) ne sont recalculées qu'**une fois**, à
     * la fin, et non après chaque geste.
     *
     * C'est ce qui fait tenir une grosse création dans le temps d'une requête. Chaque
     * geste structurant — une personne créée, une famille nouée, un enfant rattaché —
     * relisait l'arbre entier pour ce recalcul ; la demande 4178 (22 fiches, 11 unions,
     * 11 filiations) en enchaînait une quarantaine sur 2 373 fiches et tombait en `500`
     * après 30 s (04/10/2026, `MaxExecutionTimeError`).
     *
     * Le résultat est **le même** : le placement ne dépend que de la structure de l'arbre
     * à l'instant du calcul, et le dernier recalcul de la série était déjà le seul dont
     * le résultat restait. Rien, entre deux gestes d'une même validation, ne lit ces
     * colonnes.
     *
     * Appelée **dans la transaction** du validateur, comme le reste : le recalcul final
     * est `flush()`é avant de rendre la main, et l'empreinte lue ensuite le voit. Une
     * exception levée par `$ecritures` remonte telle quelle, sans recalcul — la
     * transaction est annulée de toute façon.
     *
     * @template T
     *
     * @param \Closure(): T $ecritures
     *
     * @return T
     */
    public function enUnLot(\Closure $ecritures): mixed;
}
