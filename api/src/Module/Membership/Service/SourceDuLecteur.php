<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

/**
 * D'où vient le {@see LecteurDuCercle} de la requête en cours.
 *
 * Une interface pour une seule implémentation, et le motif est celui que
 * `Tree\Serializer\FabriqueDuLecteur` écrit chez lui : c'est le **seul** point du module
 * qui touche au composant Security et à la session. Le garder derrière un contrat permet à
 * {@see CercleDeVisibilite} — la règle de confidentialité elle-même — de se tester sans
 * pare-feu, sans session et sans base, en lui donnant un lecteur construit à la main.
 *
 * Sans cette couture, vérifier « une personne vivante hors cercle est masquée » demanderait
 * de monter un pare-feu, d'ouvrir une session et de rejouer une requête HTTP — et un test
 * qu'on renonce à écrire parce qu'il est pénible est un test qui n'existe pas.
 */
interface SourceDuLecteur
{
    public function courant(): LecteurDuCercle;
}
