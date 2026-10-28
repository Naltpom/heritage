<?php

declare(strict_types=1);

namespace App\Module\Proposal\Exception;

/**
 * Les `422` du module : `donnees_invalides`, et les cas nommés `demande_sans_lien` et
 * `idempotence_corps_different`.
 *
 * `champs` est **toujours** rempli : une 422 sans `champs` n'apprend rien à l'appelant
 * et le renvoie à la lecture du contrat, alors que c'est exactement le moment où il a
 * besoin de savoir quel champ reprendre.
 *
 * Les clés de `champs` suivent le chemin du corps de requête, avec l'index de l'op :
 * `ops[0].field`, `ops[1].baseVersion`, `message`. C'est ce que le § 6.2 du contrat
 * d'API annonce (« ⇒ `422` `champs.ops[n].baseVersion` »), et c'est ce qui permet à un
 * formulaire de poser l'erreur sur la bonne ligne.
 */
final class DonneesInvalides extends ErreurDeDemande
{
    /**
     * @param array<string, string>     $champs
     * @param array<string, mixed>|null $details
     */
    public function __construct(
        array $champs,
        string $message = 'Les données envoyées ne sont pas valides.',
        string $code = 'donnees_invalides',
        ?array $details = null,
    ) {
        parent::__construct($code, $message, 422, $champs, $details);
    }

    public static function pourChamp(string $champ, string $message): self
    {
        return new self([$champ => $message]);
    }

    /**
     * `422 demande_sans_lien` — code distinct au § 15.2, parce que le front n'a rien à
     * corriger dans un formulaire : il doit renvoyer l'auteur à l'écran des liens.
     */
    public static function demandeSansLien(): self
    {
        return new self(
            ['liens' => 'Une demande de création doit porter au moins un lien.'],
            'Sans lien, la personne proposée flotterait hors de l\'arbre.',
            'demande_sans_lien',
        );
    }

    /**
     * `422 idempotence_corps_different` (ADR 0022) : la clé a déjà servi, chez ce même
     * auteur, à déposer un **autre** corps. Le rejouer rendrait une demande qui ne dit pas ce
     * que le client vient d'envoyer. `demandeId` désigne la demande que la clé a déjà créée.
     * Elle est à son auteur : la nommer ne révèle rien.
     */
    public static function cleDIdempotenceDejaEmployee(int $demandeId): self
    {
        return new self(
            ['Idempotency-Key' => \sprintf('Cette clé a déjà servi à déposer la demande n° %d, avec un autre corps.', $demandeId)],
            'Cette clé d\'idempotence a déjà servi pour un autre corps : changez de clé, ou renvoyez le corps d\'origine.',
            'idempotence_corps_different',
            ['demandeId' => $demandeId],
        );
    }

    /** Le motif de refus, dont l'absence est refusée par `ck_prp_request_motif_refus` avant même le service. */
    public static function motifDeRefusManquant(): self
    {
        return new self(['message' => 'Le motif du refus est obligatoire.']);
    }
}
