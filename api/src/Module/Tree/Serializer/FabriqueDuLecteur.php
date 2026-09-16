<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Core\Contract\AnnuaireDesMembresInterface;
use App\Module\Core\Entity\User;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Security\RegimeVoter;
use App\Module\Core\Service\BasculeVoirComme;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Construit le {@see LecteurDeLArbre} de la requête en cours, depuis la session de
 * sécurité.
 *
 * C'est le **seul** fichier de ce module qui touche au composant Security. Partout
 * ailleurs on manipule un objet valeur qu'un test construit en une ligne — et c'est
 * cette séparation qui rend écrivables les tests de la règle la plus sensible du
 * produit, sans monter un pare-feu, un fournisseur d'utilisateurs et une session.
 *
 * ## La bascule « voir comme un membre » se lit chez `Core`, pas dans la session
 *
 * Elle appartient à `Core` (§ 10.4) et vit dans la **session**, pas dans un en-tête par
 * requête : une bascule qu'on oublie de rejouer sur un appel ne vérifie plus rien. On
 * passe par {@see BasculeVoirComme::memberEmprunte()} plutôt que de relire la clé de
 * session nous-mêmes, et ce n'est pas un raffinement de style : un module qui recopie le
 * nom d'une clé de session d'un autre module **échoue en silence** le jour où ce nom
 * change — la bascule reste active à l'écran et n'a plus aucun effet sur le filtrage.
 * `Core` est en liste blanche de l'ADR 0002 ; il n'y a donc rien à contourner.
 *
 * `Tree` ne fait que lire : la bascule n'y interdit rien. Elle **remplace un cercle par
 * un autre** — le contrat du noyau la résout lui-même — et elle entre dans l'`ETag` du
 * graphe ({@see EtagDuGraphe}), ce qui est le piège que ce projet a déjà payé une fois.
 */
final readonly class FabriqueDuLecteur
{
    public function __construct(
        private Security $securite,
        private BasculeVoirComme $bascule,
        private AnnuaireDesMembresInterface $annuaire,
    ) {
    }

    /**
     * @throws NotFoundException si aucune session n'est ouverte — cas qui ne devrait pas
     *                           se produire, `access_control` plaçant tout `/api` derrière
     *                           `ROLE_USER` ; on ne suppose pas pour autant que la
     *                           configuration est juste, et on rend `404` plutôt que `403`
     *                           (§ 15.3)
     */
    public function courant(): LecteurDeLArbre
    {
        $utilisateur = $this->securite->getUser();

        if (!$utilisateur instanceof User) {
            throw new NotFoundException();
        }

        $id = $utilisateur->getId();

        if (null === $id) {
            throw new NotFoundException();
        }

        $membreEmprunte = $this->bascule->memberEmprunte();

        return new LecteurDeLArbre(
            $id,
            // Même repli qu'à `Proposal\Service\FabriqueDeLecteur` et pour le même motif
            // (§ 2.2) : un compte né du parcours d'invitation a `display_name = ''`.
            //
            // **Ici le défaut est latent, et on le ferme quand même.** `Tree` ne fait que
            // lire : ce libellé ne part dans aucune colonne, il ne traverse que
            // `LecteurDeLArbre::pourLeCalque()`, dont les décisions portent sur `userId`,
            // `administrateur` et `regime` — jamais sur le libellé. Aucun test ne tombe
            // donc si on l'oublie, et c'est précisément la raison de ne pas l'oublier :
            // des trois fabriques de lecteur du produit, deux portaient le repli et une
            // ne le portait pas, sans que rien ne puisse le signaler.
            '' !== $utilisateur->getDisplayName() ? $utilisateur->getDisplayName() : $utilisateur->getEmail(),
            $this->securite->isGranted('ROLE_ADMIN'),
            RegimeVoter::regimeDe($utilisateur),
            $membreEmprunte,
            // **Les deux identifiants ne sont pas interchangeables.** `Core` range en
            // session un `mbr_member.id` ; tout ce qui raisonne en aval — le calque « en
            // attente », `estAuteurDe()`, la parenté — raisonne en **compte**. Ranger l'un
            // là où l'autre est attendu ne ferait rien tomber : les deux sont des entiers,
            // et le calque se mettrait simplement à désigner le mauvais auteur. Une panne
            // qui ment dans le sens rassurant, comme l'`ETag` qui rendait `304`.
            null === $membreEmprunte ? null : $this->annuaire->userIdDuMembre($membreEmprunte),
        );
    }
}
