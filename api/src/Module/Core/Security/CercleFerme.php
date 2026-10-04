<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

use App\Module\Core\Enum\Regime;
use Psr\Log\LoggerInterface;

/**
 * L'implémentation **par défaut** de {@see CercleDeVisibiliteInterface} : elle refuse tout.
 *
 * ## Ce n'est pas un bouchon en attendant `Membership`, c'est une règle permanente
 *
 * Elle ne disparaît pas le jour où `Membership` branche son adaptateur. Elle reste le
 * comportement de référence **quand aucune implémentation n'est branchée**, et c'est à ce
 * titre qu'elle est arbitrée et inscrite ici.
 *
 * Le motif, parce qu'il ne se redevine pas :
 *
 * - un défaut **ouvert** transforme une erreur de câblage — un service non déclaré, un
 *   alias mal écrit, un module désactivé, une refonte de la configuration dans deux ans —
 *   en **fuite de données de personnes vivantes, silencieuse et sans message d'erreur**.
 *   Rien ne tombe, rien ne s'allume, et personne ne l'apprend avant qu'un membre de la
 *   famille s'en plaigne ;
 * - un défaut **fermé** transforme la même erreur de câblage en fiches vides : visible
 *   immédiatement, corrigé dans l'heure, et jamais au détriment de quelqu'un.
 *
 * Les deux pannes ne coûtent pas le même prix. `tests/Core/CercleFermeTest.php` le prouve
 * contre cette classe-ci et non contre un double : c'est ce test qui tiendra quand
 * quelqu'un remaniera la configuration des services.
 *
 * ## Pourquoi le régime rendu est `Aucun`
 *
 * C'est la plus fermée des deux valeurs légales — personnes décédées uniquement. Cette
 * classe est en réalité **plus fermée encore** : elle masque aussi les personnes
 * décédées, faute de pouvoir établir qu'elles le sont. Ce n'est pas une incohérence,
 * c'est la troisième règle du contrat appliquée à la lettre : *« on ne sait pas si elle
 * est morte » n'est pas une autorisation de publier ses dates.* Rendre un troisième
 * régime « panne » aurait ajouté au contrat d'API une valeur que le § 2.2 ne connaît pas,
 * pour décrire un état qui ne doit pas durer.
 *
 * ## L'avertissement
 *
 * Une application qui tourne sur ce défaut doit le dire dans ses journaux, sinon la liste
 * vide passe pour une absence de données. **Une fois par requête** et non à chaque
 * appel : le graphe pose la question pour 274 personnes, et 274 lignes de journal
 * identiques noieraient le message au lieu de le porter.
 */
final class CercleFerme implements CercleDeVisibiliteInterface
{
    private bool $dejaAverti = false;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function regime(): Regime
    {
        $this->avertir();

        return Regime::Aucun;
    }

    public function voitPersonne(int $personId): bool
    {
        $this->avertir();

        return false;
    }

    /**
     * Aucun arbre n'est établi, donc aucun ne peut être retiré : le périmètre ne ferme que
     * ce qu'il **sait** être l'arbre d'un autre compte. Le reste de cette classe ferme
     * déjà tout ; `Tree`, qui sert les défunts sans elle, garde son comportement d'avant.
     */
    public function dansLePerimetre(int $personId): bool
    {
        $this->avertir();

        return true;
    }

    public function filtrerPersonnes(array $personIds): array
    {
        $this->avertir();

        return [];
    }

    private function avertir(): void
    {
        if ($this->dejaAverti) {
            return;
        }
        $this->dejaAverti = true;

        $this->logger->warning(
            'Le cercle de visibilité n\'est pas branché : CercleFerme refuse tout, y compris les personnes décédées. '
            .'Remplacer l\'alias de Core\\Security\\CercleDeVisibiliteInterface dans config/services/membership.yaml.'
        );
    }
}
