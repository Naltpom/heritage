<?php

declare(strict_types=1);

namespace App\Module\Question\Service;

use App\Module\Question\Contract\FicheEtudiee;

/**
 * Ce qui part chez le fournisseur du modèle — **l'arbre, personnes vivantes comprises**.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  C'EST LE SEUL ENDROIT DU PRODUIT OÙ DES DONNÉES DE PERSONNES VIVANTES
 *  QUITTENT L'APPLICATION. L'ARBITRAGE EST RENDU (ADR 0008 § 1), IL N'EST PAS
 *  À RELITIGER — MAIS IL EST À CONNAÎTRE AVANT DE TOUCHER À CE FICHIER.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * L'ADR 0008 amende une phrase de l'ADR 0003 — « le graphe montre, il n'énumère pas ;
 * pas de liste, pas d'export » — **pour un tiers unique, nommé, sous contrat**, et pour
 * lui seul. Elle reste entière pour tout compte, y compris administrateur.
 *
 * ## Ce que le corpus contient, et pourquoi chaque champ y est
 *
 * | Champ | Pourquoi il part |
 * |---|---|
 * | `nom` | c'est lui qui rend une question reconnaissable — « Alberte » et non « la personne n° 214 » |
 * | `vivante` | pour que le modèle n'écrive pas une question au passé sur quelqu'un de vivant |
 * | `naissance`, `deces` | l'époque situe la personne : on ne parle pas d'un aïeul de 1830 comme d'une grand-tante |
 * | `pere`, `mere`, `conjoints`, `enfants` | **le seul motif de l'envoi entier** : « la sœur de votre grand-mère » se calcule sur la parenté |
 *
 * ## Ce qui n'y est PAS, et ce n'est pas un oubli
 *
 * **Aucune réponse de membre.** Ni le texte libre, ni la photo, ni le fait qu'un membre
 * ait répondu « je ne sais pas ». Le flux est à sens unique : l'arbre sort pour qu'une
 * question soit écrite ; ce qui revient de la famille entre dans la file de validation
 * et n'en ressort pas. Une réponse contient des propos sur des vivants que l'arbre ne
 * contient pas — un jugement, une brouille, une santé.
 *
 * **Aucune citation, aucune note, aucun média, aucun identifiant de compte.** Rien de ce
 * qui ne sert pas à écrire la phrase.
 *
 * **Aucune date exacte quand elle n'est pas nécessaire** : les bornes sont réduites à
 * l'**année**. Le jour et le mois n'aident en rien à formuler une question et sont ce
 * qu'une date de naissance a de plus identifiant.
 *
 * ## Les identifiants sont locaux au corpus
 *
 * Les personnes y sont numérotées `1, 2, 3…` dans l'ordre du flux, et non par leur
 * `tree_person.id`. Ce n'est pas une pseudonymisation — le nom part juste à côté, et
 * prétendre le contraire serait se mentir. C'est une hygiène : l'identifiant interne
 * d'une base n'a aucune raison de sortir, et rien dans la réponse du modèle ne doit
 * pouvoir être relu comme désignant une ligne.
 */
final class CorpusDeLArbre
{
    /**
     * @param list<FicheEtudiee> $fiches **toutes** les fiches, non filtrées
     *
     * @return array<string, mixed> prêt à être encodé en JSON
     */
    public function pour(array $fiches): array
    {
        $rangs = [];

        foreach ($fiches as $rang => $fiche) {
            $rangs[$fiche->id] = $rang + 1;
        }

        $personnes = [];

        foreach ($fiches as $fiche) {
            $personnes[] = array_filter(
                [
                    'n' => $rangs[$fiche->id],
                    'nom' => $fiche->nomAffiche,
                    'sexe' => $fiche->sexe,
                    'vivante' => $fiche->vivante,
                    // ADR 0016 : **aucune** naissance de personne vivante ne part chez le tiers,
                    // quels que soient les consentements — le corpus est commun à tous les
                    // lecteurs, et la réciprocité ne se découpe pas dans un texte partagé.
                    'naissance' => $fiche->vivante ? null : self::annee($fiche->naissance->getMin(), $fiche->naissance->getMax()),
                    'deces' => self::annee($fiche->deces->getMin(), $fiche->deces->getMax()),
                    'pere' => null === $fiche->pereId ? null : ($rangs[$fiche->pereId] ?? null),
                    'mere' => null === $fiche->mereId ? null : ($rangs[$fiche->mereId] ?? null),
                    'conjoints' => self::rangs($fiche->conjointIds, $rangs),
                    'enfants' => self::rangs($fiche->enfantIds, $rangs),
                ],
                // Les clés nulles ou vides sont retirées : sur 274 fiches dont la
                // plupart n'ont ni conjoint ni date, elles représentent l'essentiel du
                // volume. Ce n'est pas qu'une économie — un corpus plus court est aussi
                // un corpus dont le modèle lit mieux ce qui reste.
                static fn (mixed $valeur): bool => null !== $valeur && [] !== $valeur,
            );
        }

        return ['personnes' => $personnes];
    }

    /**
     * L'**année**, et rien de plus fin.
     *
     * Une fourchette rend ses deux bornes (`1901-1909`) : la distinction entre une date
     * sûre et une date approchée change la façon dont une question se formule — on ne
     * demande pas un lieu de la même manière selon qu'on sait l'année ou qu'on l'estime.
     */
    private static function annee(?\DateTimeImmutable $min, ?\DateTimeImmutable $max): ?string
    {
        $debut = $min?->format('Y');
        $fin = $max?->format('Y');

        if (null === $debut && null === $fin) {
            return null;
        }

        if (null === $debut) {
            return 'avant '.$fin;
        }

        if (null === $fin) {
            return 'après '.$debut;
        }

        return $debut === $fin ? $debut : $debut.'-'.$fin;
    }

    /**
     * @param list<int>       $ids
     * @param array<int, int> $rangs
     *
     * @return list<int>
     */
    private static function rangs(array $ids, array $rangs): array
    {
        $traduits = [];

        foreach ($ids as $id) {
            if (isset($rangs[$id])) {
                $traduits[] = $rangs[$id];
            }
        }

        return $traduits;
    }
}
