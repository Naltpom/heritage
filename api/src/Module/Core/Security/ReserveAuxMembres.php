<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

/**
 * Une route — ou un contrôleur entier — **réservée aux membres validés** : tout ce qui
 * lit l'arbre (ADR 0003, révision du 01/10/2026).
 *
 * ## Pourquoi un attribut à soi plutôt que `#[IsGranted(RegimeVoter::MEMBRE)]`
 *
 * La décision est **la même** — c'est {@see RegimeVoter::MEMBRE} qui tranche, et rien
 * d'autre. Ce qui change est la **réponse** : `IsGranted` lève un refus anonyme, que
 * `ApiExceptionSubscriber` traduit en `403 acces_refuse`. Or le front a besoin de savoir
 * *pourquoi* on lui refuse l'arbre : « vous n'avez pas le droit » et « votre inscription
 * est en cours de vérification » ne mènent pas au même écran. Le contrat a déjà le code
 * de ce second cas — `403 regime_en_attente` (§ 12.2) — et c'est celui qu'on rend, dans
 * l'enveloppe unique du § 15.1. Le détail est sur
 * {@see \App\Module\Core\EventSubscriber\ReserveAuxMembresSubscriber}.
 *
 * Usage :
 *
 *     #[ReserveAuxMembres]
 *     final class ArbreController extends AbstractController
 *
 * ## Pourquoi un compte en attente ne lit plus rien
 *
 * Jusqu'au 30/09/2026, il lisait « l'arbre ancien » : les défunts en entier, et chaque
 * vivant en **nœud restreint** — nom complet, sexe, génération, branche. Le lien
 * d'invitation circule dans un groupe de messagerie : n'importe qui pouvait s'inscrire
 * sous un faux nom et lire la liste des vivants de la famille. Un compte en attente ne
 * voit donc **rien** de l'arbre, ni vivants ni défunts, jusqu'à sa validation.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class ReserveAuxMembres
{
}
