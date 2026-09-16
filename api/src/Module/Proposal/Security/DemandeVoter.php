<?php

declare(strict_types=1);

namespace App\Module\Proposal\Security;

use App\Module\Core\Entity\User;
use App\Module\Proposal\Entity\ChangeRequest;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * L'anti-IDOR du § 8 du dossier de mission : « toute route qui prend un identifiant
 * passe par un voter ».
 *
 * `GET /api/demandes/{id}` est accessible à **son auteur** et aux **administrateurs**,
 * personne d'autre.
 *
 * **`403`, et non `404`** — c'est une exception nommée à la règle générale du § 15.3
 * (« une ressource que le lecteur n'a pas le droit de voir rend 404 »), pour deux
 * raisons écrites au § 6 du contrat d'API : à la différence d'un jeton d'invitation,
 * l'existence d'une demande n'est pas un secret et n'apprend rien sur les personnes de
 * l'arbre ; et un `404` empêcherait de diagnostiquer un lien partagé par erreur.
 *
 * @extends Voter<string, ChangeRequest>
 */
final class DemandeVoter extends Voter
{
    /** Lire une demande : son auteur, ou un administrateur. */
    public const string VOIR = 'prp_voir_demande';

    /** Soumettre ou retirer : **son auteur seulement**, administrateur compris. */
    public const string MODIFIER = 'prp_modifier_demande';

    /** Valider ou refuser : administrateur. */
    public const string DECIDER = 'prp_decider_demande';

    public function __construct(private readonly Security $securite)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VOIR, self::MODIFIER, self::DECIDER], true)
            && $subject instanceof ChangeRequest;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $utilisateur = $token->getUser();

        if (!$utilisateur instanceof User || null === $utilisateur->getId()) {
            return false;
        }

        $estAuteur = $subject->getAuthorUserId() === $utilisateur->getId();
        $estAdministrateur = $this->securite->isGranted('ROLE_ADMIN');

        return match ($attribute) {
            self::VOIR => $estAuteur || $estAdministrateur,
            // Un administrateur ne soumet ni ne retire la demande d'un autre : ce serait
            // décider à sa place **sans laisser de trace de décision**. Pour trancher, il
            // a `valider` et `refuser`, qui en laissent une.
            self::MODIFIER => $estAuteur,
            self::DECIDER => $estAdministrateur,
            default => false,
        };
    }
}
