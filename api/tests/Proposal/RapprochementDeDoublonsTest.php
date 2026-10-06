<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Sex;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Service\IdentiteComparee;
use App\Module\Proposal\Service\RapprochementDeDoublons;
use PHPUnit\Framework\TestCase;

/**
 * La règle du rapprochement d'avant dépôt, sans base ni noyau : ce qui rapproche, ce qui
 * écarte, et surtout ce qui **ne prouve rien** — une date inconnue, un nom absent.
 */
final class RapprochementDeDoublonsTest extends TestCase
{
    private RapprochementDeDoublons $regle;

    protected function setUp(): void
    {
        $this->regle = new RapprochementDeDoublons();
    }

    public function testLesAccentsEtLeSecondPrenomNeSeparentPas(): void
    {
        $resultat = $this->regle->comparer(
            $this->personne('Marne', 'SUREAU', naissance: $this->annee(1905)),
            $this->personne('Marne Dolomite', 'Sureau', naissance: $this->entre(1901, 1909)),
        );

        self::assertNotNull($resultat);
        self::assertSame(['nom', 'prenom', 'naissance', 'sexe'], $resultat->criteres);
    }

    public function testDeuxAnneesConnuesQuiNeSeRecouvrentPasEcartent(): void
    {
        self::assertNull($this->regle->comparer(
            $this->personne('Barnabé', 'Muguet', naissance: $this->annee(1931)),
            $this->personne('Barnabé', 'Muguet', naissance: $this->annee(1901)),
        ));
    }

    public function testUnVersSElargitDeDeuxAns(): void
    {
        $vers = new UncertainDate('ABT 1901', DateQualifier::Environ, new \DateTimeImmutable('1901-01-01'), new \DateTimeImmutable('1901-12-31'));

        self::assertNotNull($this->regle->comparer($this->personne('Barnabé', 'Muguet', naissance: $this->annee(1903)), $this->personne('Barnabé', 'Muguet', naissance: $vers)));
        self::assertNull($this->regle->comparer($this->personne('Barnabé', 'Muguet', naissance: $this->annee(1904)), $this->personne('Barnabé', 'Muguet', naissance: $vers)));
    }

    public function testUnSexeConnuEtDifferentEcarte(): void
    {
        self::assertNull($this->regle->comparer(
            $this->personne('Camille', 'Muguet', Sex::Masculin),
            $this->personne('Camille', 'Muguet', Sex::Feminin),
        ));
    }

    public function testLeNomDUsageRapprocheUneEpouse(): void
    {
        $resultat = $this->regle->comparer(
            $this->personne('Tourmaline', 'Sarriette', Sex::Feminin),
            new IdentiteComparee('Tourmaline', 'Muguet', 'Sarriette', Sex::Feminin, UncertainDate::inconnue(), UncertainDate::inconnue()),
        );

        self::assertNotNull($resultat);
        self::assertContains('nom', $resultat->criteres);
    }

    public function testLePrenomSeulNeSuffitPasSansAppui(): void
    {
        self::assertNull($this->regle->comparer($this->personne('Marne', ''), $this->personne('Marne', 'Sureau')));

        $avecParentCommun = $this->regle->comparer(
            new IdentiteComparee('Marne', '', null, Sex::Inconnu, UncertainDate::inconnue(), UncertainDate::inconnue(), parents: [55]),
            new IdentiteComparee('Marne', 'Sureau', null, Sex::Inconnu, UncertainDate::inconnue(), UncertainDate::inconnue(), parents: [55, 56]),
        );
        self::assertNotNull($avecParentCommun);
        self::assertSame(['prenom', 'parents'], $avecParentCommun->criteres);
    }

    public function testUnNomDifferentEcarteMemeAvecLeMemePrenom(): void
    {
        self::assertNull($this->regle->comparer($this->personne('Marne', 'Sureau'), $this->personne('Marne', 'Tilleul')));
    }

    private function personne(string $prenoms, string $nom, Sex $sexe = Sex::Feminin, ?UncertainDate $naissance = null): IdentiteComparee
    {
        return new IdentiteComparee($prenoms, $nom, null, $sexe, $naissance ?? UncertainDate::inconnue(), UncertainDate::inconnue());
    }

    private function annee(int $annee): UncertainDate
    {
        return new UncertainDate((string) $annee, DateQualifier::Annee, new \DateTimeImmutable($annee.'-01-01'), new \DateTimeImmutable($annee.'-12-31'));
    }

    private function entre(int $debut, int $fin): UncertainDate
    {
        return new UncertainDate(\sprintf('BET %d AND %d', $debut, $fin), DateQualifier::Entre, new \DateTimeImmutable($debut.'-01-01'), new \DateTimeImmutable($fin.'-12-31'));
    }
}
