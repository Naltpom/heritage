<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

use App\Module\Assistant\Contract\ArbrePourLAssistant;
use App\Module\Assistant\Contract\DemandeIrrecevable;
use App\Module\Assistant\Contract\DemandeVerifiee;
use App\Module\Assistant\Contract\DepotPourLAssistant;
use App\Module\Assistant\Contract\PersonneDeLArbre;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Service\AuditService;
use App\Module\Membership\Contract\ComptesARattacher;
use App\Module\Membership\Contract\DeclarationDuCompte;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LA SUGGESTION DE RATTACHEMENT : calculée une fois, gardée sur le compte, relue fraîche.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * 1. {@see AgentDeRattachement} fait tourner le modèle jusqu'à `conclure` ;
 * 2. ce service **juge** sa conclusion — le modèle n'est pas de confiance :
 *    - une fiche proposée qui n'existe pas, ou qui n'est pas vivante, est écartée avec un
 *      avertissement (et `fiche_existante` sans fiche devient `indecise`) ;
 *    - la demande de création passe par {@see TraducteurDePropositions}, puis
 *      l'identité de `#p1` est **reprise de la déclaration** ({@see CorpsDuTitulaire}),
 *      puis `Proposal` la vérifie sans rien écrire (`DepotPourLAssistant::preparer()`) :
 *      `recevable` et `motifIrrecevable` disent si « Créer la fiche et rattacher » passera
 *      tel quel, ou s'il faut d'abord choisir un parent ;
 *    - `absente` sans création utilisable reçoit le corps minimal tiré de la déclaration :
 *      la carte reste actionnable ;
 * 3. le résultat est gardé sur le compte (`ComptesARattacher::enregistrerLaSuggestion()`)
 *    — `Membership` le stocke et le purge, **il n'en lit jamais le contenu** : son schéma
 *    appartient à ce seul fichier ;
 * 4. à chaque lecture, les champs **volatils** sont recalculés ({@see presenter()}) : le
 *    nom et les dates des fiches citées, et `dejaRattachee` — une autre inscription a pu
 *    réclamer la fiche depuis. Une fiche disparue devient `fiche: null`, `indecise`.
 *
 * ── Ce qui est rendu à l'administrateur, et pourquoi sans filtre de cercle ─────
 *
 * Toutes ces routes sont sous `/api/administration` : le lecteur est un administrateur,
 * dont le cercle couvre l'arbre entier. `VueDuLecteur` s'applique quand même aux
 * personnes citées — c'est la même porte de sortie que l'analyse, et elle ne coûte rien.
 *
 * ── Le journal d'audit : des comptes, jamais le contenu ─────────────────────────
 *
 * `AppelModeleDeLangage`, sujet `Assistant\Rattachement` : tours, appels d'outils,
 * verdict. Ni la description, ni la réponse du modèle, ni la clé.
 */
final readonly class SuggestionsDeRattachement
{
    private const array VERDICTS = ['fiche_existante', 'absente', 'indecise'];

    private const array CONFIANCES = ['haute', 'moyenne', 'faible'];

    private const int ALTERNATIVES_MAXIMALES = 3;

    private const int AVERTISSEMENTS_MAXIMAUX = 10;

    public function __construct(
        private AgentDeRattachement $agent,
        private TraducteurDePropositions $traducteur,
        private DepotPourLAssistant $depot,
        private ArbrePourLAssistant $arbre,
        private AnalyseParLAssistant $analyse,
        private ComptesARattacher $comptes,
        private ReglagesDeLAssistant $reglages,
        private AuditService $audit,
    ) {
    }

    /**
     * Fait tourner le modèle, juge sa conclusion, la **garde sur le compte**, et la rend
     * telle que le front la lit.
     *
     * @return array<string, mixed> `SuggestionDeRattachement`
     */
    public function calculer(DeclarationDuCompte $declaration): array
    {
        $resultat = $this->agent->analyser($declaration);
        $suggestion = $this->juger($declaration, $resultat->arguments);
        $le = new \DateTimeImmutable();

        $gardee = $this->comptes->enregistrerLaSuggestion($declaration->memberId, $suggestion, $le);

        // L'appel a eu lieu et a coûté : il se journalise, gardé ou non.
        $this->consignerLAppel($declaration, $resultat, $suggestion);

        if (!$gardee) {
            // Le compte a été refusé — ou rattaché — **pendant** l'analyse, par une autre
            // requête : il n'attend plus rien, et la suggestion ne doit ni rester sur lui ni
            // sortir. Le même `404` que pour un compte déjà décidé ; c'est lui qui fait
            // disparaître la carte côté front.
            throw new NotFoundException('Ce compte n’attend plus de rattachement.');
        }

        return $this->presenter($declaration, $suggestion, $le);
    }

    /**
     * La suggestion gardée, **champs volatils recalculés**. `null` s'il n'y en a pas.
     *
     * @return array<string, mixed>|null
     */
    public function enCache(DeclarationDuCompte $declaration): ?array
    {
        if (null === $declaration->suggestion || null === $declaration->suggestionAt) {
            return null;
        }

        return $this->presenter($declaration, $declaration->suggestion, $declaration->suggestionAt);
    }

    /**
     * La conclusion du modèle, jugée : ce qui est gardé sur le compte.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function juger(DeclarationDuCompte $declaration, array $arguments): array
    {
        $avertissements = [];

        foreach (\is_array($arguments['avertissements'] ?? null) ? $arguments['avertissements'] : [] as $avertissement) {
            if (\is_string($avertissement) && '' !== trim($avertissement) && \count($avertissements) < self::AVERTISSEMENTS_MAXIMAUX) {
                $avertissements[] = self::texte($avertissement, 500);
            }
        }

        $verdict = \in_array($arguments['verdict'] ?? null, self::VERDICTS, true) ? $arguments['verdict'] : 'indecise';
        $fiche = null;

        if ('fiche_existante' === $verdict) {
            $brute = \is_array($arguments['fiche'] ?? null) ? $arguments['fiche'] : [];
            $personne = $this->personneVivante(self::entier($brute['personId'] ?? null), 'La fiche proposée', $avertissements);

            if (null !== $personne) {
                $fiche = [
                    'personId' => $personne->id,
                    'confiance' => \in_array($brute['confiance'] ?? null, self::CONFIANCES, true) ? $brute['confiance'] : 'moyenne',
                    'justification' => self::texte($brute['justification'] ?? null, 2000),
                ];
            } else {
                $verdict = 'indecise';
            }
        }

        $alternatives = [];
        $vues = null === $fiche ? [] : [$fiche['personId'] => true];

        foreach (\is_array($arguments['alternatives'] ?? null) ? array_values($arguments['alternatives']) : [] as $alternative) {
            if (!\is_array($alternative) || \count($alternatives) >= self::ALTERNATIVES_MAXIMALES) {
                continue;
            }

            $id = self::entier($alternative['personId'] ?? null);

            if (null === $id || isset($vues[$id])) {
                continue;
            }

            $personne = $this->personneVivante($id, 'Une autre fiche possible', $avertissements);

            if (null !== $personne) {
                $vues[$id] = true;
                $alternatives[] = ['personId' => $id, 'justification' => self::texte($alternative['justification'] ?? null, 1000)];
            }
        }

        // Avant la composition : la traduction de la création complète les avertissements.
        $creation = 'fiche_existante' === $verdict ? null : $this->creation($declaration, $arguments['creation'] ?? null, 'absente' === $verdict, $avertissements);
        $resume = self::texte($arguments['resume'] ?? null, 1000);

        return [
            'modele' => $this->reglages->modele(),
            'verdict' => $verdict,
            'resume' => '' === $resume ? 'L’analyse n’a pas rédigé de résumé.' : $resume,
            'avertissements' => $avertissements,
            'fiche' => $fiche,
            'alternatives' => $alternatives,
            'creation' => $creation,
        ];
    }

    /**
     * La demande de création proposée, jugée et présentée — ou `null`.
     *
     * @param list<string> $avertissements complétée de ce que la traduction a écarté
     *
     * @return array<string, mixed>|null
     */
    private function creation(DeclarationDuCompte $declaration, mixed $brute, bool $obligatoire, array &$avertissements): ?array
    {
        $proposition = null;

        if (\is_array($brute)) {
            $traduites = $this->traducteur->traduire(['demandes' => [['nature' => 'creation_personne'] + $brute]]);
            $proposition = $traduites->propositions[0] ?? null;

            foreach ($traduites->avertissements as $avertissement) {
                $avertissements[] = $avertissement;
            }
        }

        if (null === $proposition && !$obligatoire) {
            return null;
        }

        $nom = trim($declaration->givenNames.' '.$declaration->surname);
        $proposition ??= new PropositionTraduite(
            \sprintf('Ajouter %s', $nom),
            'Fiche tirée de la seule déclaration de la personne : l’analyse n’a relié aucun parent.',
            'faible',
            CorpsDuTitulaire::minimal($declaration),
        );

        $corps = CorpsDuTitulaire::imposerLIdentite($proposition->corps, $declaration);
        $proposition = new PropositionTraduite($proposition->titre, $proposition->justification, $proposition->confiance, $corps);

        try {
            $verifiee = $this->depot->preparer($corps);
            $recevable = true;
            $motif = null;
        } catch (DemandeIrrecevable $e) {
            $verifiee = new DemandeVerifiee($corps, []);
            $recevable = false;
            $motif = $e->getMessage();
        }

        $presentee = $this->analyse->presenter('c1', $proposition, $verifiee);

        return [
            'titre' => $presentee['titre'],
            'justification' => $presentee['justification'],
            'confiance' => $presentee['confiance'],
            'corps' => $verifiee->corps,
            'changements' => $presentee['changements'],
            'personnes' => $presentee['personnes'],
            'recevable' => $recevable,
            'motifIrrecevable' => $motif,
        ];
    }

    /**
     * La forme rendue au front (`SuggestionDeRattachement`), **champs volatils
     * recalculés** : nom, dates, `dejaRattachee`. Une fiche disparue (ou morte depuis)
     * devient `fiche: null`, avec un avertissement et le verdict `indecise`.
     *
     * @param array<string, mixed> $gardee
     *
     * @return array<string, mixed>
     */
    private function presenter(DeclarationDuCompte $declaration, array $gardee, \DateTimeImmutable $le): array
    {
        $avertissements = array_values(array_filter(
            \is_array($gardee['avertissements'] ?? null) ? $gardee['avertissements'] : [],
            'is_string',
        ));
        $verdict = \in_array($gardee['verdict'] ?? null, self::VERDICTS, true) ? $gardee['verdict'] : 'indecise';
        $fiche = null;
        $brute = \is_array($gardee['fiche'] ?? null) ? $gardee['fiche'] : null;

        if (null !== $brute) {
            $personne = $this->personneVivante(self::entier($brute['personId'] ?? null), 'La fiche proposée', $avertissements, 'n’existe plus dans l’arbre');

            if (null === $personne) {
                $verdict = 'indecise';
            } else {
                $fiche = [
                    'personId' => $personne->id,
                    'displayName' => $personne->nomAffiche,
                    'ligneDates' => VueDuLecteur::ligneDates($personne->naissance, $personne->deces),
                    'confiance' => \in_array($brute['confiance'] ?? null, self::CONFIANCES, true) ? $brute['confiance'] : 'moyenne',
                    'justification' => \is_string($brute['justification'] ?? null) ? $brute['justification'] : '',
                    'dejaRattachee' => $this->comptes->estDejaRattachee($personne->id),
                ];
            }
        }

        $alternatives = [];
        foreach (\is_array($gardee['alternatives'] ?? null) ? $gardee['alternatives'] : [] as $alternative) {
            $personne = \is_array($alternative) ? $this->arbre->personne(self::entier($alternative['personId'] ?? null) ?? 0) : null;

            // Silencieux ici : une alternative disparue ne change pas la conclusion.
            if (null === $personne || !$personne->vivante) {
                continue;
            }

            $alternatives[] = [
                'personId' => $personne->id,
                'displayName' => $personne->nomAffiche,
                'ligneDates' => VueDuLecteur::ligneDates($personne->naissance, $personne->deces),
                'justification' => \is_string($alternative['justification'] ?? null) ? $alternative['justification'] : '',
            ];
        }

        return [
            'memberId' => $declaration->memberId,
            'calculeeLe' => $le->format(\DateTimeInterface::ATOM),
            'modele' => \is_string($gardee['modele'] ?? null) ? $gardee['modele'] : $this->reglages->modele(),
            'verdict' => $verdict,
            'resume' => \is_string($gardee['resume'] ?? null) ? $gardee['resume'] : '',
            'avertissements' => $avertissements,
            'fiche' => $fiche,
            'alternatives' => $alternatives,
            'creation' => \is_array($gardee['creation'] ?? null) ? $gardee['creation'] : null,
        ];
    }

    /**
     * La personne, si elle existe **et** est vivante ; sinon `null`, et un avertissement.
     *
     * @param list<string> $avertissements
     */
    private function personneVivante(?int $id, string $sujet, array &$avertissements, string $absente = 'n’existe pas dans l’arbre'): ?PersonneDeLArbre
    {
        if (null === $id) {
            $avertissements[] = \sprintf('%s ne désignait aucune personne : elle a été écartée.', $sujet);

            return null;
        }

        $personne = $this->arbre->personne($id);

        if (null === $personne) {
            $avertissements[] = \sprintf('%s (n° %d) %s : elle a été écartée.', $sujet, $id, $absente);

            return null;
        }

        if (!$personne->vivante) {
            $avertissements[] = \sprintf('%s (%s) est une personne décédée : elle a été écartée.', $sujet, $personne->nomAffiche);

            return null;
        }

        return $personne;
    }

    /**
     * Des comptes, jamais le contenu : ni la description, ni la réponse du modèle.
     *
     * @param array<string, mixed> $suggestion
     */
    private function consignerLAppel(DeclarationDuCompte $declaration, ResultatDeLAgent $resultat, array $suggestion): void
    {
        $this->audit->enregistrer(
            AuditAction::AppelModeleDeLangage,
            'Assistant\\Rattachement',
            (string) $declaration->memberId,
            $this->reglages->modele(),
            [
                'tours' => $resultat->tours,
                'appels_d_outils' => $resultat->appelsDOutils,
                'verdict' => $suggestion['verdict'],
                'alternatives' => \count(\is_array($suggestion['alternatives']) ? $suggestion['alternatives'] : []),
                'creation_proposee' => null !== $suggestion['creation'],
            ],
        );
    }

    /** `12`, `12.0` et `"12"` désignent la même fiche ; rien d'autre n'est un identifiant. */
    private static function entier(mixed $valeur): ?int
    {
        return match (true) {
            \is_int($valeur) => $valeur > 0 ? $valeur : null,
            \is_float($valeur) => $valeur > 0 && floor($valeur) === $valeur ? (int) $valeur : null,
            \is_string($valeur) && 1 === preg_match('/^\s*\d{1,9}\s*$/', $valeur) => (int) $valeur > 0 ? (int) $valeur : null,
            default => null,
        };
    }

    private static function texte(mixed $valeur, int $longueur): string
    {
        return \is_string($valeur) ? mb_substr(trim($valeur), 0, $longueur) : '';
    }
}
