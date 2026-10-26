<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Contract\AnnuaireDesMembresInterface;
use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Entity\JetonDApi;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\EventSubscriber\JetonSansSessionSubscriber;
use App\Module\Core\Exception\ForbiddenException;
use App\Module\Core\Repository\JetonDApiRepository;
use App\Module\Core\Security\RequeteParJetonDApi;
use App\Module\Core\Security\SessionRefuseeAuJetonDApi;
use App\Module\Core\Service\AuditService;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Core\Service\GenerateurDeJetonDApi;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBag;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Le sans-état d'un jeton d'API, hors du pare-feu lui-même (ADR 0021 § 4 et § 7) : ce
 * que le reste de l'application lisait ou écrivait en session, et ce que le journal des
 * connexions écrivait à chaque requête.
 *
 * - **La bascule « voir comme »** ne se lit que dans la session apportée par la requête :
 *   sous un jeton, il n'y en a pas, la bascule du cookie n'y passe pas, et rien ne s'ouvre.
 * - **Le journal des connexions** ignore les succès par jeton (un par requête) et garde
 *   leurs échecs, `methode: jeton_api`, préfixe seulement.
 * - **La session d'un jeton** (`SessionRefuseeAuJetonDApi`) se dit absente et refuse le
 *   reste : une route qui écrirait en session refuse le jeton, sans cookie.
 *
 * Aucun jeton n'est écrit en littéral ({@see CoreWebTestCase::creerJetonDApi()}).
 */
final class JetonsDApiSansEtatTest extends CoreWebTestCase
{
    // ─── La bascule « voir comme » ───────────────────────────────────────────────

    /**
     * Une requête qui n'apporte pas de session — celle d'un jeton, dépouillée de ses
     * cookies — n'a pas de bascule, et ne la cherche pas : la session attachée par Symfony
     * n'est jamais démarrée, ni par les lectures, ni par la charge de session, ni par la
     * sortie de bascule.
     */
    public function testSansSessionApporteeLaBasculeNOuvreAucuneSession(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $bascule = $this->bascule(new Request(), $session);

        self::assertNull($bascule->memberEmprunte());
        self::assertNull($bascule->userIdEmprunte());
        self::assertFalse($bascule->estActive());
        self::assertNull($bascule->charge());
        self::assertSame([], $bascule->contribuer($this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN'])));
        $bascule->refuserSiActive();
        $bascule->desactiver();

        self::assertFalse($session->isStarted(), 'Aucun de ces chemins n’ouvre de session.');
    }

    /**
     * Une bascule rangée dans une session que la requête n'apporte pas ne vaut rien : c'est
     * le cas d'un navigateur qui a basculé, puis d'un jeton qui voyage avec son cookie —
     * le cookie est retiré, la bascule ne suit pas. Et l'activation, sans session apportée,
     * est refusée avant même de chercher le membre.
     */
    public function testUneBasculeNeVautQuePourLaSessionApporteeEtNeSActivePasSansElle(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $parCookie = $this->bascule(new Request(cookies: [$session->getName() => 'session-du-navigateur']), $session);
        $parCookie->activer(7);
        self::assertSame(7, $parCookie->memberEmprunte());

        $sansCookie = $this->bascule(new Request(), $session);
        self::assertNull($sansCookie->memberEmprunte());
        self::assertNull($sansCookie->userIdEmprunte());
        $sansCookie->refuserSiActive();

        $neuve = new Session(new MockArraySessionStorage());
        foreach ([7 => 'membre connu', 999 => 'membre inconnu'] as $memberId => $motif) {
            try {
                $this->bascule(new Request(), $neuve)->activer($memberId);
                self::fail(\sprintf('%s : sans session apportée, la bascule ne s’active pas.', $motif));
            } catch (ForbiddenException $refus) {
                self::assertSame('acces_refuse', $refus->codeErreur(), $motif);
            }
        }
        self::assertFalse($neuve->isStarted());
    }

    /**
     * Le même chemin en HTTP, avec une vraie bascule : l'administrateur bascule par cookie,
     * puis son jeton voyage avec ce cookie. La requête par jeton n'a pas de bascule, ne pose
     * pas de cookie, ne peut pas en activer une — et le cookie retrouve la sienne intacte.
     */
    public function testLaBasculeDuCookieNePassePasAuJeton(): void
    {
        $natron = $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $jeton = $this->creerJetonDApi($natron);
        $memberId = $this->creerMembre($cobalt);

        $this->connexion('natron@example.net');
        $this->requete('POST', '/api/administration/voir-comme', ['memberId' => $memberId]);
        self::assertResponseIsSuccessful();
        self::assertSame($memberId, $this->charge()['voirComme']['memberId']);

        $this->requete('GET', '/api/session', entetes: $this->bearer($jeton));
        self::assertResponseIsSuccessful();
        self::assertNull($this->charge()['voirComme'], 'La bascule vit dans la session du cookie, que le jeton n’apporte pas.');
        self::assertSame([], $this->client->getResponse()->headers->getCookies());

        $this->requete('POST', '/api/administration/voir-comme', ['memberId' => $memberId], $this->bearer($jeton));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('acces_refuse', $this->charge()['erreur']['code']);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());

        $this->requete('GET', '/api/session');
        self::assertResponseIsSuccessful();
        self::assertSame($memberId, $this->charge()['voirComme']['memberId'], 'Le cookie retrouve sa bascule.');
    }

    // ─── Le journal des connexions ───────────────────────────────────────────────

    /**
     * `access_token` émet un succès à chaque requête : aucun n'est une connexion. Le témoin
     * — la connexion par mot de passe — écrit toujours la sienne.
     */
    public function testLesSuccesParJetonNEcriventAucuneConnexion(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $jeton = $this->creerJetonDApi($cobalt);

        for ($i = 0; $i < 3; ++$i) {
            $this->requete('GET', '/api/session', entetes: $this->bearer($jeton));
            self::assertResponseIsSuccessful();
        }
        self::assertSame([], $this->lignes(AuditAction::Connexion));

        $this->connexion('cobalt@example.net');
        $connexions = $this->lignes(AuditAction::Connexion);
        self::assertCount(1, $connexions);
        self::assertSame(['methode' => 'mot_de_passe'], $connexions[0]->getChanges());
    }

    /**
     * Un jeton inconnu laisse **une** ligne d'échec, `methode: jeton_api`, avec son préfixe
     * — et rien du jeton au-delà, ni son empreinte, nulle part dans la ligne.
     */
    public function testUnJetonInconnuLaisseUnEchecAvecSonPrefixeSeulement(): void
    {
        $jeton = $this->generateur()->generer()['jeton'];

        $this->requete('GET', '/api/session', entetes: $this->bearer($jeton));
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $echecs = $this->lignes(AuditAction::ConnexionEchouee);
        self::assertCount(1, $echecs);
        self::assertSame(['methode' => 'jeton_api', 'prefixe' => substr($jeton, 0, GenerateurDeJetonDApi::LONGUEUR_PREFIXE)], $echecs[0]->getChanges());
        self::assertNull($echecs[0]->getActorUserId());
        self::assertNull($echecs[0]->getActorEmail());
        self::assertNull($echecs[0]->getApiTokenId());
        self::assertStringStartsWith('10.', (string) $echecs[0]->getIp());
        $this->assertLaLigneTaitLeSecret($echecs[0], $jeton);
        self::assertSame([], $this->lignes(AuditAction::Connexion));
    }

    /**
     * Le préfixe n'est écrit que d'une chaîne qui commence comme un jeton d'Heritage, et
     * plus longue que lui. Le reste — le secret d'un autre système collé au mauvais endroit,
     * une chaîne courte qui serait écrite entière — ne laisse que la méthode.
     */
    public function testUnJetonMalFormeNeLaisseQueLePrefixeQuiNeTrahitRien(): void
    {
        $valide = $this->generateur()->generer()['jeton'];
        $secret = substr($valide, \strlen(GenerateurDeJetonDApi::PREFIXE));
        $prefixe = substr($valide, 0, GenerateurDeJetonDApi::LONGUEUR_PREFIXE);

        $cas = [
            'allongé' => [$valide.'A', $prefixe],
            'tronqué' => [substr($valide, 0, -1), $prefixe],
            'sans le préfixe hrt_' => [$secret, null],
            'un autre système' => ['ghp_'.substr($secret, 0, 36), null],
            'de la longueur du préfixe' => [$prefixe, null],
            'court' => [GenerateurDeJetonDApi::PREFIXE.'abc', null],
            'hrt_ suivi d’un caractère hors base64url' => [GenerateurDeJetonDApi::PREFIXE.'abc.efgh'.substr($secret, 8), null],
        ];

        foreach ($cas as $motif => [$presente, $attendu]) {
            $avant = \count($this->lignes(AuditAction::ConnexionEchouee));
            $this->requete('GET', '/api/session', entetes: ['Authorization' => 'Bearer '.$presente]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, $motif);

            $echecs = $this->lignes(AuditAction::ConnexionEchouee);
            self::assertCount($avant + 1, $echecs, $motif);
            $ligne = $echecs[$avant];
            self::assertSame(['methode' => 'jeton_api', 'prefixe' => $attendu], $ligne->getChanges(), $motif);
            self::assertNull($ligne->getActorEmail(), $motif);
            $this->assertLaLigneTaitLeSecret($ligne, $presente);
        }
    }

    /**
     * Un jeton reconnu dont le compte est suspendu : le refus est le `401` commun, mais le
     * journal sait qui — le courriel du compte et le jeton, nommé.
     */
    public function testLEchecDUnCompteSuspenduNommeLeCompteEtLeJeton(): void
    {
        $exclu = $this->creerUtilisateur('exclu@example.net', statut: UserStatus::Suspendu);
        $jeton = $this->creerJetonDApi($exclu, libelle: 'Claude — session principale');
        $idDuJeton = $this->jetonEnBase($jeton)->getId();

        $this->requete('GET', '/api/session', entetes: $this->bearer($jeton));
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $echecs = $this->lignes(AuditAction::ConnexionEchouee);
        self::assertCount(1, $echecs);
        self::assertSame(['methode' => 'jeton_api', 'prefixe' => substr($jeton, 0, GenerateurDeJetonDApi::LONGUEUR_PREFIXE)], $echecs[0]->getChanges());
        self::assertSame('exclu@example.net', $echecs[0]->getActorEmail());
        self::assertNull($echecs[0]->getActorUserId(), 'Un échec ne résout pas de compte acteur.');
        self::assertSame($idDuJeton, $echecs[0]->getApiTokenId());
        self::assertSame('Claude — session principale', $echecs[0]->getApiTokenLabel());
        $this->assertLaLigneTaitLeSecret($echecs[0], $jeton);
    }

    /** Le témoin : un mot de passe faux s'écrit comme avant, sans rien d'un jeton. */
    public function testUnEchecParMotDePasseNeChangePas(): void
    {
        $this->creerUtilisateur('cobalt@example.net');

        $this->connexion('cobalt@example.net', 'pas-le-bon');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $echecs = $this->lignes(AuditAction::ConnexionEchouee);
        self::assertCount(1, $echecs);
        self::assertSame(['methode' => 'mot_de_passe'], $echecs[0]->getChanges());
        self::assertSame('cobalt@example.net', $echecs[0]->getActorEmail());
        self::assertNull($echecs[0]->getApiTokenId());
    }

    // ─── La session d'un jeton ───────────────────────────────────────────────────

    /**
     * Une requête par jeton reçoit la session qui refuse, au nom de celle de l'application ;
     * une requête à cookie garde la sienne.
     */
    public function testUneRequeteParJetonRecoitLaSessionQuiRefuse(): void
    {
        $parJeton = Request::create('/api/session', cookies: ['MOCKSESSID' => 'session-du-navigateur']);
        $parJeton->headers->set('Authorization', 'Bearer '.$this->generateur()->generer()['jeton']);
        $parJeton->setSession(new Session(new MockArraySessionStorage('MOCKSESSID')));
        $this->surRequete($parJeton);

        self::assertInstanceOf(SessionRefuseeAuJetonDApi::class, $parJeton->getSession());
        self::assertSame('MOCKSESSID', $parJeton->getSession()->getName());
        self::assertFalse($parJeton->hasPreviousSession());

        $parCookie = Request::create('/api/session', cookies: ['MOCKSESSID' => 'session-du-navigateur']);
        $session = new Session(new MockArraySessionStorage('MOCKSESSID'));
        $parCookie->setSession($session);
        $this->surRequete($parCookie);

        self::assertSame($session, $parCookie->getSession());
        self::assertTrue($parCookie->hasPreviousSession());
    }

    /**
     * Elle se dit absente — nom, identifiant vide, jamais démarrée, rien à enregistrer — et
     * refuse tout ce qui lirait ou écrirait.
     */
    public function testLaSessionDUnJetonSeDitAbsenteEtRefuseLeReste(): void
    {
        $session = new SessionRefuseeAuJetonDApi('MOCKSESSID');

        self::assertSame('MOCKSESSID', $session->getName());
        self::assertSame('', $session->getId());
        self::assertFalse($session->isStarted());
        $session->save();

        /** @var array<string, \Closure(SessionInterface): void> $gestes */
        $gestes = [
            'start' => static function (SessionInterface $s): void { $s->start(); },
            'setId' => static function (SessionInterface $s): void { $s->setId('x'); },
            'setName' => static function (SessionInterface $s): void { $s->setName('x'); },
            'invalidate' => static function (SessionInterface $s): void { $s->invalidate(); },
            'migrate' => static function (SessionInterface $s): void { $s->migrate(true); },
            'has' => static function (SessionInterface $s): void { $s->has('x'); },
            'get' => static function (SessionInterface $s): void { $s->get('x'); },
            'set' => static function (SessionInterface $s): void { $s->set('x', 1); },
            'all' => static function (SessionInterface $s): void { $s->all(); },
            'replace' => static function (SessionInterface $s): void { $s->replace(['x' => 1]); },
            'remove' => static function (SessionInterface $s): void { $s->remove('x'); },
            'clear' => static function (SessionInterface $s): void { $s->clear(); },
            'registerBag' => static function (SessionInterface $s): void { $s->registerBag(new AttributeBag()); },
            'getBag' => static function (SessionInterface $s): void { $s->getBag('attributes'); },
            'getMetadataBag' => static function (SessionInterface $s): void { $s->getMetadataBag(); },
        ];

        foreach ($gestes as $nom => $geste) {
            try {
                $geste($session);
                self::fail(\sprintf('%s() doit être refusé sous un jeton.', $nom));
            } catch (ForbiddenException $refus) {
                self::assertSame('acces_refuse', $refus->codeErreur(), $nom);
            }
        }
    }

    /**
     * Une route qui garde un état en session — ici les options de connexion par clé
     * d'accès, qui y rangent leur défi — refuse un jeton, et ne pose aucun cookie. Sans la
     * session qui refuse, elle aurait ouvert une session à chaque appel.
     */
    public function testUneRouteQuiEcritEnSessionRefuseLeJetonSansCookie(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $jeton = $this->creerJetonDApi($cobalt);

        $this->requete('POST', '/api/connexion/cle-d-acces/options', entetes: $this->bearer($jeton));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('acces_refuse', $this->charge()['erreur']['code']);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());

        // Le témoin : sans jeton, la même route publique répond, et range son défi.
        $this->requete('POST', '/api/connexion/cle-d-acces/options');
        self::assertResponseIsSuccessful();
        self::assertNotSame([], $this->client->getResponse()->headers->getCookies());
    }

    // ─── Outils ──────────────────────────────────────────────────────────────────

    /** @return array{Authorization: string} */
    private function bearer(string $jeton): array
    {
        return ['Authorization' => 'Bearer '.$jeton];
    }

    private function generateur(): GenerateurDeJetonDApi
    {
        $generateur = self::getContainer()->get(GenerateurDeJetonDApi::class);
        \assert($generateur instanceof GenerateurDeJetonDApi);

        return $generateur;
    }

    private function jetonEnBase(string $jeton): JetonDApi
    {
        $this->em()->clear();
        $depot = self::getContainer()->get(JetonDApiRepository::class);
        \assert($depot instanceof JetonDApiRepository);

        $ligne = $depot->parEmpreinte($this->generateur()->empreinte($jeton));
        self::assertNotNull($ligne);

        return $ligne;
    }

    /** @return list<AuditLog> */
    private function lignes(AuditAction $action): array
    {
        return array_values(array_filter(
            $this->journal(),
            static fn (AuditLog $ligne): bool => $action === $ligne->getAction(),
        ));
    }

    /**
     * Ni la chaîne présentée entière, ni son empreinte, dans aucun champ de la ligne — et
     * pas plus de {@see GenerateurDeJetonDApi::LONGUEUR_PREFIXE} de ses caractères d'affilée.
     */
    private function assertLaLigneTaitLeSecret(AuditLog $ligne, string $presente): void
    {
        $texte = json_encode([
            $ligne->getSubjectType(), $ligne->getSubjectId(), $ligne->getSubjectLabel(), $ligne->getChanges(),
            $ligne->getActorEmail(), $ligne->getUserAgent(), $ligne->getApiTokenLabel(),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        self::assertStringNotContainsString($presente, $texte);
        self::assertStringNotContainsString($this->generateur()->empreinte($presente), $texte);
        self::assertStringNotContainsString(substr($presente, 0, GenerateurDeJetonDApi::LONGUEUR_PREFIXE + 1), $texte);
    }

    /** Un membre actif rattaché à `$compte`, à emprunter ; rend son identifiant. */
    private function creerMembre(User $compte): int
    {
        $em = $this->em();
        $userId = $compte->getId();
        \assert(null !== $userId);

        $membre = new Member($userId, $compte->getEmail(), 1, 'Cobalt Romarin', MatchKind::PreLie);
        $membre->setStatus(MemberStatus::Actif);
        $membre->activer();
        $em->persist($membre);
        $em->flush();

        $id = $membre->getId();
        \assert(null !== $id);

        return $id;
    }

    /**
     * Une bascule sur `$requete`, avec un annuaire qui ne connaît que le membre 7 (compte
     * 42) — le modèle de `VoirCommeTest`.
     */
    private function bascule(Request $requete, Session $session): BasculeVoirComme
    {
        $requete->setSession($session);
        $pile = new RequestStack();
        $pile->push($requete);

        $annuaire = new class implements AnnuaireDesMembresInterface {
            public function libelleDuMembre(int $memberId): ?string
            {
                return 7 === $memberId ? 'Cobalt Romarin' : null;
            }

            public function membreDuCompte(int $userId): ?array
            {
                return null;
            }

            public function userIdDuMembre(int $memberId): ?int
            {
                return 7 === $memberId ? 42 : null;
            }

            public function personneDuCompte(int $userId): ?int
            {
                return null;
            }

            public function comptesParFiche(string $terme): array
            {
                return [];
            }

            /** @return array{membreId: int|null, membreLibelle: string|null, invitationsActives: int} */
            public function rattachementsDeLaPersonne(int $personneId): array
            {
                return ['membreId' => null, 'membreLibelle' => null, 'invitationsActives' => 0];
            }
        };

        $audit = self::getContainer()->get(AuditService::class);
        \assert($audit instanceof AuditService);

        return new BasculeVoirComme($pile, $annuaire, $audit);
    }

    private function surRequete(Request $requete): void
    {
        $noyau = $this->createStub(HttpKernelInterface::class);

        (new JetonSansSessionSubscriber(new RequeteParJetonDApi()))
            ->surRequete(new RequestEvent($noyau, $requete, HttpKernelInterface::MAIN_REQUEST));
    }
}
