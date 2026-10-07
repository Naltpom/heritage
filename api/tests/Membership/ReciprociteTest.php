<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Entity\Notification;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\NiveauDePartage;
use App\Module\Core\Enum\NotificationKind;
use App\Module\Core\Enum\Regime;
use App\Module\Core\Repository\UserRepository;
use App\Module\Core\ValueObject\ComposantesDeDate;
use App\Module\Core\ValueObject\PartageDeNaissance;
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
 * lecteur figé : « je vois ce que je partage », à tous ou à ses proches (révision du
 * 2026-10-07), les trois exceptions (défunt, soi-même, option d'administration), l'autorisation signée d'un vivant sans compte et le compte qui
 * l'emporte, la photo qui ouvre les portraits — et l'option qui ne les ouvre pas.
 */
final class ReciprociteTest extends CasDeTestMembership
{
    private const NiveauDePartage TOUS = NiveauDePartage::Tous;
    private const NiveauDePartage PROCHES = NiveauDePartage::Proches;
    private const NiveauDePartage PERSONNE = NiveauDePartage::Personne;

    // ── La règle ─────────────────────────────────────────────────────────────────

    public function testHorsDesProchesOnNeVoitQueCeQueLesDeuxMontrentATous(): void
    {
        $lecteur = $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, new PartageDeNaissance(self::TOUS, self::TOUS, self::PERSONNE));
        $this->membreQuiPartage('cousin@example.net', ArbreFamilial::COUSIN, new PartageDeNaissance(self::PROCHES, self::TOUS, self::TOUS));

        $vu = $this->reciprocite($lecteur)->naissance(ArbreFamilial::COUSIN);

        self::assertEquals(new ComposantesDeDate(mois: true), $vu, 'Le mois seul est montré à tous des deux côtés ; l’année du cousin ne l’est qu’à ses proches.');
    }

    public function testQuiNePartageAvecPersonneNeVoitRienEtNestPasVu(): void
    {
        $lecteur = $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, PartageDeNaissance::uniforme(self::PERSONNE));
        $cousin = $this->membreQuiPartage('cousin@example.net', ArbreFamilial::COUSIN, PartageDeNaissance::uniforme(self::TOUS));
        $pere = $this->membreQuiPartage('pere@example.net', ArbreFamilial::PERE, PartageDeNaissance::uniforme(self::TOUS));

        self::assertTrue($this->reciprocite($lecteur)->naissance(ArbreFamilial::COUSIN)->estVide());
        self::assertTrue($this->reciprocite($lecteur)->naissance(ArbreFamilial::PERE)->estVide(), 'Même ses proches.');
        self::assertTrue($this->reciprocite($cousin)->naissance(ArbreFamilial::EGO)->estVide());
        self::assertTrue($this->reciprocite($pere)->naissance(ArbreFamilial::EGO)->estVide());
    }

    /**
     * **Le défaut, `proches`** (révision du 2026-10-07) : sans choix exprimé, le lecteur voit
     * la date de ses parents, de son conjoint, de son enfant — avec ou sans compte —, et
     * rien de son cousin, qui n'est pas un proche.
     */
    public function testLesProchesSeVoientParDefaut(): void
    {
        $lecteur = $this->creerMembre($this->creerUtilisateur('ego@example.net'), ArbreFamilial::EGO, 'Natron Romarin');
        $this->creerMembre($this->creerUtilisateur('pere@example.net'), ArbreFamilial::PERE, 'Pyrite Romarin');

        $reciprocite = $this->reciprocite($lecteur);

        self::assertTrue($reciprocite->naissance(ArbreFamilial::PERE)->estComplete(), 'Un parent avec compte.');
        self::assertTrue($reciprocite->naissance(ArbreFamilial::MERE)->estComplete(), 'Un parent sans compte : le défaut vaut pour lui aussi.');
        self::assertTrue($reciprocite->naissance(ArbreFamilial::CONJOINT)->estComplete());
        self::assertTrue($reciprocite->naissance(ArbreFamilial::ENFANT)->estComplete());
        self::assertTrue($reciprocite->naissance(ArbreFamilial::COUSIN)->estVide(), 'Un cousin n’est pas un proche.');
        self::assertTrue($reciprocite->naissance(ArbreFamilial::TANTE)->estVide(), 'Une tante non plus.');
    }

    public function testUnProcheQuiNePartageAvecPersonneNEstPasVu(): void
    {
        $lecteur = $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, PartageDeNaissance::uniforme(self::TOUS));
        $this->membreQuiPartage('pere@example.net', ArbreFamilial::PERE, new PartageDeNaissance(self::PROCHES, self::PERSONNE, self::TOUS));

        self::assertEquals(new ComposantesDeDate(annee: true, jour: true), $this->reciprocite($lecteur)->naissance(ArbreFamilial::PERE));
    }

    public function testATousSuffitPourLesProches(): void
    {
        $lecteur = $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, PartageDeNaissance::uniforme(self::PROCHES));
        $this->membreQuiPartage('pere@example.net', ArbreFamilial::PERE, PartageDeNaissance::uniforme(self::TOUS));
        $this->membreQuiPartage('cousin@example.net', ArbreFamilial::COUSIN, PartageDeNaissance::uniforme(self::TOUS));

        self::assertTrue($this->reciprocite($lecteur)->naissance(ArbreFamilial::PERE)->estComplete(), '« Tous » inclut les proches.');
        self::assertTrue($this->reciprocite($lecteur)->naissance(ArbreFamilial::COUSIN)->estVide(), 'Le lecteur ne montre rien hors de ses proches : il n’y voit rien.');
    }

    public function testUnDefuntEtSaPropreFicheSeVoientEnEntier(): void
    {
        $lecteur = $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, PartageDeNaissance::uniforme(self::PERSONNE));
        $reciprocite = $this->reciprocite($lecteur);

        self::assertTrue($reciprocite->naissance(ArbreFamilial::AIEUL)->estComplete(), 'Un défunt est un fait historique.');
        self::assertTrue($reciprocite->naissance(ArbreFamilial::EGO)->estComplete(), 'On voit toujours sa propre date.');
        self::assertTrue($reciprocite->portraitNet(ArbreFamilial::EGO));
        self::assertTrue($reciprocite->portraitNet(ArbreFamilial::AIEUL));
    }

    public function testLOptionDAdministrationOuvreLesDatesMaisPasLesPhotos(): void
    {
        $lecteur = $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, PartageDeNaissance::uniforme(self::PERSONNE));
        $this->utilisateur($lecteur)->setIgnoreBirthRestriction(true);
        $this->em()->flush();

        $reciprocite = $this->reciprocite($lecteur, photo: false);

        self::assertTrue($reciprocite->naissance(ArbreFamilial::COUSIN)->estComplete());
        self::assertFalse($reciprocite->portraitNet(ArbreFamilial::COUSIN), 'L’option ne couvre que les dates.');
    }

    public function testLaPhotoDuLecteurOuvreLesPortraitsDesVivants(): void
    {
        $lecteur = $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, PartageDeNaissance::uniforme(self::PERSONNE));

        self::assertFalse($this->reciprocite($lecteur, photo: false)->portraitNet(ArbreFamilial::COUSIN));
        self::assertTrue($this->reciprocite($lecteur, photo: true)->portraitNet(ArbreFamilial::COUSIN));
    }

    public function testLAutorisationSigneeVautPourUnVivantSansCompteEtLeCompteLEmporte(): void
    {
        $lecteur = $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, PartageDeNaissance::uniforme(self::TOUS));
        $admin = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);

        $this->em()->persist(new AutorisationDeNaissance(
            ArbreFamilial::COUSIN,
            new PartageDeNaissance(self::TOUS, self::PERSONNE, self::PERSONNE),
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.pdf',
            'application/pdf',
            10,
            str_repeat('0', 64),
            'SMS du 2 octobre',
            (int) $admin->getId(),
        ));
        $this->em()->flush();

        self::assertEquals(new ComposantesDeDate(annee: true), $this->reciprocite($lecteur)->naissance(ArbreFamilial::COUSIN));

        // Un compte se rattache à la fiche — même en attente, même sans choix exprimé : le
        // défaut (`proches`) ne montre rien au lecteur, qui n'est pas un proche.
        $this->creerMembre($this->creerUtilisateur('cousin@example.net'), ArbreFamilial::COUSIN, 'Marbre Romarin');

        self::assertTrue($this->reciprocite($lecteur)->naissance(ArbreFamilial::COUSIN)->estVide(), 'Le compte l’emporte sur l’autorisation.');
    }

    public function testLEmpreinteSuitLesConsentements(): void
    {
        $lecteur = $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, PartageDeNaissance::uniforme(self::TOUS));
        $cousin = $this->membreQuiPartage('cousin@example.net', ArbreFamilial::COUSIN, PartageDeNaissance::uniforme(self::TOUS));

        $avant = $this->reciprocite($lecteur)->empreinte();

        $cousin->partagerLaNaissance(new PartageDeNaissance(self::TOUS, self::PROCHES, self::PROCHES), 'profil');
        $this->em()->flush();

        self::assertNotSame($avant, $this->reciprocite($lecteur)->empreinte(), 'Le choix d’un autre change ce que je vois.');
        self::assertNotSame($this->reciprocite($lecteur, photo: true)->empreinte(), $this->reciprocite($lecteur, photo: false)->empreinte());
    }

    /** Un nouveau proche (un mariage, une naissance) change ce que je vois : l'empreinte suit. */
    public function testLEmpreinteSuitLesProches(): void
    {
        $lecteur = $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, PartageDeNaissance::uniforme(self::TOUS));
        $avant = $this->reciprocite($lecteur)->empreinte();

        ArbreEnMemoire::ajouterFamille([ArbreFamilial::ENFANT, ArbreFamilial::ETRANGER]);

        self::assertNotSame($avant, $this->reciprocite($lecteur)->empreinte());
        self::assertTrue($this->reciprocite($lecteur)->naissance(ArbreFamilial::ETRANGER)->estComplete(), 'Le conjoint d’un enfant est un proche.');
    }

    // ── Les routes ───────────────────────────────────────────────────────────────

    public function testLeTitulaireChangeSonPartageEtLeJournalLeGarde(): void
    {
        $membre = $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, PartageDeNaissance::uniforme(self::PERSONNE));
        $this->connexion('ego@example.net');

        $this->requete('PUT', '/api/session/partage-naissance', ['annee' => 'tous', 'mois' => 'personne', 'jour' => 'proches']);

        self::assertResponseIsSuccessful();
        $charge = $this->charge();
        self::assertSame('tous', $charge['annee']);
        self::assertSame('personne', $charge['mois']);
        self::assertSame('proches', $charge['jour']);
        self::assertIsString($charge['modifieLe']);

        $this->em()->clear();
        $relu = $this->em()->getRepository(Member::class)->find($membre->getId());
        self::assertNotNull($relu);
        self::assertEquals(new PartageDeNaissance(self::TOUS, self::PERSONNE, self::PROCHES), $relu->getPartageDeNaissance());
        self::assertSame('profil', $relu->getPartageSource());

        $journal = $this->em()->getRepository(AuditLog::class)->findBy(['action' => AuditAction::PartageNaissance]);
        self::assertCount(1, $journal, 'Le consentement se prouve.');

        $this->requete('GET', '/api/session');
        self::assertSame(['annee' => 'tous', 'mois' => 'personne', 'jour' => 'proches'], array_intersect_key($this->charge()['partageNaissance'], array_flip(['annee', 'mois', 'jour'])));
    }

    /**
     * L'équité (ADR 0016) : partager avec tous est immédiat, mais une information partagée
     * avec tous le reste **au moins 30 jours** — le titulaire ne la retire pas avant. Par
     * composante : ajouter le jour reste libre, retirer le mois reste bloqué. Entre
     * `proches` et `personne`, rien n'est verrouillé.
     */
    public function testUneInformationPartageeAvecTousLeResteTrenteJours(): void
    {
        $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, PartageDeNaissance::uniforme(self::PERSONNE));
        $this->connexion('ego@example.net');

        $this->requete('PUT', '/api/session/partage-naissance', ['annee' => 'tous', 'mois' => 'tous', 'jour' => 'proches']);
        self::assertResponseIsSuccessful('Partager passe toujours, sans délai.');
        self::assertArrayHasKey('mois', $this->charge()['verrouJusquAu']);
        self::assertArrayNotHasKey('jour', $this->charge()['verrouJusquAu'], '« Proches » ne verrouille rien.');

        $this->requete('PUT', '/api/session/partage-naissance', ['annee' => 'tous', 'mois' => 'proches', 'jour' => 'proches']);
        self::assertResponseStatusCodeSame(409);
        $erreur = $this->charge()['erreur'];
        self::assertSame('partage_verrouille', $erreur['code']);
        self::assertArrayHasKey('mois', $erreur['details']['jusquAu']);

        $this->requete('PUT', '/api/session/partage-naissance', ['annee' => 'tous', 'mois' => 'tous', 'jour' => 'personne']);
        self::assertResponseIsSuccessful('De « proches » à « personne », sans délai.');

        $this->requete('PUT', '/api/session/partage-naissance', ['annee' => 'tous', 'mois' => 'tous', 'jour' => 'tous']);
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
        $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, PartageDeNaissance::uniforme(self::PERSONNE));
        $this->connexion('ego@example.net');
        $this->requete('PUT', '/api/session/partage-naissance', ['annee' => 'tous', 'mois' => 'tous', 'jour' => 'tous']);

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
        self::assertEquals(new PartageDeNaissance(self::TOUS, self::TOUS, self::PERSONNE), $membre->getPartageDeNaissance(), 'Le retrait passe la composante à « personne ».');
        self::assertSame('administration', $membre->getPartageSource());

        $this->requete('POST', '/api/administration/demandes-rgpd/'.$id.'/traiter', ['decision' => 'refusee', 'reponse' => 'Non.']);
        self::assertResponseStatusCodeSame(409, 'Une demande ne se traite qu’une fois.');
    }

    /** L'administrateur qui traite **sa propre** demande RGPD n'en est pas notifié. */
    public function testTraiterSaPropreDemandeRgpdNeNotifiePas(): void
    {
        $admin = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $this->creerMembre($admin, ArbreFamilial::EGO, 'Natron Romarin');
        $this->connexion('admin@example.net');

        $this->requete('POST', '/api/session/demandes-rgpd', ['nature' => 'rectification', 'message' => 'Mon deuxième prénom.']);
        $id = $this->charge()['id'];
        $this->requete('POST', '/api/administration/demandes-rgpd/'.$id.'/traiter', ['decision' => 'appliquee']);
        self::assertResponseIsSuccessful();

        self::assertCount(0, $this->em()->getRepository(Notification::class)->findBy(['kind' => NotificationKind::DemandeRgpdTraitee]));
    }

    public function testUneDemandeRgpdMalFormeeEstRefusee(): void
    {
        $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, PartageDeNaissance::uniforme(self::PERSONNE));
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

        $this->requete('PUT', '/api/session/partage-naissance', ['annee' => 'tous']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('compte_non_rattache', $this->charge()['erreur']['code']);
    }

    public function testUnPartageMalFormeEstRefuse(): void
    {
        $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, PartageDeNaissance::uniforme(self::PERSONNE));
        $this->connexion('ego@example.net');

        $this->requete('PUT', '/api/session/partage-naissance', ['annee' => 'peut-être']);
        self::assertResponseStatusCodeSame(422);

        $this->requete('PUT', '/api/session/partage-naissance', ['annee' => true]);
        self::assertResponseStatusCodeSame(422, 'Un booléen ne dit pas à qui.');
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

        $this->client->request('POST', $route, ['annee' => 'tous', 'mois' => 'proches', 'jour' => 'personne', 'note' => 'SMS du 2 octobre'], ['document' => $this->documentPdf()]);

        self::assertResponseStatusCodeSame(201);
        $etat = $this->charge();
        self::assertFalse($etat['compteRattache']);
        self::assertSame('tous', $etat['autorisation']['annee']);
        self::assertSame('personne', $etat['autorisation']['jour']);
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

        $this->client->request('POST', '/api/administration/personnes/'.ArbreFamilial::COUSIN.'/autorisation-naissance', ['annee' => 'tous'], ['document' => $this->documentPdf()]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('compte_rattache', $this->charge()['erreur']['code']);
    }

    public function testLAutorisationEstReserveeAuxAdministrateurs(): void
    {
        $this->membreQuiPartage('ego@example.net', ArbreFamilial::EGO, PartageDeNaissance::uniforme(self::PERSONNE));
        $this->connexion('ego@example.net');

        $this->requete('GET', '/api/administration/personnes/'.ArbreFamilial::COUSIN.'/autorisation-naissance');

        self::assertResponseStatusCodeSame(403);
    }

    public function testLeRattachementDUnCompteArchiveLAutorisation(): void
    {
        $admin = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $autorisation = new AutorisationDeNaissance(
            ArbreFamilial::COUSIN,
            PartageDeNaissance::uniforme(self::TOUS),
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

    private function membreQuiPartage(string $courriel, int $personId, PartageDeNaissance $partage): Member
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
