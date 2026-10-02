<?php

declare(strict_types=1);

namespace App\Tests\Evenement;

use App\Module\Core\Enum\NotificationKind;
use App\Module\Evenement\Entity\Evenement;
use App\Module\Membership\Entity\Member;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les routes des événements, **de bout en bout et en vrai HTTP** : pare-feu, session,
 * contrôleur, service, adaptateur réel de `Tree`, annuaire et cercle réels de `Membership`.
 *
 * Trois choses y sont éprouvées avant tout :
 *
 * 1. **les invités sont prévenus** — ceux qui ont un compte, par une notification qui ne
 *    nomme aucun autre invité ;
 * 2. **la vue se pose sans geste**, une seule fois, et jamais sous la bascule ;
 * 3. **ce que voit un invité est une liste blanche** : ni le suivi, ni qui a décliné, ni un
 *    horodatage de vue — sur le principe de `ListeBlancheDeSerialisationTest`, l'ensemble
 *    **exact** des clés est comparé.
 */
final class RoutesDesEvenementsTest extends CasDeTestEvenements
{
    /* ───────────────── Créer et prévenir ───────────────── */

    public function testCreerPrevientChaqueInviteQuiAUnCompteSansNommerPersonne(): void
    {
        $id = $this->cousinade();
        $charge = $this->charge();

        self::assertSame('Cousinade été '.$this->annee, $charge['titre']);
        self::assertSame($this->annee.'-07-17', $charge['debut']);
        self::assertSame($this->annee.'-07-18', $charge['fin']);
        self::assertSame("Apportez un plat.\nOn dort sur place.", $charge['description'], 'Les retours à la ligne sont gardés.');
        self::assertSame(5, $charge['nombreDInvites']);
        self::assertTrue($charge['peutGerer']);

        // Jaspe et Onyx ont un compte ; Opale organise (il ne se prévient pas lui-même) ;
        // oncle et enfant n'en ont pas — c'est leur lien qui les tiendra au courant.
        self::assertEqualsCanonicalizing(
            [$this->userIdDe(self::JASPE), $this->userIdDe(self::ONYX)],
            $this->destinataires(NotificationKind::EvenementInvitation),
        );

        foreach ($this->notifications(NotificationKind::EvenementInvitation) as $notification) {
            self::assertSame('Invitation : Cousinade été '.$this->annee, $notification->getTitle());
            self::assertSame('evenement.detail', $notification->getLinkRoute());
            self::assertSame(['id' => $id], $notification->getLinkParams());
            self::assertStringContainsString('juillet '.$this->annee, (string) $notification->getBody());
            self::assertStringContainsString('Chez Tante Ardoise', (string) $notification->getBody());

            foreach (['conjointe', 'cousin', 'oncle', 'enfant'] as $personne) {
                $nom = $this->personnes[$personne]->getDisplayName();
                self::assertStringNotContainsString($nom, $notification->getTitle().' '.$notification->getBody());
            }
        }

        // L'organisateur qui s'invite a vu son annonce : il vient de l'écrire.
        self::assertSame(
            ['presents' => [], 'absents' => [], 'fantomes' => ['ego'], 'nonVus' => ['conjointe', 'cousin', 'enfant', 'oncle']],
            $this->groupesDuSuivi($id),
        );
    }

    public function testInviterApresCoupNePrevientQueLesNouveaux(): void
    {
        $id = $this->cousinade(['ego', 'oncle']);
        self::assertSame([], $this->destinataires(NotificationKind::EvenementInvitation));

        // `oncle` est déjà invité : ignoré sans erreur. `conjointe` est nouvelle.
        $this->envoyer('POST', "/api/evenements/{$id}/invites", ['personIds' => [$this->idDe('conjointe'), $this->idDe('oncle')]]);
        self::assertSame(Response::HTTP_CREATED, $this->statut(), (string) $this->client->getResponse()->getContent());
        self::assertSame(3, $this->charge()['nombreDInvites']);
        self::assertSame([$this->userIdDe(self::JASPE)], $this->destinataires(NotificationKind::EvenementInvitation));

        $this->envoyer('DELETE', "/api/evenements/{$id}/invites/{$this->idDe('oncle')}");
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertSame(2, $this->charge()['nombreDInvites']);

        $this->envoyer('DELETE', "/api/evenements/{$id}/invites/{$this->idDe('oncle')}");
        self::assertSame(Response::HTTP_NOT_FOUND, $this->statut());
    }

    /* ───────────────── Voir, répondre ───────────────── */

    /**
     * La première vue se pose à l'ouverture de la page, sans geste — et ne bouge plus : une
     * seconde visite ne la déplace pas, sans quoi l'organisateur ne saurait plus depuis quand
     * l'annonce attend.
     */
    public function testOuvrirLaPagePoseLaVueUneSeuleFois(): void
    {
        $id = $this->cousinade();
        self::assertNull($this->invitationDe($id, 'conjointe')->getVuLe());

        $this->connexionDe(self::JASPE);
        $this->requete("/api/evenements/{$id}");
        self::assertSame(Response::HTTP_OK, $this->statut());
        $premiere = $this->invitationDe($id, 'conjointe')->getVuLe();
        self::assertNotNull($premiere);

        // On antidate la vue : une seconde visite ne doit pas la ramener à maintenant.
        $connexion = $this->em()->getConnection();
        $connexion->executeStatement(
            'UPDATE evt_invitation SET vu_le = ? WHERE person_id = ?',
            [(new \DateTimeImmutable('2020-01-01 10:00:00'))->format($connexion->getDatabasePlatform()->getDateTimeTzFormatString()), $this->idDe('conjointe')],
        );
        $this->requete("/api/evenements/{$id}");
        self::assertSame('2020-01-01', $this->invitationDe($id, 'conjointe')->getVuLe()?->format('Y-m-d'));

        // L'accueil marque aussi — la carte montre l'annonce.
        $this->connexionDe(self::ONYX);
        $this->envoyer('POST', "/api/evenements/{$id}/vu");
        self::assertSame(Response::HTTP_NO_CONTENT, $this->statut());
        self::assertNotNull($this->invitationDe($id, 'cousin')->getVuLe());

        self::assertSame(
            ['presents' => [], 'absents' => [], 'fantomes' => ['conjointe', 'cousin', 'ego'], 'nonVus' => ['enfant', 'oncle']],
            $this->groupesDuSuivi($id),
        );
    }

    public function testRepondrePresentOuAbsentEtChangerDAvis(): void
    {
        $id = $this->cousinade();

        $this->connexionDe(self::JASPE);
        $this->envoyer('PUT', "/api/evenements/{$id}/reponse", ['reponse' => 'absent']);
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertSame('absent', $this->charge()['maReponse']);

        $this->envoyer('PUT', "/api/evenements/{$id}/reponse", ['reponse' => 'present']);
        self::assertSame('present', $this->charge()['maReponse']);
        self::assertSame(1, $this->charge()['nombreDePresents']);
        self::assertSame([['personId' => $this->idDe('conjointe'), 'libelle' => $this->personnes['conjointe']->getDisplayName(), 'estMoi' => true]], $this->charge()['quiVient']['presents']);

        // Pas de troisième valeur : ni « peut-être », ni rien d'autre.
        $this->envoyer('PUT', "/api/evenements/{$id}/reponse", ['reponse' => 'peut_etre']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
        self::assertArrayHasKey('reponse', $this->charge()['erreur']['champs']);

        $this->connexionDe(self::ONYX);
        $this->envoyer('PUT', "/api/evenements/{$id}/reponse", ['reponse' => 'absent']);
        self::assertSame(Response::HTTP_OK, $this->statut());

        // Répondre, c'est avoir vu : Onyx n'avait jamais ouvert la page.
        self::assertNotNull($this->invitationDe($id, 'cousin')->getVuLe());
        self::assertSame(
            ['presents' => ['conjointe'], 'absents' => ['cousin'], 'fantomes' => ['ego'], 'nonVus' => ['enfant', 'oncle']],
            $this->groupesDuSuivi($id),
        );
    }

    /* ───────────────── Ce que voit un invité : une liste blanche ───────────────── */

    /**
     * Un invité voit l'événement, sa réponse, et **qui vient** — pas qui a décliné (un
     * nombre seulement), pas qui a vu sans répondre, aucun horodatage. L'ensemble des clés
     * est comparé **exactement** : un champ ajouté demain fait échouer ce test.
     */
    public function testUnInviteNeVoitNiLeSuiviNiQuiADecline(): void
    {
        $id = $this->cousinade();
        $this->connexionDe(self::ONYX);
        $this->envoyer('PUT', "/api/evenements/{$id}/reponse", ['reponse' => 'absent']);

        $this->connexionDe(self::JASPE);
        $this->requete("/api/evenements/{$id}");
        $charge = $this->charge();
        $brut = (string) $this->client->getResponse()->getContent();

        self::assertSame([
            'id', 'titre', 'debut', 'fin', 'lieu', 'description', 'annule', 'passe', 'organisateur',
            'peutGerer', 'suisInvite', 'maReponse', 'peutRepondre', 'nombreDInvites', 'nombreDePresents',
            'quiVient', 'nombreDAbsents', 'suivi',
        ], array_keys($charge));
        self::assertSame(['libelle', 'estMoi'], array_keys($charge['organisateur']));
        self::assertSame(['presents', 'autres'], array_keys($charge['quiVient']));
        self::assertNull($charge['suivi'], 'Le suivi est réservé à qui gère l’événement.');
        self::assertFalse($charge['peutGerer']);
        self::assertTrue($charge['peutRepondre']);
        self::assertSame(1, $charge['nombreDAbsents'], 'Combien ont décliné, oui…');
        self::assertStringNotContainsString($this->personnes['cousin']->getDisplayName(), $brut, '… qui, non.');

        foreach (['vuLe', 'reponduLe', 'relanceLe', 'fantomes', 'nonVus', 'aUnCompte', 'lienActif'] as $interdit) {
            self::assertStringNotContainsString('"'.$interdit.'"', $brut, \sprintf('`%s` ne sort pas pour un invité.', $interdit));
        }

        // La liste, elle, porte le résumé — la même liste blanche, sans `quiVient` ni suivi.
        $this->requete('/api/evenements');
        $liste = $this->charge();
        self::assertSame(['moi', 'evenements'], array_keys($liste));
        self::assertSame(['personId' => $this->idDe('conjointe'), 'libelle' => $this->personnes['conjointe']->getDisplayName()], $liste['moi']);
        self::assertCount(1, $liste['evenements']);
        self::assertSame(\array_slice(array_keys($charge), 0, 15), array_keys($liste['evenements'][0]));
    }

    public function testLeSuiviDeLOrganisateurEstUneListeBlanche(): void
    {
        $id = $this->cousinade();
        $this->connexionDe(self::JASPE);
        $this->envoyer('PUT', "/api/evenements/{$id}/reponse", ['reponse' => 'present']);

        $this->connexionDe(self::OPALE);
        $this->requete("/api/evenements/{$id}");
        $suivi = $this->charge()['suivi'];

        self::assertSame(
            ['presents', 'absents', 'fantomes', 'nonVus', 'derniereRelanceLe', 'prochaineRelancePossibleLe', 'aRelancer'],
            array_keys($suivi),
        );
        self::assertSame(
            ['personId', 'libelle', 'estMoi', 'aUnCompte', 'lienActif', 'peutPartagerLien', 'vuLe', 'reponduLe', 'relanceLe'],
            array_keys($suivi['presents'][0]),
        );
        self::assertNotNull($suivi['presents'][0]['vuLe']);
        self::assertNotNull($suivi['presents'][0]['reponduLe']);
        self::assertTrue($suivi['presents'][0]['aUnCompte']);
        self::assertFalse($suivi['presents'][0]['peutPartagerLien'], 'Avec un compte, pas de lien.');

        $nonVus = array_column($suivi['nonVus'], null, 'personId');
        self::assertFalse($nonVus[$this->idDe('oncle')]['aUnCompte']);
        self::assertTrue($nonVus[$this->idDe('oncle')]['peutPartagerLien']);
        self::assertTrue($nonVus[$this->idDe('cousin')]['aUnCompte']);
        // Cousin seul est à relancer : Jaspe a répondu, l'organisateur ne se relance pas,
        // oncle et enfant n'ont pas de compte.
        self::assertSame(1, $suivi['aRelancer']);
    }

    /**
     * Même parmi ceux qui viennent, un invité ne lit le nom que des personnes qu'il voit dans
     * l'arbre (ADR 0003). `voisine`, invitée quand sa fiche était ouverte, devient
     * confidentielle : Jaspe ne lit plus son nom, seulement qu'« une autre personne » vient ;
     * l'organisateur, qui l'a invitée, la voit toujours.
     */
    public function testUnInviteNeLitQueLesNomsDesPresentsQuIlVoitDansLArbre(): void
    {
        $this->semerLeCercle(voisineConfidentielle: false);
        $id = $this->cousinade(['ego', 'conjointe', 'voisine']);
        $nom = $this->personnes['voisine']->getDisplayName();

        $this->envoyer('POST', "/api/evenements/{$id}/invites/{$this->idDe('voisine')}/lien");
        $cle = $this->charge()['cle'];
        self::assertIsString($cle);
        $this->deconnexion();
        $this->envoyer('PUT', "/api/evenements/lien/{$cle}/reponse", ['reponse' => 'present']);
        self::assertSame(Response::HTTP_OK, $this->statut());

        $this->semerLeCercle(voisineConfidentielle: true);

        $this->connexionDe(self::JASPE);
        $this->requete("/api/evenements/{$id}");
        self::assertSame(['presents' => [], 'autres' => 1], $this->charge()['quiVient']);
        self::assertStringNotContainsString($nom, (string) $this->client->getResponse()->getContent());

        $this->connexionDe(self::OPALE);
        $this->requete("/api/evenements/{$id}");
        self::assertSame($nom, $this->charge()['quiVient']['presents'][0]['libelle']);
    }

    /* ───────────────── Qui voit, qui gère ───────────────── */

    public function testUnEvenementEstIntrouvablePourQuiNEnEstPasMaisLAdministrateurVoitTout(): void
    {
        $id = $this->cousinade();

        $this->connexionDe(self::AMBRE);
        $this->requete('/api/evenements');
        self::assertSame([], $this->charge()['evenements']);

        $this->requete("/api/evenements/{$id}");
        self::assertSame(Response::HTTP_NOT_FOUND, $this->statut());
        self::assertSame('ressource_introuvable', $this->codeDErreur());

        $this->envoyer('PUT', "/api/evenements/{$id}/reponse", ['reponse' => 'present']);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->statut());

        $this->creerUtilisateur('admin@example.invalid', ['ROLE_ADMIN']);
        $this->connexionDe('admin@example.invalid');
        $this->requete('/api/evenements');
        self::assertCount(1, $this->charge()['evenements']);
        $this->requete("/api/evenements/{$id}");
        self::assertTrue($this->charge()['peutGerer']);
        self::assertIsArray($this->charge()['suivi']);
    }

    public function testSeulLOrganisateurGereLEvenement(): void
    {
        $id = $this->cousinade();
        $this->connexionDe(self::JASPE);

        foreach ([
            ['PUT', "/api/evenements/{$id}", $this->annonce()],
            ['POST', "/api/evenements/{$id}/annulation", null],
            ['POST', "/api/evenements/{$id}/invites", ['personIds' => [$this->idDe('tante')]]],
            ['DELETE', "/api/evenements/{$id}/invites/{$this->idDe('oncle')}", null],
            ['POST', "/api/evenements/{$id}/invites/{$this->idDe('oncle')}/lien", null],
            ['POST', "/api/evenements/{$id}/relance", null],
        ] as [$methode, $chemin, $corps]) {
            $this->envoyer($methode, $chemin, $corps);
            self::assertSame(Response::HTTP_FORBIDDEN, $this->statut(), $methode.' '.$chemin);
            self::assertSame('reserve_a_l_organisateur', $this->codeDErreur(), $methode.' '.$chemin);
        }

        // L'organisateur qui ne s'est pas invité n'a rien à répondre.
        $autre = $this->cousinade(['conjointe']);
        $this->envoyer('PUT', "/api/evenements/{$autre}/reponse", ['reponse' => 'present']);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statut());
        self::assertSame('non_invite', $this->codeDErreur());
    }

    public function testModifierLAnnonce(): void
    {
        $id = $this->cousinade();

        $this->envoyer('PUT', "/api/evenements/{$id}", ['titre' => 'Cousinade', 'debut' => $this->annee.'-08-01', 'fin' => $this->annee.'-08-01', 'lieu' => '', 'description' => null]);
        self::assertSame(Response::HTTP_OK, $this->statut(), (string) $this->client->getResponse()->getContent());
        $charge = $this->charge();
        self::assertSame('Cousinade', $charge['titre']);
        self::assertSame($charge['debut'], $charge['fin'], 'Un événement d’un jour a ses deux dates égales.');
        self::assertNull($charge['lieu'], 'Un lieu vide est un lieu absent.');
        self::assertNull($charge['description']);
    }

    public function testUneAnnonceInvalideNommeChaqueChampFautif(): void
    {
        $this->connexionDe(self::OPALE);

        $this->envoyer('POST', '/api/evenements', ['titre' => '  ', 'debut' => '2027-02-30', 'fin' => 'demain', 'lieu' => 12]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
        self::assertSame('donnees_invalides', $this->codeDErreur());
        self::assertEqualsCanonicalizing(['titre', 'debut', 'fin', 'lieu'], array_keys($this->charge()['erreur']['champs']));

        $this->envoyer('POST', '/api/evenements', ['debut' => $this->annee.'-07-18', 'fin' => $this->annee.'-07-17'] + $this->annonce());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
        self::assertSame(['fin'], array_keys($this->charge()['erreur']['champs']), 'La fin ne précède pas le début.');

        $this->envoyer('POST', '/api/evenements', ['debut' => '2020-07-17', 'fin' => '2020-07-18'] + $this->annonce());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
        self::assertStringContainsString('passée', $this->charge()['erreur']['champs']['fin']);

        $this->envoyer('POST', '/api/evenements', $this->annonce() + ['invites' => ['12']]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
        self::assertSame(['invites'], array_keys($this->charge()['erreur']['champs']));
    }

    /* ───────────────── Qui peut être invité ───────────────── */

    public function testOnNInviteNiUnDefuntNiUnVivantQuOnNeVoitPas(): void
    {
        $this->connexionDe(self::OPALE);

        $this->envoyer('POST', '/api/evenements', $this->annonce() + ['invites' => [$this->idDe('ego'), $this->idDe('aieule')]]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
        self::assertSame('invite_decede', $this->codeDErreur());
        self::assertStringContainsString('Marne Dolomite Sureau', $this->charge()['erreur']['message'], 'Le message nomme le défunt refusé.');

        // Inexistante et hors cercle : le même refus, mot pour mot, sans le nom.
        $this->envoyer('POST', '/api/evenements', $this->annonce() + ['invites' => [$this->idDe('ego'), 999999]]);
        $inconnue = $this->charge()['erreur'];
        $this->envoyer('POST', '/api/evenements', $this->annonce() + ['invites' => [$this->idDe('ego'), $this->idDe('voisine')]]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
        self::assertSame($inconnue, $this->charge()['erreur']);
        self::assertStringNotContainsString($this->personnes['voisine']->getDisplayName(), (string) $this->client->getResponse()->getContent());

        self::assertCount(0, $this->em()->getRepository(Evenement::class)->findAll(), 'Un refus n’enregistre rien.');
    }

    /* ───────────────── Relancer ───────────────── */

    public function testLaRelanceToucheQuiNARienReponduEtUneFoisParJour(): void
    {
        $id = $this->cousinade();

        // Jaspe a vu sans répondre (le fantôme), Onyx n'a rien ouvert : les deux sont relancés.
        $this->connexionDe(self::JASPE);
        $this->requete("/api/evenements/{$id}");

        $this->connexionDe(self::OPALE);
        $this->envoyer('POST', "/api/evenements/{$id}/relance");
        self::assertSame(Response::HTTP_OK, $this->statut(), (string) $this->client->getResponse()->getContent());
        self::assertEqualsCanonicalizing(
            [$this->userIdDe(self::JASPE), $this->userIdDe(self::ONYX)],
            $this->destinataires(NotificationKind::EvenementRelance),
        );
        $suivi = $this->charge()['suivi'];
        self::assertNotNull($suivi['derniereRelanceLe']);
        self::assertNotNull($suivi['prochaineRelancePossibleLe']);
        self::assertNotNull($this->invitationDe($id, 'conjointe')->getRelanceLe());
        self::assertNull($this->invitationDe($id, 'oncle')->getRelanceLe(), 'Sans compte, rien ne part d’ici.');

        foreach ($this->notifications(NotificationKind::EvenementRelance) as $notification) {
            self::assertSame('Rappel : Cousinade été '.$this->annee, $notification->getTitle());
        }

        $this->envoyer('POST', "/api/evenements/{$id}/relance");
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
        self::assertSame('relance_trop_recente', $this->codeDErreur());
        self::assertArrayHasKey('prochaineRelancePossibleLe', $this->charge()['erreur']['details']);
        self::assertCount(2, $this->notifications(NotificationKind::EvenementRelance), 'La seconde relance n’est pas partie.');
    }

    public function testSansPersonneARelancerLaRelanceEstRefusee(): void
    {
        $id = $this->cousinade(['ego', 'conjointe', 'oncle']);
        $this->connexionDe(self::JASPE);
        $this->envoyer('PUT', "/api/evenements/{$id}/reponse", ['reponse' => 'present']);

        $this->connexionDe(self::OPALE);
        $this->envoyer('POST', "/api/evenements/{$id}/relance");
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
        self::assertSame('rien_a_relancer', $this->codeDErreur());
    }

    /* ───────────────── Annuler ───────────────── */

    public function testAnnulerPrevientQuiAVuOuReponduEtFigeLesReponses(): void
    {
        $id = $this->cousinade();
        $this->connexionDe(self::JASPE);
        $this->requete("/api/evenements/{$id}");

        $this->connexionDe(self::OPALE);
        $this->envoyer('POST', "/api/evenements/{$id}/annulation");
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertTrue($this->charge()['annule']);
        self::assertFalse($this->charge()['peutRepondre']);

        // Jaspe avait vu ; Onyx n'avait rien ouvert : il n'apprend pas l'annulation d'un
        // événement auquel il ne pensait pas aller.
        self::assertSame([$this->userIdDe(self::JASPE)], $this->destinataires(NotificationKind::EvenementAnnule));
        self::assertSame('Annulé : Cousinade été '.$this->annee, $this->notifications(NotificationKind::EvenementAnnule)[0]->getTitle());

        $this->envoyer('POST', "/api/evenements/{$id}/annulation");
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
        self::assertSame('evenement_annule', $this->codeDErreur());

        $this->envoyer('PUT', "/api/evenements/{$id}", $this->annonce());
        self::assertSame('evenement_annule', $this->codeDErreur());

        $this->connexionDe(self::JASPE);
        $this->requete("/api/evenements/{$id}");
        self::assertSame(Response::HTTP_OK, $this->statut(), 'Un événement annulé se lit encore : la notification y mène.');
        $this->envoyer('PUT', "/api/evenements/{$id}/reponse", ['reponse' => 'present']);
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
        self::assertSame('reponse_figee', $this->codeDErreur());
    }

    /* ───────────────── La bascule « voir comme » ───────────────── */

    /**
     * Sous la bascule, l'administrateur voit l'événement **comme ce membre** — sa réponse, ce
     * qu'il voit de qui vient — mais **ne marque rien** : ni vue, ni réponse. Vérifier un
     * écran ne doit pas faire d'un cousin un fantôme.
     */
    public function testSousLaBasculeLAdministrateurLitSansMarquerNiRepondre(): void
    {
        $id = $this->cousinade();

        $this->creerUtilisateur('admin@example.invalid', ['ROLE_ADMIN']);
        $this->connexionDe('admin@example.invalid');
        $membre = $this->em()->getRepository(Member::class)->findOneBy(['userId' => $this->userIdDe(self::JASPE)]);
        self::assertNotNull($membre);
        $this->envoyer('POST', '/api/administration/voir-comme', ['memberId' => $membre->getId()]);
        self::assertSame(Response::HTTP_OK, $this->statut());

        $this->requete("/api/evenements/{$id}");
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertFalse($this->charge()['peutGerer'], 'Sous la bascule, on lit comme le membre.');
        self::assertNull($this->charge()['suivi']);

        $this->envoyer('POST', "/api/evenements/{$id}/vu");
        self::assertSame(Response::HTTP_NO_CONTENT, $this->statut());
        self::assertNull($this->invitationDe($id, 'conjointe')->getVuLe(), 'Ni la page ni l’accueil ne marquent sous la bascule.');

        $this->envoyer('PUT', "/api/evenements/{$id}/reponse", ['reponse' => 'present']);
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
        self::assertSame('vue_comme_membre_active', $this->codeDErreur());
        self::assertNull($this->invitationDe($id, 'conjointe')->getReponse());
    }
}
