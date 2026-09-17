<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Tree\ValueObject\AncrageEnAttente;
use App\Module\Tree\ValueObject\AreteDePlacement;
use App\Module\Tree\ValueObject\BoiteEnglobante;
use App\Module\Tree\ValueObject\IdentifiantDeNoeud;
use App\Module\Tree\ValueObject\NoeudEnAttente;
use App\Module\Tree\ValueObject\NoeudPlace;
use App\Module\Tree\ValueObject\Placement;

/**
 * **L'étage 2 du cache** : la projection du placement partagé pour un lecteur donné
 * (ADR 0005, § 9.9 du modèle de données).
 *
 * Elle prend l'objet de l'étage 1 — commun à tous, calculé sans filtre de
 * confidentialité — et y **ajoute** les nœuds « en attente » du lecteur. Elle ne
 * recalcule rien et ne relit pas la base : toute la topologie dont elle a besoin est
 * déjà dans les arêtes du placement.
 *
 * ## Deux choses à ne pas défaire
 *
 * **1. L'insertion est locale, jamais un recalcul.** C'est la décision qui rend l'étage 1
 * partageable, donc tout le cache à deux étages possible. Un nœud en attente décale les
 * **seuls voisins immédiats de sa propre rangée**, vers la droite, et rien d'autre : ni
 * les autres rangées, ni les losanges, ni l'ordre de qui que ce soit. La conséquence
 * assumée est qu'un nœud en attente peut être légèrement mal placé — frôler une arête
 * lointaine, par exemple. C'est le prix pour que le graphe reste un objet partagé, et
 * c'est visuellement acceptable pour un nœud qui porte déjà un traitement distinct.
 *
 * **2. Cette projection n'est jamais mise en cache.** Elle coûte un parcours de 377 nœuds
 * et quelques insertions. Une clé par lecteur ne serait pas seulement coûteuse, elle
 * serait **fausse** : elle ne changerait qu'avec l'empreinte de l'arbre, c'est-à-dire à
 * une modification *validée* — or déposer un brouillon n'en est pas une
 * (`prp_draft_person` n'est pas une table `tree_*`). L'auteur d'une proposition
 * garderait son entrée antérieure et ne verrait pas son propre nœud en attente,
 * c'est-à-dire la seule chose que ce calque existe pour produire.
 *
 * ## Ce que la projection ne fait pas
 *
 * Elle n'applique **aucun filtre de confidentialité**, et ce n'est pas un oubli : un
 * `Placement` ne porte que des identifiants et des coordonnées, pas un seul champ
 * personnel. Le cercle de visibilité masque des champs, pas des nœuds (ADR 0003) — il
 * s'applique donc dans la **sérialisation de la fiche**, pas ici. C'est exactement ce
 * qui rend l'étage 1 partageable ; si un jour on masquait des nœuds, il y aurait un
 * placement par compte et cet étage n'existerait plus.
 */
final class ProjecteurDePlacement
{
    public function __construct(private readonly MesureDeFiche $mesure = new MesureDeFiche())
    {
    }

    /**
     * @param list<NoeudEnAttente> $enAttente les demandes **du lecteur**, jamais toutes :
     *                                        un nœud en attente n'est visible que de son
     *                                        auteur et des administrateurs (§ 6.4)
     */
    public function projette(Placement $partage, array $enAttente): Placement
    {
        if ([] === $enAttente) {
            // Le cas de loin le plus fréquent : l'immense majorité des lecteurs n'a
            // aucune demande en cours et reçoit l'objet partagé tel quel.
            return $partage;
        }

        $noeuds = $partage->noeuds;
        $aretes = $partage->aretes;

        foreach ($enAttente as $attente) {
            // Les arêtes passées sont **celles du moment**, arêtes provisoires comprises.
            // Voir {@see enfantsDe()} pour ce que cela change et pourquoi l'inverse était
            // faux : c'est ce qui permet à une fiche proposée d'en ancrer une autre.
            $pose = $this->pose($noeuds, $aretes, $attente);

            if (null === $pose) {
                // Ancrage introuvable : la cible a pu disparaître entre le dépôt de la
                // demande et l'affichage. On omet le nœud plutôt que de le poser à
                // `x = 0`, où il se superposerait à l'ego.
                continue;
            }

            [$noeuds, $nouvellesAretes] = $pose;
            $aretes = array_merge($aretes, $nouvellesAretes);
        }

        $noeuds = $this->recentreSurLEgo($noeuds, $partage->egoId);

        return new Placement($noeuds, $aretes, $this->boiteEnglobante($noeuds), $partage->egoId);
    }

    /**
     * @param array<string, NoeudPlace> $noeuds
     * @param list<AreteDePlacement>    $aretesConnues arêtes validées **et** provisoires déjà posées
     *
     * @return array{0: array<string, NoeudPlace>, 1: list<AreteDePlacement>}|null
     */
    private function pose(array $noeuds, array $aretesConnues, NoeudEnAttente $attente): ?array
    {
        $cible = $noeuds[$attente->ancrage->cibleId] ?? null;

        if (null === $cible) {
            return null;
        }

        $largeur = $this->mesure->largeur($attente->personne);
        $y = (float) ($attente->personne->generation * MoteurDePlacement::H_RANG);

        [$vise, $aretes, $poseLosange] = match ($attente->ancrage->genre) {
            AncrageEnAttente::ENFANT_DE_FAMILLE => $this->ancreCommeEnfant($noeuds, $aretesConnues, $attente, $cible, $largeur),
            AncrageEnAttente::ENFANT_DE_PARENT => $this->ancreCommeEnfantDUnParent($noeuds, $aretesConnues, $attente, $cible, $largeur),
            AncrageEnAttente::CONJOINT_DE => $this->ancreCommeConjoint($noeuds, $aretesConnues, $attente, $cible, $largeur),
            AncrageEnAttente::PARENT_DE => $this->ancreCommeParent($noeuds, $aretesConnues, $attente, $cible, $largeur, $y),
        };

        // La rangée se partage en deux autour de l'abscisse visée, et chaque nœud tombe
        // dans exactement un des deux camps — c'est ce qui garantit qu'aucun n'est
        // oublié. Le camp de gauche ne bouge **jamais** : c'est le nouveau venu qui
        // s'écarte. Le camp de droite, lui, est poussé : un conjoint doit rester accolé
        // à la personne qu'il épouse, pas être relégué derrière le voisin suivant.
        $x = $this->ecarteDuVoisinDeGauche($noeuds, $vise, $largeur, $y);

        $noeuds[$attente->id()] = new NoeudPlace($attente->id(), $x, $y, $largeur, MoteurDePlacement::H_FICHE);

        if (null !== $poseLosange) {
            // Le losange se calcule sur l'abscisse **définitive**, après l'écart : sinon
            // il pointerait à côté du couple qu'il joint.
            $losange = $poseLosange($x);
            $noeuds[$losange->id] = $losange;
        }

        return [$this->ecarteLesVoisinsDeDroite($noeuds, $attente->id(), $vise), $aretes];
    }

    /**
     * Enfant d'une famille existante : on se pose **à la droite de la fratrie**, sans
     * retrier personne.
     *
     * Le moteur, lui, recentrerait toute la fratrie sous le couple parental. Le refaire
     * ici déplacerait des nœuds validés dans le graphe d'un seul lecteur : c'est
     * exactement la réorganisation que l'ADR 0005 interdit.
     *
     * @param array<string, NoeudPlace> $noeuds
     * @param list<AreteDePlacement>    $aretesConnues
     *
     * @return array{0: float, 1: list<AreteDePlacement>, 2: (\Closure(float): NoeudPlace)|null}
     */
    private function ancreCommeEnfant(array $noeuds, array $aretesConnues, NoeudEnAttente $attente, NoeudPlace $famille, int $largeur): array
    {
        $bordDroit = null;

        foreach ($this->enfantsDe($aretesConnues, $famille->id) as $enfantId) {
            $enfant = $noeuds[$enfantId] ?? null;

            if (null !== $enfant) {
                $bordDroit = null === $bordDroit ? $enfant->droite() : max($bordDroit, $enfant->droite());
            }
        }

        // Une famille sans enfant placé : sous le losange, à son abscisse.
        $x = null !== $bordDroit
            ? $bordDroit + MoteurDePlacement::ESPACE + $largeur / 2
            : $famille->x;

        return [$x, [AreteDePlacement::filiation($famille->id, $attente->id())], null];
    }

    /**
     * Enfant d'une **personne** : le demandeur a nommé un parent, pas un couple.
     *
     * Deux cas, et c'est ici — et nulle part ailleurs — qu'ils se départagent, parce que
     * seule cette classe tient les arêtes :
     *
     * - le parent a une famille : l'enfant rejoint cette fratrie, exactement comme s'il
     *   avait désigné le couple. Le résultat est **le même au millième** que par
     *   {@see AncrageEnAttente::ENFANT_DE_FAMILLE}, et c'est voulu : le demandeur a
     *   nommé une personne plutôt qu'un couple, il n'a pas décrit un autre arbre ;
     * - le parent n'en a pas : on pose un losange provisoire **sous lui** et l'enfant
     *   dessous. C'est ce que la validation ferait — « désigner un parent crée une
     *   famille » (§ 6.4). Auparavant le nœud était écarté sous le motif `sans_couple` :
     *   la réponse était exacte mais elle privait le demandeur de la seule chose que ce
     *   calque existe pour produire, la vue de ce qu'il vient de proposer.
     *
     * Le losange porte l'identifiant provisoire **de l'enfant** (`#12/p3#union`), comme
     * dans le cas du parent seul plus bas : ce n'est pas le nom du couple, c'est un
     * identifiant de rendu, et la sérialisation le renomme en `#12/fN`.
     *
     * @param array<string, NoeudPlace> $noeuds
     * @param list<AreteDePlacement>    $aretesConnues
     *
     * @return array{0: float, 1: list<AreteDePlacement>, 2: (\Closure(float): NoeudPlace)|null}
     */
    private function ancreCommeEnfantDUnParent(array $noeuds, array $aretesConnues, NoeudEnAttente $attente, NoeudPlace $parent, int $largeur): array
    {
        foreach ($aretesConnues as $arete) {
            if (AreteDePlacement::GENRE_UNION !== $arete->genre || $arete->de !== $parent->id) {
                continue;
            }

            $famille = $noeuds[$arete->vers] ?? null;

            if (null !== $famille) {
                return $this->ancreCommeEnfant($noeuds, $aretesConnues, $attente, $famille, $largeur);
            }
        }

        return [
            $parent->x,
            [
                AreteDePlacement::union($parent->id, $attente->familleProvisoireId(), false),
                AreteDePlacement::filiation($attente->familleProvisoireId(), $attente->id()),
            ],
            // Le losange se pose sous la rangée du **parent**, pas sous celle de l'enfant :
            // c'est la rangée des époux qui le porte, partout ailleurs dans ce fichier.
            fn (float $xFinal): NoeudPlace => $this->losangeProvisoire($attente, ($parent->x + $xFinal) / 2, $parent->y),
        ];
    }

    /**
     * Conjoint d'une personne existante : accolé à sa droite, par la formule même du
     * repli de la phase 2 du moteur.
     *
     * Désigner un conjoint **crée une famille** (§ 6.4) — mais seulement à la validation.
     * En attendant, on pose un losange provisoire au barycentre du couple, 26 px sous
     * leur rangée, pour que le lien se dessine : il n'existe **pas** d'arête directe
     * personne → personne dans ce graphe, et en introduire une pour les seuls nœuds en
     * attente obligerait le viewer à connaître deux modèles d'arête.
     *
     * ── Sauf si le conjoint en a déjà un, tout neuf et seul dedans ─────────────
     *
     * La forme la plus courante de la file dépose les deux parents d'une même personne :
     * `#p1` se pose d'abord comme parent de l'enfant, ce qui lui crée un losange avec la
     * filiation dessous ; `#p2` arrive ensuite comme conjointe de `#p1`. En lui créant
     * son propre losange, le couple en portait **deux** — l'un les joignant, l'autre
     * tenant l'enfant sous un seul parent. C'est-à-dire deux unions dessinées là où il
     * n'y en a qu'une.
     *
     * On rejoint donc le losange existant, sous deux conditions strictes : il est
     * **inventé par cette projection**, pas une famille validée de l'arbre, et il
     * n'a **qu'un** époux. Un remariage ne doit pas absorber la nouvelle conjointe dans
     * l'ancien couple, et un losange validé n'appartient pas à cette projection.
     *
     * @param array<string, NoeudPlace> $noeuds
     * @param list<AreteDePlacement>    $aretesConnues
     *
     * @return array{0: float, 1: list<AreteDePlacement>, 2: (\Closure(float): NoeudPlace)|null}
     */
    private function ancreCommeConjoint(array $noeuds, array $aretesConnues, NoeudEnAttente $attente, NoeudPlace $conjoint, int $largeur): array
    {
        $x = $conjoint->x + $conjoint->w / 2 + MoteurDePlacement::ESPACE + $largeur / 2;

        foreach ($aretesConnues as $arete) {
            if (AreteDePlacement::GENRE_UNION !== $arete->genre || $arete->de !== $conjoint->id) {
                continue;
            }

            // « Pas une famille validée » plutôt que « provisoire » : `estFamille()` reconnaît
            // la forme `f<N>`, celle des losanges de l'arbre. Tout le reste a nécessairement
            // été inventé par cette projection, quelle que soit la convention de nommage des
            // fiches proposées — c'est la question qu'on veut poser, et elle ne dépend
            // d'aucun préfixe que `Proposal` pourrait changer.
            if (IdentifiantDeNoeud::estFamille($arete->vers) || !isset($noeuds[$arete->vers])) {
                continue;
            }

            if (1 !== \count($this->epouxDe($aretesConnues, $arete->vers))) {
                continue;
            }

            return [$x, [AreteDePlacement::union($attente->id(), $arete->vers, false)], null];
        }

        return [
            $x,
            [
                AreteDePlacement::union($conjoint->id, $attente->familleProvisoireId(), false),
                AreteDePlacement::union($attente->id(), $attente->familleProvisoireId(), false),
            ],
            fn (float $xFinal): NoeudPlace => $this->losangeProvisoire(
                $attente,
                ($conjoint->x + $xFinal) / 2,
                $conjoint->y,
            ),
        ];
    }

    /**
     * Parent d'une personne existante. Deux cas, et le premier est le plus fréquent :
     *
     * - l'enfant a **déjà** une famille : le parent proposé rejoint ce losange, accolé à
     *   l'époux déjà en place s'il y en a un sur la même rangée — c'est le complément
     *   d'une fiche (« on connaît enfin sa mère »), pas une nouvelle union ;
     * - l'enfant n'a aucune famille : on crée un losange provisoire au-dessus de lui.
     *
     * @param array<string, NoeudPlace> $noeuds
     * @param list<AreteDePlacement>    $aretesConnues
     *
     * @return array{0: float, 1: list<AreteDePlacement>, 2: (\Closure(float): NoeudPlace)|null}
     */
    private function ancreCommeParent(array $noeuds, array $aretesConnues, NoeudEnAttente $attente, NoeudPlace $enfant, int $largeur, float $y): array
    {
        $familleId = $this->familleDe($aretesConnues, $enfant->id);

        if (null !== $familleId && isset($noeuds[$familleId])) {
            $x = $noeuds[$familleId]->x;

            foreach ($this->epouxDe($aretesConnues, $familleId) as $epouxId) {
                $epoux = $noeuds[$epouxId] ?? null;

                if (null !== $epoux && $epoux->y === $y) {
                    $x = $epoux->x + $epoux->w / 2 + MoteurDePlacement::ESPACE + $largeur / 2;
                    break;
                }
            }

            return [$x, [AreteDePlacement::union($attente->id(), $familleId, false)], null];
        }

        return [
            $enfant->x,
            [
                AreteDePlacement::union($attente->id(), $attente->familleProvisoireId(), false),
                AreteDePlacement::filiation($attente->familleProvisoireId(), $enfant->id),
            ],
            // Parent seul : le losange se pose à son aplomb, entre lui et l'enfant.
            fn (float $xFinal): NoeudPlace => $this->losangeProvisoire($attente, $xFinal, $y),
        ];
    }

    /** Le losange du moteur : 22 × 14, 26 px sous la rangée des époux. */
    private function losangeProvisoire(NoeudEnAttente $attente, float $x, float $yDesEpoux): NoeudPlace
    {
        return new NoeudPlace(
            $attente->familleProvisoireId(),
            $x,
            $yDesEpoux + MoteurDePlacement::H_FICHE / 2 + MoteurDePlacement::DESCENTE_NOEUD_FAMILLE,
            MoteurDePlacement::LOSANGE_L,
            MoteurDePlacement::LOSANGE_H,
        );
    }

    /**
     * Ce que le nouveau venu doit céder à sa gauche : les nœuds validés **strictement à
     * gauche** de l'abscisse visée ne bougent pas, c'est lui qui s'écarte.
     *
     * Bouger un nœud provisoire ne coûte rien ; bouger un nœud validé se voit. À défaut,
     * un nœud en attente posé trop près de son voisin de gauche le chevaucherait sans
     * que personne ne le pousse — la poussée ne va que vers la droite.
     *
     * @param array<string, NoeudPlace> $noeuds
     */
    private function ecarteDuVoisinDeGauche(array $noeuds, float $vise, int $largeur, float $y): float
    {
        $x = $vise;

        foreach ($noeuds as $noeud) {
            if ($noeud->y !== $y || MoteurDePlacement::H_FICHE !== $noeud->h || $noeud->x >= $vise) {
                continue;
            }

            $minimum = $noeud->x + $noeud->w / 2 + MoteurDePlacement::ESPACE + $largeur / 2;

            if ($x < $minimum) {
                $x = $minimum;
            }
        }

        return $x;
    }

    /**
     * La poussée locale : les nœuds de la même rangée qui étaient **à l'abscisse visée
     * ou à sa droite** sont décalés vers la droite, en cascade et sans jamais changer
     * leur ordre.
     *
     * Le seuil est celui **visé**, pas celui retenu après l'écart de gauche : c'est ce
     * qui partage la rangée en deux camps disjoints et garantit qu'aucun nœud n'échappe
     * aux deux. Un `>` strict à la place du `>=` laissait passer le cas exact où deux
     * nœuds tombent à la même abscisse — c'est-à-dire précisément celui où ils se
     * superposent.
     *
     * La cascade s'arrête au premier voisin qui a déjà la place : c'est ce qui la rend
     * locale plutôt que globale. Les losanges ne sont jamais poussés — ils vivent 26 px
     * sous les fiches, donc sur une autre rangée, et le moteur d'origine ne les décroche
     * pas non plus.
     *
     * @param array<string, NoeudPlace> $noeuds
     *
     * @return array<string, NoeudPlace>
     */
    private function ecarteLesVoisinsDeDroite(array $noeuds, string $nouveauId, float $vise): array
    {
        $nouveau = $noeuds[$nouveauId];
        $rangee = [];

        foreach ($noeuds as $noeud) {
            if ($noeud->id !== $nouveauId
                && $noeud->y === $nouveau->y
                && MoteurDePlacement::H_FICHE === $noeud->h
                && $noeud->x >= $vise
            ) {
                $rangee[] = $noeud;
            }
        }

        usort($rangee, static fn (NoeudPlace $a, NoeudPlace $b): int => $a->x <=> $b->x);

        $precedent = $nouveau;

        foreach ($rangee as $voisin) {
            $minimum = $precedent->x + $precedent->w / 2 + MoteurDePlacement::ESPACE + $voisin->w / 2;

            if ($voisin->x >= $minimum) {
                break;
            }

            $voisin = $voisin->decaleDe($minimum - $voisin->x);
            $noeuds[$voisin->id] = $voisin;
            $precedent = $voisin;
        }

        return $noeuds;
    }

    /**
     * L'ego doit rester à `x = 0` : c'est le point de lecture du viewer, et son
     * `viewBox` initial s'y adosse.
     *
     * Une translation **rigide** n'est pas une réorganisation — elle ne change aucune
     * position relative. Elle n'est nécessaire que si un nœud en attente a été inséré à
     * gauche de l'ego sur sa propre rangée et l'a poussé, ce qui arrive dès qu'on
     * propose un frère ou une sœur à l'ego.
     *
     * @param array<string, NoeudPlace> $noeuds
     *
     * @return array<string, NoeudPlace>
     */
    private function recentreSurLEgo(array $noeuds, string $egoId): array
    {
        $ego = $noeuds[$egoId] ?? null;

        if (null === $ego || 0.0 === $ego->x) {
            return $noeuds;
        }

        $dx = -$ego->x;
        $recentres = [];

        foreach ($noeuds as $noeud) {
            $recentres[$noeud->id] = $noeud->decaleDe($dx);
        }

        return $recentres;
    }

    /**
     * La topologie se relit dans les **arêtes du placement**, pas dans la base ni dans
     * la géométrie des rangées : les arêtes sont exactes, la géométrie ne l'est
     * qu'approximativement et deux familles peuvent partager une rangée.
     *
     * ── Ce qui a changé, et pourquoi la règle d'avant était le défaut ───────────────
     *
     * Ces trois lecteurs ne parcouraient que les arêtes **d'origine**, sous la règle
     * « un nœud en attente ne doit jamais servir d'ancre à un autre ». Elle paraissait
     * prudente ; elle décrivait en réalité la moitié manquante du calque. Une branche se
     * propose en bloc — « sa mère, puis le père de sa mère » —, et tout ce qui pendait
     * derrière la première fiche tombait : mesuré, huit nœuds sur trente et un.
     *
     * Ils reçoivent donc la liste **du moment**, arêtes provisoires comprises. Trois
     * choses rendent le renversement sûr :
     *
     * - l'objet partagé n'est jamais touché. `projette()` rend `$partage` tel quel quand
     *   le lecteur n'a aucune demande, et travaille sinon sur des copies. L'étage 1 reste
     *   commun à tous, ce qui est la condition de l'ADR 0005 ;
     * - l'ordre est **acquis avant d'arriver ici**. {@see PropositionsProjetees} résout
     *   les ancrages par vagues et rend les nœuds dans l'ordre où ils se posent : une
     *   fiche ancre est toujours passée avant celles qui pendent à elle. Un nœud dont
     *   l'ancre n'est pas encore là n'est pas différé, il est écarté — comme avant ;
     * - un cycle ne peut pas boucler : chaque fiche n'est posée qu'une fois, dans la
     *   boucle `foreach` de `projette()`, et rien ne revient en arrière.
     *
     * Le gain accessoire vaut d'être noté : deux parents proposés pour le même enfant
     * partagent désormais **un seul losange**, parce que le second retrouve par
     * `familleDe()` la filiation provisoire que le premier vient de poser. Auparavant ils
     * en auraient créé deux — c'est-à-dire auraient affiché deux unions là où il n'y en a
     * qu'une.
     *
     * @param list<AreteDePlacement> $aretes
     *
     * @return list<string>
     */
    private function enfantsDe(array $aretes, string $familleId): array
    {
        $sortie = [];

        foreach ($aretes as $arete) {
            if (AreteDePlacement::GENRE_FILIATION === $arete->genre && $arete->de === $familleId) {
                $sortie[] = $arete->vers;
            }
        }

        return $sortie;
    }

    /**
     * @param list<AreteDePlacement> $aretes
     *
     * @return list<string>
     */
    private function epouxDe(array $aretes, string $familleId): array
    {
        $sortie = [];

        foreach ($aretes as $arete) {
            if (AreteDePlacement::GENRE_UNION === $arete->genre && $arete->vers === $familleId) {
                $sortie[] = $arete->de;
            }
        }

        return $sortie;
    }

    /**
     * La famille dont `$personneId` est l'enfant, s'il en a une.
     *
     * @param list<AreteDePlacement> $aretes
     */
    private function familleDe(array $aretes, string $personneId): ?string
    {
        foreach ($aretes as $arete) {
            if (AreteDePlacement::GENRE_FILIATION === $arete->genre && $arete->vers === $personneId) {
                return $arete->de;
            }
        }

        return null;
    }

    /** @param array<string, NoeudPlace> $noeuds */
    private function boiteEnglobante(array $noeuds): BoiteEnglobante
    {
        if ([] === $noeuds) {
            return new BoiteEnglobante(0.0, 0.0, 0.0, 0.0);
        }

        $xMin = $xMax = $yMin = $yMax = null;

        foreach ($noeuds as $noeud) {
            $xMin = null === $xMin ? $noeud->gauche() : min($xMin, $noeud->gauche());
            $xMax = null === $xMax ? $noeud->droite() : max($xMax, $noeud->droite());
            $yMin = null === $yMin ? $noeud->haut() : min($yMin, $noeud->haut());
            $yMax = null === $yMax ? $noeud->bas() : max($yMax, $noeud->bas());
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
