<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Proposal\Contract\RecueilDErreurs;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Exception\Introuvable;
use PHPUnit\Framework\TestCase;

/**
 * Le recueil des `422` du dépôt à blanc (ADR 0022), seul, sans noyau.
 *
 * Deux promesses le tiennent, et ce fichier éprouve chacune :
 *
 * - **une erreur seule sort intacte** : son code, son message et ses `champs`. C'est elle
 *   que l'assistant lit, et il ne doit voir aucune différence ;
 * - **la première erreur rencontrée décide du statut** : une `403`, une `404` ou une `409`
 *   arrête tout, sauf si une `422` la précède, auquel cas c'est la `422` qui part, comme au
 *   dépôt.
 */
final class RecueilDErreursTest extends TestCase
{
    private const string MESSAGE_MULTIPLE = 'La demande porte plusieurs erreurs : chacune est nommée dans « champs ».';

    public function testSansErreurRienNeSeLeve(): void
    {
        $recueil = new RecueilDErreurs();

        self::assertNull($recueil->erreur());

        $recueil->leverSiNonVide();
        $this->addToAssertionCount(1);
    }

    public function testUneErreurSeuleRepartTelleQuelle(): void
    {
        $recueil = new RecueilDErreurs();
        $sansLien = DonneesInvalides::demandeSansLien();

        RecueilDErreurs::recueillir($recueil, $sansLien);

        self::assertSame($sansLien, $recueil->erreur(), 'L’exception d’origine, et non une copie : code, message et champs intacts.');
    }

    public function testPlusieursErreursSeReunissentDansLOrdre(): void
    {
        $recueil = $this->recueilDe(
            DonneesInvalides::pourChamp('message', 'Le mot du demandeur est une chaîne.'),
            new DonneesInvalides(['ops[0].opKind' => 'Première lecture.', 'ops[1].field' => 'Le champ modifié est obligatoire.']),
            DonneesInvalides::pourChamp('ops[0].opKind', 'Seconde lecture, écartée.'),
            DonneesInvalides::pourChamp('soumettre', 'Cette valeur doit être true ou false.'),
        );
        $erreur = $recueil->erreur();
        self::assertNotNull($erreur);

        self::assertSame([
            'message' => 'Le mot du demandeur est une chaîne.',
            'ops[0].opKind' => 'Première lecture.',
            'ops[1].field' => 'Le champ modifié est obligatoire.',
            'soumettre' => 'Cette valeur doit être true ou false.',
        ], $erreur->champs(), 'Dans l’ordre d’arrivée ; une clé en double garde son premier message.');
        self::assertSame('donnees_invalides', $erreur->codeErreur());
        self::assertSame(self::MESSAGE_MULTIPLE, $erreur->getMessage());
        self::assertSame(422, $erreur->statutHttp());
        self::assertNull($erreur->details());

        // Le code commun s'il n'y en a qu'un, sinon `donnees_invalides`.
        $melange = $this->recueilDe(DonneesInvalides::demandeSansLien(), DonneesInvalides::pourChamp('message', 'Le mot du demandeur est une chaîne.'))->erreur();
        self::assertNotNull($melange);
        self::assertSame('donnees_invalides', $melange->codeErreur());
        self::assertSame(self::MESSAGE_MULTIPLE, $melange->getMessage());

        $sansLiens = $this->recueilDe(DonneesInvalides::demandeSansLien(), DonneesInvalides::demandeSansLien())->erreur();
        self::assertNotNull($sansLiens);
        self::assertSame('demande_sans_lien', $sansLiens->codeErreur());
        self::assertSame(self::MESSAGE_MULTIPLE, $sansLiens->getMessage());
        self::assertSame(['liens'], array_keys($sansLiens->champs() ?? []));
    }

    /** Le dépôt ne passe pas de recueil : rien ne change pour lui. */
    public function testSansRecueilLErreurRepart(): void
    {
        $erreur = DonneesInvalides::pourChamp('kind', 'Nature de demande inconnue.');

        try {
            RecueilDErreurs::recueillir(null, $erreur);
            self::fail('Sans recueil, l’erreur doit repartir.');
        } catch (DonneesInvalides $levee) {
            self::assertSame($erreur, $levee);
        }
    }

    public function testUneErreurNon422ArreteToutSiRienNeLaPrecede(): void
    {
        $conflit = Conflit::dejaSoumise(RequestStatus::EnAttente);

        try {
            RecueilDErreurs::recueillir(new RecueilDErreurs(), $conflit);
            self::fail('Une 409 arrête la vérification, comme elle arrêterait le dépôt.');
        } catch (Conflit $leve) {
            self::assertSame($conflit, $leve);
        }
    }

    public function testUne422RecueillieLEmporteSurLErreurQuiSuit(): void
    {
        $donnees = DonneesInvalides::pourChamp('message', 'Le mot du demandeur est une chaîne.');
        $recueil = $this->recueilDe($donnees);

        try {
            RecueilDErreurs::recueillir($recueil, Introuvable::demande(1));
            self::fail('Le dépôt se serait arrêté sur la 422 : c’est elle qui doit partir.');
        } catch (DonneesInvalides $levee) {
            self::assertSame($donnees, $levee);
        }
    }

    private function recueilDe(DonneesInvalides ...$erreurs): RecueilDErreurs
    {
        $recueil = new RecueilDErreurs();

        foreach ($erreurs as $erreur) {
            RecueilDErreurs::recueillir($recueil, $erreur);
        }

        return $recueil;
    }
}
