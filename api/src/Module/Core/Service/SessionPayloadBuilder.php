<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Contract\SessionPayloadContributorInterface;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\Regime;
use App\Module\Core\Enum\UserStatus;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Compose la **charge de session** du § 2.2 du contrat d'API — celle que le front lit au
 * démarrage, après chaque connexion, et après chaque bascule d'administration.
 *
 * Le socle (`utilisateur`, plus des défauts fermés pour le reste) vient d'ici ; le
 * rattachement et le cercle viennent des contributeurs de `Membership`
 * ({@see SessionPayloadContributorInterface}).
 */
final class SessionPayloadBuilder
{
    /** @param iterable<SessionPayloadContributorInterface> $contributeurs */
    public function __construct(
        #[AutowireIterator('core.contributeur_session')]
        private readonly iterable $contributeurs,
    ) {
    }

    /** @return array<string, mixed> */
    public function construire(User $utilisateur): array
    {
        $charge = [
            'utilisateur' => $this->utilisateur($utilisateur),
            // Défauts **fermés** : sans contributeur branché, le compte n'a ni
            // rattachement ni cercle. Une panne de `Membership` doit retirer de la
            // visibilité, jamais en accorder.
            'membre' => null,
            'cercle' => ['regime' => Regime::Aucun->value],
            'voirComme' => null,
            // Qui incarne ce compte, ou `null` : posé par {@see Incarnation} (ADR 0010).
            'incarnation' => null,
            'demandesEnAttente' => [],
        ];

        foreach ($this->contributeurs as $contributeur) {
            foreach ($contributeur->contribuer($utilisateur) as $cle => $valeur) {
                $charge[$cle] = $valeur;
            }
        }

        return UserStatus::EnAttente === $utilisateur->getStatus()
            ? $this->refermerRegimeEnAttente($charge)
            : $charge;
    }

    /** @return array<string, mixed> */
    private function utilisateur(User $utilisateur): array
    {
        $displayName = $utilisateur->getDisplayName();

        return [
            'id' => $utilisateur->getId(),
            'email' => $utilisateur->getEmail(),
            // « `displayName` retombe sur le courriel tant qu'il n'y a pas de
            // rattachement validé » (§ 2.2) : il n'est jamais renseigné depuis
            // `mbr_member.person_label` avant activation — ce serait donner le nom de la
            // personne réclamée à un compte qui n'y a pas droit.
            'displayName' => '' !== $displayName ? $displayName : $utilisateur->getEmail(),
            'roles' => $utilisateur->getRoles(),
            'status' => $utilisateur->getStatus()->value,
            'totpEnabledAt' => $this->instant($utilisateur->getTotpEnabledAt()),
            'lastLoginAt' => $this->instant($utilisateur->getLastLoginAt()),
        ];
    }

    /**
     * Le filet de sécurité du régime « en attente » (§ 12 du contrat, ADR 0003).
     *
     * Il ne corrige pas une erreur imaginaire : `Membership` calcule le cercle à partir
     * du `mbr_member`, et ce membre existe **dès l'inscription**, pré-lié à une personne.
     * Un contributeur écrit sans y penser rendrait donc `personId`, `personLabel` et un
     * cercle — pour un compte dont le rattachement n'est justement pas établi. On referme
     * ici, en un seul endroit, plutôt que de compter sur la vigilance de chaque module.
     *
     * `generations` et `taille` ne sont pas remis à zéro : ils sont **retirés**. « Le
     * front branche sur `regime`, jamais sur `taille === 0` », et il ne peut le faire que
     * si lire `taille` rend `undefined`.
     *
     * @param array<string, mixed> $charge
     *
     * @return array<string, mixed>
     */
    private function refermerRegimeEnAttente(array $charge): array
    {
        $charge['cercle'] = ['regime' => Regime::Aucun->value];
        $charge['voirComme'] = null;

        if (\is_array($charge['membre'])) {
            unset($charge['membre']['personId'], $charge['membre']['personLabel']);
        }

        return $charge;
    }

    private function instant(?\DateTimeImmutable $instant): ?string
    {
        return $instant?->format(\DateTimeInterface::ATOM);
    }
}
