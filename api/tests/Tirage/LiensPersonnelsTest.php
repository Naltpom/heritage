<?php

declare(strict_types=1);

namespace App\Tests\Tirage;

use App\Module\Tirage\Entity\Participant;
use App\Module\Tirage\Service\LimiteurDesLiens;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le lien personnel d'un participant — `/t/<clé>` côté front, `/api/tirages/lien/{clé}`
 * ici —, **de bout en bout, sans session** (ADR 0009, révision du 2026-10-01).
 *
 * Trois propriétés tiennent la sécurité de ce lien, et chacune a son test :
 *
 * 1. **il ne montre que son participant** — ni les autres, ni leurs présences, et un seul
 *    nom de plus : celui du receveur ;
 * 2. **un seul refus** pour toutes les façons de ne plus valoir — la même `404`, au même
 *    corps ;
 * 3. **seule l'empreinte est stockée**, et la clé ne se relit pas : régénérer tue l'ancienne.
 */
final class LiensPersonnelsTest extends CasDeTestTirages
{
    private int $annee;

    protected function setUp(): void
    {
        parent::setUp();

        // L'année prochaine : l'édition est à venir quelle que soit la date du jour.
        $this->annee = (int) date('Y') + 1;
    }

    /* ───────────────── Ce que le lien montre ───────────────── */

    public function testLaPersonneOuvreSonLienSansCompteEtNeVoitQueCeQuiLaConcerne(): void
    {
        $id = $this->tirageDeNoel();
        $this->envoyer('POST', "/api/tirages/{$id}/editions", ['annee' => $this->annee]);
        $cle = $this->creerLien($id, 'enfant');

        // Le détail dit qu'un lien existe — jamais sa clé.
        $this->requete("/api/tirages/{$id}");
        $participants = array_column($this->charge()['participants'], null, 'personId');
        self::assertTrue($participants[$this->idDe('enfant')]['lienActif']);
        self::assertFalse($participants[$this->idDe('cousin')]['lienActif']);
        self::assertStringNotContainsString($cle, (string) $this->client->getResponse()->getContent());

        $this->deconnexion();
        $this->requete("/api/tirages/lien/{$cle}");
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        self::assertSame([
            'tirage' => ['nom' => 'Noël côté papa', 'lieu' => 'Chez les grands-parents'],
            'edition' => ['annee' => $this->annee, 'date' => $this->annee.'-12-24', 'statut' => 'ouverte', 'passee' => false],
            'participant' => ['personId' => $this->idDe('enfant'), 'libelle' => 'Mica Romarin', 'presence' => 'sur_place', 'peutChangerPresence' => true],
            'attributions' => [],
        ], $this->charge());

        $this->assertAucunAutreNom((string) $this->client->getResponse()->getContent(), ['enfant']);
    }

    public function testApresLeTirageLeLienDitAQuiOffrirEtSeulementCela(): void
    {
        $id = $this->tirageDeNoel();
        $this->envoyer('POST', "/api/tirages/{$id}/editions", ['annee' => $this->annee]);
        // `tante` (qui a un compte) est accompagnée par `oncle`, qui n'en a pas : le lien
        // d'oncle doit montrer les deux attributions, comme `mesAttributions`.
        $this->envoyer('POST', "/api/tirages/{$id}/participants", ['personId' => $this->idDe('tante'), 'referentPersonId' => $this->idDe('oncle')]);
        self::assertSame(Response::HTTP_CREATED, $this->statut());
        $cleEnfant = $this->creerLien($id, 'enfant');
        $cleOncle = $this->creerLien($id, 'oncle');
        $this->envoyer('POST', "/api/tirages/{$id}/editions/{$this->annee}/tirage");
        self::assertSame(Response::HTTP_OK, $this->statut());

        $this->deconnexion();
        $this->requete("/api/tirages/lien/{$cleEnfant}");
        $charge = $this->charge();
        self::assertSame('tiree', $charge['edition']['statut']);
        self::assertFalse($charge['participant']['peutChangerPresence'], 'Après le tirage, la présence ne se règle plus par le lien.');
        self::assertCount(1, $charge['attributions']);
        self::assertSame($this->idDe('enfant'), $charge['attributions'][0]['donneur']['personId']);
        self::assertSame('sur_place', $charge['attributions'][0]['presenceDuDonneur']);

        // Un seul autre nom : celui du receveur.
        $receveur = $charge['attributions'][0]['receveur']['personId'];
        $cleDuReceveur = array_search($receveur, array_map(fn ($p): ?int => $p->getId(), $this->personnes), true);
        self::assertIsString($cleDuReceveur);
        $this->assertAucunAutreNom((string) $this->client->getResponse()->getContent(), ['enfant', $cleDuReceveur]);

        // Le lien d'un référent suit la règle de `mesAttributions` : la sienne, et celle de qui il accompagne.
        $this->requete("/api/tirages/lien/{$cleOncle}");
        self::assertEqualsCanonicalizing(
            [$this->idDe('oncle'), $this->idDe('tante')],
            array_map(static fn (array $a): int => $a['donneur']['personId'], $this->charge()['attributions']),
        );

        $this->envoyer('PUT', "/api/tirages/lien/{$cleEnfant}/presence", ['presence' => 'a_distance']);
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
        self::assertSame('presence_figee', $this->codeDErreur());
    }

    public function testSansEditionLeLienNeMontreQueLeTirage(): void
    {
        $id = $this->tirageDeNoel();
        $cle = $this->creerLien($id, 'enfant');

        $this->deconnexion();
        $this->requete("/api/tirages/lien/{$cle}");
        self::assertNull($this->charge()['edition']);
        self::assertSame(['personId' => $this->idDe('enfant'), 'libelle' => 'Mica Romarin', 'presence' => null, 'peutChangerPresence' => false], $this->charge()['participant']);

        $this->envoyer('PUT', "/api/tirages/lien/{$cle}/presence", ['presence' => 'absente']);
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
    }

    /* ───────────────── Ce que le lien écrit ───────────────── */

    public function testLaPersonneRegleSaPresenceTantQueLEditionEstOuverteEtAVenir(): void
    {
        $id = $this->tirageDeNoel();
        $this->envoyer('POST', "/api/tirages/{$id}/editions", ['annee' => $this->annee]);
        $cle = $this->creerLien($id, 'enfant');

        $this->deconnexion();
        $this->envoyer('PUT', "/api/tirages/lien/{$cle}/presence", ['presence' => 'a_distance']);
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertSame('a_distance', $this->charge()['participant']['presence']);

        $this->envoyer('PUT', "/api/tirages/lien/{$cle}/presence", ['presence' => 'peut-etre']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
        self::assertSame('donnees_invalides', $this->codeDErreur());

        $this->envoyer('PUT', "/api/tirages/lien/{$cle}/presence", ['presence' => 'absente']);
        self::assertSame(Response::HTTP_OK, $this->statut());

        // L'organisateur le voit sur sa page, comme s'il l'avait réglé lui-même.
        $this->connexionDe(self::OPALE);
        $this->requete("/api/tirages/{$id}");
        $presences = array_column($this->charge()['editions'][0]['presences'], 'presence', 'personId');
        self::assertSame('absente', $presences[$this->idDe('enfant')]);
    }

    public function testUneEditionPasseeNeSeRegleplusParLeLien(): void
    {
        $id = $this->tirageDeNoel();
        $this->envoyer('POST', "/api/tirages/{$id}/editions", ['annee' => 2020, 'date' => '2020-12-24']);
        $cle = $this->creerLien($id, 'enfant');

        $this->deconnexion();
        $this->requete("/api/tirages/lien/{$cle}");
        self::assertTrue($this->charge()['edition']['passee']);
        self::assertFalse($this->charge()['participant']['peutChangerPresence']);

        $this->envoyer('PUT', "/api/tirages/lien/{$cle}/presence", ['presence' => 'a_distance']);
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
        self::assertSame('presence_figee', $this->codeDErreur());
    }

    /* ───────────────── Un seul refus ───────────────── */

    /**
     * Mal formée, inconnue, révoquée, remplacée, participant retiré, tirage supprimé : la
     * **même** réponse, au même corps. Distinguer l'une de l'autre dirait au porteur d'une
     * vieille clé qu'elle a existé.
     */
    public function testToutesLesClesQuiNOuvrentRienRendentLaMemeReponse(): void
    {
        $id = $this->tirageDeNoel();
        $this->envoyer('POST', "/api/tirages/{$id}/editions", ['annee' => $this->annee]);

        $revoquee = $this->creerLien($id, 'enfant');
        $this->envoyer('DELETE', "/api/tirages/{$id}/participants/{$this->idDe('enfant')}/lien");
        self::assertSame(Response::HTTP_NO_CONTENT, $this->statut());

        $remplacee = $this->creerLien($id, 'enfant');
        $valide = $this->creerLien($id, 'enfant');
        self::assertNotSame($remplacee, $valide);

        $retire = $this->creerLien($id, 'oncle');
        $this->envoyer('DELETE', "/api/tirages/{$id}/participants/{$this->idDe('oncle')}");
        self::assertSame(Response::HTTP_OK, $this->statut());

        $this->envoyer('POST', '/api/tirages', $this->regles() + ['participants' => [$this->idDe('ego'), $this->idDe('oncle'), $this->idDe('cousin')]]);
        $autre = $this->charge()['id'];
        $supprime = $this->creerLien($autre, 'oncle');
        $this->envoyer('DELETE', "/api/tirages/{$autre}");
        self::assertSame(Response::HTTP_NO_CONTENT, $this->statut());

        $this->deconnexion();
        $corps = [];

        foreach ([
            'inconnue' => str_repeat('A', 43),
            'mal formée' => 'trop-courte',
            'révoquée' => $revoquee,
            'remplacée' => $remplacee,
            'participant retiré' => $retire,
            'tirage supprimé' => $supprime,
        ] as $cas => $cle) {
            $this->requete('/api/tirages/lien/'.rawurlencode($cle));
            self::assertSame(Response::HTTP_NOT_FOUND, $this->statut(), $cas);
            self::assertSame('lien_invalide', $this->codeDErreur(), $cas);
            $corps[$cas] = (string) $this->client->getResponse()->getContent();

            $this->envoyer('PUT', '/api/tirages/lien/'.rawurlencode($cle).'/presence', ['presence' => 'absente']);
            self::assertSame(Response::HTTP_NOT_FOUND, $this->statut(), $cas);
        }

        self::assertCount(1, array_unique($corps), 'Un seul corps de refus, quel que soit le cas.');

        $this->requete("/api/tirages/lien/{$valide}");
        self::assertSame(Response::HTTP_OK, $this->statut(), 'La clé régénérée, elle, vaut.');
    }

    /* ───────────────── Qui gère le lien ───────────────── */

    public function testSeulsLOrganisateurEtLeReferentGerentLeLien(): void
    {
        $id = $this->tirageDeNoel();
        // `tante`, accompagnée par `conjointe` (Jaspe) — qui n'organise pas.
        $this->envoyer('POST', "/api/tirages/{$id}/participants", ['personId' => $this->idDe('tante'), 'referentPersonId' => $this->idDe('conjointe')]);
        self::assertSame(Response::HTTP_CREATED, $this->statut());

        $this->connexionDe(self::JASPE);
        $this->requete("/api/tirages/{$id}");
        $participants = array_column($this->charge()['participants'], null, 'personId');
        self::assertTrue($participants[$this->idDe('tante')]['peutPartagerLien']);
        self::assertFalse($participants[$this->idDe('enfant')]['peutPartagerLien']);

        $this->envoyer('POST', "/api/tirages/{$id}/participants/{$this->idDe('tante')}/lien");
        self::assertSame(Response::HTTP_CREATED, $this->statut(), 'Le référent partage le lien de qui il accompagne.');

        $this->envoyer('POST', "/api/tirages/{$id}/participants/{$this->idDe('enfant')}/lien");
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statut());
        self::assertSame('acces_refuse', $this->codeDErreur());

        $this->connexionDe(self::ONYX);
        $this->envoyer('DELETE', "/api/tirages/{$id}/participants/{$this->idDe('tante')}/lien");
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statut());

        // Un tirage fermé reste introuvable pour qui n'en est pas — lien compris.
        $this->connexionDe(self::TUF);
        $this->envoyer('POST', "/api/tirages/{$id}/participants/{$this->idDe('tante')}/lien");
        self::assertSame(Response::HTTP_NOT_FOUND, $this->statut());

        // Personne n'est hors session sur ces deux routes-là.
        $this->deconnexion();
        $this->envoyer('POST', "/api/tirages/{$id}/participants/{$this->idDe('tante')}/lien");
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->statut());
    }

    /**
     * Le secret tient contre l'organisateur (ADR 0009) : il ne fabrique pas la clé d'un
     * participant qui a son compte — il y lirait son attribution —, ni celle d'un participant
     * accompagné par quelqu'un d'autre. Avant comme après le tirage.
     */
    public function testLOrganisateurNeLitPasLAttributionDUnParticipantQuiASonCompte(): void
    {
        $id = $this->tirageDeNoel();
        $this->envoyer('POST', "/api/tirages/{$id}/participants", ['personId' => $this->idDe('tante'), 'referentPersonId' => $this->idDe('conjointe')]);
        self::assertSame(Response::HTTP_CREATED, $this->statut());
        $this->envoyer('POST', "/api/tirages/{$id}/editions", ['annee' => $this->annee]);

        $this->requete("/api/tirages/{$id}");
        $participants = array_column($this->charge()['participants'], null, 'personId');
        self::assertFalse($participants[$this->idDe('cousin')]['peutPartagerLien'], 'Cousin a son compte.');
        self::assertFalse($participants[$this->idDe('tante')]['peutPartagerLien'], 'Tante est accompagnée par Jaspe.');
        self::assertTrue($participants[$this->idDe('oncle')]['peutPartagerLien'], 'Oncle n’a ni compte ni référent.');
        self::assertTrue($participants[$this->idDe('enfant')]['peutPartagerLien'], 'Opale accompagne enfant.');

        $this->envoyer('POST', "/api/tirages/{$id}/editions/{$this->annee}/tirage");
        self::assertSame(Response::HTTP_OK, $this->statut());

        foreach (['cousin', 'conjointe', 'tante'] as $personne) {
            $this->envoyer('POST', "/api/tirages/{$id}/participants/{$this->idDe($personne)}/lien");
            self::assertSame(Response::HTTP_FORBIDDEN, $this->statut(), $personne);
            self::assertSame('acces_refuse', $this->codeDErreur(), $personne);
            self::assertArrayNotHasKey('cle', $this->charge(), $personne);
        }
    }

    /**
     * Un lien créé par l'organisateur pour une personne sans compte cesse de valoir quand
     * elle en ouvre un : l'organisateur, qui a eu la clé, ne lit plus son attribution.
     */
    public function testLeLienDUnParticipantQuiOuvreUnCompteCesseDeValoir(): void
    {
        $id = $this->tirageDeNoel();
        $cle = $this->creerLien($id, 'oncle');

        $this->deconnexion();
        $this->requete("/api/tirages/lien/{$cle}");
        self::assertSame(Response::HTTP_OK, $this->statut());

        $this->rattacher('basalte@example.invalid', 'oncle');

        $this->requete("/api/tirages/lien/{$cle}");
        self::assertSame(Response::HTTP_NOT_FOUND, $this->statut());
        self::assertSame('lien_invalide', $this->codeDErreur());

        $this->connexionDe(self::OPALE);
        $this->envoyer('POST', "/api/tirages/{$id}/participants/{$this->idDe('oncle')}/lien");
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statut(), 'Il ne se régénère pas non plus.');
    }

    public function testSeuleLEmpreinteEstStockee(): void
    {
        $id = $this->tirageDeNoel();
        $cle = $this->creerLien($id, 'enfant');

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $cle);

        $this->em()->clear();
        $participant = $this->em()->getRepository(Participant::class)->findOneBy(['personId' => $this->idDe('enfant')]);
        self::assertNotNull($participant);
        self::assertTrue($participant->aUnLienActif());

        $ligne = $this->em()->getConnection()->fetchAssociative('SELECT lien_token_hash FROM trg_participant WHERE id = ?', [$participant->getId()]);
        self::assertIsArray($ligne);
        self::assertSame(hash('sha256', $cle), $ligne['lien_token_hash']);
    }

    /* ───────────────── Le limiteur ───────────────── */

    public function testUneAdresseQuiEnchaineLesClesInconnuesEstArretee(): void
    {
        $id = $this->tirageDeNoel();
        $cle = $this->creerLien($id, 'enfant');
        $this->deconnexion();

        for ($i = 0; $i < LimiteurDesLiens::ECHECS_PAR_IP; ++$i) {
            $this->requete('/api/tirages/lien/'.str_pad((string) $i, 43, 'x'));
            self::assertSame(Response::HTTP_NOT_FOUND, $this->statut());
        }

        // Même une clé valide : c'est l'adresse qui est arrêtée, pas la clé.
        $this->requete("/api/tirages/lien/{$cle}");
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $this->statut());
        self::assertSame('trop_de_tentatives', $this->codeDErreur());
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    /* ───────────────── Outils ───────────────── */

    /**
     * Crée (ou régénère) le lien d'un participant, en organisateur, et rend la clé. Seulement
     * pour `enfant` (Opale l'accompagne) ou un participant sans compte ni référent (`oncle`).
     */
    private function creerLien(int $tirage, string $personne): string
    {
        $this->connexionDe(self::OPALE);
        $this->envoyer('POST', "/api/tirages/{$tirage}/participants/{$this->idDe($personne)}/lien");
        self::assertSame(Response::HTTP_CREATED, $this->statut(), (string) $this->client->getResponse()->getContent());

        $charge = $this->charge();
        self::assertIsString($charge['cle']);
        self::assertSame('/t/'.$charge['cle'], $charge['url']);

        return $charge['cle'];
    }

    /**
     * Aucun nom de personne ne figure dans la réponse, hors ceux qu'on autorise.
     *
     * @param list<string> $permis
     */
    private function assertAucunAutreNom(string $contenu, array $permis): void
    {
        $contenu = json_decode($contenu, false, flags: \JSON_THROW_ON_ERROR);
        $texte = json_encode($contenu, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        foreach ($this->personnes as $cle => $personne) {
            if (\in_array($cle, $permis, true)) {
                continue;
            }

            self::assertStringNotContainsString($personne->getDisplayName(), $texte, \sprintf('Le lien ne doit pas nommer « %s ».', $cle));
            self::assertStringNotContainsString('"personId":'.$personne->getId().',', $texte, \sprintf('Le lien ne doit pas désigner « %s ».', $cle));
        }
    }
}
