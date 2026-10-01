<?php

declare(strict_types=1);

namespace App\Tests\Tirage;

use App\Module\Core\Entity\Notification;
use App\Module\Core\Enum\NotificationKind;
use App\Module\Core\Enum\UserStatus;
use App\Module\Membership\Entity\Member;
use App\Module\Tirage\Entity\Attribution;
use App\Module\Tree\Serializer\CercleDuLecteur;
use App\Module\Tree\Serializer\EtatVivant;
use App\Module\Tree\Serializer\NormaliseurDeRecherche;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les routes des tirages au sort, **de bout en bout et en vrai HTTP** : pare-feu, session,
 * contrôleur, service, adaptateur réel de `Tree` et annuaire réel de `Membership`, sur la
 * vraie base.
 *
 * L'arbre est celui du harnais, entièrement inventé, et les trois vivants de
 * {@see CasDeTestTirages}. Le couple qui compte ici est `cousin` + `conjointe`.
 *
 * Le cœur de ce fichier est **le secret** : chacun ne lit que son attribution, et celle de
 * qui il accompagne — l'organisateur pas plus qu'un autre.
 */
final class RoutesDesTiragesTest extends CasDeTestTirages
{
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

        // L'organisateur lit la sienne et celle de `enfant`, qu'il accompagne — rien de plus.
        self::assertEqualsCanonicalizing(
            [$this->idDe('ego'), $this->idDe('enfant')],
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

            foreach (['ego', 'conjointe', 'cousin', 'oncle', 'enfant'] as $personne) {
                $nom = $this->personnes[$personne]->getDisplayName();
                self::assertStringNotContainsString($nom, $notification->getTitle().' '.$notification->getBody());
            }
        }
    }

    /**
     * Sous la bascule « voir comme un membre », l'administrateur voit le tirage **comme ce
     * membre** — son attribution, et rien de plus — et ne peut rien y écrire (ADR 0009,
     * révision du 2026-09-27).
     */
    public function testSousLaBasculeLAdministrateurVoitLeTirageDuMembreSansPouvoirAgir(): void
    {
        $id = $this->tirageDeNoel();
        $this->connexionDe(self::OPALE);
        $this->envoyer('POST', "/api/tirages/{$id}/editions", ['annee' => 2026]);
        $this->envoyer('POST', "/api/tirages/{$id}/editions/2026/tirage");

        $this->creerUtilisateur('admin@example.invalid', ['ROLE_ADMIN']);
        $this->connexionDe('admin@example.invalid');

        // Sans bascule : l'administrateur voit le tirage, mais aucune attribution.
        $this->requete("/api/tirages/{$id}");
        self::assertSame([], $this->charge()['editions'][0]['mesAttributions']);

        $membreDeJaspe = $this->em()->getRepository(Member::class)->findOneBy(['userId' => $this->userIdDe(self::JASPE)]);
        self::assertNotNull($membreDeJaspe);
        $this->envoyer('POST', '/api/administration/voir-comme', ['memberId' => $membreDeJaspe->getId()]);
        self::assertSame(Response::HTTP_OK, $this->statut());

        $this->requete('/api/tirages');
        self::assertSame($this->idDe('conjointe'), $this->charge()['moi']['personId']);

        $this->requete("/api/tirages/{$id}");
        $charge = $this->charge();
        self::assertFalse($charge['peutGerer'], 'Sous la bascule, on ne gère que si le membre organise.');
        $siennes = $charge['editions'][0]['mesAttributions'];
        self::assertCount(1, $siennes, 'L’attribution du membre, et seulement elle.');
        self::assertSame($this->idDe('conjointe'), $siennes[0]['donneur']['personId']);

        $this->envoyer('PUT', "/api/tirages/{$id}/editions/2026/presences/{$this->idDe('conjointe')}", ['presence' => 'a_distance']);
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
        self::assertSame('vue_comme_membre_active', $this->codeDErreur());

        // Un tirage fermé dont le membre n'est pas reste introuvable, même pour l'administrateur.
        $this->envoyer('DELETE', '/api/administration/voir-comme');
        $this->envoyer('POST', '/api/tirages', $this->regles() + ['participants' => [$this->idDe('oncle'), $this->idDe('tante'), $this->idDe('cousin')]]);
        $autre = $this->charge()['id'];
        $this->envoyer('POST', '/api/administration/voir-comme', ['memberId' => $membreDeJaspe->getId()]);
        $this->requete("/api/tirages/{$autre}");
        self::assertSame(Response::HTTP_NOT_FOUND, $this->statut());
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
        self::assertSame(['personId' => $this->idDe('tante'), 'libelle' => 'Ardoise Tilleul'], $this->charge()['moi']);
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

        $this->envoyer('PUT', "/api/tirages/{$id}/editions/2026/presences/{$this->idDe('oncle')}", ['presence' => 'absente']);
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

    /* ───────────────── Les défunts ───────────────── */

    /**
     * On ne tire pas au sort un cadeau pour une personne disparue — ni à la création, ni à
     * l'ajout, ni en rejoignant. « Disparue » au sens de la règle unique : `aieule` a un
     * décès enregistré, `sansDate` n'en a pas mais le repli par génération la classe.
     */
    public function testUnDefuntNEntreDansAucunTirage(): void
    {
        $this->connexionDe(self::OPALE);

        $this->envoyer('POST', '/api/tirages', $this->regles() + ['participants' => [$this->idDe('ego'), $this->idDe('aieule')]]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
        self::assertSame('participant_decede', $this->codeDErreur());
        $erreur = $this->charge()['erreur'];
        self::assertIsArray($erreur);
        self::assertArrayHasKey('participants', $erreur['champs']);
        self::assertStringContainsString('Marne Dolomite Sureau', $erreur['message'], 'Le message nomme la personne refusée.');

        $id = $this->tirageDeNoel(ouvert: true);

        foreach (['aieule', 'sansDate'] as $defunt) {
            $this->envoyer('POST', "/api/tirages/{$id}/participants", ['personId' => $this->idDe($defunt)]);
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut(), $defunt);
            self::assertSame('participant_decede', $this->codeDErreur());
        }

        // Un compte rattaché à une personne décédée ne s'inscrit pas davantage.
        $this->connexionDe(self::GRENAT);
        $this->envoyer('POST', "/api/tirages/{$id}/rejoindre");
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
        self::assertSame('participant_decede', $this->codeDErreur());
    }

    /**
     * `?vivants=1` — le paramètre de la recherche des tirages. Sans lui, la recherche reste
     * celle des propositions et des actes demandés, qui cherchent aussi les défunts.
     */
    public function testLaRechercheDesTiragesNeProposeQueDesVivants(): void
    {
        $this->connexionDe(self::OPALE);

        // Décès enregistré : écarté dès le SQL.
        $this->requete('/api/arbre/recherche?q=Sarriette');
        self::assertCount(1, $this->charge()['elements']);
        $this->requete('/api/arbre/recherche?q=Sarriette&vivants=1');
        self::assertSame([], $this->charge()['elements']);

        // Aucun décès enregistré, mais la règle la classe : écartée après coup.
        $this->requete('/api/arbre/recherche?q=Fenouil');
        self::assertCount(1, $this->charge()['elements']);
        $this->requete('/api/arbre/recherche?q=Fenouil&vivants=1');
        self::assertSame([], $this->charge()['elements']);
        self::assertFalse($this->charge()['tronque']);

        // Les vivants, eux, restent — vus ici à travers un cercle qui couvre tout l'arbre :
        // celui du harnais n'en montre aucun, ce qui ne dirait rien du filtre.
        $normaliseur = self::getContainer()->get(NormaliseurDeRecherche::class);
        $etat = self::getContainer()->get(EtatVivant::class);
        \assert($normaliseur instanceof NormaliseurDeRecherche && $etat instanceof EtatVivant);
        $tous = CercleDuLecteur::de(array_values(array_map(fn (string $cle): int => $this->idDe($cle), array_keys($this->personnes))));

        $noms = array_map(
            static fn (array $e): mixed => $e['displayName'],
            $normaliseur->resultats('Romarin', 20, $tous, $etat, vivantsSeulement: true)['elements'],
        );
        self::assertEqualsCanonicalizing(['Natron Romarin', 'Cobalt Romarin', 'Basalte Romarin', 'Mica Romarin'], $noms);
    }
}
