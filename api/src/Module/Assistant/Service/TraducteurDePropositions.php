<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

/**
 * Les arguments de `proposer` → des **corps de demande** à la forme de `POST /api/demandes`.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  CE QUE LE MODÈLE REND N'EST PAS DE CONFIANCE, ET CE FICHIER LE TRAITE AINSI.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Tout ce qui arrive ici a été écrit par un modèle : des types approximatifs (`12.0`
 * pour `12`), des références mal formées (`p1` pour `#p1`), des champs hors liste, des
 * dates impossibles. La règle est simple : **on traduit ce qui est sans ambiguïté, on
 * écarte le reste, et on le dit** dans `avertissements`. On ne « répare » jamais une
 * donnée en devinant — une date réparée est une date inventée.
 *
 * Ce fichier ne valide pas les demandes au sens du dépôt : c'est `Proposal` qui le fait,
 * avec ses propres constructeurs (`Contract\DepotPourLAssistant::preparer()`). Il produit
 * seulement des corps **bien formés**, que le dépôt peut juger.
 *
 * ── Les trois formes produites ───────────────────────────────────────────────────
 *
 * | Nature | Corps |
 * |---|---|
 * | `modification_personne` | `targetId` + `ops` **sans** `valueKind` / `oldValue` / `baseVersion` — `Proposal` les lit en base |
 * | `creation_personne` | `brouillons` (`#p1`…) + `liens` |
 * | `ajout_lien` | `targetKind`/`targetId` + `liens`, aucune fiche |
 *
 * ── Les portraits ─────────────────────────────────────────────────────────────────
 *
 * `portraits` désigne un visage dans une photo jointe. Le traducteur vérifie ce qu'il
 * peut vérifier seul : le document existe **et c'est une image** (jamais un PDF ni un
 * enregistrement), le cadre est borné (0–1000) et non vide, la personne est un
 * identifiant ou la référence `#p<n>` d'un brouillon d'une demande de création
 * **traduite** — sans ambiguïté. Un portrait par personne au plus. Ce qui ne se vérifie
 * qu'en base (la fiche existe, la demande de création est gardée par le dépôt) est
 * laissé à {@see AnalyseParLAssistant}.
 */
final class TraducteurDePropositions
{
    /** Au-delà, le modèle a dérivé : vingt demandes, c'est déjà une après-midi de relecture. */
    private const int DEMANDES_MAXIMALES = 20;

    private const array CHAMPS_TEXTE = ['givenNames', 'surname', 'marriedName', 'nickname'];

    private const array SEXES = ['masculin', 'feminin', 'inconnu'];

    private const array NATURES_DE_LIEN = ['enfant_de', 'parent_de', 'conjoint_de'];

    private const array CONFIANCES = ['haute', 'moyenne', 'faible'];

    /** Six documents au plus, quelques visages chacun : au-delà, le modèle a dérivé. */
    private const int PORTRAITS_MAXIMAUX = 12;

    /** Le repère des boîtes de Gemini : chaque coordonnée entre 0 et 1000. */
    private const int REPERE = 1000;

    /**
     * @param array<string, mixed> $arguments
     * @param list<DocumentJoint>  $documents les documents envoyés, dans l'ordre de `fichiers[]` :
     *                                        un portrait ne vaut que sur l'un d'eux, et sur une image
     */
    public function traduire(array $arguments, array $documents = []): PropositionsTraduites
    {
        $avertissements = [];

        foreach (\is_array($arguments['avertissements'] ?? null) ? $arguments['avertissements'] : [] as $avertissement) {
            if (\is_string($avertissement) && '' !== trim($avertissement) && \count($avertissements) < 10) {
                $avertissements[] = self::texte($avertissement, 500);
            }
        }

        $propositions = [];
        // Pour les portraits : le rang de la demande **telle que le modèle l'a écrite** →
        // le rang de la proposition traduite, et, pour une création, la table de ses
        // références (« p1 » du modèle → « #p1 » canonique).
        $rangs = [];
        $referencesParProposition = [];
        $demandes = \is_array($arguments['demandes'] ?? null) ? array_values($arguments['demandes']) : [];

        if (\count($demandes) > self::DEMANDES_MAXIMALES) {
            $avertissements[] = \sprintf('L’assistant a proposé %d demandes ; seules les %d premières sont présentées.', \count($demandes), self::DEMANDES_MAXIMALES);
            $demandes = \array_slice($demandes, 0, self::DEMANDES_MAXIMALES);
        }

        foreach ($demandes as $rangBrut => $demande) {
            if (!\is_array($demande)) {
                continue;
            }

            /** @var array<string, mixed> $demande */
            $titre = self::texte($demande['titre'] ?? null, 200);
            $references = [];

            try {
                $corps = match ($demande['nature'] ?? null) {
                    'modification_personne' => $this->modification($demande),
                    'creation_personne' => $this->creation($demande, $references),
                    'ajout_lien' => $this->ajoutDeLien($demande),
                    default => throw new \UnexpectedValueException('sa nature n’est pas de celles que l’assistant peut proposer.'),
                };
            } catch (\UnexpectedValueException $e) {
                $avertissements[] = \sprintf('La proposition « %s » a été écartée : %s', '' === $titre ? 'sans titre' : $titre, $e->getMessage());

                continue;
            }

            $confiance = $demande['confiance'] ?? null;

            $rangs[$rangBrut] = \count($propositions);
            $referencesParProposition[\count($propositions)] = $references;
            $propositions[] = new PropositionTraduite(
                '' === $titre ? self::titreParDefaut($corps) : $titre,
                self::texte($demande['justification'] ?? null, 2000),
                \is_string($confiance) && \in_array($confiance, self::CONFIANCES, true) ? $confiance : 'moyenne',
                $corps,
            );
        }

        $portraits = $this->portraits($arguments, $documents, $rangs, $referencesParProposition, $avertissements);

        return new PropositionsTraduites(self::texte($arguments['resume'] ?? null, 1000), $propositions, $avertissements, $portraits);
    }

    /**
     * @param array<string, mixed>              $arguments
     * @param list<DocumentJoint>               $documents
     * @param array<int, int>                   $rangs                    rang de la demande brute → rang de la proposition
     * @param array<int, array<string, string>> $referencesParProposition
     * @param list<string>                      $avertissements           complétée des portraits écartés
     *
     * @return list<PortraitTraduit>
     */
    private function portraits(array $arguments, array $documents, array $rangs, array $referencesParProposition, array &$avertissements): array
    {
        $bruts = \is_array($arguments['portraits'] ?? null) ? array_values($arguments['portraits']) : [];

        if (\count($bruts) > self::PORTRAITS_MAXIMAUX) {
            $avertissements[] = \sprintf('L’assistant a repéré %d portraits ; seuls les %d premiers sont présentés.', \count($bruts), self::PORTRAITS_MAXIMAUX);
            $bruts = \array_slice($bruts, 0, self::PORTRAITS_MAXIMAUX);
        }

        $portraits = [];
        $dejaVisees = [];

        foreach ($bruts as $brut) {
            if (!\is_array($brut)) {
                continue;
            }

            try {
                $portrait = $this->portrait($brut, $documents, $rangs, $referencesParProposition);
            } catch (\UnexpectedValueException $e) {
                $rang = self::entier($brut['document'] ?? null);
                $avertissements[] = \sprintf(
                    'Un portrait proposé%s a été écarté : %s',
                    null === $rang ? '' : \sprintf(' (document %d)', $rang),
                    $e->getMessage(),
                );

                continue;
            }

            // Un portrait par personne : le premier proposé l'emporte, les suivants sont dits.
            $personne = null !== $portrait->personId
                ? 'fiche:'.$portrait->personId
                : 'brouillon:'.$portrait->proposition.':'.$portrait->brouillonRef;

            if (isset($dejaVisees[$personne])) {
                $avertissements[] = \sprintf('Un second portrait proposé pour la même personne (document %d) a été écarté : un seul portrait par personne.', $portrait->document + 1);

                continue;
            }

            $dejaVisees[$personne] = true;
            $portraits[] = $portrait;
        }

        return $portraits;
    }

    /**
     * Un portrait, ou une `\UnexpectedValueException` qui dit pourquoi il est écarté.
     *
     * @param array<mixed>                      $brut
     * @param list<DocumentJoint>               $documents
     * @param array<int, int>                   $rangs
     * @param array<int, array<string, string>> $referencesParProposition
     */
    private function portrait(array $brut, array $documents, array $rangs, array $referencesParProposition): PortraitTraduit
    {
        // ── Le document : rang 1-based côté modèle (« document 2 »), 0-based en sortie.
        $rang = self::entier($brut['document'] ?? null);
        $document = null === $rang ? null : ($documents[$rang - 1] ?? null);

        if (null === $rang || null === $document) {
            throw new \UnexpectedValueException('il ne désigne aucun document joint.');
        }

        // Le type est celui **reniflé** par le contrôleur, pas celui annoncé.
        if (!str_starts_with($document->typeMime, 'image/')) {
            $source = str_starts_with($document->typeMime, 'audio/') ? 'd’un enregistrement' : 'd’un PDF';

            throw new \UnexpectedValueException(\sprintf('un portrait ne se tire que d’une photo, jamais %s.', $source));
        }

        // ── Le cadre : dans le repère, et d'aire non nulle. Jamais « réparé ». ─────
        $cadre = \is_array($brut['cadre'] ?? null) ? $brut['cadre'] : [];
        $bornes = [];

        foreach (['haut', 'gauche', 'bas', 'droite'] as $cote) {
            $bornes[$cote] = self::coordonnee($cadre[$cote] ?? null)
                ?? throw new \UnexpectedValueException('son cadre est incomplet ou sort de l’image.');
        }

        if ($bornes['haut'] >= $bornes['bas'] || $bornes['gauche'] >= $bornes['droite']) {
            throw new \UnexpectedValueException('son cadre est vide.');
        }

        $justification = self::texte($brut['justification'] ?? null, 1000);

        // ── La personne : une fiche de l'arbre… ────────────────────────────────────
        $valeur = $brut['personne'] ?? null;
        $personId = self::entier($valeur);

        if (null !== $personId) {
            return new PortraitTraduit($rang - 1, $bornes, $justification, $personId);
        }

        // … ou un brouillon d'une création traduite, désigné sans ambiguïté.
        $cle = \is_string($valeur) ? self::cleDeReference($valeur) : '';

        if (1 !== preg_match('/^p\d{1,3}$/', $cle)) {
            throw new \UnexpectedValueException('il ne désigne aucune personne.');
        }

        $candidats = [];
        $demande = self::entier($brut['demande'] ?? null);

        if (null !== $demande) {
            // Le modèle a nommé la demande : on n'en cherche pas une autre à sa place.
            $proposition = $rangs[$demande - 1] ?? null;

            if (null !== $proposition && isset($referencesParProposition[$proposition][$cle])) {
                $candidats[$proposition] = $referencesParProposition[$proposition][$cle];
            }
        } else {
            foreach ($referencesParProposition as $proposition => $references) {
                if (isset($references[$cle])) {
                    $candidats[$proposition] = $references[$cle];
                }
            }
        }

        if (1 !== \count($candidats)) {
            throw new \UnexpectedValueException([] === $candidats ? 'la personne nouvelle qu’il désigne n’est proposée par aucune demande retenue.' : 'plusieurs demandes proposent une personne nouvelle sous cette référence.');
        }

        $proposition = array_key_first($candidats);

        return new PortraitTraduit($rang - 1, $bornes, $justification, null, $proposition, $candidats[$proposition]);
    }

    /** Une coordonnée du repère 0–1000 ; `120.0` vaut `120`, rien d'autre n'est deviné. */
    private static function coordonnee(mixed $valeur): ?int
    {
        $entier = match (true) {
            \is_int($valeur) => $valeur,
            \is_float($valeur) && floor($valeur) === $valeur => (int) $valeur,
            \is_string($valeur) && 1 === preg_match('/^\s*\d{1,4}\s*$/', $valeur) => (int) $valeur,
            default => null,
        };

        return null !== $entier && $entier >= 0 && $entier <= self::REPERE ? $entier : null;
    }

    /**
     * @param array<string, mixed> $demande
     *
     * @return array<string, mixed>
     */
    private function modification(array $demande): array
    {
        $cible = self::entier($demande['personId'] ?? null)
            ?? throw new \UnexpectedValueException('elle ne désigne aucune fiche existante.');

        $ops = [];
        $changements = \is_array($demande['changements'] ?? null) ? $demande['changements'] : [];

        foreach ($changements as $changement) {
            if (!\is_array($changement)) {
                continue;
            }

            $champ = $changement['champ'] ?? null;
            $valeur = $this->valeurDeChamp($champ, $changement);

            if (!\is_string($champ) || null === $valeur) {
                continue;
            }

            $ops[] = [
                'opKind' => 'champ',
                'subjectKind' => 'personne',
                'subjectId' => $cible,
                'field' => $champ,
                'newValue' => $valeur['valeur'],
            ];
        }

        if ([] === $ops) {
            throw new \UnexpectedValueException('aucun des changements proposés n’était exploitable.');
        }

        return [
            'kind' => 'modification_personne',
            'targetKind' => 'personne',
            'targetId' => $cible,
            'ops' => $ops,
        ];
    }

    /**
     * La valeur d'un changement, dans la forme que le champ attend — ou `null` si elle
     * est absente ou inexploitable. Enveloppée dans un tableau pour distinguer « pas de
     * valeur » d'une valeur `false`.
     *
     * @param array<mixed> $changement
     *
     * @return array{valeur: mixed}|null
     */
    private function valeurDeChamp(mixed $champ, array $changement): ?array
    {
        // Le schéma demande `texte`, mais le modèle range parfois la valeur sous
        // `valeur` : on l'accepte plutôt que de perdre une proposition valable pour
        // une question de forme (constaté en essai réel, 24/09/2026).
        $changement['texte'] ??= $changement['valeur'] ?? null;

        if (\in_array($champ, self::CHAMPS_TEXTE, true)) {
            $texte = self::texte($changement['texte'] ?? null, 250);

            return '' === $texte ? null : ['valeur' => $texte];
        }

        return match ($champ) {
            'sex' => \in_array($changement['texte'] ?? null, self::SEXES, true) ? ['valeur' => $changement['texte']] : null,
            'deceased' => \is_bool($changement['booleen'] ?? null) ? ['valeur' => $changement['booleen']] : null,
            'birth', 'death' => null !== ($bloc = DateProposee::versBloc($changement['date'] ?? null)) ? ['valeur' => $bloc] : null,
            default => null,
        };
    }

    /**
     * @param array<string, mixed>  $demande
     * @param array<string, string> $references rendue : la référence du modèle (« p1 ») → `#p<n>`,
     *                                          pour que les portraits puissent viser un brouillon
     *
     * @return array<string, mixed>
     */
    private function creation(array $demande, array &$references): array
    {
        $references = [];
        $brouillons = [];
        $personnes = \is_array($demande['personnes'] ?? null) ? array_values($demande['personnes']) : [];

        foreach ($personnes as $rang => $personne) {
            if (!\is_array($personne)) {
                continue;
            }

            // La référence canonique est **recalculée**, dans l'ordre : `#p1`, `#p2`… Le
            // modèle garde sa propre écriture (« p1 », « #P1 ») pour ses liens, que l'on
            // traduit par la table ci-dessous.
            $canonique = '#p'.(\count($brouillons) + 1);
            $brute = \is_string($personne['ref'] ?? null) ? self::cleDeReference($personne['ref']) : 'rang'.$rang;

            if (isset($references[$brute])) {
                throw new \UnexpectedValueException('deux personnes nouvelles y portent la même référence.');
            }

            $references[$brute] = $canonique;
            $brouillons[] = $this->brouillon($canonique, $personne);
        }

        if ([] === $brouillons) {
            throw new \UnexpectedValueException('elle ne décrit aucune personne nouvelle.');
        }

        return [
            'kind' => 'creation_personne',
            'brouillons' => $brouillons,
            'liens' => $this->liens($demande, $references),
        ];
    }

    /**
     * @param array<string, mixed> $demande
     *
     * @return array<string, mixed>
     */
    private function ajoutDeLien(array $demande): array
    {
        $corps = [
            'kind' => 'ajout_lien',
            'targetKind' => 'personne',
            'liens' => $this->liens($demande, []),
        ];

        $cible = self::entier($demande['personId'] ?? null);

        if (null !== $cible) {
            $corps['targetId'] = $cible;
        }

        return $corps;
    }

    /**
     * @param array<mixed> $personne
     *
     * @return array<string, mixed>
     */
    private function brouillon(string $reference, array $personne): array
    {
        $naissance = DateProposee::versBloc($personne['naissance'] ?? null);
        $deces = DateProposee::versBloc($personne['deces'] ?? null);
        $sexe = $personne['sexe'] ?? null;

        return [
            'localRef' => $reference,
            'givenNames' => self::texteOuNull($personne['prenoms'] ?? null),
            'surname' => self::texteOuNull($personne['nom'] ?? null),
            'marriedName' => self::texteOuNull($personne['nomDUsage'] ?? null),
            'nickname' => self::texteOuNull($personne['surnom'] ?? null),
            'sex' => \is_string($sexe) && \in_array($sexe, self::SEXES, true) ? $sexe : 'inconnu',
            // Une date de décès attestée par un document **est** un décès : le formulaire
            // refuse de cocher la case à la place du contributeur, mais ici c'est la
            // source qui la coche, et le membre relit avant de déposer.
            'deceased' => true === ($personne['decedee'] ?? null) || DateProposee::porteUneDate($deces),
            'birth' => $naissance,
            'death' => $deces,
            'note' => self::texteOuNull($personne['note'] ?? null, 2000),
        ];
    }

    /**
     * @param array<string, mixed>  $demande
     * @param array<string, string> $references la référence du modèle → `#p<n>` ; vide
     *                                          pour un ajout de lien, qui ne crée personne
     *
     * @return list<array<string, mixed>>
     */
    private function liens(array $demande, array $references): array
    {
        $liens = [];

        foreach (\is_array($demande['liens'] ?? null) ? $demande['liens'] : [] as $lien) {
            if (!\is_array($lien)) {
                continue;
            }

            $nature = $lien['nature'] ?? null;

            if (!\is_string($nature) || !\in_array($nature, self::NATURES_DE_LIEN, true)) {
                throw new \UnexpectedValueException('un de ses liens de parenté est d’une nature inconnue.');
            }

            $construit = ['seq' => \count($liens) + 1, 'kind' => $nature];
            $construit += $this->extremite($lien['sujet'] ?? null, $references, 'subjectPersonId', 'subjectDraftRef')
                ?? throw self::introuvable($lien['sujet'] ?? null, 'un de ses liens désigne une personne introuvable.');
            $construit += $this->extremite($lien['autre'] ?? null, $references, 'otherPersonId', 'otherDraftRef')
                ?? throw self::introuvable($lien['autre'] ?? null, 'un de ses liens désigne une personne introuvable.');

            if ('enfant_de' === $nature && null !== ($lien['autre2'] ?? null) && '' !== $lien['autre2']) {
                $construit += $this->extremite($lien['autre2'], $references, 'other2PersonId', 'other2DraftRef')
                    ?? throw self::introuvable($lien['autre2'], 'le second parent d’un de ses liens est introuvable.');
            }

            if ('conjoint_de' === $nature) {
                $mariage = DateProposee::versBloc($lien['mariage'] ?? null);

                if (null !== $mariage) {
                    $construit['marriage'] = $mariage;
                }

                if (true === ($lien['divorce'] ?? null)) {
                    $construit['divorced'] = true;
                }
            }

            $note = self::texteOuNull($lien['note'] ?? null, 2000);
            if (null !== $note) {
                $construit['note'] = $note;
            }

            $liens[] = $construit;
        }

        return $liens;
    }

    /**
     * Une extrémité de lien : `#p1` (brouillon de la même demande) ou `12` (personne de
     * l'arbre). **Jamais les deux, jamais une devinette** : une chaîne qui n'est ni l'une
     * ni l'autre rend `null`, et le lien — donc la demande — est écarté.
     *
     * @param array<string, string> $references
     *
     * @return array<string, int|string>|null
     */
    private function extremite(mixed $valeur, array $references, string $clePersonne, string $cleBrouillon): ?array
    {
        $entier = self::entier($valeur);

        if (null !== $entier) {
            return [$clePersonne => $entier];
        }

        if (!\is_string($valeur)) {
            return null;
        }

        $reference = $references[self::cleDeReference($valeur)] ?? null;

        return null === $reference ? null : [$cleBrouillon => $reference];
    }

    /**
     * Le motif d'une extrémité introuvable. Une référence `#p<n>` que la demande ne décrit
     * pas est presque toujours une personne nouvelle décrite dans **une autre** demande :
     * le dire, c'est dire au membre pourquoi l'enfant ou le conjoint manque.
     */
    private static function introuvable(mixed $valeur, string $motif): \UnexpectedValueException
    {
        if (\is_string($valeur) && 1 === preg_match('/^p\d{1,3}$/', self::cleDeReference($valeur))) {
            return new \UnexpectedValueException(\sprintf('un de ses liens vise la personne nouvelle « %s », qu’elle ne décrit pas — une personne nouvelle se décrit dans la même demande que ceux qui s’y rattachent.', trim($valeur)));
        }

        return new \UnexpectedValueException($motif);
    }

    /** « #p1 », « p1 », « #P1 » → « p1 ». */
    private static function cleDeReference(string $brute): string
    {
        return ltrim(mb_strtolower(trim($brute)), '#');
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

    private static function texteOuNull(mixed $valeur, int $longueur = 250): ?string
    {
        $texte = self::texte($valeur, $longueur);

        return '' === $texte ? null : $texte;
    }

    /** @param array<string, mixed> $corps */
    private static function titreParDefaut(array $corps): string
    {
        return match ($corps['kind']) {
            'creation_personne' => 'Ajouter une personne',
            'ajout_lien' => 'Ajouter un lien de parenté',
            default => 'Modifier une fiche',
        };
    }
}
