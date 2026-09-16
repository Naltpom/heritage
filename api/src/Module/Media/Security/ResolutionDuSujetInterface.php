<?php

declare(strict_types=1);

namespace App\Module\Media\Security;

use App\Module\Media\Enum\MediaSubject;

/**
 * **La couture entre `Media` et l'arbre. À brancher par l'agent `Tree`.**.
 *
 * Elle ne pose **aucune question de confidentialité** — c'est important, et c'est ce qui
 * la distingue de {@see VisibiliteDesSujetsInterface}. Elle répond à trois choses que
 * seul le propriétaire des lignes sait :
 *
 * 1. cette ligne existe-t-elle ?
 * 2. sous quel libellé (à dénormaliser dans `med_media.subject_label`) ?
 * 3. **de quelles personnes sa visibilité dépend-elle ?**
 *
 * La troisième est la seule qui demande un jugement, et il appartient à `Tree` :
 *
 * - `personne` ⇒ elle-même ;
 * - `famille` ⇒ **les deux époux**, parce qu'une preuve d'union en dit autant sur l'un
 *   que sur l'autre ;
 * - `citation` ⇒ la personne ou les époux de la famille qu'elle étaye.
 *
 * La réponse ne dépend **pas du lecteur** : c'est une propriété de la donnée, pas de qui
 * la regarde. C'est ce qui permet de la mettre en cache si le besoin s'en fait sentir, et
 * c'est la même raison qui fait que la largeur d'un nœud ne dépend pas du lecteur
 * (§ 11.2 du contrat d'API).
 *
 * ## Ce qu'il faut brancher
 *
 * Une classe de `Tree` implémente cette interface, et l'alias de
 * `config/services/media.yaml` bascule dessus. Tant qu'elle n'est pas branchée,
 * {@see SujetNonResolu} rend `null` pour tout : aucun média ne se dépose, aucun ne se
 * sert. Fermé, donc visible tout de suite — jamais permissif en silence.
 *
 * ## Résolution au point d'entrée
 *
 * C'est aussi la mesure 1 de la compensation de l'ADR 0002 : `Media` ne stocke un
 * `subject_id` qu'après que `Tree` le lui a confirmé et lui a rendu le libellé. Sans
 * clé étrangère inter-module, c'est la seule chose qui empêche une référence pendante
 * d'entrer en base.
 */
interface ResolutionDuSujetInterface
{
    /**
     * Rend `null` si la ligne n'existe pas. Ne juge jamais du droit d'en voir le contenu :
     * c'est {@see VisibiliteDesSujetsInterface} qui tranche, et lui seul.
     */
    public function resoudre(MediaSubject $kind, int $id): ?SujetResolu;
}
