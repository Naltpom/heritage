<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Core\Contract\CalqueEnAttenteInterface;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Citation;
use App\Module\Tree\Entity\Fact;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Note;
use App\Module\Tree\Entity\Person;

/**
 * La fiche d'une personne — § 5.3 du contrat pour la forme complète, § 11.1 pour la
 * forme réduite.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  C'EST ICI, ET NULLE PART AILLEURS, QUE LE CERCLE DE VISIBILITÉ S'APPLIQUE.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * « Le filtrage est appliqué côté serveur, dans la sérialisation. Jamais côté client. Un
 * champ masqué n'est pas envoyé puis caché : il n'est pas envoyé » (§ 6.2 du dossier de
 * mission, ADR 0003).
 *
 * **Corollaire que ce fichier tient à la lettre** : on ne construit jamais la charge
 * complète pour en retirer ensuite les clés interdites. Les deux formes sont écrites
 * séparément, et la forme réduite est une **liste blanche littérale** de sept clés. Une
 * implémentation qui remplit puis `unset()` laisse passer le premier champ ajouté après
 * coup — c'est exactement le mode de défaillance que le test de liste blanche existe
 * pour attraper, et le retirer d'ici rendrait ce test cosmétique.
 *
 * ## Les trois pièges de cette fiche
 *
 * 1. **`vivant` sort même hors cercle**, et il vaut `true` : il annonce donc que la
 *    personne est vivante. C'est le « masquage est son propre indice » que l'ADR 0003
 *    accepte, et `.fiche.prive` en dépend au rendu (ADR 0007). Ne pas l'envoyer serait
 *    pire : le client devrait inférer l'état vivant de l'*absence* de champs,
 *    c'est-à-dire l'inférence côté client que le § 6.2 interdit.
 * 2. **`ligneDates` tombe.** C'est du formatage, mais c'est du formatage **de dates** :
 *    la laisser reviendrait à envoyer la naissance sous une autre orthographe.
 * 3. **`parents`, `fratrie` et `unions` tombent**, alors que les arêtes correspondantes
 *    **restent dans le graphe**. Ce n'est pas une incohérence : le graphe expose la
 *    topologie, que l'ADR 0003 accepte comme risque résiduel ; la fiche exposerait les
 *    **noms et identifiants** de la parenté sous forme de liste, c'est-à-dire une
 *    énumération. Le graphe montre, il n'énumère pas.
 *
 * Et `version` tombe aussi, ce qui rend matériellement impossible de déposer une demande
 * sur cette fiche : sans `baseVersion`, `POST /api/demandes` rend `422`. Le contrôle
 * d'écriture devient redondant avec celui de lecture — c'est de la défense en
 * profondeur, pas un effet de bord.
 */
final readonly class NormaliseurDePersonne
{
    public function __construct(
        private NormaliseurDeDate $dates,
        private LectureDeLArbre $lecture,
        private PortDesMedias $medias,
        private ParenteDuLecteur $parente,
        private CalqueEnAttenteInterface $calque,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function fiche(
        Person $personne,
        LecteurDeLArbre $lecteur,
        CercleDuLecteur $cercle,
        EtatVivant $vivants,
    ): array {
        $vivante = $vivants->estVivante($personne);

        return $cercle->voit($personne->getId(), $vivante)
            ? $this->ficheComplete($personne, $lecteur, $cercle, $vivants, $vivante)
            : $this->ficheReduite($personne);
    }

    /**
     * Les **sept clés** de la liste blanche de l'ADR 0003, et pas une de plus.
     *
     * Aucune clé à `null` pour un champ interdit : les champs interdits sont **absents**
     * (§ 1.3). Un `"birth": null` dirait « on ne sait pas » là où la vérité est « tu n'as
     * pas le droit de savoir », et le front afficherait un tiret au lieu de ne rien
     * afficher du tout.
     *
     * `generation` et `branch` peuvent valoir `null` : elles sont autorisées et
     * simplement pas encore calculées. Ce `null`-là veut bien dire « inconnu ».
     *
     * @return array{id: int|null, displayName: string, sex: string, generation: int|null, branch: string|null, vivant: bool, visibilite: string}
     */
    private function ficheReduite(Person $personne): array
    {
        return [
            'id' => $personne->getId(),
            'displayName' => $personne->getDisplayName(),
            'sex' => $personne->getSex()->value,
            'generation' => $personne->getGeneration(),
            'branch' => $personne->getBranch()?->value,
            // Toujours `true` ici : une personne décédée n'atteint jamais cette branche.
            'vivant' => true,
            'visibilite' => NiveauDeVisibilite::Restreinte->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ficheComplete(
        Person $personne,
        LecteurDeLArbre $lecteur,
        CercleDuLecteur $cercle,
        EtatVivant $vivants,
        bool $vivante,
    ): array {
        $id = $personne->getId();

        $filiations = $this->lecture->filiationsDe($personne);
        $famillesDOrigine = array_values(array_map(static fn (Child $c): Family => $c->getFamily(), $filiations));
        $unions = $this->lecture->unionsDe($personne);
        $enfantsParFamille = $this->lecture->enfantsDe($unions);
        $citations = $this->lecture->citationsDePersonne($personne);

        return [
            'id' => $id,
            'gedcomXref' => $personne->getGedcomXref(),
            'displayName' => $personne->getDisplayName(),
            'givenNames' => $personne->getGivenNames(),
            'surname' => $personne->getSurname(),
            'marriedName' => $personne->getMarriedName(),
            'nickname' => $personne->getNickname(),
            'sex' => $personne->getSex()->value,
            // `deceased` est **stocké**, `vivant` est **calculé** : les deux sont présents
            // et ne disent pas la même chose. C'est `vivant` qui allume `.fiche.prive`,
            // jamais `deceased` et jamais l'absence de dates.
            'deceased' => $personne->isDeceased(),
            'vivant' => $vivante,
            'birth' => $this->dates->pourFiche($personne->getBirth()),
            'death' => $this->dates->pourFiche($personne->getDeath()),
            'ligneDates' => $this->dates->ligneDeFiche($personne->getBirth(), $personne->getDeath()),
            'branch' => $personne->getBranch()?->value,
            'generation' => $personne->getGeneration(),
            'bestQuay' => $personne->getBestQuay(),
            'citationCount' => $personne->getCitationCount(),
            'forcePrivacy' => $personne->isForcePrivacy(),
            'privacyNote' => $personne->getPrivacyNote(),
            'version' => $personne->getVersion(),
            'visibilite' => NiveauDeVisibilite::Complete->value,
            // **Relative au lecteur**, jamais à la racine de l'arbre : « arrière-grand-mère »
            // est une phrase parfaitement plausible, y compris quand elle désigne l'aïeule
            // de quelqu'un d'autre. {@see ParenteDuLecteur} porte ce choix et les trois
            // cas où il rend `null` — compte en attente, bascule active, pas de
            // rattachement.
            'parente' => $this->parente->pour($lecteur, $id),
            'portrait' => null !== $id ? $this->medias->portraitDe($id, $lecteur) : null,
            'parents' => $this->parents($filiations, $personne),
            'fratrie' => $this->fratrie($personne, $famillesDOrigine),
            'unions' => $this->unions($personne, $unions, $enfantsParFamille),
            'faits' => $this->faits($this->lecture->faitsDe($personne)),
            'citations' => $this->citations($citations, $lecteur),
            'notes' => $this->notes($this->lecture->notesDePersonne($personne)),
            'medias' => null !== $id ? $this->medias->mediasDePersonne($id, $lecteur) : [],
            // Le calque et le cercle sont deux filtres **composés**, jamais substitués
            // (ADR 0004). On n'arrive ici que si le cercle a déjà dit oui ; une personne
            // vivante hors cercle n'a pas de clé `enAttente` **du tout**, pas un calque
            // vide — servir `{}` signalerait quand même l'existence de la fiche.
            //
            // Le `(object)` n'est pas cosmétique : un tableau PHP **vide** se sérialise
            // en `[]`, pas en `{}`. Le § 13.1 exige un objet toujours présent, et un
            // front qui écrit `fiche.enAttente[champ]` sur un tableau JavaScript ne
            // trouverait rien le jour où le calque cesserait d'être vide — un bogue qui
            // n'apparaît qu'en présence de données.
            'enAttente' => (object) (null !== $id ? ($this->calque->calque(SubjectKind::Personne, [$id], $lecteur->pourLeCalque())[$id] ?? []) : []),
            'demandesEnAttente' => null !== $id ? $this->calque->demandesSur(SubjectKind::Personne, $id, $lecteur->pourLeCalque()) : [],
        ];
    }

    /**
     * La référence courte d'une personne : `personId` et `displayName`, et **rien
     * d'autre**.
     *
     * Les deux clés sont dans la liste blanche, donc cette forme est servie telle quelle
     * que la personne désignée soit visible ou non. C'est ce qui permet à une fratrie de
     * rester une fratrie quand l'un de ses membres est masqué : la retirer rendrait la
     * fratrie fausse, ce que l'ADR 0003 refuse explicitement.
     *
     * @return array{personId: int|null, displayName: string}
     */
    public function reference(Person $personne): array
    {
        return [
            'personId' => $personne->getId(),
            'displayName' => $personne->getDisplayName(),
        ];
    }

    /**
     * @param list<Child> $filiations
     *
     * @return list<array{personId: int|null, displayName: string, relation: string, familyId: int|null}>
     */
    private function parents(array $filiations, Person $personne): array
    {
        $sortie = [];

        foreach ($filiations as $filiation) {
            $famille = $filiation->getFamily();

            foreach ([$famille->getHusband(), $famille->getWife()] as $parent) {
                if (null === $parent || $parent === $personne) {
                    continue;
                }

                $sortie[] = [
                    'personId' => $parent->getId(),
                    'displayName' => $parent->getDisplayName(),
                    // `relation` appartient au **lien**, pas à la personne : elle suit la
                    // même logique que les dates d'union du § 5.4, qui restent visibles
                    // même quand un époux est masqué.
                    'relation' => $filiation->getRelation()->value,
                    'familyId' => $famille->getId(),
                ];
            }
        }

        return $sortie;
    }

    /**
     * @param list<Family> $famillesDOrigine
     *
     * @return list<array{personId: int|null, displayName: string, siblingOrder: int}>
     */
    private function fratrie(Person $personne, array $famillesDOrigine): array
    {
        $sortie = [];

        foreach ($this->lecture->fratrieDe($personne, $famillesDOrigine) as $ligne) {
            $frereOuSoeur = $ligne->getPerson();

            $sortie[] = [
                'personId' => $frereOuSoeur->getId(),
                'displayName' => $frereOuSoeur->getDisplayName(),
                'siblingOrder' => $ligne->getSiblingOrder(),
            ];
        }

        return $sortie;
    }

    /**
     * @param list<Family>            $unions
     * @param array<int, list<Child>> $enfantsParFamille
     *
     * @return list<array<string, mixed>>
     */
    private function unions(Person $personne, array $unions, array $enfantsParFamille): array
    {
        $sortie = [];

        foreach ($unions as $famille) {
            $familleId = $famille->getId();
            $conjoint = $famille->getHusband() === $personne ? $famille->getWife() : $famille->getHusband();

            $enfants = [];
            foreach ($enfantsParFamille[$familleId] ?? [] as $ligne) {
                $enfants[] = $this->reference($ligne->getPerson());
            }

            $sortie[] = [
                'familyId' => $familleId,
                // `null` quand l'union n'a qu'un époux connu : 3 familles réelles n'ont
                // qu'une `WIFE`. Ce n'est pas une donnée manquante, c'est l'état du
                // travail généalogique.
                'conjoint' => null !== $conjoint ? $this->reference($conjoint) : null,
                // Les dates d'union restent visibles même si le conjoint est masqué :
                // elles appartiennent à la famille, pas à la personne — et une union
                // dont un membre est masqué reste une union, sans quoi la fratrie
                // devient fausse (§ 5.4, ADR 0003).
                'marriage' => $this->dates->pourFiche($famille->getMarriage()),
                'divorced' => $famille->isDivorced(),
                'divorce' => $famille->isDivorced() || $famille->getDivorce()->isKnown()
                    ? $this->dates->pourFiche($famille->getDivorce())
                    : null,
                'enfants' => $enfants,
            ];
        }

        return $sortie;
    }

    /**
     * @param list<Fact> $faits
     *
     * @return list<array<string, mixed>>
     */
    private function faits(array $faits): array
    {
        $sortie = [];

        foreach ($faits as $fait) {
            $sortie[] = [
                'id' => $fait->getId(),
                'type' => $fait->getType()->value,
                'label' => $fait->getLabel(),
                'happened' => $this->dates->pourFiche($fait->getHappened()),
                'address' => $fait->getAddress(),
                'age' => $fait->getAge(),
                'cause' => $fait->getCause(),
            ];
        }

        return $sortie;
    }

    /**
     * @param list<Citation> $citations
     *
     * @return list<array<string, mixed>>
     */
    private function citations(array $citations, LecteurDeLArbre $lecteur): array
    {
        $ids = [];
        foreach ($citations as $citation) {
            $id = $citation->getId();
            if (null !== $id) {
                $ids[] = $id;
            }
        }

        $mediasParCitation = $this->medias->mediasDesCitations($ids, $lecteur);
        $sortie = [];

        foreach ($citations as $citation) {
            $id = $citation->getId();
            $source = $citation->getSource();
            $quay = $citation->getQuay();

            $sortie[] = [
                'id' => $id,
                'scope' => $citation->getScope()->value,
                'quay' => $quay?->value,
                // Le libellé reprend le vocabulaire déjà retenu (§ 3.9 du modèle) pour que
                // l'interface ne change pas de mots entre deux écrans.
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
                'medias' => null !== $id ? ($mediasParCitation[$id] ?? []) : [],
            ];
        }

        return $sortie;
    }

    /**
     * @param list<Note> $notes
     *
     * @return list<array<string, mixed>>
     */
    private function notes(array $notes): array
    {
        $sortie = [];

        foreach ($notes as $note) {
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
