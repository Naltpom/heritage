<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Contract\AnnuaireDesMembresInterface;
use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\Regime;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\Service\AuditService;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Service\CompletionDeProposition;
use App\Module\Proposal\Service\FabriqueDeLecteur;
use App\Module\Proposal\Service\FusionDeComplement;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * La bascule « voir comme un membre » — **le test qui manquait**.
 *
 * ── Ce qu'il attrape, et pourquoi il n'existait pas ─────────────────────────────
 *
 * Le garde-fou « aucune écriture sous identité empruntée » existait, compilait, et était
 * couvert par un test. Ce test construisait un `Lecteur` **à la main**, avec son
 * `membreEmprunteUserId` renseigné : il vérifiait donc que le service refuse d'écrire
 * *quand on lui dit que la bascule est active*. Ce qu'il ne vérifiait pas, c'est **qu'on
 * le lui dise jamais**.
 *
 * Or {@see FabriqueDeLecteur} lisait la clé de session `core.vue_comme_membre`, devinée,
 * quand `Core` écrit `core.voir_comme.member_id`. La bascule était donc perpétuellement
 * « inactive » aux yeux de `Proposal` : l'administrateur aurait validé une demande sous
 * identité empruntée sans que rien ne s'y oppose, et la `prp_revision` aurait porté son
 * nom pendant que l'écran montrait le cercle de quelqu'un d'autre.
 *
 * **Une règle de sécurité qui lit sa donnée au mauvais endroit ne laisse aucune trace :
 * elle répond toujours « rien à signaler ».** D'où ce test, qui part de la vraie session
 * et remonte jusqu'au refus — c'est la seule chaîne dont la rupture était invisible.
 */
final class BasculeVoirCommeTest extends BaseDeDemandes
{
    /**
     * Les deux identifiants de la bascule, **qui ne sont pas le même** — et c'est le
     * piège que `Core` a nommé en rangeant les deux en session.
     *
     * `mbr_member.id` désigne le **rattachement** ; `core_user.id` désigne le **compte**.
     * Le calque raisonne en comptes, puisqu'il compare à `prp_change_request.author_user_id`.
     * Confondre les deux ferait servir le calque du mauvais auteur : un numéro plausible,
     * une réponse plausible, et une fuite invisible.
     */
    private const int MEMBRE_EMPRUNTE = 42;

    private const int COMPTE_DU_MEMBRE = 4242;

    /**
     * De la session au `Lecteur` : la bascule activée **arrive** jusqu'à `Proposal`.
     *
     * C'est le maillon qui était rompu, et il ne pouvait l'être qu'ici : partout
     * ailleurs, le `Lecteur` est un objet valeur qu'un test construit correctement.
     */
    public function testUneBasculeActiveArriveJusquAuLecteur(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $bascule = $this->bascule($session);

        self::assertNull(
            $this->fabrique($bascule)->courant()->membreEmprunteUserId,
            'Sans bascule, le lecteur est lui-même.',
        );

        $bascule->activer(self::MEMBRE_EMPRUNTE);

        self::assertSame(
            self::COMPTE_DU_MEMBRE,
            $this->fabrique($bascule)->courant()->membreEmprunteUserId,
            'La bascule de Core doit être lue par Proposal — et c\'est le compte, pas le membre.',
        );

        $bascule->desactiver();

        self::assertNull($this->fabrique($bascule)->courant()->membreEmprunteUserId);
    }

    /**
     * Et une fois qu'elle arrive, elle **refuse toute écriture** — les gestes du membre
     * (déposer, retirer, compléter) et ceux de l'administrateur (valider, refuser,
     * fusionner).
     */
    public function testAucuneEcritureNePasseSousLaBascule(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $bascule = $this->bascule($session);
        $bascule->activer(self::MEMBRE_EMPRUNTE);

        $emprunteur = $this->fabrique($bascule)->courant();

        // Une demande déposée hors bascule, pour avoir de quoi valider et refuser.
        $demande = $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]),
            $this->lecteur(self::OPALE),
        );

        $gestes = [
            'deposer' => fn (): mixed => $this->depot->deposer(
                $this->corpsDeModification([$this->opTexte('nickname', null, 'Mimi')]),
                $emprunteur,
            ),
            'retirer' => fn (): mixed => $this->depot->retirer($demande, $emprunteur),
            'valider' => fn (): mixed => $this->validation->valider($demande, $emprunteur),
            'refuser' => fn (): mixed => $this->refus->refuser($demande, $emprunteur, 'Non.', null),
            // Les deux écritures du 10/10/2026 (ADR 0017) : compléter sur place, fusionner un
            // complément. La première réécrit une demande déjà enregistrée, la seconde en
            // clôt une — sous une identité empruntée, ni l'une ni l'autre.
            'completer' => fn (): mixed => self::getContainer()->get(CompletionDeProposition::class)
                ->completer($demande, 'p1', ['fiche' => ['surname' => 'Sureau']], $emprunteur),
            'fusionner' => fn (): mixed => self::getContainer()->get(FusionDeComplement::class)
                ->fusionner($demande, $demande, [], $emprunteur),
        ];

        foreach ($gestes as $nom => $geste) {
            try {
                $geste();
                self::fail(\sprintf('Le geste « %s » ne doit pas passer sous la bascule.', $nom));
            } catch (Conflit $conflit) {
                self::assertSame('vue_comme_membre_active', $conflit->codeErreur());
                self::assertSame(409, $conflit->statutHttp());
            }
        }

        // Rien n'a bougé : ni l'arbre, ni le statut de la demande.
        self::assertSame(0, $this->arbre->ecritures());
        self::assertSame('en_attente', $this->recharger($demande)->getStatus()->value);
    }

    /**
     * **Les lectures, elles, restent ouvertes** — c'est tout l'objet de la bascule.
     *
     * Un administrateur sous emprunt voit le calque du membre emprunté : vide ici,
     * puisque ce membre n'a rien proposé. Fermer aussi la lecture reviendrait à
     * supprimer la seule façon de vérifier la règle de confidentialité de l'extérieur.
     */
    public function testLaLectureResteOuverteEtMontreLeCalqueDuMembreEmprunte(): void
    {
        $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]),
            $this->lecteur(self::OPALE),
        );

        $session = new Session(new MockArraySessionStorage());
        $bascule = $this->bascule($session);
        $bascule->activer(self::MEMBRE_EMPRUNTE);

        $emprunteur = $this->fabrique($bascule)->courant();

        self::assertFalse($emprunteur->voitToutesLesDemandes(), 'Sous emprunt, l\'administrateur n\'est plus global.');
        self::assertSame(
            [],
            $this->calque->pour(SubjectKind::Personne, self::PERSONNE, $emprunteur),
            'Il voit le calque du membre emprunté, qui n\'a rien proposé.',
        );
    }

    private function bascule(Session $session): BasculeVoirComme
    {
        $requete = new Request();
        $requete->setSession($session);

        $pile = new RequestStack();
        $pile->push($requete);

        // Un annuaire qui ne connaît **qu'un** membre : `activer()` refuse l'emprunt
        // d'un membre inexistant, et c'est la bonne règle — un annuaire de test qui
        // répondrait à tout la ferait passer pour inexistante.
        $annuaire = new class(self::MEMBRE_EMPRUNTE, self::COMPTE_DU_MEMBRE) implements AnnuaireDesMembresInterface {
            public function __construct(
                private readonly int $connu,
                private readonly int $compte,
            ) {
            }

            public function libelleDuMembre(int $memberId): ?string
            {
                return $memberId === $this->connu ? 'Membre '.$memberId : null;
            }

            public function membreDuCompte(int $userId): ?array
            {
                return null;
            }

            public function userIdDuMembre(int $memberId): ?int
            {
                return $memberId === $this->connu ? $this->compte : null;
            }

            public function personneDuCompte(int $userId): ?int
            {
                return null;
            }

            public function comptesParFiche(string $terme): array
            {
                return [];
            }

            /**
             * Aucun rattachement : cette bascule-ci ne teste pas les suppressions.
             *
             * @return array{membreId: int|null, membreLibelle: string|null, invitationsActives: int}
             */
            public function rattachementsDeLaPersonne(int $personneId): array
            {
                return ['membreId' => null, 'membreLibelle' => null, 'invitationsActives' => 0];
            }
        };

        $audit = self::getContainer()->get(AuditService::class);
        self::assertInstanceOf(AuditService::class, $audit);

        return new BasculeVoirComme($pile, $annuaire, $audit);
    }

    private function fabrique(BasculeVoirComme $bascule): FabriqueDeLecteur
    {
        // `createStub` et non `createMock` : on ne vérifie rien sur ces deux-là, on leur
        // fait seulement dire quelque chose. PHPUnit 13 le signale, et il a raison — un
        // mock sans attente laisse croire qu'une interaction est vérifiée alors que
        // personne ne la regarde.
        $securite = $this->createStub(Security::class);
        $securite->method('getUser')->willReturn($this->administrateur());
        $securite->method('isGranted')->willReturn(true);

        $cercle = $this->createStub(CercleDeVisibiliteInterface::class);
        $cercle->method('regime')->willReturn(Regime::Cercle);

        return new FabriqueDeLecteur($securite, $cercle, $bascule);
    }

    /**
     * Un compte administrateur avec un identifiant.
     *
     * Posé par réflexion : `core_user.id` est écrit par Doctrine à l'insertion, et la
     * fabrique refuse — à raison — un utilisateur qui n'en a pas. Le persister
     * vraiment ferait dépendre ce test du module des comptes, dont il ne dit rien.
     */
    private function administrateur(): User
    {
        $utilisateur = (new User('natron@example.test', 'Natron Romarin'))->setRoles(['ROLE_ADMIN']);

        $identifiant = new \ReflectionProperty(User::class, 'id');
        $identifiant->setValue($utilisateur, self::ADMIN);

        return $utilisateur;
    }
}
