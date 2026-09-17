<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Core\Contract\AncragePropose;
use App\Module\Core\Contract\NoeudPropose;
use App\Module\Core\Contract\ProjectionEnAttente;
use App\Module\Core\Enum\Branch;
use App\Module\Core\Enum\Sex;
use App\Module\Tree\Service\MoteurDePlacement;
use App\Module\Tree\ValueObject\AncrageEnAttente;
use App\Module\Tree\ValueObject\AreteDePlacement;
use App\Module\Tree\ValueObject\IdentifiantDeNoeud;
use App\Module\Tree\ValueObject\PersonnePlacable;
use App\Module\Tree\ValueObject\Placement;

/**
 * La **traduction** entre ce que `Proposal` publie et ce que le moteur de placement sait
 * consommer.
 *
 * `Core\Contract\ProjectionEnAttente` décrit des personnes proposées en termes de fiche,
 * et leurs ancrages en termes d'**intention** — « conjoint de X », « enfant de tel
 * couple ». Le moteur, lui, place des {@see PersonnePlacable} à des ancrages typés. Tout
 * l'écart est ici, et nulle part ailleurs.
 *
 * ## Trois choses que le contrat du noyau ne porte pas, et qui se déduisent
 *
 * **1. `generation`.** Elle fixe `y = génération × 150`, sans aucune liberté verticale :
 * sans elle, le nœud tombe à `y = 0`, c'est-à-dire sur la rangée de l'ego. Elle se lit
 * sur le nœud d'ancrage, déjà placé : même rangée pour un conjoint, une de moins pour un
 * parent, une de plus pour un enfant. C'est exact, et non approché — les générations sont
 * une propriété de la topologie, pas une estimation.
 *
 * **2. `branch`.** Le canevas colore par quart d'ascendance (arbitrage **M**). On reprend
 * celle de l'ancre : un conjoint, un parent ou un enfant proposé appartient au même quart
 * que la personne à laquelle il se rattache. C'est une **approximation assumée**, et elle
 * ne peut pas nuire : `branch` « n'apprend rien » — elle se déduit du chemin vers la
 * racine, c'est-à-dire des arêtes, que tout lecteur reçoit déjà. À la validation, un
 * service de `Tree` recalcule la vraie valeur.
 *
 * **3. Les deux moitiés de la ligne de dates.** Le contrat rend `ligneDates` **composée**
 * (« vers 1889 – 1972 »), alors que {@see PersonnePlacable} porte la naissance et le décès
 * séparément. On la redécoupe par les séparateurs que
 * {@see \App\Module\Tree\Service\MesureDeFiche::ligneDates()} emploie lui-même, de sorte
 * que la recomposition rende **exactement** la chaîne reçue. C'est ce qui garantit que
 * `w` est mesurée sur le texte effectivement envoyé — un nœud en attente dont l'étiquette
 * déborde de sa boîte détonnerait dans sa fratrie.
 *
 * ## Ce qu'elle ne peut pas placer, elle le **nomme**
 *
 * Un nœud dont l'ancre n'est pas dans le placement partagé est **omis**, et il doit
 * l'être : le poser à `x = 0` le mettrait sur l'ego. Mais l'omission était muette, et
 * c'est ce qui a coûté — une branche de vingt-deux personnes déposée en cinq demandes a
 * rendu cinq nœuds placés, dix-sept écartés, et un calque annonçant `actif: true` sur les
 * cinq demandes sans un mot sur l'écart.
 *
 * `preparer()` rend donc une troisième liste, `ecartes`, avec la raison de chaque
 * absence ({@see NoeudProposeEcarte}). **Le comportement ne change pas** : les mêmes
 * nœuds sont placés, les mêmes sont omis, et le placement partagé reste identique au
 * millième. Seul le silence est levé.
 *
 * ## Le losange provisoire garde le numéro de `Proposal`
 *
 * Le projecteur nomme son losange interne `#12/p1#union` ; le contrat expose `#12/f1`,
 * numéroté dans l'ordre des liens de la demande. La correspondance est rendue ici, et
 * appliquée par le normaliseur aux nœuds **et** aux arêtes. C'est `Proposal` qui numérote,
 * parce que c'est lui qui connaît l'ordre des liens.
 */
final readonly class PropositionsProjetees
{
    /**
     * @param array<int, array{displayName: string, branch: string|null}> $branchesDesPersonnes indexé par `tree_person.id`
     *
     * @return array{noeuds: list<NoeudProposePlacable>, renommage: array<string, string>, ecartes: list<NoeudProposeEcarte>}
     */
    public function preparer(ProjectionEnAttente $projection, Placement $partage, array $branchesDesPersonnes): array
    {
        $ancrages = [];
        foreach ($projection->ancrages as $ancrage) {
            // Le premier lien d'une personne proposée est celui qui la pose ; les suivants
            // sont des arêtes que le projecteur ne sait pas placer. `seq` porte l'ordre de
            // la demande, et c'est lui qui départage.
            $ancrages[$ancrage->sujetNoeudId] ??= $ancrage;
        }

        $noeuds = [];
        $renommage = [];
        $ecartes = [];

        foreach ($projection->noeuds as $source) {
            $ancrage = $ancrages[$source->noeudId] ?? null;

            if (null === $ancrage) {
                // Une personne proposée sans lien flotterait hors de l'arbre : le § 6.4 du
                // dossier l'interdit à la saisie. Si le cas survient malgré tout, on omet
                // le nœud plutôt que de le poser à `x = 0`, où il recouvrirait l'ego.
                $ecartes[] = new NoeudProposeEcarte($source, NoeudProposeEcarte::SANS_LIEN);

                continue;
            }

            $prepare = $this->traduire($source, $ancrage, $partage, $branchesDesPersonnes);

            if (\is_string($prepare)) {
                // **L'omission reste la bonne réponse ; le silence ne l'était pas.** La
                // raison remonte jusqu'au calque, qui la publie — voir
                // {@see NoeudProposeEcarte}.
                $ecartes[] = new NoeudProposeEcarte($source, $prepare);

                continue;
            }

            $noeuds[] = $prepare;

            if (null !== $prepare->familleProvisoire) {
                $renommage[$prepare->pourLaProjection()->familleProvisoireId()] = $prepare->familleProvisoire;
            }
        }

        return ['noeuds' => $noeuds, 'renommage' => $renommage, 'ecartes' => $ecartes];
    }

    /**
     * Le nœud traduit, **ou la raison de son absence** — jamais `null` : un `null` est ce
     * qui a laissé dix-sept nœuds disparaître sans que rien ne puisse le dire.
     *
     * @param array<int, array{displayName: string, branch: string|null}> $branches
     */
    private function traduire(
        NoeudPropose $source,
        AncragePropose $ancrage,
        Placement $partage,
        array $branches,
    ): NoeudProposePlacable|string {
        $traduit = $this->ancrage($ancrage, $partage);

        if (null === $traduit) {
            return $this->raisonDAncrage($ancrage, $partage);
        }

        [$ancre, $cibleId] = $traduit;

        $generation = $this->generationDepuisLAncre($ancrage->kind, $cibleId, $partage);

        if (null === $generation) {
            return NoeudProposeEcarte::GENERATION_INDETERMINEE;
        }

        [$nais, $deces] = $this->decouperLaLigneDeDates($source->ligneDates);

        $mesure = new PersonnePlacable(
            id: $source->noeudId,
            // `pre or "?"` dans `graphe.py` : une fiche sans prénom mesure quand même un
            // caractère. Une chaîne vide rétrécirait la fiche par rapport à ses voisines.
            prenom: '' !== $source->givenNames ? $source->givenNames : '?',
            nom: $source->surname,
            // Le contrat ne porte pas de `NICK` : c'est le prénom qui compose l'étiquette,
            // comme pour la grande majorité des fiches validées.
            usage: '',
            sexe: Sex::tryFrom($source->sex) ?? Sex::Inconnu,
            nais: $nais,
            deces: $deces,
            // L'année de naissance n'ordonne que les fratries, et le projecteur ne retrie
            // jamais une fratrie — il pose le nouveau venu à sa droite (ADR 0005).
            anNais: null,
            vivant: $source->vivant(),
            generation: $generation,
        );

        return new NoeudProposePlacable(
            $source,
            $mesure,
            $ancre,
            $this->brancheDeLAncre($cibleId, $partage, $branches),
            $ancrage->familleProvisoire,
        );
    }

    /**
     * Pourquoi {@see ancrage()} n'a rien rendu — lu sur l'ancrage, pas deviné.
     *
     * La distinction qui compte est celle entre `enchainement` et `ancre_absente` : la
     * première est la forme normale d'une branche proposée en bloc et elle en écarte la
     * majeure partie ; la seconde est une cible qui a disparu de l'arbre. Les confondre
     * ferait passer un fonctionnement attendu pour une anomalie, et l'inverse.
     */
    private function raisonDAncrage(AncragePropose $ancrage, Placement $partage): string
    {
        if (null !== $ancrage->familleId) {
            return NoeudProposeEcarte::ANCRE_ABSENTE;
        }

        $autre = $ancrage->autreNoeudId;

        if (null === $autre) {
            return NoeudProposeEcarte::ANCRE_ABSENTE;
        }

        if (IdentifiantDeNoeud::estProvisoire($autre)) {
            return NoeudProposeEcarte::ENCHAINEMENT;
        }

        // L'autre extrémité est une personne validée, et elle est bien dans le graphe :
        // l'ancrage a donc échoué plus loin, et il ne reste qu'un cas — `enfant_de` n'a
        // trouvé aucun losange sous ce parent.
        return isset($partage->noeuds[$autre]) && 'enfant_de' === $ancrage->kind
            ? NoeudProposeEcarte::SANS_COUPLE
            : NoeudProposeEcarte::ANCRE_ABSENTE;
    }

    /**
     * L'ancrage du moteur, et l'identifiant du nœud **déjà placé** dont il dépend.
     *
     * @return array{0: AncrageEnAttente, 1: string}|null
     */
    private function ancrage(AncragePropose $ancrage, Placement $partage): ?array
    {
        if (null !== $ancrage->familleId) {
            // Un couple existant a été désigné : il y a un losange, rien à inventer.
            $famille = IdentifiantDeNoeud::famille($ancrage->familleId);

            return isset($partage->noeuds[$famille]) ? [AncrageEnAttente::enfantDe($famille), $famille] : null;
        }

        $autre = $ancrage->autreNoeudId;

        if (null === $autre || !isset($partage->noeuds[$autre])) {
            return null;
        }

        return match ($ancrage->kind) {
            'conjoint_de' => [AncrageEnAttente::conjointDe($autre), $autre],
            'parent_de' => [AncrageEnAttente::parentDe($autre), $autre],
            // « Enfant de » sans couple désigné : un seul parent est nommé. Le projecteur
            // ne sait ancrer un enfant que sous un losange, on cherche donc la famille de
            // ce parent dans les arêtes du placement — exactes, là où la géométrie ne
            // l'est qu'approximativement.
            'enfant_de' => $this->familleDe($autre, $partage),
            default => null,
        };
    }

    /** @return array{0: AncrageEnAttente, 1: string}|null */
    private function familleDe(string $personneId, Placement $partage): ?array
    {
        foreach ($partage->aretes as $arete) {
            if (AreteDePlacement::GENRE_UNION === $arete->genre && $arete->de === $personneId) {
                return [AncrageEnAttente::enfantDe($arete->vers), $arete->vers];
            }
        }

        return null;
    }

    /**
     * La génération, lue sur le nœud d'ancrage déjà placé.
     *
     * `y = génération × 150` pour une fiche ; un losange vit 46 px plus bas que la rangée
     * de ses époux — `H_FICHE / 2 + 26`. On retire ce décalage avant de diviser, sans quoi
     * un enfant proposé tomberait un tiers de rangée trop bas.
     */
    private function generationDepuisLAncre(string $kind, string $cibleId, Placement $partage): ?int
    {
        $cible = $partage->noeuds[$cibleId] ?? null;

        if (null === $cible) {
            return null;
        }

        if (MoteurDePlacement::H_FICHE !== $cible->h) {
            // Un losange : la rangée des époux est au-dessus, et l'enfant une en dessous.
            $rangeeDesEpoux = ($cible->y - MoteurDePlacement::H_FICHE / 2 - MoteurDePlacement::DESCENTE_NOEUD_FAMILLE) / MoteurDePlacement::H_RANG;

            return (int) round($rangeeDesEpoux) + 1;
        }

        $rangee = (int) round($cible->y / MoteurDePlacement::H_RANG);

        return match ($kind) {
            // Les générations sont négatives en remontant : un parent est **une de moins**.
            'parent_de' => $rangee - 1,
            'enfant_de' => $rangee + 1,
            default => $rangee,
        };
    }

    /**
     * @param array<int, array{displayName: string, branch: string|null}> $branches
     */
    private function brancheDeLAncre(string $cibleId, Placement $partage, array $branches): ?Branch
    {
        if (IdentifiantDeNoeud::estFamille($cibleId)) {
            // Pour une famille, on prend la branche du premier époux placé : les deux
            // époux d'un couple portent le même quart d'ascendance, sauf à la jonction des
            // branches — où la couleur est de toute façon conventionnelle.
            foreach ($partage->aretes as $arete) {
                if (AreteDePlacement::GENRE_UNION === $arete->genre && $arete->vers === $cibleId) {
                    $cibleId = $arete->de;
                    break;
                }
            }
        }

        $id = IdentifiantDeNoeud::estPersonne($cibleId) ? IdentifiantDeNoeud::identifiantBase($cibleId) : null;

        if (null === $id) {
            return null;
        }

        $valeur = $branches[$id]['branch'] ?? null;

        return \is_string($valeur) ? Branch::tryFrom($valeur) : null;
    }

    /**
     * L'inverse exact de {@see \App\Module\Tree\Service\MesureDeFiche::ligneDates()}.
     *
     * Les quatre formes qu'elle produit : `naissance – décès`, `n. naissance`,
     * `† décès`, et `—` quand rien n'est connu. On les redécoupe pour que la
     * recomposition rende **la même chaîne**, et donc que `w` soit mesurée sur le texte
     * effectivement envoyé.
     *
     * @return array{0: string, 1: string}
     */
    private function decouperLaLigneDeDates(string $ligne): array
    {
        $ligne = trim($ligne);

        if ('' === $ligne || '—' === $ligne) {
            return ['', ''];
        }

        if (str_contains($ligne, ' – ')) {
            [$nais, $deces] = explode(' – ', $ligne, 2);

            return [trim($nais), trim($deces)];
        }

        if (str_starts_with($ligne, 'n. ')) {
            return [trim(substr($ligne, 3)), ''];
        }

        if (str_starts_with($ligne, '† ')) {
            return ['', trim(substr($ligne, \strlen('† ')))];
        }

        // Forme inattendue : on la prend pour une naissance seule plutôt que de la perdre.
        // La recomposition ajoutera « n. », donc la largeur sera mesurée sur trois
        // caractères de trop — visible, et préférable à une étiquette muette.
        return [$ligne, ''];
    }
}
