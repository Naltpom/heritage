<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

use App\Module\Assistant\Contract\ArbrePourLAssistant;
use App\Module\Assistant\Contract\PersonneDeLArbre;
use App\Module\Assistant\Contract\UnionDeLArbre;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * Les trois outils du modèle : leurs déclarations (le schéma que Gemini reçoit) et
 * l'exécution des deux outils de lecture.
 *
 * ── Les outils lisent TOUT l'arbre, et disent au modèle ce qu'il ne doit pas répéter ──
 *
 * Décision du commanditaire : le modèle reconnaît les personnes sur l'arbre entier. Mais
 * chaque personne qu'il reçoit porte `confidentielle: true` quand le membre qui l'a
 * sollicité ne voit pas ses données datées (vivante, hors de son cercle). La consigne
 * système lui interdit de citer quoi que ce soit de ces personnes au-delà de leur nom ;
 * c'est une **seconde** ligne de défense — la première est que rien de ce qu'on renvoie
 * au navigateur ne vient des outils sans passer par {@see VueDuLecteur}.
 *
 * `proposer` n'est pas exécuté ici : c'est l'outil final, et ses arguments sont la
 * réponse de l'agent ({@see AgentDeLAssistant}).
 */
final readonly class OutilsDeLAssistant
{
    public const string CHERCHER = 'chercher_personnes';

    public const string LIRE = 'lire_personne';

    public const string PROPOSER = 'proposer';

    /** Au plus quinze résultats par recherche : le contrat de l'outil, et assez pour départager. */
    public const int RESULTATS_MAXIMAUX = 15;

    public function __construct(
        private ArbrePourLAssistant $arbre,
        private VueDuLecteur $vue,
    ) {
    }

    /**
     * Les `functionDeclarations` de la requête — le sous-ensemble OpenAPI que Gemini lit.
     *
     * @return list<array<string, mixed>>
     */
    public static function declarations(): array
    {
        $date = [
            'type' => 'object',
            'description' => 'Une date telle que le document la donne, sans l’inventer ni la préciser. En cas de doute, préférer « environ » ou « entre » à une date exacte.',
            'properties' => [
                'qualificatif' => [
                    'type' => 'string',
                    'enum' => ['exacte', 'mois', 'annee', 'environ', 'avant', 'apres', 'entre'],
                    'description' => 'exacte = jour connu ; mois = mois et année ; annee = année seule ; environ = « vers » ; avant / apres = borne exclue ; entre = fourchette (remplir date_fin).',
                ],
                'date' => ['type' => 'string', 'description' => 'AAAA, AAAA-MM ou AAAA-MM-JJ selon la précision réelle du document.'],
                'date_fin' => ['type' => 'string', 'description' => 'Seconde borne, uniquement pour « entre » (AAAA, AAAA-MM ou AAAA-MM-JJ).'],
                'lieu' => ['type' => 'string', 'description' => 'Le lieu tel qu’écrit (commune, département, pays).'],
            ],
        ];

        return [
            [
                'name' => self::CHERCHER,
                'description' => 'Cherche des personnes de l’arbre par leur nom (prénom et/ou nom, dans n’importe quel ordre). Rend au plus 15 personnes avec leurs dates, leurs parents et leurs conjoints. À utiliser pour CHAQUE personne citée avant de proposer de la créer.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'requete' => ['type' => 'string', 'description' => 'Un ou plusieurs mots du nom, par exemple « Marie Dupont » ou « Dupont ».'],
                    ],
                    'required' => ['requete'],
                ],
            ],
            [
                'name' => self::LIRE,
                'description' => 'Lit la fiche complète d’une personne de l’arbre : champs modifiables, parents, unions (avec l’identifiant de famille et les enfants), fratrie.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'personId' => ['type' => 'integer', 'description' => 'L’identifiant rendu par chercher_personnes.'],
                    ],
                    'required' => ['personId'],
                ],
            ],
            [
                'name' => self::PROPOSER,
                'description' => 'Outil FINAL : rend les demandes à soumettre au membre. À appeler une seule fois, quand l’analyse est terminée — même avec une liste vide si rien n’est à proposer.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'resume' => ['type' => 'string', 'description' => 'Ce que tu as compris des documents et du texte, en 1 à 3 phrases, sans aucune donnée de l’arbre qui n’y figure pas.'],
                        'avertissements' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Ce que le membre doit savoir : document illisible, homonymie non tranchée, information contradictoire.'],
                        'demandes' => [
                            'type' => 'array',
                            'description' => 'Une demande par nature et par personne visée.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'nature' => ['type' => 'string', 'enum' => ['creation_personne', 'modification_personne', 'ajout_lien']],
                                    'titre' => ['type' => 'string', 'description' => 'Une ligne, par exemple « Ajouter Marie Dupont (1921), fille de Jean Dupont ».'],
                                    'justification' => ['type' => 'string', 'description' => 'Sur quoi s’appuie la demande : quel document, quelle phrase du membre. Jamais une donnée de l’arbre.'],
                                    'confiance' => ['type' => 'string', 'enum' => ['haute', 'moyenne', 'faible']],
                                    'personId' => ['type' => 'integer', 'description' => 'modification_personne et ajout_lien : la personne existante visée.'],
                                    'changements' => [
                                        'type' => 'array',
                                        'description' => 'modification_personne : un élément par champ modifié.',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'champ' => ['type' => 'string', 'enum' => ['givenNames', 'surname', 'marriedName', 'nickname', 'sex', 'deceased', 'birth', 'death']],
                                                'texte' => ['type' => 'string', 'description' => 'Pour givenNames, surname, marriedName, nickname ; pour sex : masculin, feminin ou inconnu.'],
                                                'booleen' => ['type' => 'boolean', 'description' => 'Pour deceased.'],
                                                'date' => $date,
                                            ],
                                            'required' => ['champ'],
                                        ],
                                    ],
                                    'personnes' => [
                                        'type' => 'array',
                                        'description' => 'creation_personne : les personnes nouvelles, chacune avec une référence « #p1 », « #p2 »…',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'ref' => ['type' => 'string', 'description' => '#p1, #p2… unique dans la demande.'],
                                                'prenoms' => ['type' => 'string'],
                                                'nom' => ['type' => 'string'],
                                                'nomDUsage' => ['type' => 'string'],
                                                'surnom' => ['type' => 'string'],
                                                'sexe' => ['type' => 'string', 'enum' => ['masculin', 'feminin', 'inconnu']],
                                                'decedee' => ['type' => 'boolean'],
                                                'naissance' => $date,
                                                'deces' => $date,
                                                'note' => ['type' => 'string'],
                                            ],
                                            'required' => ['ref'],
                                        ],
                                    ],
                                    'liens' => [
                                        'type' => 'array',
                                        'description' => 'creation_personne (au moins un) et ajout_lien. Se lit du sujet vers l’autre : « sujet enfant_de autre ».',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'nature' => ['type' => 'string', 'enum' => ['enfant_de', 'parent_de', 'conjoint_de']],
                                                'sujet' => ['type' => 'string', 'description' => '« #p1 » pour une personne nouvelle de la demande, ou l’identifiant numérique d’une personne de l’arbre (« 12 »).'],
                                                'autre' => ['type' => 'string', 'description' => 'Même forme que sujet.'],
                                                'autre2' => ['type' => 'string', 'description' => 'enfant_de seulement : le second parent, même forme.'],
                                                'mariage' => $date,
                                                'divorce' => ['type' => 'boolean', 'description' => 'conjoint_de seulement.'],
                                                'note' => ['type' => 'string'],
                                            ],
                                            'required' => ['nature', 'sujet', 'autre'],
                                        ],
                                    ],
                                ],
                                'required' => ['nature', 'titre', 'justification', 'confiance'],
                            ],
                        ],
                        'portraits' => [
                            'type' => 'array',
                            'description' => 'Facultatif : les visages nets et identifiables repérés dans les PHOTOS jointes (jamais dans un PDF), à utiliser comme portrait de la personne. Au plus un portrait par personne. Liste vide si aucun.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'document' => ['type' => 'integer', 'description' => 'Le numéro du document (1 pour « Document 1 »). Une image, jamais un PDF.'],
                                    'personne' => ['type' => 'string', 'description' => 'L’identifiant numérique d’une personne de l’arbre (« 12 »), ou la référence « #p1 » d’une personne nouvelle d’une demande creation_personne de cet appel.'],
                                    'demande' => ['type' => 'integer', 'description' => 'Pour une personne nouvelle seulement : le rang (1 pour la première) dans « demandes » de la demande creation_personne qui la décrit.'],
                                    'cadre' => [
                                        'type' => 'object',
                                        'description' => 'La boîte du visage, comme tes boîtes de détection [ymin, xmin, ymax, xmax] normalisées sur 0–1000 dans le repère de l’image (0,0 en haut à gauche). Englobe toute la tête avec une marge : cheveux, menton et haut des épaules compris.',
                                        'properties' => [
                                            'haut' => ['type' => 'integer', 'description' => 'ymin, de 0 à 1000.'],
                                            'gauche' => ['type' => 'integer', 'description' => 'xmin, de 0 à 1000.'],
                                            'bas' => ['type' => 'integer', 'description' => 'ymax, de 0 à 1000, plus grand que haut.'],
                                            'droite' => ['type' => 'integer', 'description' => 'xmax, de 0 à 1000, plus grand que gauche.'],
                                        ],
                                        'required' => ['haut', 'gauche', 'bas', 'droite'],
                                    ],
                                    'justification' => ['type' => 'string', 'description' => 'Pourquoi ce visage est cette personne, d’après le document ou le texte du membre (« photo de mariage, document 2 : la mariée, Marie Dupont »).'],
                                ],
                                'required' => ['document', 'personne', 'cadre', 'justification'],
                            ],
                        ],
                    ],
                    'required' => ['resume', 'demandes'],
                ],
            ],
        ];
    }

    /**
     * Exécute un outil de lecture. Un appel mal formé rend une `erreur` **au modèle**,
     * pas une exception : il peut se corriger au tour suivant.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function executer(string $nom, array $arguments): array
    {
        return match ($nom) {
            self::CHERCHER => $this->chercher($arguments),
            self::LIRE => $this->lire($arguments),
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

        $personnes = $this->arbre->chercher(mb_substr($requete, 0, 200), self::RESULTATS_MAXIMAUX);

        return [
            'personnes' => array_map(fn (PersonneDeLArbre $p): array => $this->personne($p, true), $personnes),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function lire(array $arguments): array
    {
        $id = $arguments['personId'] ?? null;

        // Gemini rend les entiers JSON tantôt `12`, tantôt `12.0` : les deux désignent
        // la même fiche, une chaîne numérique aussi.
        if (\is_float($id) || (\is_string($id) && ctype_digit($id))) {
            $id = (int) $id;
        }

        if (!\is_int($id)) {
            return ['erreur' => 'Le paramètre « personId » est un entier.'];
        }

        $fiche = $this->arbre->fiche($id);

        if (null === $fiche) {
            return ['erreur' => \sprintf('Aucune personne n° %d dans l’arbre.', $id)];
        }

        $vue = $this->personne($fiche->personne, false);
        $vue['version'] = $fiche->version;
        $vue['champs'] = [
            'givenNames' => $fiche->prenoms,
            'surname' => $fiche->nom,
            'marriedName' => $fiche->nomDUsage,
            'nickname' => $fiche->surnom,
            'sex' => $fiche->personne->sexe->value,
            'deceased' => $fiche->decedee,
            'birth' => self::dateDetaillee($fiche->personne->naissance),
            'death' => self::dateDetaillee($fiche->personne->deces),
        ];
        $vue['parents'] = array_map(fn (PersonneDeLArbre $p): array => $this->personne($p, false), $fiche->parents);
        $vue['unions'] = array_map(fn (UnionDeLArbre $u): array => [
            'familyId' => $u->familleId,
            'conjoint' => null === $u->conjoint ? null : $this->personne($u->conjoint, false),
            'mariage' => self::dateLisible($u->mariage),
            'divorce' => $u->divorce,
            'enfants' => array_map(fn (PersonneDeLArbre $p): array => $this->personne($p, false), $u->enfants),
        ], $fiche->unions);
        $vue['fratrie'] = array_map(fn (PersonneDeLArbre $p): array => $this->personne($p, false), $fiche->fratrie);

        return $vue;
    }

    /**
     * @return array<string, mixed>
     */
    private function personne(PersonneDeLArbre $personne, bool $avecParente): array
    {
        $vue = [
            'personId' => $personne->id,
            'nom' => $personne->nomAffiche,
            'sexe' => $personne->sexe->value,
            'vivant' => $personne->vivante,
            'naissance' => self::dateLisible($personne->naissance),
            'deces' => self::dateLisible($personne->deces),
            // Le drapeau qui gouverne la consigne : « nomme-la, ne cite rien d'autre ».
            'confidentielle' => !$this->vue->voitEnEntier($personne),
        ];

        if ($avecParente) {
            $vue['parents'] = $personne->parents;
            $vue['conjoints'] = $personne->conjoints;
        }

        return $vue;
    }

    private static function dateLisible(UncertainDate $date): ?string
    {
        $texte = $date->format();
        $lieu = trim($date->getPlace() ?? '');

        return match (true) {
            '' !== $texte && '' !== $lieu => $texte.', '.$lieu,
            '' !== $texte => $texte,
            '' !== $lieu => $lieu,
            default => null,
        };
    }

    /**
     * La date avec sa précision réelle : le modèle doit voir qu'une naissance est
     * « entre 1901 et 1909 » avant de la « corriger » en 1905.
     *
     * @return array<string, string|null>|null
     */
    private static function dateDetaillee(UncertainDate $date): ?array
    {
        if (!$date->isKnown() && null === $date->getPlace()) {
            return null;
        }

        return [
            'texte' => self::dateLisible($date),
            'qualificatif' => $date->getQualifier()->value,
            'min' => $date->getMin()?->format('Y-m-d'),
            'max' => $date->getMax()?->format('Y-m-d'),
            'lieu' => $date->getPlace(),
        ];
    }
}
