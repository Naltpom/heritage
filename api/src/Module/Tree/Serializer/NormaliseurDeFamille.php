<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Core\Contract\CalqueEnAttenteInterface;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;

/**
 * La fiche d'une famille — § 5.4 du contrat.
 *
 * Elle existe parce que `modification_famille` est une nature de demande : sans écran de
 * famille, on ne peut pas proposer de corriger une date de mariage.
 *
 * ## Ce qui est filtré ici, et ce qui ne l'est pas
 *
 * **Une famille n'est ni vivante ni décédée** : le cercle de visibilité ne porte pas sur
 * elle. Ce sont ses **époux** et ses **enfants** qui sont filtrés, chacun pour son compte :
 * un époux vivant hors cercle est réduit à `{personId, displayName, visibilite}`, et depuis
 * la révision du 2026-10-01 de l'ADR 0003, son `displayName` — comme celui d'un enfant
 * vivant hors cercle — vaut {@see CercleDeVisibiliteInterface::NOM_MASQUE}. Les enfants
 * sortaient jusque-là sous leur vrai nom **sans aucun contrôle** : la famille d'aïeux
 * défunts était l'annuaire de leurs descendants vivants.
 *
 * **Les dates d'union restent visibles**, y compris quand un époux est masqué. C'est
 * écrit noir sur blanc au § 5.4, et la raison mérite d'être retenue : elles appartiennent
 * à la famille, pas à la personne — et une union dont un membre est masqué reste une
 * union, sans quoi la fratrie devient fausse (ADR 0003). C'est le même raisonnement qui
 * garde les arêtes du graphe.
 *
 * C'est le point où le réflexe « masquer un peu plus par prudence » casse quelque chose :
 * retirer la date de mariage d'un couple à demi masqué rendrait l'écran incohérent pour
 * l'époux qui, lui, est parfaitement visible.
 */
final readonly class NormaliseurDeFamille
{
    public function __construct(
        private NormaliseurDeDate $dates,
        private LectureDeLArbre $lecture,
        private CalqueEnAttenteInterface $calque,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function fiche(
        Family $famille,
        LecteurDeLArbre $lecteur,
        CercleDuLecteur $cercle,
        EtatVivant $vivants,
    ): array {
        $id = $famille->getId();
        $enfants = $this->lecture->enfantsDe([$famille])[$id] ?? [];

        $lignesEnfants = [];
        foreach ($enfants as $ligne) {
            $personne = $ligne->getPerson();

            $lignesEnfants[] = [
                'personId' => $personne->getId(),
                // L'enfant reste dans la liste — une fratrie amputée serait fausse —, son
                // nom suit le cercle.
                'displayName' => $cercle->voit($personne->getId(), $vivants->estVivante($personne))
                    ? $personne->getDisplayName()
                    : CercleDeVisibiliteInterface::NOM_MASQUE,
                'siblingOrder' => $ligne->getSiblingOrder(),
                'relation' => $ligne->getRelation()->value,
            ];
        }

        return [
            'id' => $id,
            'gedcomXref' => $famille->getGedcomXref(),
            'husband' => $this->epoux($famille->getHusband(), $cercle, $vivants),
            'wife' => $this->epoux($famille->getWife(), $cercle, $vivants),
            'marriage' => $this->dates->pourFiche($famille->getMarriage()),
            'divorced' => $famille->isDivorced(),
            'divorce' => $famille->isDivorced() || $famille->getDivorce()->isKnown()
                ? $this->dates->pourFiche($famille->getDivorce())
                : null,
            'generation' => $famille->getGeneration(),
            'rank' => $famille->getRank(),
            'version' => $famille->getVersion(),
            'enfants' => $lignesEnfants,
            'citations' => $this->citations($famille),
            'notes' => $this->notes($famille),
            // `(object)` et non un tableau nu : un tableau PHP vide se sérialise en `[]`,
            // pas en `{}`, et le § 13.1 exige un objet toujours présent.
            'enAttente' => (object) (null !== $id ? ($this->calque->calque(SubjectKind::Famille, [$id], $lecteur->pourLeCalque())[$id] ?? []) : []),
        ];
    }

    /**
     * Un époux : trois clés, plus le détail seulement si le lecteur y a droit.
     *
     * Contrairement aux références de personne d'une fiche (§ 5.3), `visibilite` est
     * **toujours** émise ici — c'est ce que montre l'exemple du § 5.4, et c'est utile :
     * l'écran de famille propose un lien vers chaque époux, et il doit savoir d'avance
     * lequel mène à une impasse.
     *
     * @return array{personId: int|null, displayName: string, visibilite: string}|null
     */
    private function epoux(?Person $personne, CercleDuLecteur $cercle, EtatVivant $vivants): ?array
    {
        if (null === $personne) {
            return null;
        }

        $niveau = $cercle->niveau($personne->getId(), $vivants->estVivante($personne));

        return [
            'personId' => $personne->getId(),
            'displayName' => NiveauDeVisibilite::Complete === $niveau
                ? $personne->getDisplayName()
                : CercleDeVisibiliteInterface::NOM_MASQUE,
            'visibilite' => $niveau->value,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function citations(Family $famille): array
    {
        $sortie = [];

        foreach ($this->lecture->citationsDeFamille($famille) as $citation) {
            $source = $citation->getSource();
            $quay = $citation->getQuay();

            $sortie[] = [
                'id' => $citation->getId(),
                'scope' => $citation->getScope()->value,
                'quay' => $quay?->value,
                'quayLabel' => $quay?->label(),
                'page' => $citation->getPage(),
                'evenContext' => $citation->getEvenContext()?->value,
                'url' => $citation->getUrl(),
                'dataText' => $citation->getDataText(),
                'source' => [
                    'id' => $source->getId(),
                    'title' => $source->getTitle(),
                    'author' => $source->getAuthor(),
                    'sourceKind' => $source->getSourceKind()->value,
                ],
                'medias' => [],
                // Même clé que sur la fiche d'une personne : une citation se date
                // partout pareil.
                'createdAt' => $citation->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return $sortie;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notes(Family $famille): array
    {
        $sortie = [];

        foreach ($this->lecture->notesDeFamille($famille) as $note) {
            $sortie[] = [
                'id' => $note->getId(),
                'scope' => $note->getScope()->value,
                'body' => $note->getBody(),
                'authorLabel' => $note->getAuthorLabel(),
                'createdAt' => $note->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return $sortie;
    }
}
