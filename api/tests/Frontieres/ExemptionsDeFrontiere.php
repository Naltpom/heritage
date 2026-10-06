<?php

declare(strict_types=1);

namespace App\Tests\Frontieres;

/**
 * La liste NOMMÉE des franchissements de frontière tolérés aujourd'hui.
 *
 * ## Pourquoi une liste et non un seuil
 *
 * Le premier réflexe était de figer le compte : « pas plus de 46 références
 * illicites ». Deux mesures l'ont écarté (ADR 0002, § « Le compte doit être
 * reproductible avant d'être une porte » ; docs/arbitrages.md, point **HH**) :
 *
 *  1. **le code bouge pendant qu'on le compte** — rejoué à une heure
 *     d'intervalle, le recensement est passé de 54 à 55. Un seuil figé sur un
 *     chiffre pareil est faux le lendemain, et son auteur passe son temps à le
 *     relever d'un cran ;
 *  2. **un seuil est compensable** — une violation ajoutée ici, une retirée là,
 *     le total ne bouge pas, le test reste vert. Il est alors exactement aveugle
 *     à ce qu'il devait empêcher : l'apparition d'une frontière franchie.
 *
 * D'où la forme retenue : **le contrôle échoue sur la règle, jamais sur un
 * compte.** Toute référence qui ne vise ni `Core\…` ni `<Autre>\Contract\…`
 * fait échouer le test, sauf si elle figure ci-dessous, **fichier par fichier et
 * cible par cible**.
 *
 * ## Pourquoi cible par cible, et non `Source -> Module\Entity\*`
 *
 * Une exemption par motif générique se rouvre toute seule. Un motif
 * `Media\… -> Proposal\Entity\*` laisserait entrer demain une troisième entité
 * sans que personne ne le voie ; les lignes explicites, elles, ne couvrent que
 * les cibles constatées. Chaque bloc énumère donc ses cibles.
 *
 * ## Comment on solde
 *
 * On **retire des lignes**. Une exemption qui ne correspond plus à aucune
 * référence réelle fait échouer
 * `testAucuneExemptionNEstDevenueInutile` : on ne peut donc pas déplacer un
 * fichier sans nettoyer la liste, ni laisser traîner une ligne périmée qui
 * couvrirait plus tard une vraie régression. Et l'on ne peut pas remettre une
 * ligne retirée sans que cela se lise en revue.
 *
 * ## Pourquoi `::class` et non des chaînes
 *
 * Une liste de chaînes de caractères ne prévient jamais qu'elle a vieilli. Ici
 * chaque extrémité est écrite `\App\Module\…::class` : PHPStan (niveau 6,
 * porte bloquante de la CI) signale `class.notFound` dès qu'une des classes
 * citées est renommée, déplacée ou supprimée. La liste ne peut pas survivre au
 * contrat qu'elle décrit.
 *
 * ## La trajectoire, ventilée par motif
 *
 * Compte au 2026-10-06 : **24 exemptions**, réparties ainsi — et la somme est le
 * seul contrôle qui vaille, parce qu'un total sans ventilation est compensable :
 *
 * | Motif | Lignes | Ce qu'il reste après l'avoir soldé |
 * |---|---:|---:|
 * | vocabulaire à monter dans `Core` | 6 | — |
 * | port à descendre dans `Contract\` | 10 | **8** (les deux gestes mécaniques, ensemble) |
 * | surface publique à déclarer | 8 | **0** |
 * | **total** | **24** | |
 *
 * Soit **24 → 8 → 0**. Le compte du 2026-09-16 était de 46 (« 46 → 18 → 8 → 0 ») :
 * 22 lignes partaient du module `Gedcom` — la dette datée de l'import (10 lignes,
 * `EcrivainTree` et `AnalyseurGedcom` vers l'interne de `Tree`) et 12 lignes de
 * vocabulaire. Le module a été supprimé avec l'import (ADR 0018) : la dette n'a pas
 * été soldée par un port, elle a disparu avec son code. `FrontieresInterModulesTest`
 * vérifie ces nombres à chaque exécution, de sorte qu'ils ne puissent plus vieillir
 * en silence.
 */
final class ExemptionsDeFrontiere
{
    /**
     * Vocabulaire partagé par deux modules : il monte dans `Core\Enum` ou
     * `Core\ValueObject`. Précédent : arbitrage E — `UncertainDate`,
     * `DateQualifier`, `Sex`, `Branch` ont fait le même chemin. Pour un
     * vocabulaire, le seuil est **deux** modules, pas trois : un vocabulaire
     * n'appartient à personne.
     *
     * Geste mécanique : déplacer l'énumération, corriger les `use`. Il appartient
     * au module qui DÉCLARE (ici `Proposal`, `Media`), pas à celui qui cite.
     */
    public const string VOCABULAIRE_A_MONTER_DANS_CORE = 'vocabulaire à monter dans Core (ADR 0002 règle 3 ; arbitrage E)';

    /**
     * Interface ou objet de transfert déjà propre au sens de l'arbitrage — aucune
     * entité, aucun dépôt — mais rangé dans `Service\Port\` au lieu de
     * `Contract\`. Déplacement mécanique de dossier et de namespace, à faire
     * **dans le module qui déclare le port**.
     */
    public const string PORT_A_DESCENDRE_DANS_CONTRACT = 'port à déplacer de Service\\Port\\ vers Contract\\ (ADR 0002, surface publique)';

    /**
     * Aucun contrat n'existe encore pour ce besoin : le régulariser demande d'en
     * DESSINER un, pas de déplacer un fichier. C'est le travail du module qui
     * déclare, et il passe par une relecture — d'où la distinction d'avec les
     * deux motifs mécaniques ci-dessus.
     */
    public const string SURFACE_PUBLIQUE_A_DECLARER = 'surface publique à déclarer par le module cible (ADR 0002, § La surface publique d\'un module)';

    /**
     * @return list<array{source: class-string, cible: class-string, motif: string}>
     */
    public static function toutes(): array
    {
        return array_merge(
            // =================================================================
            //  VOCABULAIRE À MONTER DANS Core — 6 lignes.
            //  Le plus mécanique : 6 énumérations citées par un second module.
            // =================================================================
            self::bloc(
                \App\Module\Media\Service\Proposition\ApplicateurDOperationMedia::class,
                self::VOCABULAIRE_A_MONTER_DANS_CORE,
                [
                    \App\Module\Proposal\Enum\OpKind::class,
                ],
            ),
            self::bloc(
                \App\Module\Media\Service\Proposition\ConstructeurDAjoutDeMedia::class,
                self::VOCABULAIRE_A_MONTER_DANS_CORE,
                [
                    \App\Module\Proposal\Enum\OpKind::class,
                    \App\Module\Proposal\Enum\RequestKind::class,
                    \App\Module\Proposal\Enum\ValueKind::class,
                ],
            ),
            self::bloc(
                \App\Module\Media\Service\Proposition\ValidationDesMediasDeDemande::class,
                self::VOCABULAIRE_A_MONTER_DANS_CORE,
                [
                    \App\Module\Proposal\Enum\RevisionOperation::class,
                ],
            ),
            self::bloc(
                \App\Module\Tree\Service\ResolutionDesSujetsMedia::class,
                self::VOCABULAIRE_A_MONTER_DANS_CORE,
                [
                    \App\Module\Media\Enum\MediaSubject::class,
                ],
            ),

            // =================================================================
            //  PORTS RANGÉS AU MAUVAIS ENDROIT — 10 lignes.
            //  Rien à concevoir : les fichiers sont déjà des interfaces et des
            //  objets de transfert sans entité ni dépôt. Ils sont dans
            //  `Service\Port\` au lieu de `Contract\`. Le déplacement se fait
            //  chez le module qui DÉCLARE (Proposal, Membership), pas chez Tree
            //  ni chez Media qui n'y changent que des lignes `use`.
            // =================================================================
            self::bloc(
                \App\Module\Tree\Service\EcritureDeLArbrePourProposal::class,
                self::PORT_A_DESCENDRE_DANS_CONTRACT,
                [
                    \App\Module\Proposal\Service\Port\EcritureAppliquee::class,
                    \App\Module\Proposal\Service\Port\EtatCible::class,
                    \App\Module\Proposal\Service\Port\LigneCreee::class,
                    \App\Module\Proposal\Service\Port\PersonneAcreer::class,
                    \App\Module\Proposal\Service\Port\PortArbre::class,
                ],
            ),
            self::bloc(
                \App\Module\Media\Service\Proposition\MediasDeBrouillon::class,
                self::PORT_A_DESCENDRE_DANS_CONTRACT,
                [
                    \App\Module\Proposal\Service\Port\PortMedias::class,
                ],
            ),
            self::bloc(
                \App\Module\Tree\Service\VueDeLArbrePourMembership::class,
                self::PORT_A_DESCENDRE_DANS_CONTRACT,
                [
                    \App\Module\Membership\Service\Port\FamilleDeLArbre::class,
                    \App\Module\Membership\Service\Port\PersonneDeLArbre::class,
                    \App\Module\Membership\Service\Port\PortArbre::class,
                    \App\Module\Membership\Service\Port\VueDeLArbre::class,
                ],
            ),

            // =================================================================
            //  SURFACE PUBLIQUE À DESSINER — 8 lignes.
            //  Ici aucun contrat n'existe : le régulariser demande une décision,
            //  pas un déplacement.
            // =================================================================
            self::bloc(
                \App\Module\Media\Service\Arbre\PortDesMediasDeLArbre::class,
                self::SURFACE_PUBLIQUE_A_DECLARER,
                [
                    \App\Module\Tree\Serializer\LecteurDeLArbre::class,
                    \App\Module\Tree\Serializer\PortDesMedias::class,
                ],
            ),
            self::bloc(
                \App\Module\Media\Service\Proposition\ConstructeurDAjoutDeMedia::class,
                self::SURFACE_PUBLIQUE_A_DECLARER,
                [
                    \App\Module\Proposal\Entity\ChangeRequest::class,
                    \App\Module\Proposal\Entity\ChangeRequestOp::class,
                    \App\Module\Proposal\Exception\DonneesInvalides::class,
                    \App\Module\Proposal\Service\ConstructeurDeDemande::class,
                ],
            ),
            self::bloc(
                \App\Module\Tree\Service\ResolutionDesSujetsMedia::class,
                self::SURFACE_PUBLIQUE_A_DECLARER,
                [
                    \App\Module\Media\Security\ResolutionDuSujetInterface::class,
                    \App\Module\Media\Security\SujetResolu::class,
                ],
            ),
        );
    }

    /**
     * Le seul franchissement toléré depuis un dossier `Contract/` vers une
     * entité : le contrat de session du noyau, qui reçoit l'utilisateur
     * authentifié. `Core\Entity\User` est le vocabulaire du noyau lui-même, et
     * `Core` est en liste blanche pour tous les modules ; aucune frontière de
     * module n'est franchie ici. Écrit à part, pour que la règle générale reste
     * « un contrat ne transporte ni entité ni dépôt », sans nuance dans son
     * énoncé.
     *
     * @return list<array{fichier: string, cible: class-string}>
     */
    public static function contratsAutorisesATransporterUneEntite(): array
    {
        return [
            [
                'fichier' => 'Core/Contract/SessionPayloadContributorInterface.php',
                'cible' => \App\Module\Core\Entity\User::class,
            ],
            // Même motif, même entité du noyau : `rattacherAFicheCreee()` reçoit
            // l'administrateur qui décide, pour l'activation et son journal (ADR 0013).
            // Aucune entité de `Membership` ne traverse le contrat — la déclaration sort
            // par l'objet valeur `DeclarationDuCompte`.
            [
                'fichier' => 'Membership/Contract/ComptesARattacher.php',
                'cible' => \App\Module\Core\Entity\User::class,
            ],
        ];
    }

    /**
     * Les chaînes de caractères qui nomment un module et qu'on tolère.
     *
     * Une seule aujourd'hui, et elle mérite d'être lue : le message d'erreur du
     * repli `ArbreNonBranche` recopie le FQCN de l'alias à reposer, pour que
     * l'exploitant ait la commande sous les yeux. Ce n'est pas une dépendance —
     * rien n'est instancié depuis cette chaîne — mais c'est bien **un nom
     * recopié qui ne préviendra pas qu'il a vieilli** : le jour où
     * `Proposal\Service\Port\PortArbre` descend dans `Proposal\Contract\`, ce
     * message enverra chercher un alias qui n'existe plus.
     *
     * La bonne forme est `PortArbre::class` interpolé dans le message. Elle
     * demande de toucher `src/`, ce qui relève du module Proposal — d'où la
     * tolérance nommée plutôt qu'un silence. Elle se retire en même temps que le
     * déplacement du port.
     *
     * @return list<array{fichier: string, extrait: string, motif: string}>
     */
    public static function chainesTolerees(): array
    {
        return [
            [
                'fichier' => 'Proposal/Service/Port/ArbreNonBranche.php',
                'extrait' => 'bin/console debug:container',
                'motif' => 'message de diagnostic, pas une dépendance — à remplacer par PortArbre::class '
                    .'interpolé au moment où le port descend dans Proposal\\Contract\\',
            ],
        ];
    }

    /**
     * @param class-string       $source
     * @param list<class-string> $cibles
     *
     * @return list<array{source: class-string, cible: class-string, motif: string}>
     */
    private static function bloc(string $source, string $motif, array $cibles): array
    {
        $lignes = [];

        foreach ($cibles as $cible) {
            $lignes[] = ['source' => $source, 'cible' => $cible, 'motif' => $motif];
        }

        return $lignes;
    }
}
