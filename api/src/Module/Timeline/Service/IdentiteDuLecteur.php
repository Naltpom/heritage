<?php

declare(strict_types=1);

namespace App\Module\Timeline\Service;

use App\Module\Core\Entity\User;
use App\Module\Core\Exception\NotFoundException;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * L'identifiant du compte connecté — **le seul fichier de `Timeline` qui touche au
 * composant Security**.
 *
 * Même séparation que `Tree\Serializer\FabriqueDuLecteur`, et pour la même raison :
 * partout ailleurs le module manipule des entiers et des objets valeur qu'un test
 * construit en une ligne, sans monter un pare-feu, un fournisseur d'utilisateurs et une
 * session.
 *
 * ## Le lecteur n'entre pas dans le cercle, et c'est voulu
 *
 * `CercleDeVisibiliteInterface` ne prend **aucun** lecteur en paramètre : il le résout
 * lui-même, bascule « voir comme un membre » comprise. Cet identifiant-ci ne sert donc
 * **qu'aux préférences** — la ligne `tml_preference` du compte. Le passer au cercle serait
 * précisément l'erreur que le contrat du noyau ferme : *un lecteur passé par chaque
 * appelant est un lecteur qu'un appelant finira par construire sans la bascule.*
 *
 * Et c'est bien le compte **réel** qu'on rend, pas le membre emprunté : sous la bascule,
 * l'administrateur voit les données d'un autre, mais sa largeur de bande reste la sienne
 * (voir {@see ServiceDesPreferences}).
 */
final readonly class IdentiteDuLecteur
{
    public function __construct(private Security $securite)
    {
    }

    /**
     * @throws NotFoundException si aucune session n'est ouverte — cas qui ne devrait pas
     *                           se produire, `access_control` plaçant tout `/api` derrière
     *                           `ROLE_USER` ; on ne suppose pas pour autant que la
     *                           configuration est juste, et on rend `404` plutôt que `403`
     *                           (§ 15.3)
     */
    public function userId(): int
    {
        $utilisateur = $this->securite->getUser();

        if (!$utilisateur instanceof User || null === $id = $utilisateur->getId()) {
            throw new NotFoundException();
        }

        return $id;
    }
}
