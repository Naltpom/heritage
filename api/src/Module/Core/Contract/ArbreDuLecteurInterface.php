<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

/**
 * **L'arbre du lecteur** — décision du commanditaire du 05/10/2026 : les onglets
 * d'Explorer (Chronologie, Carte, Calendrier, Stats) ne montrent que la famille du
 * lecteur, pas les fiches « sans lien connu » que la base porte à côté.
 *
 * L'arbre du lecteur est la **composante reliée** (parent, enfant, conjoint, dans les deux
 * sens) qui contient sa fiche — celle du membre emprunté sous la bascule « voir comme » —,
 * à défaut de fiche, celle de la racine de l'arbre.
 *
 * ── Ce n'est pas un filtre de confidentialité ──────────────────────────────────
 *
 * Le cercle de visibilité ({@see \App\Module\Core\Security\CercleDeVisibiliteInterface})
 * s'applique toujours, avant ou après : ce contrat ne fait que **resserrer** ce qu'un écran
 * montre à la famille du lecteur. D'où un repli **ouvert** ({@see ArbreDuLecteurOuvert}) :
 * mal câblé, un onglet montrerait les fiches sans lien comme avant, jamais une fiche que
 * le cercle refuse.
 *
 * ── `$toutLaBase` ───────────────────────────────────────────────────────────────
 *
 * La bascule « Toute la base » de l'administration. C'est au **contrôleur** de ne la
 * passer qu'à un administrateur ; à `true`, rien n'est retiré.
 */
interface ArbreDuLecteurInterface
{
    /**
     * @param list<int> $personIds
     *
     * @return list<int> ceux qui sont dans l'arbre du lecteur, dans l'ordre reçu
     */
    public function filtrer(array $personIds, bool $toutLaBase = false): array;
}
