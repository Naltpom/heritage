<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Service;

use App\Module\Gedcom\Dto\FichierGedcom;
use App\Module\Gedcom\Dto\NoeudGedcom;

/**
 * Le lexeur : des octets vers un arbre d'enregistrements GEDCOM.
 *
 * Il ne connaît rien à la généalogie. Il sait lire la grammaire de la norme 5.5.1 —
 * `<niveau> [<xref>] <TAG> [<valeur>]` — et rien d'autre. C'est ce qui le rend
 * testable sur quelques lignes écrites à la main.
 *
 * **Les deux pièges déjà payés**, et qu'on ne redécouvre pas :
 *
 * 1. `CONC` recolle **sans séparateur** (le mot était coupé en deux), `CONT` ajoute un
 *    saut de ligne. Les inverser transforme les notes de recherche en bouillie — et ces
 *    notes sont les lignes les plus précieuses du fichier (§ 3.5 du modèle).
 * 2. L'encodage. Le `README.md` du chantier documente un fichier d'origine invalide en
 *    UTF-8, un caractère accentué coupé en deux par le découpage de lignes de
 *    MyHeritage. On vérifie donc les octets **avant** de décoder, et on refuse le
 *    fichier plutôt que de laisser des `?` s'installer en base.
 */
final class LecteurGedcom
{
    /**
     * La grammaire d'une ligne GEDCOM.
     *
     * `[A-Za-z0-9_]+` et non `[A-Z]+` : MyHeritage émet des tags propriétaires à
     * souligné (`_UID`, `_MARNM`, `_PHOTO_RIN`), et les refuser ferait perdre les noms
     * d'épouse, qui sont de la vraie donnée.
     */
    private const string GRAMMAIRE = '/^(\d+) (?:(@[^@]+@) )?([A-Za-z0-9_]+)(?: (.*))?$/';

    /**
     * @throws \RuntimeException si le fichier est illisible ou mal encodé
     */
    public function lire(string $chemin, CollecteurAnomalies $anomalies): FichierGedcom
    {
        $octets = @file_get_contents($chemin);

        if (false === $octets) {
            throw new \RuntimeException(\sprintf('Fichier GEDCOM illisible : %s', $chemin));
        }

        return $this->analyserOctets($octets, $anomalies);
    }

    /**
     * @throws \RuntimeException si les octets ne sont pas de l'UTF-8 valide
     */
    public function analyserOctets(string $octets, CollecteurAnomalies $anomalies): FichierGedcom
    {
        // La vérification porte sur les octets bruts. `mb_convert_encoding` aurait
        // « réparé » le fichier en silence, ce qui est exactement ce qu'on ne veut pas :
        // un caractère remplacé par un losange est une donnée perdue qu'on ne remarque
        // que des mois plus tard, sur une fiche qu'on n'ouvre jamais.
        if (!mb_check_encoding($octets, 'UTF-8')) {
            $anomalies->erreur(
                CollecteurAnomalies::ENCODAGE_INVALIDE,
                'Le fichier contient des octets non décodables en UTF-8. Il a probablement été produit par un exporteur qui coupe les lignes au milieu d’un caractère accentué.',
            );

            throw new \RuntimeException('Le fichier GEDCOM n’est pas de l’UTF-8 valide.');
        }

        // Le BOM est légal en tête de fichier et illégal en tête de ligne : il ferait
        // échouer la grammaire sur la toute première ligne, `0 HEAD`.
        $octets = ltrim($octets, "\u{FEFF}");

        $lignes = preg_split('/\r\n|\r|\n/', $octets);
        \assert(\is_array($lignes));

        $racines = [];
        /** @var array<int, NoeudGedcom> $pile niveau → dernier nœud ouvert à ce niveau */
        $pile = [];
        $dernier = null;
        $courant = null;
        $charset = null;
        $nbPhysiques = 0;

        foreach ($lignes as $index => $ligneBrute) {
            $numero = $index + 1;

            // Une dernière ligne vide est le sort normal d'un fichier qui se termine par
            // un saut de ligne : ce n'est pas une anomalie.
            if ('' === $ligneBrute && $index === \count($lignes) - 1) {
                continue;
            }

            ++$nbPhysiques;

            if (1 !== preg_match(self::GRAMMAIRE, $ligneBrute, $trouve)) {
                // Une ligne hors grammaire est presque toujours la suite d'une valeur
                // qu'un exporteur a coupée sans émettre de `CONT`. On la raccroche à la
                // valeur en cours plutôt que de la jeter — c'est le comportement des
                // scripts d'origine, et il a produit un `graphe.json` juste.
                if (null !== $dernier) {
                    $dernier->valeur .= "\n".$ligneBrute;
                }

                $anomalies->avertir(
                    CollecteurAnomalies::ENCODAGE_INVALIDE,
                    'Ligne hors grammaire GEDCOM, rattachée à la valeur précédente.',
                    $numero,
                    $courant?->xref,
                    null,
                    $ligneBrute,
                );

                continue;
            }

            $niveau = (int) $trouve[1];
            $xref = '' === $trouve[2] ? null : $trouve[2];
            $tag = $trouve[3];
            $valeur = $trouve[4] ?? '';

            if (null !== $dernier && ('CONC' === $tag || 'CONT' === $tag)) {
                $dernier->valeur .= ('CONC' === $tag ? '' : "\n").$valeur;

                continue;
            }

            $noeud = new NoeudGedcom($niveau, $xref, $tag, $valeur, $numero);
            $dernier = $noeud;

            if (0 === $niveau) {
                $racines[] = $noeud;
                $courant = $noeud;
                $pile = [0 => $noeud];

                continue;
            }

            $parent = $pile[$niveau - 1] ?? null;

            if (null === $parent) {
                // Un niveau qui saute une marche (`0 INDI` puis `2 DATE`) : on le
                // raccroche au plus profond parent connu plutôt que de perdre la ligne.
                $parent = $pile[max(array_keys($pile))] ?? null;

                $anomalies->avertir(
                    CollecteurAnomalies::ENCODAGE_INVALIDE,
                    \sprintf('Niveau %d sans parent de niveau %d.', $niveau, $niveau - 1),
                    $numero,
                    $courant?->xref,
                    $tag,
                    $ligneBrute,
                );
            }

            if (null === $parent) {
                continue;
            }

            $parent->ajouter($noeud);
            $pile[$niveau] = $noeud;

            // Les niveaux plus profonds que celui qu'on vient d'ouvrir sont périmés :
            // sans ce nettoyage, un `3 TEXT` orphelin se raccrocherait au `3 ROLE` de
            // l'enregistrement précédent.
            foreach (array_keys($pile) as $profondeur) {
                if ($profondeur > $niveau) {
                    unset($pile[$profondeur]);
                }
            }

            if (1 === $niveau && 'CHAR' === $tag && null !== $courant && 'HEAD' === $courant->tag) {
                $charset = $valeur;
            }
        }

        return new FichierGedcom($racines, $charset, $nbPhysiques);
    }
}
