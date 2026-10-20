<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Service;

use App\Module\Core\Enum\Branch;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Statistiques\Contract\PersonneDuReleve;
use App\Module\Statistiques\Contract\PorteeDeLAppui;

/**
 * Section 1 — **l'arbre en chiffres** : le bandeau (personnes, familles, générations,
 * période), la part vérifiée et son évolution, la répartition par branche.
 *
 * ── « Vérifié », une seule définition ────────────────────────────────────────────
 *
 * Exactement celle de la fiche (`regimeDocumentaire`, `components/personne/champs.ts`) et
 * du filtre « Vérifiés » de l'arbre : **`bestQuay ≥ 2`**, une citation au moins qui est un
 * acte ou un registre. La part vérifiée de l'arbre est donc la part des **fiches**
 * vérifiées — c'est le chiffre que l'arbre filtre, et deux chiffres différents sous le
 * même mot se contrediraient à l'écran.
 *
 * Le détail par événement (naissances, décès, mariages appuyés sur un acte) lit la portée
 * des citations : une citation de qualité ≥ 2 rattachée à la naissance d'une personne, à
 * son décès, ou au mariage d'une famille. Le dénominateur est l'événement **renseigné**
 * (une date, même floue, ou un lieu) : on ne reproche pas à un mariage inconnu d'être
 * sans acte.
 *
 * ── L'évolution par mois, et ses limites ─────────────────────────────────────────
 *
 * Il n'existe pas de journal de « Vérifié ». Mais chaque citation porte sa date de saisie
 * (`tree_citation.created_at`) et chaque fiche la sienne : on peut donc reconstruire, à la
 * fin de chaque mois, combien de fiches existaient et combien avaient déjà une citation de
 * qualité ≥ 2. Ce que cette reconstruction **ne voit pas**, et que l'écran dit :
 *
 * - une citation ou une fiche **supprimée** depuis disparaît aussi du passé ;
 * - une citation dont la **qualité a été relevée** après coup compte depuis sa saisie ;
 * - tout ce qui vient de l'**import GEDCOM initial** porte la date de cet import — l'arbre « commence »
 *   ce jour-là, même pour des recherches de plusieurs années.
 *
 * C'est donc l'évolution **dans l'application**, pas l'histoire de la recherche.
 *
 * ── Jour par jour (demande du 20/10/2026) ────────────────────────────────────────
 *
 * « Combien de personnes ont été ajoutées ou vérifiées, tous les jours » : la même
 * reconstruction, au jour près. Une fiche est **ajoutée** le jour de sa création ; elle
 * devient **vérifiée** le jour de sa première citation de qualité ≥ 2 — au plus tôt le
 * jour de sa création, une citation ne précédant sa fiche que par un import. Les **mêmes
 * limites** que l'évolution par mois : une fiche ou une citation supprimée disparaît de
 * son jour, une qualité relevée après coup compte du jour de la saisie, l'import tombe
 * tout entier le jour de l'import. Les jours sont ceux de la famille (`Europe/Paris`),
 * pas ceux du serveur (UTC) : une fiche saisie à 0 h 30 l'est le jour qui commence. La
 * série compte tous les jours, même ceux où rien n'a bougé, et s'arrête à un an.
 */
final class ArbreEnChiffres
{
    /** L'ordre d'affichage des branches : celui de la légende de l'arbre. */
    private const array ORDRE = ['pp', 'pm', 'mp', 'mm', 'tr', 'cpp', 'cpm', 'cmp', 'cmm', 'hors'];

    /** L'historique s'arrête aux trois dernières années : au-delà, la courbe ne se lit plus. */
    private const int MOIS_D_HISTORIQUE = 36;

    /** La série par jour s'arrête à un an, aujourd'hui compris. */
    private const int JOURS_DE_SERIE = 365;

    /** Le fuseau où se lisent les jours : celui de la famille, pas celui du serveur. */
    private const string FUSEAU = 'Europe/Paris';

    /** @return array<string, mixed> */
    public function calculer(Population $population): array
    {
        return [
            'personnes' => \count($population->personnes),
            'familles' => \count($population->familles),
            'generations' => $this->generations($population),
            'periode' => $this->periode($population),
            'verification' => $this->verification($population),
            'branches' => $this->branches($population),
            'parJour' => $this->parJour($population),
        ];
    }

    /** Le nombre de rangées distinctes de l'arbre — « 12 générations ». */
    private function generations(Population $population): int
    {
        $rangees = [];

        foreach ($population->personnes as $personne) {
            if (null !== $personne->generation) {
                $rangees[$personne->generation] = true;
            }
        }

        return \count($rangees);
    }

    /**
     * « De 1650 à aujourd'hui » : la plus ancienne naissance qui compte, et « aujourd'hui »
     * dès qu'une personne de l'arbre est vivante. Les années d'une fiche que le lecteur ne
     * voit pas n'entrent pas dans les bornes : une borne est la date d'**une** personne.
     *
     * @return array{debut: int|null, fin: int|null, jusquAujourdhui: bool}
     */
    private function periode(Population $population): array
    {
        $debut = null;
        $fin = null;
        $vivants = false;

        foreach ($population->personnes as $personne) {
            if ($personne->vivant && !$personne->decede) {
                $vivants = true;
            }

            if ($population->masquee($personne->id)) {
                continue;
            }

            $naissance = DateRetenue::annee($personne->naissance);
            $deces = DateRetenue::annee($personne->deces);

            foreach ([$naissance, $deces] as $annee) {
                if (null === $annee) {
                    continue;
                }

                $debut = null === $debut ? $annee : min($debut, $annee);
                $fin = null === $fin ? $annee : max($fin, $annee);
            }
        }

        return ['debut' => $debut, 'fin' => $vivants ? null : $fin, 'jusquAujourdhui' => $vivants];
    }

    /** @return array<string, mixed> */
    private function verification(Population $population): array
    {
        $naissances = [];
        $deces = [];
        $mariages = [];

        foreach ($population->releve->appuis as $appui) {
            if (null === $appui->quay || $appui->quay < 2) {
                continue;
            }

            if (null !== $appui->personId && PorteeDeLAppui::Naissance === $appui->portee) {
                $naissances[$appui->personId] = true;
            }

            if (null !== $appui->personId && PorteeDeLAppui::Deces === $appui->portee) {
                $deces[$appui->personId] = true;
            }

            if (null !== $appui->familyId && \in_array($appui->portee, [PorteeDeLAppui::Mariage, PorteeDeLAppui::Generale], true)) {
                $mariages[$appui->familyId] = true;
            }
        }

        $parEvenement = [
            'naissances' => ['verifiees' => 0, 'total' => 0],
            'deces' => ['verifiees' => 0, 'total' => 0],
            'mariages' => ['verifiees' => 0, 'total' => 0],
        ];
        $verifiees = 0;

        foreach ($population->personnes as $personne) {
            if (self::estVerifiee($personne)) {
                ++$verifiees;
            }

            if (self::renseigne($personne->naissance)) {
                ++$parEvenement['naissances']['total'];
                $parEvenement['naissances']['verifiees'] += isset($naissances[$personne->id]) ? 1 : 0;
            }

            if ($personne->decede || self::renseigne($personne->deces)) {
                ++$parEvenement['deces']['total'];
                $parEvenement['deces']['verifiees'] += isset($deces[$personne->id]) ? 1 : 0;
            }
        }

        foreach ($population->familles as $famille) {
            if (self::renseigne($famille->mariage)) {
                ++$parEvenement['mariages']['total'];
                $parEvenement['mariages']['verifiees'] += isset($mariages[$famille->id]) ? 1 : 0;
            }
        }

        return [
            'fiches' => ['verifiees' => $verifiees, 'total' => \count($population->personnes)],
            ...$parEvenement,
            'historique' => $this->historique($population),
        ];
    }

    /**
     * La part vérifiée à la fin de chaque mois — voir l'en-tête pour ce qu'elle ne voit pas.
     *
     * @return list<array{mois: string, verifiees: int, total: int}>
     */
    private function historique(Population $population): array
    {
        if ([] === $population->personnes) {
            return [];
        }

        $premierActe = $this->premiersActes($population);

        $creations = array_map(static fn (PersonneDuReleve $p): \DateTimeImmutable => $p->creeLe, $population->personnes);
        $premier = min($creations);

        $debut = $premier->modify('first day of this month')->setTime(0, 0);
        $limite = $population->maintenant->modify('first day of this month')->modify(\sprintf('-%d months', self::MOIS_D_HISTORIQUE - 1))->setTime(0, 0);
        if ($debut < $limite) {
            $debut = $limite;
        }

        $mois = [];
        for ($courant = $debut; $courant <= $population->maintenant; $courant = $courant->modify('+1 month')) {
            $fin = $courant->modify('first day of next month');
            $total = 0;
            $verifiees = 0;

            foreach ($population->personnes as $personne) {
                if ($personne->creeLe >= $fin) {
                    continue;
                }

                ++$total;
                $acte = $premierActe[$personne->id] ?? null;
                $verifiees += null !== $acte && $acte < $fin ? 1 : 0;
            }

            $mois[] = ['mois' => $courant->format('Y-m'), 'verifiees' => $verifiees, 'total' => $total];
        }

        return $mois;
    }

    /**
     * Les fiches ajoutées et devenues vérifiées, jour par jour — voir l'en-tête pour ce que
     * la reconstruction ne voit pas. Du jour de la première fiche à aujourd'hui, un an au
     * plus, **tous les jours**, zéros compris : l'écran n'a pas à deviner qu'un jour absent
     * est un jour vide. Chaque jour porte le même détail par branche (les branches sans
     * rien ce jour-là omises), pour que l'écran filtre sans redemander.
     *
     * Des comptes simples (premier traitement de l'ADR 0015) : ils comptent tout le monde,
     * sans seuil de discrétion.
     *
     * @return list<array{jour: string, ajoutees: int, verifiees: int, branches: object}>
     */
    private function parJour(Population $population): array
    {
        if ([] === $population->personnes) {
            return [];
        }

        $fuseau = new \DateTimeZone(self::FUSEAU);
        $jourDe = static fn (\DateTimeImmutable $instant): string => $instant->setTimezone($fuseau)->format('Y-m-d');
        $premierActe = $this->premiersActes($population);

        /** @var array<string, array<string, array{ajoutees: int, verifiees: int}>> $comptes jour → branche → comptes */
        $comptes = [];
        $premier = null;

        foreach ($population->personnes as $personne) {
            $branche = $personne->branch->value ?? 'hors';

            $jour = $jourDe($personne->creeLe);
            $comptes[$jour][$branche] ??= ['ajoutees' => 0, 'verifiees' => 0];
            ++$comptes[$jour][$branche]['ajoutees'];
            $premier = null === $premier || $jour < $premier ? $jour : $premier;

            $acte = $premierActe[$personne->id] ?? null;
            if (null !== $acte) {
                $jour = $jourDe(max($acte, $personne->creeLe));
                $comptes[$jour][$branche] ??= ['ajoutees' => 0, 'verifiees' => 0];
                ++$comptes[$jour][$branche]['verifiees'];
            }
        }

        // Les jours se parcourent en UTC : ce ne sont plus des instants mais des dates du
        // calendrier, et l'UTC n'a pas de changement d'heure qui sauterait ou doublerait un jour.
        $utc = new \DateTimeZone('UTC');
        $aujourdhui = new \DateTimeImmutable($jourDe($population->maintenant), $utc);
        $debut = max(
            new \DateTimeImmutable((string) $premier, $utc),
            $aujourdhui->modify(\sprintf('-%d days', self::JOURS_DE_SERIE - 1)),
        );

        $jours = [];
        for ($courant = $debut; $courant <= $aujourdhui; $courant = $courant->modify('+1 day')) {
            $jour = $courant->format('Y-m-d');
            $ligne = ['jour' => $jour, 'ajoutees' => 0, 'verifiees' => 0];
            $branches = [];

            foreach (self::ORDRE as $branche) {
                $compte = $comptes[$jour][$branche] ?? null;

                if (null !== $compte) {
                    $ligne['ajoutees'] += $compte['ajoutees'];
                    $ligne['verifiees'] += $compte['verifiees'];
                    $branches[$branche] = $compte;
                }
            }

            // `(object)` et non un tableau nu : un jour sans rien se sérialiserait en `[]`, et
            // l'écran attend un objet indexé par branche.
            $jours[] = [...$ligne, 'branches' => (object) $branches];
        }

        return $jours;
    }

    /**
     * La date de saisie de la première citation de qualité ≥ 2 de chaque fiche : le moment
     * où, pour ces reconstructions, elle est devenue « Vérifiée ».
     *
     * @return array<int, \DateTimeImmutable>
     */
    private function premiersActes(Population $population): array
    {
        $premierActe = [];

        foreach ($population->releve->appuis as $appui) {
            if (null === $appui->personId || null === $appui->quay || $appui->quay < 2) {
                continue;
            }

            $deja = $premierActe[$appui->personId] ?? null;
            $premierActe[$appui->personId] = null === $deja || $appui->creeLe < $deja ? $appui->creeLe : $deja;
        }

        return $premierActe;
    }

    /**
     * La répartition par branche, lue depuis la racine de l'arbre, avec le patronyme qui
     * nomme chaque quart — celui du grand-parent de tête (sosa 4 à 7), comme les bandes de
     * l'arbre (`disposerLignees().quarts[].nom`). Un patronyme n'est donné que si ce
     * grand-parent est visible du lecteur ; sinon la branche garde son nom générique.
     *
     * @return list<array{branche: string, nom: string|null, personnes: int, verifiees: int}>
     */
    private function branches(Population $population): array
    {
        $parBranche = [];

        foreach ($population->personnes as $personne) {
            $cle = $personne->branch->value ?? 'hors';
            $ligne = $parBranche[$cle] ?? ['personnes' => 0, 'verifiees' => 0];
            ++$ligne['personnes'];
            $ligne['verifiees'] += self::estVerifiee($personne) ? 1 : 0;
            $parBranche[$cle] = $ligne;
        }

        $noms = $this->nomsDesQuarts($population);
        $branches = [];

        foreach (self::ORDRE as $cle) {
            if (!isset($parBranche[$cle])) {
                continue;
            }

            $branches[] = ['branche' => $cle, 'nom' => $noms[$cle] ?? null, ...$parBranche[$cle]];
        }

        return $branches;
    }

    /**
     * Le patronyme de tête de chaque quart : le père du père de la racine pour `pp`, sa
     * mère pour `pm`, et ainsi de suite ; les mêmes depuis le premier conjoint de la racine
     * pour les quarts `c…`.
     *
     * @return array<string, string>
     */
    public function nomsDesQuarts(Population $population): array
    {
        $racine = $population->releve->racineId;

        if (null === $racine || !isset($population->personnes[$racine])) {
            return [];
        }

        $noms = $this->quartsDe($population, $racine, '');

        foreach ($population->unionsDe($racine) as $union) {
            $conjoint = $union->mariId === $racine ? $union->epouseId : $union->mariId;

            if (null !== $conjoint) {
                $noms = [...$noms, ...$this->quartsDe($population, $conjoint, 'c')];
                break;
            }
        }

        return $noms;
    }

    /** @return array<string, string> */
    private function quartsDe(Population $population, int $ego, string $prefixe): array
    {
        $pere = $population->pere($ego);
        $mere = $population->mere($ego);

        $tetes = [
            Branch::Pp->value => null !== $pere ? $population->pere($pere->id) : null,
            Branch::Pm->value => null !== $pere ? $population->mere($pere->id) : null,
            Branch::Mp->value => null !== $mere ? $population->pere($mere->id) : null,
            Branch::Mm->value => null !== $mere ? $population->mere($mere->id) : null,
        ];

        $noms = [];
        foreach ($tetes as $quart => $tete) {
            if (null !== $tete && !$population->masquee($tete->id) && '' !== trim($tete->nom)) {
                $noms[$prefixe.$quart] = trim($tete->nom);
            }
        }

        return $noms;
    }

    /** La définition unique de « Vérifié » : `bestQuay ≥ 2`. */
    public static function estVerifiee(PersonneDuReleve $personne): bool
    {
        return ($personne->bestQuay ?? -1) >= 2;
    }

    private static function renseigne(UncertainDate $date): bool
    {
        $lieu = $date->getPlace();

        return $date->isKnown() || (null !== $lieu && '' !== trim($lieu));
    }
}
