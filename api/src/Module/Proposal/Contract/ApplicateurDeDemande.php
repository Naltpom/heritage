<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

use App\Module\Core\Contract\Lecteur;
use App\Module\Proposal\Enum\RequestKind;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * LE SECOND POINT DE GREFFE DE LA VALIDATION — celui des demandes sans op.
 *
 * {@see ApplicateurDOperation} applique des **ops** ; celui-ci applique **une demande**.
 * Les deux existent parce que les demandes ne sont pas toutes faites d'ops : une
 * `creation_personne` n'en porte aucune — le § 6.5 du contrat d'API la montre avec
 * `"ops": []` —, elle porte des `prp_draft_person` et des `prp_draft_link`. Un
 * applicateur d'op ne saurait pas quoi en faire, et lui passer une demande entière lui
 * ferait porter deux responsabilités.
 *
 * Il est découvert par le tag `app.proposal.applicateur_de_demande`, posé par
 * `autoconfigure` : **rien à modifier dans le validateur**, qui est le fichier qu'on
 * veut le moins toucher puisqu'il porte la distinction entre les deux `409`.
 *
 * ── Ce qu'un applicateur doit savoir ────────────────────────────────────────────
 *
 * - Il est appelé **dans la transaction**, après le contrôle préalable. À ce moment, la
 *   décision entre application, rejeu et caducité est déjà prise : il applique, il ne
 *   décide pas.
 * - Il **n'écrit dans aucune table d'un autre module** : tout passe par
 *   {@see Port\PortArbre} (ADR 0002, règle 5).
 * - Il rend ce qu'il a écrit sous forme d'{@see EcritureDeRevision}, **sans empreinte** :
 *   c'est le validateur qui la calcule une fois, après toutes les écritures, et qui la
 *   pose sur toutes les révisions du lot.
 * - Il laisse remonter `Doctrine\ORM\OptimisticLockException` — c'est elle qui devient
 *   `verrou_concurrent`, la `409` qui dit « réessayer ».
 */
#[AutoconfigureTag('app.proposal.applicateur_de_demande')]
interface ApplicateurDeDemande
{
    /**
     * Les natures de demande que cet applicateur sait appliquer.
     *
     * @return list<RequestKind>
     */
    public function natures(): array;

    /**
     * Applique la demande, et rend ce qui a ete ecrit.
     *
     * @param list<OpEnAttente> $ops toutes les ops de la demande, dans l'ordre de `seq`
     *
     * @return list<EcritureDeRevision>
     */
    public function appliquer(DemandeEnCours $demande, array $ops, Lecteur $lecteur): array;
}
