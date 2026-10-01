<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Assistant\Contract\ArbrePourLaConsultation;
use App\Module\Assistant\Contract\DossierDeLaPersonne;
use App\Module\Assistant\Contract\FaitDeLArbre;
use App\Module\Assistant\Contract\PersonneDeLArbre;
use App\Module\Assistant\Contract\SourceCitee;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Tree\Entity\Citation;
use App\Module\Tree\Entity\Fact;
use App\Module\Tree\Entity\Note;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Repository\PersonRepository;
use App\Module\Tree\Serializer\AppuiDUneCitation;
use App\Module\Tree\Serializer\EtatVivant;
use App\Module\Tree\Serializer\LectureDeLArbre;
use App\Module\Tree\Serializer\NormaliseurDeRequete;
use App\Module\Tree\Serializer\ParenteDuLecteur;

/**
 * Ce que « Poser une question » lit de l'arbre en plus des deux outils de l'assistant :
 * les sources d'une fiche, la parenté vue d'un ego, une recherche par lieu ou par période.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  AUCUN FILTRE DE CERCLE ICI — l'appelant filtre AVANT de transmettre au modèle.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Même règle que {@see VueDeLArbrePourLAssistant}, et lire l'en-tête de
 * `Assistant\Contract\ArbrePourLaConsultation` : ce fichier ne sait pas qui lit. Chaque
 * personne rendue porte `vivante` (la règle du graphe, {@see EtatVivant}), et c'est
 * `Assistant\Service\OutilsDeConsultation` qui la confronte au cercle du lecteur.
 *
 * ── Rien n'est recalculé ici ─────────────────────────────────────────────────────
 *
 * - le niveau d'appui d'une citation est celui de la fiche ({@see AppuiDUneCitation}) ;
 * - les adresses d'arbres en ligne sont retirées comme sur la fiche
 *   ({@see AdressesDArbresEnLigne::nettoyer()}) ;
 * - la parenté est celle du champ `parente` ({@see ParenteDuLecteur}), depuis l'ego
 *   que l'appelant désigne ;
 * - la correspondance des lieux replie accents et casse avec le même outil que la
 *   recherche par nom ({@see NormaliseurDeRequete}).
 */
final readonly class VueDeLArbrePourLaConsultation implements ArbrePourLaConsultation
{
    public function __construct(
        private LectureDeLArbre $lecture,
        private PersonRepository $personnes,
        private EtatVivant $vivants,
        private AppuiDUneCitation $appui,
        private AdressesDArbresEnLigne $adresses,
        private ParenteDuLecteur $parentes,
        private NormaliseurDeRequete $requetes,
    ) {
    }

    public function dossier(int $personneId): ?DossierDeLaPersonne
    {
        $personne = $this->lecture->personne($personneId);

        if (null === $personne) {
            return null;
        }

        return new DossierDeLaPersonne(
            array_map(static fn (Fact $f): FaitDeLArbre => new FaitDeLArbre(
                $f->getType()->label(),
                $f->getLabel(),
                $f->getHappened(),
                $f->getAddress(),
                $f->getAge(),
                $f->getCause(),
            ), $this->lecture->faitsDe($personne)),
            array_map($this->source(...), $this->lecture->citationsDePersonne($personne)),
            array_values(array_filter(
                array_map(static fn (Note $n): string => trim($n->getBody()), $this->lecture->notesDePersonne($personne)),
                static fn (string $texte): bool => '' !== $texte,
            )),
        );
    }

    public function parente(int $egoPersonneId, int $personneId): ?string
    {
        return $this->parentes->depuis($egoPersonneId, $personneId);
    }

    public function parLieuEtPeriode(?string $lieu, ?int $anneeMin, ?int $anneeMax, string $evenement, int $limite): array
    {
        $motsDuLieu = null === $lieu ? [] : $this->requetes->mots($lieu);

        if (([] === $motsDuLieu && null === $anneeMin && null === $anneeMax) || $limite < 1) {
            return [];
        }

        $trouvees = [];

        // 274 fiches : on lit l'arbre une fois et l'on compare en PHP. Un `LIKE` en SQL ne
        // replierait pas les accents de la même façon sur SQLite et PostgreSQL, et la
        // correspondance doit être celle de la recherche par nom, pas une seconde règle.
        foreach ($this->personnes->findAll() as $personne) {
            $date = $this->evenementRetenu($personne, $evenement, $motsDuLieu, $anneeMin, $anneeMax);

            if (null !== $date) {
                $trouvees[] = [$date->getMin() ?? $date->getMax(), $personne];
            }
        }

        // Par date de l'événement, les dates inconnues en dernier : « qui est né à
        // Étaples » se lit dans l'ordre des naissances.
        usort($trouvees, static function (array $a, array $b): int {
            if (null === $a[0] || null === $b[0]) {
                return (null === $a[0]) <=> (null === $b[0]);
            }

            return $a[0] <=> $b[0];
        });

        return array_map(
            fn (array $ligne): PersonneDeLArbre => $this->seule($ligne[1]),
            \array_slice($trouvees, 0, $limite),
        );
    }

    /**
     * L'événement de la personne qui répond aux critères — la naissance d'abord, puis le
     * décès —, ou `null`.
     *
     * @param list<string> $motsDuLieu
     */
    private function evenementRetenu(Person $personne, string $evenement, array $motsDuLieu, ?int $anneeMin, ?int $anneeMax): ?UncertainDate
    {
        $candidates = match ($evenement) {
            'naissance' => [$personne->getBirth()],
            'deces' => [$personne->getDeath()],
            default => [$personne->getBirth(), $personne->getDeath()],
        };

        foreach ($candidates as $date) {
            if ($this->convient($date, $motsDuLieu, $anneeMin, $anneeMax)) {
                return $date;
            }
        }

        return null;
    }

    /** @param list<string> $motsDuLieu */
    private function convient(UncertainDate $date, array $motsDuLieu, ?int $anneeMin, ?int $anneeMax): bool
    {
        if ([] !== $motsDuLieu) {
            $lieu = ' '.$this->requetes->cle($date->getPlace() ?? '');

            foreach ($motsDuLieu as $mot) {
                // En tête d'un mot du lieu, comme la recherche par nom : « etap » trouve
                // « Étaples », « pas calais » trouve « Pas-de-Calais ».
                if (!str_contains($lieu, ' '.$mot)) {
                    return false;
                }
            }
        }

        if (null === $anneeMin && null === $anneeMax) {
            return true;
        }

        // Une fourchette ouverte d'un côté (« avant 1900 ») compte pour sa seule borne :
        // elle ne doit pas répondre à toutes les périodes antérieures.
        $debut = $date->getMin() ?? $date->getMax();
        $fin = $date->getMax() ?? $date->getMin();

        if (null === $debut || null === $fin) {
            return false;
        }

        return (null === $anneeMax || (int) $debut->format('Y') <= $anneeMax)
            && (null === $anneeMin || (int) $fin->format('Y') >= $anneeMin);
    }

    private function source(Citation $citation): SourceCitee
    {
        $source = $citation->getSource();
        $fonds = $source->getSourceKind();
        $niveau = $this->appui->niveau($fonds, $citation->getQuay());

        return new SourceCitee(
            $niveau,
            $this->appui->libelle($niveau),
            $citation->getQuay()?->label(),
            $source->getTitle(),
            $fonds->label(),
            $this->adresses->nettoyer($citation->getPage()),
            $citation->getEvenContext()?->value,
            $this->adresses->nettoyer($citation->getDataText()),
        );
    }

    private function seule(Person $personne): PersonneDeLArbre
    {
        return new PersonneDeLArbre(
            (int) $personne->getId(),
            $personne->getDisplayName(),
            $personne->getSex(),
            $this->vivants->estVivante($personne),
            $personne->getBirth(),
            $personne->getDeath(),
        );
    }
}
