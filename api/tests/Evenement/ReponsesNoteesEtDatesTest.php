<?php

declare(strict_types=1);

namespace App\Tests\Evenement;

use App\Module\Core\Enum\NotificationKind;
use App\Module\Membership\Entity\Member;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les deux demandes du 2 octobre 2026 sur les événements (ADR 0014, addendum) :
 *
 * 1. **noter la réponse d'un invité à sa place** — « grand-mère a répondu par téléphone » :
 *    réservé à qui gère l'événement, tracé (« notée par … »), et **jamais compté comme une
 *    vue** — le suivi ne doit pas prétendre que l'invité a ouvert l'annonce ;
 * 2. **changer les dates prévient les invités** — tous ceux qui ont un compte, sauf l'auteur
 *    du geste ; les réponses restent, mais deviennent « à confirmer », et le redeviennent pas
 *    dès que l'invité répond de nouveau.
 *
 * Même socle que {@see RoutesDesEvenementsTest} : vrai HTTP, vrais adaptateurs.
 */
final class ReponsesNoteesEtDatesTest extends CasDeTestEvenements
{
    /* ───────────────── Noter la réponse à la place de l'invité ───────────────── */

    public function testLOrganisateurNoteLaReponseDUnInviteSansPoserLaVue(): void
    {
        $id = $this->cousinade();
        $organisateur = $this->charge()['organisateur']['libelle'];

        $this->envoyer('PUT', "/api/evenements/{$id}/invites/{$this->idDe('oncle')}/reponse", ['reponse' => 'present']);
        self::assertSame(Response::HTTP_OK, $this->statut(), (string) $this->client->getResponse()->getContent());

        $presents = array_column($this->charge()['suivi']['presents'], null, 'personId');
        $ligne = $presents[$this->idDe('oncle')] ?? null;
        self::assertNotNull($ligne, 'La réponse notée range l’invité parmi les présents.');
        self::assertSame($organisateur, $ligne['noteePar'], 'Le suivi dit qui l’a notée.');
        self::assertNull($ligne['vuLe'], 'Noter une réponse n’est pas voir l’annonce.');
        self::assertNotNull($ligne['reponduLe']);

        $invitation = $this->invitationDe($id, 'oncle');
        self::assertNull($invitation->getVuLe());
        self::assertSame($this->userIdDe(self::OPALE), $invitation->getReponseNoteeParUserId());

        // On corrige : « finalement, elle ne vient pas ».
        $this->connexionDe(self::OPALE);
        $this->envoyer('PUT', "/api/evenements/{$id}/invites/{$this->idDe('oncle')}/reponse", ['reponse' => 'absent']);
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertSame(
            ['presents' => [], 'absents' => ['oncle'], 'fantomes' => ['ego'], 'nonVus' => ['conjointe', 'cousin', 'enfant']],
            $this->groupesDuSuivi($id),
        );
    }

    /**
     * L'invité retrouve la réponse notée pour lui, marquée comme telle ; dès qu'il répond
     * lui-même, la trace s'efface — la réponse redevient la sienne, et la vue se pose.
     */
    public function testLInviteVoitQueSaReponseAEteNoteeEtLaReprendEnRepondant(): void
    {
        $id = $this->cousinade();
        $organisateur = $this->charge()['organisateur']['libelle'];
        $this->envoyer('PUT', "/api/evenements/{$id}/invites/{$this->idDe('conjointe')}/reponse", ['reponse' => 'present']);
        self::assertSame(Response::HTTP_OK, $this->statut());

        $this->connexionDe(self::JASPE);
        $this->requete('/api/evenements');
        $resume = $this->charge()['evenements'][0];
        self::assertSame('present', $resume['maReponse']);
        self::assertSame($organisateur, $resume['maReponseNoteePar']);

        $this->envoyer('PUT', "/api/evenements/{$id}/reponse", ['reponse' => 'absent']);
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertNull($this->charge()['maReponseNoteePar']);

        $invitation = $this->invitationDe($id, 'conjointe');
        self::assertNull($invitation->getReponseNoteeParUserId());
        self::assertNull($invitation->getReponseNoteeParLibelle());
        self::assertNotNull($invitation->getVuLe());
    }

    public function testSeulQuiGereNoteUneReponseEtTantQueLEvenementTient(): void
    {
        $id = $this->cousinade();

        // Un invité ne répond pas pour un autre.
        $this->connexionDe(self::JASPE);
        $this->envoyer('PUT', "/api/evenements/{$id}/invites/{$this->idDe('cousin')}/reponse", ['reponse' => 'present']);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statut());
        self::assertSame('reserve_a_l_organisateur', $this->codeDErreur());
        self::assertNull($this->invitationDe($id, 'cousin')->getReponse());

        // Une personne qui n'est pas invitée : introuvable, comme pour retirer.
        $this->connexionDe(self::OPALE);
        $this->envoyer('PUT', "/api/evenements/{$id}/invites/{$this->idDe('tante')}/reponse", ['reponse' => 'present']);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->statut());

        // Deux réponses, pas de troisième.
        $this->envoyer('PUT', "/api/evenements/{$id}/invites/{$this->idDe('oncle')}/reponse", ['reponse' => 'peut_etre']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());

        // L'organisateur qui se désigne lui-même répond — il ne « note » rien.
        $this->envoyer('PUT', "/api/evenements/{$id}/invites/{$this->idDe('ego')}/reponse", ['reponse' => 'present']);
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertNull($this->invitationDe($id, 'ego')->getReponseNoteeParUserId());

        // Un administrateur gère tout événement : il note aussi.
        $this->creerUtilisateur('admin@example.invalid', ['ROLE_ADMIN']);
        $this->connexionDe('admin@example.invalid');
        $this->envoyer('PUT', "/api/evenements/{$id}/invites/{$this->idDe('enfant')}/reponse", ['reponse' => 'absent']);
        self::assertSame(Response::HTTP_OK, $this->statut(), (string) $this->client->getResponse()->getContent());
        self::assertNotNull($this->invitationDe($id, 'enfant')->getReponseNoteeParUserId());

        // Annulé : plus rien ne se note.
        $this->connexionDe(self::OPALE);
        $this->envoyer('POST', "/api/evenements/{$id}/annulation");
        $this->envoyer('PUT', "/api/evenements/{$id}/invites/{$this->idDe('oncle')}/reponse", ['reponse' => 'present']);
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
        self::assertSame('reponse_figee', $this->codeDErreur());
    }

    public function testSousLaBasculeOnNeNoteRien(): void
    {
        $id = $this->cousinade();

        $this->creerUtilisateur('admin@example.invalid', ['ROLE_ADMIN']);
        $this->connexionDe('admin@example.invalid');
        $membre = $this->em()->getRepository(Member::class)->findOneBy(['userId' => $this->userIdDe(self::OPALE)]);
        self::assertNotNull($membre);
        $this->envoyer('POST', '/api/administration/voir-comme', ['memberId' => $membre->getId()]);
        self::assertSame(Response::HTTP_OK, $this->statut());

        $this->envoyer('PUT', "/api/evenements/{$id}/invites/{$this->idDe('oncle')}/reponse", ['reponse' => 'present']);
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
        self::assertSame('vue_comme_membre_active', $this->codeDErreur());
        self::assertNull($this->invitationDe($id, 'oncle')->getReponse());
    }

    /* ───────────────── Changer les dates ───────────────── */

    public function testChangerLesDatesPrevientTousLesInvitesAvecUnCompteEtDemandeDeConfirmer(): void
    {
        $id = $this->cousinade();

        $this->connexionDe(self::JASPE);
        $this->envoyer('PUT', "/api/evenements/{$id}/reponse", ['reponse' => 'present']);
        // La réponse date d'avant le changement — les horodatages sont à la seconde.
        $this->antidaterLaReponse('conjointe');

        $this->connexionDe(self::OPALE);
        $this->envoyer('PUT', "/api/evenements/{$id}", ['debut' => $this->annee.'-08-07', 'fin' => $this->annee.'-08-08'] + $this->annonce());
        self::assertSame(Response::HTTP_OK, $this->statut(), (string) $this->client->getResponse()->getContent());

        // Jaspe (qui a répondu) et Onyx (qui n'a rien ouvert) : tous les invités avec un
        // compte. Pas Opale, l'auteur du geste ; pas oncle ni enfant, sans compte.
        self::assertEqualsCanonicalizing(
            [$this->userIdDe(self::JASPE), $this->userIdDe(self::ONYX)],
            $this->destinataires(NotificationKind::EvenementDatesModifiees),
        );

        foreach ($this->notifications(NotificationKind::EvenementDatesModifiees) as $notification) {
            self::assertSame('Cousinade été '.$this->annee.' : nouvelles dates', $notification->getTitle());
            self::assertStringContainsString('août '.$this->annee, (string) $notification->getBody());
            self::assertSame('evenement.detail', $notification->getLinkRoute());
            self::assertSame(['id' => $id], $notification->getLinkParams());
        }

        $ligne = array_column($this->charge()['suivi']['presents'], null, 'personId')[$this->idDe('conjointe')];
        self::assertTrue($ligne['aConfirmer'], 'L’organisateur voit qui doit reconfirmer.');

        // La réponse reste, mais elle est à confirmer.
        $this->connexionDe(self::JASPE);
        $this->requete("/api/evenements/{$id}");
        self::assertSame('present', $this->charge()['maReponse']);
        self::assertTrue($this->charge()['reponseAConfirmer']);

        // Confirmer, c'est répondre de nouveau — la même chose suffit.
        $this->envoyer('PUT', "/api/evenements/{$id}/reponse", ['reponse' => 'present']);
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertFalse($this->charge()['reponseAConfirmer']);

        // Onyx n'avait pas répondu : rien à confirmer, il reste à répondre.
        $this->connexionDe(self::ONYX);
        $this->requete("/api/evenements/{$id}");
        self::assertFalse($this->charge()['reponseAConfirmer']);
    }

    public function testChangerLeTitreOuLeLieuSeulNePrevientPersonne(): void
    {
        $id = $this->cousinade();
        $this->connexionDe(self::JASPE);
        $this->envoyer('PUT', "/api/evenements/{$id}/reponse", ['reponse' => 'present']);
        $this->antidaterLaReponse('conjointe');

        $this->connexionDe(self::OPALE);
        $this->envoyer('PUT', "/api/evenements/{$id}", ['titre' => 'Grande cousinade', 'lieu' => 'À la ferme'] + $this->annonce());
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertSame([], $this->notifications(NotificationKind::EvenementDatesModifiees));

        $this->connexionDe(self::JASPE);
        $this->requete("/api/evenements/{$id}");
        self::assertFalse($this->charge()['reponseAConfirmer']);
    }

    /** Sans compte, pas de notification : c'est le lien qui dit que les dates ont changé. */
    public function testLeLienDitQuUneReponseEstAConfirmer(): void
    {
        $id = $this->cousinade();
        $this->envoyer('POST', "/api/evenements/{$id}/invites/{$this->idDe('oncle')}/lien");
        self::assertSame(Response::HTTP_CREATED, $this->statut());
        $cle = $this->charge()['cle'];
        self::assertIsString($cle);

        $this->deconnexion();
        $this->envoyer('PUT', "/api/evenements/lien/{$cle}/reponse", ['reponse' => 'absent']);
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertFalse($this->charge()['invite']['aConfirmer']);
        $this->antidaterLaReponse('oncle');

        $this->connexionDe(self::OPALE);
        $this->envoyer('PUT', "/api/evenements/{$id}", ['debut' => $this->annee.'-07-24', 'fin' => $this->annee.'-07-25'] + $this->annonce());
        self::assertSame(Response::HTTP_OK, $this->statut());

        $this->deconnexion();
        $this->requete("/api/evenements/lien/{$cle}");
        self::assertSame('absent', $this->charge()['invite']['reponse']);
        self::assertTrue($this->charge()['invite']['aConfirmer']);

        $this->envoyer('PUT', "/api/evenements/lien/{$cle}/reponse", ['reponse' => 'absent']);
        self::assertFalse($this->charge()['invite']['aConfirmer']);
    }

    /** Recule la réponse d'un invité d'une heure : la suite tourne dans la même seconde. */
    private function antidaterLaReponse(string $personne): void
    {
        $connexion = $this->em()->getConnection();
        $connexion->executeStatement(
            'UPDATE evt_invitation SET repondu_le = ? WHERE person_id = ?',
            [(new \DateTimeImmutable('-1 hour'))->format($connexion->getDatabasePlatform()->getDateTimeTzFormatString()), $this->idDe($personne)],
        );
        $this->em()->clear();
    }
}
