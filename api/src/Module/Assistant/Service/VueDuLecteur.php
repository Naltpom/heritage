<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

use App\Module\Assistant\Contract\PersonneDeLArbre;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LA SEULE PORTE DE SORTIE DE L'ASSISTANT VERS LE NAVIGATEUR — et elle passe par le
 *  cercle du lecteur.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Le modèle voit tout l'arbre (décision du commanditaire) ; le membre, non. Tout ce qui
 * décrit une personne existante dans la réponse passe par ici, et suit **la règle du
 * graphe** (`GET /api/arbre/graphe`, ADR 0003) :
 *
 * - une personne **décédée** est un fait historique, visible de tout compte connecté ;
 * - une personne **vivante** n'est visible en entier que dans le cercle du lecteur ;
 * - hors cercle, elle garde son nom et son identifiant — `visibilite: "restreinte"` — et
 *   **rien de daté** : ni ligne de dates, ni valeur d'avant dans un changement, ni
 *   `oldValue` dans le corps affiché.
 *
 * `vivante` vient de `Tree` (la règle du graphe, un décès inconnu vaut vivant) ; la
 * question du cercle est posée à `Core\Security\CercleDeVisibiliteInterface`, dont
 * l'implémentation appartient à `Membership`. Aucune des deux n'est recalculée ici.
 */
final class VueDuLecteur
{
    public const string VISIBILITE_COMPLETE = 'complete';

    public const string VISIBILITE_RESTREINTE = 'restreinte';

    /** @var array<int, bool> la réponse du cercle, une fois par personne et par requête */
    private array $memoire = [];

    public function __construct(private readonly CercleDeVisibiliteInterface $cercle)
    {
    }

    /**
     * Le lecteur a-t-il le droit de voir les données datées de cette personne ?
     *
     * Le test « vivante » passe **avant** le cercle : une personne décédée ne coûte pas
     * de résolution de cercle, et c'est la majorité d'un arbre généalogique.
     */
    public function voitEnEntier(PersonneDeLArbre $personne): bool
    {
        if (!$personne->vivante) {
            return true;
        }

        return $this->memoire[$personne->id] ??= $this->cercle->voitPersonne($personne->id);
    }

    /**
     * Une personne citée par une demande, telle que le front l'affiche (`PersonneCitee`).
     *
     * **`ligneDates` est absente, et non `null`, pour une personne restreinte** — c'est
     * la convention du § 1.3 du contrat d'API : un champ interdit n'est pas « vide », il
     * n'existe pas dans la charge.
     *
     * @return array{personId: int, displayName: string, visibilite: string, ligneDates?: string}
     */
    public function personneCitee(PersonneDeLArbre $personne): array
    {
        $citee = [
            'personId' => $personne->id,
            'displayName' => $personne->nomAffiche,
            'visibilite' => self::VISIBILITE_RESTREINTE,
        ];

        if (!$this->voitEnEntier($personne)) {
            return $citee;
        }

        $citee['visibilite'] = self::VISIBILITE_COMPLETE;
        $citee['ligneDates'] = self::ligneDates($personne->naissance, $personne->deces);

        return $citee;
    }

    /** « vers 1889 – 1972 », composée depuis les blocs sans jamais les aplatir. */
    public static function ligneDates(UncertainDate $naissance, UncertainDate $deces): string
    {
        $debut = $naissance->format();
        $fin = $deces->format();

        return match (true) {
            '' !== $debut && '' !== $fin => $debut.' – '.$fin,
            '' !== $debut => $debut.' –',
            '' !== $fin => '– '.$fin,
            default => '',
        };
    }
}
