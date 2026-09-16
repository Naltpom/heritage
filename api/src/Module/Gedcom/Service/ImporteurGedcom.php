<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Service;

use App\Module\Gedcom\Dto\RapportImport;
use App\Module\Gedcom\Dto\ResultatAnalyse;
use App\Module\Gedcom\Entity\Issue;
use App\Module\Gedcom\Entity\Run;
use App\Module\Gedcom\Enum\RunDirection;
use App\Module\Gedcom\Enum\RunStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * L'orchestration d'un import : lire, analyser, écrire, **recompter, comparer**.
 *
 * La dernière étape est la raison d'être de la classe. Un import silencieusement
 * incomplet est le pire résultat possible — l'application marcherait, avec un arbre
 * amputé que personne ne remarquerait avant des mois. On recompte donc depuis la base,
 * dans la transaction, et un écart l'annule.
 *
 * **Ce qui est dans la transaction et ce qui n'y est pas.** L'écriture de `Tree` y est.
 * `ged_run` et `ged_issue` n'y sont **pas** : ce sont les pièces du diagnostic, et un
 * import qui échoue doit laisser derrière lui de quoi comprendre pourquoi. Les annuler
 * avec le reste reviendrait à effacer le rapport d'accident avec l'accident.
 */
final class ImporteurGedcom
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly AnalyseurGedcom $analyseur,
        private readonly EcrivainTree $ecrivain,
        private readonly TotauxAttendus $reference,
        private readonly GardeDesComptes $garde,
    ) {
    }

    /**
     * @param array<string, int>|null $attendusManuels totaux fournis à la main, pour un
     *                                                 fichier qui n'a pas de jeu de référence
     *
     * @throws \RuntimeException si le fichier est illisible, si une anomalie bloquante
     *                           est relevée, ou si les dénombrements ne retombent pas
     */
    public function importer(string $chemin, bool $aBlanc = false, ?string $ego = null, ?array $attendusManuels = null, bool $premierImport = false): RapportImport
    {
        $depart = microtime(true);

        if (!is_file($chemin) || !is_readable($chemin)) {
            throw new \RuntimeException(\sprintf('Fichier GEDCOM introuvable ou illisible : %s', $chemin));
        }

        // **Avant d'ouvrir le fichier, et avant même d'ouvrir une ligne `ged_run`.**
        // Un refus doit être franc et ne rien laisser derrière lui — pas même la trace
        // d'une exécution qui n'a jamais commencé. À blanc, on ne garde rien : lire et
        // compter ne met aucun compte en danger, et c'est justement le mode dans lequel
        // on veut pouvoir regarder une base qu'on ne comprend pas.
        if (!$aBlanc && !$premierImport) {
            $refus = $this->garde->refus($this->garde->constater());

            if (null !== $refus) {
                throw new \RuntimeException($refus);
            }
        }

        $sha256 = (string) hash_file('sha256', $chemin);
        $octets = (int) filesize($chemin);

        $run = new Run(RunDirection::Import, basename($chemin), $aBlanc);
        $run->setFileSha256($sha256)->setByteSize($octets)->setLaunchedBy(null, 'console');

        $em = $this->manager();
        $em->persist($run);
        $em->flush();

        $runId = $run->getId();

        try {
            $analyse = $this->analyseur->analyser($chemin, $ego);
        } catch (\Throwable $erreur) {
            $this->cloturer($runId, RunStatus::Echoue, $erreur->getMessage(), null, null);

            throw $erreur;
        }

        $anomalies = $analyse->anomalies;
        $attendus = $attendusManuels ?? $this->reference->pour($sha256);

        if (null === $attendus) {
            $anomalies->avertir(
                CollecteurAnomalies::SANS_TOTAUX_DE_REFERENCE,
                \sprintf(
                    'Aucun jeu de totaux de référence pour l’empreinte %s… : les dénombrements sont rapportés, pas contrôlés.',
                    substr($sha256, 0, 12),
                ),
            );
        }

        try {
            $obtenus = $aBlanc
                ? $analyse->totaux()
                : $this->ecrire($analyse, $attendus, $anomalies);
        } catch (\Throwable $erreur) {
            $this->cloturer($runId, RunStatus::Echoue, $erreur->getMessage(), $analyse->charset, $anomalies);

            throw $erreur;
        }

        $rapport = new RapportImport(
            $obtenus,
            $attendus,
            $anomalies,
            $chemin,
            $sha256,
            $octets,
            $analyse->charset,
            $analyse->ego,
            $aBlanc,
            microtime(true) - $depart,
            $runId,
        );

        // Hors `--dry-run`, un écart a déjà fait échouer l'écriture plus haut : on ne
        // passe ici avec des écarts qu'en exécution à blanc. Ils doivent tout de même
        // marquer l'exécution comme échouée — c'est le mode dans lequel on découvre qu'un
        // fichier est mauvais **avant** d'avoir touché la base.
        if (!$rapport->estConforme()) {
            $this->consignerEcarts($rapport, $anomalies);
        }

        $this->cloturer(
            $runId,
            $rapport->estConforme() ? RunStatus::Reussi : RunStatus::Echoue,
            $rapport->estConforme() ? null : $this->resumerEcarts($rapport),
            $analyse->charset,
            $anomalies,
            $obtenus,
        );

        return $rapport;
    }

    /**
     * @param array<string, int>|null $attendus
     *
     * @return array<string, int>
     *
     * @throws \RuntimeException
     */
    private function ecrire(ResultatAnalyse $analyse, ?array $attendus, CollecteurAnomalies $anomalies): array
    {
        if ($anomalies->aDesErreurs()) {
            // § 8.4 du modèle : une anomalie de sévérité « erreur » bloque l'import hors
            // exécution à blanc. Un lien cassé ou un octet indécodable ne se rattrape pas
            // à l'écriture.
            throw new \RuntimeException('Le fichier porte des anomalies de sévérité « erreur » : import refusé. Relancer avec --dry-run pour en voir le détail.');
        }

        $connexion = $this->manager()->getConnection();
        $connexion->beginTransaction();

        try {
            $obtenus = $this->ecrivain->ecrire($analyse);
            $ecarts = $this->comparer($obtenus, $attendus);

            if ([] !== $ecarts) {
                foreach ($ecarts as $cle => $ecart) {
                    $anomalies->erreur(
                        CollecteurAnomalies::DENOMBREMENT_INATTENDU,
                        \sprintf('Dénombrement « %s » : attendu %d, obtenu %d.', $cle, $ecart['attendu'], $ecart['obtenu']),
                    );
                }

                // On annule tout. Une base à moitié peuplée est pire qu'une base vide :
                // la seconde se voit, la première se découvre six mois plus tard sur une
                // branche que personne n'ouvrait.
                throw new \RuntimeException("Les dénombrements ne retombent pas, l’import est annulé :\n  - ".implode("\n  - ", array_map(static fn (string $cle, array $e): string => \sprintf('%s : attendu %d, obtenu %d', $cle, $e['attendu'], $e['obtenu']), array_keys($ecarts), array_values($ecarts))));
            }

            $connexion->commit();

            return $obtenus;
        } catch (\Throwable $erreur) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }

            throw $erreur;
        }
    }

    /**
     * @param array<string, int>      $obtenus
     * @param array<string, int>|null $attendus
     *
     * @return array<string, array{attendu: int, obtenu: int}>
     */
    private function comparer(array $obtenus, ?array $attendus): array
    {
        $ecarts = [];

        foreach ($attendus ?? [] as $cle => $attendu) {
            $obtenu = $obtenus[$cle] ?? 0;

            if ($obtenu !== $attendu) {
                $ecarts[$cle] = ['attendu' => $attendu, 'obtenu' => $obtenu];
            }
        }

        return $ecarts;
    }

    private function consignerEcarts(RapportImport $rapport, CollecteurAnomalies $anomalies): void
    {
        foreach ($rapport->ecarts() as $cle => $ecart) {
            $anomalies->erreur(
                CollecteurAnomalies::DENOMBREMENT_INATTENDU,
                \sprintf('Dénombrement « %s » : attendu %d, obtenu %d.', $cle, $ecart['attendu'], $ecart['obtenu']),
            );
        }
    }

    private function resumerEcarts(RapportImport $rapport): string
    {
        $lignes = [];

        foreach ($rapport->ecarts() as $cle => $ecart) {
            $lignes[] = \sprintf('%s : attendu %d, obtenu %d', $cle, $ecart['attendu'], $ecart['obtenu']);
        }

        return 'Dénombrements en écart — '.implode(' ; ', $lignes);
    }

    /**
     * Ferme la ligne `ged_run` et déverse les anomalies dans `ged_issue`.
     *
     * Le gestionnaire est **redemandé au registre** plutôt que gardé en propriété : une
     * transaction annulée peut avoir fermé le précédent, et c'est précisément le cas où
     * l'on tient le plus à écrire le journal.
     *
     * @param array<string, int>|null $totaux
     */
    private function cloturer(?int $runId, RunStatus $etat, ?string $erreur, ?string $charset, ?CollecteurAnomalies $anomalies, ?array $totaux = null): void
    {
        if (null === $runId) {
            return;
        }

        $em = $this->registry->getManager();

        if ($em instanceof EntityManagerInterface && !$em->isOpen()) {
            $this->registry->resetManager();
        }

        $em = $this->manager();
        $run = $em->find(Run::class, $runId);

        if (!$run instanceof Run) {
            return;
        }

        $run->setGedcomCharset($charset)->setCounts($totaux)->terminer($etat, $erreur);

        foreach ($anomalies?->anomalies() ?? [] as $anomalie) {
            $issue = new Issue($run, $anomalie->severite, $anomalie->code, $anomalie->message);
            $issue->situer(
                $anomalie->ligne,
                null === $anomalie->xref ? null : mb_substr($anomalie->xref, 0, 20, 'UTF-8'),
                null === $anomalie->tag ? null : mb_substr($anomalie->tag, 0, 20, 'UTF-8'),
                $anomalie->ligneBrute,
            );

            $em->persist($issue);
        }

        $em->flush();
    }

    private function manager(): EntityManagerInterface
    {
        $em = $this->registry->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
