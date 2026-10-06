<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

use App\Module\Assistant\Contract\ArbrePourLAssistant;
use App\Module\Assistant\Contract\ArbrePourLaConsultation;
use App\Module\Assistant\Contract\FaitDeLArbre;
use App\Module\Assistant\Contract\PersonneDeLArbre;
use App\Module\Assistant\Contract\SourceCitee;
use App\Module\Assistant\Contract\UnionDeLArbre;
use App\Module\Core\Security\CercleDeVisibiliteInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LES OUTILS DE « POSER UNE QUESTION » — LE MODÈLE NE VOIT QUE CE QUE LE LECTEUR VOIT.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Mêmes noms d'outils de lecture que l'assistant de proposition — `chercher_personnes`,
 * `lire_personne` —, mêmes ports, même {@see VueDuLecteur}, même cercle. **Une
 * différence, et c'est toute la raison d'être de cette classe** :
 *
 * > {@see OutilsDeLAssistant} montre au modèle l'arbre entier et lui signale les
 * > personnes confidentielles ; **ces outils-ci ne les lui montrent pas.**
 *
 * L'assistant de proposition a besoin de reconnaître une personne cachée pour ne pas la
 * proposer en double (décision du commanditaire, ADR 0008) ; il rend des demandes, que
 * le serveur filtre avant de les servir. Ici, le modèle rend **une phrase libre** au
 * lecteur — et une phrase ne se filtre pas champ par champ. Si le modèle connaissait le
 * nom d'un vivant hors cercle, une réponse pourrait le dire, en toutes lettres ou par
 * allusion (« votre cousin né à Quimper en 1990 »), et aucun masque posé après coup ne
 * le rattraperait à coup sûr. **On ne filtre donc pas la sortie : on filtre l'entrée.**
 *
 * La règle est celle des portes de sortie de l'ADR 0003, appliquée au modèle comme à un
 * navigateur :
 *
 * | Outil | Vivant hors du cercle du lecteur |
 * |---|---|
 * | `chercher_personnes` | **absent** — comme `GET /api/arbre/recherche` |
 * | `chercher_par_lieu` | **absent** |
 * | `lire_personne` sur lui | identifiant, « Personne vivante », `confidentielle` — rien d'autre, comme la fiche réduite |
 * | parent, conjoint, enfant, fratrie d'une fiche lue | identifiant et « Personne vivante » — comme les références de la fiche |
 *
 * Le modèle n'apprend donc d'un vivant caché que ce qu'un lecteur lit sur le graphe :
 * qu'il existe, et où il se rattache. Rien de daté, pas un nom.
 *
 * ── Pourquoi on ne passe pas les textes au carnet des noms masqués ──────────────
 *
 * {@see VueDuLecteur::sansNomMasque()} remplace dans un texte du modèle le nom de chaque
 * personne restreinte croisée pendant la requête. Ici, ce serait **une fuite** : un
 * membre qui demande « où est né Jean Dupont ? » ferait croiser à la recherche le Jean
 * Dupont vivant qu'il ne voit pas, et la réponse « je ne trouve pas Jean Dupont » sortirait
 * « je ne trouve pas Personne vivante » — c'est-à-dire l'aveu qu'il existe. Le modèle n'a
 * reçu aucun nom caché ; il n'y a rien à rattraper, et l'on ne rattrape rien.
 */
final readonly class OutilsDeConsultation
{
    public const string CHERCHER = OutilsDeLAssistant::CHERCHER;

    public const string LIRE = OutilsDeLAssistant::LIRE;

    public const string CHERCHER_PAR_LIEU = 'chercher_par_lieu';

    /** L'outil final : ses arguments sont la réponse. */
    public const string REPONDRE = 'repondre';

    /** Au plus quinze personnes par recherche par nom, comme l'assistant de proposition. */
    public const int RESULTATS_MAXIMAUX = OutilsDeLAssistant::RESULTATS_MAXIMAUX;

    /** Au plus quarante par recherche par lieu : « qui est né à Étaples » en rend vingt. */
    public const int RESULTATS_PAR_LIEU = 40;

    /**
     * Le sur-échantillon demandé aux ports : le cercle en retirera, et le plafond se coupe
     * **après** le filtre — un plafond posé avant dirait combien on cache.
     */
    private const int SUR_ECHANTILLON = 4;

    public function __construct(
        private ArbrePourLAssistant $arbre,
        private ArbrePourLaConsultation $consultation,
        private VueDuLecteur $vue,
    ) {
    }

    /**
     * Les `functionDeclarations` de la requête.
     *
     * @return list<array<string, mixed>>
     */
    public static function declarations(): array
    {
        return [
            [
                'name' => self::CHERCHER,
                'description' => 'Cherche des personnes de l’arbre par leur nom (prénom et/ou nom, dans n’importe quel ordre). Rend au plus 15 personnes avec leurs dates, leurs parents et leurs conjoints.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'requete' => ['type' => 'string', 'description' => 'Un ou plusieurs mots du nom, par exemple « Marie Becquelin » ou « Becquelin ».'],
                    ],
                    'required' => ['requete'],
                ],
            ],
            [
                'name' => self::LIRE,
                'description' => 'Lit la fiche d’une personne : identité, dates, lien de parenté avec le lecteur, parents, unions et enfants, fratrie, faits (profession, résidence…), sources citées avec leur appui (acte, index, arbre en ligne), notes.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'personId' => ['type' => 'integer', 'description' => 'Le numéro rendu par un autre outil.'],
                    ],
                    'required' => ['personId'],
                ],
            ],
            [
                'name' => self::CHERCHER_PAR_LIEU,
                'description' => 'Cherche les personnes nées ou décédées dans un lieu et/ou pendant une période. Rend au plus 40 personnes, dans l’ordre des dates.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'lieu' => ['type' => 'string', 'description' => 'Un ou plusieurs mots du lieu, sans se soucier des accents : « Étaples », « Pas-de-Calais ».'],
                        'anneeMin' => ['type' => 'integer', 'description' => 'Première année de la période, incluse.'],
                        'anneeMax' => ['type' => 'integer', 'description' => 'Dernière année de la période, incluse.'],
                        'evenement' => ['type' => 'string', 'enum' => ['naissance', 'deces', 'tous'], 'description' => 'L’événement qui doit se trouver dans le lieu ou la période. « tous » par défaut.'],
                    ],
                ],
            ],
            [
                'name' => self::REPONDRE,
                'description' => 'Outil FINAL : rend ta réponse au membre. À appeler une seule fois, quand ta recherche est terminée — y compris pour dire que l’arbre ne permet pas de répondre.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'texte' => ['type' => 'string', 'description' => 'La réponse, en français. Chaque personne citée sous la forme [Prénom Nom](personne:12).'],
                        'personIds' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Les numéros des fiches citées dans la réponse.'],
                    ],
                    'required' => ['texte', 'personIds'],
                ],
            ],
        ];
    }

    /**
     * Exécute un outil de lecture. Un appel mal formé rend une `erreur` **au modèle**, pas
     * une exception : il peut se corriger au tour suivant.
     *
     * @param array<string, mixed> $arguments
     * @param int|null             $personneDuLecteur l'ego de la parenté
     *
     * @return array<string, mixed>
     */
    public function executer(string $nom, array $arguments, ?int $personneDuLecteur): array
    {
        return match ($nom) {
            self::CHERCHER => $this->chercher($arguments),
            self::LIRE => $this->lire($arguments, $personneDuLecteur),
            self::CHERCHER_PAR_LIEU => $this->chercherParLieu($arguments),
            default => ['erreur' => \sprintf('Outil inconnu : %s.', $nom)],
        };
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function chercher(array $arguments): array
    {
        $requete = $arguments['requete'] ?? null;

        if (!\is_string($requete) || '' === trim($requete)) {
            return ['erreur' => 'Le paramètre « requete » est obligatoire.'];
        }

        $visibles = array_values(array_filter(
            $this->arbre->chercher(mb_substr($requete, 0, 200), self::RESULTATS_MAXIMAUX * self::SUR_ECHANTILLON),
            // **Absente**, et non réduite : une ligne de résultat est une énumération.
            $this->vue->voitEnEntier(...),
        ));

        return [
            'personnes' => array_map(
                fn (PersonneDeLArbre $p): array => $this->personne($p, true),
                \array_slice($visibles, 0, self::RESULTATS_MAXIMAUX),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function chercherParLieu(array $arguments): array
    {
        $lieu = $arguments['lieu'] ?? null;
        $lieu = \is_string($lieu) && '' !== trim($lieu) ? mb_substr(trim($lieu), 0, 120) : null;
        $min = self::annee($arguments['anneeMin'] ?? null);
        $max = self::annee($arguments['anneeMax'] ?? null);
        $evenement = $arguments['evenement'] ?? 'tous';

        if (null === $lieu && null === $min && null === $max) {
            return ['erreur' => 'Donne au moins un lieu ou une année.'];
        }

        if (!\in_array($evenement, ['naissance', 'deces', 'tous'], true)) {
            $evenement = 'tous';
        }

        $visibles = array_values(array_filter(
            $this->consultation->parLieuEtPeriode($lieu, $min, $max, $evenement, self::RESULTATS_PAR_LIEU * self::SUR_ECHANTILLON),
            $this->vue->voitEnEntier(...),
        ));

        return [
            'personnes' => array_map(
                fn (PersonneDeLArbre $p): array => $this->personne($p, false),
                \array_slice($visibles, 0, self::RESULTATS_PAR_LIEU),
            ),
            // Calculé sur le jeu **déjà filtré** : « il y en a d'autres » ne dit rien de
            // ce qui est caché.
            'tronque' => \count($visibles) > self::RESULTATS_PAR_LIEU,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function lire(array $arguments, ?int $personneDuLecteur): array
    {
        $id = $arguments['personId'] ?? null;

        // Gemini rend les entiers JSON tantôt `12`, tantôt `12.0`.
        if (\is_float($id) || (\is_string($id) && ctype_digit($id))) {
            $id = (int) $id;
        }

        if (!\is_int($id)) {
            return ['erreur' => 'Le paramètre « personId » est un entier.'];
        }

        // L'arbre d'un autre compte n'existe pas pour ce lecteur, ni pour le modèle qui
        // travaille en son nom : la même réponse qu'un identifiant inconnu.
        $fiche = $this->vue->connait($id) ? $this->arbre->fiche($id) : null;

        if (null === $fiche) {
            return ['erreur' => \sprintf('Aucune personne n° %d dans l’arbre.', $id)];
        }

        if (!$this->vue->voitEnEntier($fiche->personne)) {
            // La fiche réduite de l'ADR 0003, rien de plus : ni parents, ni unions, ni
            // sources — ce sont des données de la personne, pas du graphe.
            return [
                ...$this->restreinte($fiche->personne),
                'remarque' => 'Fiche confidentielle : personne vivante que ce membre n’a pas le droit de connaître. Rien d’autre ne peut en être dit.',
            ];
        }

        $vue = $this->personne($fiche->personne, false);
        $vue['prenoms'] = $fiche->prenoms;
        $vue['nomDeNaissance'] = $fiche->nom;
        $vue['nomDUsage'] = $fiche->nomDUsage;
        $vue['surnom'] = $fiche->surnom;
        $vue['naissance'] = OutilsDeLAssistant::dateDetaillee($fiche->personne->naissance);
        $vue['deces'] = OutilsDeLAssistant::dateDetaillee($fiche->personne->deces);
        $vue['parenteAvecLeLecteur'] = match (true) {
            null === $personneDuLecteur => null,
            $personneDuLecteur === $id => 'c’est le lecteur lui-même',
            default => $this->consultation->parente($personneDuLecteur, $id),
        };
        $vue['parents'] = array_map(fn (PersonneDeLArbre $p): array => $this->personne($p, false), $fiche->parents);
        $vue['unions'] = array_map(fn (UnionDeLArbre $u): array => [
            'conjoint' => null === $u->conjoint ? null : $this->personne($u->conjoint, false),
            // Les dates d'union restent, même conjoint masqué : elles sont à la famille
            // (§ 5.4 du contrat), et la fiche les montre au lecteur.
            'mariage' => OutilsDeLAssistant::dateLisible($u->mariage),
            'divorce' => $u->divorce,
            'enfants' => array_map(fn (PersonneDeLArbre $p): array => $this->personne($p, false), $u->enfants),
        ], $fiche->unions);
        $vue['fratrie'] = array_map(fn (PersonneDeLArbre $p): array => $this->personne($p, false), $fiche->fratrie);

        $dossier = $this->consultation->dossier($id);

        $vue['faits'] = null === $dossier ? [] : array_map(static fn (FaitDeLArbre $f): array => array_filter([
            'type' => $f->type,
            'libelle' => $f->libelle,
            'date' => OutilsDeLAssistant::dateLisible($f->date),
            'adresse' => $f->adresse,
            'age' => $f->age,
            'cause' => $f->cause,
        ], static fn (mixed $v): bool => null !== $v && '' !== $v), $dossier->faits);
        $vue['sources'] = null === $dossier ? [] : array_map(static fn (SourceCitee $s): array => array_filter([
            'appui' => $s->appui,
            'appuiLibelle' => $s->appuiLibelle,
            'qualite' => $s->qualite,
            'titre' => $s->titre,
            'fonds' => $s->fonds,
            'evenement' => $s->contexte,
            'page' => $s->page,
            'texte' => null === $s->texte ? null : mb_substr($s->texte, 0, 600),
        ], static fn (mixed $v): bool => null !== $v && '' !== $v), $dossier->sources);
        $vue['notes'] = null === $dossier ? [] : array_map(static fn (string $n): string => mb_substr($n, 0, 1500), $dossier->notes);

        return $vue;
    }

    /**
     * Une personne vue par le lecteur : entière s'il la voit, réduite sinon.
     *
     * @return array<string, mixed>
     */
    private function personne(PersonneDeLArbre $personne, bool $avecParente): array
    {
        if (!$this->vue->voitEnEntier($personne)) {
            return $this->restreinte($personne);
        }

        $vue = [
            'personId' => $personne->id,
            'nom' => $personne->nomAffiche,
            'sexe' => $personne->sexe->value,
            'vivant' => $personne->vivante,
            'naissance' => OutilsDeLAssistant::dateLisible($personne->naissance),
            'deces' => OutilsDeLAssistant::dateLisible($personne->deces),
        ];

        if ($avecParente) {
            $vue['parents'] = array_map($this->nomDUnProche(...), $personne->parents);
            $vue['conjoints'] = array_map($this->nomDUnProche(...), $personne->conjoints);
        }

        return $vue;
    }

    /**
     * Le masque, **posé à la construction** : la charge ne contient le vrai nom à aucun
     * moment, ni le sexe, ni une date.
     *
     * @return array{personId: int, nom: string, vivant: true, confidentielle: true}
     */
    private function restreinte(PersonneDeLArbre $personne): array
    {
        return [
            'personId' => $personne->id,
            'nom' => CercleDeVisibiliteInterface::NOM_MASQUE,
            'vivant' => true,
            'confidentielle' => true,
        ];
    }

    private function nomDUnProche(PersonneDeLArbre $proche): string
    {
        return $this->vue->voitEnEntier($proche) ? $proche->nomAffiche : CercleDeVisibiliteInterface::NOM_MASQUE;
    }

    private static function annee(mixed $brut): ?int
    {
        if (\is_float($brut) || (\is_string($brut) && ctype_digit($brut))) {
            $brut = (int) $brut;
        }

        return \is_int($brut) && $brut >= 1000 && $brut <= 2200 ? $brut : null;
    }
}
