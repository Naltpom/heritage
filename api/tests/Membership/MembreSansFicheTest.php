<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use App\Module\Membership\Service\DeclarationDeFamille;
use PHPUnit\Framework\TestCase;

/**
 * Les règles du compte **sans fiche** qui tiennent dans l'entité et dans la validation de
 * la déclaration — sans base, sans HTTP (ADR 0013).
 *
 * La base les redit par ses `CHECK` (`ck_mbr_member_*`), que SQLite ne porte pas : c'est
 * ici qu'on vérifie que le PHP refuse le premier, et avec un message qui dit pourquoi.
 */
final class MembreSansFicheTest extends TestCase
{
    public function testLaFabriquePoseUnCompteEnAttenteSansFicheAvecSaDeclaration(): void
    {
        $membre = $this->membre();

        self::assertNull($membre->getPersonId());
        self::assertNull($membre->getPersonLabel());
        self::assertSame(MatchKind::ARattacher, $membre->getMatchKind());
        self::assertSame(MemberStatus::EnAttente, $membre->getStatus());
        self::assertTrue($membre->estARattacher());
        self::assertSame('Romarin', $membre->getDeclaredSurname());
        self::assertFalse(MatchKind::ARattacher->activeLeCompte());
        self::assertSame('Fiche à identifier', MatchKind::ARattacher->label());
    }

    public function testUnCompteSansFicheNeSActivePas(): void
    {
        $this->expectException(\LogicException::class);

        $this->membre()->activer(1);
    }

    public function testLeRattachementPoseLaFicheEtPurgeLaDeclaration(): void
    {
        $membre = $this->membre()->enregistrerLaSuggestion(['verdict' => 'absente'], new \DateTimeImmutable());
        self::assertNotNull($membre->getAttachmentSuggestion());

        $membre->rattacherA(900, 'Onyx Lointain', MatchKind::ManuelAdmin);

        self::assertSame(900, $membre->getPersonId());
        self::assertSame('Onyx Lointain', $membre->getPersonLabel());
        self::assertSame(MatchKind::ManuelAdmin, $membre->getMatchKind());
        self::assertFalse($membre->estARattacher());
        self::assertNull($membre->getDeclaredSurname());
        self::assertNull($membre->getDeclaredGivenNames());
        self::assertNull($membre->getDeclaredBirthDate());
        self::assertNull($membre->getFamilyDescription());
        self::assertNull($membre->getAttachmentSuggestion());
        self::assertNull($membre->getAttachmentSuggestionAt());

        // Désormais rattaché, il s'active.
        $membre->activer(1);
        self::assertSame(MemberStatus::Actif, $membre->getStatus());
    }

    public function testOnNeRattachePasAUneFicheEnNatureARattacher(): void
    {
        $this->expectException(\LogicException::class);

        $this->membre()->rattacherA(900, 'Onyx Lointain', MatchKind::ARattacher);
    }

    /** `ck_mbr_member_declaration` refuserait une suggestion sur un compte avec fiche : le PHP ne l'écrit pas. */
    public function testUneSuggestionNeSePosePasSurUnCompteAvecFiche(): void
    {
        $membre = new Member(3, 'cousin@example.net', 50, 'Marbre Romarin', MatchKind::RechercheConfirmee);
        $membre->enregistrerLaSuggestion(['verdict' => 'absente'], new \DateTimeImmutable());

        self::assertNull($membre->getAttachmentSuggestion());
    }

    /**
     * Un compte **refusé** reste `a_rattacher` : la nature seule ne dit pas qu'il attend.
     * Une analyse qui se termine après le refus ne doit pas y réécrire sa suggestion.
     */
    public function testUneSuggestionNeSePosePasSurUnCompteRefuse(): void
    {
        $membre = $this->membre();
        $membre->setStatus(MemberStatus::Suspendu)->purgerLaDeclaration();

        $membre->enregistrerLaSuggestion(['verdict' => 'absente'], new \DateTimeImmutable());

        self::assertSame(MatchKind::ARattacher, $membre->getMatchKind());
        self::assertNull($membre->getAttachmentSuggestion());
        self::assertNull($membre->getAttachmentSuggestionAt());
    }

    public function testUneDeclarationValideEstNettoyeeDeSesBlancs(): void
    {
        [$declaration, $erreurs] = DeclarationDeFamille::lire([
            'surname' => '  Romarin ',
            'givenNames' => ' Lave  ',
            'birthDate' => '1998-04-12',
            'description' => '  '.str_repeat('Mon père est Pyrite Romarin. ', 3).'  ',
        ], new \DateTimeImmutable('2026-10-01'));

        self::assertSame([], $erreurs);
        self::assertNotNull($declaration);
        self::assertSame('Romarin', $declaration->nom);
        self::assertSame('Lave', $declaration->prenoms);
        self::assertSame('1998-04-12', $declaration->naissance->format('Y-m-d'));
        self::assertStringStartsWith('Mon père', $declaration->description);
    }

    /**
     * La longueur de la description se compte **après** nettoyage des blancs : soixante
     * espaces ne décrivent aucune famille.
     */
    public function testLaDescriptionSeMesureSansSesBlancs(): void
    {
        [, $erreurs] = DeclarationDeFamille::lire([
            'surname' => 'Romarin',
            'givenNames' => 'Lave',
            'birthDate' => '1998-04-12',
            'description' => str_repeat(' ', 80).'Court.'.str_repeat(' ', 80),
        ]);

        self::assertSame(['declaration.description'], array_keys($erreurs));

        [, $tropLong] = DeclarationDeFamille::lire([
            'surname' => 'Romarin',
            'givenNames' => 'Lave',
            'birthDate' => '1998-04-12',
            'description' => str_repeat('a', DeclarationDeFamille::DESCRIPTION_MAX + 1),
        ]);

        self::assertSame(['declaration.description'], array_keys($tropLong));
    }

    public function testUnBlocQuiNEstPasUnObjetEstRefuse(): void
    {
        [$declaration, $erreurs] = DeclarationDeFamille::lire('Mon père est Jean.');

        self::assertNull($declaration);
        self::assertSame(['declaration'], array_keys($erreurs));
    }

    public function testLaDateDuJourEstAcceptee(): void
    {
        [$declaration] = DeclarationDeFamille::lire([
            'surname' => 'Romarin',
            'givenNames' => 'Lave',
            'birthDate' => '2026-10-01',
            'description' => str_repeat('Ma mère est Cobalt Cassis. ', 3),
        ], new \DateTimeImmutable('2026-10-01 18:00'));

        self::assertNotNull($declaration);
    }

    private function membre(): Member
    {
        return Member::aRattacher(
            22,
            'lave@example.net',
            'Romarin',
            'Lave',
            new \DateTimeImmutable('1998-04-12'),
            'Mon père est Pyrite Romarin, né en 1956 ; ma mère Cobalt Cassis.',
        );
    }
}
