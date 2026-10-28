<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Contract\AnnuaireDesMembresInterface;
use App\Module\Core\Contract\SessionPayloadContributorInterface;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Exception\ConflictException;
use App\Module\Core\Exception\ForbiddenException;
use App\Module\Core\Exception\NotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * La bascule **« voir comme un membre »** (§ 10.4 du contrat d'API) : l'administrateur
 * regarde l'arbre à travers le cercle de quelqu'un d'autre.
 *
 * C'est à la fois une commodité et **le seul moyen de vérifier la règle de
 * confidentialité de l'extérieur**. D'où le soin apporté à ses trois pièges — chacun la
 * viderait de son sens sans rien casser de visible.
 *
 * ## Elle est portée par la session, pas par un en-tête
 *
 * Une bascule par requête est une bascule qu'on oublie de rejouer sur un appel, et un
 * appel qui l'oublie ne vérifie plus rien. Elle doit en outre survivre à la navigation,
 * sinon l'administrateur n'explore pas réellement l'arbre sous cette identité.
 *
 * Elle ne se lit donc que dans la session **apportée par la requête** — son cookie
 * (`hasPreviousSession()`), jamais dans la session que Symfony attache à toute requête
 * (`hasSession()`) et qu'une première lecture ouvrirait. Une requête par jeton d'API, que
 * le pare-feu `api_jetons` juge sans état et dépouille de ses cookies (ADR 0021 § 4), n'a
 * pas de bascule, n'en ouvre pas, et n'y gagne aucun cookie.
 *
 * ## Piège n° 1 — l'`ETag` du graphe
 *
 * **`GET /api/arbre/graphe` doit incorporer {@see memberEmprunte()} dans son `ETag`
 * tant que la bascule est active.** Sans cela, l'administrateur revalide, reçoit `304`,
 * et regarde **son propre graphe non filtré** en croyant tester celui de quelqu'un
 * d'autre : l'outil censé vérifier la confidentialité affirme alors que tout va bien,
 * quoi qu'il arrive. C'est la panne la plus coûteuse de ce fichier, parce qu'elle est
 * silencieuse et qu'elle ment dans le sens rassurant.
 *
 * Ce module ne calcule pas l'`ETag` — il appartient à `Tree`. {@see memberEmprunte()}
 * est publique **pour cela**.
 *
 * ## Piège n° 2 — aucune écriture sous identité empruntée
 *
 * {@see refuserSiActive()} est à appeler en tête de **toute** route d'écriture :
 * création de demande, validation, refus, invitation, téléversement, réglages, rôles.
 * On ne valide pas une demande sous une identité empruntée — la `prp_revision`
 * porterait l'administrateur alors que l'écran montrait autre chose, et le journal
 * deviendrait ininterprétable. Le front désactive les boutons ; l'API ne compte pas
 * dessus.
 *
 * ## Piège n° 3 — ce n'est pas un contournement du filtre
 *
 * La bascule **remplace** un cercle par un autre ; elle n'en supprime jamais un. Le
 * normaliseur n'a pas de mode « sans filtre » (ADR 0003) : l'administrateur passe par un
 * cercle complet, jamais par un `if ($estAdmin) { return $tout; }` — « exactement le bug
 * qu'on découvrira dans deux ans, le jour où un rôle sera attribué par erreur ».
 */
final class BasculeVoirComme implements SessionPayloadContributorInterface
{
    /**
     * Clés de session. Préfixées par le module : la session est un espace partagé et
     * rien ne protège d'une collision de noms entre deux modules.
     */
    private const string CLE_MEMBRE = 'core.voir_comme.member_id';

    /**
     * Le **compte** du membre emprunté, rangé en même temps que son identifiant de
     * membre. Voir {@see userIdEmprunte()} : les deux sont des entiers, les confondre ne
     * casse rien de visible, et c'est précisément pourquoi la bascule les range toutes
     * les deux plutôt que de laisser chaque appelant faire la conversion.
     */
    private const string CLE_COMPTE = 'core.voir_comme.user_id';

    private const string CLE_DEPUIS = 'core.voir_comme.depuis';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly AnnuaireDesMembresInterface $annuaire,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Active la bascule sur un membre, et la **journalise une fois** — « c'est une
     * lecture de données masquées, elle se journalise » (§ 10.4).
     *
     * @throws NotFoundException  si le membre n'existe pas, ou si `Membership` n'est pas
     *                            branché : dans les deux cas l'emprunt n'a pas de sens
     * @throws ForbiddenException `403 acces_refuse` sans session apportée par la requête
     *                            — un jeton d'API : la bascule ne lui survivrait pas
     */
    public function activer(int $memberId): void
    {
        // La session d'abord : sans elle, ni `404` ni `200` — un jeton n'apprend pas quels
        // membres existent.
        $session = $this->sessionApportee() ?? throw new ForbiddenException();

        $libelle = $this->annuaire->libelleDuMembre($memberId)
            ?? throw new NotFoundException('Ce membre n’existe pas.');

        $session->set(self::CLE_MEMBRE, $memberId);
        // Résolu **ici**, une fois, et rangé : sans lui, chaque appelant qui raisonne en
        // compte — le calque « en attente » notamment — n'aurait d'autre choix que de
        // ranger un identifiant de membre là où un identifiant de compte est attendu.
        $session->set(self::CLE_COMPTE, $this->annuaire->userIdDuMembre($memberId));
        $session->set(self::CLE_DEPUIS, (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));

        // Une fois, à l'activation — pas à chaque requête servie sous la bascule, sinon
        // une exploration de dix minutes noierait le journal d'audit.
        $this->audit->enregistrer(
            AuditAction::VueCommeMembre,
            'Membership\\Member',
            (string) $memberId,
            $libelle,
            ['memberId' => $memberId],
        );
    }

    public function desactiver(): void
    {
        // Sans session apportée, aucune bascule n'est active : rien à quitter, et rien à
        // ouvrir pour le dire.
        $session = $this->sessionApportee();

        if (null === $session) {
            return;
        }

        $session->remove(self::CLE_MEMBRE);
        $session->remove(self::CLE_COMPTE);
        $session->remove(self::CLE_DEPUIS);
    }

    /**
     * Le **`core_user.id`** du membre emprunté, ou `null` hors bascule.
     *
     * C'est celui-ci qu'attendent `Core\Contract\Lecteur::$membreEmprunteUserId` et tout
     * ce qui raisonne en compte — `identiteEffective()`, `estAuteurDe()`, le calque
     * « en attente ». **Ne pas confondre avec {@see memberEmprunte()}** : l'un est un
     * `mbr_member.id`, l'autre un `core_user.id`, les deux sont des entiers, et les
     * échanger ne casse rien de visible. Le calque se met simplement à désigner le
     * mauvais auteur.
     *
     * `null` sous bascule active signifie que l'annuaire n'a pas su résoudre le compte —
     * `Membership` non branché, ou membre supprimé entre-temps. L'appelant ferme alors ce
     * qu'il sert plutôt que de retomber sur l'administrateur : servir le calque de
     * l'administrateur sous une identité empruntée est précisément ce que la règle 3 du
     * § 10.4 interdit.
     */
    public function userIdEmprunte(): ?int
    {
        $valeur = $this->sessionApportee()?->get(self::CLE_COMPTE);

        return \is_int($valeur) ? $valeur : null;
    }

    /**
     * L'identifiant du membre emprunté, ou `null`.
     *
     * **C'est le grain que `Tree` doit incorporer dans l'`ETag` du graphe** (piège n° 1),
     * et celui que l'implémentation du cercle de visibilité doit lire pour savoir quel
     * cercle appliquer.
     */
    public function memberEmprunte(): ?int
    {
        $valeur = $this->sessionApportee()?->get(self::CLE_MEMBRE);

        return \is_int($valeur) ? $valeur : null;
    }

    public function estActive(): bool
    {
        return null !== $this->memberEmprunte();
    }

    /**
     * La charge `voirComme` du § 10.4, ou `null` quand la bascule est inactive.
     *
     * @return array{memberId: int, memberLabel: string|null, depuis: string|null}|null
     */
    public function charge(): ?array
    {
        $memberId = $this->memberEmprunte();

        if (null === $memberId) {
            return null;
        }

        $depuis = $this->sessionApportee()?->get(self::CLE_DEPUIS);

        return [
            'memberId' => $memberId,
            'memberLabel' => $this->annuaire->libelleDuMembre($memberId),
            'depuis' => \is_string($depuis) ? $depuis : null,
        ];
    }

    /**
     * La clé `voirComme` de la charge de session (§ 2.2).
     *
     * Contributeur plutôt qu'une dépendance de `SessionPayloadBuilder` : le constructeur
     * du bâtisseur reste sans service de session, donc testable sans requête, et la
     * bascule se branche par le même tag que les contributions de `Membership`. Le
     * bâtisseur referme ensuite la charge d'un compte `en_attente`, qui n'est jamais
     * administrateur — la clé y revient donc à `null` quoi qu'on rende ici.
     *
     * @return array<string, mixed>
     */
    public function contribuer(User $utilisateur): array
    {
        if (null === $this->sessionApportee()) {
            return [];
        }

        return ['voirComme' => $this->charge()];
    }

    /**
     * À appeler en tête de toute route d'écriture (piège n° 2).
     *
     * @throws ConflictException `409 vue_comme_membre_active`
     */
    public function refuserSiActive(): void
    {
        $memberId = $this->memberEmprunte();

        if (null === $memberId) {
            return;
        }

        $details = ['memberId' => $memberId, 'memberLabel' => $this->annuaire->libelleDuMembre($memberId)];

        $motif = 'Quittez la vue « voir comme un membre » avant d’écrire.';

        throw new ConflictException('vue_comme_membre_active', $motif, $details);
    }

    /**
     * La session **apportée par la requête**, ou `null` : hors requête (console), ou sans
     * cookie de session — une requête par jeton d'API (voir l'en-tête de la classe).
     *
     * `hasPreviousSession()` et non `hasSession()` : la seconde répond oui à toute requête
     * principale, celle d'un jeton comprise, parce que Symfony y attache une fabrique de
     * session ; la première lecture ouvrirait alors une session neuve, et la réponse sans
     * état repartirait avec un cookie (ADR 0021 § 4). Sans session apportée, aucune
     * bascule ne peut être active : elle ne vit que d'une requête à l'autre.
     */
    private function sessionApportee(): ?SessionInterface
    {
        $requete = $this->requestStack->getCurrentRequest();

        if (null === $requete || !$requete->hasPreviousSession()) {
            return null;
        }

        return $requete->getSession();
    }
}
