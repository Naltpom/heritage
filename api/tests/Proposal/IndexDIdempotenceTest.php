<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestKind;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Les colonnes et l'index du rejeu d'un dépôt (ADR 0022), sur le SQLite des tests.
 *
 * SQLite ne pose pas d'index partiel : DBAL ignore le `where` et crée un index unique
 * complet, où les `NULL` restent distincts. Ce que ces tests affirment est donc le
 * comportement observable, identique sur les deux moteurs. Le `CHECK` et l'instruction
 * exacte de l'index sont éprouvés sur PostgreSQL par `IndexDIdempotencePostgresTest`.
 */
final class IndexDIdempotenceTest extends BaseDeDemandes
{
    private const string CLE = 'cle-de-test-0123456789';

    public function testUneDemandeSansCleNePorteNiCleNiEmpreinte(): void
    {
        $id = $this->enregistrer(self::OPALE, null)->getId();
        $this->nouvelleRequete();

        $relue = $this->demandes->find($id);

        self::assertNotNull($relue);
        self::assertNull($relue->getIdempotencyKey());
        self::assertNull($relue->getIdempotencyFingerprint());
    }

    public function testLaCleEtSonEmpreinteSeRelisentApresEnregistrement(): void
    {
        $id = $this->enregistrer(self::OPALE, self::CLE)->getId();
        $this->nouvelleRequete();

        $relue = $this->demandes->find($id);

        self::assertNotNull($relue);
        self::assertSame(self::CLE, $relue->getIdempotencyKey());
        self::assertSame(hash('sha256', self::CLE), $relue->getIdempotencyFingerprint());
    }

    public function testLaCleNeSePoseQuUneFois(): void
    {
        $demande = $this->enregistrer(self::OPALE, self::CLE);

        $this->expectException(\LogicException::class);

        $demande->poserLaCleDIdempotence('une-autre-cle-0123456789', hash('sha256', 'autre'));
    }

    public function testParCleDIdempotenceNeTrouveQueLaDemandeDeSonAuteur(): void
    {
        $id = $this->enregistrer(self::OPALE, self::CLE)->getId();
        $this->nouvelleRequete();

        self::assertSame($id, $this->demandes->parCleDIdempotence(self::OPALE, self::CLE)?->getId());
        self::assertNull($this->demandes->parCleDIdempotence(self::TUF, self::CLE));
        self::assertNull($this->demandes->parCleDIdempotence(self::OPALE, 'une-autre-cle-0123456789'));
    }

    public function testIdParCleDIdempotenceRepondParLaConnexion(): void
    {
        $id = $this->enregistrer(self::OPALE, self::CLE)->getId();

        self::assertSame($id, $this->demandes->idParCleDIdempotence(self::OPALE, self::CLE));
        self::assertNull($this->demandes->idParCleDIdempotence(self::TUF, self::CLE));
    }

    public function testDeuxDemandesSansCleDuMemeAuteurCoexistent(): void
    {
        $premiere = $this->enregistrer(self::OPALE, null);
        $seconde = $this->enregistrer(self::OPALE, null);

        self::assertNotSame($premiere->getId(), $seconde->getId());
    }

    public function testLaMemeCleChezDeuxAuteursEstAdmise(): void
    {
        $premiere = $this->enregistrer(self::OPALE, self::CLE);
        $seconde = $this->enregistrer(self::TUF, self::CLE);

        self::assertNotSame($premiere->getId(), $seconde->getId());
    }

    public function testLaMemeCleDeuxFoisChezLeMemeAuteurEstRefuseeParLaBase(): void
    {
        $this->enregistrer(self::OPALE, self::CLE);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->enregistrer(self::OPALE, self::CLE);
    }

    private function enregistrer(int $auteur, ?string $cle): ChangeRequest
    {
        $demande = new ChangeRequest(RequestKind::AjoutNote, $auteur, 'Compte '.$auteur);

        if (null !== $cle) {
            $demande->poserLaCleDIdempotence($cle, hash('sha256', $cle));
        }

        $this->em->persist($demande);
        $this->em->flush();

        return $demande;
    }
}
