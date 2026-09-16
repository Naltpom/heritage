<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use App\Module\Membership\Service\CercleDeVisibilite;

/**
 * **Le fil-piège sur le câblage de la règle de confidentialité.**.
 *
 * `tests/Core/CercleFermeTest.php` a le même rôle pris par l'autre bout : il vérifie que le
 * conteneur rend l'implémentation fermée tant que rien n'est branché. Ce fichier-ci
 * vérifie qu'une fois branché, c'est bien `Membership` qui répond — et que la charge de
 * session dit la vérité sur le régime.
 *
 * Les deux ne peuvent pas être vrais en même temps : le jour où l'alias de
 * `config/services/membership.yaml` est posé, le test de `Core` tombe et celui-ci passe.
 * C'est voulu, et c'est écrit dans l'en-tête du test de `Core` : « un changement
 * d'implémentation du cercle ne doit pas passer inaperçu ».
 */
final class CablageDuCercleTest extends CasDeTestMembership
{
    public function testLeConteneurRendLImplementationDeMembership(): void
    {
        $cercle = self::getContainer()->get(CercleDeVisibiliteInterface::class);

        self::assertInstanceOf(
            CercleDeVisibilite::class,
            $cercle,
            'L’alias de config/services/membership.yaml doit l’emporter sur celui de core.yaml — '
            .'le glob services/*.yaml est trié, et « membership » passe après « core ».',
        );
    }

    /**
     * La charge de session d'un **membre actif** — § 2.2 du contrat.
     *
     * `cercle.regime` vaut `cercle`, avec `generations` et `taille`. Et `membre` porte
     * `personId` et `personLabel`, parce que le rattachement est validé.
     */
    public function testLaChargeDeSessionDUnMembreActif(): void
    {
        $utilisateur = $this->creerUtilisateur('cobalt@example.net');
        $this->creerMembre($utilisateur, ArbreFamilial::EGO, 'Natron Romarin');

        $this->connexion('cobalt@example.net');
        $charge = $this->charge();

        self::assertSame(['regime' => 'cercle', 'generations' => 3, 'taille' => 13], $charge['cercle']);

        self::assertIsArray($charge['membre']);
        self::assertSame('actif', $charge['membre']['status']);
        self::assertSame(ArbreFamilial::EGO, $charge['membre']['personId']);
        self::assertSame('Natron Romarin', $charge['membre']['personLabel']);
        self::assertNull($charge['voirComme']);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  LA CHARGE D'UN COMPTE EN ATTENTE — les trois différences du § 2.2.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * 1. `cercle.regime` vaut `aucun`, et `generations` / `taille` sont **absents** — pour
     *    qu'un front qui testerait `taille === 0` lise `undefined`, pas `0` ;
     * 2. `membre.personId` et `membre.personLabel` sont **absents**, alors même que la
     *    ligne `mbr_member` les porte. *Confirmer une date n'est pas la lire* ;
     * 3. `utilisateur.displayName` retombe sur le courriel.
     */
    public function testLaChargeDeSessionDUnCompteEnAttenteNeDitRienDeLaPersonne(): void
    {
        $utilisateur = $this->creerUtilisateur('cousin@example.net', statut: UserStatus::EnAttente);
        $this->creerMembre(
            $utilisateur,
            ArbreFamilial::EGO,
            'Natron Romarin',
            MemberStatus::EnAttente,
            MatchKind::RechercheConfirmee,
        );

        $this->connexion('cousin@example.net');
        $charge = $this->charge();

        self::assertSame(['regime' => 'aucun'], $charge['cercle']);

        self::assertIsArray($charge['membre']);
        self::assertArrayNotHasKey('personId', $charge['membre']);
        self::assertArrayNotHasKey('personLabel', $charge['membre']);

        self::assertIsArray($charge['utilisateur']);
        self::assertSame('cousin@example.net', $charge['utilisateur']['displayName']);

        // Le filet, complet : le nom de la personne réclamée n'apparaît nulle part dans la
        // charge, sous aucune clé — c'est la porte de sortie n° 8, celle des libellés
        // dénormalisés (§ 11.3).
        self::assertStringNotContainsStringIgnoringCase(
            'Romarin',
            json_encode($charge, \JSON_THROW_ON_ERROR),
            'Aucune clé de la charge ne doit porter le libellé de la personne réclamée.',
        );
    }

    /** L'administrateur obtient un cercle **complet**, annoncé comme tel dans la charge. */
    public function testLaChargeDeSessionDUnAdministrateur(): void
    {
        $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);

        $this->connexion('admin@example.net');
        $charge = $this->charge();

        self::assertSame(['regime' => 'cercle', 'generations' => 3, 'taille' => 15], $charge['cercle']);
        self::assertNull($charge['membre'], 'Le compte d’amorçage n’a pas de rattachement, et ce n’est pas une erreur.');
    }
}
