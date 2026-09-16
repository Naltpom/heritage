<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Service;

use App\Module\Core\Enum\Sex;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Gedcom\Dto\CitationLue;
use App\Module\Gedcom\Dto\FaitLu;
use App\Module\Gedcom\Dto\FamilleLue;
use App\Module\Gedcom\Dto\FichierGedcom;
use App\Module\Gedcom\Dto\GrapheFamilial;
use App\Module\Gedcom\Dto\NoeudGedcom;
use App\Module\Gedcom\Dto\NoteLue;
use App\Module\Gedcom\Dto\PersonneLue;
use App\Module\Gedcom\Dto\ResultatAnalyse;
use App\Module\Gedcom\Dto\SourceLue;
use App\Module\Tree\Enum\CitationContext;
use App\Module\Tree\Enum\CitationQuality;
use App\Module\Tree\Enum\FactType;
use App\Module\Tree\Enum\NoteScope;
use App\Module\Tree\Service\CalculAscendance;
use App\Module\Tree\Service\NormalisateurNom;
use App\Module\Tree\ValueObject\FamillePlacable;

/**
 * L'arbre de tags GEDCOM vers le modèle du § 3 : personnes, familles, sources,
 * citations, notes, faits.
 *
 * **C'est ici que sont rangés les pièges de ce fichier**, chacun avec la raison pour
 * laquelle il existe :
 *
 * - Les **14 `NOTE` portées par un enregistrement `SOUR`** vont dans `tree_source.note`,
 *   pas dans `tree_note`. C'est l'écart entre les 189 `NOTE` du fichier et les 175 notes
 *   de recherche.
 * - La **484ᵉ ligne `1 SOUR` est dans `HEAD`** : elle décrit la provenance du fichier,
 *   pas un fait. Restent 483 citations, ce que les 483 `QUAY` confirment de leur côté.
 * - **`BIRT` et `DEAT` vont dans les colonnes** de `tree_person` et n'en font pas *aussi*
 *   des `tree_fact` (§ 3.1). Un fait dupliqué est une divergence qui attend son heure.
 * - **`_MARNM`** porte les noms d'épouse, 97 occurrences. C'est de la vraie donnée, pas
 *   du bruit MyHeritage, et `graphe.json` l'avait perdue.
 * - **`DEAT Y` sans `DATE`** est un décès certain sans date : 25 cas. D'où un booléen
 *   distinct du bloc date.
 * - Les **4 individus sans nom** (`1 NAME  //`) et les **3 familles à un seul époux**
 *   sont des données valides. On les signale, on ne les écarte pas.
 */
final class AnalyseurGedcom
{
    /**
     * Les événements de niveau 1 d'un `INDI` qui deviennent des `tree_fact`.
     *
     * `BIRT` et `DEAT` en sont volontairement absents : ce sont des colonnes.
     */
    private const array FAITS = [
        'OCCU' => FactType::Profession,
        'RESI' => FactType::Residence,
        'BURI' => FactType::Inhumation,
        'CENS' => FactType::Recensement,
        'CHR' => FactType::BaptemeReligieux,
        'BAPM' => FactType::Bapteme,
        'EVEN' => FactType::Autre,
    ];

    /** Les tags propriétaires MyHeritage que l'import **consomme** vraiment. */
    private const array PROPRIETAIRES_UTILISES = ['_MARNM', '_UID', '_MEDI'];

    /**
     * Les tags standard qu'aucune colonne du modèle n'accueille, avec l'endroit où ils
     * apparaissent. Signalés en `info` plutôt que tus : le fichier porte 38 `AGE` et une
     * `CAUS` sous des `DEAT`, et le § 3.1 interdit d'en faire des `tree_fact`.
     */
    private const array SANS_COLONNE = ['DEAT.AGE', 'DEAT.CAUS', 'RESI.EMAIL', 'BIRT.ADDR'];

    public function __construct(
        private readonly LecteurGedcom $lecteur,
        private readonly ConvertisseurDate $dates,
        private readonly DecoupageDuNom $decoupage,
        private readonly NormalisateurNom $noms,
        private readonly QualificateurSource $qualificateur,
        private readonly CalculAscendance $ascendance,
    ) {
    }

    /**
     * @throws \RuntimeException si le fichier est illisible, mal encodé, ou dépourvu de racine
     */
    public function analyser(string $chemin, ?string $ego = null): ResultatAnalyse
    {
        $anomalies = new CollecteurAnomalies();
        $fichier = $this->lecteur->lire($chemin, $anomalies);

        return $this->analyserFichier($fichier, $anomalies, $ego);
    }

    /**
     * @throws \RuntimeException si le fichier ne contient aucun individu, ou si l'ego demandé est absent
     */
    public function analyserFichier(FichierGedcom $fichier, CollecteurAnomalies $anomalies, ?string $ego = null): ResultatAnalyse
    {
        $indi = $fichier->parType('INDI');
        $fam = $fichier->parType('FAM');
        $sour = $fichier->parType('SOUR');

        if ([] === $indi) {
            throw new \RuntimeException('Le fichier ne contient aucun enregistrement INDI : ce n’est pas un GEDCOM généalogique.');
        }

        $this->signalerTypesIgnores($fichier, $anomalies);
        $this->signalerTagsProprietaires($fichier, $anomalies);
        $this->verifierLiens($indi, $fam, $sour, $anomalies);

        $sources = [];
        foreach ($sour as $xref => $noeud) {
            $sources[$xref] = $this->lireSource($xref, $noeud);
        }

        $personnes = [];
        foreach ($indi as $xref => $noeud) {
            $personnes[$xref] = $this->lirePersonne($xref, $noeud, $sources, $anomalies);
        }

        $familles = [];
        foreach ($fam as $xref => $noeud) {
            $familles[$xref] = $this->lireFamille($xref, $noeud, $sources, $anomalies);
        }

        $graphe = GrapheFamilial::construire($personnes, $familles);
        $ego = $this->choisirEgo($ego, $personnes, $graphe);

        // Les identifiants passés au calcul sont les `xref` GEDCOM, pas encore les
        // identifiants de base — l'arbre n'y est pas. C'est ce qui permet au `--dry-run`
        // d'annoncer 12 générations et la répartition par branche **avant** d'écrire quoi
        // que ce soit. Les `xref` portent leur type dans leur premier caractère (`@I…@`,
        // `@F…@`), donc la même garantie que les `p1` / `f28` de `Tree`.
        $placement = $this->ascendance->calculer(
            array_keys($personnes),
            $this->famillesPlacables($familles),
            $ego,
        );

        return new ResultatAnalyse(
            $personnes,
            $familles,
            $sources,
            $graphe,
            $placement,
            $ego,
            $fichier->charset,
            $fichier->lignesPhysiques,
            $anomalies,
        );
    }

    /**
     * Les familles lues, dans la forme que `Tree\Service\CalculAscendance` attend.
     *
     * Aucun champ n'est inventé pour satisfaire la signature : `epoux` et `enfants`
     * viennent des lignes `HUSB`/`WIFE`/`CHIL`, `aDivorce` du `DIV`. L'`id` est le `xref`.
     *
     * @param array<string, FamilleLue> $familles
     *
     * @return list<FamillePlacable>
     */
    private function famillesPlacables(array $familles): array
    {
        $placables = [];

        foreach ($familles as $xref => $famille) {
            $placables[] = new FamillePlacable(
                id: $xref,
                epoux: $famille->epoux(),
                enfants: $famille->enfants,
                aDivorce: $famille->divorce,
            );
        }

        return $placables;
    }

    // ------------------------------------------------------------------ sources

    private function lireSource(string $xref, NoeudGedcom $noeud): SourceLue
    {
        $titre = $noeud->texteDe('TITL') ?? $noeud->texteDe('ABBR') ?? '(sans titre)';
        $auteur = $noeud->texteDe('AUTH');
        $publication = $noeud->texteDe('PUBL');

        return new SourceLue(
            $xref,
            mb_substr($titre, 0, 255, 'UTF-8'),
            null === $auteur ? null : mb_substr($auteur, 0, 255, 'UTF-8'),
            $publication,
            $noeud->texteDe('TEXT'),
            // Le `NOTE` d'une source va **ici**, et non dans `tree_note` : c'est la
            // description du fonds, pas une note de recherche sur une personne.
            $noeud->texteDe('NOTE'),
            null === ($medi = $noeud->texteDe('_MEDI')) ? null : mb_substr($medi, 0, 60, 'UTF-8'),
            $this->qualificateur->qualifier($titre, $publication, $auteur),
            $noeud->ligne,
        );
    }

    // ------------------------------------------------------------------ personnes

    /**
     * @param array<string, SourceLue> $sources
     */
    private function lirePersonne(string $xref, NoeudGedcom $noeud, array $sources, CollecteurAnomalies $anomalies): PersonneLue
    {
        $name = $noeud->enfant('NAME');
        $decoupe = $this->decoupage->decouper($name?->valeur);

        $prenoms = $name?->texteDe('GIVN') ?? ('' !== $decoupe['prenoms'] ? $decoupe['prenoms'] : null);
        $nom = $name?->texteDe('SURN') ?? ('' !== $decoupe['nom'] ? $decoupe['nom'] : null);
        $surnom = $name?->texteDe('NICK') ?? $noeud->texteDe('NICK');
        $nomEpouse = $name?->texteDe('_MARNM') ?? $noeud->texteDe('_MARNM');

        if (null === $prenoms && null === $nom) {
            // Ce ne sont pas des lignes corrompues : ce sont des ancêtres réellement
            // inconnus, chacun ouvrant une branche de recherche. On les importe.
            $anomalies->avertir(
                CollecteurAnomalies::NOM_VIDE,
                'Individu sans nom ni prénom : conservé, il ouvre une branche de recherche.',
                null === $name ? $noeud->ligne : $name->ligne,
                $xref,
                'NAME',
                $name?->valeur,
            );
        }

        $prenoms = mb_substr($prenoms ?? '', 0, 160, 'UTF-8');
        $nom = mb_substr($nom ?? '', 0, 120, 'UTF-8');

        $naissance = $this->lireEvenement($noeud->enfant('BIRT'), $xref, $anomalies);
        $mort = $noeud->enfant('DEAT');
        $deces = $this->lireEvenement($mort, $xref, $anomalies);

        $this->signalerTagsSansColonne($noeud, $xref, $anomalies);

        $faits = $this->lireFaits($noeud, $xref, $sources, $anomalies);
        $medias = $this->compterMedias($noeud, $xref, $anomalies);

        return new PersonneLue(
            $xref,
            null === ($uid = $noeud->texteDe('_UID')) ? null : mb_substr($uid, 0, 32, 'UTF-8'),
            null === ($rin = $noeud->texteDe('RIN')) ? null : mb_substr($rin, 0, 32, 'UTF-8'),
            $prenoms,
            $nom,
            null === $nomEpouse ? null : mb_substr($nomEpouse, 0, 120, 'UTF-8'),
            null === $surnom ? null : mb_substr($surnom, 0, 80, 'UTF-8'),
            $this->noms->composerAffichage($prenoms, $nom, $surnom),
            // `$surnom` est passé aux DEUX, et c'est le point : l'import et la validation
            // d'une demande doivent produire la même clé pour la même personne. Omettre
            // le surnom ici les ferait diverger — une fiche importée et la même fiche
            // corrigée ensuite n'auraient plus la même `search_key`, sans que rien ne le
            // signale. Même règle pour `$nomEpouse`, qui entre dans la clé sans entrer
            // dans l'affichage.
            $this->noms->cleDeRecherche($prenoms, $nom, $surnom, $nomEpouse),
            match ($noeud->texteDe('SEX')) {
                'M' => Sex::Masculin,
                'F' => Sex::Feminin,
                default => Sex::Inconnu,
            },
            // Le **fait** du décès, indépendant de sa date : `DEAT Y` sans `DATE` est un
            // décès certain qu'on ne sait pas dater.
            null !== $mort,
            $naissance,
            $deces,
            $faits,
            $this->lireNotes($noeud, NoteScope::Generale, [
                'BIRT' => NoteScope::Naissance,
                'DEAT' => NoteScope::Deces,
            ]),
            $this->lireCitations($noeud, $xref, $sources, $anomalies),
            $medias,
            null !== ($resn = $noeud->texteDe('RESN')) && 'privacy' === mb_strtolower($resn, 'UTF-8'),
            $noeud->ligne,
        );
    }

    /**
     * @param array<string, SourceLue> $sources
     *
     * @return list<FaitLu>
     */
    private function lireFaits(NoeudGedcom $noeud, string $xref, array $sources, CollecteurAnomalies $anomalies): array
    {
        $faits = [];
        $ordre = 0;

        foreach ($noeud->enfants as $enfant) {
            $type = self::FAITS[$enfant->tag] ?? null;

            if (null === $type) {
                continue;
            }

            $libelle = $enfant->texte();

            if ('' === $libelle) {
                // Un `1 EVEN` sans valeur porte parfois son intitulé dans un `2 TYPE`.
                $libelle = $enfant->texteDe('TYPE') ?? '';
            }

            $faits[] = new FaitLu(
                $type,
                '' === $libelle ? null : mb_substr($libelle, 0, 255, 'UTF-8'),
                $this->lireEvenement($enfant, $xref, $anomalies),
                $this->adresse($enfant),
                null === ($age = $enfant->texteDe('AGE')) ? null : mb_substr($age, 0, 20, 'UTF-8'),
                null === ($cause = $enfant->texteDe('CAUS')) ? null : mb_substr($cause, 0, 255, 'UTF-8'),
                $ordre++,
                $this->lireNotes($enfant, NoteScope::Generale, []),
                $this->lireCitations($enfant, $xref, $sources, $anomalies),
                $enfant->ligne,
            );
        }

        return $faits;
    }

    /**
     * L'`ADDR` détaillée, distincte du `PLAC` que porte déjà le bloc date.
     *
     * Le fichier écrit l'adresse sur plusieurs lignes (`ADDR` puis `ADR1`, `CITY`,
     * `CTRY`) : on les recolle plutôt que de n'en garder qu'une.
     */
    private function adresse(NoeudGedcom $noeud): ?string
    {
        $addr = $noeud->enfant('ADDR');

        if (null === $addr) {
            return null;
        }

        $morceaux = array_filter([$addr->texte(), ...array_map(
            static fn (NoeudGedcom $n): string => $n->texte(),
            $addr->tous('ADR1', 'ADR2', 'ADR3', 'CITY', 'STAE', 'POST', 'CTRY'),
        )]);

        // `array_unique` parce que le fichier répète l'`ADDR` dans son `ADR1` : sans
        // cela, l'adresse sort en double dans la fiche.
        $recolle = implode(', ', array_unique($morceaux));

        return '' === $recolle ? null : $recolle;
    }

    // ------------------------------------------------------------------ familles

    /**
     * @param array<string, SourceLue> $sources
     */
    private function lireFamille(string $xref, NoeudGedcom $noeud, array $sources, CollecteurAnomalies $anomalies): FamilleLue
    {
        $divorce = $noeud->enfant('DIV');

        $mariages = $noeud->tous('MARR');

        if (\count($mariages) > 1) {
            // Une famille du fichier porte deux blocs `MARR`. On garde le premier —
            // celui qui porte la date — et on le dit plutôt que de laisser le dernier
            // écraser silencieusement le premier.
            $anomalies->avertir(
                CollecteurAnomalies::DATE_NON_ANALYSABLE,
                \sprintf('%d blocs MARR sur la même famille : seul le premier est retenu.', \count($mariages)),
                $noeud->ligne,
                $xref,
                'MARR',
            );
        }

        return new FamilleLue(
            $xref,
            null === ($uid = $noeud->texteDe('_UID')) ? null : mb_substr($uid, 0, 32, 'UTF-8'),
            null === ($rin = $noeud->texteDe('RIN')) ? null : mb_substr($rin, 0, 32, 'UTF-8'),
            $noeud->texteDe('HUSB'),
            $noeud->texteDe('WIFE'),
            array_values(array_filter(array_map(
                static fn (NoeudGedcom $n): string => $n->texte(),
                $noeud->tous('CHIL'),
            ))),
            $this->lireEvenement($mariages[0] ?? null, $xref, $anomalies),
            null !== $divorce,
            $this->lireEvenement($divorce, $xref, $anomalies),
            $this->lireNotes($noeud, NoteScope::Generale, [
                'MARR' => NoteScope::Mariage,
                'DIV' => NoteScope::Divorce,
            ]),
            $this->lireCitations($noeud, $xref, $sources, $anomalies),
            $noeud->ligne,
        );
    }

    // ------------------------------------------------------------------ pièces communes

    /** Le bloc date d'un événement : sa `DATE` et son `PLAC`. */
    private function lireEvenement(?NoeudGedcom $noeud, string $xref, CollecteurAnomalies $anomalies): UncertainDate
    {
        if (null === $noeud) {
            return UncertainDate::inconnue();
        }

        $date = $noeud->enfant('DATE');
        $analyse = $this->dates->convertir($date?->valeur, $noeud->texteDe('PLAC'));

        if (null !== $analyse->anomalie) {
            $anomalies->avertir(
                CollecteurAnomalies::DATE_NON_ANALYSABLE,
                $analyse->anomalie,
                null === $date ? $noeud->ligne : $date->ligne,
                $xref,
                $noeud->tag.'.DATE',
                $date?->valeur,
            );
        }

        return $analyse->date;
    }

    /**
     * Les notes de niveau 1, plus celles que portent les sous-blocs nommés.
     *
     * `$sousBlocs` traduit le découpage du § 3.1 : la naissance et le décès n'ayant pas
     * de ligne à eux, un `1 BIRT / 2 NOTE` devient une note de portée `naissance`
     * rattachée à la personne.
     *
     * @param array<string, NoteScope> $sousBlocs
     *
     * @return list<NoteLue>
     */
    private function lireNotes(NoeudGedcom $noeud, NoteScope $portee, array $sousBlocs): array
    {
        $notes = [];

        foreach ($noeud->tous('NOTE') as $note) {
            $corps = $note->texte();

            if ('' !== $corps) {
                $notes[] = new NoteLue($corps, $portee, $note->ligne);
            }
        }

        foreach ($sousBlocs as $tag => $porteeDuBloc) {
            foreach ($noeud->tous($tag) as $bloc) {
                foreach ($bloc->tous('NOTE') as $note) {
                    $corps = $note->texte();

                    if ('' !== $corps) {
                        $notes[] = new NoteLue($corps, $porteeDuBloc, $note->ligne);
                    }
                }
            }
        }

        return $notes;
    }

    /**
     * @param array<string, SourceLue> $sources
     *
     * @return list<CitationLue>
     */
    private function lireCitations(NoeudGedcom $noeud, string $xref, array $sources, CollecteurAnomalies $anomalies): array
    {
        $citations = [];

        foreach ($noeud->tous('SOUR') as $citation) {
            $cible = $citation->texte();

            if (1 !== preg_match('/^@[^@]+@$/', $cible) || !isset($sources[$cible])) {
                // Une citation qui ne pointe vers rien n'est pas récupérable : sans
                // `source_id`, `tree_citation` n'existe pas (`NOT NULL`).
                $anomalies->erreur(
                    CollecteurAnomalies::LIEN_CASSE,
                    \sprintf('Citation pointant vers une source inexistante : « %s ».', $cible),
                    $citation->ligne,
                    $xref,
                    'SOUR',
                    $citation->valeur,
                );

                continue;
            }

            $donnees = $citation->enfant('DATA');
            $even = $citation->enfant('EVEN');
            $role = $even?->texteDe('ROLE');

            if (null !== $role && 1 !== preg_match('/^[A-Z_]+$|^\(.+\)$/', $role)) {
                // Les 52 `ROLE` du fichier sont au format interne MyHeritage
                // (`10823:18741399-:`). On les conserve bruts pour l'export, on ne
                // prétend pas les comprendre.
                $anomalies->info(
                    CollecteurAnomalies::ROLE_MAL_FORME,
                    'ROLE au format interne MyHeritage, conservé brut.',
                    $even?->ligne,
                    $xref,
                    'ROLE',
                    $role,
                );
            }

            $citations[] = new CitationLue(
                $cible,
                $citation->texteDe('PAGE'),
                $this->qualite($citation, $xref, $anomalies),
                $this->contexte($even?->texte()),
                null === $role ? null : mb_substr($role, 0, 120, 'UTF-8'),
                null === ($date = $donnees?->texteDe('DATE')) ? null : mb_substr($date, 0, 60, 'UTF-8'),
                $donnees?->texteDe('TEXT'),
                $citation->ligne,
            );
        }

        return $citations;
    }

    private function qualite(NoeudGedcom $citation, string $xref, CollecteurAnomalies $anomalies): ?CitationQuality
    {
        $quay = $citation->enfant('QUAY');

        if (null === $quay || 1 !== preg_match('/^\d+$/', $quay->texte())) {
            // `null` n'est pas 0 : zéro affirme « recopie non vérifiée », l'absence
            // n'affirme rien.
            return null;
        }

        $valeur = (int) $quay->texte();
        $qualite = CitationQuality::tryFrom($valeur);

        if (null !== $qualite) {
            return $qualite;
        }

        // MyHeritage a émis des `QUAY 4`, valeur qui n'existe pas dans la norme et que
        // `ck_tree_citation_quay` refuserait. Ramenée à 3, et signalée : on ne corrige
        // pas une donnée en silence.
        $anomalies->avertir(
            CollecteurAnomalies::QUAY_HORS_NORME,
            \sprintf('QUAY %d hors de la plage 0-3 de la norme : ramené à %d.', $valeur, $valeur > 3 ? 3 : 0),
            $quay->ligne,
            $xref,
            'QUAY',
            $quay->valeur,
        );

        return $valeur > 3 ? CitationQuality::PreuveDirecte : CitationQuality::RecopieNonVerifiee;
    }

    private function contexte(?string $even): ?CitationContext
    {
        if (null === $even || '' === $even) {
            return null;
        }

        return match (mb_strtolower($even, 'UTF-8')) {
            'discovery' => CitationContext::Discovery,
            'record' => CitationContext::Record,
            'smart matching', 'smartmatching' => CitationContext::SmartMatching,
            default => CitationContext::Autre,
        };
    }

    /**
     * Les `OBJE` sont **comptés et signalés, jamais téléchargés**.
     *
     * Les 31 `FILE` du fichier sont des liens `sites-cf.mhcache.com` signés et
     * **expirants** : un import qui dépend d'un tiers n'est pas reproductible, et le lien
     * sera mort bien avant qu'on s'en aperçoive. Le § 8.2 du modèle prévoit de les
     * enregistrer en `med_media` avec `status = 'en_attente'` ; cet import ne le fait pas
     * — voir le livrable, `med_media` exige un `sha256`, une taille et un déposant qu'un
     * lancement en console n'a pas.
     */
    private function compterMedias(NoeudGedcom $noeud, string $xref, CollecteurAnomalies $anomalies): int
    {
        $medias = $noeud->tous('OBJE');

        foreach ($medias as $media) {
            $fichier = $media->texteDe('FILE');

            $anomalies->avertir(
                CollecteurAnomalies::MEDIA_DISTANT,
                null !== $fichier && 1 === preg_match('#^https?://#i', $fichier)
                    ? 'OBJE pointant vers une URL distante et expirante : compté, non téléchargé.'
                    : 'OBJE non importé : le dépôt des médias passe par le module Media.',
                $media->ligne,
                $xref,
                'OBJE',
                $media->texteDe('TITL') ?? $fichier,
            );
        }

        return \count($medias);
    }

    // ------------------------------------------------------------------ contrôles

    /**
     * @param array<string, NoeudGedcom> $indi
     * @param array<string, NoeudGedcom> $fam
     * @param array<string, NoeudGedcom> $sour
     */
    private function verifierLiens(array $indi, array $fam, array $sour, CollecteurAnomalies $anomalies): void
    {
        foreach ($fam as $xref => $famille) {
            foreach ($famille->tous('HUSB', 'WIFE', 'CHIL') as $lien) {
                if (!isset($indi[$lien->texte()])) {
                    $anomalies->erreur(
                        CollecteurAnomalies::LIEN_CASSE,
                        \sprintf('%s pointant vers un individu inexistant : « %s ».', $lien->tag, $lien->texte()),
                        $lien->ligne,
                        $xref,
                        $lien->tag,
                        $lien->valeur,
                    );
                }
            }
        }

        foreach ($indi as $xref => $personne) {
            foreach ($personne->tous('FAMC', 'FAMS') as $lien) {
                if (!isset($fam[$lien->texte()])) {
                    $anomalies->erreur(
                        CollecteurAnomalies::LIEN_CASSE,
                        \sprintf('%s pointant vers une famille inexistante : « %s ».', $lien->tag, $lien->texte()),
                        $lien->ligne,
                        $xref,
                        $lien->tag,
                        $lien->valeur,
                    );
                }
            }

            // Deux `FAMC` sur la même personne, c'est soit une adoption — légitime —,
            // soit un défaut de données. Le fichier n'en contient aucun ; le jour où il
            // y en aura, mieux vaut le lire dans le rapport que le découvrir dans l'arbre.
            if (\count($personne->tous('FAMC')) > 1) {
                $anomalies->avertir(
                    CollecteurAnomalies::FILIATION_MULTIPLE,
                    'Individu rattaché à plusieurs familles par FAMC : adoption, ou défaut de données.',
                    $personne->ligne,
                    $xref,
                    'FAMC',
                );
            }
        }

        unset($sour);
    }

    private function signalerTypesIgnores(FichierGedcom $fichier, CollecteurAnomalies $anomalies): void
    {
        foreach ($fichier->enregistrements as $enregistrement) {
            if (\in_array($enregistrement->tag, ['HEAD', 'TRLR', 'INDI', 'FAM', 'SOUR'], true)) {
                continue;
            }

            $anomalies->info(
                CollecteurAnomalies::TAG_PROPRIETAIRE_IGNORE,
                \sprintf('Enregistrement de type %s non importé : aucune table du modèle ne l’accueille.', $enregistrement->tag),
                $enregistrement->ligne,
                $enregistrement->xref,
                $enregistrement->tag,
            );
        }
    }

    /**
     * Le bruit MyHeritage : `_UPD`, `_PRIM`, `_CUTOUT`, `_TYPE`, `_ALBUM`, `_FILESIZE`,
     * `_PHOTO_RIN`….
     *
     * Une ligne de synthèse par tag plutôt qu'une par occurrence — {@see CollecteurAnomalies}
     * s'en charge —, sans quoi le rapport d'un import compterait 500 lignes pour dire
     * qu'on a ignoré ce qu'on avait décidé d'ignorer.
     */
    private function signalerTagsProprietaires(FichierGedcom $fichier, CollecteurAnomalies $anomalies): void
    {
        foreach ($fichier->enregistrements as $enregistrement) {
            $this->parcourir($enregistrement, function (NoeudGedcom $noeud) use ($enregistrement, $anomalies): void {
                if (!str_starts_with($noeud->tag, '_') || \in_array($noeud->tag, self::PROPRIETAIRES_UTILISES, true)) {
                    return;
                }

                $anomalies->info(
                    CollecteurAnomalies::TAG_PROPRIETAIRE_IGNORE,
                    \sprintf('Tag propriétaire %s ignoré : sans effet sur le modèle.', $noeud->tag),
                    $noeud->ligne,
                    $enregistrement->xref,
                    $noeud->tag,
                );
            });
        }
    }

    private function signalerTagsSansColonne(NoeudGedcom $noeud, string $xref, CollecteurAnomalies $anomalies): void
    {
        foreach (self::SANS_COLONNE as $chemin) {
            [$parent, $enfant] = explode('.', $chemin);

            foreach ($noeud->tous($parent) as $bloc) {
                foreach ($bloc->tous($enfant) as $perdu) {
                    $anomalies->info(
                        CollecteurAnomalies::TAG_SANS_COLONNE,
                        \sprintf(
                            'Tag %s sous %s : aucune colonne ne l’accueille — le § 3.1 du modèle interdit de faire un tree_fact d’un BIRT ou d’un DEAT.',
                            $enfant,
                            $parent,
                        ),
                        $perdu->ligne,
                        $xref,
                        $chemin,
                        $perdu->valeur,
                    );
                }
            }
        }
    }

    /**
     * @param \Closure(NoeudGedcom): void $action
     */
    private function parcourir(NoeudGedcom $noeud, \Closure $action): void
    {
        $action($noeud);

        foreach ($noeud->enfants as $enfant) {
            $this->parcourir($enfant, $action);
        }
    }

    /**
     * La personne racine : celle demandée, ou à défaut celle qui a le plus d'ascendants.
     *
     * Le repli n'est pas une commodité : sans racine, `generation` et `branch` n'ont pas
     * de sens, et un GEDCOM de test écrit à la main n'a aucune raison de porter
     * `@I500001@`. Le plus profondément enraciné est le meilleur candidat par défaut, et
     * l'ordre du fichier départage les ex æquo — pour que deux exécutions donnent le
     * même résultat.
     *
     * @param array<string, PersonneLue> $personnes
     *
     * @throws \RuntimeException si l'ego demandé n'existe pas dans le fichier
     */
    private function choisirEgo(?string $ego, array $personnes, GrapheFamilial $graphe): string
    {
        if (null !== $ego) {
            if (!isset($personnes[$ego])) {
                throw new \RuntimeException(\sprintf('La personne racine demandée « %s » est absente du fichier.', $ego));
            }

            return $ego;
        }

        $meilleur = null;
        $profondeur = -1;

        foreach (array_keys($personnes) as $xref) {
            $nombre = \count($graphe->ascendants($xref));

            if ($nombre > $profondeur) {
                $profondeur = $nombre;
                $meilleur = $xref;
            }
        }

        \assert(null !== $meilleur);

        return $meilleur;
    }
}
