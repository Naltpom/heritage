<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Security\ResolutionDuSujetInterface;
use App\Module\Media\Security\SujetResolu;
use App\Module\Tree\Entity\Citation;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Repository\CitationRepository;
use App\Module\Tree\Repository\FamilyRepository;
use App\Module\Tree\Repository\PersonRepository;

/**
 * L'implémentation, côté `Tree`, de {@see ResolutionDuSujetInterface} — « cette ligne
 * existe-t-elle, sous quel libellé, et **de quelles personnes dépend-elle** ? ».
 *
 * Elle ne pose **aucune question de confidentialité**, et c'est ce qui la distingue de
 * `Media\Security\VisibiliteDesSujetsInterface`. Elle décrit la donnée ; c'est
 * `VisibiliteParLeCercle` qui décide, en interrogeant le contrat du noyau sur les
 * personnes qu'on lui nomme ici. La réponse ne dépend donc **pas du lecteur** : c'est une
 * propriété de l'arbre, pas de qui le regarde.
 *
 * ## La règle qui décide de tout : rendre **toutes** les personnes engagées
 *
 * L'appelant conclut « visible **si toutes** le sont », et le motif va contre
 * l'intuition : *une preuve d'union en dit autant sur l'un des époux que sur l'autre*.
 * « Visible si au moins un l'est » publierait la photo de mariage de l'époux masqué sous
 * prétexte que sa femme est dans le cercle du lecteur.
 *
 * **Une personne oubliée ici est une fuite là-bas**, et elle ne se verrait pas : la
 * réponse resterait plausible. D'où les jointures explicites plus bas, et d'où le fait
 * qu'une citation remonte jusqu'aux **deux** époux quand elle étaye une famille.
 *
 * ## Un sujet qui n'engage personne est fermé
 *
 * Une famille sans époux résolu, une citation orpheline : on rend une liste **vide**, et
 * `VisibiliteParLeCercle::autorise()` la refuse explicitement. Ce n'est pas un effet de
 * bord heureux, c'est le comportement voulu — traiter « rien à protéger » comme « ouvert »
 * ferait d'un bogue de ce module une fuite de données de personnes vivantes.
 *
 * ## Le libellé
 *
 * Il part dans `med_media.subject_label`, une colonne dénormalisée qui vit **hors** du
 * normaliseur portant le cercle — la « huitième porte » du § 11.3 du contrat. Il est
 * protégé par le même mécanisme que le reste : la visibilité du sujet, donc celle des
 * personnes rendues ici. C'est pourquoi le libellé d'une union nomme **les deux** époux et
 * que les deux figurent dans `personIds` : le libellé et sa protection ne doivent jamais
 * porter sur des ensembles différents.
 */
final class ResolutionDesSujetsMedia implements ResolutionDuSujetInterface
{
    public function __construct(
        private readonly PersonRepository $personnes,
        private readonly FamilyRepository $familles,
        private readonly CitationRepository $citations,
    ) {
    }

    public function resoudre(MediaSubject $kind, int $id): ?SujetResolu
    {
        return match ($kind) {
            MediaSubject::Personne => $this->personne($id),
            MediaSubject::Famille => $this->famille($id),
            MediaSubject::Citation => $this->citation($id),
        };
    }

    private function personne(int $id): ?SujetResolu
    {
        $personne = $this->personnes->find($id);

        return null === $personne ? null : $this->depuisUnePersonne($personne);
    }

    private function famille(int $id): ?SujetResolu
    {
        $famille = $this->familles->find($id);

        return null === $famille ? null : $this->depuisUneFamille($famille);
    }

    /**
     * Une citation suit **ce qu'elle étaye**, et elle peut l'étayer de trois façons : une
     * personne, une famille, ou un fait — lequel appartient lui-même à l'une ou l'autre.
     *
     * Le troisième cas est celui qu'on oublie. Une citation rattachée à un `tree_fact`
     * — « profession : chainetier, Sorbière, 1957 » — ne porte ni `person_id` ni
     * `family_id`, et une résolution qui s'arrêterait aux deux premiers rendrait une liste
     * vide. Ce serait fermé, donc sans fuite, mais la preuve deviendrait invisible de son
     * propre sujet.
     */
    private function citation(int $id): ?SujetResolu
    {
        $citation = $this->citations->find($id);

        if (!$citation instanceof Citation) {
            return null;
        }

        $personne = $citation->getPerson() ?? $citation->getFact()?->getPerson();

        if (null !== $personne) {
            return $this->depuisUnePersonne($personne);
        }

        $famille = $citation->getFamily() ?? $citation->getFact()?->getFamily();

        if (null !== $famille) {
            return $this->depuisUneFamille($famille);
        }

        // La citation existe mais n'étaye rien de résoluble : on la décrit sans engager
        // personne, ce que l'appelant lit comme « masqué ». On ne rend surtout pas `null`,
        // qui voudrait dire « n'existe pas » et empêcherait d'en diagnostiquer la cause.
        return new SujetResolu($citation->getSource()->getTitle(), []);
    }

    private function depuisUnePersonne(Person $personne): SujetResolu
    {
        $id = $personne->getId();

        return new SujetResolu($personne->getDisplayName(), null === $id ? [] : [$id]);
    }

    /**
     * Les **deux** époux, et le libellé qui les nomme tous les deux.
     *
     * 3 des 103 familles réelles n'ont qu'une `WIFE` : la liste en compte alors un seul,
     * et c'est juste — on protège les personnes que la famille engage réellement, pas un
     * nombre fixe.
     */
    private function depuisUneFamille(Family $famille): SujetResolu
    {
        $ids = [];
        $noms = [];

        foreach ([$famille->getHusband(), $famille->getWife()] as $epoux) {
            if (null === $epoux) {
                continue;
            }

            $id = $epoux->getId();

            if (null !== $id) {
                $ids[] = $id;
            }

            $noms[] = $epoux->getDisplayName();
        }

        return new SujetResolu([] === $noms ? 'Union' : implode(' × ', $noms), $ids);
    }
}
