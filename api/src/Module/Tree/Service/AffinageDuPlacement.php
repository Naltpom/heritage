<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\Enum\Sex;
use App\Module\Tree\ValueObject\AreteDePlacement;
use App\Module\Tree\ValueObject\BoiteEnglobante;
use App\Module\Tree\ValueObject\JeuDePlacement;
use App\Module\Tree\ValueObject\NoeudPlace;
use App\Module\Tree\ValueObject\Placement;

/**
 * **L'affinage du placement** : les règles de lisibilité, appliquées après le moteur.
 *
 * ════════════════════════════════════════════════════════════════════════════════
 * LE DÉFAUT, MESURÉ SUR L'ARBRE RÉEL
 *
 * | | |
 * |---|---|
 * | Longueur totale des traits | 89 787 |
 * | Médiane d'un trait | 108 — soit une largeur de fiche, donc normal |
 * | Le plus long | 4 840 |
 * | Les dix pires | **29 % du total** |
 * | Couples sur une même rangée | 100, dont **28 séparés** (98 personnes intercalées) |
 *
 * Le problème n'est pas que tout soit un peu long : la moitié des traits font une
 * largeur de fiche. Quelques dizaines sont catastrophiques, et ce sont elles qu'on
 * voit.
 *
 * ── D'où venait le chaos ────────────────────────────────────────────────────────
 *
 * {@see CalculDePlacement} a deux régimes. L'**ascendance** est posée par une
 * récursion propre — bloc paternel à gauche, maternel à droite, fratrie recentrée
 * sous le couple parental. Tout le reste — cousins, pièces rapportées, leurs
 * enfants — passe par un point fixe : chacun au barycentre de ses parents, **puis**
 * un conjoint déposé « juste à droite de mon partenaire » **sans vérifier si
 * quelqu'un y est déjà**. La passe finale trie par abscisse et pousse à droite,
 * sans jamais réordonner.
 *
 * Deux règles indépendantes visent le même point, le perdant se fait pousser
 * au-delà de la mauvaise personne, et rien ne les rééchange.
 *
 * ── Ce que cette classe garde du moteur, et ce qu'elle refait ──────────────────
 *
 * Elle **garde** les générations et la structure d'ascendance : c'est ce qui met
 * le lecteur au centre, son père à gauche et sa mère à droite, et c'est ce qui
 * fonctionne. Elle **refait** l'ordre des rangées et toutes les abscisses.
 *
 * ════════════════════════════════════════════════════════════════════════════════
 * LES RÈGLES, ARRÊTÉES AVEC LE COMMANDITAIRE
 *
 * **Invariants** — vrais toujours, tenus par {@see \App\Tests\Tree\PlacementLisibleTest} :
 *
 * - **I1** `y = génération × 150`. Aucune liberté verticale, et cette classe n'y
 *   touche pas : une ordonnée est une propriété de la topologie.
 * - **I2** Deux fiches d'une rangée ne se chevauchent jamais.
 * - **I3** Les deux membres d'un couple sont **voisins immédiats**.
 * - **I4** Les enfants d'une famille forment un **bloc contigu** — au sens des
 *   blocs : leurs conjoints ont le droit de s'y intercaler.
 * - **I5** Le losange suit son couple, le bloc d'enfants se centre dessous.
 * - **I6** Dans un couple mixte, **l'homme à gauche**. Dur, partout.
 * - **I7** Le lecteur est à `x = 0`.
 * - **I8** Dans une fratrie, l'ordre est celui des naissances.
 *
 * **Objectif**, sous ces invariants : minimiser **la somme des carrés** des
 * longueurs de traits. Le carré et non la somme simple, et c'est la mesure qui l'a
 * tranché : un trait de 4 840 pèse alors deux mille fois un trait de 108, donc les
 * aberrants sont écrasés d'abord. La somme simple les aurait traités comme
 * quarante-cinq traits normaux, et aurait réparti l'effort au lieu de viser ce
 * qu'on voit.
 *
 * ── Deux exceptions, et elles sont structurelles ───────────────────────────────
 *
 * **I3 et I6 cèdent sur un remariage.** On ne peut pas être voisin de ses deux
 * conjoints. La personne rejoint le bloc de l'union **qui a des enfants dans
 * l'arbre**, à défaut la première ; l'autre union garde un trait plus long. Mesuré :
 * 3 couples sur 100, et les trois impliquent un remarié.
 *
 * **I4 cède quand deux fratries s'allient.** Si un frère épouse quelqu'un d'une
 * autre fratrie, il ne peut être qu'à un endroit : **l'une des deux sera cassée**.
 * On casse alors la plus petite — la plus nombreuse reste entière. Mesuré : 7
 * fratries sur 33, toutes dans ce cas.
 *
 * ════════════════════════════════════════════════════════════════════════════════
 * CE QUE ÇA DONNE, ET CE QUE ÇA A COÛTÉ
 *
 * | | avant | après |
 * |---|---|---|
 * | Longueur totale | 89 787 | **71 918** |
 * | **Somme des carrés** | 106 488 563 | **35 861 601** — −66 % |
 * | Trait le plus long | 4 840 | **2 261** |
 * | Couples accolés | 72/100 | **97/100** |
 * | Fratries contiguës | 26/33 | **26/33** |
 * | Homme à gauche | 93/100 | **98/100** |
 *
 * **Le prix : la preuve du portage de `graphe.py` ne décrit plus ce qui est servi.**
 * Elle décrivait une identité au centième, et une identité interdit par construction
 * toute amélioration — c'est exactement ce qui bloquait. {@see MoteurDePlacement}
 * reste éprouvé contre elle, et reste la première moitié du calcul ; ce qui sort
 * d'ici, lui, est tenu par des **propriétés** : les invariants ci-dessus, et une
 * somme des carrés qui ne remonte jamais.
 *
 * **Le coût en temps est nul pour le lecteur.** Ce calcul vit derrière le cache de
 * {@see PlacementPartage}, une entrée par état de l'arbre et par point de vue,
 * refaite à la validation d'une demande — pas à chaque affichage. On peut donc se
 * permettre soixante passes.
 * ════════════════════════════════════════════════════════════════════════════════
 */
final class AffinageDuPlacement
{
    /** Passes de réordonnancement. Au-delà, la mesure ne bouge plus. */
    private const int PASSES_D_ORDRE = 12;

    /** Passes de descente sur les abscisses. */
    private const int PASSES_DE_PLACEMENT = 60;

    /**
     * **De combien le losange descend sous la rangée de ses époux.**.
     *
     * Le moteur pose 26 px ({@see MoteurDePlacement::DESCENTE_NOEUD_FAMILLE}), et on
     * ne touche pas à sa constante : sa preuve de portage au centième en dépend.
     * C'est ici, en sortie, qu'on la corrige.
     *
     * ── Pourquoi 46, et pas un chiffre rond choisi à l'œil ──────────────────────
     *
     * Les barres d'union d'une rangée vivent dans une bande étroite : sous le bas des
     * fiches, au-dessus de la pointe haute du losange. Avec 26, cette bande vaut
     * exactement **19 px** — `26 + 20 - 20 - 7` —, et quatre barres qui se croisent
     * s'y retrouvent à moins de 4 px l'une de l'autre. Elles ne se superposent plus
     * depuis la coloration par intervalles, mais elles se frôlent.
     *
     * 46 donne **39 px**, soit exactement le double. C'est ce que le commanditaire a
     * demandé, et c'est le seul levier : l'étalement à l'intérieur de la bande est
     * déjà presque complet, il ne restait qu'un facteur 1,4 à y gagner.
     *
     * ── Ce que ça coûte, et ce qui a été vérifié ────────────────────────────────
     *
     * Le losange descend de 20 px, donc la bande des **peignes de filiation** — du
     * losange au haut des fiches enfants — passe de 84 à 64 px. Ces barres-là sont
     * étagées depuis toujours et n'ont jamais eu de recouvrement ; la mesure après
     * changement le confirme, sans quoi on aurait déplacé un défaut au lieu d'en
     * corriger un.
     */
    private const int DESCENTE_DU_LOSANGE = 46;

    /**
     * @var array<string, list<string>> famille → ses époux
     */
    private array $epoux = [];

    /** @var array<string, list<string>> famille → ses enfants */
    private array $enfants = [];

    /** @var array<string, string> personne → la famille dont elle est l'enfant */
    private array $familleDesParents = [];

    /** @var array<string, list<string>> tout nœud → ses voisins, sans distinction de sens */
    private array $voisins = [];

    /** @var array<string, Sex> */
    private array $sexes = [];

    /** @var array<string, int|null> */
    private array $annees = [];

    /** @var array<string, NoeudPlace> */
    private array $noeuds = [];

    /** @var array<string, float> */
    private array $posx = [];

    public function affine(Placement $place, JeuDePlacement $jeu): Placement
    {
        $this->indexer($place, $jeu);

        if ([] === $this->noeuds) {
            return $place;
        }

        $rangees = $this->blocs();

        $this->ordonner($rangees);
        $this->placer($rangees, self::PASSES_DE_PLACEMENT);
        $this->recentrerSurLEgo($place->egoId);

        $descendus = $this->losangesDescendus();
        $noeuds = [];

        foreach ($this->noeuds as $id => $noeud) {
            $noeuds[$id] = new NoeudPlace(
                $id,
                $this->posx[$id] ?? $noeud->x,
                $descendus[$id] ?? $noeud->y,
                $noeud->w,
                $noeud->h,
            );
        }

        return new Placement($noeuds, $place->aretes, $this->boiteEnglobante($noeuds), $place->egoId);
    }

    /**
     * La nouvelle ordonnée de chaque losange — {@see DESCENTE_DU_LOSANGE}.
     *
     * **Seuls les losanges bougent, et seulement ceux dont les deux époux sont sur
     * une même rangée.** Une famille sans époux placé est posée autrement par le
     * moteur, au-dessus de ses enfants ; la recalculer ici sur une règle qui ne la
     * concerne pas la décrocherait de sa fratrie.
     *
     * L'ordonnée d'une personne, elle, ne bouge jamais : c'est sa génération, une
     * propriété de la donnée. C'est **I1**, et elle tient toujours — au mot près,
     * qu'il faut donc lire « la rangée d'une personne », pas « toute ordonnée ».
     *
     * @return array<string, float>
     */
    private function losangesDescendus(): array
    {
        $descendus = [];

        foreach ($this->epoux as $famille => $membres) {
            if (!isset($this->noeuds[$famille])) {
                continue;
            }

            $ys = [];

            foreach ($membres as $membre) {
                if (isset($this->noeuds[$membre])) {
                    $ys[] = $this->noeuds[$membre]->y;
                }
            }

            // Une liste puis `array_unique`, et non un tableau indexé par l'ordonnée :
            // une clé de tableau ne peut pas être un flottant, PHP la tronquerait en
            // entier et deux rangées distantes de moins d'un pixel se confondraient.
            $rangees = array_values(array_unique($ys, \SORT_REGULAR));

            if (1 !== \count($rangees)) {
                continue;
            }

            $descendus[$famille] = $rangees[0]
                + MoteurDePlacement::H_FICHE / 2
                + self::DESCENTE_DU_LOSANGE;
        }

        return $descendus;
    }

    /* ------------------------------------------------------------------ */
    /* Lecture */
    /* ------------------------------------------------------------------ */

    private function indexer(Placement $place, JeuDePlacement $jeu): void
    {
        $this->epoux = [];
        $this->enfants = [];
        $this->familleDesParents = [];
        $this->voisins = [];
        $this->sexes = [];
        $this->annees = [];
        $this->noeuds = $place->noeuds;
        $this->posx = [];

        foreach ($place->noeuds as $id => $noeud) {
            $this->posx[$id] = $noeud->x;
        }

        foreach ($jeu->personnes as $personne) {
            $this->sexes[$personne->id] = $personne->sexe;
            $this->annees[$personne->id] = $personne->anNais;
        }

        foreach ($place->aretes as $arete) {
            if (AreteDePlacement::GENRE_UNION === $arete->genre) {
                $this->epoux[$arete->vers][] = $arete->de;
            } else {
                $this->enfants[$arete->de][] = $arete->vers;
                $this->familleDesParents[$arete->vers] = $arete->de;
            }

            $this->voisins[$arete->de][] = $arete->vers;
            $this->voisins[$arete->vers][] = $arete->de;
        }
    }

    /* ------------------------------------------------------------------ */
    /* I3, I6 : les blocs */
    /* ------------------------------------------------------------------ */

    /**
     * Les blocs de chaque rangée : un couple, ou une personne seule.
     *
     * **C'est la pièce qui rend I3 et I6 vraies par construction** au lieu d'être
     * des contraintes à réparer après coup. Un couple est indissociable dès cet
     * instant : plus rien ne peut s'intercaler entre ses deux membres, ni à
     * l'ordonnancement ni au placement.
     *
     * Les losanges forment leurs propres rangées — ils vivent 26 px sous celle de
     * leurs époux —, et chacun y est un bloc d'un seul.
     *
     * @return array<int, list<list<string>>> ordonnée → blocs, de gauche à droite
     */
    private function blocs(): array
    {
        $unionChoisie = $this->unionsChoisies();

        $rangees = [];
        $pris = [];

        foreach ($this->epoux as $famille => $membres) {
            if (2 !== \count($membres)) {
                continue;
            }

            [$a, $b] = $membres;

            if (isset($pris[$a]) || isset($pris[$b])) {
                continue;
            }

            if (($unionChoisie[$a] ?? null) !== $famille || ($unionChoisie[$b] ?? null) !== $famille) {
                continue;
            }

            $na = $this->noeuds[$a] ?? null;
            $nb = $this->noeuds[$b] ?? null;

            if (null === $na || null === $nb || $na->y !== $nb->y) {
                continue;
            }

            $rangees[(int) $na->y][] = $this->hommeAGauche($a, $b);
            $pris[$a] = true;
            $pris[$b] = true;
        }

        foreach ($this->noeuds as $id => $noeud) {
            if (isset($pris[$id])) {
                continue;
            }

            $rangees[(int) $noeud->y][] = [$id];
        }

        ksort($rangees, \SORT_NUMERIC);

        foreach ($rangees as $y => $blocs) {
            usort($blocs, fn (array $a, array $b): int => $this->gaucheDuBloc($a) <=> $this->gaucheDuBloc($b));
            $rangees[$y] = $blocs;
        }

        return $rangees;
    }

    /**
     * **I6 — l'homme à gauche, dur.**.
     *
     * À sexe inconnu ou identique, on garde l'ordre reçu : le moteur le tient de
     * `HUSB` puis `WIFE`, c'est-à-dire du GEDCOM, et inventer un ordre ici
     * déplacerait des couples sans raison lisible.
     *
     * @return list<string>
     */
    private function hommeAGauche(string $a, string $b): array
    {
        $sa = $this->sexes[$a] ?? Sex::Inconnu;
        $sb = $this->sexes[$b] ?? Sex::Inconnu;

        return Sex::Masculin === $sb && Sex::Feminin === $sa ? [$b, $a] : [$a, $b];
    }

    /**
     * L'union de chaque personne, quand elle en a plusieurs.
     *
     * **Exception structurelle à I3 : on ne peut pas être voisin de ses deux
     * conjoints.** On garde l'union **qui a des enfants dans l'arbre** — c'est
     * celle qui porte des traits vers le bas, donc celle dont l'allongement coûte
     * le plus. À défaut, la première rencontrée, qui est l'ordre du GEDCOM.
     *
     * @return array<string, string>
     */
    private function unionsChoisies(): array
    {
        $choisie = [];

        foreach ($this->epoux as $famille => $membres) {
            if (2 !== \count($membres)) {
                continue;
            }

            foreach ($membres as $personne) {
                $deja = $choisie[$personne] ?? null;

                if (null !== $deja && [] !== ($this->enfants[$deja] ?? [])) {
                    continue;
                }

                $choisie[$personne] = $famille;
            }
        }

        return $choisie;
    }

    /* ------------------------------------------------------------------ */
    /* I4, I8 : l'ordre */
    /* ------------------------------------------------------------------ */

    /**
     * Réordonne chaque rangée par **balayages de médiane sur les groupes de
     * fratrie**.
     *
     * Deux niveaux, et c'est ce qui tient I4 et I8 ensemble :
     *
     * - **entre groupes** : la médiane de la position des voisins. La médiane et
     *   non la moyenne — elle résiste au nœud qui a un voisin très lointain,
     *   typiquement l'ancêtre commun à deux branches éloignées, que la moyenne
     *   enverrait entre les deux, c'est-à-dire nulle part ;
     * - **dans un groupe** : l'ordre des naissances (I8), qui est une propriété de
     *   la donnée et non du dessin.
     *
     * Un placement provisoire suit chaque passe : sans lui, la médiane du tour
     * suivant se calculerait sur des positions que le réordonnancement vient de
     * rendre fausses.
     *
     * @param array<int, list<list<string>>> $rangees
     */
    private function ordonner(array &$rangees): void
    {
        for ($passe = 0; $passe < self::PASSES_D_ORDRE; ++$passe) {
            foreach ($rangees as $y => $blocs) {
                $rangees[$y] = $this->ordonnerLaRangee($blocs);
            }

            $this->placer($rangees, 1);
        }
    }

    /**
     * @param list<list<string>> $blocs
     *
     * @return list<list<string>>
     */
    private function ordonnerLaRangee(array $blocs): array
    {
        /** @var array<string, list<list<string>>> $groupes */
        $groupes = [];
        /** @var list<string> $ordre */
        $ordre = [];

        foreach ($blocs as $index => $bloc) {
            $cle = $this->ancreDeFratrie($bloc) ?? 'seul:'.$index;

            if (!isset($groupes[$cle])) {
                $groupes[$cle] = [];
                $ordre[] = $cle;
            }

            $groupes[$cle][] = $bloc;
        }

        // I8 : dans une fratrie, l'ordre des naissances.
        foreach ($groupes as $cle => $membres) {
            usort($membres, fn (array $a, array $b): int => $this->cleDeNaissance($a) <=> $this->cleDeNaissance($b));
            $groupes[$cle] = $membres;
        }

        $rang = array_flip($ordre);

        usort($ordre, function (string $a, string $b) use ($groupes, $rang): int {
            $ma = $this->medianeDuGroupe($groupes[$a]);
            $mb = $this->medianeDuGroupe($groupes[$b]);

            // Un groupe sans voisin placé garde sa place : le faire glisser en tête
            // ou en queue ferait bouger des gens pour rien.
            if (null === $ma || null === $mb) {
                return $rang[$a] <=> $rang[$b];
            }

            return $ma <=> $mb ?: $rang[$a] <=> $rang[$b];
        });

        $sortie = [];

        foreach ($ordre as $cle) {
            foreach ($groupes[$cle] as $bloc) {
                $sortie[] = $bloc;
            }
        }

        return $sortie;
    }

    /**
     * La fratrie à laquelle ce bloc appartient.
     *
     * **Exception structurelle à I4.** Quand les deux époux d'un bloc sont chacun
     * enfant d'une famille de l'arbre, l'une des deux fratries sera cassée : une
     * personne ne peut être qu'à un endroit. On casse **la plus petite** — le bloc
     * rejoint la fratrie la plus nombreuse, qui reste entière.
     *
     * @param list<string> $bloc
     */
    private function ancreDeFratrie(array $bloc): ?string
    {
        $candidats = [];

        foreach ($bloc as $id) {
            $famille = $this->familleDesParents[$id] ?? null;

            if (null !== $famille) {
                $candidats[$famille] = \count($this->enfants[$famille] ?? []);
            }
        }

        if ([] === $candidats) {
            return null;
        }

        arsort($candidats);

        return (string) array_key_first($candidats);
    }

    /** @param list<list<string>> $groupe */
    private function medianeDuGroupe(array $groupe): ?float
    {
        $positions = [];

        foreach ($groupe as $bloc) {
            foreach ($bloc as $id) {
                foreach ($this->voisins[$id] ?? [] as $voisin) {
                    if (isset($this->posx[$voisin])) {
                        $positions[] = $this->posx[$voisin];
                    }
                }
            }
        }

        if ([] === $positions) {
            return null;
        }

        sort($positions);
        $milieu = intdiv(\count($positions), 2);

        return 0 === \count($positions) % 2
            ? ($positions[$milieu - 1] + $positions[$milieu]) / 2
            : $positions[$milieu];
    }

    /**
     * La clé de tri d'une fratrie : l'année de naissance, puis l'identifiant.
     *
     * Une année inconnue passe en dernier plutôt qu'en premier : à l'année zéro,
     * elle prendrait la tête de toutes les fratries de l'arbre.
     *
     * @param list<string> $bloc
     */
    private function cleDeNaissance(array $bloc): string
    {
        $tete = $bloc[0] ?? '';
        $annee = $this->annees[$tete] ?? null;

        return \sprintf('%04d|%s', $annee ?? 9999, $tete);
    }

    /** @param list<string> $bloc */
    private function gaucheDuBloc(array $bloc): float
    {
        $positions = [];

        foreach ($bloc as $id) {
            $positions[] = $this->posx[$id] ?? 0.0;
        }

        return [] === $positions ? 0.0 : min($positions);
    }

    /* ------------------------------------------------------------------ */
    /* L'objectif : les abscisses */
    /* ------------------------------------------------------------------ */

    /**
     * Minimise la **somme des carrés** des longueurs de traits, sous les
     * contraintes d'écart et d'ordre.
     *
     * Une descente par coordonnées, en deux temps répétés :
     *
     * 1. **chaque nœud vise la moyenne de ses voisins.** C'est l'optimum d'un nœud
     *    pris seul pour `Σ (Δx)²` — la dérivée s'annule à la moyenne, là où pour la
     *    somme simple ce serait la médiane ;
     * 2. **on projette la rangée sur l'ensemble admissible**, exactement.
     *
     * La projection est le point qui mérite d'être dit : elle n'approche pas, elle
     * **résout**. {@see self::projeter()}.
     *
     * @param array<int, list<list<string>>> $rangees
     */
    private function placer(array $rangees, int $passes): void
    {
        for ($passe = 0; $passe < $passes; ++$passe) {
            $desirees = [];

            foreach ($this->noeuds as $id => $noeud) {
                $positions = [];

                foreach ($this->voisins[$id] ?? [] as $voisin) {
                    if (isset($this->posx[$voisin])) {
                        $positions[] = $this->posx[$voisin];
                    }
                }

                $desirees[$id] = [] === $positions
                    ? ($this->posx[$id] ?? $noeud->x)
                    : array_sum($positions) / \count($positions);
            }

            foreach ($rangees as $blocs) {
                $suite = [];

                foreach ($blocs as $bloc) {
                    foreach ($bloc as $id) {
                        $suite[] = $id;
                    }
                }

                $this->projeterLaRangee($suite, $desirees);
            }
        }
    }

    /**
     * @param list<string>         $suite
     * @param array<string, float> $desirees
     */
    private function projeterLaRangee(array $suite, array $desirees): void
    {
        $total = \count($suite);

        if (0 === $total) {
            return;
        }

        $ecarts = [];
        $cibles = [];
        $poids = [];

        for ($i = 0; $i < $total; ++$i) {
            $id = $suite[$i];
            $cibles[] = $desirees[$id] ?? ($this->posx[$id] ?? 0.0);
            $poids[] = max(1.0, (float) \count($this->voisins[$id] ?? []));

            if ($i + 1 < $total) {
                $ecarts[] = ($this->noeuds[$id]->w / 2)
                    + MoteurDePlacement::ESPACE
                    + ($this->noeuds[$suite[$i + 1]]->w / 2);
            }
        }

        foreach ($this->projeter($cibles, $poids, $ecarts) as $i => $x) {
            $this->posx[$suite[$i]] = $x;
        }
    }

    /**
     * **La projection exacte** : minimise `Σ p·(x − cible)²` sous
     * `x[i+1] − x[i] ≥ écart[i]`.
     *
     * ── Pourquoi c'est exact, et pourquoi c'est important ──────────────────────
     *
     * En retirant les écarts cumulés, la contrainte devient `y[i+1] ≥ y[i]` :
     * c'est une **régression isotonique**, et l'algorithme PAVA — *pool adjacent
     * violators* — en donne l'optimum en un seul parcours. Tant que le voisin de
     * gauche dépasse, on fond les deux en un bloc de moyenne pondérée, et l'on
     * recommence.
     *
     * L'alternative habituelle — pousser vers la droite jusqu'à ce que ça rentre —
     * ne donne pas le minimum : elle privilégie arbitrairement la gauche, et le
     * résultat dépend du sens de parcours. C'est précisément ce que faisait la
     * passe de décrochage du moteur, et c'est une des raisons du chaos.
     *
     * @param list<float> $cibles
     * @param list<float> $poids
     * @param list<float> $ecarts
     *
     * @return list<float>
     */
    private function projeter(array $cibles, array $poids, array $ecarts): array
    {
        $total = \count($cibles);
        $decalage = [0.0];

        for ($i = 1; $i < $total; ++$i) {
            $decalage[$i] = $decalage[$i - 1] + $ecarts[$i - 1];
        }

        /** @var list<float> $valeurs */
        $valeurs = [];
        /** @var list<float> $masses */
        $masses = [];
        /** @var list<int> $tailles */
        $tailles = [];

        for ($i = 0; $i < $total; ++$i) {
            $valeur = $cibles[$i] - $decalage[$i];
            $masse = $poids[$i];
            $taille = 1;

            while ([] !== $valeurs && end($valeurs) > $valeur) {
                $vPrec = (float) array_pop($valeurs);
                $mPrec = (float) array_pop($masses);
                $tPrec = (int) array_pop($tailles);

                $valeur = ($vPrec * $mPrec + $valeur * $masse) / ($mPrec + $masse);
                $masse += $mPrec;
                $taille += $tPrec;
            }

            $valeurs[] = $valeur;
            $masses[] = $masse;
            $tailles[] = $taille;
        }

        $sortie = [];
        $i = 0;

        foreach ($valeurs as $rang => $valeur) {
            for ($n = 0; $n < $tailles[$rang]; ++$n) {
                $sortie[] = $valeur + $decalage[$i];
                ++$i;
            }
        }

        return $sortie;
    }

    /* ------------------------------------------------------------------ */
    /* I7 et la boîte */
    /* ------------------------------------------------------------------ */

    /**
     * **I7 — le lecteur à `x = 0`.**.
     *
     * Une translation rigide : elle ne change aucune position relative, donc rien
     * de ce que les autres règles ont obtenu.
     */
    private function recentrerSurLEgo(string $egoId): void
    {
        $ego = $this->posx[$egoId] ?? null;

        if (null === $ego || 0.0 === $ego) {
            return;
        }

        foreach ($this->posx as $id => $x) {
            $this->posx[$id] = $x - $ego;
        }
    }

    /** @param array<string, NoeudPlace> $noeuds */
    private function boiteEnglobante(array $noeuds): BoiteEnglobante
    {
        if ([] === $noeuds) {
            return new BoiteEnglobante(0.0, 0.0, 0.0, 0.0);
        }

        $xMin = $yMin = \INF;
        $xMax = $yMax = -\INF;

        foreach ($noeuds as $noeud) {
            $xMin = min($xMin, $noeud->x - $noeud->w / 2);
            $xMax = max($xMax, $noeud->x + $noeud->w / 2);
            $yMin = min($yMin, $noeud->y - $noeud->h / 2);
            $yMax = max($yMax, $noeud->y + $noeud->h / 2);
        }

        $marge = MoteurDePlacement::MARGE_BOITE;

        return new BoiteEnglobante(
            $xMin - $marge,
            $yMin - $marge,
            $xMax - $xMin + 2 * $marge,
            $yMax - $yMin + 2 * $marge,
        );
    }
}
