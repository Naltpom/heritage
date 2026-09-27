<?php

declare(strict_types=1);

namespace App\Tests\Tirage;

use App\Module\Core\Entity\Notification;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\NotificationKind;
use App\Module\Core\Enum\UserStatus;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Tirage\Entity\Attribution;
use App\Tests\Tree\ArbreWebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les routes des tirages au sort, **de bout en bout et en vrai HTTP** : pare-feu, session,
 * contrôleur, service, adaptateur réel de `Tree` et annuaire réel de `Membership`, sur la
 * vraie base.
 *
 * L'arbre est celui du harnais, entièrement inventé. On s'y sert de deux couples :
 * `aieul` + `aieule`, et `cousin` + `conjointe`.
 *
 * Le cœur de ce fichier est **le secret** : chacun ne lit que son attribution, et celle de
 * qui il accompagne — l'organisateur pas plus qu'un autre.
 */
final class RoutesDesTiragesTest extends ArbreWebTestCase
{
    private const string OPALE = 'opale@example.invalid';   // ego, organisateur
    private const string JASPE = 'jaspe@example.invalid';   // conjointe
    private const string ONYX = 'onyx@example.invalid';     // cousin
    private const string AMBRE = 'ambre@example.invalid';   // aieul, hors du tirage
    private const string TUF = 'tuf@example.invalid';       // aucun rattachement

    protected function setUp(): void
    {
        parent::setUp();

        $this->rattacher(self::OPALE, 'ego');
        $this->rattacher(self::JASPE, 'conjointe');
        $this->rattacher(self::ONYX, 'cousin');
        $this->rattacher(self::AMBRE, 'aieul');
        $this->creerUtilisateur(self::TUF);
    }

    /* ───────────────── Le secret ───────────────── */

    public function testChacunNeVoitQueSonAttributionEtCelleDeQuiIlAccompagne(): void
    {
        $id = $this->tirageDeNoel();

        $this->connexionDe(self::OPALE);
        $this->envoyer('POST', "/api/tirages/{$id}/editions", ['annee' => 2026]);
        self::assertSame(Response::HTTP_CREATED, $this->statut());
        self::assertSame('2026-12-24', $this->charge()['editions'][0]['date'], 'La date de l’édition est le jour habituel du tirage.');

        $this->envoyer('POST', "/api/tirages/{$id}/editions/2026/tirage");
        self::assertSame(Response::HTTP_OK, $this->statut());

        $edition = $this->charge()['editions'][0];
        self::assertSame('tiree', $edition['statut']);
        self::assertSame(5, $edition['nombreDAttributions']);

        // L'organisateur lit la sienne et celle de `sansDate`, qu'il accompagne — rien de plus.
        self::assertEqualsCanonicalizing(
            [$this->idDe('ego'), $this->idDe('sansDate')],
            array_map(static fn (array $a): int => $a['donneur']['personId'], $edition['mesAttributions']),
        );

        foreach ([self::JASPE => 'conjointe', self::ONYX => 'cousin'] as $courriel => $personne) {
            $this->connexionDe($courriel);
            $this->requete("/api/tirages/{$id}");
            self::assertSame(Response::HTTP_OK, $this->statut());

            $siennes = $this->charge()['editions'][0]['mesAttributions'];
            self::assertCount(1, $siennes, \sprintf('%s ne doit lire que sa propre attribution.', $personne));
            self::assertSame($this->idDe($personne), $siennes[0]['donneur']['personId']);
            self::assertNotSame($this->idDe($personne), $siennes[0]['receveur']['personId']);
        }
    }

    public function testLesConjointsNeSeTirentPasEtChacunRecoitUneFois(): void
    {
        $id = $this->tirageDeNoel();
        $this->connexionDe(self::OPALE);
        $this->envoyer('POST', "/api/tirages/{$id}/editions", ['annee' => 2026]);
        $this->envoyer('POST', "/api/tirages/{$id}/editions/2026/tirage");

        $paires = [];
        foreach ($this->em()->getRepository(Attribution::class)->findAll() as $attribution) {
            $paires[$attribution->getDonneurPersonId()] = $attribution->getReceveurPersonId();
        }

        self::assertCount(5, $paires);
        self::assertCount(5, array_unique($paires), 'Chacun reçoit exactement un cadeau.');
        self::assertNotSame($this->idDe('cousin'), $paires[$this->idDe('conjointe')]);
        self::assertNotSame($this->idDe('conjointe'), $paires[$this->idDe('cousin')]);
    }

    /** La notification prévient sans rien révéler : elle ne nomme personne. */
    public function testChaqueParticipantAvecUnCompteEstPrevenuSansQueLaNotificationNeNommeQuiQueCeSoit(): void
    {
        $id = $this->tirageDeNoel();
        $this->connexionDe(self::OPALE);
        $this->envoyer('POST', "/api/tirages/{$id}/editions", ['annee' => 2026]);
        $this->envoyer('POST', "/api/tirages/{$id}/editions/2026/tirage");

        $notifications = $this->em()->getRepository(Notification::class)->findBy(['kind' => NotificationKind::TirageEffectue]);
        $destinataires = array_map(static fn (Notification $n): int => $n->getRecipientUserId(), $notifications);

        self::assertEqualsCanonicalizing(
            [$this->userIdDe(self::OPALE), $this->userIdDe(self::JASPE), $this->userIdDe(self::ONYX)],
            $destinataires,
            'Un compte par participant, et l’organisateur une seule fois bien qu’il accompagne un autre participant.',
        );

        foreach ($notifications as $notification) {
            self::assertSame('tirage.detail', $notification->getLinkRoute());

            foreach (['ego', 'conjointe', 'cousin', 'aieule', 'sansDate'] as $personne) {
                $nom = $this->personnes[$personne]->getDisplayName();
                self::assertStringNotContainsString($nom, $notification->getTitle().' '.$notification->getBody());
            }
        }
    }

    /* ───────────────── Qui voit, qui gère ───────────────── */

    public function testUnTirageFermeEstIntrouvablePourQuiNEnEstPas(): void
    {
        $id = $this->tirageDeNoel();

        $this->connexionDe(self::AMBRE);
        $this->requete('/api/tirages');
        self::assertSame([], $this->charge()['tirages']);

        $this->requete("/api/tirages/{$id}");
        self::assertSame(Response::HTTP_NOT_FOUND, $this->statut());

        $this->envoyer('POST', "/api/tirages/{$id}/rejoindre");
        self::assertSame(Response::HTTP_NOT_FOUND, $this->statut());
    }

    public function testUnTirageOuvertSeRejointSiLOnEstRattacheALArbre(): void
    {
        $id = $this->tirageDeNoel(ouvert: true);

        $this->connexionDe(self::AMBRE);
        $this->requete('/api/tirages');
        self::assertSame(['personId' => $this->idDe('aieul'), 'libelle' => 'Émeri Sarriette'], $this->charge()['moi']);
        $liste = $this->charge()['tirages'];
        self::assertCount(1, $liste);
        self::assertTrue($liste[0]['peutRejoindre']);

        $this->envoyer('POST', "/api/tirages/{$id}/rejoindre");
        self::assertSame(Response::HTTP_CREATED, $this->statut());
        self::assertTrue($this->charge()['suisParticipant']);

        $this->connexionDe(self::TUF);
        $this->requete('/api/tirages');
        self::assertNull($this->charge()['moi'], 'Un compte sans rattachement n’a pas de personne.');
        self::assertFalse($this->charge()['tirages'][0]['peutRejoindre']);

        $this->envoyer('POST', "/api/tirages/{$id}/rejoindre");
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
        self::assertSame('rattachement_requis', $this->codeDErreur());
    }

    public function testSeulLOrganisateurLanceLeTirage(): void
    {
        $id = $this->tirageDeNoel();
        $this->connexionDe(self::OPALE);
        $this->envoyer('POST', "/api/tirages/{$id}/editions", ['annee' => 2026]);

        $this->connexionDe(self::JASPE);
        $this->envoyer('POST', "/api/tirages/{$id}/editions/2026/tirage");
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statut());
        self::assertSame('reserve_a_l_organisateur', $this->codeDErreur());
    }

    public function testUnCompteEnAttenteNAccedePasAuxTirages(): void
    {
        $this->creerUtilisateur('attente@example.invalid', statut: UserStatus::EnAttente);
        $this->connexionDe('attente@example.invalid');

        $this->requete('/api/tirages');
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statut());
    }

    /* ───────────────── Présences et annulation ───────────────── */

    public function testQuiNeVientPasNEntrePasDansLeTirageEtNePeutPlusSeRetirerEnsuite(): void
    {
        $id = $this->tirageDeNoel();
        $this->connexionDe(self::OPALE);
        $this->envoyer('POST', "/api/tirages/{$id}/editions", ['annee' => 2026]);

        $this->envoyer('PUT', "/api/tirages/{$id}/editions/2026/presences/{$this->idDe('aieule')}", ['presence' => 'absente']);
        self::assertSame(Response::HTTP_OK, $this->statut());

        $this->envoyer('POST', "/api/tirages/{$id}/editions/2026/tirage");
        self::assertSame(4, $this->charge()['editions'][0]['nombreDAttributions']);

        // Jaspe vient, mais de loin : c'est permis, elle reste dans le tirage.
        $this->connexionDe(self::JASPE);
        $this->envoyer('PUT', "/api/tirages/{$id}/editions/2026/presences/{$this->idDe('conjointe')}", ['presence' => 'a_distance']);
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertSame('a_distance', $this->charge()['editions'][0]['mesAttributions'][0]['presenceDuDonneur']);

        // Se retirer après le tirage laisserait quelqu'un sans cadeau.
        $this->envoyer('PUT', "/api/tirages/{$id}/editions/2026/presences/{$this->idDe('conjointe')}", ['presence' => 'absente']);
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
        self::assertSame('edition_deja_tiree', $this->codeDErreur());

        // Et l'on ne règle pas la présence de quelqu'un d'autre.
        $this->envoyer('PUT', "/api/tirages/{$id}/editions/2026/presences/{$this->idDe('cousin')}", ['presence' => 'a_distance']);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statut());
    }

    public function testLOrganisateurPeutAnnulerPuisRefaireLeTirage(): void
    {
        $id = $this->tirageDeNoel();
        $this->connexionDe(self::OPALE);
        $this->envoyer('POST', "/api/tirages/{$id}/editions", ['annee' => 2026]);
        $this->envoyer('POST', "/api/tirages/{$id}/editions/2026/tirage");

        $this->envoyer('POST', "/api/tirages/{$id}/editions/2026/tirage");
        self::assertSame(Response::HTTP_CONFLICT, $this->statut(), 'On ne retire pas au sort une édition déjà tirée.');

        $this->envoyer('DELETE', "/api/tirages/{$id}/editions/2026/tirage");
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertSame('ouverte', $this->charge()['editions'][0]['statut']);
        self::assertSame([], $this->charge()['editions'][0]['mesAttributions']);
        self::assertCount(0, $this->em()->getRepository(Attribution::class)->findAll());

        $this->envoyer('POST', "/api/tirages/{$id}/editions/2026/tirage");
        self::assertSame(Response::HTTP_OK, $this->statut());
    }

    public function testOnNeQuittePasUnTirageFaitEtAVenirMaisLOrganisateurPeutToutSupprimer(): void
    {
        $id = $this->tirageDeNoel();
        $this->connexionDe(self::OPALE);
        $annee = (int) date('Y') + 1;
        $this->envoyer('POST', "/api/tirages/{$id}/editions", ['annee' => $annee]);
        $this->envoyer('POST', "/api/tirages/{$id}/editions/{$annee}/tirage");

        $this->connexionDe(self::ONYX);
        $this->envoyer('DELETE', "/api/tirages/{$id}/participants/{$this->idDe('cousin')}");
        self::assertSame(Response::HTTP_CONFLICT, $this->statut(), 'Quitter laisserait quelqu’un sans cadeau.');

        $this->envoyer('DELETE', "/api/tirages/{$id}");
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statut());

        $this->connexionDe(self::OPALE);
        $this->envoyer('DELETE', "/api/tirages/{$id}");
        self::assertSame(Response::HTTP_NO_CONTENT, $this->statut());

        $this->requete("/api/tirages/{$id}");
        self::assertSame(Response::HTTP_NOT_FOUND, $this->statut());
        self::assertCount(0, $this->em()->getRepository(Attribution::class)->findAll(), 'Les attributions partent avec le tirage.');
    }

    /* ───────────────── Validation ───────────────── */

    public function testUnReglageInvalideNommeChaqueChampFautif(): void
    {
        $this->connexionDe(self::OPALE);
        $this->envoyer('POST', '/api/tirages', ['nom' => '', 'jour' => 31, 'mois' => 13, 'memoire' => 99]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
        $erreur = $this->charge()['erreur'];
        self::assertIsArray($erreur);
        self::assertEqualsCanonicalizing(['nom', 'mois', 'memoire'], array_keys($erreur['champs']));
    }

    public function testUnParticipantDoitExisterDansLArbre(): void
    {
        $this->connexionDe(self::OPALE);
        $this->envoyer('POST', '/api/tirages', $this->regles() + ['participants' => [$this->idDe('ego'), 999999]]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
    }

    /* ───────────────── Outils ───────────────── */

    /**
     * « Noël côté papa » : ego, conjointe, cousin et aieule, puis `sansDate` — qui n'a pas
     * de compte — accompagné par ego.
     */
    private function tirageDeNoel(bool $ouvert = false): int
    {
        $this->connexionDe(self::OPALE);
        $this->envoyer('POST', '/api/tirages', ['ouvert' => $ouvert] + $this->regles() + ['participants' => [
            $this->idDe('ego'),
            $this->idDe('conjointe'),
            $this->idDe('cousin'),
            $this->idDe('aieule'),
        ]]);
        self::assertSame(Response::HTTP_CREATED, $this->statut(), (string) $this->client->getResponse()->getContent());

        $id = $this->charge()['id'];
        self::assertIsInt($id);

        $this->envoyer('POST', "/api/tirages/{$id}/participants", [
            'personId' => $this->idDe('sansDate'),
            'referentPersonId' => $this->idDe('ego'),
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->statut(), (string) $this->client->getResponse()->getContent());

        return $id;
    }

    /** @return array<string, mixed> */
    private function regles(): array
    {
        return ['nom' => 'Noël côté papa', 'jour' => 24, 'mois' => 12, 'lieu' => 'Chez les grands-parents', 'memoire' => 2, 'exclureConjoints' => true];
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

    private function codeDErreur(): mixed
    {
        $erreur = $this->charge()['erreur'] ?? null;
        self::assertIsArray($erreur);

        return $erreur['code'] ?? null;
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
