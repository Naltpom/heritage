<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\DraftPerson;
use App\Module\Proposal\Repository\DraftLinkRepository;
use App\Module\Proposal\Repository\DraftPersonRepository;
use App\Module\Proposal\Service\CompletionDeProposition;
use App\Module\Proposal\Service\EmpreinteDeDemande;
use App\Module\Proposal\Service\FusionDeComplement;

/**
 * Le socle des trois classes de la complétion (ADR 0017) : compléter sur place, déposer un
 * complément, le fusionner.
 *
 * ── Le scénario, une fois pour les trois fichiers ───────────────────────────────
 *
 * **Opale** propose **Barnabé Muguet**, époux de Jargon Sarriette (`p55`, qui existe). Ce
 * qu'il advient ensuite de la proposition dépend de qui la touche :
 *
 * - Opale elle-même, ou l'administrateur, la **complètent sur place** ;
 * - **Tuf**, un autre membre, y reconnaît son arrière-grand-père et dépose un
 *   **complément** — sa propre fiche de Barnabé, avec ce qu'il sait de plus ;
 * - l'administrateur **fusionne** le complément dans la proposition d'Opale.
 *
 * Barnabé est **vivant** par défaut : c'est le cas qui protège le plus — la forme discrète
 * de la ressemblance, les libellés qu'on ne recopie pas chez un autre auteur. Les cas qui
 * ont besoin d'un défunt le disent.
 */
abstract class BaseDeCompletion extends BaseDeDemandes
{
    /** L'époux d'accueil : il existe dans l'arbre validé. */
    protected const int JARGON = 55;

    /** Un enfant existant, pour les liens qu'un complément ajoute. */
    protected const int ENFANT = 61;

    protected function setUp(): void
    {
        parent::setUp();

        $this->arbre
            ->poser(SubjectKind::Personne, self::JARGON, 'Jargon Sarriette', 3, $this->champsDUnePersonne('Jargon', 'Sarriette'))
            ->poser(SubjectKind::Personne, self::ENFANT, 'Lave Sarriette', 1, $this->champsDUnePersonne('Lave', 'Sarriette'));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Les services
    // ─────────────────────────────────────────────────────────────────────────────

    protected function completion(): CompletionDeProposition
    {
        return self::getContainer()->get(CompletionDeProposition::class);
    }

    protected function fusion(): FusionDeComplement
    {
        return self::getContainer()->get(FusionDeComplement::class);
    }

    protected function empreinte(ChangeRequest $demande): string
    {
        return self::getContainer()->get(EmpreinteDeDemande::class)->de($demande);
    }

    protected function fiches(): DraftPersonRepository
    {
        return self::getContainer()->get(DraftPersonRepository::class);
    }

    protected function liensEnBase(): DraftLinkRepository
    {
        return self::getContainer()->get(DraftLinkRepository::class);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Les dépôts
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Une proposition de Barnabé, époux de Jargon.
     *
     * @param array<string, mixed>            $fiche      ce qui change de la fiche par défaut
     * @param list<array<string, mixed>>|null $liens
     */
    protected function proposer(
        int $auteur,
        array $fiche = [],
        ?array $liens = null,
        bool $soumettre = true,
        ?int $complementDe = null,
        ?string $message = 'mon arrière-grand-père',
    ): ChangeRequest {
        $corps = [
            'kind' => 'creation_personne',
            'message' => $message,
            'soumettre' => $soumettre,
            'brouillons' => [$this->fiche($fiche)],
            'liens' => $liens ?? [$this->lienConjoint()],
        ];

        if (null !== $complementDe) {
            $corps['complementDe'] = $complementDe;
        }

        return $this->depot->deposer($corps, $this->lecteur($auteur));
    }

    /**
     * Tuf complète la proposition `$origine` : sa propre fiche de Barnabé.
     *
     * @param array<string, mixed>            $fiche
     * @param list<array<string, mixed>>|null $liens
     */
    protected function completerPar(
        int $auteur,
        ChangeRequest $origine,
        array $fiche = [],
        ?array $liens = null,
        ?string $message = 'c\'est aussi mon arrière-grand-père',
    ): ChangeRequest {
        return $this->proposer($auteur, $fiche, $liens, true, $this->ficheDe($origine)->getId(), $message);
    }

    /**
     * La fiche par défaut : Barnabé Muguet, **vivant**, sexe inconnu, sans date.
     *
     * @param array<string, mixed> $surcharges
     *
     * @return array<string, mixed>
     */
    protected function fiche(array $surcharges = []): array
    {
        return array_replace([
            'localRef' => '#p1',
            'givenNames' => 'Barnabé',
            'surname' => 'Muguet',
            'marriedName' => null,
            'nickname' => null,
            'sex' => 'inconnu',
            'deceased' => false,
            'birth' => null,
            'death' => null,
            'note' => null,
        ], $surcharges);
    }

    /** @return array<string, mixed> */
    protected function lienConjoint(?int $seq = 1, string $sujet = '#p1'): array
    {
        $lien = [
            'kind' => 'conjoint_de',
            'subjectDraftRef' => $sujet,
            'otherPersonId' => self::JARGON,
            'divorced' => false,
        ];

        return null === $seq ? $lien : ['seq' => $seq] + $lien;
    }

    /** @return array<string, mixed> */
    protected function lienParent(?int $seq, int $enfant = self::ENFANT, string $sujet = '#p1'): array
    {
        $lien = ['kind' => 'parent_de', 'subjectDraftRef' => $sujet, 'otherPersonId' => $enfant];

        return null === $seq ? $lien : ['seq' => $seq] + $lien;
    }

    /** La fiche unique (ou la première) d'une demande, relue en base. */
    protected function ficheDe(ChangeRequest $demande): DraftPerson
    {
        $fiche = $this->fiches()->pourDemande($demande)[0] ?? null;
        self::assertInstanceOf(DraftPerson::class, $fiche, 'La demande doit porter une fiche.');

        return $fiche;
    }

    /**
     * Recule `updated_at` d'une demande d'une heure, **en base**.
     *
     * Les cas qui vérifient qu'une écriture **touche** une demande en ont besoin : la
     * colonne est un `TIMESTAMP(0)`, et un dépôt suivi d'une complétion dans la même
     * seconde laisserait l'horodatage inchangé — le test passerait au vert sans rien
     * prouver.
     */
    protected function vieillir(ChangeRequest $demande): ChangeRequest
    {
        $connexion = $this->em->getConnection();
        $connexion->executeStatement(
            'UPDATE prp_change_request SET updated_at = :avant WHERE id = :id',
            [
                // Le format de la plateforme, pas le nôtre : SQLite range un `datetimetz` sans
                // décalage, et un `+00:00` de trop rendrait la ligne illisible par Doctrine.
                'avant' => (new \DateTimeImmutable('-1 hour'))->format($connexion->getDatabasePlatform()->getDateTimeTzFormatString()),
                'id' => $demande->getId(),
            ],
        );

        return $this->recharger($demande);
    }

    /**
     * Une pièce jointe à la fiche `#p1` d'une demande, telle que `POST /api/medias` la pose.
     *
     * `BaseDeDemandes` ne vide que les tables `prp_*` : le cas qui en pose une la retire
     * lui-même ({@see oublierLesMedias()}).
     */
    protected function joindreUnePiece(ChangeRequest $demande, int $deposant): Media
    {
        $media = new Media(
            MediaKind::Preuve,
            MediaSubject::Personne,
            'Personne proposée',
            'preuve-'.bin2hex(random_bytes(4)).'.jpg',
            'image/jpeg',
            91,
            hash('sha256', random_bytes(16)),
            $deposant,
            'Compte '.$deposant,
        );
        $media->rattacherAuBrouillon('p1', (int) $demande->getId());

        $this->em->persist($media);
        $this->em->flush();

        return $media;
    }

    protected function oublierLesMedias(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM med_media');
    }

    protected function admin(): Lecteur
    {
        return new Lecteur(self::ADMIN, 'Natron Romarin', administrateur: true);
    }

    /** @return array<string, mixed> */
    protected function champsDUnePersonne(string $prenom, string $nom): array
    {
        return [
            'givenNames' => $prenom,
            'surname' => $nom,
            'marriedName' => null,
            'nickname' => null,
            'sex' => 'masculin',
            'deceased' => true,
            'birth' => UncertainDate::inconnue(),
            'death' => UncertainDate::inconnue(),
            'forcePrivacy' => false,
            'privacyNote' => null,
        ];
    }
}
