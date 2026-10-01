<?php

declare(strict_types=1);

namespace App\Tests\Demarche;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\UserStatus;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Tests\Membership\Double\ArbreEnMemoire;
use App\Tests\Tree\ArbreWebTestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les actes demandés : qui les voit, qui les fait avancer, ce qui en est gardé.
 *
 * L'arbre semé est celui d'`ArbreWebTestCase` : `aieule` et `aieul` sont décédés, donc
 * visibles de tout membre ; `cousin` est vivant, hors du cercle d'`ego`, et sa fiche est
 * posée **confidentielle** : depuis la révision du 2026-10-01 de l'ADR 0003, c'est la seule
 * chose qui ferme encore un vivant à un membre validé.
 *
 * **Le cercle est le vrai**, `Membership\Service\CercleDeVisibilite` ; en test, il lit
 * l'arbre par `ArbreEnMemoire` et non par la base. On y pose donc le même arbre, aux mêmes
 * identifiants — sans quoi le cercle ne voit personne et tout est refusé, ce qui ferait
 * passer un test de masquage pour de mauvaises raisons.
 */
final class RoutesDesDemarchesTest extends ArbreWebTestCase
{
    private const string OPALE = 'opale@example.invalid';   // rattachée à ego
    private const string JASPE = 'jaspe@example.invalid';   // rattachée à la conjointe
    private const string ADMIN = 'admin@example.invalid';

    private const string TEXTE = "Bonjour,\n\nJe me permets de vous solliciter pour obtenir la copie intégrale de l'acte de décès suivant : SUREAU Marne Dolomite, décédée en 1987.\n\nBien cordialement,\nOpale";

    protected function setUp(): void
    {
        parent::setUp();

        $this->poserLArbre(cousinConfidentiel: true);

        foreach (['cache.rate_limiter', 'cache.app'] as $identifiant) {
            $pool = self::getContainer()->get($identifiant);
            if ($pool instanceof CacheItemPoolInterface) {
                $pool->clear();
            }
        }

        $this->rattacher(self::OPALE, 'ego');
        $this->rattacher(self::JASPE, 'conjointe');
        $this->creerUtilisateur(self::ADMIN, ['ROLE_ADMIN']);
    }

    private function poserLArbre(bool $cousinConfidentiel): void
    {
        ArbreEnMemoire::reinitialiser();
        foreach (['ego' => true, 'conjointe' => true, 'cousin' => true, 'aieule' => false, 'aieul' => false, 'sansDate' => false] as $cle => $vivante) {
            $nom = $this->personnes[$cle]->getDisplayName();
            ArbreEnMemoire::ajouterPersonne($this->idDe($cle), $nom, '', $vivante, libelle: $nom, confidentielle: $cousinConfidentiel && 'cousin' === $cle);
        }
        ArbreEnMemoire::ajouterFamille([$this->idDe('ego'), $this->idDe('conjointe')]);
    }

    protected function tearDown(): void
    {
        ArbreEnMemoire::reinitialiser();

        parent::tearDown();
    }

    /* ───────────────── Le parcours ───────────────── */

    public function testUneDemarcheGardeLeTexteEntierEtSeRetrouveSurLaFiche(): void
    {
        $id = $this->demarcheSurLAieule();

        $this->requete("/api/demarches/{$id}");
        $charge = $this->charge();
        self::assertSame('Acte de décès de Marne Dolomite Sureau', $charge['intitule']);
        self::assertSame('envoyee', $charge['statut']);
        self::assertTrue($charge['enAttente']);
        self::assertSame([['personId' => $this->idDe('aieule'), 'libelle' => 'Marne Dolomite Sureau']], $charge['personnes']);
        self::assertCount(1, $charge['messages']);
        self::assertSame(self::TEXTE, $charge['messages'][0]['texte'], 'Le texte est gardé mot pour mot, retours à la ligne compris.');
        self::assertSame('mairie@charmille.invalid', $charge['messages'][0]['a']);
        self::assertIsInt($charge['joursDAttente']);

        $this->requete('/api/demarches?personId='.$this->idDe('aieule'));
        self::assertSame(1, $this->charge()['total']);
        self::assertArrayNotHasKey('messages', $this->charge()['elements'][0], 'Une liste ne transporte pas le texte des messages.');
        self::assertSame(
            [self::OPALE, 'mairie@charmille.invalid'],
            $this->charge()['elements'][0]['correspondants'],
            'Les adresses, elles, y sont : c’est par elles qu’on retrouve la démarche d’une réponse.',
        );

        $this->requete('/api/demarches?personId='.$this->idDe('aieul'));
        self::assertSame(0, $this->charge()['total']);
    }

    public function testUnAutreMembreFaitAvancerLaDemarcheSansPouvoirLaSupprimer(): void
    {
        $id = $this->demarcheSurLAieule();

        $this->connexionDe(self::JASPE);
        $this->envoyer('POST', "/api/demarches/{$id}/messages", [
            'sens' => 'recu',
            'canal' => 'courriel',
            'date' => (new \DateTimeImmutable())->format('Y-m-d'),
            'de' => 'etat.civil@charmille.invalid',
            'texte' => 'Veuillez trouver ci-joint la copie demandée.',
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->statut(), (string) $this->client->getResponse()->getContent());
        self::assertCount(2, $this->charge()['messages']);
        self::assertSame('envoyee', $this->charge()['statut'], 'Une réponse reçue ne change pas le statut d’elle-même.');

        $this->envoyer('PUT', "/api/demarches/{$id}/suivi", ['statut' => 'recue', 'resultat' => 'Copie intégrale reçue, déposée sur la fiche.']);
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertSame('recue', $this->charge()['statut']);
        self::assertFalse($this->charge()['enAttente']);
        self::assertNull($this->charge()['joursDAttente']);

        $messageDOpale = $this->charge()['messages'][0]['id'];
        self::assertFalse($this->charge()['messages'][0]['peutRetirer']);
        $this->envoyer('DELETE', "/api/demarches/{$id}/messages/{$messageDOpale}");
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statut());

        self::assertFalse($this->charge2("/api/demarches/{$id}")['peutSupprimer']);
        $this->envoyer('DELETE', "/api/demarches/{$id}");
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statut());

        $this->connexionDe(self::OPALE);
        $this->envoyer('DELETE', "/api/demarches/{$id}");
        self::assertSame(Response::HTTP_NO_CONTENT, $this->statut());
        $this->requete("/api/demarches/{$id}");
        self::assertSame(Response::HTTP_NOT_FOUND, $this->statut());
    }

    public function testUneSecondeDemandeEnvoyeeFaitPasserLaDemarcheARelancee(): void
    {
        $id = $this->demarcheSurLAieule();

        $this->envoyer('POST', "/api/demarches/{$id}/messages", [
            'sens' => 'envoye',
            'canal' => 'courriel',
            'date' => (new \DateTimeImmutable())->format('Y-m-d'),
            'texte' => 'Je me permets de revenir vers vous au sujet de ma demande.',
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->statut());
        self::assertSame('relancee', $this->charge()['statut']);
        self::assertTrue($this->charge()['enAttente']);
    }

    /* ───────────────── Expédiée par courrier (révision du 2026-10-01) ───────────────── */

    public function testUneDemarcheExpedieeAttendLaPieceSansEtreARelancer(): void
    {
        $id = $this->demarcheSurLAieule('-40 days');

        $this->requete("/api/demarches/{$id}");
        self::assertTrue($this->charge()['attendUneReponse']);
        self::assertTrue($this->charge()['aRelancer'], 'Quarante jours sans réponse : à relancer.');

        $this->envoyer('PUT', "/api/demarches/{$id}/suivi", ['statut' => 'expediee', 'resultat' => 'La mairie a traité la demande, l’acte part par la poste.']);
        self::assertSame(Response::HTTP_OK, $this->statut(), (string) $this->client->getResponse()->getContent());
        $charge = $this->charge();
        self::assertSame('expediee', $charge['statut']);
        self::assertSame('Expédiée par courrier, en attente de réception', $charge['statutLibelle']);
        self::assertTrue($charge['enAttente'], 'La pièce n’est pas encore là : la démarche attend toujours.');
        self::assertFalse($charge['attendUneReponse'], 'Mais plus la mairie : elle a répondu.');
        self::assertFalse($charge['aRelancer'], 'On ne relance pas une mairie qui a déjà traité la demande.');
        self::assertGreaterThanOrEqual(40, $charge['joursDAttente']);

        // Elle reste dans la liste « en attente ».
        $this->requete('/api/demarches?enAttente=1');
        self::assertSame([$id], array_column($this->charge()['elements'], 'id'));

        // Un message envoyé de plus ne la fait pas passer « relancée » : seule une
        // démarche « envoyée » le devient.
        $this->envoyer('POST', "/api/demarches/{$id}/messages", [
            'sens' => 'envoye',
            'canal' => 'courriel',
            'date' => (new \DateTimeImmutable())->format('Y-m-d'),
            'texte' => 'Merci, je guette le courrier.',
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->statut());
        self::assertSame('expediee', $this->charge()['statut']);
    }

    /* ───────────────── Refaite autrement (révision du 2026-10-01) ───────────────── */

    public function testUneDemarcheRefaiteAutrementDitDOuElleVientEtPerdLeLienSiLaPremiereEstSupprimee(): void
    {
        $premiere = $this->demarcheSurLAieule();
        $this->envoyer('PUT', "/api/demarches/{$premiere}/suivi", ['statut' => 'refusee', 'resultat' => 'La mairie ne traite pas les demandes par courriel.']);
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertNull($this->charge()['precedente']);
        self::assertSame([], $this->charge()['suites']);

        $this->envoyer('POST', '/api/demarches', $this->description('aieule') + ['precedenteId' => $premiere]);
        self::assertSame(Response::HTTP_CREATED, $this->statut(), (string) $this->client->getResponse()->getContent());
        $seconde = $this->charge()['id'];
        self::assertSame(
            ['id' => $premiere, 'statut' => 'refusee', 'demandeeA' => 'Mairie de Charmille'],
            $this->charge()['precedente'],
        );

        $this->requete("/api/demarches/{$premiere}");
        self::assertSame([['id' => $seconde, 'statut' => 'envoyee']], $this->charge()['suites']);

        // Dans la liste aussi : c'est là que la carte dit « Refaite : démarche n° … ».
        $this->requete('/api/demarches');
        $parId = array_column($this->charge()['elements'], null, 'id');
        self::assertSame($premiere, $parId[$seconde]['precedente']['id']);
        self::assertSame([['id' => $seconde, 'statut' => 'envoyee']], $parId[$premiere]['suites']);

        // Supprimer la première ne supprime pas la seconde : elle perd seulement le lien.
        $this->envoyer('DELETE', "/api/demarches/{$premiere}");
        self::assertSame(Response::HTTP_NO_CONTENT, $this->statut());

        $this->requete("/api/demarches/{$seconde}");
        self::assertSame(Response::HTTP_OK, $this->statut(), (string) $this->client->getResponse()->getContent());
        self::assertNull($this->charge()['precedente']);
    }

    public function testOnNeReprendQuUneDemarcheQueLOnVoit(): void
    {
        $visible = $this->demarcheSurLAieule();

        // Une démarche sur le cousin, fiche confidentielle hors du cercle d'Opale.
        $this->connexionDe(self::ADMIN);
        $this->envoyer('POST', '/api/demarches', $this->description('cousin'));
        self::assertSame(Response::HTTP_CREATED, $this->statut());
        $masquee = $this->charge()['id'];

        // L'administrateur la reprend par une démarche sur l'aïeule, que tous voient…
        $this->envoyer('POST', '/api/demarches', $this->description('aieule') + ['precedenteId' => $masquee]);
        self::assertSame(Response::HTTP_CREATED, $this->statut());
        $suiteDeLaMasquee = $this->charge()['id'];

        // … et il reprend celle d'Opale par une démarche sur le cousin.
        $this->envoyer('POST', '/api/demarches', $this->description('cousin') + ['precedenteId' => $visible]);
        self::assertSame(Response::HTTP_CREATED, $this->statut());

        $this->connexionDe(self::OPALE);

        // Ce qu'Opale ne voit pas n'apparaît pas dans les liens…
        $this->requete("/api/demarches/{$suiteDeLaMasquee}");
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertNull($this->charge()['precedente'], 'La démarche reprise est masquée : le lien l’est aussi.');
        $this->requete("/api/demarches/{$visible}");
        self::assertSame([], $this->charge()['suites'], 'La suite porte sur le cousin : Opale ne la voit pas.');

        // … et ne se reprend pas : même refus pour « masquée » et « inexistante ».
        $this->envoyer('POST', '/api/demarches', $this->description('aieule') + ['precedenteId' => $masquee]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
        $refusMasquee = $this->charge()['erreur']['champs'];

        $this->envoyer('POST', '/api/demarches', $this->description('aieule') + ['precedenteId' => 999999]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
        self::assertSame($refusMasquee, $this->charge()['erreur']['champs']);
        self::assertArrayHasKey('precedenteId', $refusMasquee);

        $this->envoyer('POST', '/api/demarches', $this->description('aieule') + ['precedenteId' => 'trois']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
        self::assertArrayHasKey('precedenteId', $this->charge()['erreur']['champs']);

        // Rien n'a été écrit par ces refus.
        $this->requete('/api/demarches?personId='.$this->idDe('aieule'));
        self::assertSame(2, $this->charge()['total']);
    }

    /* ───────────────── Qui voit ───────────────── */

    /**
     * **La règle du 2026-10-01** : un vivant hors du cercle, mais dont la fiche n'est pas
     * confidentielle, est visible du membre — sa démarche aussi.
     */
    public function testUneDemarcheSurUnVivantHorsDuCercleEstVisibleDuMembre(): void
    {
        $this->poserLArbre(cousinConfidentiel: false);

        $this->connexionDe(self::ADMIN);
        $this->envoyer('POST', '/api/demarches', $this->description('cousin'));
        self::assertSame(Response::HTTP_CREATED, $this->statut(), (string) $this->client->getResponse()->getContent());
        $id = $this->charge()['id'];

        $this->connexionDe(self::OPALE);
        $this->requete("/api/demarches/{$id}");
        self::assertSame(Response::HTTP_OK, $this->statut());

        $this->requete('/api/demarches?personId='.$this->idDe('cousin'));
        self::assertSame(1, $this->charge()['total']);
    }

    public function testUneDemarcheSurUneFicheConfidentielleHorsDuCercleNExistePasPourLeMembre(): void
    {
        $this->connexionDe(self::ADMIN);
        $this->envoyer('POST', '/api/demarches', $this->description('cousin'));
        self::assertSame(Response::HTTP_CREATED, $this->statut(), (string) $this->client->getResponse()->getContent());
        $id = $this->charge()['id'];

        $this->connexionDe(self::OPALE);
        $this->requete("/api/demarches/{$id}");
        self::assertSame(Response::HTTP_NOT_FOUND, $this->statut(), 'Masquée et inexistante rendent la même chose.');

        $this->requete('/api/demarches');
        self::assertSame(0, $this->charge()['total']);

        $this->requete('/api/demarches?personId='.$this->idDe('cousin'));
        self::assertSame(0, $this->charge()['total']);

        // Et on ne peut pas en créer une qui le concerne : même refus que pour une personne
        // qui n'existe pas.
        $this->envoyer('POST', '/api/demarches', $this->description('cousin'));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
        self::assertArrayHasKey('personIds', $this->charge()['erreur']['champs']);

        $this->envoyer('POST', '/api/demarches', ['personIds' => [999999]] + $this->description('aieule'));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
    }

    public function testUnCompteEnAttenteNAccedePasAuxDemarches(): void
    {
        $this->creerUtilisateur('attente@example.invalid', statut: UserStatus::EnAttente);
        $this->connexionDe('attente@example.invalid');

        $this->requete('/api/demarches');
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statut());
        // Le refus dit pourquoi : le front y reconnaît l'attente (ADR 0003, 01/10/2026).
        self::assertSame('regime_en_attente', $this->charge()['erreur']['code']);
    }

    public function testSousLaBasculeLAdministrateurLitSansEcrire(): void
    {
        $id = $this->demarcheSurLAieule();

        $this->connexionDe(self::ADMIN);
        $membre = $this->em()->getRepository(Member::class)->findOneBy(['userId' => $this->userIdDe(self::JASPE)]);
        self::assertNotNull($membre);
        $this->envoyer('POST', '/api/administration/voir-comme', ['memberId' => $membre->getId()]);
        self::assertSame(Response::HTTP_OK, $this->statut());

        $this->requete("/api/demarches/{$id}");
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertFalse($this->charge()['peutSupprimer'], 'Sous la bascule, pas de droit d’administration.');

        $this->envoyer('PUT', "/api/demarches/{$id}/suivi", ['statut' => 'recue']);
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
    }

    /* ───────────────── Ce qui est refusé ───────────────── */

    public function testUnCorpsInvalideNommeChaqueChampFautif(): void
    {
        $this->connexionDe(self::OPALE);
        $this->envoyer('POST', '/api/demarches', [
            'nature' => 'bapteme',
            'forme' => 'copie_integrale',
            'intitule' => '',
            'destinataire' => 'Mairie',
            'personIds' => [],
            'premierMessage' => ['sens' => 'envoye', 'canal' => 'pigeon', 'date' => '2026-02-30', 'texte' => 'x'],
        ]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
        $champs = $this->charge()['erreur']['champs'];
        self::assertArrayHasKey('nature', $champs);
        self::assertArrayHasKey('intitule', $champs);
        self::assertArrayHasKey('personIds', $champs);

        $this->envoyer('POST', '/api/demarches', $this->description('aieule') + [
            'premierMessage' => ['sens' => 'envoye', 'canal' => 'pigeon', 'date' => '2026-02-30', 'texte' => ' '],
        ]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
        $champs = $this->charge()['erreur']['champs'];
        self::assertArrayHasKey('premierMessage.canal', $champs);
        self::assertArrayHasKey('premierMessage.date', $champs, 'Le 30 février n’existe pas.');
        self::assertArrayHasKey('premierMessage.texte', $champs);
    }

    /* ───────────────── Outils ───────────────── */

    private function demarcheSurLAieule(string $envoyeeIlYA = '-3 days'): int
    {
        $this->connexionDe(self::OPALE);
        $this->envoyer('POST', '/api/demarches', $this->description('aieule') + [
            'premierMessage' => [
                'sens' => 'envoye',
                'canal' => 'courriel',
                'date' => (new \DateTimeImmutable($envoyeeIlYA))->format('Y-m-d'),
                'de' => self::OPALE,
                'a' => 'mairie@charmille.invalid',
                'objet' => 'Demande de copie intégrale d’un acte de décès',
                'texte' => self::TEXTE,
            ],
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->statut(), (string) $this->client->getResponse()->getContent());

        $id = $this->charge()['id'];
        self::assertIsInt($id);

        return $id;
    }

    /** @return array<string, mixed> */
    private function description(string $personne): array
    {
        return [
            'nature' => 'deces',
            'forme' => 'copie_integrale',
            'intitule' => 'Acte de décès de '.$this->personnes[$personne]->getDisplayName(),
            'commune' => 'Charmille',
            'dateDeLActe' => '1987',
            'destinataire' => 'Mairie de Charmille',
            'personIds' => [$this->idDe($personne)],
        ];
    }

    /** @return array<string, mixed> */
    private function charge2(string $chemin): array
    {
        $this->requete($chemin);

        return $this->charge();
    }

    /** @param array<string, mixed>|null $corps */
    private function envoyer(string $methode, string $chemin, ?array $corps = null): void
    {
        $this->client->request($methode, $chemin, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: null === $corps ? '' : json_encode($corps, \JSON_THROW_ON_ERROR));
    }

    private function statut(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    private function userIdDe(string $courriel): int
    {
        $utilisateur = $this->em()->getRepository(User::class)->findOneBy(['email' => $courriel]);
        self::assertNotNull($utilisateur);

        return (int) $utilisateur->getId();
    }

    private function rattacher(string $courriel, string $personne): void
    {
        $identifiant = $this->creerUtilisateur($courriel)->getId();
        self::assertNotNull($identifiant);

        $membre = new Member($identifiant, $courriel, $this->idDe($personne), $this->personnes[$personne]->getDisplayName(), MatchKind::PreLie);
        $membre->activer();

        $this->em()->persist($membre);
        $this->em()->flush();
    }

    private function connexionDe(string $courriel): void
    {
        $this->client->request('POST', '/api/connexion', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['email' => $courriel, 'password' => 'motdepasse-de-test'], \JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_OK, $this->statut(), 'La connexion de test doit réussir.');
    }
}
