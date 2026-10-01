<?php

declare(strict_types=1);

namespace App\Module\Tirage\Exception;

use App\Module\Core\Exception\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les erreurs métier du module, chacune avec son code `snake_case` **stable** : le front
 * branche dessus, le message peut être réécrit.
 *
 * Même geste que `Question\Exception\ErreurDeQuestion` : le contrôleur ne construit
 * jamais de réponse d'erreur, la correspondance « erreur → code HTTP » se lit ici.
 */
final class ErreurDeTirage extends DomainException
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
     * réponse dirait quels tirages existent (§ 15.3 du contrat).
     */
    public static function introuvable(): self
    {
        return new self('ressource_introuvable', 'Tirage introuvable.', Response::HTTP_NOT_FOUND);
    }

    public static function reserveALOrganisateur(): self
    {
        return new self('reserve_a_l_organisateur', 'Seul l’organisateur du tirage peut faire cela.', Response::HTTP_FORBIDDEN);
    }

    public static function nonAutorisePourCeParticipant(): self
    {
        return new self('acces_refuse', 'Vous ne pouvez pas modifier la participation de cette personne.', Response::HTTP_FORBIDDEN);
    }

    public static function nonAutorisePourLeLien(): self
    {
        return new self(
            'acces_refuse',
            'Seul le référent de cette personne peut gérer son lien — ou l’organisateur, si elle n’a ni compte ni référent : son tirage reste secret.',
            Response::HTTP_FORBIDDEN,
        );
    }

    public static function sansRattachement(): self
    {
        return new self(
            'rattachement_requis',
            'Votre compte n’est rattaché à aucune personne de l’arbre : vous ne pouvez pas rejoindre un tirage.',
            Response::HTTP_CONFLICT,
        );
    }

    public static function fermeAuxInscriptions(): self
    {
        return new self('tirage_ferme', 'Ce tirage n’accepte pas d’inscription libre : demandez à son organisateur.', Response::HTTP_FORBIDDEN);
    }

    public static function dejaParticipant(): self
    {
        return new self('deja_participant', 'Cette personne participe déjà à ce tirage.', Response::HTTP_CONFLICT);
    }

    public static function editionExistante(int $annee): self
    {
        return new self('edition_existante', \sprintf('L’édition %d existe déjà.', $annee), Response::HTTP_CONFLICT, details: ['annee' => $annee]);
    }

    public static function dejaTiree(): self
    {
        return new self(
            'edition_deja_tiree',
            'Le tirage de cette année est déjà fait. Annulez-le d’abord pour le refaire.',
            Response::HTTP_CONFLICT,
        );
    }

    public static function pasEncoreTiree(): self
    {
        return new self('edition_non_tiree', 'Le tirage de cette année n’a pas encore eu lieu.', Response::HTTP_CONFLICT);
    }

    public static function retraitApresLeTirage(): self
    {
        return new self(
            'edition_deja_tiree',
            'Le tirage est fait : se retirer maintenant laisserait quelqu’un sans cadeau. Demandez à l’organisateur d’annuler le tirage.',
            Response::HTTP_CONFLICT,
        );
    }

    public static function tropPeuDeParticipants(int $minimum): self
    {
        return new self(
            'participants_insuffisants',
            \sprintf('Il faut au moins %d participants présents pour tirer au sort.', $minimum),
            Response::HTTP_CONFLICT,
            details: ['minimum' => $minimum],
        );
    }

    /**
     * Le cas typique : trois participants dont deux forment un couple. Le troisième ne
     * peut offrir qu'à l'un des deux, et le couple ne peut offrir qu'au troisième — qui ne
     * reçoit qu'un cadeau.
     */
    public static function impossible(): self
    {
        return new self(
            'tirage_impossible',
            'Aucun tirage ne respecte les règles, même sans tenir compte des années passées. Ajoutez des participants ou autorisez les conjoints à se tirer.',
            Response::HTTP_CONFLICT,
        );
    }

    /**
     * On ne tire pas au sort un cadeau pour quelqu'un qui n'est plus là. `422` et non
     * `409` : c'est la valeur envoyée qui est fautive, pas l'état du tirage — et le champ
     * est nommé, comme pour une personne inconnue de l'arbre.
     *
     * @param list<string> $noms
     */
    public static function participantDecede(string $champ, array $noms): self
    {
        $message = 1 === \count($noms)
            ? \sprintf('%s est décédé·e : on ne tire pas au sort un cadeau pour une personne disparue.', $noms[0])
            : \sprintf('%s sont décédé·e·s : on ne tire pas au sort un cadeau pour une personne disparue.', implode(', ', $noms));

        return new self('participant_decede', $message, Response::HTTP_UNPROCESSABLE_ENTITY, [$champ => $message]);
    }

    /**
     * Le lien personnel est inconnu, révoqué, remplacé, ou son participant a quitté le
     * tirage — **une seule réponse pour tous ces cas**, et la même pour une clé mal formée.
     * Distinguer « révoqué » d'« inconnu » dirait au porteur d'une vieille clé qu'elle a
     * existé, donc qu'il a trouvé la bonne forme.
     */
    public static function lienInvalide(): self
    {
        return new self(
            'lien_invalide',
            'Ce lien n’est plus valide. Demandez-en un nouveau à l’organisateur du tirage.',
            Response::HTTP_NOT_FOUND,
        );
    }

    /**
     * Par le lien personnel, la présence ne se règle que tant que l'édition est ouverte et
     * à venir. Après le tirage, un changement touche l'organisation de quelqu'un d'autre :
     * il passe par un compte, pas par un lien.
     */
    public static function presenceFigee(): self
    {
        return new self(
            'presence_figee',
            'La présence ne peut plus être modifiée par ce lien : le tirage est fait ou la date est passée. Voyez avec l’organisateur.',
            Response::HTTP_CONFLICT,
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
