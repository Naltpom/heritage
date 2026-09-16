<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Tree\ValueObject\AncrageEnAttente;
use App\Module\Tree\ValueObject\AreteDePlacement;
use App\Module\Tree\ValueObject\BoiteEnglobante;
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
            $pose = $this->pose($noeuds, $partage, $attente);

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
     *
     * @return array{0: array<string, NoeudPlace>, 1: list<AreteDePlacement>}|null
     */
    private function pose(array $noeuds, Placement $partage, NoeudEnAttente $attente): ?array
    {
        $cible = $noeuds[$attente->ancrage->cibleId] ?? null;

        if (null === $cible) {
            return null;
        }

        $largeur = $this->mesure->largeur($attente->personne);
        $y = (float) ($attente->personne->generation * MoteurDePlacement::H_RANG);

        [$vise, $aretes, $poseLosange] = match ($attente->ancrage->genre) {
            AncrageEnAttente::ENFANT_DE_FAMILLE => $this->ancreCommeEnfant($noeuds, $partage, $attente, $cible, $largeur),
            AncrageEnAttente::CONJOINT_DE => $this->ancreCommeConjoint($attente, $cible, $largeur),
            AncrageEnAttente::PARENT_DE => $this->ancreCommeParent($noeuds, $partage, $attente, $cible, $largeur, $y),
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
     *
     * @return array{0: float, 1: list<AreteDePlacement>, 2: (\Closure(float): NoeudPlace)|null}
     */
    private function ancreCommeEnfant(array $noeuds, Placement $partage, NoeudEnAttente $attente, NoeudPlace $famille, int $largeur): array
    {
        $bordDroit = null;

        foreach ($this->enfantsDe($partage, $famille->id) as $enfantId) {
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
     * Conjoint d'une personne existante : accolé à sa droite, par la formule même du
     * repli de la phase 2 du moteur.
     *
     * Désigner un conjoint **crée une famille** (§ 6.4) — mais seulement à la validation.
     * En attendant, on pose un losange provisoire au barycentre du couple, 26 px sous
     * leur rangée, pour que le lien se dessine : il n'existe **pas** d'arête directe
     * personne → personne dans ce graphe, et en introduire une pour les seuls nœuds en
     * attente obligerait le viewer à connaître deux modèles d'arête.
     *
     * @return array{0: float, 1: list<AreteDePlacement>, 2: (\Closure(float): NoeudPlace)|null}
     */
    private function ancreCommeConjoint(NoeudEnAttente $attente, NoeudPlace $conjoint, int $largeur): array
    {
        $x = $conjoint->x + $conjoint->w / 2 + MoteurDePlacement::ESPACE + $largeur / 2;

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
     *
     * @return array{0: float, 1: list<AreteDePlacement>, 2: (\Closure(float): NoeudPlace)|null}
     */
    private function ancreCommeParent(array $noeuds, Placement $partage, NoeudEnAttente $attente, NoeudPlace $enfant, int $largeur, float $y): array
    {
        $familleId = $this->familleDe($partage, $enfant->id);

        if (null !== $familleId && isset($noeuds[$familleId])) {
            $x = $noeuds[$familleId]->x;

            foreach ($this->epouxDe($partage, $familleId) as $epouxId) {
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
     * On ne parcourt que les arêtes d'origine : un nœud en attente ne doit jamais servir
     * d'ancre à un autre.
     *
     * @return list<string>
     */
    private function enfantsDe(Placement $partage, string $familleId): array
    {
        $sortie = [];

        foreach ($partage->aretes as $arete) {
            if (AreteDePlacement::GENRE_FILIATION === $arete->genre && $arete->de === $familleId) {
                $sortie[] = $arete->vers;
            }
        }

        return $sortie;
    }

    /** @return list<string> */
    private function epouxDe(Placement $partage, string $familleId): array
    {
        $sortie = [];

        foreach ($partage->aretes as $arete) {
            if (AreteDePlacement::GENRE_UNION === $arete->genre && $arete->vers === $familleId) {
                $sortie[] = $arete->de;
            }
        }

        return $sortie;
    }

    /** La famille dont `$personneId` est l'enfant, s'il en a une. */
    private function familleDe(Placement $partage, string $personneId): ?string
    {
        foreach ($partage->aretes as $arete) {
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
