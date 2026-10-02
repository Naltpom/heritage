<?php

declare(strict_types=1);

namespace App\Module\Evenement\Exception;

use App\Module\Core\Exception\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les erreurs métier du module, chacune avec son code `snake_case` **stable** : le front
 * branche dessus, le message peut être réécrit.
 *
 * Même geste que `Tirage\Exception\ErreurDeTirage` : le contrôleur ne construit jamais de
 * réponse d'erreur, la correspondance « erreur → code HTTP » se lit ici. Les codes communs
 * aux deux modules (`ressource_introuvable`, `reserve_a_l_organisateur`, `lien_invalide`,
 * `donnees_invalides`) sont **les mêmes chaînes** : le front les traite déjà.
 */
final class ErreurDEvenement extends DomainException
{
    /**
     * @param array<string, string>|null $champs
     * @param array<string, mixed>|null  $details
     */
    private function __construct(
        private readonly string $codeErreur,
        string $message,
        private readonly int $statut,
        private readonly ?array $champs = null,
        private readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Introuvable **ou** invisible pour ce compte : les deux rendent `404`, sans quoi la
     * réponse dirait quels événements existent (§ 15.3 du contrat).
     */
    public static function introuvable(): self
    {
        return new self('ressource_introuvable', 'Événement introuvable.', Response::HTTP_NOT_FOUND);
    }

    public static function reserveALOrganisateur(): self
    {
        return new self('reserve_a_l_organisateur', 'Seul l’organisateur de l’événement peut faire cela.', Response::HTTP_FORBIDDEN);
    }

    /** Un organisateur — ou un administrateur — qui n'est pas lui-même invité veut répondre. */
    public static function nonInvite(): self
    {
        return new self('non_invite', 'Vous n’êtes pas invité·e à cet événement : il n’y a rien à répondre.', Response::HTTP_FORBIDDEN);
    }

    /**
     * Le lien d'une personne qui a son compte : elle répond elle-même. Tenir sa clé, ce serait
     * pouvoir répondre et « voir » à sa place — et fausser exactement ce que le suivi mesure.
     */
    public static function lienReserveAuxSansCompte(): self
    {
        return new self(
            'acces_refuse',
            'Cette personne a son compte : elle reçoit l’invitation dans l’application, sans lien.',
            Response::HTTP_FORBIDDEN,
        );
    }

    public static function annule(): self
    {
        return new self('evenement_annule', 'Cet événement est annulé.', Response::HTTP_CONFLICT);
    }

    public static function passe(): self
    {
        return new self('evenement_passe', 'Cet événement est terminé : il ne se modifie plus.', Response::HTTP_CONFLICT);
    }

    /**
     * La réponse ne se change plus : l'événement est terminé ou annulé. `409` et non `422` :
     * c'est l'état de l'événement qui refuse, pas la valeur envoyée.
     */
    public static function reponseFigee(): self
    {
        return new self(
            'reponse_figee',
            'Il n’est plus possible de répondre : l’événement est terminé ou annulé.',
            Response::HTTP_CONFLICT,
        );
    }

    public static function relanceTropRecente(\DateTimeImmutable $prochaine): self
    {
        return new self(
            'relance_trop_recente',
            'Une relance est déjà partie il y a moins d’un jour. Laissez aux invités le temps de répondre.',
            Response::HTTP_CONFLICT,
            details: ['prochaineRelancePossibleLe' => $prochaine->format(\DATE_ATOM)],
        );
    }

    /** Personne à relancer par l'application : tout le monde a répondu, ou personne n'a de compte. */
    public static function personneARelancer(): self
    {
        return new self(
            'rien_a_relancer',
            'Aucun invité avec un compte n’attend de réponse : il n’y a personne à relancer par l’application.',
            Response::HTTP_CONFLICT,
        );
    }

    /**
     * On n'invite pas une personne disparue. `422` et non `409` : c'est la valeur envoyée qui
     * est fautive — et le champ est nommé, comme pour une personne inconnue de l'arbre.
     *
     * @param list<string> $noms
     */
    public static function inviteDecede(string $champ, array $noms): self
    {
        $message = 1 === \count($noms)
            ? \sprintf('%s est décédé·e : on n’invite que des personnes vivantes.', $noms[0])
            : \sprintf('%s sont décédé·e·s : on n’invite que des personnes vivantes.', implode(', ', $noms));

        return new self('invite_decede', $message, Response::HTTP_UNPROCESSABLE_ENTITY, [$champ => $message]);
    }

    /**
     * Le lien personnel est inconnu, révoqué, remplacé, ou son invité a été retiré — **une
     * seule réponse pour tous ces cas**, et la même pour une clé mal formée. Distinguer
     * « révoqué » d'« inconnu » dirait au porteur d'une vieille clé qu'elle a existé.
     */
    public static function lienInvalide(): self
    {
        return new self(
            'lien_invalide',
            'Ce lien n’est plus valide. Demandez-en un nouveau à l’organisateur de l’événement.',
            Response::HTTP_NOT_FOUND,
        );
    }

    /** @param array<string, string> $champs */
    public static function donneesInvalides(array $champs): self
    {
        return new self('donnees_invalides', 'Les données envoyées sont invalides.', Response::HTTP_UNPROCESSABLE_ENTITY, $champs);
    }

    public function statutHttp(): int
    {
        return $this->statut;
    }

    public function codeErreur(): string
    {
        return $this->codeErreur;
    }

    public function champs(): ?array
    {
        return $this->champs;
    }

    public function details(): ?array
    {
        return $this->details;
    }
}
