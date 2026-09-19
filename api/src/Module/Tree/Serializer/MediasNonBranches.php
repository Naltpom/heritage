<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Le repli de {@see PortDesMedias} tant que `Media` n'a pas branché le sien : **aucun
 * portrait, aucune preuve**.
 *
 * Rendre du vide est ici la bonne réponse et non un pis-aller : une fiche sans portrait
 * est une fiche valide, et c'est l'état de 190 des 274 personnes. Ce qui serait faux
 * serait de lever — on priverait de la fiche entière pour une vignette.
 *
 * `Media` prend la place par une ligne d'alias dans `config/services/media.yaml`, qui
 * est chargé après l'attribut ci-dessous.
 */
#[AsAlias(PortDesMedias::class)]
final class MediasNonBranches implements PortDesMedias
{
    public function portraitDe(int $personneId, LecteurDeLArbre $lecteur): ?array
    {
        return null;
    }

    public function mediasDePersonne(int $personneId, LecteurDeLArbre $lecteur): array
    {
        return [];
    }

    public function mediasDesCitations(array $citationIds, LecteurDeLArbre $lecteur): array
    {
        return [];
    }

    /**
     * Aucune personne n'a de média tant que `Media` n'est pas branché — et c'est exact,
     * pas approximatif : aucun média n'est servable non plus. Le filtre « Avec image »
     * rendra donc zéro résultat, ce qui est la vérité de ce que l'API sait servir.
     */
    public function vignettesDePortraits(array $personneIds, LecteurDeLArbre $lecteur): array
    {
        return [];
    }

    public function personnesAvecMedia(array $personneIds, LecteurDeLArbre $lecteur): array
    {
        return [];
    }
}
