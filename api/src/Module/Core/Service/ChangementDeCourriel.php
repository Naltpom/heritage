<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Entity\EmailChange;
use App\Module\Core\Entity\User;
use App\Module\Core\Exception\ConflictException;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Repository\EmailChangeRepository;
use App\Module\Core\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Le changement de courriel d'un compte — § 2.5 du contrat d'API.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LE COURRIEL EST L'IDENTIFIANT. ON NE LE REMPLACE PAS SANS PREUVE.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Deux preuves, et aucune des deux ne suffit seule :
 *
 * 1. **Le mot de passe actuel**, à la demande. Une session volée — un onglet resté
 *    ouvert, un poste partagé — ne connaît pas le mot de passe. Sans lui, changer le
 *    courriel serait le moyen le plus court de prendre un compte pour de bon.
 * 2. **Un clic sur un lien reçu à la nouvelle adresse**, à la confirmation. Sans lui,
 *    une faute de frappe dans l'adresse enferme le titulaire dehors : il ne peut plus
 *    se connecter, et la réinitialisation partirait vers une boîte qui n'existe pas.
 *
 * ── Trois règles héritées des invitations, et pourquoi elles s'appliquent ici ─────
 *
 * - **Le serveur ne stocke que `hash(jeton)`.** Une fuite de la table ne donne aucun
 *   lien utilisable.
 * - **Tout refus du lien répond 404, avec le même corps.** Inconnu, expiré, consommé,
 *   mal formé : un seul cas observable. Un 403 sur « expiré » dirait qu'un jeton a
 *   existé, et pour qui — la route est publique, on y clique sans session.
 * - **Usage unique.** Le lien est consommé à la confirmation ; le même lien rejoué
 *   rend 404.
 *
 * ── Ce qui n'est pas fait, et pourquoi c'est dit ici ─────────────────────────────
 *
 * Il n'y a pas de limiteur de débit dédié. Une nouvelle demande **périme la
 * précédente** — un compte n'a jamais qu'un lien valable —, et chaque demande exige
 * le mot de passe, que la connexion limite déjà. Si un jour quelqu'un s'acharne sur
 * cette route avec un mot de passe valide, le problème n'est pas la route.
 *
 * ── Sans SMTP, la fonctionnalité n'existe pas ────────────────────────────────────
 *
 * Contrairement à l'invitation, dont le lien est **aussi** affiché à l'administrateur
 * pour copie manuelle, il n'y a personne ici à qui montrer le lien : le montrer au
 * demandeur annulerait la seconde preuve. Sans SMTP configuré, on répond donc `409
 * courriel_non_envoyable` — un refus franc — plutôt qu'un succès qui n'enverrait rien.
 * Et `null://null` n'est pas un SMTP configuré : voir `EnvoiDInvitation`, qui a payé
 * pour l'apprendre.
 */
final readonly class ChangementDeCourriel
{
    public const int DUREE_DE_VALIDITE_EN_HEURES = 24;

    /** 32 octets → 43 caractères en base64 URL-sûre, sans remplissage. */
    private const int OCTETS_DU_JETON = 32;

    private const string FORME_DU_JETON = '/^[A-Za-z0-9_-]{43}$/';

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $utilisateurs,
        private EmailChangeRepository $demandes,
        private UserPasswordHasherInterface $hacheur,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        // Le DSN passe par un paramètre et non par `env:` directement : c'est ce qui
        // permet à l'environnement de test de le déclarer « configuré » pendant que le
        // transport réel reste `null://null` — les messages sont alors collectés par le
        // mailer de test au lieu de partir. Voir `config/services/core.yaml`.
        #[Autowire(param: 'app.core.mailer_dsn')]
        private string $dsn,
        #[Autowire(param: 'app.core.expediteur')]
        private string $expediteur,
    ) {
    }

    /**
     * `POST /api/session/courriel` — la demande.
     *
     * Validation, mot de passe, unicité, SMTP, envoi, **puis** écriture : la ligne n'est
     * persistée qu'une fois le courriel parti. Un envoi qui échoue ne laisse donc aucune
     * demande orpheline derrière lui.
     *
     * @param array<string, mixed> $corps
     *
     * @return array{courrielEnvoye: true, expiresAt: string}
     *
     * @throws ValidationException courriel invalide ou identique, mot de passe absent ou faux
     * @throws ConflictException   `courriel_deja_pris`, `courriel_non_envoyable`, `courriel_non_envoye`
     */
    public function demander(User $utilisateur, array $corps, Request $requete): array
    {
        $motDePasse = $corps['motDePasseActuel'] ?? null;
        $nouveau = $corps['nouveauCourriel'] ?? null;

        $champs = [];

        if (!\is_string($motDePasse) || '' === $motDePasse) {
            $champs['motDePasseActuel'] = 'Le mot de passe actuel est requis.';
        }

        if (!\is_string($nouveau) || false === filter_var($nouveau, \FILTER_VALIDATE_EMAIL)) {
            $champs['nouveauCourriel'] = 'Adresse de courriel invalide.';
        } elseif (mb_strtolower($nouveau) === mb_strtolower($utilisateur->getEmail())) {
            $champs['nouveauCourriel'] = 'C’est déjà l’adresse de ce compte.';
        }

        if ([] !== $champs) {
            throw new ValidationException($champs);
        }

        \assert(\is_string($motDePasse) && \is_string($nouveau));

        // `422` et non `401` : le compte est authentifié, c'est la donnée soumise qui est
        // mauvaise. Une `401` ferait déconnecter le front — voir § 2.1, même arbitrage.
        if (!$this->hacheur->isPasswordValid($utilisateur, $motDePasse)) {
            throw new ValidationException(['motDePasseActuel' => 'Le mot de passe actuel est incorrect.']);
        }

        // Révéler qu'un compte existe pour cette adresse est inévitable — l'index unique
        // refuserait de toute façon — et borné : il faut une session ET le mot de passe
        // pour poser la question.
        if (null !== $this->utilisateurs->findOneBy(['email' => $nouveau])) {
            throw new ConflictException('courriel_deja_pris', 'Un compte existe déjà pour ce courriel.');
        }

        if (!$this->smtpConfigure()) {
            throw new ConflictException('courriel_non_envoyable', 'Le changement de courriel exige l’envoi d’un lien de confirmation, et aucun envoi de courriel n’est configuré. Demandez à l’administrateur.');
        }

        $identifiant = $utilisateur->getId();
        \assert(null !== $identifiant);

        $maintenant = new \DateTimeImmutable();
        $jeton = $this->jeton();
        $expiration = $maintenant->modify(\sprintf('+%d hours', self::DUREE_DE_VALIDITE_EN_HEURES));

        $this->envoyer($nouveau, $this->lien($requete, $jeton), $expiration);

        // L'ancienne demande, s'il y en a une, est périmée et non supprimée : le journal
        // d'audit garde la trace, et un lien périmé répond comme un lien inconnu.
        foreach ($this->demandes->enCoursPour($identifiant) as $ancienne) {
            $ancienne->perimer($maintenant);
        }

        $this->em->persist(new EmailChange($identifiant, $nouveau, $this->empreinte($jeton), $expiration));
        $this->em->flush();

        return [
            'courrielEnvoye' => true,
            'expiresAt' => $expiration->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * `POST /api/session/courriel/confirmer` — le clic sur le lien. **Route publique.**.
     *
     * Le courriel devient l'identifiant du compte à cet instant. **La session qui
     * confirme continue**, sous la nouvelle adresse : le jeton de sécurité porte
     * l'entité `User`, modifiée pendant cette requête et resérialisée avec la nouvelle
     * adresse à la fin — `isEqualTo` compare ensuite nouveau contre nouveau. **Toute
     * autre session**, ouverte ailleurs sous l'ancienne adresse, tombe à sa requête
     * suivante, et c'est le comportement voulu : c'est le cas nominal d'un compte
     * repris, et le titulaire légitime a le nouveau courriel et son mot de passe.
     *
     * La première formulation de cette règle disait « toute session tombe ». Elle a été
     * corrigée par la mesure (`ChangementDeCourrielTest`), pas par le raisonnement.
     *
     * Le changement lui-même est journalisé par le souscripteur d'audit, comme toute
     * modification de `Core\User` : `changes.email` porte l'ancienne et la nouvelle
     * adresse. Aucune entrée à écrire à la main ici.
     *
     * @throws NotFoundException jeton mal formé, inconnu, périmé ou déjà consommé —
     *                           **un seul cas observable**
     * @throws ConflictException `courriel_deja_pris`, si l'adresse a été prise entre
     *                           la demande et le clic
     */
    public function confirmer(string $jeton): User
    {
        if (1 !== preg_match(self::FORME_DU_JETON, $jeton)) {
            throw new NotFoundException();
        }

        $demande = $this->demandes->parEmpreinte($this->empreinte($jeton));
        $maintenant = new \DateTimeImmutable();

        if (null === $demande || !$demande->estUtilisable($maintenant)) {
            throw new NotFoundException();
        }

        $utilisateur = $this->utilisateurs->find($demande->getUserId());

        if (null === $utilisateur) {
            throw new NotFoundException();
        }

        $autre = $this->utilisateurs->findOneBy(['email' => $demande->getNewEmail()]);

        if (null !== $autre && $autre->getId() !== $utilisateur->getId()) {
            throw new ConflictException('courriel_deja_pris', 'Un compte existe déjà pour ce courriel.');
        }

        $utilisateur->setEmail($demande->getNewEmail())->toucher();
        $demande->marquerUtilisee($maintenant);

        $this->em->flush();

        return $utilisateur;
    }

    public function smtpConfigure(): bool
    {
        $dsn = trim($this->dsn);

        return '' !== $dsn && !str_starts_with($dsn, 'null:');
    }

    private function jeton(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::OCTETS_DU_JETON)), '+/', '-_'), '=');
    }

    private function empreinte(string $jeton): string
    {
        return hash('sha256', $jeton);
    }

    /**
     * Le lien pointe sur le **front**, servi par la même origine que l'API. L'origine est
     * celle de la requête : en production, `Host` est vérifié contre la liste des hôtes
     * autorisés avant d'arriver ici, donc elle est digne de confiance — et il n'y a pas
     * de réglage de plus à tenir à jour.
     */
    private function lien(Request $requete, string $jeton): string
    {
        return $requete->getSchemeAndHttpHost().'/compte/courriel/'.$jeton;
    }

    private function envoyer(string $destinataire, string $lien, \DateTimeImmutable $expiration): void
    {
        $courriel = (new Email())
            ->to($destinataire)
            ->subject('Confirmez votre nouvelle adresse pour Heritage')
            ->text(
                "Bonjour,\n\n"
                ."Une demande de changement d'adresse a été faite pour votre compte Heritage.\n\n"
                ."Pour confirmer que cette adresse est bien la vôtre, ouvrez ce lien :\n"
                .$lien."\n\n"
                .\sprintf("Il ne sert qu'une fois et expire le %s.\n", $expiration->format('d/m/Y à H:i'))
                ."\nSi vous n'êtes pas à l'origine de cette demande, ignorez ce message : rien ne changera.\n",
            );

        if ('' !== trim($this->expediteur)) {
            $courriel->from($this->expediteur);
        }

        try {
            $this->mailer->send($courriel);
        } catch (TransportExceptionInterface $panne) {
            // Le détail va au journal, pas à la réponse : il porte le nom du relais.
            $this->logger->error('Envoi du lien de changement de courriel impossible.', ['exception' => $panne]);

            throw new ConflictException('courriel_non_envoye', 'L’envoi du courriel a échoué. Réessayez plus tard.');
        }
    }
}
