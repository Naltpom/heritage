<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

use App\Module\Assistant\Contract\ArbrePourLAssistant;
use App\Module\Assistant\Contract\DemandeIrrecevable;
use App\Module\Assistant\Contract\DemandeVerifiee;
use App\Module\Assistant\Contract\DepotPourLAssistant;
use App\Module\Assistant\Contract\PersonneDeLArbre;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Service\AuditService;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  UNE ANALYSE, DE BOUT EN BOUT : l'agent propose, `Proposal` vérifie, le cercle filtre.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * 1. {@see AgentDeLAssistant} fait tourner le modèle jusqu'à `proposer` ;
 * 2. {@see TraducteurDePropositions} met ses arguments à la forme d'un corps de demande ;
 * 3. `Proposal` complète chaque corps (verrous lus en base) et le **valide sans rien
 *    écrire** — un corps refusé est retiré, avec un avertissement, jamais rendu cassé ;
 * 4. les corps complets sont gardés côté serveur ({@see MemoireDesAnalyses}) ;
 * 5. la réponse est composée **à travers le cercle du lecteur** ({@see VueDuLecteur}).
 *
 * ── La règle de confidentialité de la réponse, point par point ──────────────────
 *
 * Pour une personne existante que le lecteur ne voit qu'en restreint (vivante, hors de
 * son cercle) :
 *
 * - `personnes[]` la décrit par son nom seul, `visibilite: "restreinte"`, sans dates ;
 * - `changements[].avant` vaut `null` — le front écrit « (valeur masquée) » ;
 * - le `corps` rendu est une **vue d'affichage** : `oldValue` et `baseVersion` de ses ops
 *   sont retirés. Le corps complet, lui, ne quitte pas le serveur.
 *
 * `titre`, `justification` et `resume` sont écrits par le modèle, à qui la consigne
 * interdit d'y recopier une donnée de l'arbre — et chaque personne confidentielle lui a
 * été signalée comme telle par les outils.
 */
final readonly class AnalyseParLAssistant
{
    public function __construct(
        private AgentDeLAssistant $agent,
        private TraducteurDePropositions $traducteur,
        private DepotPourLAssistant $depot,
        private ArbrePourLAssistant $arbre,
        private VueDuLecteur $vue,
        private MemoireDesAnalyses $memoire,
        private ReglagesDeLAssistant $reglages,
        private AuditService $audit,
    ) {
    }

    /**
     * @return array{analyseId: string, resume: string, demandes: list<array<string, mixed>>, avertissements: list<string>, restant: int}
     */
    public function analyser(EntreeDAnalyse $entree, int $userId, int $restant): array
    {
        $resultat = $this->agent->analyser($entree);
        $traduites = $this->traducteur->traduire($resultat->arguments);

        $avertissements = $traduites->avertissements;
        $gardees = [];
        $rendues = [];

        foreach ($traduites->propositions as $proposition) {
            try {
                $verifiee = $this->depot->preparer($proposition->corps);
            } catch (DemandeIrrecevable $e) {
                $avertissements[] = \sprintf('La proposition « %s » a été écartée : %s', $proposition->titre, $e->getMessage());

                continue;
            }

            $cle = 'd'.(\count($gardees) + 1);
            $gardees[$cle] = [
                'corps' => $verifiee->corps,
                'titre' => $proposition->titre,
                'justification' => $proposition->justification,
            ];
            $rendues[] = $this->presenter($cle, $proposition, $verifiee);
        }

        $analyseId = $this->memoire->garder($userId, $gardees);

        $this->consignerLAppel($entree, $resultat, \count($traduites->propositions), \count($rendues));

        return [
            'analyseId' => $analyseId,
            'resume' => '' === $traduites->resume ? 'L’assistant n’a pas rédigé de résumé.' : $traduites->resume,
            'demandes' => $rendues,
            'avertissements' => array_values($avertissements),
            'restant' => $restant,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presenter(string $cle, PropositionTraduite $proposition, DemandeVerifiee $verifiee): array
    {
        $corps = $verifiee->corps;
        $personnes = $this->personnesExistantes($corps);
        $restreintes = [];

        foreach ($personnes as $id => $personne) {
            if (!$this->vue->voitEnEntier($personne)) {
                $restreintes[$id] = true;
            }
        }

        return [
            'cle' => $cle,
            'titre' => $proposition->titre,
            'justification' => $proposition->justification,
            'confiance' => $proposition->confiance,
            'corps' => self::vueDAffichage($corps, $restreintes),
            'personnes' => array_values(array_map(fn (PersonneDeLArbre $p): array => $this->vue->personneCitee($p), $personnes)),
            'changements' => $this->changements($corps, $verifiee, $personnes, $restreintes),
        ];
    }

    /**
     * Le corps **tel que le navigateur peut le recevoir** : sur une personne restreinte,
     * les ops perdent `oldValue` et `baseVersion`. Clés **retirées**, pas mises à `null` —
     * `null` est une valeur possible d'un champ, et le front la prendrait pour « vide ».
     *
     * @param array<string, mixed> $corps
     * @param array<int, true>     $restreintes
     *
     * @return array<string, mixed>
     */
    private static function vueDAffichage(array $corps, array $restreintes): array
    {
        if (!\is_array($corps['ops'] ?? null)) {
            return $corps;
        }

        foreach ($corps['ops'] as $rang => $op) {
            if (\is_array($op) && \is_int($op['subjectId'] ?? null) && isset($restreintes[$op['subjectId']])) {
                unset($op['oldValue'], $op['baseVersion']);
                $corps['ops'][$rang] = $op;
            }
        }

        return $corps;
    }

    /**
     * Les personnes **existantes** citées par un corps : la cible, les sujets des ops, les
     * extrémités réelles des liens.
     *
     * @param array<string, mixed> $corps
     *
     * @return array<int, PersonneDeLArbre>
     */
    private function personnesExistantes(array $corps): array
    {
        $ids = [];

        if (\is_int($corps['targetId'] ?? null)) {
            $ids[] = $corps['targetId'];
        }

        foreach ([...(\is_array($corps['ops'] ?? null) ? $corps['ops'] : []), ...(\is_array($corps['liens'] ?? null) ? $corps['liens'] : [])] as $ligne) {
            if (!\is_array($ligne)) {
                continue;
            }

            foreach (['subjectId', 'subjectPersonId', 'otherPersonId', 'other2PersonId'] as $cle) {
                if (\is_int($ligne[$cle] ?? null)) {
                    $ids[] = $ligne[$cle];
                }
            }
        }

        $personnes = [];
        foreach (array_unique($ids) as $id) {
            $personne = $this->arbre->personne($id);

            if (null !== $personne) {
                $personnes[$id] = $personne;
            }
        }

        return $personnes;
    }

    /**
     * Une ligne lisible par op, par personne nouvelle, par lien.
     *
     * @param array<string, mixed>         $corps
     * @param array<int, PersonneDeLArbre> $personnes
     * @param array<int, true>             $restreintes
     *
     * @return list<array{libelle: string, avant: string|null, apres: string|null}>
     */
    private function changements(array $corps, DemandeVerifiee $verifiee, array $personnes, array $restreintes): array
    {
        $lignes = [];

        foreach ($verifiee->changements as $changement) {
            $lignes[] = [
                'libelle' => $changement->libelle,
                // La valeur d'avant d'une personne restreinte **ne part pas** : c'est une
                // donnée de l'arbre que le lecteur n'a pas le droit de lire.
                'avant' => isset($restreintes[$changement->personneId]) ? null : $changement->avant,
                'apres' => $changement->apres,
            ];
        }

        $noms = [];
        foreach (\is_array($corps['brouillons'] ?? null) ? $corps['brouillons'] : [] as $brouillon) {
            if (!\is_array($brouillon) || !\is_string($brouillon['localRef'] ?? null)) {
                continue;
            }

            $nom = self::nomDuBrouillon($brouillon);
            $noms[$brouillon['localRef']] = $nom.' (proposée)';
            $lignes[] = ['libelle' => 'Nouvelle personne', 'avant' => null, 'apres' => $nom.self::datesDuBrouillon($brouillon)];
        }

        foreach (\is_array($corps['liens'] ?? null) ? $corps['liens'] : [] as $lien) {
            if (!\is_array($lien)) {
                continue;
            }

            $sujet = self::extremite($lien, 'subjectPersonId', 'subjectDraftRef', $personnes, $noms);
            $autre = self::extremite($lien, 'otherPersonId', 'otherDraftRef', $personnes, $noms);
            $second = self::extremite($lien, 'other2PersonId', 'other2DraftRef', $personnes, $noms);

            $lignes[] = [
                'libelle' => 'Lien de parenté',
                'avant' => null,
                'apres' => \sprintf(
                    '%s %s %s',
                    $sujet,
                    match ($lien['kind'] ?? null) {
                        'enfant_de' => 'enfant de',
                        'parent_de' => 'parent de',
                        default => 'conjoint de',
                    },
                    null === $second ? $autre : $autre.' et '.$second,
                ),
            ];
        }

        return $lignes;
    }

    /**
     * @param array<mixed>                 $lien
     * @param array<int, PersonneDeLArbre> $personnes
     * @param array<string, string>        $noms
     */
    private static function extremite(array $lien, string $clePersonne, string $cleBrouillon, array $personnes, array $noms): ?string
    {
        $id = $lien[$clePersonne] ?? null;
        if (\is_int($id)) {
            // Le nom seul : il fait partie de ce qu'une personne restreinte expose.
            return isset($personnes[$id]) ? $personnes[$id]->nomAffiche : 'la personne n° '.$id;
        }

        $reference = $lien[$cleBrouillon] ?? null;

        return \is_string($reference) ? ($noms[$reference] ?? $reference) : null;
    }

    /** @param array<mixed> $brouillon */
    private static function nomDuBrouillon(array $brouillon): string
    {
        $nom = trim(implode(' ', array_filter(
            [$brouillon['givenNames'] ?? null, $brouillon['surname'] ?? null],
            static fn (mixed $partie): bool => \is_string($partie) && '' !== $partie,
        )));

        return '' === $nom ? 'Personne sans nom' : $nom;
    }

    /**
     * « , née le 3 mars 1921, Brest ; décès : vers 1990 » — composée depuis les blocs du
     * corps, c'est-à-dire depuis ce que le membre a apporté.
     *
     * @param array<mixed> $brouillon
     */
    private static function datesDuBrouillon(array $brouillon): string
    {
        $morceaux = [];

        foreach (['birth' => 'naissance', 'death' => 'décès'] as $cle => $libelle) {
            $bloc = $brouillon[$cle] ?? null;

            if (!\is_array($bloc)) {
                continue;
            }

            $date = self::reconstruire($bloc);
            $texte = $date->format();
            $lieu = $date->getPlace();
            $rendu = trim($texte.(null !== $lieu && '' !== $lieu ? ('' === $texte ? $lieu : ', '.$lieu) : ''));

            if ('' !== $rendu) {
                $morceaux[] = $libelle.' : '.$rendu;
            }
        }

        return [] === $morceaux ? '' : ' — '.implode(' ; ', $morceaux);
    }

    /** @param array<mixed> $bloc */
    private static function reconstruire(array $bloc): UncertainDate
    {
        $jour = static fn (mixed $v): ?\DateTimeImmutable => \is_string($v) && false !== ($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v)) ? $d : null;

        return new UncertainDate(
            \is_string($bloc['raw'] ?? null) ? $bloc['raw'] : null,
            DateQualifier::tryFrom(\is_string($bloc['qualifier'] ?? null) ? $bloc['qualifier'] : '') ?? DateQualifier::Inconnue,
            $jour($bloc['min'] ?? null),
            $jour($bloc['max'] ?? null),
            \is_string($bloc['place'] ?? null) ? $bloc['place'] : null,
        );
    }

    /**
     * Le journal d'audit : **des comptes, jamais le contenu, jamais la clé** — même
     * règle que `Question\Service\RedactionParGemini::consignerLAppel()`. Sans cette
     * ligne, « combien de documents sont partis chez le tiers ce mois-ci » n'aurait pas
     * de réponse.
     */
    private function consignerLAppel(EntreeDAnalyse $entree, ResultatDeLAgent $resultat, int $proposees, int $rendues): void
    {
        $this->audit->enregistrer(
            AuditAction::AppelModeleDeLangage,
            'Assistant\\Analyse',
            null,
            $this->reglages->modele(),
            [
                'documents_transmis' => \count($entree->documents),
                'texte_transmis' => '' !== trim($entree->texte),
                'tours' => $resultat->tours,
                'appels_d_outils' => $resultat->appelsDOutils,
                'demandes_proposees' => $proposees,
                'demandes_rendues' => $rendues,
            ],
        );
    }
}
