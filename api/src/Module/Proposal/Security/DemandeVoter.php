<?php

declare(strict_types=1);

namespace App\Module\Proposal\Security;

use App\Module\Core\Entity\User;
use App\Module\Core\Security\JetonDApiAuthentifie;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestStatus;
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

    /**
     * Retirer : son auteur, comme {@see self::MODIFIER} — et, sous un jeton d'API, **jamais
     * une demande en attente** (ADR 0021 § 2). Le jeton est celui du compte du commanditaire :
     * sans cette borne, un agent retirerait de la file toute demande de ce compte, celles que
     * le commanditaire a envoyées lui-même comprises, et un retrait est définitif.
     *
     * Le brouillon passe, **tout brouillon du compte** : celui que le commanditaire a saisi
     * dans le formulaire comme celui d'un agent, rien ne les distingue. Une demande tranchée
     * passe aussi, pour que le service réponde `409 demande_deja_decidee` : un agent qui
     * rejoue un retrait dont il a perdu la réponse doit l'apprendre.
     */
    public const string RETIRER = 'prp_retirer_demande';

    /** Valider ou refuser : administrateur — et, sous un jeton d'API, auteur de la demande. */
    public const string DECIDER = 'prp_decider_demande';

    /**
     * Compléter sur place une fiche proposée (§ 6.6 ter, ADR 0017) : **son auteur, ou un
     * administrateur**.
     *
     * Le voter ne juge que la personne ; le statut, il ne le voit pas, et c'est
     * {@see \App\Module\Proposal\Service\CompletionDeProposition} qui le juge — l'auteur
     * complète son brouillon comme sa demande en attente, l'administrateur la demande en
     * attente d'un autre, jamais son brouillon. Un autre membre ne complète pas : il dépose
     * un **complément**, que l'administrateur fusionne.
     */
    public const string COMPLETER = 'prp_completer_demande';

    public function __construct(private readonly Security $securite)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VOIR, self::MODIFIER, self::RETIRER, self::DECIDER, self::COMPLETER], true)
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
            self::RETIRER => $estAuteur
                && (null === JetonDApiAuthentifie::depuisLeJetonDeSecurite($token)
                    || RequestStatus::Brouillon === $subject->getStatus()
                    || $subject->getStatus()->estDefinitif()),
            // Sous un jeton d'API, décider exige en plus d'être l'auteur (ADR 0021 § 5) : la
            // session principale tranche les demandes que ses agents ont déposées sous le
            // même compte, jamais celle d'un membre, qui reste à un humain. Sans jeton,
            // rien ne change.
            self::DECIDER => $estAdministrateur
                && (null === JetonDApiAuthentifie::depuisLeJetonDeSecurite($token) || $estAuteur),
            self::COMPLETER => $estAuteur || $estAdministrateur,
            default => false,
        };
    }
}
