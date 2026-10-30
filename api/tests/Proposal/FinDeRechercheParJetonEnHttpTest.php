<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\PorteeDeJeton;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Tests\Core\CoreWebTestCase;
use App\Tests\Proposal\Double\ArbreEnMemoire;
use App\Tests\Proposal\Double\NotificateurEnMemoire;
use Symfony\Component\HttpFoundation\Response;

/**
 * La fin de recherche sous un jeton d'API de portée `contribution` (ADR 0023, ADR 0021) :
 * elle se dépose par `POST /api/demandes` et se vérifie par `POST /api/demandes/verifier`,
 * sans route nouvelle. Le retrait suit la règle de la PR #232 — un brouillon se retire, une
 * demande en attente non — et **lever n'est pas retirer** : la levée est une demande.
 *
 * Complète {@see FinDeRechercheTest}, qui éprouve le circuit au niveau du service.
 */
final class FinDeRechercheParJetonEnHttpTest extends CoreWebTestCase
{
    private const int PERSONNE = 17;

    private const int VERSION_DE_DEPART = 7;

    protected function setUp(): void
    {
        parent::setUp();

        ArbreEnMemoire::oublierTout();
        NotificateurEnMemoire::oublierTout();
        $this->arbre()->poser(SubjectKind::Personne, self::PERSONNE, 'Marne Dolomite Sureau', self::VERSION_DE_DEPART, [
            'givenNames' => 'Marne Dolomite',
            'surname' => 'Sureau',
            'marriedName' => null,
            'nickname' => null,
            'sex' => 'feminin',
            'deceased' => true,
            'birth' => new UncertainDate('1987', DateQualifier::Annee, new \DateTimeImmutable('1987-01-01'), new \DateTimeImmutable('1987-12-31')),
            'death' => UncertainDate::inconnue(),
            'forcePrivacy' => false,
            'privacyNote' => null,
            'finDeRecherchePere' => null,
            'finDeRechercheMere' => null,
        ]);
    }

    protected function tearDown(): void
    {
        ArbreEnMemoire::oublierTout();
        NotificateurEnMemoire::oublierTout();

        parent::tearDown();
    }

    public function testUnJetonDeContributionDeposeUneFinDeRechercheEtLaValideLAdministrateur(): void
    {
        $jeton = $this->creerJetonDApi($this->administration(), PorteeDeJeton::Contribution);

        $this->requete('POST', '/api/demandes', $this->demande(true), $this->bearer($jeton));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $this->client->getResponse()->getContent());
        $demande = $this->charge()['id'];
        self::assertIsInt($demande);
        self::assertSame('en_attente', $this->charge()['status']);
        self::assertNull($this->arbre()->valeur(SubjectKind::Personne, self::PERSONNE, 'finDeRechercheMere'), 'Déposer n’écrit pas dans l’arbre.');

        // Le commanditaire, par son cookie, la valide : le jeton de contribution ne décide pas.
        $this->connexion('administration@example.net');
        $this->requete('POST', '/api/administration/demandes/'.$demande.'/valider');
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->arbre()->valeur(SubjectKind::Personne, self::PERSONNE, 'finDeRechercheMere'));
    }

    public function testLeDepotABlancSousJetonDeContributionDitOuiOuNon(): void
    {
        $jeton = $this->creerJetonDApi($this->administration(), PorteeDeJeton::Contribution);

        $this->requete('POST', '/api/demandes/verifier', $this->demande(true), $this->bearer($jeton));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $fautive = $this->demande(true);
        $fautive['ops'][0]['newValue'] = ['reason' => 'pere_inconnu', 'beforeYear' => null, 'body' => null];
        $this->requete('POST', '/api/demandes/verifier', $fautive, $this->bearer($jeton));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        // Une erreur seule garde son message (§ 6.1 ter du contrat).
        self::assertStringContainsString('Père inconnu', (string) json_encode($this->charge()['erreur'], \JSON_UNESCAPED_UNICODE));
    }

    /**
     * Un jeton retire le brouillon de sa fin de recherche, jamais la demande en attente — que
     * l'administrateur, par son cookie, retire comme avant (PR #232).
     */
    public function testLeBrouillonSeRetireMaisPasLaDemandeEnAttente(): void
    {
        $jeton = $this->creerJetonDApi($this->administration(), PorteeDeJeton::Contribution);

        $this->requete('POST', '/api/demandes', $this->demande(false), $this->bearer($jeton));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $brouillon = $this->charge()['id'];
        self::assertIsInt($brouillon);
        self::assertSame('brouillon', $this->charge()['status']);

        $this->requete('POST', '/api/demandes/'.$brouillon.'/retirer', [], $this->bearer($jeton));
        self::assertResponseIsSuccessful();
        self::assertSame('annulee', $this->charge()['status']);

        $this->requete('POST', '/api/demandes', $this->demande(true), $this->bearer($jeton));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $envoyee = $this->charge()['id'];
        self::assertIsInt($envoyee);

        $this->requete('POST', '/api/demandes/'.$envoyee.'/retirer', [], $this->bearer($jeton));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(
            'Sous un jeton d\'API, une demande en attente ne se retire pas : elle attend un humain.',
            $this->charge()['erreur']['message'],
        );

        $this->connexion('administration@example.net');
        $this->requete('POST', '/api/demandes/'.$envoyee.'/retirer');
        self::assertResponseIsSuccessful();
        self::assertSame('annulee', $this->charge()['status']);
    }

    /** Lever n'est pas retirer : sous le jeton, la levée est une demande ordinaire. */
    public function testLeverUneFinDeRechercheSeDeposeSousJeton(): void
    {
        $jeton = $this->creerJetonDApi($this->administration(), PorteeDeJeton::Contribution);
        $this->connexion('administration@example.net');
        $this->requete('POST', '/api/demandes', $this->demande(true));
        $pose = $this->charge()['id'];
        $this->requete('POST', '/api/administration/demandes/'.$pose.'/valider');
        self::assertResponseIsSuccessful();

        $version = $this->arbre()->version(SubjectKind::Personne, self::PERSONNE);
        $levee = $this->demande(true);
        $levee['ops'][0]['oldValue'] = $levee['ops'][0]['newValue'];
        $levee['ops'][0]['newValue'] = null;
        $levee['ops'][0]['baseVersion'] = $version;

        $this->requete('POST', '/api/demandes', $levee, $this->bearer($jeton));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $this->client->getResponse()->getContent());
        self::assertSame('en_attente', $this->charge()['status']);
        self::assertNotNull($this->arbre()->valeur(SubjectKind::Personne, self::PERSONNE, 'finDeRechercheMere'), 'Tant que personne n’a validé, la fin de recherche reste.');
    }

    /** @return array<string, mixed> */
    private function demande(bool $soumettre): array
    {
        return [
            'kind' => 'modification_personne',
            'message' => 'Dépouillé aux AD : rien avant 1668.',
            'soumettre' => $soumettre,
            'targetKind' => 'personne',
            'targetId' => self::PERSONNE,
            'ops' => [[
                'seq' => 1,
                'opKind' => 'champ',
                'subjectKind' => 'personne',
                'subjectId' => self::PERSONNE,
                'field' => 'finDeRechercheMere',
                'valueKind' => 'fin_de_recherche',
                'oldValue' => null,
                'newValue' => ['reason' => 'registres_inexistants', 'beforeYear' => 1668, 'body' => 'Saint-Martin commence en 1668.'],
                'baseVersion' => self::VERSION_DE_DEPART,
            ]],
        ];
    }

    private function administration(): User
    {
        return $this->creerUtilisateur('administration@example.net', roles: ['ROLE_ADMIN'], nomAffiche: 'Administration');
    }

    private function arbre(): ArbreEnMemoire
    {
        $arbre = self::getContainer()->get(ArbreEnMemoire::class);
        \assert($arbre instanceof ArbreEnMemoire);

        return $arbre;
    }

    /** @return array{Authorization: string} */
    private function bearer(string $jeton): array
    {
        return ['Authorization' => 'Bearer '.$jeton];
    }
}
