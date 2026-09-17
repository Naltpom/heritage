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
        $rattachements = $this->rattachements($projection);

        $noeuds = [];
        $renommage = [];
        $ecartes = [];

        /**
         * Ce qui a déjà trouvé sa place, et ce qu'on en sait.
         *
         * C'est le carnet des vagues : une fiche posée devient une ancre valable pour la
         * suivante, avec sa rangée et sa branche. Sans lui, une branche proposée en bloc
         * ne montrait que sa racine.
         *
         * @var array<string, array{generation: int, branche: Branch|null}> $poses
         */
        $poses = [];

        /** @var list<array{0: NoeudPropose, 1: non-empty-list<array{kind: string, cible: string|null, familleId: int|null, familleProvisoire: string|null}>}> $restants */
        $restants = [];

        foreach ($projection->noeuds as $source) {
            $candidats = $rattachements[$source->noeudId] ?? [];

            if ([] === $candidats) {
                // Une personne proposée sans lien flotterait hors de l'arbre : le § 6.4 du
                // dossier l'interdit à la saisie. Si le cas survient malgré tout, on omet
                // le nœud plutôt que de le poser à `x = 0`, où il recouvrirait l'ego.
                $ecartes[] = new NoeudProposeEcarte($source, NoeudProposeEcarte::SANS_LIEN);

                continue;
            }

            $restants[] = [$source, $candidats];
        }

        // ── Les vagues ──────────────────────────────────────────────────────────────
        // Une passe pose tout ce qui s'ancre à du déjà placé ; la suivante reprend le
        // reste, qui peut maintenant s'ancrer à ce que la première a posé. On s'arrête
        // dès qu'une passe ne pose rien : ce qui reste alors ne se posera jamais — ancre
        // disparue, ou cycle — et part en écarté avec la raison de sa dernière tentative.
        //
        // L'ordre de sortie est celui des vagues, et ce n'est pas cosmétique : le
        // projecteur pose dans l'ordre reçu, donc une ancre doit précéder ce qui pend à
        // elle. Le rendre ici, c'est éviter que deux classes aient à s'accorder dessus.
        while ([] !== $restants) {
            $suivants = [];
            $raisons = [];
            $aPose = false;

            foreach ($restants as [$source, $candidats]) {
                $prepare = $this->traduirePremierPossible($source, $candidats, $partage, $poses, $branchesDesPersonnes);

                if (\is_string($prepare)) {
                    $suivants[] = [$source, $candidats];
                    $raisons[$source->noeudId] = $prepare;

                    continue;
                }

                $noeuds[] = $prepare;
                $poses[$source->noeudId] = [
                    'generation' => $prepare->mesure->generation,
                    'branche' => $prepare->branche,
                ];
                $aPose = true;

                if (null !== $prepare->familleProvisoire) {
                    $renommage[$prepare->pourLaProjection()->familleProvisoireId()] = $prepare->familleProvisoire;
                }
            }

            if (!$aPose) {
                // **L'omission reste la bonne réponse ; le silence ne l'était pas.** La
                // raison remonte jusqu'au calque, qui la publie — voir
                // {@see NoeudProposeEcarte}.
                foreach ($suivants as [$source]) {
                    $ecartes[] = new NoeudProposeEcarte($source, $raisons[$source->noeudId] ?? NoeudProposeEcarte::ANCRE_ABSENTE);
                }

                break;
            }

            $restants = $suivants;
        }

        return ['noeuds' => $noeuds, 'renommage' => $renommage, 'ecartes' => $ecartes];
    }

    /**
     * Par quoi chaque fiche proposée **peut** se rattacher — tous les liens, lus dans
     * les deux sens.
     *
     * ── Le défaut que le sens inverse répare ────────────────────────────────
     *
     * Un lien a un sujet et une autre extrémité, et l'écran qui les compose met en sujet
     * **la fiche qu'on est en train de compléter**, pas celle qu'on ajoute. « Ajouter le
     * père de Jean » s'envoie `enfant_de(sujet: Jean, autre: le père proposé)` ; de même
     * pour un conjoint et pour un enfant hors couple. La fiche proposée n'est alors le
     * sujet d'aucun lien — et l'indexation par le seul sujet la déclarait `sans_lien`,
     * c'est-à-dire « flotte hors de l'arbre », alors qu'elle était parfaitement rattachée.
     * Mesuré sur la file réelle : treize nœuds sur trente et un.
     *
     * L'inversion est celle des rôles, pas des identifiants : `enfant_de` vu de l'autre
     * bout est `parent_de`, et réciproquement ; `conjoint_de` est sa propre réciproque.
     * `autre2` — le second parent d'une filiation — s'inverse de la même façon.
     *
     * ── Pourquoi **tous** les candidats, et non le premier ────────────────────
     *
     * N'en garder qu'un par fiche était la simplification d'avant les vagues, et elle
     * décidait trop tôt. Mesuré en production, sur la forme la plus courante de la file —
     * « j'ajoute les deux parents de quelqu'un » :
     *
     * ```
     * seq 1 : conjoint_de(sujet: #p2, autre: #p1)          les deux proposés
     * seq 2 : enfant_de(sujet: p307, autre: #p1, autre2: #p2)
     * ```
     *
     * `#p1` a deux rattachements possibles : « conjoint de `#p2` », qui n'est nulle part,
     * et « parent de `p307` », qui existe. Le premier seul, c'est une impasse — et les
     * deux fiches s'attendaient l'une l'autre indéfiniment. Huit demandes sur neuf étaient
     * dans ce cas.
     *
     * On rend donc la liste, et {@see preparer()} essaie dans l'ordre à chaque vague. Le
     * sens direct vient d'abord — un lien dont la fiche est le sujet dit ce qu'elle est,
     * un lien où elle est l'autre bout ne le dit que par déduction —, puis l'inverse, et
     * à sens égal l'ordre de `seq`, c'est-à-dire celui de la demande.
     *
     * Une filiation qui désigne un **couple existant** ne s'inverse pas : elle n'a pas
     * d'autre extrémité, `familleId` tient lieu des deux parents.
     *
     * @return array<string, non-empty-list<array{kind: string, cible: string|null, familleId: int|null, familleProvisoire: string|null}>>
     */
    private function rattachements(ProjectionEnAttente $projection): array
    {
        /** @var array<string, list<array{kind: string, cible: string|null, familleId: int|null, familleProvisoire: string|null}>> $direct */
        $direct = [];
        /** @var array<string, list<array{kind: string, cible: string|null, familleId: int|null, familleProvisoire: string|null}>> $inverse */
        $inverse = [];

        foreach ($projection->ancrages as $ancrage) {
            $direct[$ancrage->sujetNoeudId][] = [
                'kind' => $ancrage->kind,
                'cible' => $ancrage->autreNoeudId,
                'familleId' => $ancrage->familleId,
                'familleProvisoire' => $ancrage->familleProvisoire,
            ];

            $reciproque = match ($ancrage->kind) {
                'enfant_de' => 'parent_de',
                'parent_de' => 'enfant_de',
                'conjoint_de' => 'conjoint_de',
                default => null,
            };

            if (null === $reciproque) {
                continue;
            }

            foreach ([$ancrage->autreNoeudId, $ancrage->autre2NoeudId] as $bout) {
                if (null === $bout) {
                    continue;
                }

                $inverse[$bout][] = [
                    'kind' => $reciproque,
                    'cible' => $ancrage->sujetNoeudId,
                    // Le couple existant, s'il y en avait un, portait la filiation du
                    // sujet. Vu de ce bout-ci on est **parent**, et la famille se retrouve
                    // — ou s'invente — depuis l'enfant : voir
                    // {@see \App\Module\Tree\Service\ProjecteurDePlacement::ancreCommeParent()}.
                    'familleId' => null,
                    'familleProvisoire' => $ancrage->familleProvisoire,
                ];
            }
        }

        $tous = [];
        foreach ([...array_keys($direct), ...array_keys($inverse)] as $noeudId) {
            $tous[$noeudId] = [...($direct[$noeudId] ?? []), ...($inverse[$noeudId] ?? [])];
        }

        return array_filter($tous, static fn (array $liste): bool => [] !== $liste);
    }

    /**
     * Le premier candidat qui se pose, **ou la raison de l'échec le plus instructif**.
     *
     * Entre deux échecs, `enchainement` l'emporte sur `ancre_absente` : le premier dit
     * « l'ancre est une fiche proposée », donc « une vague suivante peut encore la poser »,
     * et c'est ce qu'un lecteur a besoin de savoir. Une cible disparue, elle, appelle un
     * tout autre geste.
     *
     * @param non-empty-list<array{kind: string, cible: string|null, familleId: int|null, familleProvisoire: string|null}> $candidats
     * @param array<string, array{generation: int, branche: Branch|null}>                                                  $poses
     * @param array<int, array{displayName: string, branch: string|null}>                                                  $branches
     */
    private function traduirePremierPossible(
        NoeudPropose $source,
        array $candidats,
        Placement $partage,
        array $poses,
        array $branches,
    ): NoeudProposePlacable|string {
        $raison = NoeudProposeEcarte::ANCRE_ABSENTE;

        foreach ($candidats as $candidat) {
            $prepare = $this->traduire($source, $candidat, $partage, $poses, $branches);

            if (!\is_string($prepare)) {
                return $prepare;
            }

            if (NoeudProposeEcarte::ENCHAINEMENT === $prepare) {
                $raison = $prepare;
            }
        }

        return $raison;
    }

    /**
     * Le nœud traduit, **ou la raison de son absence** — jamais `null` : un `null` est ce
     * qui a laissé dix-sept nœuds disparaître sans que rien ne puisse le dire.
     *
     * @param array{kind: string, cible: string|null, familleId: int|null, familleProvisoire: string|null} $rattachement
     * @param array<string, array{generation: int, branche: Branch|null}>                                  $poses        les fiches proposées déjà posées par une vague précédente
     * @param array<int, array{displayName: string, branch: string|null}>                                  $branches
     */
    private function traduire(
        NoeudPropose $source,
        array $rattachement,
        Placement $partage,
        array $poses,
        array $branches,
    ): NoeudProposePlacable|string {
        $traduit = $this->ancrage($rattachement, $partage, $poses);

        if (null === $traduit) {
            return $this->raisonDAncrage($rattachement, $partage);
        }

        [$ancre, $cibleId] = $traduit;

        $generation = $this->generationDepuisLAncre($rattachement['kind'], $cibleId, $partage, $poses);

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
            $this->brancheDeLAncre($cibleId, $partage, $poses, $branches),
            $rattachement['familleProvisoire'],
        );
    }

    /**
     * Pourquoi {@see ancrage()} n'a rien rendu — lu sur le rattachement, pas deviné.
     *
     * La distinction qui compte est celle entre `enchainement` et `ancre_absente` : la
     * première dit que l'ancre est une autre fiche proposée, la seconde qu'une cible a
     * disparu de l'arbre. Les confondre ferait passer un fonctionnement attendu pour une
     * anomalie, et l'inverse.
     *
     * **`enchainement` est devenu rare, et c'est le but.** Il ne sortait autrefois que
     * parce qu'une ancre proposée n'était jamais placée ; les vagues de {@see preparer()}
     * les placent désormais. Ce qui reste sous ce motif est ce qu'aucune vague n'a pu
     * résoudre : une ancre elle-même écartée, ou un cycle.
     *
     * @param array{kind: string, cible: string|null, familleId: int|null, familleProvisoire: string|null} $rattachement
     */
    private function raisonDAncrage(array $rattachement, Placement $partage): string
    {
        if (null !== $rattachement['familleId']) {
            return NoeudProposeEcarte::ANCRE_ABSENTE;
        }

        $autre = $rattachement['cible'];

        if (null === $autre) {
            return NoeudProposeEcarte::ANCRE_ABSENTE;
        }

        if (IdentifiantDeNoeud::estProvisoire($autre)) {
            return NoeudProposeEcarte::ENCHAINEMENT;
        }

        return NoeudProposeEcarte::ANCRE_ABSENTE;
    }

    /**
     * L'ancrage du moteur, et l'identifiant du nœud dont il dépend.
     *
     * Ce nœud est **déjà placé** — dans le placement partagé, ou par une vague
     * précédente. C'est toute la condition à vérifier ici ; le reste du travail est au
     * projecteur, qui tient les arêtes.
     *
     * @param array{kind: string, cible: string|null, familleId: int|null, familleProvisoire: string|null} $rattachement
     * @param array<string, array{generation: int, branche: Branch|null}>                                  $poses
     *
     * @return array{0: AncrageEnAttente, 1: string}|null
     */
    private function ancrage(array $rattachement, Placement $partage, array $poses): ?array
    {
        if (null !== $rattachement['familleId']) {
            // Un couple existant a été désigné : il y a un losange, rien à inventer.
            $famille = IdentifiantDeNoeud::famille($rattachement['familleId']);

            return isset($partage->noeuds[$famille]) ? [AncrageEnAttente::enfantDe($famille), $famille] : null;
        }

        $autre = $rattachement['cible'];

        if (null === $autre || (!isset($partage->noeuds[$autre]) && !isset($poses[$autre]))) {
            return null;
        }

        return match ($rattachement['kind']) {
            'conjoint_de' => [AncrageEnAttente::conjointDe($autre), $autre],
            'parent_de' => [AncrageEnAttente::parentDe($autre), $autre],
            // « Enfant de » sans couple désigné : un seul parent est nommé, et la famille
            // reste à trouver — ou à inventer. C'est le projecteur qui s'en charge : la
            // chercher ici obligeait à relire les arêtes **validées seulement**, donc à
            // écarter tout enfant dont le parent venait d'être posé par une vague.
            'enfant_de' => [AncrageEnAttente::enfantDeParent($autre), $autre],
            default => null,
        };
    }

    /**
     * La génération, lue sur le nœud d'ancrage déjà placé.
     *
     * `y = génération × 150` pour une fiche ; un losange vit 46 px plus bas que la rangée
     * de ses époux — `H_FICHE / 2 + 26`. On retire ce décalage avant de diviser, sans quoi
     * un enfant proposé tomberait un tiers de rangée trop bas.
     *
     * **Une ancre posée par une vague précédente n'a pas d'ordonnée à lire** : elle n'est
     * pas dans le placement partagé, et le projecteur ne l'aura placée qu'après. Sa
     * génération, elle, est connue — c'est celle que la vague a calculée. On la reprend
     * telle quelle et on applique le même décalage. Pas d'arrondi ici : la rangée n'a
     * jamais transité par des pixels, donc rien à en déduire.
     *
     * @param array<string, array{generation: int, branche: Branch|null}> $poses
     */
    private function generationDepuisLAncre(string $kind, string $cibleId, Placement $partage, array $poses): ?int
    {
        $posee = $poses[$cibleId] ?? null;

        if (null !== $posee) {
            return $this->decalage($kind, $posee['generation']);
        }

        $cible = $partage->noeuds[$cibleId] ?? null;

        if (null === $cible) {
            return null;
        }

        if (MoteurDePlacement::H_FICHE !== $cible->h) {
            // Un losange : la rangée des époux est au-dessus, et l'enfant une en dessous.
            $rangeeDesEpoux = ($cible->y - MoteurDePlacement::H_FICHE / 2 - MoteurDePlacement::DESCENTE_NOEUD_FAMILLE) / MoteurDePlacement::H_RANG;

            return (int) round($rangeeDesEpoux) + 1;
        }

        return $this->decalage($kind, (int) round($cible->y / MoteurDePlacement::H_RANG));
    }

    /** La rangée de l'ancre, décalée par la nature du lien. */
    private function decalage(string $kind, int $rangee): int
    {
        return match ($kind) {
            // Les générations sont négatives en remontant : un parent est **une de moins**.
            'parent_de' => $rangee - 1,
            'enfant_de' => $rangee + 1,
            default => $rangee,
        };
    }

    /**
     * @param array<string, array{generation: int, branche: Branch|null}> $poses
     * @param array<int, array{displayName: string, branch: string|null}> $branches
     */
    private function brancheDeLAncre(string $cibleId, Placement $partage, array $poses, array $branches): ?Branch
    {
        // Une ancre posée par une vague précédente n'est dans aucune table de branches :
        // elle n'a pas d'identifiant d'arbre. Sa couleur est celle qu'on lui a déduite, et
        // la passer au suivant est exactement l'approximation assumée en tête de fichier —
        // « un conjoint, un parent ou un enfant proposé appartient au même quart que la
        // personne à laquelle il se rattache », transitivement.
        if (isset($poses[$cibleId])) {
            return $poses[$cibleId]['branche'];
        }

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
