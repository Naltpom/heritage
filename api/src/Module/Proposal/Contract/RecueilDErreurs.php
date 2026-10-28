<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

use App\Module\Core\Exception\DomainException;
use App\Module\Proposal\Exception\DonneesInvalides;

/**
 * Les `422` d'une seule passe du dépôt à blanc, **rendues en une fois** (ADR 0022).
 *
 * Le dépôt s'arrête à la première erreur, et c'est juste : il n'écrit rien tant que tout
 * n'est pas bon. Mais un client qui ne fait que vérifier (`POST /api/demandes/verifier`)
 * paierait alors un aller-retour par champ fautif, et un agent un tour de modèle par champ.
 * Ce recueil note les `422` des contrôles indépendants (une par champ de l'enveloppe, une
 * par élément des listes) pour qu'elles partent ensemble.
 *
 * ── Pourquoi dans `Contract\` et non dans `Service\` ────────────────────────────
 *
 * Il passe par {@see \App\Module\Proposal\Service\ConstructeurDeDemande::construire()}, et
 * l'un des constructeurs vit dans un autre module : celui de l'ajout de média, dans `Media`.
 * Une référence vers `Proposal\Contract\` est licite d'un module à l'autre ; vers
 * `Proposal\Service\`, elle demanderait une exemption de frontière de plus (ADR 0002).
 *
 * ── La première erreur rencontrée décide du statut ──────────────────────────────
 *
 * Le dépôt à blanc doit rendre le statut que rendrait le dépôt. Une `422` notée laisse donc
 * la lecture continuer, mais une `403`, une `404` ou une `409` l'arrête comme elle
 * arrêterait le dépôt. Si une `422` la précède, c'est sur elle que le dépôt se serait
 * arrêté : c'est elle qui part.
 */
final class RecueilDErreurs
{
    /** @var list<DonneesInvalides> */
    private array $erreurs = [];

    /**
     * Le geste de chaque `catch` du dépôt à blanc :
     * - sans recueil (le dépôt), l'erreur repart telle quelle, et rien ne change ;
     * - une 422 est notée, et la lecture continue ;
     * - toute autre erreur (403, 404, 409) arrête tout, comme au dépôt. Mais si une 422 la
     *   précède, c'est elle que le dépôt aurait rendue, et c'est donc elle qui part.
     */
    public static function recueillir(?self $recueil, DomainException $erreur): void
    {
        if (null === $recueil) {
            throw $erreur;
        }

        if ($erreur instanceof DonneesInvalides) {
            $recueil->erreurs[] = $erreur;

            return;
        }

        $recueil->leverSiNonVide();

        throw $erreur;
    }

    /** @throws DonneesInvalides */
    public function leverSiNonVide(): void
    {
        $erreur = $this->erreur();

        if (null !== $erreur) {
            throw $erreur;
        }
    }

    /**
     * `null` si rien n'a été noté. **Une seule erreur : l'exception d'origine, intacte**
     * (code, message, champs). C'est elle que lit l'assistant. Plusieurs : leurs `champs`
     * réunis dans l'ordre où ils ont été rencontrés, le premier message gardé pour une clé
     * en double, et le code commun s'il n'y en a qu'un, sinon `donnees_invalides`.
     */
    public function erreur(): ?DonneesInvalides
    {
        if ([] === $this->erreurs) {
            return null;
        }

        if (1 === \count($this->erreurs)) {
            return $this->erreurs[0];
        }

        $champs = [];
        $codes = [];

        foreach ($this->erreurs as $erreur) {
            $champs += $erreur->champs() ?? [];
            $codes[$erreur->codeErreur()] = true;
        }

        return new DonneesInvalides(
            $champs,
            'La demande porte plusieurs erreurs : chacune est nommée dans « champs ».',
            1 === \count($codes) ? (string) array_key_first($codes) : 'donnees_invalides',
        );
    }
}
