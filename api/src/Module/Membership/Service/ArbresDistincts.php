<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Membership\Service\Port\VueDeLArbre;

/**
 * **Les arbres de la base**, et ce que chaque lecteur a le droit d'en lire — révision du
 * 2026-10-04 de l'ADR 0003, « un arbre par famille ».
 *
 * Un arbre est une **composante reliée** : l'ensemble des fiches qu'un chemin de liens
 * de famille (parent, enfant, conjoint, dans les deux sens) relie entre elles. Pas de
 * borne, pas de distinction sang / alliance : c'est la question « ces deux fiches
 * sont-elles dans le même arbre ? », pas « sont-elles parentes ? ». Un mariage entre
 * deux arbres en fait un seul — décision du commanditaire, assumée : « logique ».
 *
 * Le **périmètre** d'un lecteur est :
 *
 * - l'arbre de sa fiche ;
 * - plus chaque arbre **sans lien connu** — qu'aucune fiche rattachée à un compte ne
 *   tient ({@see Port\RattachementsAuxArbres}). Décision du commanditaire : « sans lien
 *   c'est sans lien », visible de tout le monde ; la case « Personnes sans lien connu »
 *   de l'arbre ne fait que les montrer ou les cacher à l'écran.
 *
 * Hors périmètre, une fiche n'existe pas pour le lecteur — **défunts compris**, et pas
 * même sous forme de nœud masqué : l'arbre d'un ami n'a rien à dire à la famille, ni
 * l'inverse. C'est ce qui distingue le périmètre du cercle : le cercle masque des
 * champs d'une fiche qu'on garde à sa place (un arbre amputé serait faux) ; le
 * périmètre retire des arbres entiers, et un arbre entier retiré ne laisse aucun trou.
 *
 * Pur, sans base : {@see \App\Tests\Membership\ArbresDistinctsTest}.
 */
final readonly class ArbresDistincts
{
    /**
     * @param array<int, int>  $arbreDe   personne ⇒ identifiant de son arbre (le plus petit
     *                                    identifiant de fiche qu'il contient)
     * @param array<int, true> $rattaches les arbres qu'au moins une fiche réclamée tient
     */
    private function __construct(
        private array $arbreDe,
        private array $rattaches,
    ) {
    }

    /**
     * @param list<int> $personnesRattachees {@see Port\RattachementsAuxArbres::personnes()}
     */
    public static function de(VueDeLArbre $vue, array $personnesRattachees): self
    {
        $arbreDe = [];
        $ids = array_keys($vue->personnes);
        sort($ids);

        foreach ($ids as $depart) {
            if (isset($arbreDe[$depart])) {
                continue;
            }

            // Les identifiants sont parcourus en ordre croissant : le départ est le plus
            // petit de sa composante, qui la nomme — déterministe, d'une requête à l'autre.
            $arbreDe[$depart] = $depart;
            $pile = [$depart];

            while ([] !== $pile) {
                $courant = array_pop($pile);

                foreach ([...$vue->parentsDe($courant), ...$vue->enfantsDe($courant), ...$vue->conjointsDe($courant)] as $voisin) {
                    if (!isset($arbreDe[$voisin]) && isset($vue->personnes[$voisin])) {
                        $arbreDe[$voisin] = $depart;
                        $pile[] = $voisin;
                    }
                }
            }
        }

        $rattaches = [];

        foreach ($personnesRattachees as $id) {
            if (isset($arbreDe[$id])) {
                $rattaches[$arbreDe[$id]] = true;
            }
        }

        return new self($arbreDe, $rattaches);
    }

    /** L'arbre de cette fiche, `null` pour une fiche inconnue de la vue. */
    public function arbreDe(int $personId): ?int
    {
        return $this->arbreDe[$personId] ?? null;
    }

    /** Cette fiche est-elle dans un arbre qu'aucun compte ne tient ? Inconnue : non. */
    public function estSansLien(int $personId): bool
    {
        $arbre = $this->arbreDe[$personId] ?? null;

        return null !== $arbre && !isset($this->rattaches[$arbre]);
    }

    /**
     * Cette fiche est-elle dans le périmètre d'un lecteur ancré sur `$ancre` ?
     *
     * `$ancre` nul — pas de fiche, régime `Aucun` — : les seuls arbres sans lien.
     *
     * **Une fiche inconnue de la vue n'est pas exclue** : le périmètre retire ce qu'on
     * **sait** appartenir à l'arbre d'un autre compte, et une fiche dont on ne connaît pas
     * l'arbre n'est d'aucun. Elle retombe sur les règles d'avant, qui la tiennent déjà
     * pour vivante, donc masquée ({@see VueDeLArbre::estVivante()}). En production
     * la vue est l'arbre complet, relu à chaque requête : l'inconnue est un identifiant
     * pendant, pas une fiche d'ami.
     */
    public function dansLePerimetre(int $personId, ?int $ancre): bool
    {
        $arbre = $this->arbreDe[$personId] ?? null;

        if (null === $arbre) {
            return true;
        }

        if (null !== $ancre && $arbre === ($this->arbreDe[$ancre] ?? null)) {
            return true;
        }

        return !isset($this->rattaches[$arbre]);
    }
}
