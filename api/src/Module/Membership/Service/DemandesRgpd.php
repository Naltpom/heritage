<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\NiveauDePartage;
use App\Module\Core\Enum\NotificationKind;
use App\Module\Core\Exception\ConflictException;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Service\AuditService;
use App\Module\Core\Service\NotificationService;
use App\Module\Core\ValueObject\ComposantesDeDate;
use App\Module\Membership\Entity\DemandeRgpd;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Repository\DemandeRgpdRepository;
use App\Module\Membership\Repository\MemberRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * **Le formulaire RGPD** (ADR 0016) : la soupape du verrou de 30 jours, et le canal de
 * toute demande d'un membre sur ses données.
 *
 * Le membre dépose ; les administrateurs sont notifiés ; l'un d'eux applique ou refuse,
 * avec une réponse. Un retrait **appliqué** passe par {@see ConsentementDeNaissance} en
 * source `administration`, qui ignore le verrou. Chaque geste est journalisé.
 */
final readonly class DemandesRgpd
{
    private const int LONGUEUR_MAX = 4000;

    public function __construct(
        private EntityManagerInterface $em,
        private DemandeRgpdRepository $demandes,
        private MemberRepository $membres,
        private ConsentementDeNaissance $consentements,
        private NotificationService $notifications,
        private AuditService $audit,
    ) {
    }

    /**
     * @param array<mixed> $corps `{nature, annee?, mois?, jour?, message?}`
     *
     * @throws ConflictException   `compte_non_rattache`
     * @throws ValidationException nature inconnue, retrait sans composante, message absent
     */
    public function deposer(User $utilisateur, array $corps): DemandeRgpd
    {
        $membre = $this->membreDe($utilisateur);

        $nature = $corps['nature'] ?? null;
        if (!\is_string($nature) || !\in_array($nature, DemandeRgpd::NATURES, true)) {
            throw new ValidationException(['nature' => 'Choisissez la nature de la demande.']);
        }

        [$aRetirer, $erreurs] = ConsentementDeNaissance::lireLesComposantes($corps);
        $message = \is_string($corps['message'] ?? null) ? trim($corps['message']) : '';

        if (DemandeRgpd::RETRAIT_PARTAGE === $nature) {
            if ($aRetirer->estVide()) {
                $erreurs['composantes'] = 'Cochez au moins une information à retirer.';
            }
        } else {
            $aRetirer = ComposantesDeDate::aucune();
            if ('' === $message) {
                $erreurs['message'] = 'Décrivez votre demande.';
            }
        }
        if (mb_strlen($message) > self::LONGUEUR_MAX) {
            $erreurs['message'] = \sprintf('%d caractères au plus.', self::LONGUEUR_MAX);
        }
        if ([] !== $erreurs) {
            throw new ValidationException($erreurs);
        }

        $demande = new DemandeRgpd($membre, $nature, $aRetirer, '' === $message ? null : $message);
        $this->em->persist($demande);
        $this->em->flush();

        $this->audit->enregistrer(AuditAction::DemandeRgpd, 'demande_rgpd', (string) $demande->getId(), $membre->getUserEmail(), ['geste' => 'deposee', 'nature' => $nature]);

        // Le titre nomme le compte et la nature, jamais le message : une notification se lit
        // hors de l'écran qui instruit la demande.
        $this->notifications->notifierLesAdministrateurs(
            NotificationKind::DemandeRgpd,
            'Une demande RGPD attend votre réponse',
            \sprintf('%s : %s.', $membre->getUserEmail(), self::libelle($nature)),
            'administration.rgpd',
        );

        return $demande;
    }

    /** @return list<DemandeRgpd> */
    public function duCompte(User $utilisateur): array
    {
        return $this->demandes->duCompte((int) $utilisateur->getId());
    }

    /** @return list<DemandeRgpd> */
    public function file(): array
    {
        return $this->demandes->file();
    }

    /**
     * @param array<mixed> $corps `{decision: "appliquee"|"refusee", reponse?}`
     *
     * @throws NotFoundException   demande inconnue
     * @throws ConflictException   `demande_deja_traitee`
     * @throws ValidationException décision inconnue, refus sans réponse
     */
    public function traiter(int $id, array $corps, User $administrateur): DemandeRgpd
    {
        $demande = $this->demandes->find($id) ?? throw new NotFoundException();

        if ($demande->estTraitee()) {
            throw new ConflictException('demande_deja_traitee', 'Cette demande a déjà été traitée.');
        }

        $decision = $corps['decision'] ?? null;
        if (!\in_array($decision, [DemandeRgpd::APPLIQUEE, DemandeRgpd::REFUSEE], true)) {
            throw new ValidationException(['decision' => 'Appliquer ou refuser.']);
        }
        $reponse = \is_string($corps['reponse'] ?? null) ? trim($corps['reponse']) : '';
        if (DemandeRgpd::REFUSEE === $decision && '' === $reponse) {
            throw new ValidationException(['reponse' => 'Expliquez le refus au membre.']);
        }

        // Un retrait appliqué passe les composantes visées à `personne`, **sans** verrou :
        // c'est le retrait du consentement, entier. Le membre remonte ensuite à `proches`
        // librement s'il le souhaite.
        if (DemandeRgpd::APPLIQUEE === $decision && DemandeRgpd::RETRAIT_PARTAGE === $demande->getNature()) {
            $membre = $this->membres->find($demande->getMemberId());
            if ($membre instanceof Member) {
                $this->consentements->enregistrer(
                    $membre,
                    $membre->getPartageDeNaissance()->avec($demande->getARetirer(), NiveauDePartage::Personne),
                    'administration',
                );
            }
        }

        $demande->traiter($decision, '' === $reponse ? null : $reponse, (int) $administrateur->getId());
        $this->em->flush();

        $this->audit->enregistrer(AuditAction::DemandeRgpd, 'demande_rgpd', (string) $demande->getId(), $demande->getUserEmail(), ['geste' => 'traitee', 'nature' => $demande->getNature(), 'decision' => $decision]);

        // L'administrateur qui traite sa propre demande n'a pas à en être notifié.
        if ($demande->getUserId() !== $administrateur->getId()) {
            $this->notifications->notifier(
                $demande->getUserId(),
                NotificationKind::DemandeRgpdTraitee,
                DemandeRgpd::APPLIQUEE === $decision ? 'Votre demande RGPD a été appliquée' : 'Votre demande RGPD a été refusée',
                '' === $reponse ? null : $reponse,
                'compte',
            );
        }

        return $demande;
    }

    /** @return array<string, mixed> */
    public static function normaliser(DemandeRgpd $demande, bool $pourAdministration = false): array
    {
        return [
            'id' => $demande->getId(),
            'nature' => $demande->getNature(),
            'aRetirer' => $demande->getARetirer()->enTableau(),
            'message' => $demande->getMessage(),
            'creeeLe' => $demande->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'traiteeLe' => $demande->getTraiteeAt()?->format(\DateTimeInterface::ATOM),
            'decision' => $demande->getDecision(),
            'reponse' => $demande->getReponse(),
        ] + ($pourAdministration ? ['memberId' => $demande->getMemberId(), 'userId' => $demande->getUserId(), 'userEmail' => $demande->getUserEmail()] : []);
    }

    private static function libelle(string $nature): string
    {
        return match ($nature) {
            DemandeRgpd::RETRAIT_PARTAGE => 'retrait d’une date de naissance partagée',
            DemandeRgpd::RECTIFICATION => 'rectification',
            default => 'autre demande',
        };
    }

    private function membreDe(User $utilisateur): Member
    {
        $membre = null !== ($id = $utilisateur->getId()) ? $this->membres->pourUtilisateur($id) : null;

        return $membre ?? throw new ConflictException('compte_non_rattache', 'Ce compte n’est rattaché à aucune personne de l’arbre.');
    }
}
