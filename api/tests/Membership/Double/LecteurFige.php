<?php

declare(strict_types=1);

namespace App\Tests\Membership\Double;

use App\Module\Membership\Service\LecteurDuCercle;
use App\Module\Membership\Service\SourceDuLecteur;

/**
 * Un lecteur posé à la main, pour tester la règle de confidentialité **sans pare-feu, sans
 * session et sans base**.
 *
 * C'est ce que l'interface {@see SourceDuLecteur} existe pour permettre. La bascule « voir
 * comme un membre », le régime du compte en attente et le cercle complet d'un
 * administrateur sont trois états de session ; ici ce sont trois constructions d'objet
 * valeur, et le test qui les distingue tient en trois lignes.
 */
final readonly class LecteurFige implements SourceDuLecteur
{
    public function __construct(private LecteurDuCercle $lecteur)
    {
    }

    public function courant(): LecteurDuCercle
    {
        return $this->lecteur;
    }
}
