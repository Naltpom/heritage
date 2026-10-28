<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\PorteeDeJeton;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use App\Tests\Core\CoreWebTestCase;
use App\Tests\Proposal\Double\ArbreEnMemoire;
use App\Tests\Proposal\Double\NotificateurEnMemoire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le dépôt à blanc en HTTP : `POST /api/demandes/verifier` (§ 6.1 ter du contrat d'API,
 * ADR 0022).
 *
 * Ce que la route promet, et que chaque test tient :
 *
 * | Promesse | Test |
 * |---|---|
 * | `204` sans corps, et rien d'écrit ni de journalisé | {@see self::testUnCorpsValideRend204SansCorpsEtNEcritRien()} |
 * | une `422` porte toutes les erreurs d'une passe, là où le dépôt n'en rend qu'une | {@see self::testUnCorpsFautifRend422AvecToutesSesErreurs()} |
 * | une erreur seule garde son code et son message | {@see self::testUneSeuleErreurGardeSonCodeEtSonMessage()} |
 * | `soumettre` est lu comme au dépôt | {@see self::testSoumettreFauxDispenseDuControleDeCompletude()} |
 * | le statut est celui que rendrait le dépôt | {@see self::testLeStatutEstCeluiQueRendraitLeDepot()} |
 * | portée `contribution`, bascule « voir comme » refusée | les deux derniers tests sur les garde-fous |
 *
 * Par défaut, tout passe par un jeton `contribution` du compte d'administration : c'est
 * le client que la route sert, un agent qui vérifie avant de déposer. Le service lui-même
 * est éprouvé par {@see DepotABlancTest}.
 */
final class VerificationDuDepotEnHttpTest extends CoreWebTestCase
{
    private const string HORS_PORTEE = 'Ce jeton d’API n’ouvre pas cette route.';

    private const string MESSAGE_MULTIPLE = 'La demande porte plusieurs erreurs : chacune est nommée dans « champs ».';

    /** Marne Dolomite Sureau, la fiche que les demandes modifient. */
    private const int PERSONNE = 17;

    private const int VERSION_DE_DEPART = 7;

    /** La personne d'Opale, membre de la famille : distincte de la fiche modifiée. */
    private const int PERSONNE_D_OPALE = 41;

    protected function setUp(): void
    {
        parent::setUp();

        // L'arbre et le notificateur des tests de `Proposal`, que `when@test` branche à la
        // place des vrais (`config/services/proposal.yaml`). Leur état est statique : on le
        // remet à zéro ici.
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
        ]);
    }

    protected function tearDown(): void
    {
        ArbreEnMemoire::oublierTout();
        NotificateurEnMemoire::oublierTout();

        parent::tearDown();
    }

    public function testUnCorpsValideRend204SansCorpsEtNEcritRien(): void
    {
        $jeton = $this->creerJetonDApi($this->administration());

        $this->verifier($this->demande('surname', 'Sureau', 'SUREAU'), $jeton);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame('', $this->client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $this->assertRienNEstEcrit($jeton);
    }

    public function testUnCorpsFautifRend422AvecToutesSesErreurs(): void
    {
        $jeton = $this->creerJetonDApi($this->administration());
        $corps = $this->demande('surname', 'Sureau', 'SUREAU');
        $corps['message'] = 42;
        $corps['ops'] = [$this->opFaux0(), $this->opFaux1()];
        $corps['soumettre'] = 'oui';

        $this->verifier($corps, $jeton);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $erreur = $this->erreur();
        self::assertSame('donnees_invalides', $erreur['code']);
        self::assertSame(self::MESSAGE_MULTIPLE, $erreur['message']);
        self::assertIsArray($erreur['champs']);
        self::assertSame(['message', 'ops[0].opKind', 'ops[1].subjectKind', 'soumettre'], array_keys($erreur['champs']));
        self::assertNull($erreur['details']);

        // Le dépôt du même corps s'arrête à la première.
        $this->requete('POST', '/api/demandes', $corps, $this->bearer($jeton));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertIsArray($this->erreur()['champs']);
        self::assertSame(['message'], array_keys($this->erreur()['champs']));

        $this->assertRienNEstEcrit($jeton);
    }

    public function testUneSeuleErreurGardeSonCodeEtSonMessage(): void
    {
        $jeton = $this->creerJetonDApi($this->administration());

        $this->verifier($this->creationSansLien(soumettre: true), $jeton);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('demande_sans_lien', $this->erreur()['code']);
        self::assertSame('Sans lien, la personne proposée flotterait hors de l\'arbre.', $this->erreur()['message']);
        self::assertIsArray($this->erreur()['champs']);
        self::assertSame(['liens'], array_keys($this->erreur()['champs']));
    }

    /** Le dépôt d'un brouillon ne contrôle pas la complétude : le dépôt à blanc non plus. */
    public function testSoumettreFauxDispenseDuControleDeCompletude(): void
    {
        $jeton = $this->creerJetonDApi($this->administration());

        $this->verifier($this->creationSansLien(soumettre: false), $jeton);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    /** Une erreur par fiche ; les liens, qui désignent les fiches, ne sont pas jugés. */
    public function testDeuxFichesFautivesSontNommeesToutesLesDeux(): void
    {
        $jeton = $this->creerJetonDApi($this->administration());
        $corps = $this->creationSansLien(soumettre: true);
        $corps['brouillons'] = [$this->fiche('#p1', sexe: 'zzz'), $this->fiche('#p2', sexe: 'zzz')];

        $this->verifier($corps, $jeton);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertIsArray($this->erreur()['champs']);
        $cles = array_keys($this->erreur()['champs']);
        self::assertCount(2, $cles);
        self::assertStringStartsWith('brouillons[0]', $cles[0]);
        self::assertStringStartsWith('brouillons[1]', $cles[1]);
    }

    /** Une `oldValue` périmée : `409 conflit`, au dépôt comme à blanc. */
    public function testLeStatutEstCeluiQueRendraitLeDepot(): void
    {
        $jeton = $this->creerJetonDApi($this->administration());
        $corps = $this->demande('surname', 'Faux', 'SUREAU');

        $this->verifier($corps, $jeton);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('conflit', $this->erreur()['code']);

        $this->requete('POST', '/api/demandes', $corps, $this->bearer($jeton));
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('conflit', $this->erreur()['code']);
    }

    public function testLaPorteeContributionOuvreLaVerificationEtLaLectureNon(): void
    {
        $administration = $this->administration();
        $corps = $this->demande('surname', 'Sureau', 'SUREAU');

        $this->verifier($corps, $this->creerJetonDApi($administration, PorteeDeJeton::Lecture, 'Lecture seule'));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('acces_refuse', $this->erreur()['code']);
        self::assertSame(self::HORS_PORTEE, $this->erreur()['message']);

        foreach ([PorteeDeJeton::Contribution, PorteeDeJeton::Validation] as $portee) {
            $this->verifier($corps, $this->creerJetonDApi($administration, $portee, 'Jeton '.$portee->value));
            self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT, $portee->value);
        }
    }

    /** La route n'écrit pas, mais elle prédit une écriture : sous la bascule, le dépôt rendrait `409`. */
    public function testLaBasculeVoirCommeRefuseLaVerification(): void
    {
        $this->administration();
        $membre = $this->opale();

        $this->connexion('administration@example.net');
        $this->requete('POST', '/api/administration/voir-comme', ['memberId' => $membre]);
        self::assertResponseIsSuccessful();

        $this->requete('POST', '/api/demandes/verifier', $this->demande('surname', 'Sureau', 'SUREAU'));

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('vue_comme_membre_active', $this->erreur()['code']);
        self::assertIsArray($this->erreur()['details']);
        self::assertNotNull($this->erreur()['details']['memberLabel'] ?? null, 'Le libellé réel du membre, que seule la bascule connaît.');
    }

    /** Rien n'est écrit, il n'y a rien à rejouer : l'en-tête n'est ni lu ni validé, même mal formé. */
    public function testLEnTeteIdempotencyKeyEstIgnore(): void
    {
        $jeton = $this->creerJetonDApi($this->administration());

        $this->verifier($this->demande('surname', 'Sureau', 'SUREAU'), $jeton, ['Idempotency-Key' => 'x']);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    // ─── Outils ──────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>  $corps
     * @param array<string, string> $entetes
     */
    private function verifier(array $corps, string $jeton, array $entetes = []): void
    {
        $this->requete('POST', '/api/demandes/verifier', $corps, $this->bearer($jeton) + $entetes);
    }

    /** Aucune demande pour le compte, et aucune ligne de journal du module. */
    private function assertRienNEstEcrit(string $jeton): void
    {
        $this->requete('GET', '/api/demandes', entetes: $this->bearer($jeton));
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->charge()['total']);

        self::assertSame([], array_values(array_filter(
            $this->journal(),
            static fn (AuditLog $ligne): bool => str_starts_with($ligne->getSubjectType(), 'Proposal\\'),
        )), 'Une vérification ne laisse aucune trace au journal.');
    }

    /** @return array<string, mixed> */
    private function erreur(): array
    {
        $erreur = $this->charge()['erreur'];
        self::assertIsArray($erreur);

        /* @var array<string, mixed> $erreur */
        return $erreur;
    }

    /** Le compte du commanditaire, celui des deux jetons du plan : actif, administrateur, sans fiche. */
    private function administration(): User
    {
        return $this->creerUtilisateur('administration@example.net', roles: ['ROLE_ADMIN'], nomAffiche: 'Administration');
    }

    /**
     * Opale, membre de la famille : un compte actif, rattaché à sa fiche. Rend l'identifiant
     * de son `Member`, celui que la bascule « voir comme » attend.
     */
    private function opale(): int
    {
        $opale = $this->creerUtilisateur('opale@example.net', nomAffiche: 'Opale Romarin');
        $id = $opale->getId();
        \assert(null !== $id);

        $membre = new Member($id, $opale->getEmail(), self::PERSONNE_D_OPALE, 'Opale Romarin', MatchKind::PreLie);
        $membre->setStatus(MemberStatus::Actif);
        $membre->activer();
        $this->em()->persist($membre);
        $this->em()->flush();

        $membreId = $membre->getId();
        \assert(null !== $membreId);

        return $membreId;
    }

    /** @return array<string, mixed> */
    private function demande(string $champ, ?string $ancienne, string $nouvelle): array
    {
        return [
            'kind' => 'modification_personne',
            'message' => null,
            'soumettre' => true,
            'targetKind' => 'personne',
            'targetId' => self::PERSONNE,
            'ops' => [$this->op($champ, $ancienne, $nouvelle)],
        ];
    }

    /** @return array<string, mixed> */
    private function op(string $champ, ?string $ancienne, string $nouvelle, int $seq = 1): array
    {
        return [
            'seq' => $seq,
            'opKind' => 'champ',
            'subjectKind' => 'personne',
            'subjectId' => self::PERSONNE,
            'field' => $champ,
            'valueKind' => 'texte',
            'oldValue' => $ancienne,
            'newValue' => $nouvelle,
            'baseVersion' => self::VERSION_DE_DEPART,
        ];
    }

    /**
     * Le premier op faux : une nature d'op inconnue.
     *
     * @return array<string, mixed>
     */
    private function opFaux0(): array
    {
        return ['opKind' => 'zzz'] + $this->op('surname', 'Sureau', 'SUREAU');
    }

    /**
     * Le second, au rang 2 : une nature de sujet inconnue.
     *
     * @return array<string, mixed>
     */
    private function opFaux1(): array
    {
        return ['subjectKind' => 'zzz'] + $this->op('nickname', null, 'Mimi', seq: 2);
    }

    /** @return array<string, mixed> */
    private function creationSansLien(bool $soumettre): array
    {
        return [
            'kind' => 'creation_personne',
            'message' => 'épouse de Jargon, j\'ai le livret de famille',
            'soumettre' => $soumettre,
            'brouillons' => [$this->fiche('#p1')],
            'liens' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function fiche(string $reference, string $sexe = 'feminin'): array
    {
        return [
            'localRef' => $reference,
            'givenNames' => 'Tourmaline',
            'surname' => 'Muguet',
            'marriedName' => null,
            'nickname' => null,
            'sex' => $sexe,
            'deceased' => true,
            'birth' => ['raw' => '1889', 'qualifier' => 'annee', 'min' => '1889-01-01', 'max' => '1889-12-31', 'place' => null],
            'death' => ['raw' => '1972', 'qualifier' => 'annee', 'min' => '1972-01-01', 'max' => '1972-12-31', 'place' => null],
            'note' => null,
        ];
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
