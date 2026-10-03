<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Core\Contract\SessionPayloadContributorInterface;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\Regime;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MemberStatus;
use App\Module\Membership\Repository\MemberRepository;

/**
 * La contribution de `Membership` à la charge de session du § 2.2 du contrat d'API : les
 * clés `membre` et `cercle`.
 *
 * **Pas `voirComme`** — `Core\Service\BasculeVoirComme` est un contributeur lui aussi, et
 * c'est lui qui la rend. Deux contributeurs qui renseignent la même clé de premier niveau
 * se contredisent en silence : « une clé rendue **remplace** celle du socle », et l'ordre
 * d'itération du tag n'est garanti nulle part. Celui qui **écrit** la bascule est celui
 * qui la rend.
 *
 * `Core` sait dire qui est connecté ; il ne sait pas — et ne doit pas savoir — à quelle
 * personne de l'arbre ce compte est rattaché. D'où cette couture
 * ({@see SessionPayloadContributorInterface}, tag `core.contributeur_session`), qui évite
 * un `if` dans `Core` et une dépendance que l'ADR 0002 lui interdit.
 *
 * ## Les trois différences de la charge d'un compte en attente, et qui les tient
 *
 * Le § 2.2 les énumère et précise qu'elles « ne sont pas cosmétiques » :
 *
 * 1. `cercle.regime` vaut `"aucun"`, **pas** `"cercle"` avec une liste vide, et
 *    `generations` et `taille` sont **absents** — pour qu'un front qui testerait
 *    `taille === 0` lise `undefined` et non `0` ;
 * 2. `membre.personId` et `membre.personLabel` sont **absents**. Le compte prétend se
 *    rattacher à une personne, éventuellement déjà pré-liée sur l'invitation — mais
 *    *confirmer une date n'est pas la lire*, et le pré-lien n'ouvre aucun accès. C'est la
 *    porte de sortie n° 8 (§ 11.3), celle des libellés dénormalisés ;
 * 3. `utilisateur.displayName` retombe sur le courriel — tenu par `Core`, qui ne renseigne
 *    jamais ce champ depuis `mbr_member.person_label`.
 *
 * `Core\Service\SessionPayloadBuilder` **referme** de toute façon la charge d'un compte
 * `en_attente`, et son en-tête dit pourquoi : « un contributeur écrit sans y penser
 * rendrait `personId`, `personLabel` et un cercle ». **Ce n'est pas une raison de s'y
 * reposer.** Les deux gardes sont indépendantes et se vérifient séparément : la nôtre
 * couvre le cas où ce contributeur serait un jour appelé ailleurs qu'à travers le
 * constructeur de charge, la sienne couvre le cas où ce fichier serait mal remanié.
 */
final readonly class ContributeurDeSession implements SessionPayloadContributorInterface
{
    public function __construct(
        private MemberRepository $membres,
        private CercleDeVisibilite $cercle,
        private ReglagesDeMembership $reglages,
        private Reciprocite $reciprocite,
    ) {
    }

    /** @return array<string, mixed> */
    public function contribuer(User $utilisateur): array
    {
        $userId = $utilisateur->getId();
        $membre = null !== $userId ? $this->membres->pourUtilisateur($userId) : null;

        return [
            'membre' => null !== $membre ? $this->membre($membre) : null,
            'cercle' => $this->cercle(),
            // ADR 0016. Le choix **du compte de la session** — celui que l'écran « Mon compte »
            // modifie —, `null` sans rattachement.
            'partageNaissance' => null !== $membre ? [
                ...$membre->getPartageDeNaissance()->enTableau(),
                'modifieLe' => $membre->getPartageModifieLe()?->format(\DateTimeInterface::ATOM),
            ] : null,
            // Et ce qui s'applique **au lecteur effectif** (le membre emprunté sous la
            // bascule) : l'option d'administration, et s'il a une photo de lui.
            'reciprocite' => [
                'restrictionIgnoree' => $this->reciprocite->restrictionIgnoree(),
                'photo' => $this->reciprocite->lecteurAUnePhoto(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function membre(Member $membre): array
    {
        $charge = [
            'id' => $membre->getId(),
            'status' => $membre->getStatus()->value,
            'matchKind' => $membre->getMatchKind()->value,
            'activatedAt' => $membre->getActivatedAt()?->format(\DateTimeInterface::ATOM),
        ];

        // La personne n'entre dans la charge qu'une fois le rattachement **validé**. Les
        // deux clés se posent ensemble ou pas du tout : un `personId` sans libellé serait
        // un identifiant de personne vivante livré à un compte non validé, ce qui est
        // précisément le début d'une énumération (§ 4.2, divergence 2).
        //
        // Un compte sans fiche (`a_rattacher`, ADR 0013) n'est jamais actif — la base le
        // garantit — et n'entre donc jamais ici ; le test de nullité le redit pour le cas où
        // la contrainte tomberait.
        if (MemberStatus::Actif === $membre->getStatus() && null !== $membre->getPersonId()) {
            $charge['personId'] = $membre->getPersonId();
            $charge['personLabel'] = $membre->getPersonLabel();
        }

        return $charge;
    }

    /**
     * @return array<string, mixed>
     */
    private function cercle(): array
    {
        if (Regime::Cercle !== $this->cercle->regime()) {
            return ['regime' => Regime::Aucun->value];
        }

        return [
            'regime' => Regime::Cercle->value,
            'generations' => $this->reglages->generationsDuCercle(),
            'taille' => $this->cercle->taille() ?? 0,
        ];
    }
}
