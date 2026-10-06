<?php

declare(strict_types=1);

namespace App\Integrite;

use App\Module\Core\Contract\CiblesDeReferenceInterface;
use App\Module\Core\Contract\ReferencesInterModulesInterface;
use App\Module\Core\Enum\CibleDeReference;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * LE FILET QUE LA BASE NE TEND PLUS — la quatrième mesure de l'ADR 0002.
 *
 * Les références inter-modules n'ont pas de clé étrangère, par décision (règle 2). Ce
 * service les parcourt toutes et signale les pendantes. Il est le cœur de la commande
 * `heritage:verifier-integrite` ({@see VerifierIntegriteCommand}), et il est testé sans elle.
 *
 * ## Pourquoi il vit ici, au-dessus des modules
 *
 * L'ADR le place à la racine de `api/src/`, à côté des fixtures : « la seule composition
 * autorisée ». Il rapproche ce que neuf modules déclarent, et c'est son seul travail — il
 * ne lit **aucune table** lui-même :
 *
 * 1. chaque module déclare les références qu'il porte, relevées dans ses propres tables
 *    ({@see ReferencesInterModulesInterface}) ;
 * 2. chaque propriétaire d'une cible dit lesquels des identifiants existent
 *    ({@see CiblesDeReferenceInterface}) ;
 * 3. ce qui a été désigné et n'existe pas est pendant.
 *
 * Aucune jointure ne franchit donc une frontière, et la commande tourne à l'identique sur
 * SQLite et sur PostgreSQL : chaque module n'émet qu'un `GROUP BY` et un `IN`.
 *
 * ## Une question par cible, pas par référence
 *
 * `tree_person` est désignée par une vingtaine de colonnes. Les identifiants de toutes ces
 * colonnes sont réunis, et le propriétaire n'est interrogé qu'une fois par cible.
 *
 * ## Ce qui empêche de conclure
 *
 * Une référence vers une cible sans propriétaire déclaré, ou une cible revendiquée par deux
 * modules, est une **anomalie** : le contrôle n'a pas pu se faire, et il le dit au lieu de
 * passer pour réussi. De même si aucune référence n'est déclarée — un balayage vide est le
 * vert le plus trompeur qui soit.
 */
final readonly class VerificationDIntegrite
{
    /**
     * @param iterable<ReferencesInterModulesInterface> $declarants
     * @param iterable<CiblesDeReferenceInterface>      $proprietaires
     */
    public function __construct(
        #[AutowireIterator('core.references_inter_modules')]
        private iterable $declarants,
        #[AutowireIterator('core.cibles_de_reference')]
        private iterable $proprietaires,
    ) {
    }

    public function verifier(): RapportDIntegrite
    {
        $anomalies = [];

        /** @var array<string, CiblesDeReferenceInterface> $parCible */
        $parCible = [];

        foreach ($this->proprietaires as $proprietaire) {
            foreach ($proprietaire->cibles() as $cible) {
                if (isset($parCible[$cible->value])) {
                    $anomalies[] = \sprintf('La cible %s est revendiquée par deux modules : %s et %s.', $cible->value, $this->module($parCible[$cible->value]), $this->module($proprietaire));

                    continue;
                }

                $parCible[$cible->value] = $proprietaire;
            }
        }

        /** @var list<array{module: string, reference: \App\Module\Core\Contract\ReferenceInterModules}> $relevees */
        $relevees = [];
        /** @var array<string, array<int, true>> $designes */
        $designes = [];

        foreach ($this->declarants as $declarant) {
            foreach ($declarant->references() as $reference) {
                $relevees[] = ['module' => $this->module($declarant), 'reference' => $reference];

                foreach (array_keys($reference->occurrences) as $id) {
                    $designes[$reference->cible->value][$id] = true;
                }
            }
        }

        if ([] === $relevees) {
            $anomalies[] = 'Aucune référence inter-modules n\'a été déclarée : le contrôle ne vérifierait rien.';
        }

        /** @var array<string, array<int, true>> $existants */
        $existants = [];

        foreach ($designes as $cible => $identifiants) {
            $proprietaire = $parCible[$cible] ?? null;

            if (null === $proprietaire) {
                $anomalies[] = \sprintf('Des références visent %s, mais aucun module ne s\'en déclare propriétaire : impossible de dire si elles pendent.', $cible);

                continue;
            }

            $existants[$cible] = array_fill_keys(
                $proprietaire->existants(CibleDeReference::from($cible), array_keys($identifiants)),
                true,
            );
        }

        $controles = [];

        foreach ($relevees as ['module' => $module, 'reference' => $reference]) {
            $connus = $existants[$reference->cible->value] ?? null;

            // Sans propriétaire, l'anomalie est déjà levée : on ne déclare rien pendant
            // plutôt que tout, pour qu'un seul défaut de câblage ne noie pas le rapport.
            $pendants = null === $connus
                ? []
                : array_diff_key($reference->occurrences, $connus);

            $controles[] = new ControleDIntegrite($module, $reference, $pendants);
        }

        return new RapportDIntegrite($controles, array_values(array_unique($anomalies)));
    }

    /** Le module d'un déclarant, lu dans son espace de noms — le rapport le cite. */
    private function module(object $declarant): string
    {
        return 1 === preg_match('/^App\\\\Module\\\\(\w+)\\\\/', $declarant::class, $morceaux) ? $morceaux[1] : $declarant::class;
    }
}
