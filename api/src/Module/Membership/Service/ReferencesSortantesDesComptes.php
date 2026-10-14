<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Core\Contract\ReferenceInterModules;
use App\Module\Core\Contract\ReferencesInterModulesInterface;
use App\Module\Core\Enum\CibleDeReference;
use App\Module\Core\Service\ReleveurDeReferences;
use App\Module\Membership\Enum\InvitationStatus;

/**
 * Les références de `Membership` vers l'arbre et vers les comptes.
 *
 * `mbr_member.person_id` est la flèche qui a motivé la commande : un `TRUNCATE` de
 * `tree_person` l'a déjà laissée pendante une fois, et le compte s'est retrouvé sans racine
 * — sans cercle ni parenté calculables ({@see CercleDeVisibilite}). Elle est stricte.
 *
 * Deux colonnes sont déclarées sous deux portées, parce qu'une partie de leurs lignes est
 * faite pour survivre à la fiche :
 *
 * - `mbr_invitation.person_id` : seule une invitation **active** doit désigner une fiche
 *   existante — c'est elle qui bloque une suppression. Une invitation utilisée, expirée ou
 *   révoquée ne produira plus rien : c'est de l'historique (§ 6.4 quater du contrat d'API) ;
 * - `mbr_autorisation_naissance.person_id` : seule l'autorisation **active** est lue par la
 *   réciprocité, et elle bloque la suppression. Une autorisation archivée est une **preuve**
 *   de consentement, « archivée, jamais supprimée » (ADR 0016) : elle survit à sa fiche.
 */
final readonly class ReferencesSortantesDesComptes implements ReferencesInterModulesInterface
{
    public function __construct(private ReleveurDeReferences $releveur)
    {
    }

    public function references(): array
    {
        $active = ['statut' => InvitationStatus::Active->value];

        return [
            $this->compte('mbr_member', 'user_id'),
            $this->compte('mbr_member', 'activated_by_user_id'),
            new ReferenceInterModules('mbr_member.person_id', CibleDeReference::Personne, $this->releveur->occurrences('mbr_member', 'person_id')),

            new ReferenceInterModules(
                'mbr_invitation.person_id',
                CibleDeReference::Personne,
                $this->releveur->occurrences('mbr_invitation', 'person_id', 'status = :statut', $active),
                'invitations actives',
            ),
            new ReferenceInterModules(
                'mbr_invitation.person_id',
                CibleDeReference::Personne,
                $this->releveur->occurrences('mbr_invitation', 'person_id', 'status <> :statut', $active),
                'invitations utilisées, expirées ou révoquées',
                'historique : une invitation close ne produira plus aucun compte',
            ),
            $this->compte('mbr_invitation', 'created_by_user_id'),
            $this->compte('mbr_invitation', 'used_by_user_id'),
            $this->compte('mbr_invitation', 'revoked_by_user_id'),

            new ReferenceInterModules(
                'mbr_autorisation_naissance.person_id',
                CibleDeReference::Personne,
                $this->releveur->occurrences('mbr_autorisation_naissance', 'person_id', 'archived_at IS NULL'),
                'autorisations actives',
            ),
            new ReferenceInterModules(
                'mbr_autorisation_naissance.person_id',
                CibleDeReference::Personne,
                $this->releveur->occurrences('mbr_autorisation_naissance', 'person_id', 'archived_at IS NOT NULL'),
                'autorisations archivées',
                'preuve de consentement archivée, jamais supprimée (ADR 0016)',
            ),
            $this->compte('mbr_autorisation_naissance', 'granted_by_user_id'),
            $this->compte('mbr_autorisation_naissance', 'archived_by_user_id'),

            $this->compte('mbr_demande_rgpd', 'user_id'),
            $this->compte('mbr_demande_rgpd', 'traitee_par_user_id'),
        ];
    }

    private function compte(string $table, string $colonne): ReferenceInterModules
    {
        return new ReferenceInterModules($table.'.'.$colonne, CibleDeReference::Compte, $this->releveur->occurrences($table, $colonne));
    }
}
