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
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\Service\AuditService;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  UNE ANALYSE, DE BOUT EN BOUT : l'agent propose, `Proposal` vérifie, le cercle filtre.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * 1. {@see AgentDeLAssistant} fait tourner le modèle jusqu'à `proposer` ;
 * 2. {@see TraducteurDePropositions} met ses arguments à la forme d'un corps de demande ;
 * 3. `Proposal` complète chaque corps (verrous lus en base) et le **valide sans rien
 *    écrire** — un corps refusé est retiré, avec un avertissement, jamais rendu cassé ;
 * 4. les corps complets sont gardés côté serveur ({@see MemoireDesAnalyses}) ;
 * 5. la réponse est composée **à travers le cercle du lecteur** ({@see VueDuLecteur}) ;
 * 6. les portraits repérés ne sont rendus que s'ils visent une fiche qui existe, ou un
 *    brouillon d'une demande de création **gardée** à l'étape 3 — les autres deviennent
 *    des avertissements.
 *
 * ── La règle de confidentialité de la réponse, point par point ──────────────────
 *
 * Pour une personne existante que le lecteur ne voit qu'en restreint (vivante, hors de
 * son cercle) :
 *
 * - `personnes[]` la décrit par le masque (« Personne vivante », révision du 2026-10-01
 *   de l'ADR 0003), `visibilite: "restreinte"`, sans dates ;
 * - `changements[].avant` vaut `null` — le front écrit « (valeur masquée) » ;
 * - le `corps` rendu est une **vue d'affichage** : `oldValue` et `baseVersion` de ses ops
 *   sont retirés. Le corps complet, lui, ne quitte pas le serveur.
 *
 * `titre`, `justification` et `resume` sont écrits par le modèle, à qui la consigne
 * interdit d'y recopier une donnée de l'arbre — et chaque personne confidentielle lui a
 * été signalée comme telle par les outils. Depuis la révision du 2026-10-01 de l'ADR 0003,
 * la consigne lui interdit aussi d'y **nommer** une personne confidentielle, et ces
 * textes — avertissements compris — passent par {@see VueDuLecteur::sansNomMasque()}
 * avant de partir : le vrai nom de chaque personne restreinte croisée y devient le masque.
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
     * @return array{analyseId: string, resume: string, demandes: list<array<string, mixed>>, portraits: list<array<string, mixed>>, avertissements: list<string>, restant: int}
     */
    public function analyser(EntreeDAnalyse $entree, int $userId, int $restant): array
    {
        $resultat = $this->agent->analyser($entree);
        $traduites = $this->traducteur->traduire($resultat->arguments, $entree->documents);

        $avertissements = $traduites->avertissements;
        $gardees = [];
        $rendues = [];
        /** @var array<int, string> $cles rang de la proposition traduite → `cle` de la demande gardée */
        $cles = [];

        foreach ($traduites->propositions as $rang => $proposition) {
            try {
                $verifiee = $this->depot->preparer($proposition->corps);
            } catch (DemandeIrrecevable $e) {
                $avertissements[] = \sprintf('La proposition « %s » a été écartée : %s', $proposition->titre, $e->getMessage());

                continue;
            }

            $cle = 'd'.(\count($gardees) + 1);
            $cles[$rang] = $cle;
            $gardees[$cle] = [
                'corps' => $verifiee->corps,
                'titre' => $proposition->titre,
                'justification' => $proposition->justification,
            ];
            $rendues[] = $this->presenter($cle, $proposition, $verifiee);
        }

        $portraits = [];

        foreach ($traduites->portraits as $portrait) {
            try {
                $portraits[] = $this->portrait('r'.(\count($portraits) + 1), $portrait, $cles);
            } catch (\UnexpectedValueException $e) {
                $avertissements[] = \sprintf('Un portrait proposé (document %d) a été écarté : %s', $portrait->document + 1, $e->getMessage());
            }
        }

        // Les textes libres du modèle, passés au carnet des noms masqués **après** les
        // boucles : `presenter()` et `portrait()` y ont ajouté les personnes restreintes
        // qu'ils citent. Les gardées aussi — leur justification devient le message de la
        // demande déposée, que « Mes demandes » relit.
        $masquer = $this->vue->sansNomMasque(...);

        foreach ($gardees as $cle => $gardee) {
            $gardees[$cle]['titre'] = $masquer($gardee['titre']);
            $gardees[$cle]['justification'] = $masquer($gardee['justification']);
        }

        foreach ($rendues as $rang => $rendue) {
            $rendues[$rang]['titre'] = $masquer($rendue['titre']);
            $rendues[$rang]['justification'] = $masquer($rendue['justification']);
        }

        foreach ($portraits as $rang => $portrait) {
            $portraits[$rang]['justification'] = $masquer($portrait['justification']);
        }

        $analyseId = $this->memoire->garder($userId, $gardees);

        $this->consignerLAppel($entree, $resultat, \count($traduites->propositions), \count($rendues), \count($portraits));

        return [
            'analyseId' => $analyseId,
            'resume' => '' === $traduites->resume ? 'L’assistant n’a pas rédigé de résumé.' : $masquer($traduites->resume),
            'demandes' => $rendues,
            'portraits' => $portraits,
            'avertissements' => array_values(array_map($masquer, $avertissements)),
            'restant' => $restant,
        ];
    }

    /**
     * Un portrait, tel que le front le reçoit — ou une `\UnexpectedValueException` qui dit
     * pourquoi il est écarté.
     *
     * - fiche existante : `personId` + `personne`, décrite **par le cercle du lecteur**
     *   (mêmes règles que `personnes[]` : restreinte ⇒ le masque, sans dates) ;
     * - brouillon : `demandeCle` (la demande de création **gardée**) + `brouillonRef`
     *   sans dièse (`p1`), que le front passe tel quel au téléversement.
     *
     * Le portrait n'est pas gardé côté serveur : le front a les fichiers, les recadre et
     * les téléverse par `POST /api/medias`, où ils attendent la validation comme tout
     * média déposé pour autrui.
     *
     * @param array<int, string> $cles
     *
     * @return array<string, mixed>
     */
    private function portrait(string $cle, PortraitTraduit $portrait, array $cles): array
    {
        $rendu = [
            'cle' => $cle,
            'document' => $portrait->document,
            'cadre' => $portrait->cadre,
            'justification' => $portrait->justification,
            'personId' => null,
            'personne' => null,
            'demandeCle' => null,
            'brouillonRef' => null,
        ];

        if (null !== $portrait->personId) {
            $personne = $this->arbre->personne($portrait->personId)
                ?? throw new \UnexpectedValueException('la personne qu’il désigne n’existe pas dans l’arbre.');

            $rendu['personId'] = $personne->id;
            $rendu['personne'] = $this->vue->personneCitee($personne);

            return $rendu;
        }

        $demandeCle = null === $portrait->proposition ? null : ($cles[$portrait->proposition] ?? null);

        if (null === $demandeCle || null === $portrait->brouillonRef) {
            throw new \UnexpectedValueException('la demande qui crée la personne visée a été écartée.');
        }

        $rendu['demandeCle'] = $demandeCle;
        $rendu['brouillonRef'] = ltrim($portrait->brouillonRef, '#');

        return $rendu;
    }

    /**
     * Une demande gardée, telle que le front la reçoit (`DemandeProposee` de
     * `lib/api/assistant.ts`).
     *
     * Publique : {@see SuggestionsDeRattachement} présente la demande de création d'un
     * compte « à rattacher » exactement de la même façon — mêmes personnes citées, mêmes
     * lignes de changement —, pour que l'administrateur lise les deux écrans pareil.
     *
     * @return array<string, mixed>
     */
    public function presenter(string $cle, PropositionTraduite $proposition, DemandeVerifiee $verifiee): array
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

            $sujet = self::extremite($lien, 'subjectPersonId', 'subjectDraftRef', $personnes, $noms, $restreintes);
            $autre = self::extremite($lien, 'otherPersonId', 'otherDraftRef', $personnes, $noms, $restreintes);
            $second = self::extremite($lien, 'other2PersonId', 'other2DraftRef', $personnes, $noms, $restreintes);

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
     * @param array<int, true>             $restreintes
     */
    private static function extremite(array $lien, string $clePersonne, string $cleBrouillon, array $personnes, array $noms, array $restreintes): ?string
    {
        $id = $lien[$clePersonne] ?? null;
        if (\is_int($id)) {
            // Plus même le nom pour une personne restreinte : depuis la révision du
            // 2026-10-01 de l'ADR 0003, un vivant hors cercle n'expose que le masque.
            if (isset($restreintes[$id])) {
                return CercleDeVisibiliteInterface::NOM_MASQUE;
            }

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
    private function consignerLAppel(EntreeDAnalyse $entree, ResultatDeLAgent $resultat, int $proposees, int $rendues, int $portraits): void
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
                'portraits_rendus' => $portraits,
            ],
        );
    }
}
