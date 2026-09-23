<?php

declare(strict_types=1);

namespace App\Module\Question\Service;

use App\Module\Question\Contract\DepotDeReponse;
use App\Module\Question\Contract\ReponseDeposee;
use App\Module\Question\Entity\Question;
use App\Module\Question\Entity\QuestionDemande;
use App\Module\Question\Exception\QuestionDejaTranchee;
use App\Module\Question\Exception\ReponseInvalide;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ce qu'un membre fait d'une question : il ne sait pas, il se renseignera, ou il répond.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  AUCUNE DE CES TROIS MÉTHODES N'ÉCRIT DANS `tree_*`.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Les deux premières n'écrivent que `qst_question`. La troisième dépose dans la file de
 * l'ADR 0004 **par un port**, que `Proposal` implémente en appelant exactement le service
 * que `POST /api/demandes` appelle — mêmes contrôles, même verrou optimiste, même file.
 * *Si l'on se surprend à écrire ici un `INSERT` vers l'arbre, on s'est trompé de route.*
 *
 * ── Les trois gestes, et ce que chacun coûterait s'il manquait ──────────────────
 *
 * | geste | écrit | sans lui |
 * |---|---|---|
 * | {@see jeNeSaisPas()} | l'état | la même question revient à chaque chargement, et la page devient un mur |
 * | {@see plusTard()} | l'état + une relance | la promesse la plus probable de toutes est perdue |
 * | {@see repondre()} | 1 ou 2 demandes | — |
 *
 * ── Pourquoi une transaction ────────────────────────────────────────────────────
 *
 * Une réponse peut produire **deux** demandes — le fait en note, la photo de l'acte en
 * média. Si la seconde échoue après la première, on obtiendrait une question `repondue`
 * dont la moitié du travail seulement est dans la file, sans rien pour le signaler : le
 * membre a vu sa confirmation, l'administrateur ne voit qu'une pièce, et personne ne sait
 * qu'il en manque une. On enveloppe donc le tout — les deux dépôts **et** le changement
 * d'état — dans une transaction unique.
 */
final readonly class ReponseAUneQuestion
{
    /**
     * `tree_note.body` n'a pas de borne en base ; celle-ci est généreuse et n'existe que
     * pour écarter un corps de requête qui ne serait pas une réponse du tout. Même ordre
     * de grandeur que `ConstructeurDAjoutDeNote`.
     */
    private const int LONGUEUR_MAXIMALE = 20000;

    public function __construct(
        private DepotDeReponse $depot,
        private ReglagesDesQuestions $reglages,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * « Je ne sais pas » — **définitif, et rien n'est écrit dans l'arbre**.
     *
     * C'est pourtant une réponse, et elle est horodatée : sans cela, rien ne
     * distinguerait « personne n'a jamais regardé cette question » de « quelqu'un a
     * regardé et ne savait pas », qui sont deux renseignements opposés pour qui cherche
     * où chercher.
     */
    public function jeNeSaisPas(Question $question): Question
    {
        $this->refuserSiDejaTranchee($question);

        $question->fermerFauteDeSavoir();
        $this->em->flush();

        return $question;
    }

    /** « Je vais me renseigner » — elle revient, et de plus en plus tard. */
    public function plusTard(Question $question, \DateTimeImmutable $maintenant): Question
    {
        $this->refuserSiDejaTranchee($question);

        $question->differer($this->reglages->prochaineRelance($maintenant, $question->getReports()));
        $this->em->flush();

        return $question;
    }

    /**
     * Une réponse : du texte, une photo, ou les deux.
     *
     * @param int|null $mediaId un média **déjà téléversé** par `POST /api/medias`, et
     *                          en attente. Aucun octet ne passe par ici : le
     *                          téléversement, la purge EXIF, la vignette et l'empreinte
     *                          appartiennent à `Media` et y restent
     *
     * @throws ReponseInvalide                               corps vide, ou texte hors bornes
     * @throws \App\Module\Question\Contract\DepotImpossible refus du circuit de validation
     */
    public function repondre(Question $question, ?string $texte, ?int $mediaId): Question
    {
        $this->refuserSiDejaTranchee($question);

        $texte = $this->texteNettoye($texte);

        // **Une réponse vide n'est pas « je ne sais pas ».** Il y a un bouton pour ça, et
        // les deux ne veulent pas dire la même chose. L'accepter en silence marquerait la
        // question `repondue` sans rien déposer : le membre croirait avoir contribué,
        // l'administrateur ne verrait rien, et la question ne reviendrait jamais.
        if (null === $texte && null === $mediaId) {
            throw ReponseInvalide::vide();
        }

        if (null !== $mediaId && $mediaId < 1) {
            throw ReponseInvalide::pourChamp('mediaId', 'Identifiant de média attendu.');
        }

        $this->em->wrapInTransaction(function () use ($question, $texte, $mediaId): void {
            foreach ($this->deposer($question, $texte, $mediaId) as $deposee) {
                $this->em->persist(new QuestionDemande($question, $deposee->demandeId, $deposee->nature));
            }

            $question->marquerRepondue($texte);
        });

        return $question;
    }

    /**
     * Les dépôts, dans l'ordre : **le texte d'abord, la photo ensuite**.
     *
     * L'ordre n'est pas indifférent quand l'un des deux échoue : le texte est ce que le
     * membre a écrit de sa main et ne se retrouve nulle part ailleurs, tandis qu'un média
     * refusé reste dans sa galerie, déposé et récupérable. La transaction annule tout de
     * toute façon — mais si elle venait à sauter, c'est le texte qu'on veut avoir tenté
     * en premier.
     *
     * @return list<ReponseDeposee>
     */
    private function deposer(Question $question, ?string $texte, ?int $mediaId): array
    {
        $deposees = [];
        $personneId = $question->getSujetId();

        // Le mot qui accompagne la demande dans la file, c'est **la question elle-même** :
        // sans elle, l'administrateur lit « Prénoms : ∅ → Alberte » sans savoir d'où cela
        // sort, et ne peut pas juger si la réponse répond bien à ce qui était demandé.
        $message = $question->getTexte();

        if (null !== $texte) {
            $champ = $question->getNature()->champ();

            $deposees[] = null !== $champ
                ? $this->depot->corrigerUnChamp($personneId, $champ, $texte, $message)
                : $this->depot->annoterUneFiche($personneId, $this->corpsDeLaNote($question, $texte), $message);
        }

        if (null !== $mediaId) {
            $deposees[] = $this->depot->joindreUnMedia($personneId, $mediaId, $message);
        }

        return $deposees;
    }

    /**
     * **La note porte la question ET la réponse.**.
     *
     * Une note qui ne contiendrait que « à Quimper » serait illisible dans six mois :
     * personne ne saurait de quel événement il s'agit. Les notes sont « les lignes les
     * plus précieuses du fichier » (§ 3.5 du modèle) précisément parce qu'elles gardent
     * le raisonnement, pas seulement la conclusion.
     *
     * On nomme aussi l'auteur de la réponse ? **Non** : `prp_change_request.author_label`
     * le porte déjà, et le recopier dans le corps le figerait une seconde fois, dans une
     * colonne que personne ne saurait rafraîchir.
     */
    private function corpsDeLaNote(Question $question, string $texte): string
    {
        return $question->getTexte()."\n\n".$texte;
    }

    private function texteNettoye(?string $texte): ?string
    {
        if (null === $texte) {
            return null;
        }

        $propre = trim($texte);

        if ('' === $propre) {
            return null;
        }

        if (mb_strlen($propre) > self::LONGUEUR_MAXIMALE) {
            throw ReponseInvalide::pourChamp('texte', 'Cette réponse est trop longue.');
        }

        return $propre;
    }

    /**
     * Une question tranchée ne se répond plus.
     *
     * `409` et non `422` : ce n'est pas la réponse qui est mauvaise, c'est le monde qui a
     * bougé — un second onglet, un double envoi. Le conseil est « rechargez », pas
     * « corrigez ».
     */
    private function refuserSiDejaTranchee(Question $question): void
    {
        if ($question->getEtat()->estDefinitif()) {
            throw QuestionDejaTranchee::pour($question->getEtat());
        }
    }
}
