<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Entity\JetonDApi;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\PorteeDeJeton;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Repository\JetonDApiRepository;
use App\Module\Core\Service\GenerateurDeJetonDApi;
use App\Tests\Proposal\Double\ArbreEnMemoire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le pare-feu `api_jetons` en HTTP (ADR 0021, § 1.6 du contrat) : un jeton d'API passe
 * sans cookie et n'en pose aucun, tout refus est le même `401`, l'audit nomme le jeton, et
 * le cookie marche comme avant.
 *
 * Les portées, la liste blanche des routes, les limites de débit et la règle de l'auteur
 * ont leurs propres tests.
 *
 * Aucun jeton n'est écrit en littéral : tous sortent du générateur
 * ({@see CoreWebTestCase::creerJetonDApi()}), un `hrt_` suivi de 43 caractères dans un
 * fichier du dépôt faisant échouer la CI.
 */
final class JetonsDApiEnHttpTest extends CoreWebTestCase
{
    public function testUnJetonValidePasseSansCookieEtNEnPoseAucun(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net', nomAffiche: 'Cobalt Romarin');
        $jeton = $this->creerJetonDApi($cobalt);

        $this->requete('GET', '/api/session', entetes: $this->bearer($jeton));

        self::assertResponseIsSuccessful();
        self::assertSame('cobalt@example.net', $this->charge()['utilisateur']['email']);
        self::assertSame('Cobalt Romarin', $this->charge()['utilisateur']['displayName']);
        // Sans état : ni cookie de session posé, ni jeton dans la charge utile.
        self::assertSame([], $this->client->getResponse()->headers->getCookies(), 'Une réponse à jeton ne pose aucun cookie.');
        self::assertSame([], $this->client->getCookieJar()->all());
        self::assertStringNotContainsString($jeton, (string) $this->client->getResponse()->getContent());

        // Rien n'a été gardé : sans son en-tête, la requête suivante est anonyme.
        $this->requete('GET', '/api/session');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Le cœur de l'ADR 0021 § 6 : l'appelant n'apprend pas pourquoi on le refuse. Chaque
     * refus est comparé aux autres, statut, type, corps et en-têtes compris, et son corps à
     * celui que reçoit l'anonyme sans aucun en-tête.
     */
    public function testInconnuExpireRevoqueMalFormeOuSuspenduRendentLeMemeRefus(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $exclu = $this->creerUtilisateur('exclu@example.net', statut: UserStatus::Suspendu);

        $valide = $this->creerJetonDApi($cobalt);
        $expire = $this->creerJetonDApi($cobalt, expireLe: new \DateTimeImmutable('-1 second'));
        $revoque = $this->creerJetonDApi($cobalt);
        $this->jetonEnBase($revoque)->revoquer(new \DateTimeImmutable());
        $this->em()->flush();
        $duSuspendu = $this->creerJetonDApi($exclu);

        $refus = [
            'inconnu' => 'Bearer '.$this->jetonInconnu(),
            'expiré' => 'Bearer '.$expire,
            'révoqué' => 'Bearer '.$revoque,
            'compte suspendu' => 'Bearer '.$duSuspendu,
            // Mal formés, mais dans la syntaxe d'un Bearer : jugés par `JetonDApiHandler`.
            'sans le préfixe hrt_' => 'Bearer '.substr($valide, \strlen(GenerateurDeJetonDApi::PREFIXE)),
            'tronqué' => 'Bearer '.substr($valide, 0, -1),
            'allongé' => 'Bearer '.$valide.'A',
            // Hors de la syntaxe d'un Bearer : l'extracteur ne rend rien, le point d'entrée
            // répond. Même refus, par construction (`RefusDuJetonDApi`).
            'caractère interdit' => 'Bearer '.$valide.'!',
            'schéma sans jeton' => 'Bearer',
            // Le schéma en minuscules est jugé par `api_jetons` (il entend présenter un jeton)
            // et refusé par l'extracteur de Symfony, qui veut `Bearer` à la lettre : un refus,
            // jamais un repli sur le cookie.
            'schéma en minuscules' => 'bearer '.$valide,
        ];

        $reference = null;
        foreach ($refus as $motif => $entete) {
            $this->requete('GET', '/api/session', entetes: ['Authorization' => $entete]);
            $signature = $this->signatureDeLaReponse();
            $reference ??= $signature;
            self::assertSame($reference, $signature, \sprintf('Refus « %s » : il doit être celui du jeton inconnu, au caractère près.', $motif));
        }
        self::assertSame(Response::HTTP_UNAUTHORIZED, $reference['statut']);
        self::assertNull($reference['wwwAuthenticate'], 'Aucun en-tête ne dit ce que le corps tait.');
        self::assertSame(0, $reference['cookies']);

        // Le corps est celui de l'anonyme, venu sans aucun en-tête — le point d'entrée de
        // `main`. Seul le corps se compare : l'anonyme de `main` reçoit en plus un cookie,
        // le pare-feu à état gardant en session le chemin qu'il visait.
        $this->requete('GET', '/api/session');
        self::assertSame($this->client->getResponse()->getContent(), $reference['corps']);
        self::assertSame('authentification_requise', $this->charge()['erreur']['code']);

        // Et le refus n'est pas celui de tout le monde : le jeton valide passe.
        $this->requete('GET', '/api/session', entetes: $this->bearer($valide));
        self::assertResponseIsSuccessful();
    }

    /**
     * Le `user_checker` de `main` refuse aussi le compte suspendu par jeton — mais avec le
     * `401 authentification_requise` du jeton inconnu, pas le `401 compte_suspendu` de
     * `POST /api/connexion` : un jeton se vole et se rejoue sans son titulaire, son porteur
     * n'apprend pas que le compte existe (voir `RefusDuJetonDApi`).
     */
    public function testUnCompteSuspenduNEstPasAuthentifieParSonJeton(): void
    {
        $exclu = $this->creerUtilisateur('exclu@example.net', statut: UserStatus::Suspendu);
        $jeton = $this->creerJetonDApi($exclu);

        $this->requete('GET', '/api/session', entetes: $this->bearer($jeton));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('authentification_requise', $this->charge()['erreur']['code']);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());

        // Le même jeton repasse dès que le compte est réactivé : c'est le compte qu'on
        // refusait, pas le jeton.
        $em = $this->em();
        $em->find($exclu::class, $exclu->getId())?->setStatus(UserStatus::Actif);
        $em->flush();

        $this->requete('GET', '/api/session', entetes: $this->bearer($jeton));
        self::assertResponseIsSuccessful();
    }

    /**
     * ADR 0021 § 7 : l'auteur reste le compte du jeton, et chaque ligne écrite sous un
     * jeton le nomme. `POST /api/demandes` est l'écriture même des agents, et la plus
     * simple que la portée `contribution` ouvre (`PorteeDuJetonSubscriber`).
     */
    public function testUneEcritureParJetonNommeLeJetonAuJournalSansChangerDAuteur(): void
    {
        // La fiche que la demande modifie, dans l'arbre des tests de `Proposal`
        // (`when@test`) : son état est statique, on le pose et on le retire ici.
        ArbreEnMemoire::oublierTout();
        $arbre = self::getContainer()->get(ArbreEnMemoire::class);
        \assert($arbre instanceof ArbreEnMemoire);
        $arbre->poser(SubjectKind::Personne, 17, 'Marne Dolomite Sureau', 7, ['surname' => 'Sureau', 'nickname' => null]);

        // Administrateur : il a le cercle complet sans fiche rattachée, donc il dépose.
        $cobalt = $this->creerUtilisateur('cobalt@example.net', roles: ['ROLE_ADMIN']);
        $jeton = $this->creerJetonDApi($cobalt, PorteeDeJeton::Contribution, 'Claude — agents');
        $idDuJeton = $this->jetonEnBase($jeton)->getId();

        $this->requete('POST', '/api/demandes', $this->demande('surname', 'Sureau', 'SUREAU'), $this->bearer($jeton));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $creations = $this->creationsDeDemande();
        self::assertCount(1, $creations);
        self::assertSame($cobalt->getId(), $creations[0]->getActorUserId(), 'L’auteur reste le compte du jeton.');
        self::assertSame('cobalt@example.net', $creations[0]->getActorEmail());
        self::assertNull($creations[0]->getImpersonatorUserId());
        self::assertSame($idDuJeton, $creations[0]->getApiTokenId());
        self::assertSame('Claude — agents', $creations[0]->getApiTokenLabel());

        // Le même geste par cookie ne nomme aucun jeton.
        $this->connexion('cobalt@example.net');
        $this->requete('POST', '/api/demandes', $this->demande('nickname', null, 'Marnette'));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $creations = $this->creationsDeDemande();
        self::assertCount(2, $creations);
        self::assertSame($cobalt->getId(), $creations[1]->getActorUserId());
        self::assertNull($creations[1]->getApiTokenId());
        self::assertNull($creations[1]->getApiTokenLabel());

        ArbreEnMemoire::oublierTout();
    }

    /**
     * ADR 0021 § 3 : une lecture ne devient pas une écriture à chaque requête. Le dernier
     * usage s'écrit au premier appel, puis pas avant cinq minutes — et jamais au journal.
     */
    public function testLeDernierUsageNEstEcritQuUneFoisParCinqMinutesEtJamaisAuJournal(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $jeton = $this->creerJetonDApi($cobalt);
        self::assertNull($this->jetonEnBase($jeton)->getLastUsedAt());

        $this->requete('GET', '/api/session', entetes: $this->bearer($jeton));
        self::assertResponseIsSuccessful();
        $premier = $this->jetonEnBase($jeton);
        self::assertNotNull($premier->getLastUsedAt(), 'Le premier usage s’écrit.');
        self::assertStringStartsWith('10.', (string) $premier->getLastUsedIp(), 'L’adresse est celle de l’appelant.');

        // Il y a quatre minutes : la requête ne réécrit rien, ni l'heure ni l'adresse.
        $ilYAQuatreMinutes = new \DateTimeImmutable('-4 minutes');
        $this->noterUsage($jeton, $ilYAQuatreMinutes, '192.0.2.1');
        $this->requete('GET', '/api/session', entetes: $this->bearer($jeton));
        self::assertResponseIsSuccessful();
        $recent = $this->jetonEnBase($jeton);
        self::assertSame($ilYAQuatreMinutes->getTimestamp(), $recent->getLastUsedAt()?->getTimestamp());
        self::assertSame('192.0.2.1', $recent->getLastUsedIp());

        // Il y a six minutes : elle réécrit.
        $ilYASixMinutes = new \DateTimeImmutable('-6 minutes');
        $this->noterUsage($jeton, $ilYASixMinutes, '192.0.2.1');
        $this->requete('GET', '/api/session', entetes: $this->bearer($jeton));
        self::assertResponseIsSuccessful();
        $ancien = $this->jetonEnBase($jeton);
        self::assertGreaterThanOrEqual((new \DateTimeImmutable('-1 minute'))->getTimestamp(), $ancien->getLastUsedAt()?->getTimestamp());
        self::assertStringStartsWith('10.', (string) $ancien->getLastUsedIp());

        // `lastUsedAt` et `lastUsedIp` sont des champs techniques de `AuditDoctrineSubscriber` :
        // deux écritures, aucune ligne.
        foreach ($this->journal() as $ligne) {
            self::assertNotSame('Core\\JetonDApi', $ligne->getSubjectType(), 'Le dernier usage d’un jeton ne s’écrit pas au journal.');
        }
    }

    /**
     * Cookie et Bearer dans la même requête : **le Bearer fait foi, sans session** (ADR 0021
     * § 4). Un Bearer faux ne retombe pas sur le cookie, et le cookie n'y perd rien.
     */
    public function testAvecCookieEtBearerLeBearerFaitFoiEtLeCookieSurvit(): void
    {
        $this->creerUtilisateur('cobalt@example.net');
        $pyrite = $this->creerUtilisateur('pyrite@example.net');
        $jetonDePyrite = $this->creerJetonDApi($pyrite);

        $this->connexion('cobalt@example.net');
        self::assertResponseIsSuccessful();

        $this->requete('GET', '/api/session', entetes: $this->bearer($jetonDePyrite));
        self::assertResponseIsSuccessful();
        self::assertSame('pyrite@example.net', $this->charge()['utilisateur']['email'], 'Le Bearer fait foi.');
        self::assertSame([], $this->client->getResponse()->headers->getCookies(), 'La session du cookie n’est ni lue ni réécrite.');

        $this->requete('GET', '/api/session', entetes: ['Authorization' => 'Bearer '.$this->jetonInconnu()]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('authentification_requise', $this->charge()['erreur']['code']);

        $this->requete('GET', '/api/session');
        self::assertResponseIsSuccessful();
        self::assertSame('cobalt@example.net', $this->charge()['utilisateur']['email'], 'Le cookie ressert intact sans Bearer.');
    }

    public function testLeCookieMarcheCommeAvant(): void
    {
        $this->creerUtilisateur('cobalt@example.net');

        $this->connexion('cobalt@example.net');
        self::assertResponseIsSuccessful();
        self::assertNotSame([], $this->client->getResponse()->headers->getCookies(), 'La connexion pose toujours son cookie.');

        $this->requete('GET', '/api/session');
        self::assertResponseIsSuccessful();
        self::assertSame('cobalt@example.net', $this->charge()['utilisateur']['email']);

        $this->requete('POST', '/api/deconnexion');
        self::assertResponseIsSuccessful();
        $this->requete('GET', '/api/session');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * `api_jetons` ne prend que le schéma `Bearer` : un `Authorization: Basic` posé par un
     * intermédiaire (mot de passe HTTP d'une préproduction) laisse la requête à `main` et
     * à son cookie (`RequeteParJetonDApi`).
     */
    public function testUnAutreSchemaDAutorisationLaisseLaRequeteAuCookie(): void
    {
        $this->creerUtilisateur('cobalt@example.net');
        $this->connexion('cobalt@example.net');

        $this->requete('GET', '/api/session', entetes: ['Authorization' => 'Basic '.base64_encode('preprod:mot-de-passe')]);

        self::assertResponseIsSuccessful();
        self::assertSame('cobalt@example.net', $this->charge()['utilisateur']['email']);
    }

    // ─── Outils ──────────────────────────────────────────────────────────────────

    /** @return array{Authorization: string} */
    private function bearer(string $jeton): array
    {
        return ['Authorization' => 'Bearer '.$jeton];
    }

    /** Un jeton bien formé qu'aucune ligne ne connaît. */
    private function jetonInconnu(): string
    {
        return $this->generateur()->generer()['jeton'];
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

    /** Pose le dernier usage à la main, hors requête : le journal ne le voit pas. */
    private function noterUsage(string $jeton, \DateTimeImmutable $le, string $ip): void
    {
        $this->jetonEnBase($jeton)->noterUsage($le, $ip);
        $this->em()->flush();
    }

    private function generateur(): GenerateurDeJetonDApi
    {
        $generateur = self::getContainer()->get(GenerateurDeJetonDApi::class);
        \assert($generateur instanceof GenerateurDeJetonDApi);

        return $generateur;
    }

    /**
     * Ce qu'un appelant peut lire d'un refus : statut, type, corps, en-tête
     * d'authentification, cookies.
     *
     * @return array{statut: int, type: string|null, corps: string|false, wwwAuthenticate: string|null, cookies: int}
     */
    private function signatureDeLaReponse(): array
    {
        $reponse = $this->client->getResponse();

        return [
            'statut' => $reponse->getStatusCode(),
            'type' => $reponse->headers->get('Content-Type'),
            'corps' => $reponse->getContent(),
            'wwwAuthenticate' => $reponse->headers->get('WWW-Authenticate'),
            'cookies' => \count($reponse->headers->getCookies()),
        ];
    }

    /** @return list<AuditLog> */
    private function creationsDeDemande(): array
    {
        return array_values(array_filter(
            $this->journal(),
            static fn (AuditLog $ligne): bool => AuditAction::Creation === $ligne->getAction() && 'Proposal\\ChangeRequest' === $ligne->getSubjectType(),
        ));
    }

    /**
     * Une demande de modification d'un champ texte de la fiche n° 17, soumise.
     *
     * @return array<string, mixed>
     */
    private function demande(string $champ, ?string $ancienne, string $nouvelle): array
    {
        return [
            'kind' => 'modification_personne',
            'message' => null,
            'soumettre' => true,
            'targetKind' => 'personne',
            'targetId' => 17,
            'ops' => [[
                'seq' => 1,
                'opKind' => 'champ',
                'subjectKind' => 'personne',
                'subjectId' => 17,
                'field' => $champ,
                'valueKind' => 'texte',
                'oldValue' => $ancienne,
                'newValue' => $nouvelle,
                'baseVersion' => 7,
            ]],
        ];
    }
}
