<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

use App\Module\Core\Contract\Lecteur;
use App\Module\Proposal\Enum\OpKind;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * LE POINT DE GREFFE DES NATURES D'OP À VENIR.
 *
 * `OpKind` en compte huit. `champ` est traitée directement par
 * {@see ValidationDeDemande} parce qu'elle seule se **regroupe par ligne visée** — un
 * appel au port par ligne, pas par champ. Les sept autres — `ajout_lien`,
 * `retrait_lien`, `ajout_media`, `retrait_media`, `ajout_citation`, `retrait_citation`,
 * `ajout_note` — passent par ici, découvertes par le tag
 * `app.proposal.applicateur_d_operation` que pose `autoconfigure`.
 *
 * ── Le défaut qu'elle a porté, et qu'il faut connaître ──────────────────────────
 *
 * Pendant un temps, le validateur **résolvait** un applicateur pour chaque op non-`champ`
 * — afin d'échouer bruyamment si aucun n'était branché — puis passait à l'op suivante
 * **sans jamais l'appeler**. Pour un module qui n'aurait implémenté que ce point de
 * greffe, la conséquence était la pire de ce projet : la validation **réussissait**, les
 * ops étaient marquées appliquées, la demande passait `validee`, l'auteur recevait une
 * notification lui annonçant que sa contribution était acceptée — **et rien n'était
 * écrit**. Une perte de données silencieuse, assortie d'une confirmation de succès.
 *
 * Il a survécu parce que rien ne pouvait le voir : le harnais de test construisait les
 * applicateurs à la main plutôt que de lire les services tagués, et ne pouvait donc pas
 * constater qu'un greffon n'était pas appelé. **Un point de greffe qui n'est pas exercé
 * par un test à travers le conteneur n'est pas un point de greffe, c'est une promesse.**
 *
 * ── Ce qu'un applicateur doit savoir ─────────────────────────────────────────────
 *
 * - Il est appelé **dans la transaction** de la validation, après le contrôle préalable
 *   et jamais avant : à ce moment, la décision de rejeu ou de caducité est déjà prise.
 * - Il doit laisser remonter `Doctrine\ORM\OptimisticLockException` — c'est elle qui
 *   devient `verrou_concurrent`, la `409` qui dit « réessayer ».
 * - Il **n'écrit pas dans une table d'un autre module** : il passe par le service de ce
 *   module (règle 5 de l'ADR 0002), comme le fait
 *   {@see Port\PortArbre} pour les champs.
 * - Il rend les révisions qu'il a produites, pour qu'elles apparaissent dans
 *   `revisions` de la réponse (§ 6.5) : « une création en génère plusieurs — la
 *   personne, la famille, les filiations ».
 */
#[AutoconfigureTag('app.proposal.applicateur_d_operation')]
interface ApplicateurDOperation
{
    /**
     * Les natures d'op que cet applicateur sait appliquer.
     *
     * @return list<OpKind>
     */
    public function natures(): array;

    /**
     * Applique les ops de ces natures, et rend **ce qui a ete ecrit**.
     *
     * @param list<OpEnAttente> $ops les ops de ces natures, dans l'ordre de `seq`
     *
     * @return list<EcritureDeRevision>
     */
    public function appliquer(DemandeEnCours $demande, array $ops, Lecteur $lecteur): array;
}
