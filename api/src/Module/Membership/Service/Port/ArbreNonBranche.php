<?php

declare(strict_types=1);

namespace App\Module\Membership\Service\Port;

use Psr\Log\LoggerInterface;

/**
 * Implémentation de repli de {@see PortArbre}, tant que `Tree` n'a pas posé la sienne.
 *
 * ## Elle ne lève pas — et c'est la différence avec le repli de `Proposal`
 *
 * `Proposal\Service\Port\ArbreNonBranche` lève sur ses trois méthodes, parce qu'une
 * **validation** qui réussirait sans rien écrire serait pire qu'une erreur franche. Ici
 * la question est inverse : ce port sert une **lecture**, et le comportement juste d'une
 * lecture dont le câblage a sauté est de ne rien montrer. Une exception ferait tomber
 * `GET /api/session`, `GET /api/arbre/graphe` et les quatre routes de `Media` en `500` ;
 * un arbre vide les laisse répondre, en régime fermé.
 *
 * C'est la même règle que `Core\Security\CercleFerme`, et il faut la lire de la même
 * façon : **ce n'est pas un bouchon, c'est le comportement de référence quand rien n'est
 * branché.** Un défaut *ouvert* transformerait une erreur de configuration — un alias mal
 * écrit, une refonte des services dans deux ans — en fuite silencieuse de dates de
 * naissance de personnes vivantes. Un défaut *fermé* la transforme en fiches muettes :
 * visible tout de suite, corrigé dans l'heure, et jamais au détriment de quelqu'un.
 *
 * Conséquence directe, et voulue : tant que cet alias est en place, aucune personne
 * vivante n'est visible de personne, la recherche de rattachement ne rend aucun candidat,
 * et une invitation pré-liée est refusée en `422`. **Il ne faut pas l'« arranger »** en
 * rendant un arbre partiel ou un cercle ouvert.
 *
 * ## L'empreinte est reconnaissable, pas vide
 *
 * Une chaîne vide se confondrait avec l'empreinte d'un arbre vide et, le jour où
 * l'adaptateur réel sera branché sur une base non migrée, les deux partageraient leurs
 * entrées de cache. Une valeur littérale, elle, ne peut collisionner avec aucun `sha256`.
 */
final class ArbreNonBranche implements PortArbre
{
    /**
     * Ni un hexadécimal de 64 signes, ni une chaîne vide : aucune empreinte réelle ne
     * peut valoir cela, donc aucune entrée de cache ne peut être partagée par erreur.
     */
    private const string EMPREINTE = 'arbre-non-branche';

    private bool $dejaAverti = false;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function vue(): VueDeLArbre
    {
        $this->avertir();

        return VueDeLArbre::vide();
    }

    public function empreinte(): string
    {
        $this->avertir();

        return self::EMPREINTE;
    }

    /**
     * **Une fois par requête** et non à chaque appel : le graphe pose la question pour
     * 274 personnes, et 274 lignes identiques noieraient le message au lieu de le porter.
     */
    private function avertir(): void
    {
        if ($this->dejaAverti) {
            return;
        }
        $this->dejaAverti = true;

        $this->logger->warning(
            'Le port vers Tree n\'est pas branché : Membership travaille sur un arbre vide, '
            .'donc aucune personne vivante n\'est visible et la recherche de rattachement ne rend rien. '
            .'Remplacer l\'alias de Membership\\Service\\Port\\PortArbre dans config/services/membership.yaml.'
        );
    }
}
