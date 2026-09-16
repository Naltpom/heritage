<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

use App\Module\Core\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * La **couture** par laquelle un autre module complète la charge de session du § 2.2 du
 * contrat d'API.
 *
 * `Core` sait dire qui est connecté ; il ne sait pas — et ne doit pas savoir — à quelle
 * personne de l'arbre ce compte est rattaché, ni quel cercle de visibilité en découle.
 * C'est `Membership` qui le sait (ADR 0002 : aucune relation Doctrine inter-modules,
 * aucune dépendance de `Core` vers un autre module). Plutôt qu'un `if` dans `Core`, on
 * publie cette interface : `Membership` l'implémente, le conteneur la branche, et `Core`
 * reste ignorant.
 *
 * Contrat d'implémentation, en trois points :
 *
 * 1. On rend **les seules clés de premier niveau qu'on renseigne** — typiquement
 *    `membre`, `cercle`, `demandesEnAttente`, `voirComme`. Une clé rendue **remplace**
 *    celle du socle ; une clé absente laisse le défaut en place.
 * 2. Le socle pose `cercle = {"regime": "aucun"}` **pour tout le monde**, y compris un
 *    compte actif. C'est le défaut le plus fermé : si `Membership` n'est pas branché, ou
 *    tombe, personne n'obtient de cercle — on dégrade vers moins de visibilité, jamais
 *    vers plus.
 * 3. Quoi qu'un contributeur rende, {@see \App\Module\Core\Service\SessionPayloadBuilder}
 *    **referme** la charge d'un compte `en_attente` : régime `aucun`, ni `personId` ni
 *    `personLabel`. Un contributeur ne peut donc pas ouvrir par mégarde la huitième porte
 *    de sortie (ADR 0003) sur ce régime.
 */
#[AutoconfigureTag('core.contributeur_session')]
interface SessionPayloadContributorInterface
{
    /**
     * @return array<string, mixed> Les clés de premier niveau que ce module renseigne
     */
    public function contribuer(User $utilisateur): array;
}
