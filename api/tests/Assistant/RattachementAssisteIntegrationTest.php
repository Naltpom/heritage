<?php

declare(strict_types=1);

namespace App\Tests\Assistant;

use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Entity\Notification;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\NotificationKind;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Service\CleDeRecherche;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use App\Module\Tree\Entity\Person;
use App\Tests\Assistant\Double\AccesAuModeleSimule;
use App\Tests\Assistant\Double\ComptesARattacherEnMemoire;
use App\Tests\Assistant\Double\ModeleScripte;
use App\Tests\Media\FabriqueDeFichiers;
use App\Tests\Membership\Double\ArbreEnMemoire as ArbreDeMembership;
use App\Tests\Proposal\CasDeTestDemandesHttp;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Le rattachement assisté **sur de vraies lignes `mbr_member`** — l'intégration des deux
 * moitiés de l'ADR 0013 que leurs tests respectifs éprouvent séparément :
 *
 * - {@see RattachementAssisteHttpTest} tient `Membership` par un double en mémoire ;
 * - `tests/Membership/RattachementDesComptesSansFicheTest` tient l'implémentation réelle
 *   du contrat, mais sans passer par les routes de l'assistant.
 *
 * Ici, le double est **branché sur `Membership`** ({@see ComptesARattacherEnMemoire::brancherSurMembership()}) :
 * la suggestion est écrite dans la colonne `attachment_suggestion` du compte, relue par
 * l'onglet « À rattacher », et « Créer la fiche et rattacher » active un vrai compte —
 * `core_user` compris —, purge sa déclaration et fait de sa photo d'inscription le
 * portrait de la fiche neuve. Le tout dans la transaction englobante de l'assistant.
 *
 * `MEDIA_RACINE` est détournée vers `var/`, suffixée du numéro de processus : la photo
 * déposée n'écrit pas dans l'arborescence du dépôt.
 */
final class RattachementAssisteIntegrationTest extends CasDeTestDemandesHttp
{
    private const string DESCRIPTION = 'Mon père est Jean Dupont, né vers 1960 à Lille ; ma mère Marie Martin ; ma grand-mère paternelle Rose.';

    private const string COURRIEL_INSCRIT = 'lea.dupont@example.net';

    private int $jean;

    private int $membre;

    protected function setUp(): void
    {
        // AVANT `createClient()` : le conteneur lit l'environnement au premier appel.
        $racine = self::racineDesMedias();
        $_ENV['MEDIA_RACINE'] = $racine;
        $_SERVER['MEDIA_RACINE'] = $racine;

        parent::setUp();

        ModeleScripte::oublierTout();
        AccesAuModeleSimule::oublierTout();
        ComptesARattacherEnMemoire::brancherSurMembership();
        // En test, `Membership` lit l'arbre par son propre double (`membership.yaml`) : les
        // fiches du scénario y sont posées aussi, comme `Proposal` a le sien.
        ArbreDeMembership::reinitialiser();

        $this->jean = $this->personne('Jean', 'Dupont', 1960);
        $this->arbre()->poser(SubjectKind::Personne, $this->jean, 'Jean Dupont', 1, [
            'givenNames' => 'Jean',
            'surname' => 'Dupont',
            'marriedName' => null,
            'nickname' => null,
            'sex' => 'masculin',
            'deceased' => false,
            'birth' => UncertainDate::inconnue(),
            'death' => UncertainDate::inconnue(),
            'forcePrivacy' => false,
            'privacyNote' => null,
        ]);

        $this->creerUtilisateur('natron@example.net', ['ROLE_ADMIN'], 'Natron');
        $this->membre = $this->inscriptionSansFiche();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        self::effacer(self::racineDesMedias());
        unset($_ENV['MEDIA_RACINE'], $_SERVER['MEDIA_RACINE']);
    }

    /**
     * Le parcours entier, côté administrateur : l'onglet, l'analyse gardée sur le compte,
     * puis « Créer la fiche et rattacher » — et le compte devient un membre actif, dont la
     * photo d'inscription est le portrait de sa fiche.
     */
    public function testLAnalyseEstGardeeSurLeCompteEtLaCreationActiveUnVraiCompte(): void
    {
        // La personne inscrite dépose sa photo depuis l'écran d'attente.
        $this->connexion(self::COURRIEL_INSCRIT);
        $this->deposerPhoto();
        self::assertResponseStatusCodeSame(201);
        $photo = $this->charge();
        self::assertSame('en_attente', $photo['status']);
        $this->deconnexion();

        $this->connexion('natron@example.net');

        // L'onglet la montre, sans analyse encore.
        $this->requete('GET', '/api/administration/rattachements');
        self::assertResponseIsSuccessful();
        $carte = $this->charge()['elements'][0];
        self::assertSame($this->membre, $carte['memberId']);
        self::assertSame($photo['id'], $carte['photo']['mediaId']);
        self::assertNull($carte['suggestionCalculeeLe']);

        // L'analyse : absente de l'arbre, fille de Jean.
        ModeleScripte::repondra(ModeleScripte::appel('conclure', [
            'resume' => 'Absente de l’arbre ; son père Jean Dupont y est.',
            'verdict' => 'absente',
            'creation' => [
                'titre' => 'Ajouter Léa Dupont',
                'justification' => 'Elle nomme son père.',
                'confiance' => 'haute',
                'personnes' => [['ref' => '#p1', 'sexe' => 'feminin']],
                'liens' => [['nature' => 'enfant_de', 'sujet' => '#p1', 'autre' => (string) $this->jean]],
            ],
        ]));

        $this->requete('POST', $this->route('suggestion'));
        self::assertResponseIsSuccessful();
        self::assertSame('absente', $this->charge()['verdict']);
        self::assertTrue($this->charge()['creation']['recevable']);

        // Gardée dans la colonne du compte, et l'onglet le sait.
        self::assertNotNull($this->ligne()->getAttachmentSuggestion());
        $this->requete('GET', '/api/administration/rattachements');
        self::assertIsString($this->charge()['elements'][0]['suggestionCalculeeLe']);

        // Relue du cache : pas de second appel au modèle (le script est épuisé).
        $this->requete('GET', $this->route('suggestion'));
        self::assertResponseIsSuccessful();
        self::assertCount(1, ModeleScripte::requetes());

        // « Créer la fiche et rattacher », sur le corps de la suggestion gardée.
        $this->requete('POST', $this->route('creer-et-rattacher'), []);
        self::assertResponseStatusCodeSame(201);
        $resultat = $this->charge();

        $creees = $this->arbre()->personnesCreees();
        self::assertCount(1, $creees);
        self::assertSame($creees[0], $resultat['personId']);
        self::assertSame('actif', $resultat['membre']['status']);
        self::assertSame('manuel_admin', $resultat['membre']['matchKind']);

        // Le compte : actif des deux côtés, rattaché, déclaration et suggestion purgées.
        $membre = $this->ligne();
        self::assertSame(MemberStatus::Actif, $membre->getStatus());
        self::assertSame(MatchKind::ManuelAdmin, $membre->getMatchKind());
        self::assertSame($creees[0], $membre->getPersonId());
        self::assertSame('Léa Marie Dupont', $membre->getPersonLabel());
        self::assertNull($membre->getFamilyDescription());
        self::assertNull($membre->getDeclaredSurname());
        self::assertNull($membre->getAttachmentSuggestion());
        self::assertSame(UserStatus::Actif, $this->utilisateur(self::COURRIEL_INSCRIT)->getStatus());

        // Le compte l'apprend par une notification **à lui**, `compte_active`, qui mène à
        // l'accueil — et part aussi sur son téléphone, puisqu'elle passe par
        // `NotificationService` (révision du 01/10/2026 : un compte en attente ne voit
        // plus rien de l'arbre, cette notification est la seule à lui dire qu'il peut
        // revenir).
        $notifications = $this->em()->getRepository(Notification::class)->findBy([
            'recipientUserId' => $this->utilisateur(self::COURRIEL_INSCRIT)->getId(),
            'kind' => NotificationKind::CompteActive,
        ]);
        self::assertCount(1, $notifications, 'Une seule notification « Votre compte est activé ».');
        self::assertSame('Votre compte est activé', $notifications[0]->getTitle());
        self::assertSame('accueil', $notifications[0]->getLinkRoute());

        // La photo de l'inscription est devenue le portrait actif de la fiche neuve.
        $media = $this->em()->find(Media::class, $photo['id']);
        self::assertInstanceOf(Media::class, $media);
        self::assertSame(MediaStatus::Actif, $media->getStatus());
        self::assertSame($creees[0], $media->getSubjectId());
        self::assertNull($media->getSubjectUserId());
        self::assertTrue($media->isActivePortrait());

        // Le compte a quitté l'onglet, et l'assistant ne le connaît plus.
        $this->requete('GET', '/api/administration/rattachements');
        self::assertSame([], $this->charge()['elements']);
        $this->requete('GET', $this->route('suggestion'));
        self::assertResponseStatusCodeSame(404);

        // Aucun journal ne cite la description — ni l'enregistrement de la suggestion, ni
        // la purge au rattachement.
        foreach ($this->em()->getRepository(AuditLog::class)->findAll() as $journal) {
            $texte = json_encode([$journal->getSubjectLabel(), $journal->getChanges()], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
            self::assertStringNotContainsString('Marie Martin', $texte, $journal->getAction()->value);
            self::assertStringNotContainsString('grand-mère', $texte, $journal->getAction()->value);
        }
    }

    /**
     * Le compte a été refusé (par un autre administrateur, dans un autre onglet) : les
     * routes de l'assistant ne le connaissent plus — `404`, et rien n'est écrit. C'est ce
     * `404` qui fait disparaître la carte côté front.
     */
    public function testUnCompteRefuseEntreTempsRend404EtNeCreeRien(): void
    {
        $this->connexion('natron@example.net');

        $this->requete('POST', \sprintf('/api/administration/membres/%d/refuser', $this->membre));
        self::assertResponseIsSuccessful();
        self::assertSame('suspendu', $this->charge()['status']);

        $this->requete('GET', $this->route('suggestion'));
        self::assertResponseStatusCodeSame(404);

        $this->requete('POST', $this->route('creer-et-rattacher'), ['parents' => [$this->jean]]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame([], $this->arbre()->personnesCreees());
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM prp_change_request'));
        self::assertSame([], ModeleScripte::requetes());
    }

    /**
     * Le compte est refusé **pendant** « Créer la fiche et rattacher » — entre la lecture
     * de la déclaration et la transaction. Le refus arrive par une autre requête : on le
     * simule par une écriture SQL directe, juste avant la relecture sous verrou, **sans
     * `em->clear()`** — le `Member` chargé par `declaration()` reste périmé dans l'identity
     * map, et c'est lui qu'il ne faut pas croire.
     *
     * Avant : la fiche était créée, le compte refusé rattaché et réactivé — le refus défait
     * sans bruit. Désormais `409 compte_deja_decide`, et rien n'est créé : ni fiche, ni
     * demande — la vérification précède la création.
     */
    public function testUnRefusPendantLaCreationRend409EtNeCreeRien(): void
    {
        $this->connexion('natron@example.net');
        $connexion = $this->em()->getConnection();

        ComptesARattacherEnMemoire::avantLeVerrou(static function (int $memberId) use ($connexion): void {
            $connexion->executeStatement(
                "UPDATE mbr_member SET status = 'suspendu', family_description = NULL, declared_surname = NULL,
                 declared_given_names = NULL, declared_birth_date = NULL, attachment_suggestion = NULL,
                 attachment_suggestion_at = NULL WHERE id = ?",
                [$memberId],
            );
        });

        $this->requete('POST', $this->route('creer-et-rattacher'), ['parents' => [$this->jean]]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('compte_deja_decide', $this->charge()['erreur']['code']);

        self::assertSame([], $this->arbre()->personnesCreees());
        self::assertSame(0, (int) $connexion->fetchOne('SELECT COUNT(*) FROM prp_change_request'));
        self::assertSame('suspendu', $connexion->fetchOne('SELECT status FROM mbr_member WHERE id = ?', [$this->membre]));
        self::assertNull($connexion->fetchOne('SELECT person_id FROM mbr_member WHERE id = ?', [$this->membre]));
    }

    /**
     * Le compte est refusé **pendant** l'analyse — l'administrateur a ouvert l'onglet,
     * l'analyse paresseuse tourne, il clique « Refuser l'inscription » sans l'attendre.
     *
     * Le refus arrive par une **autre requête** : on le simule par une écriture SQL directe,
     * au moment où le modèle « répond ». Le `Member` que la requête d'analyse a chargé avant
     * de lancer l'agent reste donc dans son identity map, **périmé** — et c'est exactement
     * le piège : sans relecture, il croyait encore le compte `en_attente` et réécrivait la
     * suggestion (résumé de la description, identité déclarée, proches) sur un compte
     * suspendu que plus rien ne purgerait. Pas d'`em->clear()` ici, à dessein.
     */
    public function testUnRefusPendantLAnalyseNeLaissePasLaSuggestionSurLeCompte(): void
    {
        $this->connexion('natron@example.net');
        $connexion = $this->em()->getConnection();

        ModeleScripte::repondra(function () use ($connexion): array {
            $connexion->executeStatement(
                "UPDATE mbr_member SET status = 'suspendu', family_description = NULL, declared_surname = NULL,
                 declared_given_names = NULL, declared_birth_date = NULL, attachment_suggestion = NULL,
                 attachment_suggestion_at = NULL WHERE id = ?",
                [$this->membre],
            );

            return ModeleScripte::appel('conclure', [
                'resume' => 'Absente de l’arbre ; son père Jean Dupont y est.',
                'verdict' => 'absente',
            ]);
        });

        $this->requete('POST', $this->route('suggestion'));
        self::assertResponseStatusCodeSame(404);

        self::assertCount(1, ModeleScripte::requetes(), 'L’analyse a bien tourné : c’est l’écriture qui est refusée.');
        self::assertNull($connexion->fetchOne('SELECT attachment_suggestion FROM mbr_member WHERE id = ?', [$this->membre]));
        self::assertNull($connexion->fetchOne('SELECT attachment_suggestion_at FROM mbr_member WHERE id = ?', [$this->membre]));
        self::assertSame('suspendu', $connexion->fetchOne('SELECT status FROM mbr_member WHERE id = ?', [$this->membre]));
    }

    /**
     * La variante « rattaché entre-temps » : en PostgreSQL, écrire la suggestion sur un
     * compte devenu `manuel_admin` violait `ck_mbr_member_declaration` — une `500` pour
     * l'administrateur. Désormais : `404`, et la ligne n'est pas touchée.
     */
    public function testUnRattachementPendantLAnalyseRend404SansEcrire(): void
    {
        $this->connexion('natron@example.net');
        $connexion = $this->em()->getConnection();

        ModeleScripte::repondra(function () use ($connexion): array {
            $connexion->executeStatement(
                "UPDATE mbr_member SET status = 'actif', match_kind = 'manuel_admin', person_id = ?, person_label = 'Jean Dupont',
                 family_description = NULL, declared_surname = NULL, declared_given_names = NULL, declared_birth_date = NULL
                 WHERE id = ?",
                [$this->jean, $this->membre],
            );

            return ModeleScripte::appel('conclure', ['resume' => 'Elle est dans l’arbre.', 'verdict' => 'indecise']);
        });

        $this->requete('POST', $this->route('suggestion'));
        self::assertResponseStatusCodeSame(404);
        self::assertNull($connexion->fetchOne('SELECT attachment_suggestion FROM mbr_member WHERE id = ?', [$this->membre]));
    }

    /**
     * « Rattacher à la fiche suggérée » : c'est la route d'activation de `Membership` qui
     * sert, sur la fiche que l'analyse a reconnue.
     */
    public function testRattacherALaFicheSuggereePasseParLActivation(): void
    {
        $lea = $this->personne('Léa Marie', 'Dupont', 1998);
        ArbreDeMembership::ajouterPersonne($lea, 'Léa Marie', 'Dupont', true, 1998);
        $this->connexion('natron@example.net');

        ModeleScripte::repondra(ModeleScripte::appel('conclure', [
            'resume' => 'Elle est dans l’arbre.',
            'verdict' => 'fiche_existante',
            'fiche' => ['personId' => $lea, 'confiance' => 'haute', 'justification' => 'Même identité.'],
        ]));
        $this->requete('POST', $this->route('suggestion'));
        self::assertResponseIsSuccessful();
        $fiche = $this->charge()['fiche'];
        self::assertSame($lea, $fiche['personId']);
        self::assertFalse($fiche['dejaRattachee']);

        $this->requete('POST', \sprintf('/api/administration/membres/%d/activer', $this->membre), ['personId' => $fiche['personId']]);
        self::assertResponseIsSuccessful();

        $membre = $this->ligne();
        self::assertSame(MemberStatus::Actif, $membre->getStatus());
        self::assertSame($lea, $membre->getPersonId());
        self::assertNull($membre->getAttachmentSuggestion());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Outils
    // ─────────────────────────────────────────────────────────────────────────────

    private function inscriptionSansFiche(): int
    {
        $compte = $this->creerUtilisateur(self::COURRIEL_INSCRIT, [], 'Léa', UserStatus::EnAttente);
        $id = $compte->getId();
        \assert(null !== $id);

        $membre = Member::aRattacher($id, self::COURRIEL_INSCRIT, 'Dupont', 'Léa Marie', new \DateTimeImmutable('1998-04-12'), self::DESCRIPTION);
        $em = $this->em();
        $em->persist($membre);
        $em->flush();

        $membreId = $membre->getId();
        \assert(null !== $membreId);

        return $membreId;
    }

    private function ligne(): Member
    {
        $em = $this->em();
        $em->clear();
        $membre = $em->find(Member::class, $this->membre);
        self::assertInstanceOf(Member::class, $membre);

        return $membre;
    }

    private function utilisateur(string $courriel): User
    {
        $utilisateur = $this->em()->getRepository(User::class)->findOneBy(['email' => $courriel]);
        self::assertInstanceOf(User::class, $utilisateur);

        return $utilisateur;
    }

    private function route(string $fin): string
    {
        return \sprintf('/api/administration/rattachements/%d/%s', $this->membre, $fin);
    }

    private function personne(string $prenoms, string $nom, int $annee): int
    {
        $personne = new Person($prenoms.' '.$nom);
        $personne
            ->setGivenNames($prenoms)
            ->setSurname($nom)
            ->setSearchKey((new CleDeRecherche())->cleDeNom($prenoms, $nom))
            ->setSex(Sex::Inconnu)
            ->setGeneration(1)
            ->setDeceased(false)
            ->setBirth(new UncertainDate((string) $annee, DateQualifier::Annee, new \DateTimeImmutable("$annee-01-01"), new \DateTimeImmutable("$annee-12-31")))
            ->setDeath(UncertainDate::inconnue());

        $em = $this->em();
        $em->persist($personne);
        $em->flush();

        $id = $personne->getId();
        \assert(null !== $id);

        return $id;
    }

    private function deposerPhoto(): void
    {
        $chemin = self::racineDesMedias().'/entree-'.bin2hex(random_bytes(6)).'.jpg';

        if (!is_dir(\dirname($chemin))) {
            mkdir(\dirname($chemin), 0o777, true);
        }

        file_put_contents($chemin, FabriqueDeFichiers::jpegNu());

        $this->client->request(
            'POST',
            '/api/medias/portrait-du-compte',
            files: ['fichier' => new UploadedFile($chemin, 'moi.jpg', 'image/jpeg', null, true)],
            server: ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.8.8.8'],
        );
    }

    private static function racineDesMedias(): string
    {
        return \dirname(__DIR__, 2).'/var/test-medias-ast-'.getmypid();
    }

    private static function effacer(string $dossier): void
    {
        if (!is_dir($dossier)) {
            return;
        }

        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterateur as $entree) {
            if ($entree instanceof \SplFileInfo) {
                $entree->isDir() ? @rmdir($entree->getPathname()) : @unlink($entree->getPathname());
            }
        }

        @rmdir($dossier);
    }
}
