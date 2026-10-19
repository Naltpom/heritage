<?php

declare(strict_types=1);

namespace App\Module\Noel\Contract;

/**
 * Ce que `Noel` demande à `Tree` : le lien de parenté de chaque participant **vu depuis le
 * lecteur** — « cousine germaine », « époux de ta tante » —, le même libellé que le champ
 * `parente` d'une fiche ; et qui, parmi eux, est son conjoint dans l'arbre.
 *
 * `Tree` l'implémente (`Tree\Service\ParentesPourNoel`). Le site de Noël ne sert les liens
 * qu'aux administrateurs ; c'est `Noel` qui le vérifie.
 */
interface PortParentesPourNoel
{
    /**
     * Le libellé de chaque fiche demandée, depuis la fiche du compte `$lecteurUserId`.
     * Vide si le lecteur n'a pas de rattachement validé ; une fiche sans lien établi est
     * absente.
     *
     * @param array<int, int> $fiches identifiant de compte ⇒ fiche rattachée
     *
     * @return array<int, string> indexé par identifiant de compte
     */
    public function liens(int $lecteurUserId, array $fiches): array;

    /**
     * Les comptes dont la fiche est unie — sans divorce — à celle de `$lecteurUserId`.
     * Vide si le lecteur n'a pas de rattachement validé.
     *
     * @param array<int, int> $fiches identifiant de compte ⇒ fiche rattachée
     *
     * @return list<int> identifiants de compte
     */
    public function conjoints(int $lecteurUserId, array $fiches): array;
}
