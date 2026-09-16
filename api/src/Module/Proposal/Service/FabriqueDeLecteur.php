<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\Regime;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Proposal\Exception\AccesRefuse;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Construit le {@see Lecteur} de la requête en cours, depuis la session de sécurité.
 *
 * C'est le seul endroit du module qui touche au composant Security : partout ailleurs,
 * on manipule un `Lecteur`, qui est un objet valeur qu'un test construit en une ligne.
 * Cette séparation n'est pas cosmétique — elle est ce qui rend testable la règle la
 * plus sensible du module (« le calque est invisible d'un autre membre ») sans monter
 * un pare-feu, un fournisseur d'utilisateurs et une session.
 *
 * ── Deux faits qui se **lisent** chez `Core`, et ne se recalculent jamais ────────
 *
 * Le **régime** et le **membre emprunté** viennent l'un de
 * {@see CercleDeVisibiliteInterface::regime()}, l'autre de
 * {@see BasculeVoirComme::memberEmprunte()}. Les recalculer depuis `core_user.status` et
 * depuis une clé de session devinée serait une seconde définition de la même chose — et
 * c'est exactement ce qui s'est produit ici : ce fichier a lu pendant un temps une clé
 * `core.vue_comme_membre` que personne n'écrit. Le garde-fou était là, il compilait, il
 * était testé contre un `Lecteur` construit à la main… et il **ne s'est jamais
 * déclenché** en conditions réelles, parce que la valeur qu'il interrogeait n'existait
 * pas. Une règle de sécurité qui lit sa donnée au mauvais endroit ne laisse aucune
 * trace : elle répond simplement « rien à signaler ».
 *
 * La leçon vaut au-delà de ce cas : **une clé de session appartient au module qui
 * l'écrit**, et se lit par sa méthode, jamais par son nom recopié.
 */
final readonly class FabriqueDeLecteur
{
    public function __construct(
        private Security $securite,
        private CercleDeVisibiliteInterface $cercle,
        private BasculeVoirComme $bascule,
    ) {
    }

    /**
     * Le lecteur authentifié.
     *
     * @throws AccesRefuse si aucune session n'est ouverte — cas qui ne devrait pas
     *                     arriver, `access_control` plaçant tout `/api` derrière
     *                     `ROLE_USER` ; on ne suppose pas que la configuration est
     *                     juste pour autant
     */
    public function courant(): Lecteur
    {
        $utilisateur = $this->securite->getUser();

        if (!$utilisateur instanceof User) {
            throw AccesRefuse::demandeDAutrui();
        }

        $id = $utilisateur->getId();
        if (null === $id) {
            throw AccesRefuse::demandeDAutrui();
        }

        return new Lecteur(
            $id,
            // **Le repli sur le courriel, et pourquoi il est ici plutôt qu'à la lecture.**
            // `core_user.display_name` vaut `''` **par construction** pour un compte né du
            // parcours d'invitation : il n'est jamais renseigné depuis
            // `mbr_member.person_label` avant activation — ce serait donner le nom de la
            // personne réclamée à un compte qui n'y a pas droit (§ 2.2). Ce libellé part
            // ensuite dans `prp_change_request.author_label`, qui est un **instantané** :
            // il doit survivre au renommage et à la suppression du compte, donc il se
            // fige au dépôt et ne se recalcule jamais. Un instantané vide donnait
            // « proposée par , le 16 septembre 2026 » dans la file d'administration, et un
            // titre de notification commençant par une espace.
            //
            // Même forme exactement qu'à `Core\Service\SessionPayloadBuilder::utilisateur()`,
            // `Core\Service\UserAdminService::normaliser()` et
            // `Media\Security\FabriqueDeLecteur::depuisUtilisateur()` — c'est la règle du
            // § 2.2, écrite une quatrième fois et non une deuxième façon.
            '' !== $utilisateur->getDisplayName() ? $utilisateur->getDisplayName() : $utilisateur->getEmail(),
            $this->securite->isGranted('ROLE_ADMIN'),
            // Le régime est **lu**, jamais déduit de `core_user.status`. Les deux
            // coïncident aujourd'hui, et divergeraient dès la première bascule :
            // l'administrateur garde son statut `actif` pendant que son régime effectif
            // devient celui du membre emprunté.
            Regime::Aucun === $this->cercle->regime(),
            // `userIdEmprunte()` et **non** `memberEmprunte()` : `Lecteur` exprime
            // l'identité empruntée par un identifiant de **compte**, sur lequel reposent
            // `identiteEffective()` et `estAuteurDe()`. Les deux sont des entiers — les
            // confondre ne casse rien de visible, le calque se met simplement à désigner
            // le mauvais auteur. La bascule range les deux pour que la question ne se
            // pose plus ici.
            $this->bascule->userIdEmprunte(),
        );
    }
}
