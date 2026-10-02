<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Service;

use App\Module\Core\Service\CleDeRecherche;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Statistiques\Contract\FamilleDuReleve;
use App\Module\Statistiques\Contract\LieuDuReleve;
use App\Module\Statistiques\Contract\PersonneDuReleve;
use App\Module\Statistiques\Contract\ReleveDeLArbre;

/**
 * Le relevé, indexé une fois pour toutes les sections : qui sont les parents de qui, quelles
 * unions a chacun, quel lieu résolu porte une chaîne `PLAC` — et **qui le lecteur voit**.
 *
 * `masquee($id)` est la seule question de confidentialité que les calculs posent. La
 * réponse vient de l'ensemble que {@see StatistiquesVisibles} a obtenu du cercle, en un
 * seul appel ; aucun calcul ne s'adresse au cercle lui-même.
 */
final class Population
{
    /** @var array<int, PersonneDuReleve> */
    public readonly array $personnes;

    /** @var array<int, FamilleDuReleve> */
    public readonly array $familles;

    /** @var array<int, int> enfant → famille d'origine (la première, dans l'ordre des familles) */
    private array $origine = [];

    /** @var array<int, list<int>> personne → familles où elle est époux ou épouse */
    private array $unions = [];

    /** @var array<string, LieuDuReleve> */
    private array $lieux = [];

    private readonly CleDeRecherche $cles;

    /**
     * @param array<int, true> $visibles les identifiants que le lecteur voit, **en clés**
     */
    public function __construct(
        public readonly ReleveDeLArbre $releve,
        private readonly array $visibles,
        public readonly \DateTimeImmutable $maintenant,
    ) {
        $this->cles = new CleDeRecherche();

        $personnes = [];
        foreach ($releve->personnes as $personne) {
            $personnes[$personne->id] = $personne;
        }
        $this->personnes = $personnes;

        $familles = [];
        foreach ($releve->familles as $famille) {
            $familles[$famille->id] = $famille;

            foreach ([$famille->mariId, $famille->epouseId] as $epoux) {
                if (null !== $epoux) {
                    $this->unions[$epoux][] = $famille->id;
                }
            }

            foreach ($famille->enfants as $enfant) {
                $this->origine[$enfant] ??= $famille->id;
            }
        }
        $this->familles = $familles;

        foreach ($releve->lieux as $lieu) {
            $this->lieux[$lieu->cle] = $lieu;
        }
    }

    /** Le lecteur ne voit pas cette fiche (fiche confidentielle hors de son cercle). */
    public function masquee(int $personId): bool
    {
        return !isset($this->visibles[$personId]);
    }

    /** Une famille est masquée dès qu'un de ses époux l'est — la règle du « ET » d'`Almanach`. */
    public function familleMasquee(FamilleDuReleve $famille): bool
    {
        foreach ([$famille->mariId, $famille->epouseId] as $epoux) {
            if (null !== $epoux && $this->masquee($epoux)) {
                return true;
            }
        }

        return false;
    }

    public function familleDOrigine(int $personId): ?FamilleDuReleve
    {
        $id = $this->origine[$personId] ?? null;

        return null === $id ? null : ($this->familles[$id] ?? null);
    }

    public function pere(int $personId): ?PersonneDuReleve
    {
        $mari = $this->familleDOrigine($personId)?->mariId;

        return null === $mari ? null : ($this->personnes[$mari] ?? null);
    }

    public function mere(int $personId): ?PersonneDuReleve
    {
        $epouse = $this->familleDOrigine($personId)?->epouseId;

        return null === $epouse ? null : ($this->personnes[$epouse] ?? null);
    }

    public function aDesParents(int $personId): bool
    {
        return isset($this->origine[$personId]);
    }

    /** @return list<FamilleDuReleve> */
    public function unionsDe(int $personId): array
    {
        $unions = [];

        foreach ($this->unions[$personId] ?? [] as $id) {
            if (isset($this->familles[$id])) {
                $unions[] = $this->familles[$id];
            }
        }

        return $unions;
    }

    /** Le lieu résolu d'une date — le même repliement que la carte. */
    public function lieuDe(UncertainDate $date): ?LieuDuReleve
    {
        $brut = $date->getPlace();

        if (null === $brut || '' === trim($brut)) {
            return null;
        }

        return $this->lieux[$this->cles->replier($brut)] ?? null;
    }

    /**
     * L'âge atteint : au décès s'il est daté, aujourd'hui pour une personne vivante dont
     * aucun décès n'est noté. `null` quand on ne sait pas (décès noté sans date, ou personne
     * présumée décédée par la règle du § 9.2 sans date de décès).
     */
    public function ageAtteint(PersonneDuReleve $personne): ?int
    {
        if (null !== DateRetenue::annee($personne->deces)) {
            return DateRetenue::ageEnAnnees($personne->naissance, $personne->deces);
        }

        if ($personne->vivant && !$personne->decede && !$personne->deces->isKnown()) {
            return DateRetenue::ageEnAnnees($personne->naissance, $this->maintenant);
        }

        return null;
    }

    /**
     * Le nom affiché d'une personne que le lecteur voit — à n'appeler qu'après `masquee()`.
     *
     * @return array{id: int, nom: string}
     */
    public function reference(PersonneDuReleve $personne): array
    {
        return ['id' => $personne->id, 'nom' => $personne->displayName];
    }
}
