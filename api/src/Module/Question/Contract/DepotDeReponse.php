<?php

declare(strict_types=1);

namespace App\Module\Question\Contract;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * POINT DE BRANCHEMENT DU MODULE `Proposal` — **la seule sortie en écriture de ce
 * module, et elle ne touche pas l'arbre**.
 *
 * Une réponse de membre n'écrit **jamais** dans `tree_person`. Elle crée une
 * `ChangeRequest` portant des `ChangeRequestOp`, elle entre dans la file, et
 * l'administrateur tranche (ADR 0004). Ce port est ce qui garantit qu'aucun chemin
 * d'écriture nouveau n'est apparu : l'adaptateur appelle `Proposal\Service\DepotDeDemande`,
 * c'est-à-dire **exactement le service que `POST /api/demandes` appelle**, avec les
 * mêmes contrôles, le même verrou optimiste et la même file.
 *
 * *Si l'on se surprend à écrire ici une méthode qui modifie l'arbre, on s'est trompé de
 * route.*
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ## L'auteur n'est pas un paramètre, et c'est le même motif que le cercle
 *
 * Aucune de ces méthodes ne prend d'identifiant d'auteur : l'adaptateur résout le
 * lecteur lui-même, depuis la session, par la fabrique de `Proposal`. C'est le choix
 * qu'a déjà fait `Core\Security\CercleDeVisibiliteInterface`, et pour la même raison :
 * *un auteur passé par chaque appelant est un auteur qu'un appelant finira par
 * construire de travers.*
 *
 * Trois garde-fous viennent gratuitement avec ce choix, et aucun n'est à réécrire ici :
 * un compte en attente de rattachement ne contribue pas ; la bascule « voir comme un
 * membre » interdit toute écriture tant qu'elle est active ; et `author_label` est
 * l'instantané que `Proposal` sait figer.
 *
 * ## Trois méthodes, trois natures de demande, aucune de plus
 *
 * | Méthode | Nature | Quand |
 * |---|---|---|
 * | {@see corrigerUnChamp()} | `modification_personne` | la réponse **est** la valeur : un prénom, un nom |
 * | {@see annoterUneFiche()} | `ajout_note` | tout le reste du texte libre |
 * | {@see joindreUnMedia()} | `ajout_media` | une photo |
 *
 * **Pourquoi si peu de champs passent par la première.** Parce qu'une réponse en texte
 * libre n'est pas une valeur validée. « vers 1923 », « juste avant la guerre », « l'été
 * 38 » : un bloc date porte cinq valeurs solidaires, et les deviner depuis une phrase
 * fabriquerait une date ferme à partir d'une fourchette — l'aplatissement que le § 9.1
 * du modèle interdit, et le bogue qui faisait naître une aïeule après ses propres
 * enfants. Les lieux ont le même défaut : `place` vit **dans** le bloc date.
 *
 * La réponse devient donc une note, que l'administrateur lit avant de saisir la valeur.
 * Ce n'est pas un pis-aller, c'est le mécanisme de l'ADR 0004 : « quelqu'un qui regarde
 * un diff et demande sur quoi tu t'appuies ? ». La page apporte le renseignement ; la
 * mise en forme reste au validateur.
 *
 * ## Ce que l'adaptateur doit faire et que ce module ne peut pas faire
 *
 * Une `modification_personne` exige `oldValue`, `valueKind` et `baseVersion` (§ 6.2 du
 * contrat d'API), qui se lisent **sur la fiche courante**. L'adaptateur les lit lui-même
 * par le port que `Proposal` possède déjà. Les faire traverser ce contrat aurait obligé
 * `Question` à connaître le vocabulaire de `ValueKind` et à relire la version de la
 * ligne — c'est-à-dire à recopier la moitié du constructeur de modification, qui est
 * exactement le mécanisme par lequel une frontière se dissout.
 *
 * ## Une méthode, une demande — et une réponse peut en produire deux
 *
 * Un membre peut répondre « à Brest » **et** déposer la photo de l'acte. Ce sont deux
 * natures, donc deux appels, donc deux demandes — et il **faut** qu'elles soient deux :
 * l'administrateur doit pouvoir accepter la photo et refuser le lieu. Les fondre ferait
 * de la file un tout-ou-rien sur des faits sans rapport. C'est pour cela que
 * `qst_question_demande` est une table et non une colonne.
 *
 * ## Le repli
 *
 * {@see \App\Module\Question\Service\Port\DepotNonBranche} **lève** à chaque appel. Il
 * n'y a pas de repli silencieux possible ici : une réponse qu'on ferait semblant
 * d'enregistrer serait une contribution perdue sans que personne ne l'apprenne, ce qui
 * est le seul défaut de ce chantier qui ne se rattrape pas.
 */
interface DepotDeReponse
{
    /**
     * `modification_personne` — un op de champ, sur la surface éditable.
     *
     * @param string $champ   un nom de la surface éditable de `Proposal`, tel que
     *                        {@see \App\Module\Question\Enum\NatureDeLacune::champ()} le
     *                        rend. L'adaptateur **lève** si le nom n'y figure pas :
     *                        c'est la table de correspondance explicite du § 9.4 du
     *                        modèle, jamais une résolution par réflexion
     * @param string $valeur  la réponse du membre, verbatim
     * @param string $message le mot qui accompagne la demande dans la file — ici, la
     *                        question posée, pour que l'administrateur voie **à quoi**
     *                        cette valeur répond. Sans elle, il lit « Prénoms : ∅ →
     *                        Alberte » sans savoir d'où cela sort
     *
     * @throws DepotImpossible refus du circuit de validation, message destiné au membre
     */
    public function corrigerUnChamp(int $personneId, string $champ, string $valeur, string $message): ReponseDeposee;

    /**
     * `ajout_note` — « les lignes les plus précieuses du fichier » (§ 3.5 du modèle).
     *
     * @param string $corps le texte de la note : la question **et** la réponse, parce
     *                      qu'une note qui ne porterait que « à Brest » serait
     *                      illisible dans six mois
     *
     * @throws DepotImpossible
     */
    public function annoterUneFiche(int $personneId, string $corps, string $message): ReponseDeposee;

    /**
     * `ajout_media` — le média a **déjà été téléversé** par `POST /api/medias`, et il
     * est en attente.
     *
     * Ce port ne reçoit donc aucun octet : le téléversement, la purge des métadonnées
     * EXIF, la vignette et l'empreinte appartiennent à `Media` et y restent. Ce qu'on
     * demande ici, c'est de rattacher une ligne existante à une fiche.
     *
     * @throws DepotImpossible notamment quand le média n'appartient pas au membre, ou
     *                         qu'il a déjà été traité
     */
    public function joindreUnMedia(int $personneId, int $mediaId, string $message): ReponseDeposee;
}
