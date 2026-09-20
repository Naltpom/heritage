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

    private const int VERS_LA_GAUCHE = -1;

    private const int VERS_LA_DROITE = 1;

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
     * 46 donnait **39 px**, soit exactement le double — c'est ce que le commanditaire
     * a demandé, et c'est le seul levier : l'étalement à l'intérieur de la bande est
     * déjà presque complet, il ne restait qu'un facteur 1,4 à y gagner.
     *
     * **54 depuis que la fiche fait 56 px de haut** ({@see MesureDeFiche::HAUTEUR_AFFICHEE}) :
     * le bas des fiches est descendu de 8 px, et la bande vaut `54 + 20 - 7 - 28`,
     * soit toujours **39 px**. Le chiffre suit la géométrie de la fiche, pas l'inverse.
     *
     * ── Ce que ça coûte, et ce qui a été vérifié ────────────────────────────────
     *
     * Le losange descend, donc la bande des **peignes de filiation** — du losange au
     * haut des fiches enfants — se resserre : de 84 px à l'origine à 48 px maintenant
     * (`150 - 28 - 74`). Ces barres-là sont étagées depuis toujours et n'ont jamais eu
     * de recouvrement ; la mesure après changement le confirme, sans quoi on aurait
     * déplacé un défaut au lieu d'en corriger un.
     */
    private const int DESCENTE_DU_LOSANGE = 54;

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

    /**
     * Le rang du quart de chaque nœud, quand le lecteur a ses deux parents placés.
     *
     * `-1` détaché · `0` pp · `1` pm · `2` tronc et parents · `3` mp · `4` mm.
     * Vide quand le découpage n'a pas de sens — pas de parents, ou pas deux.
     *
     * @var array<string, int>
     */
    private array $quarts = [];

    /** Le rang du tronc dans {@see $quarts} : 2 sans conjoint, 4 avec. */
    private int $rangDuTronc = 2;

    /**
     * La mesure des fiches **telles qu'elles sont affichées** — photo à gauche, dates
     * des vivants — est faite ici et non dans le moteur : le moteur mesure ce que sa
     * preuve de non-régression attend, et c'est l'affinage qui refait les abscisses.
     * Un défaut en argument, pour que `new AffinageDuPlacement()` reste possible dans
     * les tests et les scripts de mesure.
     */
    public function __construct(private readonly MesureDeFiche $mesure = new MesureDeFiche())
    {
    }

    public function affine(Placement $place, JeuDePlacement $jeu): Placement
    {
        $this->indexer($place, $jeu);

        if ([] === $this->noeuds) {
            return $place;
        }

        // **Les blocs d'abord, les gens ensuite.** Le découpage en quarts est
        // topologique : il ne dépend d'aucune abscisse, donc il se calcule avant
        // tout placement et sert de première clé d'ordre. Les quarts sont ainsi
        // **ordonnés sans être écartés** — aucun espace n'est imposé entre eux.
        $this->quarts = $this->quartsDuLecteur($place->egoId);

        $rangees = $this->blocs();

        $this->ordonner($rangees);
        $this->placer($rangees, self::PASSES_DE_PLACEMENT);
        $this->isolerLeTronc($place->egoId);
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

            // La fiche telle qu'elle est AFFICHÉE : un emplacement photo à gauche pour
            // tout le monde, et la naissance des vivants sur la ligne de dates. Le
            // moteur a mesuré la fiche d'avant — c'est ce que sa preuve attend — et
            // c'est ici, avant de refaire les abscisses, que la vraie boîte remplace la
            // sienne. Voir {@see MesureDeFiche::largeurAffichee()}.
            $ancien = $this->noeuds[$personne->id] ?? null;

            if (null !== $ancien) {
                $this->noeuds[$personne->id] = new NoeudPlace(
                    $ancien->id,
                    $ancien->x,
                    $ancien->y,
                    $this->mesure->largeurAffichee($personne),
                    MesureDeFiche::HAUTEUR_AFFICHEE,
                );
            }
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
     * Le quart d'un groupe de blocs — le plus petit rang de ses membres.
     *
     * Le plus petit, et non la moyenne : un groupe qui touche deux quarts se range
     * du côté du premier, ce qui garde l'ordre total et évite qu'un cas mixte
     * flotte entre les deux.
     *
     * @param list<list<string>> $groupe
     */
    private function quartDuGroupe(array $groupe): ?int
    {
        $rang = null;

        foreach ($groupe as $bloc) {
            foreach ($bloc as $id) {
                if (!isset($this->quarts[$id])) {
                    continue;
                }

                $rang = null === $rang ? $this->quarts[$id] : min($rang, $this->quarts[$id]);
            }
        }

        return $rang;
    }

    /**
     * **Les cinq régions du lecteur, en topologie pure.**
     *
     * `[ pp | pm ][ TRONC ][ mp | mm ]`, plus ce qui n'est relié à rien. Le calcul
     * ne lit aucune abscisse : c'est ce qui permet de s'en servir comme clé d'ordre
     * avant le premier placement.
     *
     * ── L'INFORMATION LA PLUS UTILE DE CE CHANTIER, POUR QUI REPREND ────────────
     *
     * **Ordonner et écarter ne sont pas la même contrainte, et l'écart vaut
     * 67 millions.** Mesuré le 20/09/2026 sur l'arbre de référence, 377 nœuds :
     *
     * |                          | carrés | trait max | largeur |
     * |---|---|---|---|
     * | moteur seul              | 106,5 M | 4 840 | 15 704 |
     * | affinage, avant          | 61,9 M | 4 198 | 13 714 |
     * | quatre blocs **écartés** | **122,8 M** | 7 548 | 18 709 |
     * | quarts **ordonnés**      | **55,7 M** | 3 982 | 13 437 |
     *
     * Écarter impose un espace : chaque arête qui va d'un quart à l'autre traverse
     * alors la largeur entière d'un quart, et la garde de l'arbre réel refuse la
     * version — la somme des carrés repasse au-dessus du moteur seul.
     *
     * Ordonner n'impose rien : les quarts se touchent, ils ne se traversent plus.
     * **Et le gain n'est pas un hasard** — l'entrelacement mesuré avant (`pp` et
     * `pm` chevauchés sur 4 337 px sur l'arbre du commanditaire) **était lui-même
     * du désordre**. Le défaire sans écarter raccourcit les traits.
     *
     * **Conséquence pour la suite, et elle est chiffrée, plus supposée :** la
     * spécification à treize régions du commanditaire — ses quatre quarts, ceux de
     * sa conjointe, le tronc — passe par une **clé de tri plus fine**, pas par des
     * blocs rigides. C'est « les blocs d'abord, les gens ensuite » sans blocs.
     * ────────────────────────────────────────────────────────────────────
     *
     * Rend un tableau vide quand le découpage n'a pas de sens — lecteur sans
     * parents placés, ou famille qui n'en porte pas deux.
     *
     * @return array<string, int>
     */
    private function quartsDuLecteur(string $egoId): array
    {
        $famille = $this->familleDesParents[$egoId] ?? null;

        if (null === $famille || 2 !== \count($this->epoux[$famille] ?? [])) {
            return [];
        }

        [$pere, $mere] = $this->hommeAGauche(...$this->epoux[$famille]);

        if (!isset($this->noeuds[$pere], $this->noeuds[$mere])) {
            return [];
        }

        $parents = [$famille => true, $pere => true, $mere => true];
        $noyau = $this->descendanceStricte($this->enfants[$famille] ?? []);
        $aieuxDuPere = $this->ascendanceStricte($pere);
        $aieuxDeLaMere = $this->ascendanceStricte($mere);

        $paternel = $this->atteignableDepuis(
            array_keys($aieuxDuPere + [$pere => true]),
            $parents + $aieuxDeLaMere + $noyau,
        );
        $maternel = $this->atteignableDepuis(
            array_keys($aieuxDeLaMere + [$mere => true]),
            $parents + $paternel + $noyau,
        );
        $tronc = $this->atteignableDepuis($this->enfants[$famille] ?? [], $parents + $paternel + $maternel);

        // ── L'ASCENDANCE DU CONJOINT, EXTRAITE DU TRONC.
        //
        // **Le tronc du moteur et le tronc du commanditaire ne sont pas le même
        // objet.** Sa définition dit : *« moi, mes parents, mon conjoint — mais pas
        // ses frères et sœurs, eux restent dans le bloc de la famille opposée — mes
        // enfants et leurs conjoints »*. Celle du moteur avale toute la parenté du
        // conjoint, parce que la fouille du tronc part de la fratrie du lecteur et
        // que rien ne l'arrête avant.
        //
        // Mesuré le 20/09/2026, depuis le père du commanditaire :
        //
        //     paternel 154 · maternel 204 · TRONC 244 · hors 43
        //
        // **181 personnes dans ce tronc, dont l'ascendance entière de sa conjointe.**
        // C'est cela qu'on en sort.
        $conjoints = $this->conjointsDe($egoId);
        $cote = [];

        foreach ($conjoints as $conjoint) {
            $cote = $this->ascendanceDuConjoint($conjoint, $tronc);

            if ([] !== $cote) {
                $tronc = array_diff_key($tronc, $cote);
                break;
            }
        }

        // **Le tronc se tient ENTRE les deux moitiés du dessin.** Avec un conjoint,
        // ce sont la moitié du lecteur et la sienne ; sans conjoint, ce sont le côté
        // du père et celui de la mère — l'arrangement d'aujourd'hui, inchangé.
        //
        // Sans cette distinction, un lecteur sans conjoint verrait tout son arbre
        // passer à gauche et **sa propre fiche partir au bord droit du dessin**,
        // alors qu'elle est au milieu. On ne déplace pas le lecteur pour une moitié
        // qui n'existe pas.
        $avecConjoint = [] !== $cote;
        $rangs = $avecConjoint
            ? ['pp' => 0, 'pm' => 1, 'mp' => 2, 'mm' => 3, 'tr' => 4]
            : ['pp' => 0, 'pm' => 1, 'tr' => 2, 'mp' => 3, 'mm' => 4];
        $this->rangDuTronc = $rangs['tr'];

        $quarts = [];

        foreach ([[$pere, $paternel, 'pp', 'pm'], [$mere, $maternel, 'mp', 'mm']] as [$parent, $bloc, $un, $deux]) {
            [$premier, $second] = $this->deuxQuarts($parent, $bloc);

            foreach (array_keys($premier) as $id) {
                $quarts[$id] = $rangs[$un];
            }

            foreach (array_keys($second) as $id) {
                $quarts[$id] = $rangs[$deux];
            }
        }

        foreach (array_keys($tronc + $parents) as $id) {
            $quarts[$id] = $rangs['tr'];
        }

        if ($avecConjoint) {
            foreach ($this->quatreQuartsDuConjoint($cote) as $rang => $ensemble) {
                foreach (array_keys($ensemble) as $id) {
                    $quarts[$id] = 5 + $rang;
                }
            }
        }

        foreach (array_keys($this->noeuds) as $id) {
            if (!isset($quarts[$id])) {
                $quarts[$id] = -1;
            }
        }

        return $quarts;
    }

    /**
     * Les conjoints du lecteur, dans l'ordre des familles où il est époux.
     *
     * @return list<string>
     */
    private function conjointsDe(string $egoId): array
    {
        $conjoints = [];

        foreach ($this->voisins[$egoId] ?? [] as $voisin) {
            if (!isset($this->epoux[$voisin]) || !\in_array($egoId, $this->epoux[$voisin], true)) {
                continue;
            }

            foreach ($this->epoux[$voisin] as $membre) {
                if ($membre !== $egoId) {
                    $conjoints[] = $membre;
                }
            }
        }

        return $conjoints;
    }

    /**
     * **Ce qui, dans le tronc, appartient à la parenté du conjoint et non au foyer.**
     *
     * On part de ses parents — jamais de lui : le conjoint reste dans le tronc, c'est
     * le foyer du lecteur. Sa fratrie, ses parents et leur lignée en sortent.
     *
     * Rend un tableau vide quand il n'a aucun parent placé : il n'y a alors rien à
     * extraire, et le dessin garde son arrangement d'aujourd'hui.
     *
     * @param array<string, true> $tronc
     *
     * @return array<string, true>
     */
    private function ascendanceDuConjoint(string $conjoint, array $tronc): array
    {
        $famille = $this->familleDesParents[$conjoint] ?? null;

        if (null === $famille || !isset($tronc[$famille])) {
            return [];
        }

        return $this->atteignableDepuis([$famille], $this->horsDe($tronc) + [$conjoint => true]);
    }

    /**
     * Le côté du conjoint, découpé comme celui du lecteur : `pp'`, `pm'`, `mp'`, `mm'`.
     *
     * Le même geste que {@see deuxQuarts()}, appliqué deux fois — une par parent du
     * conjoint. Quand un partage n'est pas possible, le quart vide reste vide : c'est
     * une région de moins, pas une région fausse.
     *
     * @param array<string, true> $cote
     *
     * @return list<array<string, true>>
     */
    private function quatreQuartsDuConjoint(array $cote): array
    {
        $couple = null;

        foreach (array_keys($cote) as $id) {
            if (isset($this->epoux[$id]) && 2 === \count($this->epoux[$id])) {
                $couple = $id;
                break;
            }
        }

        if (null === $couple) {
            return [$cote, [], [], []];
        }

        [$pere, $mere] = $this->hommeAGauche(...$this->epoux[$couple]);

        $paternel = $this->atteignableDepuis(
            array_keys($this->ascendanceStricte($pere) + [$pere => true]),
            $this->horsDe($cote) + $this->ascendanceStricte($mere) + [$mere => true, $couple => true],
        );

        $maternel = [];

        foreach (array_keys($cote) as $id) {
            if (!isset($paternel[$id])) {
                $maternel[$id] = true;
            }
        }

        [$pp, $pm] = $this->deuxQuarts($pere, $paternel);
        [$mp, $mm] = $this->deuxQuarts($mere, $maternel);

        return [$pp, $pm, $mp, $mm];
    }

    /**
     * Le complément d'un ensemble parmi les nœuds placés — ce qu'une fouille doit
     * s'interdire pour rester chez elle.
     *
     * @param array<string, true> $ensemble
     *
     * @return array<string, true>
     */
    private function horsDe(array $ensemble): array
    {
        $dehors = [];

        foreach (array_keys($this->noeuds) as $id) {
            if (!isset($ensemble[$id])) {
                $dehors[$id] = true;
            }
        }

        return $dehors;
    }

    /**
     * Les deux quarts d'un côté : la lignée du grand-père, puis celle de la
     * grand-mère. Sans partage possible, tout va dans le premier.
     *
     * Les frères et sœurs du parent descendent des deux grands-parents : ils
     * suivent le losange de leurs parents, donc le quart du grand-père. **C'est un
     * choix par défaut, pas une évidence.**
     *
     * @param array<string, true> $bloc
     *
     * @return array{0: array<string, true>, 1: array<string, true>}
     */
    private function deuxQuarts(string $parent, array $bloc): array
    {
        $famille = $this->familleDesParents[$parent] ?? null;

        if (null === $famille || 2 !== \count($this->epoux[$famille] ?? [])) {
            return [$bloc, []];
        }

        [$grandPere, $grandMere] = $this->hommeAGauche(...$this->epoux[$famille]);

        if (!isset($this->noeuds[$grandPere], $this->noeuds[$grandMere])) {
            return [$bloc, []];
        }

        $premier = $this->atteignableDepuis(
            array_keys($this->ascendanceStricte($grandPere) + [$grandPere => true]),
            $this->horsDe($bloc) + $this->ascendanceStricte($grandMere) + [$grandMere => true],
        );

        $second = [];

        foreach (array_keys($bloc) as $id) {
            if (!isset($premier[$id])) {
                $second[$id] = true;
            }
        }

        return [] === $premier || [] === $second ? [$bloc, []] : [$premier, $second];
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
            $famille = str_starts_with($cle, 'seul:') ? null : $cle;

            usort(
                $membres,
                fn (array $a, array $b): int => $this->cleDeNaissance($a, $famille)
                    <=> $this->cleDeNaissance($b, $famille),
            );
            $groupes[$cle] = $membres;
        }

        $rang = array_flip($ordre);

        usort($ordre, function (string $a, string $b) use ($groupes, $rang): int {
            // **Le quart passe avant tout le reste.** `[ pp | pm ][ TRONC ][ mp | mm ]`
            // est l'ordre demandé par le commanditaire ; il se tient par une clé de
            // tri, pas par des blocs écartés. Rien n'impose d'espace entre deux
            // quarts : ils se touchent, ils ne se traversent pas.
            $qa = $this->quartDuGroupe($groupes[$a]);
            $qb = $this->quartDuGroupe($groupes[$b]);

            if (null !== $qa && null !== $qb && $qa !== $qb) {
                return $qa <=> $qb;
            }

            // **Une fratrie se range sous ses parents ; sa descendance la suit, elle
            // ne la commande pas.** C'est la clé suivante, avant la médiane.
            $pa = $this->ancreDesParents($a);
            $pb = $this->ancreDesParents($b);

            if (null !== $pa && null !== $pb && $pa !== $pb) {
                return $pa <=> $pb;
            }

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

    /**
     * **Où sont les parents de cette fratrie.**
     *
     * C'est la clé qui range les fratries d'une rangée, **avant** la médiane de leurs
     * positions courantes. La différence n'est pas théorique :
     *
     * ── Ce que la médiane seule produisait ──────────────────────────────────────
     *
     * Les positions courantes portent la cascade du moteur. Celui-ci centre bien
     * chaque fratrie sous ses parents ({@see CalculDePlacement::poseNonAscendant()}),
     * **puis** dépose les conjoints « juste à droite de mon partenaire, sans
     * vérifier si quelqu'un y est déjà », **puis** décroche les chevauchements par
     * une seule poussée vers la droite qui ne réordonne jamais et ne ramène jamais
     * personne vers la gauche.
     *
     * Une fratrie qui porte des conjoints est donc plus large, chevauche davantage,
     * et la cascade la déplace à droite. Trier ensuite sur la médiane des positions
     * **ratifiait ce déplacement au lieu de le défaire.**
     *
     * Mesuré sur l'arbre réel, avant correction : sur 46 paires de frères et sœurs
     * ayant tous deux une descendance, **9 avaient l'ordre de leurs enfants inversé
     * par rapport au leur** — et dans 8 cas sur 9, celui qui était devant portait la
     * descendance la plus lourde. Le rapport de poids décidait : au-delà d'un
     * facteur trois environ, le sous-arbre lourd doublait ses cadets.
     *
     * ── Ce que ça ne change pas ────────────────────────────────────────────────
     *
     * La médiane reste la clé de secours : un groupe sans parents placés — les
     * aïeux du haut de l'arbre, les pièces rapportées isolées — garde exactement le
     * comportement précédent. **On ajoute une clé devant, on n'en retire aucune.**
     *
     * Les rangées sont parcourues de haut en bas : quand on ordonne une rangée, les
     * parents de ses fratries sont déjà placés par la passe courante.
     */
    private function ancreDesParents(string $cle): ?float
    {
        if (str_starts_with($cle, 'seul:')) {
            return null;
        }

        $positions = [];

        foreach ($this->epoux[$cle] ?? [] as $epoux) {
            if (isset($this->posx[$epoux])) {
                $positions[] = $this->posx[$epoux];
            }
        }

        if ([] === $positions) {
            return null;
        }

        return array_sum($positions) / \count($positions);
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
     * ── L'année DE QUI, et c'est tout le sujet ──────────────────────────────
     *
     * Un bloc n'est pas une personne : c'est une personne **et son conjoint**, et
     * I6 met l'homme à gauche. Lire `$bloc[0]` revient donc, pour une sœur mariée,
     * à trier la fratrie sur l'année de son **mari** — qui n'en fait pas partie, et
     * dont l'année est souvent inconnue.
     *
     * Mesuré le 20/09/2026 sur l'arbre réel, avant correction : **dix fratries
     * entièrement datables sur trente étaient dans le désordre.** Dans l'une d'elles,
     * l'aînée née en 1820 passait après ses trois frères de 1823, 1825 et 1830, parce
     * que son époux n'a pas de date et tombait donc à 9999.
     *
     * On prend donc l'année du membre **qui est enfant de la famille triée**, et
     * l'on ne retombe sur la tête du bloc que lorsqu'aucune ancre n'est connue.
     *
     * @param list<string> $bloc
     * @param string|null  $famille la fratrie qu'on est en train d'ordonner
     */
    private function cleDeNaissance(array $bloc, ?string $famille = null): string
    {
        $sujet = $bloc[0] ?? '';

        if (null !== $famille) {
            $fratrie = $this->enfants[$famille] ?? [];

            foreach ($bloc as $membre) {
                if (\in_array($membre, $fratrie, true)) {
                    $sujet = $membre;
                    break;
                }
            }
        }

        $annee = $this->annees[$sujet] ?? null;

        return \sprintf('%04d|%s', $annee ?? 9999, $sujet);
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
    /* I9 : le tronc du lecteur */
    /* ------------------------------------------------------------------ */

    /**
     * ════════════════════════════════════════════════════════════════════════════
     * **LE TRONC DU LECTEUR, ISOLÉ.**.
     *
     * ```
     *   [famille du père]      [père]◇[mère]      [famille de la mère]
     *                                │
     *                      ┌─────────┼─────────┐
     *                   [frère]   [lecteur]   [sœur]
     * ```
     *
     * Trois blocs, un couloir. C'est ce que le commanditaire a décrit, mot pour mot,
     * et c'est construit **tel quel** — par translation de blocs rigides, après que
     * la descente a fait son travail — plutôt que dérivé d'une règle générale.
     *
     * ── Pourquoi une passe à part, et pas une contrainte dans la descente ────────
     *
     * La descente au barycentre est exacte sur son objectif, et elle place bien.
     * Elle a un angle mort : rien ne lui interdit de laisser un aïeul paternel
     * franchir la colonne du lecteur. Mesuré sur l'arbre réel : le bloc paternel
     * s'étendait jusqu'à **+466 px**, le lecteur étant à 0 — il vivait *dans* le bloc
     * de son père. Et le couple parent, tiré vers le haut par ses propres parents,
     * ne se posait pas au milieu de sa fratrie.
     *
     * Une réécriture complète en tassement de sous-arbres a été tentée — douze
     * variantes, mesurées — et n'a pas convergé : chacune réparait un défaut en en
     * ouvrant un autre. Celle-ci ne prétend à rien de général. Elle prend l'arbre
     * tel que la descente le rend, déplace **trois blocs** pour qu'ils ne se
     * recouvrent plus, puis **trois nœuds** pour que les parents soient au-dessus de
     * leurs enfants. Rien d'autre ne bouge, et c'est ce qui la rend sûre.
     *
     * ── Les trois blocs ──────────────────────────────────────────────────────────
     *
     * Le **tronc** : tout ce qui est atteignable depuis la fratrie du lecteur sans
     * passer par ses parents. Conjoints, enfants, petits-enfants — et les
     * belles-familles, qui sont les voisins naturels du tronc et restent avec lui.
     *
     * Le **côté paternel** : tout ce qui est atteignable depuis le père sans passer
     * par la mère ni par le tronc. Ses parents, ses frères, leurs descendances, ses
     * autres unions. Le **côté maternel** : pareil depuis la mère, et ce que les deux
     * côtés se disputent — deux cousins mariés — reste au père.
     *
     * Les côtés sont poussés **en bloc**, du minimum qui dégage le couloir, chacun
     * du sien. Leur mise en page interne est intacte.
     * ════════════════════════════════════════════════════════════════════════════
     */
    private function isolerLeTronc(string $egoId): void
    {
        $famille = $this->familleDesParents[$egoId] ?? null;

        if (null === $famille || 2 !== \count($this->epoux[$famille] ?? [])) {
            return;
        }

        [$pere, $mere] = $this->hommeAGauche(...$this->epoux[$famille]);

        if (!isset($this->noeuds[$pere], $this->noeuds[$mere], $this->noeuds[$famille])) {
            return;
        }

        $xs = [];

        foreach ($this->enfants[$famille] ?? [] as $enfant) {
            if (isset($this->posx[$enfant])) {
                $xs[] = $this->posx[$enfant];
            }
        }

        if ([] === $xs) {
            return;
        }

        // ── Qui est de quel côté. L'ordre des trois fouilles est la règle.
        //
        // Les ascendances STRICTES d'abord : un aïeul du père est paternel même si un
        // frère du lecteur a épousé une cousine et qu'on l'atteint par elle. Sans
        // cette priorité, la fouille du tronc remontait par la belle-famille et
        // avalait un morceau du côté paternel — mesuré : un aïeul restait à +466 px,
        // dans le couloir, et le bloc paternel recouvrait le maternel sur 254 px.
        //
        // Le noyau du tronc — la fratrie et ce qui en descend, sans jamais remonter —
        // est interdit aux deux côtés.
        //
        // **Ce commentaire disait que le tronc récupère les belles-familles. C'est
        // faux, et mesuré le 20/09/2026 :** sur l'arbre du commanditaire comme sur
        // l'arbre de référence, `$tronc` fait **trois nœuds**. La fouille part de la
        // fratrie du lecteur et se trouve aussitôt cernée par les deux côtés, qui
        // ont été calculés avant elle. Les belles-familles ne sont donc dans AUCUN
        // des trois ensembles — voir `$detachees` plus bas.
        $parents = [$famille => true, $pere => true, $mere => true];
        $noyau = $this->descendanceStricte($this->enfants[$famille]);
        $aieuxDuPere = $this->ascendanceStricte($pere);
        $aieuxDeLaMere = $this->ascendanceStricte($mere);

        $paternel = $this->atteignableDepuis(
            array_keys($aieuxDuPere + [$pere => true]),
            $parents + $aieuxDeLaMere + $noyau,
        );
        $maternel = $this->atteignableDepuis(
            array_keys($aieuxDeLaMere + [$mere => true]),
            $parents + $paternel + $noyau,
        );
        $tronc = $this->atteignableDepuis($this->enfants[$famille], $parents + $paternel + $maternel);

        // ── Les parents au milieu de leur fratrie, accolés, l'homme à gauche.
        $milieu = (min($xs) + max($xs)) / 2;
        $largeurPere = (float) $this->noeuds[$pere]->w;
        $largeurMere = (float) $this->noeuds[$mere]->w;
        $couple = $largeurPere + MoteurDePlacement::ESPACE + $largeurMere;

        $this->posx[$pere] = $milieu - $couple / 2 + $largeurPere / 2;
        $this->posx[$mere] = $milieu + $couple / 2 - $largeurMere / 2;
        $this->posx[$famille] = $milieu;

        // ── Le couloir : l'emprise du tronc et du couple, toutes rangées confondues.
        //    Toutes rangées, et non rangée par rangée : c'est ce qui fait un couloir
        //    qu'on voit, et non trois blocs qui s'imbriquent.
        $gauche = $this->posx[$pere] - $largeurPere / 2;
        $droite = $this->posx[$mere] + $largeurMere / 2;

        foreach (array_keys($tronc) as $id) {
            if (!isset($this->posx[$id], $this->noeuds[$id])) {
                continue;
            }

            $gauche = min($gauche, $this->posx[$id] - $this->noeuds[$id]->w / 2);
            $droite = max($droite, $this->posx[$id] + $this->noeuds[$id]->w / 2);
        }

        // **Les deux moitiés se lisent sur les rangs, plus sur « paternel » et
        // « maternel ».** Le tronc est au milieu par définition : ce qui a un rang
        // plus petit va à sa gauche, ce qui en a un plus grand à sa droite. Sans
        // conjoint, ça redonne exactement l'arrangement d'avant — père à gauche,
        // mère à droite —, et avec conjoint c'est le lecteur à gauche et lui à droite.
        $aGauche = [];
        $aDroite = [];

        foreach ($this->quarts as $id => $rang) {
            if ($rang >= 0 && $rang < $this->rangDuTronc) {
                $aGauche[$id] = true;
            } elseif ($rang > $this->rangDuTronc) {
                $aDroite[$id] = true;
            }
        }

        if ([] === $this->quarts) {
            $aGauche = $paternel;
            $aDroite = $maternel;
        }

        $this->pousserLeBloc($aGauche, $gauche - MoteurDePlacement::ESPACE, self::VERS_LA_GAUCHE);
        $this->pousserLeBloc($aDroite, $droite + MoteurDePlacement::ESPACE, self::VERS_LA_DROITE);

        // ── CE QUI N'EST RELIÉ À RIEN, ET QUI DOIT QUAND MÊME SE POSER QUELQUE PART.
        //
        // Ce qu'aucun des trois ensembles n'a réclamé. **On a d'abord cru à des
        // belles-familles ; la mesure dit autre chose**, et c'est plus important que
        // le placement : le 20/09/2026, sur l'arbre du commanditaire, ces nœuds
        // forment **trois composantes DÉTACHÉES du reste du graphe**.
        //
        //     composantes connexes : 607 nœuds · 39 · 1 · 1
        //     aucune arête ne relie les 41 au reste — vérifié, zéro
        //
        // Ce ne sont donc pas des parents par alliance mal rangés : ce sont des
        // personnes dont le lien à l'arbre **manque**. Le placement ne peut pas le
        // réparer, et ce commentaire ne prétend pas le contraire.
        //
        // Elles flottaient à **4 161 px à gauche du bloc paternel**, posées là par la
        // seule cascade. On les aligne contre lui :
        // `[ détachées ][ paternel ][ TRONC ][ maternel ]`. Ce n'est pas une place
        // *méritée*, c'est une place *décidée* — et surtout arrêtée, plutôt que
        // laissée au hasard d'une poussée.
        //
        // Sur l'arbre de référence, l'ensemble est **vide** : aucune garde locale ne
        // couvre ce chemin, et il faut le savoir avant de lire un vert.
        $detachees = [];

        foreach (array_keys($this->noeuds) as $id) {
            if (!isset($parents[$id]) && !isset($paternel[$id])
                && !isset($maternel[$id]) && !isset($tronc[$id])) {
                $detachees[$id] = true;
            }
        }

        if ([] === $detachees) {
            return;
        }

        $bordPaternel = $this->bordDuBloc([] === $aGauche ? $paternel : $aGauche, self::VERS_LA_DROITE);

        if (null === $bordPaternel) {
            return;
        }

        $this->pousserLeBloc($detachees, $bordPaternel - MoteurDePlacement::ESPACE, self::VERS_LA_GAUCHE);
    }

    /**
     * Les aïeux d'une personne, et rien qu'eux : on ne remonte que par la famille
     * dont chacun est l'enfant. Les losanges traversés en font partie.
     *
     * @return array<string, true>
     */
    private function ascendanceStricte(string $personne): array
    {
        $vus = [];
        $pile = [$personne];

        while ([] !== $pile) {
            $courant = array_pop($pile);
            $famille = $this->familleDesParents[$courant] ?? null;

            if (null === $famille || isset($vus[$famille])) {
                continue;
            }

            $vus[$famille] = true;

            foreach ($this->epoux[$famille] ?? [] as $parent) {
                if (!isset($vus[$parent])) {
                    $vus[$parent] = true;
                    $pile[] = $parent;
                }
            }
        }

        return $vus;
    }

    /**
     * Ce qui descend de ces personnes, et rien qu'eux : leurs unions, les conjoints
     * de ces unions, leurs enfants, et ainsi de suite — sans jamais remonter vers la
     * famille dont quelqu'un est l'enfant.
     *
     * @param list<string> $departs
     *
     * @return array<string, true>
     */
    private function descendanceStricte(array $departs): array
    {
        $vus = [];
        $file = [];

        foreach ($departs as $depart) {
            if (isset($this->noeuds[$depart])) {
                $vus[$depart] = true;
                $file[] = $depart;
            }
        }

        while ([] !== $file) {
            $courant = array_shift($file);

            if (isset($this->epoux[$courant]) || isset($this->enfants[$courant])) {
                $suivants = array_merge($this->epoux[$courant] ?? [], $this->enfants[$courant] ?? []);
            } else {
                $suivants = [];

                foreach ($this->voisins[$courant] ?? [] as $voisin) {
                    if (isset($this->epoux[$voisin]) && \in_array($courant, $this->epoux[$voisin], true)) {
                        $suivants[] = $voisin;
                    }
                }
            }

            foreach ($suivants as $suivant) {
                if (!isset($vus[$suivant]) && isset($this->noeuds[$suivant])) {
                    $vus[$suivant] = true;
                    $file[] = $suivant;
                }
            }
        }

        return $vus;
    }

    /**
     * Tout ce qui est atteignable depuis ces départs, sans jamais entrer dans un
     * nœud bloqué. Les arêtes sont parcourues dans les deux sens : ce qu'on cherche
     * est un **côté** de l'arbre, pas une direction.
     *
     * @param list<string>        $departs
     * @param array<string, true> $bloques
     *
     * @return array<string, true>
     */
    private function atteignableDepuis(array $departs, array $bloques): array
    {
        $vus = [];
        $file = [];

        foreach ($departs as $depart) {
            if (!isset($bloques[$depart]) && isset($this->noeuds[$depart])) {
                $vus[$depart] = true;
                $file[] = $depart;
            }
        }

        while ([] !== $file) {
            $courant = array_shift($file);

            foreach ($this->voisins[$courant] ?? [] as $voisin) {
                if (isset($vus[$voisin]) || isset($bloques[$voisin]) || !isset($this->noeuds[$voisin])) {
                    continue;
                }

                $vus[$voisin] = true;
                $file[] = $voisin;
            }
        }

        return $vus;
    }

    /**
     * **Le bord d'un bloc dans le sens où on le pousse** : son extrémité droite
     * quand on l'écarte vers la gauche, son extrémité gauche dans l'autre cas.
     *
     * C'est ce bord-là qui touche le voisin, et donc le seul qui décide.
     *
     * @param array<string, true> $bloc
     */
    private function bordDuBloc(array $bloc, int $sens): ?float
    {
        $bord = null;

        foreach (array_keys($bloc) as $id) {
            if (!isset($this->posx[$id], $this->noeuds[$id])) {
                continue;
            }

            $demi = $this->noeuds[$id]->w / 2;
            $candidat = self::VERS_LA_GAUCHE === $sens ? $this->posx[$id] + $demi : $this->posx[$id] - $demi;

            $bord = null === $bord
                ? $candidat
                : (self::VERS_LA_GAUCHE === $sens ? max($bord, $candidat) : min($bord, $candidat));
        }

        return $bord;
    }

    /**
     * **Aligne un bloc entier sur une limite** — son bord droit dessus quand on
     * l'écarte vers la gauche, son bord gauche dans l'autre cas.
     *
     * @param array<string, true> $bloc
     */
    private function pousserLeBloc(array $bloc, float $limite, int $sens): void
    {
        $bord = $this->bordDuBloc($bloc, $sens);

        if (null === $bord) {
            return;
        }

        // **Un ALIGNEMENT, et non une poussée.** Le `min(0.0, …)` qui était ici
        // n'écartait que dans un sens : un bloc déjà trop loin y restait, et rien
        // ne le ramenait jamais. Mesuré le 20/09/2026 sur l'arbre du commanditaire,
        // après le correctif du tri des fratries : le bloc paternel s'était arrêté
        // à **2 693 px** de la place que cette ligne lui assigne, et les 268 fiches
        // qu'il porte avec lui ont emmené le trait du tronc de 3 288 à 5 879 px.
        //
        // **Ce que l'alignement ne peut pas casser, et c'est un argument exact :**
        // le bord droit du bloc paternel finit à `gauche - ESPACE`, où `gauche` est
        // le minimum sur TOUT le tronc. Aucun nœud paternel ne peut donc toucher un
        // nœud du tronc, sur aucune rangée. Le bloc maternel est de l'autre côté.
        // Restaient les nœuds qui n'appartiennent à aucun des trois ensembles :
        // mesuré, ils sont 41 sur 644, et **aucun** ne se tient dans l'intervalle
        // que le bloc traverse.
        $decalage = $limite - $bord;

        if (0.0 === $decalage) {
            return;
        }

        foreach (array_keys($bloc) as $id) {
            if (isset($this->posx[$id])) {
                $this->posx[$id] += $decalage;
            }
        }
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
