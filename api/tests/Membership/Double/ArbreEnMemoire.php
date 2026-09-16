<?php

declare(strict_types=1);

namespace App\Tests\Membership\Double;

use App\Module\Membership\Service\Port\FamilleDeLArbre;
use App\Module\Membership\Service\Port\PersonneDeLArbre;
use App\Module\Membership\Service\Port\PortArbre;
use App\Module\Membership\Service\Port\VueDeLArbre;

/**
 * L'arbre, joué par un double réglable — il tient la place de l'adaptateur de `Tree`.
 *
 * Ce qui est testé dans ce module, ce n'est pas la façon dont `Tree` charge ses tables :
 * c'est la **formule** du cercle, les garde-fous de la recherche de rattachement, et le
 * fait que la sérialisation obéisse. Un double rend ces tests lisibles — un arbre de
 * huit personnes dont on voit la forme d'un coup d'œil — et surtout **indépendants de
 * PostgreSQL** : la base de test est SQLite, `TreeFingerprint` est écrit en SQL
 * PostgreSQL, et les tables `tree_*` ne sont pas montées ici.
 *
 * ## Pourquoi l'état est statique
 *
 * `KernelBrowser` relance le noyau **avant chaque requête** : un double peuplé par le test
 * serait remis à zéro entre deux appels HTTP, et le test verrait un arbre vide sans que
 * rien ne le dise. L'état vit donc dans une propriété statique, réinitialisée en `setUp`.
 * Ce n'est pas élégant ; c'est ce qui rend le double utilisable depuis un test fonctionnel,
 * et la même contrainte a déjà été payée sur la base SQLite et les pools de cache
 * (`tests/bootstrap.php`).
 */
final class ArbreEnMemoire implements PortArbre
{
    /** @var list<PersonneDeLArbre> */
    private static array $personnes = [];

    /** @var list<FamilleDeLArbre> */
    private static array $familles = [];

    private static string $empreinte = 'arbre-de-test';

    public static function reinitialiser(): void
    {
        self::$personnes = [];
        self::$familles = [];
        self::$empreinte = 'arbre-de-test';
    }

    public static function ajouterPersonne(
        int $id,
        string $prenoms,
        string $nom,
        bool $vivante,
        ?int $anneeNaissance = null,
        string $etiquetteNaissance = '',
        ?string $libelle = null,
    ): void {
        self::$personnes[] = new PersonneDeLArbre(
            $id,
            $prenoms,
            $nom,
            $libelle ?? trim($prenoms.' '.$nom),
            $anneeNaissance,
            $etiquetteNaissance,
            $vivante,
        );
    }

    /**
     * @param list<int> $epoux
     * @param list<int> $enfants
     */
    public static function ajouterFamille(array $epoux, array $enfants = []): void
    {
        self::$familles[] = new FamilleDeLArbre($epoux, $enfants);
    }

    /** Fait bouger l'empreinte, comme le ferait une validation qui touche `tree_person`. */
    public static function changerLEmpreinte(string $empreinte): void
    {
        self::$empreinte = $empreinte;
    }

    public function vue(): VueDeLArbre
    {
        return new VueDeLArbre(self::$personnes, self::$familles);
    }

    public function empreinte(): string
    {
        return self::$empreinte;
    }
}
