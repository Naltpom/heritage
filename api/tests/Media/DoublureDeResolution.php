<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Security\ResolutionDuSujetInterface;
use App\Module\Media\Security\SujetResolu;

/**
 * La traduction « sujet de média → personnes », jouée par un double.
 *
 * Elle tient la place de l'implémentation de `Tree`. Elle ne juge de rien : un sujet
 * déclaré ici **existe**, avec son libellé et les personnes dont il dépend. Que le
 * lecteur ait le droit de le voir est la question de {@see DoublureDeCercle}, et les deux
 * ne doivent jamais se confondre — c'est la séparation que l'adaptateur réel matérialise.
 */
final class DoublureDeResolution implements ResolutionDuSujetInterface
{
    /** @var array<string, SujetResolu> */
    private array $sujets = [];

    /**
     * @param list<int>|null $personIds les personnes dont dépend la visibilité ; par
     *                                  défaut l'identifiant lui-même, ce qui est le cas
     *                                  d'un sujet `personne`
     */
    public function declarer(MediaSubject $kind, int $id, string $libelle, ?array $personIds = null): self
    {
        $this->sujets[$kind->value.':'.$id] = new SujetResolu($libelle, $personIds ?? [$id]);

        return $this;
    }

    public function resoudre(MediaSubject $kind, int $id): ?SujetResolu
    {
        return $this->sujets[$kind->value.':'.$id] ?? null;
    }

    public function personnesEngagees(MediaSubject $kind, array $ids): array
    {
        $sortie = [];

        foreach ($ids as $id) {
            $resolu = $this->resoudre($kind, $id);

            if (null !== $resolu) {
                $sortie[$id] = $resolu->personIds;
            }
        }

        return $sortie;
    }
}
