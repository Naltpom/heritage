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
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Le modèle voit tout l'arbre (décision du commanditaire) ; le membre, non. Tout ce qui
 * décrit une personne existante dans la réponse passe par ici, et suit **la règle du
 * graphe** (`GET /api/arbre/graphe`, ADR 0003) :
 *
 * - une personne **décédée** est un fait historique, visible de tout compte connecté ;
 * - une personne **vivante** est visible en entier de tout membre validé — révision du
 *   2026-10-01 de l'ADR 0003 —, **sauf** fiche à confidentialité forcée, qui ne l'est que
 *   dans le cercle du lecteur ;
 * - hors de ce que le lecteur voit, elle garde son identifiant — `visibilite: "restreinte"` —, **plus son
 *   nom** (révision du 2026-10-01 de l'ADR 0003 : `displayName` vaut
 *   {@see CercleDeVisibiliteInterface::NOM_MASQUE}), et **rien de daté** : ni ligne de
 *   dates, ni valeur d'avant dans un changement, ni `oldValue` dans le corps affiché.
 *
 * `vivante` vient de `Tree` (la règle du graphe, un décès inconnu vaut vivant) ; la
 * question du cercle est posée à `Core\Security\CercleDeVisibiliteInterface`, dont
 * l'implémentation appartient à `Membership`. Aucune des deux n'est recalculée ici.
 *
 * ── Et les textes libres du modèle ──────────────────────────────────────────────
 *
 * `titre`, `justification`, `resume` et `avertissements` sont **écrits par le modèle**,
 * qui connaît le vrai nom des personnes confidentielles (il en a besoin pour reconnaître
 * celle que le membre cite). La consigne lui interdit de les y nommer ; c'est la première
 * ligne de défense. La seconde est ici : chaque personne que {@see self::voitEnEntier()}
 * déclare restreinte pendant la requête — dans les résultats des outils comme dans les
 * demandes présentées — laisse son nom dans un carnet, et {@see self::sansNomMasque()}
 * remplace chacun de ces noms par le masque dans un texte du modèle avant qu'il ne parte.
 */
final class VueDuLecteur
{
    public const string VISIBILITE_COMPLETE = 'complete';

    public const string VISIBILITE_RESTREINTE = 'restreinte';

    /** @var array<int, bool> la réponse du cercle, une fois par personne et par requête */
    private array $memoire = [];

    /** @var array<string, true> le vrai nom de chaque personne restreinte croisée dans la requête */
    private array $nomsMasques = [];

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

        $voit = $this->memoire[$personne->id] ??= $this->cercle->voitPersonne($personne->id);

        if (!$voit && '' !== trim($personne->nomAffiche)) {
            $this->nomsMasques[trim($personne->nomAffiche)] = true;
        }

        return $voit;
    }

    /**
     * Un texte écrit par le modèle, chaque nom de personne restreinte croisée dans la
     * requête remplacé par {@see CercleDeVisibiliteInterface::NOM_MASQUE}.
     *
     * Insensible à la casse — le modèle écrit volontiers « DUPONT Jean » d'après un acte
     * —, et **le plus long d'abord** : « Jean Dupont » doit être remplacé avant « Jean »,
     * sans quoi le reste du nom survivrait. Un nom cité autrement (le prénom seul, une
     * inversion) échappe à ce filet : c'est la consigne qui l'interdit, ceci n'est que le
     * rattrapage du cas le plus fréquent, la recopie du nom affiché.
     */
    public function sansNomMasque(string $texte): string
    {
        if ([] === $this->nomsMasques || '' === $texte) {
            return $texte;
        }

        $noms = array_map('strval', array_keys($this->nomsMasques));
        usort($noms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($noms as $nom) {
            $texte = (string) preg_replace('/'.preg_quote($nom, '/').'/iu', CercleDeVisibiliteInterface::NOM_MASQUE, $texte);
        }

        return $texte;
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
        if (!$this->voitEnEntier($personne)) {
            // Le masque, posé à la construction et jamais par remplacement : la charge
            // restreinte ne contient le vrai nom à aucun moment.
            return [
                'personId' => $personne->id,
                'displayName' => CercleDeVisibiliteInterface::NOM_MASQUE,
                'visibilite' => self::VISIBILITE_RESTREINTE,
            ];
        }

        $citee = [
            'personId' => $personne->id,
            'displayName' => $personne->nomAffiche,
            'visibilite' => self::VISIBILITE_COMPLETE,
        ];

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
