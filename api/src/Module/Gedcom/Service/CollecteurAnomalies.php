<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Service;

use App\Module\Gedcom\Dto\Anomalie;
use App\Module\Gedcom\Enum\IssueSeverity;

/**
 * Accumule les anomalies d'une lecture, puis les rend prêtes à devenir des `ged_issue`.
 *
 * **Pourquoi un collecteur plutôt qu'une écriture au fil de l'eau** : l'analyse doit
 * pouvoir tourner sans base — c'est tout l'intérêt du `--dry-run`, et c'est ce qui rend
 * l'analyseur testable sans serveur PostgreSQL. L'écriture vient après, une fois qu'on
 * sait si l'import a réussi.
 *
 * Ce n'est **pas** un service partagé : le lecteur en fabrique un par exécution. Un
 * collecteur injecté par le conteneur garderait les anomalies de l'import précédent.
 */
final class CollecteurAnomalies
{
    /**
     * Les codes dont on ne veut qu'**une ligne de synthèse** et non une ligne par
     * occurrence.
     *
     * Le fichier réel porte 473 tags propriétaires MyHeritage (`_UPD`, `_UID`, `_PRIM`…)
     * et 52 `ROLE` mal formés : 525 lignes de journal pour dire deux choses. On agrège
     * par (code, tag) et on met le nombre dans le message. Les avertissements et les
     * erreurs, eux, restent détaillés — c'est sur eux qu'on veut le numéro de ligne.
     */
    private const array CODES_AGREGES = [
        self::TAG_PROPRIETAIRE_IGNORE,
        self::ROLE_MAL_FORME,
        self::TAG_SANS_COLONNE,
    ];

    // Codes du § 8.2 du modèle de données.
    public const string TAG_PROPRIETAIRE_IGNORE = 'tag_proprietaire_ignore';
    public const string ROLE_MAL_FORME = 'role_mal_forme';
    public const string NOM_VIDE = 'nom_vide';
    public const string QUAY_HORS_NORME = 'quay_hors_norme';
    public const string DATE_NON_ANALYSABLE = 'date_non_analysable';
    public const string FILIATION_MULTIPLE = 'filiation_multiple';
    public const string MEDIA_DISTANT = 'media_distant';
    public const string LIEN_CASSE = 'lien_casse';
    public const string DENOMBREMENT_INATTENDU = 'denombrement_inattendu';
    public const string ENCODAGE_INVALIDE = 'encodage_invalide';

    /**
     * **Ajout au jeu du § 8.2**, signalé dans le livrable : un tag standard du GEDCOM
     * qu'aucune colonne du modèle n'accueille. Le fichier réel en porte trois — l'`AGE`
     * et la `CAUS` d'un `DEAT`, l'`EMAIL` d'une `RESI` — et le modèle interdit d'en
     * faire des `tree_fact` (§ 3.1 : « `BIRT` et `DEAT` vont dans les colonnes et n'en
     * font pas *aussi* des faits »). Les taire serait perdre de la donnée sans trace ;
     * les signaler laisse la décision à qui relira le modèle.
     */
    public const string TAG_SANS_COLONNE = 'tag_sans_colonne';

    /** Le fichier ne correspond à aucun jeu de totaux de référence. */
    public const string SANS_TOTAUX_DE_REFERENCE = 'sans_totaux_de_reference';

    /** @var list<Anomalie> */
    private array $anomalies = [];

    /** @var array<string, int> clé « code|tag » → nombre d'occurrences */
    private array $compteurs = [];

    /** @var array<string, Anomalie> clé « code|tag » → première occurrence rencontrée */
    private array $premieres = [];

    public function info(string $code, string $message, ?int $ligne = null, ?string $xref = null, ?string $tag = null, ?string $ligneBrute = null): void
    {
        $this->ajouter(new Anomalie(IssueSeverity::Info, $code, $message, $ligne, $xref, $tag, $ligneBrute));
    }

    public function avertir(string $code, string $message, ?int $ligne = null, ?string $xref = null, ?string $tag = null, ?string $ligneBrute = null): void
    {
        $this->ajouter(new Anomalie(IssueSeverity::Avertissement, $code, $message, $ligne, $xref, $tag, $ligneBrute));
    }

    public function erreur(string $code, string $message, ?int $ligne = null, ?string $xref = null, ?string $tag = null, ?string $ligneBrute = null): void
    {
        $this->ajouter(new Anomalie(IssueSeverity::Erreur, $code, $message, $ligne, $xref, $tag, $ligneBrute));
    }

    private function ajouter(Anomalie $anomalie): void
    {
        $cle = $anomalie->code.'|'.($anomalie->tag ?? '');
        $this->compteurs[$cle] = ($this->compteurs[$cle] ?? 0) + 1;

        if (\in_array($anomalie->code, self::CODES_AGREGES, true)) {
            $this->premieres[$cle] ??= $anomalie;

            return;
        }

        $this->anomalies[] = $anomalie;
    }

    /**
     * Les anomalies à écrire : les détaillées telles quelles, puis une ligne de synthèse
     * par code agrégé.
     *
     * @return list<Anomalie>
     */
    public function anomalies(): array
    {
        $sorties = $this->anomalies;

        foreach ($this->premieres as $cle => $premiere) {
            $nombre = $this->compteurs[$cle];

            $sorties[] = new Anomalie(
                $premiere->severite,
                $premiere->code,
                \sprintf('%s (%d occurrence%s, première ligne %s)', $premiere->message, $nombre, $nombre > 1 ? 's' : '', $premiere->ligne ?? '?'),
                $premiere->ligne,
                $premiere->xref,
                $premiere->tag,
                $premiere->ligneBrute,
            );
        }

        return $sorties;
    }

    public function compte(string $code): int
    {
        $total = 0;

        foreach ($this->compteurs as $cle => $nombre) {
            if (str_starts_with($cle, $code.'|')) {
                $total += $nombre;
            }
        }

        return $total;
    }

    /** @return array<string, int> code → nombre d'occurrences, toutes sévérités confondues */
    public function parCode(): array
    {
        $parCode = [];

        foreach ($this->compteurs as $cle => $nombre) {
            $code = substr($cle, 0, (int) strpos($cle, '|'));
            $parCode[$code] = ($parCode[$code] ?? 0) + $nombre;
        }

        ksort($parCode);

        return $parCode;
    }

    /** @return array<value-of<IssueSeverity>, int> */
    public function parSeverite(): array
    {
        $parSeverite = [
            IssueSeverity::Info->value => 0,
            IssueSeverity::Avertissement->value => 0,
            IssueSeverity::Erreur->value => 0,
        ];

        foreach ($this->anomalies() as $anomalie) {
            ++$parSeverite[$anomalie->severite->value];
        }

        return $parSeverite;
    }

    /** Une erreur bloque l'import hors `--dry-run` (§ 8.4 du modèle de données). */
    public function aDesErreurs(): bool
    {
        foreach ($this->anomalies as $anomalie) {
            if (IssueSeverity::Erreur === $anomalie->severite) {
                return true;
            }
        }

        return false;
    }
}
