<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Sex;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\DraftPerson;
use App\Module\Proposal\Enum\RequestKind;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/demandes/brouillons/ressemblances`, **traversée pour de vrai** : pare-feu,
 * routeur, lecture du corps, session — et la bascule « voir comme un membre » activée par
 * sa vraie route, cookie compris.
 *
 * La règle elle-même — qui voit quoi, proposition par proposition — est éprouvée au
 * niveau du service ({@see RessemblanceDesPropositionsTest}). Ce qui ne s'éprouve qu'ici :
 * les codes de statut, l'enveloppe du `422`, l'en-tête de cache, et que la bascule posée
 * en session arrive bien jusqu'au choix de la forme.
 */
final class RessemblanceDesPropositionsEnHttpTest extends CasDeTestDemandesHttp
{
    private const int PERSONNE_OPALE = 63;

    private const int PERSONNE_TUF = 64;

    private const string CHEMIN = '/api/demandes/brouillons/ressemblances';

    public function testUnAnonymeEstRefuse(): void
    {
        $this->requete('POST', self::CHEMIN, $this->corps());

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('authentification_requise', $this->erreur()['code']);
    }

    public function testUnMembreRecoitSaPropositionEnClairSansMiseEnCache(): void
    {
        $opale = $this->creerContributeur('opale@example.net', self::PERSONNE_OPALE, 'Opale Romarin');
        $brouillon = $this->proposer((int) $opale->getId(), 'Opale Romarin');
        $this->connexion('opale@example.net');

        $this->requete('POST', self::CHEMIN, $this->corps());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $charge = $this->charge();
        self::assertSame(['elements', 'tronque'], array_keys($charge), 'Pas de `total`.');
        self::assertCount(1, $charge['elements']);
        self::assertSame('complete', $charge['elements'][0]['forme']);
        self::assertSame($brouillon->getId(), $charge['elements'][0]['draftPersonId']);
        self::assertSame('Opale Romarin', $charge['elements'][0]['authorLabel']);
        self::assertSame('completer', $charge['elements'][0]['action']);
    }

    /** Un autre membre n'apprend qu'une chose : une proposition semblable attend déjà. */
    public function testUnAutreMembreNeRecoitQuUnSignal(): void
    {
        $opale = $this->creerContributeur('opale@example.net', self::PERSONNE_OPALE, 'Opale Romarin');
        $this->proposer((int) $opale->getId(), 'Opale Romarin');
        $this->creerContributeur('tuf@example.net', self::PERSONNE_TUF, 'Tuf Romarin');
        $this->connexion('tuf@example.net');

        $this->requete('POST', self::CHEMIN, $this->corps());

        self::assertResponseIsSuccessful();
        $element = $this->charge()['elements'][0];
        self::assertSame(['forme', 'draftPersonId', 'action', 'criteres', 'raisons'], array_keys($element));
        self::assertSame('discrete', $element['forme']);
        self::assertSame('complement', $element['action']);

        $brut = (string) $this->client->getResponse()->getContent();
        foreach (['Tourmaline', 'Muguet', 'Opale', '1889', 'Saule'] as $valeur) {
            self::assertStringNotContainsString($valeur, $brut);
        }
    }

    /**
     * La bascule, de la session au choix de la forme : l'administrateur voit la
     * proposition vivante d'Opale **en clair** ; sous « voir comme Tuf », il n'en voit plus
     * que le signal — ce que Tuf verrait.
     */
    public function testSousLaBasculeLAdministrateurNeVoitQueCeQueVerraitLeMembre(): void
    {
        $opale = $this->creerContributeur('opale@example.net', self::PERSONNE_OPALE, 'Opale Romarin');
        $this->proposer((int) $opale->getId(), 'Opale Romarin');
        $tuf = $this->creerUtilisateur('tuf@example.net', nomAffiche: 'Tuf Romarin');
        $membreTuf = $this->creerMembreActif($tuf, self::PERSONNE_TUF, 'Tuf Romarin');
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN'], nomAffiche: 'Natron Romarin');
        $this->connexion('natron@example.net');

        $this->requete('POST', self::CHEMIN, $this->corps());
        self::assertResponseIsSuccessful();
        self::assertSame('complete', $this->charge()['elements'][0]['forme']);
        self::assertSame('completer', $this->charge()['elements'][0]['action']);

        $this->requete('POST', '/api/administration/voir-comme', ['memberId' => $membreTuf->getId()]);
        self::assertResponseIsSuccessful('La bascule doit s’activer.');

        $this->requete('POST', self::CHEMIN, $this->corps());
        self::assertResponseIsSuccessful('Une lecture reste ouverte sous la bascule.');
        self::assertSame('discrete', $this->charge()['elements'][0]['forme']);
        self::assertSame('complement', $this->charge()['elements'][0]['action']);
    }

    /** Un compte sans rattachement ne dépose pas de demande : il n'a rien à compléter. */
    public function testUnCompteSansRattachementNeRecoitRien(): void
    {
        $opale = $this->creerContributeur('opale@example.net', self::PERSONNE_OPALE, 'Opale Romarin');
        $this->proposer((int) $opale->getId(), 'Opale Romarin', decedee: true);
        $this->creerUtilisateur('nouveau@example.net');
        $this->connexion('nouveau@example.net');

        $this->requete('POST', self::CHEMIN, $this->corps());

        self::assertResponseIsSuccessful();
        self::assertSame(['elements' => [], 'tronque' => false], $this->charge());
    }

    public function testUneSaisieSansPrenomEstRefusee(): void
    {
        $this->creerContributeur('opale@example.net', self::PERSONNE_OPALE, 'Opale Romarin');
        $this->connexion('opale@example.net');

        $this->requete('POST', self::CHEMIN, ['fiche' => ['surname' => 'Muguet']]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $erreur = $this->erreur();
        self::assertSame('donnees_invalides', $erreur['code']);
        self::assertSame(['fiche.givenNames'], array_keys((array) $erreur['champs']));
    }

    public function testSaufDoitEtreUnIdentifiantDeDemande(): void
    {
        $this->creerContributeur('opale@example.net', self::PERSONNE_OPALE, 'Opale Romarin');
        $this->connexion('opale@example.net');

        $this->requete('POST', self::CHEMIN, $this->corps() + ['sauf' => '12']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['sauf'], array_keys((array) $this->erreur()['champs']));
    }

    // ─────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function corps(): array
    {
        return ['fiche' => [
            'givenNames' => 'Tourmaline',
            'surname' => 'Muguet',
            'sex' => 'feminin',
            'birth' => ['raw' => 'ABT 1889', 'qualifier' => 'environ', 'min' => '1889-01-01', 'max' => '1889-12-31', 'place' => null],
        ]];
    }

    /** Tourmaline Muguet, née vers 1889 à Saule — vivante par défaut. */
    private function proposer(int $auteur, string $libelle, bool $decedee = false): DraftPerson
    {
        $em = $this->em();

        $demande = new ChangeRequest(RequestKind::CreationPersonne, $auteur, $libelle);
        $demande->soumettre();
        $em->persist($demande);

        $brouillon = new DraftPerson($demande, '#p1');
        $brouillon
            ->setGivenNames('Tourmaline')
            ->setSurname('Muguet')
            ->setSex(Sex::Feminin)
            ->setDeceased($decedee)
            ->setBirth(new UncertainDate('ABT 1889', DateQualifier::Environ, new \DateTimeImmutable('1889-01-01'), new \DateTimeImmutable('1889-12-31'), 'Saule, Ombrage, Sylvanie'))
            ->setDeath($decedee ? new UncertainDate('1972', DateQualifier::Annee, new \DateTimeImmutable('1972-01-01'), new \DateTimeImmutable('1972-12-31')) : UncertainDate::inconnue());
        $em->persist($brouillon);
        $em->flush();

        return $brouillon;
    }
}
