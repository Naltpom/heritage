<?php

declare(strict_types=1);

namespace App\Module\Proposal\Exception;

/**
 * Les `422` du module : `donnees_invalides`, et le cas nommé `demande_sans_lien`.
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
    /** @param array<string, string> $champs */
    public function __construct(
        array $champs,
        string $message = 'Les données envoyées ne sont pas valides.',
        string $code = 'donnees_invalides',
    ) {
        parent::__construct($code, $message, 422, $champs);
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

    /** Le motif de refus, dont l'absence est refusée par `ck_prp_request_motif_refus` avant même le service. */
    public static function motifDeRefusManquant(): self
    {
        return new self(['message' => 'Le motif du refus est obligatoire.']);
    }
}
