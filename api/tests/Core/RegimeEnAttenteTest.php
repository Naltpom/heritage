<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Contract\SessionPayloadContributorInterface;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\Regime;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Security\RegimeVoter;
use App\Module\Core\Service\SessionPayloadBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Le **régime distinct** du compte en attente de validation.
 *
 * Ce qui est vérifié ici n'est pas cosmétique : c'est que le socle ne peut pas rendre, ni
 * par défaut ni par la faute d'un module branché dessus, le moindre élément de personne
 * vivante à un compte dont le rattachement n'est pas établi — « y compris celle à
 * laquelle il prétend se rattacher » (§ 12.1 du contrat, ADR 0003).
 *
 * Test unitaire et non fonctionnel : il n'a besoin ni de base ni de requête, et il porte
 * sur la composition de la charge — l'endroit exact où la règle se perd si on la perd.
 */
final class RegimeEnAttenteTest extends TestCase
{
    public function testLeRegimeNEstPasUnCercleAvecNZero(): void
    {
        // La distinction est portée par une énumération, pas par un entier : il n'y a
        // aucune valeur de `N` qui produise le régime « aucun », et c'est le point.
        self::assertSame(['aucun', 'cercle'], array_column(Regime::cases(), 'value'));
    }

    public function testUnCompteEnAttenteNAPasDeCercle(): void
    {
        $charge = (new SessionPayloadBuilder([]))->construire($this->compte(UserStatus::EnAttente));

        self::assertSame(['regime' => 'aucun'], $charge['cercle']);
        // `generations` et `taille` sont **absents**, pas à zéro : le front branche sur
        // `regime`, et lire `taille` doit rendre `undefined`.
        self::assertArrayNotHasKey('generations', $charge['cercle']);
        self::assertArrayNotHasKey('taille', $charge['cercle']);
    }

    /**
     * Le filet du socle : même un contributeur `Membership` écrit sans y penser — et il
     * y en aura un, puisque le `mbr_member` existe dès l'inscription, pré-lié — ne peut
     * pas ouvrir la fiche réclamée à un compte en attente.
     */
    public function testUnContributeurNePeutPasOuvrirLeRegimeEnAttente(): void
    {
        $bavard = new class implements SessionPayloadContributorInterface {
            public function contribuer(User $utilisateur): array
            {
                return [
                    'membre' => [
                        'id' => 11,
                        'status' => 'en_attente',
                        'personId' => 210,
                        'personLabel' => 'Pyrite Romarin',
                        'matchKind' => 'recherche_confirmee',
                        'activatedAt' => null,
                    ],
                    'cercle' => ['regime' => 'cercle', 'generations' => 3, 'taille' => 34],
                ];
            }
        };

        $charge = (new SessionPayloadBuilder([$bavard]))->construire($this->compte(UserStatus::EnAttente));

        self::assertSame(['regime' => 'aucun'], $charge['cercle']);
        self::assertIsArray($charge['membre']);
        self::assertArrayNotHasKey('personId', $charge['membre']);
        self::assertArrayNotHasKey('personLabel', $charge['membre']);
        // Ce qui n'identifie personne reste : l'écran d'attente en a besoin.
        self::assertSame('en_attente', $charge['membre']['status']);

        self::assertStringNotContainsString(
            'Pyrite Romarin',
            json_encode($charge, \JSON_THROW_ON_ERROR),
            'Le nom de la personne réclamée ne sort pas avant activation.',
        );
    }

    public function testUnCompteActifRecoitCeQueSonModuleLuiDonne(): void
    {
        $contributeur = new class implements SessionPayloadContributorInterface {
            public function contribuer(User $utilisateur): array
            {
                return ['cercle' => ['regime' => 'cercle', 'generations' => 3, 'taille' => 34]];
            }
        };

        $charge = (new SessionPayloadBuilder([$contributeur]))->construire($this->compte(UserStatus::Actif));

        self::assertSame(['regime' => 'cercle', 'generations' => 3, 'taille' => 34], $charge['cercle']);
    }

    /**
     * Le défaut du socle est **fermé** : sans contributeur branché — ou si `Membership`
     * tombe — personne n'obtient de cercle. On dégrade vers moins de visibilité.
     */
    public function testLeDefautDuSocleEstFerme(): void
    {
        $charge = (new SessionPayloadBuilder([]))->construire($this->compte(UserStatus::Actif));

        self::assertSame(['regime' => 'aucun'], $charge['cercle']);
        self::assertNull($charge['membre']);
        self::assertNull($charge['voirComme']);
        self::assertSame([], $charge['demandesEnAttente']);
    }

    public function testLeNomAfficheRetombeSurLeCourrielSansRattachementValide(): void
    {
        $charge = (new SessionPayloadBuilder([]))->construire($this->compte(UserStatus::EnAttente));

        self::assertSame('cousin.saule@example.net', $charge['utilisateur']['displayName']);
        self::assertSame('en_attente', $charge['utilisateur']['status']);
        self::assertSame(['ROLE_USER'], $charge['utilisateur']['roles']);
    }

    public function testLaCorrespondanceStatutRegimeNEstDefinieQuUneFois(): void
    {
        self::assertSame(Regime::Aucun, RegimeVoter::regimeDe($this->compte(UserStatus::EnAttente)));
        self::assertSame(Regime::Aucun, RegimeVoter::regimeDe($this->compte(UserStatus::Suspendu)));
        self::assertSame(Regime::Cercle, RegimeVoter::regimeDe($this->compte(UserStatus::Actif)));
    }

    private function compte(UserStatus $statut): User
    {
        return (new User('cousin.saule@example.net'))->setStatus($statut);
    }
}
