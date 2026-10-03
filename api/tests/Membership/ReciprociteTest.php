<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\Regime;
use App\Module\Core\Repository\UserRepository;
use App\Module\Core\ValueObject\ComposantesDeDate;
use App\Module\Membership\Contract\PortraitsDesComptes;
use App\Module\Membership\Entity\AutorisationDeNaissance;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Repository\MemberRepository;
use App\Module\Membership\Service\LecteurDuCercle;
use App\Module\Membership\Service\Reciprocite;
use App\Tests\Membership\Double\ArbreEnMemoire;
use App\Tests\Membership\Double\LecteurFige;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * **La réciprocité des vivants** (ADR 0016) — la règle, puis ses routes.
 *
 * La règle est exercée sur le vrai service, la vraie base et le vrai journal, avec un
 * lecteur figé : « je vois ce que je partage », les trois exceptions (défunt, soi-même,
 * option d'administration), l'autorisation signée d'un vivant sans compte et le compte qui
 * l'emporte, la photo qui ouvre les portraits — et l'option qui ne les ouvre pas.
 */
final class ReciprociteTest extends CasDeTestMembership
{
    // ── La règle ─────────────────────────────────────────────────────────────────

    public function testOnNeVoitQueCeQueLesDeuxPartagent(): void
    {
        $lecteur = $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, new ComposantesDeDate(annee: true, mois: true));
        $this->membreQuiPartage('cousin@example.net', ArbreFamilial::COUSIN, new ComposantesDeDate(mois: true, jour: true));

        $vu = $this->reciprocite($lecteur)->naissance(ArbreFamilial::COUSIN);

        self::assertEquals(new ComposantesDeDate(mois: true), $vu, 'Le mois seul est partagé des deux côtés.');
    }

    public function testQuiNePartageRienNeVoitRienEtNestPasVu(): void
    {
        $lecteur = $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, ComposantesDeDate::aucune());
        $cousin = $this->membreQuiPartage('cousin@example.net', ArbreFamilial::COUSIN, ComposantesDeDate::tout());

        self::assertTrue($this->reciprocite($lecteur)->naissance(ArbreFamilial::COUSIN)->estVide());
        self::assertTrue($this->reciprocite($cousin)->naissance(ArbreFamilial::EGO)->estVide());
    }

    public function testUnDefuntEtSaPropreFicheSeVoientEnEntier(): void
    {
        $lecteur = $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, ComposantesDeDate::aucune());
        $reciprocite = $this->reciprocite($lecteur);

        self::assertTrue($reciprocite->naissance(ArbreFamilial::AIEUL)->estComplete(), 'Un défunt est un fait historique.');
        self::assertTrue($reciprocite->naissance(ArbreFamilial::EGO)->estComplete(), 'On voit toujours sa propre date.');
        self::assertTrue($reciprocite->portraitNet(ArbreFamilial::EGO));
        self::assertTrue($reciprocite->portraitNet(ArbreFamilial::AIEUL));
    }

    public function testLOptionDAdministrationOuvreLesDatesMaisPasLesPhotos(): void
    {
        $lecteur = $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, ComposantesDeDate::aucune());
        $this->utilisateur($lecteur)->setIgnoreBirthRestriction(true);
        $this->em()->flush();

        $reciprocite = $this->reciprocite($lecteur, photo: false);

        self::assertTrue($reciprocite->naissance(ArbreFamilial::COUSIN)->estComplete());
        self::assertFalse($reciprocite->portraitNet(ArbreFamilial::COUSIN), 'L’option ne couvre que les dates.');
    }

    public function testLaPhotoDuLecteurOuvreLesPortraitsDesVivants(): void
    {
        $lecteur = $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, ComposantesDeDate::aucune());

        self::assertFalse($this->reciprocite($lecteur, photo: false)->portraitNet(ArbreFamilial::COUSIN));
        self::assertTrue($this->reciprocite($lecteur, photo: true)->portraitNet(ArbreFamilial::COUSIN));
    }

    public function testLAutorisationSigneeVautPourUnVivantSansCompteEtLeCompteLEmporte(): void
    {
        $lecteur = $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, ComposantesDeDate::tout());
        $admin = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);

        $this->em()->persist(new AutorisationDeNaissance(
            ArbreFamilial::COUSIN,
            new ComposantesDeDate(annee: true),
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.pdf',
            'application/pdf',
            10,
            str_repeat('0', 64),
            'SMS du 2 octobre',
            (int) $admin->getId(),
        ));
        $this->em()->flush();

        self::assertEquals(new ComposantesDeDate(annee: true), $this->reciprocite($lecteur)->naissance(ArbreFamilial::COUSIN));

        // Un compte se rattache à la fiche — même en attente, même sans rien partager.
        $this->creerMembre($this->creerUtilisateur('cousin@example.net'), ArbreFamilial::COUSIN, 'Marbre Romarin');

        self::assertTrue($this->reciprocite($lecteur)->naissance(ArbreFamilial::COUSIN)->estVide(), 'Le compte l’emporte sur l’autorisation.');
    }

    public function testLEmpreinteSuitLesConsentements(): void
    {
        $lecteur = $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, ComposantesDeDate::tout());
        $cousin = $this->membreQuiPartage('cousin@example.net', ArbreFamilial::COUSIN, ComposantesDeDate::tout());

        $avant = $this->reciprocite($lecteur)->empreinte();

        $cousin->partagerLaNaissance(new ComposantesDeDate(annee: true), 'profil');
        $this->em()->flush();

        self::assertNotSame($avant, $this->reciprocite($lecteur)->empreinte(), 'Le choix d’un autre change ce que je vois.');
        self::assertNotSame($this->reciprocite($lecteur, photo: true)->empreinte(), $this->reciprocite($lecteur, photo: false)->empreinte());
    }

    // ── Les routes ───────────────────────────────────────────────────────────────

    public function testLeTitulaireChangeSonPartageEtLeJournalLeGarde(): void
    {
        $membre = $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, ComposantesDeDate::aucune());
        $this->connexion('ego@example.net');

        $this->requete('PUT', '/api/session/partage-naissance', ['annee' => true, 'mois' => false, 'jour' => true]);

        self::assertResponseIsSuccessful();
        $charge = $this->charge();
        self::assertTrue($charge['annee']);
        self::assertFalse($charge['mois']);
        self::assertTrue($charge['jour']);
        self::assertIsString($charge['modifieLe']);

        $this->em()->clear();
        $relu = $this->em()->getRepository(Member::class)->find($membre->getId());
        self::assertNotNull($relu);
        self::assertEquals(new ComposantesDeDate(annee: true, jour: true), $relu->getPartageDeNaissance());
        self::assertSame('profil', $relu->getPartageSource());

        $journal = $this->em()->getRepository(AuditLog::class)->findBy(['action' => AuditAction::PartageNaissance]);
        self::assertCount(1, $journal, 'Le consentement se prouve.');

        $this->requete('GET', '/api/session');
        self::assertSame(['annee' => true, 'mois' => false, 'jour' => true], array_intersect_key($this->charge()['partageNaissance'], array_flip(['annee', 'mois', 'jour'])));
    }

    /**
     * L'équité (ADR 0016) : partager est immédiat, mais une information partagée reste
     * visible **au moins 30 jours** — le titulaire ne la retire pas avant. Par composante :
     * ajouter le jour reste libre, retirer le mois reste bloqué.
     */
    public function testUneInformationPartageeResteVisibleTrenteJours(): void
    {
        $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, ComposantesDeDate::aucune());
        $this->connexion('ego@example.net');

        $this->requete('PUT', '/api/session/partage-naissance', ['annee' => true, 'mois' => true]);
        self::assertResponseIsSuccessful('Partager passe toujours, sans délai.');
        self::assertArrayHasKey('mois', $this->charge()['verrouJusquAu']);

        $this->requete('PUT', '/api/session/partage-naissance', ['annee' => true, 'mois' => false]);
        self::assertResponseStatusCodeSame(409);
        $erreur = $this->charge()['erreur'];
        self::assertSame('partage_verrouille', $erreur['code']);
        self::assertArrayHasKey('mois', $erreur['details']['jusquAu']);

        $this->requete('PUT', '/api/session/partage-naissance', ['annee' => true, 'mois' => true, 'jour' => true]);
        self::assertResponseIsSuccessful('Ajouter une autre information reste libre.');

        $membre = $this->em()->getRepository(Member::class)->findOneBy(['personId' => ArbreFamilial::EGO]);
        self::assertNotNull($membre);
        self::assertSame([], $membre->verrouDuPartage(new \DateTimeImmutable('+31 days')), 'Après trente jours, le titulaire retire librement.');
    }

    /**
     * La soupape : le formulaire RGPD. Le membre demande le retrait, l'administrateur
     * l'applique — sans attendre la fin du verrou —, et chacun est notifié.
     */
    public function testUneDemandeRgpdAppliqueeRetireSansDelai(): void
    {
        $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, ComposantesDeDate::aucune());
        $this->connexion('ego@example.net');
        $this->requete('PUT', '/api/session/partage-naissance', ['annee' => true, 'mois' => true, 'jour' => true]);

        $this->requete('POST', '/api/session/demandes-rgpd', ['nature' => 'retrait_partage', 'jour' => true, 'message' => 'Je me suis trompé.']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->charge()['id'];

        $this->client->request('POST', '/api/deconnexion');
        $this->connexion('admin@example.net');
        $this->requete('GET', '/api/administration/demandes-rgpd');
        self::assertSame($id, $this->charge()['elements'][0]['id']);

        $this->requete('POST', '/api/administration/demandes-rgpd/'.$id.'/traiter', ['decision' => 'appliquee']);
        self::assertResponseIsSuccessful();
        self::assertSame('appliquee', $this->charge()['decision']);

        $this->em()->clear();
        $membre = $this->em()->getRepository(Member::class)->findOneBy(['personId' => ArbreFamilial::EGO]);
        self::assertNotNull($membre);
        self::assertEquals(new ComposantesDeDate(annee: true, mois: true), $membre->getPartageDeNaissance());
        self::assertSame('administration', $membre->getPartageSource());

        $this->requete('POST', '/api/administration/demandes-rgpd/'.$id.'/traiter', ['decision' => 'refusee', 'reponse' => 'Non.']);
        self::assertResponseStatusCodeSame(409, 'Une demande ne se traite qu’une fois.');
    }

    public function testUneDemandeRgpdMalFormeeEstRefusee(): void
    {
        $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, ComposantesDeDate::aucune());
        $this->connexion('ego@example.net');

        $this->requete('POST', '/api/session/demandes-rgpd', ['nature' => 'retrait_partage']);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('composantes', $this->charge()['erreur']['champs']);

        $this->requete('POST', '/api/session/demandes-rgpd', ['nature' => 'rectification', 'message' => '  ']);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('message', $this->charge()['erreur']['champs']);
    }

    public function testUnCompteSansRattachementNAPasDePartage(): void
    {
        $this->creerUtilisateur('seul@example.net');
        $this->connexion('seul@example.net');

        $this->requete('PUT', '/api/session/partage-naissance', ['annee' => true]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('compte_non_rattache', $this->charge()['erreur']['code']);
    }

    public function testUnPartageMalFormeEstRefuse(): void
    {
        $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, ComposantesDeDate::aucune());
        $this->connexion('ego@example.net');

        $this->requete('PUT', '/api/session/partage-naissance', ['annee' => 'peut-être']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testLAdministrateurPoseLOptionEtLeJournalLaGarde(): void
    {
        $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $cible = $this->creerUtilisateur('membre@example.net');
        $this->connexion('admin@example.net');

        $this->requete('PATCH', '/api/administration/utilisateurs/'.$cible->getId(), ['ignoreRestrictionNaissance' => true]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->charge()['ignoreRestrictionNaissance']);
        self::assertCount(1, $this->em()->getRepository(AuditLog::class)->findBy(['action' => AuditAction::RestrictionNaissance]));
    }

    public function testLAutorisationSeDeposeSeLitEtSeRetire(): void
    {
        $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $this->connexion('admin@example.net');
        $route = '/api/administration/personnes/'.ArbreFamilial::COUSIN.'/autorisation-naissance';

        $this->client->request('POST', $route, ['annee' => 'true', 'mois' => 'true', 'jour' => 'false', 'note' => 'SMS du 2 octobre'], ['document' => $this->documentPdf()]);

        self::assertResponseStatusCodeSame(201);
        $etat = $this->charge();
        self::assertFalse($etat['compteRattache']);
        self::assertTrue($etat['autorisation']['annee']);
        self::assertFalse($etat['autorisation']['jour']);
        self::assertSame('application/pdf', $etat['autorisation']['mime']);

        $this->client->request('GET', $etat['autorisation']['documentUrl']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $this->requete('DELETE', $route);
        self::assertResponseIsSuccessful();
        $etat = $this->charge();
        self::assertNull($etat['autorisation']);
        self::assertSame('retiree', $etat['historique'][0]['raisonArchivage'], 'Retirée, pas supprimée : c’est une preuve.');
    }

    public function testLAutorisationEstRefuseeQuandUnCompteEstRattache(): void
    {
        $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $this->creerMembre($this->creerUtilisateur('cousin@example.net'), ArbreFamilial::COUSIN, 'Marbre Romarin');
        $this->connexion('admin@example.net');

        $this->client->request('POST', '/api/administration/personnes/'.ArbreFamilial::COUSIN.'/autorisation-naissance', ['annee' => 'true'], ['document' => $this->documentPdf()]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('compte_rattache', $this->charge()['erreur']['code']);
    }

    public function testLAutorisationEstReserveeAuxAdministrateurs(): void
    {
        $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, ComposantesDeDate::aucune());
        $this->connexion('ego@example.net');

        $this->requete('GET', '/api/administration/personnes/'.ArbreFamilial::COUSIN.'/autorisation-naissance');

        self::assertResponseStatusCodeSame(403);
    }

    public function testLeRattachementDUnCompteArchiveLAutorisation(): void
    {
        $admin = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $autorisation = new AutorisationDeNaissance(
            ArbreFamilial::COUSIN,
            ComposantesDeDate::tout(),
            'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.pdf',
            'application/pdf',
            10,
            str_repeat('1', 64),
            null,
            (int) $admin->getId(),
        );
        $this->em()->persist($autorisation);
        $this->em()->flush();

        $membre = $this->creerMembreSansFiche($this->creerUtilisateur('cousin@example.net', statut: \App\Module\Core\Enum\UserStatus::EnAttente));
        $this->connexion('admin@example.net');
        $this->requete('POST', '/api/administration/membres/'.$membre->getId().'/activer', ['personId' => ArbreFamilial::COUSIN]);
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        $relue = $this->em()->getRepository(AutorisationDeNaissance::class)->find($autorisation->getId());
        self::assertNotNull($relue);
        self::assertSame(AutorisationDeNaissance::ARCHIVE_COMPTE_RATTACHE, $relue->getArchiveReason());
    }

    // ── Outils ───────────────────────────────────────────────────────────────────

    private function membreQuiPartage(string $courriel, int $personId, ComposantesDeDate $partage): Member
    {
        $membre = $this->creerMembre($this->creerUtilisateur($courriel), $personId, 'Fiche '.$personId);
        $membre->partagerLaNaissance($partage, 'inscription');
        $this->em()->flush();

        return $membre;
    }

    private function utilisateur(Member $membre): User
    {
        $utilisateur = $this->em()->getRepository(User::class)->find($membre->getUserId());
        \assert($utilisateur instanceof User);

        return $utilisateur;
    }

    /** Le vrai service, la vraie base — et un lecteur figé, sans session. */
    private function reciprocite(Member $membre, bool $photo = false): Reciprocite
    {
        $conteneur = self::getContainer();
        $memberRepository = $conteneur->get(MemberRepository::class);
        $userRepository = $conteneur->get(UserRepository::class);
        $connexion = $conteneur->get(Connection::class);
        $arbre = $conteneur->get(ArbreEnMemoire::class);
        \assert($memberRepository instanceof MemberRepository && $userRepository instanceof UserRepository && $connexion instanceof Connection && $arbre instanceof ArbreEnMemoire);

        $portraits = new class($photo) implements PortraitsDesComptes {
            public function __construct(private readonly bool $photo)
            {
            }

            public function aUnePhoto(int $userId, ?int $personId): bool
            {
                return $this->photo;
            }

            public function portraitEnAttente(int $userId): ?array
            {
                return null;
            }

            public function rattacherALaFiche(int $userId, int $personId, string $personLabel): bool
            {
                return false;
            }

            public function ecarter(int $userId): \Closure
            {
                return static function (): void {};
            }
        };

        return new Reciprocite(
            new LecteurFige(new LecteurDuCercle($membre->getUserId(), Regime::Cercle, $membre->getId(), $membre->getPersonId())),
            $arbre,
            $memberRepository,
            $userRepository,
            $portraits,
            $connexion,
        );
    }

    private function documentPdf(): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'autorisation');
        \assert(false !== $chemin);
        file_put_contents($chemin, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

        return new UploadedFile($chemin, 'autorisation.pdf', 'application/pdf', null, true);
    }
}
