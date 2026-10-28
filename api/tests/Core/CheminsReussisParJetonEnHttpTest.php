<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\PorteeDeJeton;
use App\Module\Demarche\Entity\Demarche;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Entity\Source;
use App\Tests\Membership\Double\ArbreEnMemoire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les chemins **réussis** sous jeton d'API (ADR 0021) : les routes que les agents appellent
 * vraiment — créer une démarche, y écrire, la suivre, créer une source — passent par jeton,
 * sans session ni cookie. {@see PorteesDesJetonsEnHttpTest} éprouve les refus ; ici, les succès.
 *
 * Aucun jeton n'est écrit en littéral ({@see CoreWebTestCase::creerJetonDApi()}).
 */
final class CheminsReussisParJetonEnHttpTest extends CoreWebTestCase
{
    private int $personne;

    protected function setUp(): void
    {
        parent::setUp();

        // Une personne décédée, dans la base et dans l'arbre du cercle (en test, le cercle
        // lit `ArbreEnMemoire`) : l'administrateur la voit, la démarche peut la nommer.
        $em = $this->em();
        $fiche = new Person('Marne Dolomite Sureau');
        $em->persist($fiche);
        $em->flush();
        $this->personne = (int) $fiche->getId();

        ArbreEnMemoire::reinitialiser();
        ArbreEnMemoire::ajouterPersonne($this->personne, 'Marne Dolomite', 'Sureau', false);
    }

    protected function tearDown(): void
    {
        ArbreEnMemoire::reinitialiser();

        parent::tearDown();
    }

    public function testUnJetonDeContributionCreeUneDemarcheLaFaitAvancerEtLaSuit(): void
    {
        $jeton = $this->creerJetonDApi($this->administration(), PorteeDeJeton::Contribution, 'Claude — agents');
        $idDuJeton = $this->idDuJeton();

        $this->requete('POST', '/api/demarches', [
            'nature' => 'deces',
            'forme' => 'copie_integrale',
            'intitule' => 'Acte de décès de Marne Dolomite Sureau',
            'commune' => 'Charmille',
            'dateDeLActe' => '1987',
            'destinataire' => 'Mairie de Charmille',
            'personIds' => [$this->personne],
            'premierMessage' => [
                'sens' => 'envoye',
                'canal' => 'courriel',
                'date' => (new \DateTimeImmutable('-2 days'))->format('Y-m-d'),
                'a' => 'mairie@charmille.invalid',
                'texte' => 'Bonjour, je vous demande la copie intégrale de cet acte.',
            ],
        ], $this->bearer($jeton));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $this->client->getResponse()->getContent());
        $this->assertAucunCookie();
        $id = $this->charge()['id'];
        self::assertIsInt($id);

        $this->requete('POST', "/api/demarches/{$id}/messages", [
            'sens' => 'recu',
            'canal' => 'courriel',
            'date' => (new \DateTimeImmutable())->format('Y-m-d'),
            'de' => 'etat.civil@charmille.invalid',
            'texte' => 'Veuillez trouver ci-joint la copie demandée.',
        ], $this->bearer($jeton));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $this->client->getResponse()->getContent());
        $this->assertAucunCookie();
        self::assertCount(2, $this->charge()['messages']);

        $this->requete('PUT', "/api/demarches/{$id}/suivi", [
            'statut' => 'recue',
            'resultat' => 'Copie intégrale reçue.',
        ], $this->bearer($jeton));
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $this->client->getResponse()->getContent());
        $this->assertAucunCookie();
        self::assertSame('recue', $this->charge()['statut']);

        // En base : la démarche et ses deux messages.
        $this->em()->clear();
        $demarche = $this->em()->find(Demarche::class, $id);
        self::assertInstanceOf(Demarche::class, $demarche);
        self::assertCount(2, $demarche->getEchanges());

        // Le journal : toute ligne écrite sous le jeton le nomme, et une écriture de démarche
        // n'y échappe pas.
        $lignes = $this->em()->getRepository(AuditLog::class)->findAll();
        $parJeton = array_filter($lignes, static fn (AuditLog $l): bool => null !== $l->getApiTokenId());
        self::assertNotSame([], $parJeton, 'Au moins une ligne d’audit nomme le jeton.');
        foreach ($parJeton as $ligne) {
            self::assertSame($idDuJeton, $ligne->getApiTokenId());
        }
        foreach ($lignes as $ligne) {
            if (str_contains($ligne->getSubjectType(), 'Demarche')) {
                self::assertSame($idDuJeton, $ligne->getApiTokenId(), 'Écriture de démarche sans api_token_id : '.$ligne->getSubjectType());
            }
        }
    }

    public function testUnJetonDeValidationCreeUneSource(): void
    {
        $jeton = $this->creerJetonDApi($this->administration(), PorteeDeJeton::Validation, 'Claude — session principale');

        $this->requete('POST', '/api/administration/sources', [
            'title' => 'État civil de Charmille',
            'sourceKind' => 'acte_etat_civil',
        ], $this->bearer($jeton));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $this->client->getResponse()->getContent());
        $this->assertAucunCookie();
        $id = $this->charge()['id'];
        self::assertIsInt($id);

        $this->em()->clear();
        $source = $this->em()->find(Source::class, $id);
        self::assertInstanceOf(Source::class, $source);
        self::assertSame('État civil de Charmille', $source->getTitle());
    }

    public function testUnJetonDeContributionNeCreePasDeSource(): void
    {
        $jeton = $this->creerJetonDApi($this->administration(), PorteeDeJeton::Contribution);

        $this->requete('POST', '/api/administration/sources', [
            'title' => 'Hors portée',
            'sourceKind' => 'acte_etat_civil',
        ], $this->bearer($jeton));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('acces_refuse', $this->charge()['erreur']['code']);
        $this->assertAucunCookie();

        self::assertSame([], $this->em()->getRepository(Source::class)->findAll());
    }

    private function assertAucunCookie(): void
    {
        self::assertSame([], $this->client->getResponse()->headers->getCookies(), 'Une réponse à jeton ne pose aucun cookie.');
        self::assertSame([], $this->client->getCookieJar()->all());
    }

    private function idDuJeton(): int
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT MAX(id) FROM core_api_token');
        self::assertNotFalse($id);

        return (int) $id;
    }

    private function administration(): User
    {
        return $this->creerUtilisateur('administration@example.net', roles: ['ROLE_ADMIN'], nomAffiche: 'Administration');
    }

    /** @return array<string, string> */
    private function bearer(string $jeton): array
    {
        return ['Authorization' => 'Bearer '.$jeton];
    }
}
