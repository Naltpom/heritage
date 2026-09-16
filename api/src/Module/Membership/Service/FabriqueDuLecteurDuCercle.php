<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\Regime;
use App\Module\Core\Security\RegimeVoter;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MemberStatus;
use App\Module\Membership\Repository\MemberRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Construit le {@see LecteurDuCercle} de la requête en cours, depuis la session de
 * sécurité.
 *
 * C'est le **seul** fichier de ce module qui touche au composant Security, et le seul qui
 * lise la session. Partout ailleurs on manipule un objet valeur qu'un test construit en
 * une ligne.
 *
 * ## Pourquoi le lecteur n'est pas un paramètre des méthodes publiques
 *
 * `Core\Security\CercleDeVisibiliteInterface` le dit et l'argumente : la bascule « voir
 * comme un membre » (§ 10.4) doit s'appliquer à **tous** les appels, et « un lecteur passé
 * par chaque appelant est un lecteur qu'un appelant finira par construire sans la
 * bascule ». La résolution a donc lieu ici, une fois, et les appelants n'ont pas le moyen
 * de l'oublier.
 *
 * ## La bascule est lue au service de `Core`, jamais à la clé de session
 *
 * `Core\Service\BasculeVoirComme` **écrit** cette clé ; c'est donc lui qui sait la lire.
 * Un module qui la relirait à la main dupliquerait un nom de clé — et c'est déjà arrivé
 * dans ce dépôt : `Proposal` et `Tree` lisent `core.vue_comme_membre` par le paramètre
 * `app.proposal.cle_vue_comme_membre`, alors que `Core` écrit `core.voir_comme.member_id`.
 * Deux modules qui lisent deux clés différentes donnent une bascule qui filtre l'arbre
 * mais pas les médias, **et une bascule à moitié appliquée ne vérifie plus rien**.
 * Signalé au livrable ; ici on lit le propriétaire.
 *
 * ## L'administrateur passe par un cercle complet
 *
 * Corollaire non négociable de l'ADR 0003 : « le normaliseur n'a pas de mode sans
 * filtre ». Un administrateur obtient `cercleComplet = true`, ce qui donne plus loin un
 * cercle **contenant toutes les personnes de l'arbre** — et non un court-circuit. La
 * différence se voit le jour où un rôle est attribué par erreur : un cercle complet reste
 * un cercle, qui se journalise, se teste, et se remplace par la bascule.
 */
final readonly class FabriqueDuLecteurDuCercle implements SourceDuLecteur
{
    public function __construct(
        private Security $securite,
        private MemberRepository $membres,
        private BasculeVoirComme $bascule,
    ) {
    }

    public function courant(): LecteurDuCercle
    {
        $utilisateur = $this->securite->getUser();

        if (!$utilisateur instanceof User || null === ($userId = $utilisateur->getId())) {
            // Hors requête authentifiée — une commande de console, un appel interne. Pas
            // de session, donc pas de cercle : le défaut fermé vaut ici comme ailleurs.
            return LecteurDuCercle::sansCercle(0);
        }

        $emprunte = $this->membreEmprunte();

        if (null !== $emprunte) {
            return $this->sousBascule($userId, $emprunte);
        }

        // Le régime est lu à `Core\Security\RegimeVoter`, jamais recalculé : c'est
        // l'unique correspondance statut → régime du projet, et une seconde, même
        // triviale, finirait par ne plus dire la même chose.
        if (Regime::Aucun === RegimeVoter::regimeDe($utilisateur)) {
            return LecteurDuCercle::sansCercle($userId);
        }

        $membre = $this->membres->pourUtilisateur($userId);

        if ($this->securite->isGranted('ROLE_ADMIN')) {
            return new LecteurDuCercle(
                userId: $userId,
                regime: Regime::Cercle,
                memberId: $membre?->getId(),
                cercleComplet: true,
            );
        }

        // Un compte actif sans rattachement, ou dont le rattachement n'est pas actif :
        // référence pendante ou activation incomplète. On ferme — un cercle qu'on ne sait
        // pas ancrer n'est pas un cercle vide, c'est une absence de cercle.
        if (null === $membre || MemberStatus::Actif !== $membre->getStatus()) {
            return LecteurDuCercle::sansCercle($userId);
        }

        return new LecteurDuCercle(
            userId: $userId,
            regime: Regime::Cercle,
            memberId: $membre->getId(),
            personneAncre: $membre->getPersonId(),
        );
    }

    /**
     * La bascule **remplace un cercle par un autre, elle n'en supprime jamais un**
     * (§ 10.4). Si le membre emprunté a disparu ou n'est pas actif, on ferme plutôt que de
     * retomber sur le cercle complet de l'administrateur : un écran qui montre tout alors
     * que le bandeau annonce « vu comme Cobalt » est exactement la fausse assurance que la
     * bascule existe pour éviter.
     */
    private function sousBascule(int $userId, Member $emprunte): LecteurDuCercle
    {
        if (MemberStatus::Actif !== $emprunte->getStatus()) {
            return new LecteurDuCercle($userId, Regime::Aucun, $emprunte->getId(), sousBascule: true);
        }

        return new LecteurDuCercle(
            userId: $userId,
            regime: Regime::Cercle,
            memberId: $emprunte->getId(),
            personneAncre: $emprunte->getPersonId(),
            sousBascule: true,
        );
    }

    /**
     * Le membre emprunté, lu dans la session — et **seulement pour un administrateur**.
     *
     * La double vérification n'est pas de la paranoïa : la clé de session est posée par
     * une route `/api/administration`, mais un rôle retiré en cours de session laisserait
     * la clé en place. Le contrôle du rôle à la lecture rend la bascule inopérante dès la
     * requête suivante.
     */
    private function membreEmprunte(): ?Member
    {
        if (!$this->securite->isGranted('ROLE_ADMIN')) {
            return null;
        }

        $memberId = $this->bascule->memberEmprunte();

        return null !== $memberId ? $this->membres->find($memberId) : null;
    }
}
