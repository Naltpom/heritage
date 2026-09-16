<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Service;

use App\Module\Tree\Enum\SourceKind;

/**
 * Devine la nature d'un fonds documentaire depuis son `TITL` et son `PUBL`.
 *
 * `tree_source.source_kind` est un **ajout par rapport au GEDCOM** (§ 3.6 du modèle) :
 * c'est la colonne qui permettra de dire « ces 40 fiches reposent sur l'arbre d'un autre
 * utilisateur ». Le GEDCOM ne la porte pas, il faut donc la poser à l'import.
 *
 * **C'est une première passe, pas un verdict.** Le modèle la dit « révisable à la main »,
 * et c'est la bonne lecture : sur les 28 sources du fichier, les règles ci-dessous
 * tombent juste parce que les intitulés sont réguliers (« … Web Site » pour un arbre
 * d'utilisateur, « Registres d'état civil de … » pour un dépôt d'archives). Sur un fonds
 * au titre imprévu, elles rendront `autre` — ce qui est la bonne réponse par défaut :
 * `autre` n'affirme rien, alors qu'une catégorie devinée de travers ferait passer une
 * recopie pour un acte.
 */
final class QualificateurSource
{
    /**
     * L'ordre compte : une source peut porter plusieurs mots-clés, et le premier motif
     * qui reconnaît l'emporte. « Arbres généalogiques Geneanet » est d'abord un arbre
     * d'utilisateur, même si « Geneanet » évoque des relevés.
     *
     * @var list<array{0: non-empty-string, 1: SourceKind}>
     */
    private const array MOTIFS = [
        ['/\bweb\s?site\b|\barbres?\s+g[ée]n[ée]alogiques?\b|\bfamily\s+tree\b|\bgeni\b/iu', SourceKind::ArbreTiers],
        ['/\brecensements?\b|\bd[ée]nombrements?\s+de\s+population\b|\bcensus\b/iu', SourceKind::Recensement],
        ['/\b[ée]tat\s+civil\b|\bregistres?\s+paroissiaux\b|\btables?\s+d[ée]cennales?\b|\brelev[ée]s?\s+(?:collaboratifs?|d.actes)\b/iu', SourceKind::ActeEtatCivil],
        ['/\bregistres?\s+matricules?\b|\bfichier\s+des\s+personnes\b|\bindex\s+des\b|\bavis\s+de\s+d[ée]c[èe]s\b|\bd[ée]c[èe]s\b/iu', SourceKind::RegistreOfficiel],
        ['/\bfind\s?a\s?grave\b|\bm[ée]moriaux\b|\bfun[ée]raires?\b/iu', SourceKind::Publication],
        ['/\bt[ée]moignage\b|\bsouvenirs?\s+de\s+famille\b/iu', SourceKind::Temoignage],
    ];

    public function qualifier(?string $titre, ?string $publication = null, ?string $auteur = null): SourceKind
    {
        $texte = trim(implode(' ', array_filter([$titre, $publication, $auteur])));

        if ('' === $texte) {
            return SourceKind::Autre;
        }

        foreach (self::MOTIFS as [$motif, $genre]) {
            if (1 === preg_match($motif, $texte)) {
                return $genre;
            }
        }

        return SourceKind::Autre;
    }
}
