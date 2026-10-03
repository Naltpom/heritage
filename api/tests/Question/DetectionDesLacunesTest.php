<?php

declare(strict_types=1);

namespace App\Tests\Question;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Question\Contract\FicheEtudiee;
use App\Module\Question\Enum\NatureDeLacune;
use App\Module\Question\Service\DetecteurDeLacunes;
use App\Module\Question\Service\Lacune;
use PHPUnit\Framework\TestCase;

/**
 * Les règles de détection (ADR 0008, § 4) — **sans base, sans conteneur, sans réseau**.
 *
 * C'est la pièce du chantier dont la justesse se juge sur des exemples et non sur une
 * lecture : une règle de détection fausse ne plante pas, elle pose une question idiote à
 * quelqu'un qui referme la page. {@see DetecteurDeLacunes} est donc pur, et chaque cas
 * ci-dessous monte l'arbre minimal qui l'exerce.
 *
 * **C'est aussi la moitié du chantier qui doit tenir sans Gemini.** Tout ce qui est
 * vérifié ici fonctionne avec le tiers coupé, hors quota, ou jamais configuré.
 *
 * Aucune personne réelle : les noms viennent du vocabulaire inventé du dépôt (ADR 0006).
 */
final class DetectionDesLacunesTest extends TestCase
{
    private const int PLANCHER = 1936;

    private DetecteurDeLacunes $detecteur;

    protected function setUp(): void
    {
        $this->detecteur = new DetecteurDeLacunes();
    }

    /* ─────────────────── Ce que chaque nature attrape ─────────────────── */

    public function testUneFicheSansMereEtSansPereProduitLesDeuxQuestions(): void
    {
        $natures = $this->naturesPour($this->fiche(id: 1));

        self::assertContains(NatureDeLacune::MereInconnue, $natures);
        self::assertContains(NatureDeLacune::PereInconnu, $natures);
    }

    public function testUneFiliationConnueNeProduitAucuneQuestionDeParent(): void
    {
        $natures = $this->naturesPour($this->fiche(id: 1, pereId: 8, mereId: 9));

        self::assertNotContains(NatureDeLacune::MereInconnue, $natures);
        self::assertNotContains(NatureDeLacune::PereInconnu, $natures);
    }

    public function testUnPrenomOuUnNomVideProduitSaQuestion(): void
    {
        $natures = $this->naturesPour($this->fiche(id: 1, prenoms: '   ', nom: ''));

        self::assertContains(NatureDeLacune::PrenomInconnu, $natures);
        self::assertContains(NatureDeLacune::NomInconnu, $natures);
    }

    /**
     * **On ne demande jamais le LIEU d'un événement qu'on ne sait pas DATER.**.
     *
     * Les deux questions sur la même fiche en produiraient une seconde, absurde pour qui
     * la lit — et un membre qui lit une question absurde cesse de lire les suivantes.
     */
    public function testLeLieuNEstDemandeQueSurUnEvenementDate(): void
    {
        $sansDate = $this->naturesPour($this->fiche(id: 1, vivante: false, deces: $this->annee(1990)));

        self::assertContains(NatureDeLacune::DateDeNaissanceInconnue, $sansDate);
        self::assertNotContains(NatureDeLacune::LieuDeNaissanceInconnu, $sansDate);

        $dateeSansLieu = $this->naturesPour($this->fiche(id: 1, vivante: false, naissance: $this->annee(1950), deces: $this->annee(1990)));

        self::assertNotContains(NatureDeLacune::DateDeNaissanceInconnue, $dateeSansLieu);
        self::assertContains(NatureDeLacune::LieuDeNaissanceInconnu, $dateeSansLieu);

        $complete = $this->naturesPour($this->fiche(id: 1, vivante: false, naissance: $this->annee(1950, 'Saule'), deces: $this->annee(1990)));

        self::assertNotContains(NatureDeLacune::LieuDeNaissanceInconnu, $complete);
    }

    /**
     * **Une fourchette EST une date.** Redemander « entre 1901 et 1909 » ferait douter de
     * ce qui est écrit, et l'on récolterait une date ferme là où la source n'en donne pas.
     */
    public function testUneFourchetteCompteCommeUneDate(): void
    {
        $fourchette = new UncertainDate(
            'BET 1940 AND 1948',
            DateQualifier::Entre,
            new \DateTimeImmutable('1940-01-01'),
            new \DateTimeImmutable('1948-12-31'),
            'Saule',
        );

        self::assertNotContains(
            NatureDeLacune::DateDeNaissanceInconnue,
            $this->naturesPour($this->fiche(id: 1, vivante: false, naissance: $fourchette, deces: $this->annee(1990))),
        );
    }

    /**
     * Une chaîne GEDCOM qu'aucune forme connue n'a su analyser est **une provenance, pas
     * une date** : la question se pose, et c'est même le cas le plus utile du lot.
     */
    public function testUneChaineBruteSansBorneResteUneLacune(): void
    {
        $illisible = new UncertainDate('AVANT LA GUERRE', DateQualifier::Inconnue, null, null, null);

        self::assertContains(
            NatureDeLacune::DateDeNaissanceInconnue,
            $this->naturesPour($this->fiche(id: 1, vivante: false, naissance: $illisible, deces: $this->annee(1990))),
        );
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  ON NE DEMANDE JAMAIS À QUELQU'UN QUAND UNE PERSONNE VIVANTE EST MORTE.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * Le genre de maladresse qu'aucune excuse ne rattrape. `vivante` vient de
     * `ReglePersonneVivante`, jamais de `tree_person.deceased` — « un décès inconnu vaut
     * vivant » ferme ici aussi.
     */
    public function testAucuneQuestionDeDecesSurUnePersonneVivante(): void
    {
        $natures = $this->naturesPour($this->fiche(id: 1, vivante: true, naissance: $this->annee(1960, 'Saule')));

        self::assertNotContains(NatureDeLacune::DateDeDecesInconnue, $natures);
        self::assertNotContains(NatureDeLacune::LieuDeDecesInconnu, $natures);
    }

    /**
     * ADR 0016 : on ne demande **jamais** la naissance d'une personne vivante — ni sa date,
     * ni son lieu. La question dirait si la date est connue, à qui ne la partage pas, et la
     * réponse collecterait la donnée même que la réciprocité protège.
     */
    public function testAucuneQuestionDeNaissanceSurUnePersonneVivante(): void
    {
        foreach ([null, $this->annee(1960)] as $naissance) {
            $natures = $this->naturesPour($this->fiche(id: 1, vivante: true, naissance: $naissance));

            self::assertNotContains(NatureDeLacune::DateDeNaissanceInconnue, $natures);
            self::assertNotContains(NatureDeLacune::LieuDeNaissanceInconnu, $natures);
        }
    }

    public function testLeDecesDUnePersonneDecedeeEstDemande(): void
    {
        $natures = $this->naturesPour($this->fiche(id: 1, vivante: false, deces: $this->annee(1990)));

        self::assertContains(NatureDeLacune::LieuDeDecesInconnu, $natures);
        self::assertNotContains(NatureDeLacune::DateDeDecesInconnue, $natures);
    }

    /* ─────────────────── La photo, et le port non branché ─────────────────── */

    public function testUneFicheSansMediaProduitLaQuestionDePhoto(): void
    {
        self::assertContains(
            NatureDeLacune::AucunePhoto,
            $this->naturesPour($this->fiche(id: 1, vivante: true), medias: []),
        );
    }

    public function testUneFicheAvecUnMediaNeLaProduitPas(): void
    {
        self::assertNotContains(
            NatureDeLacune::AucunePhoto,
            $this->naturesPour($this->fiche(id: 1, vivante: true), medias: [1 => 1]),
        );
    }

    /**
     * **Le cas qui coûterait le plus cher.**.
     *
     * Le port `Media` non branché rend un tableau vide, qui se lit « zéro média pour tout
     * le monde ». Pris au mot, il ouvrirait la lacune sur **les 274 fiches** : la page
     * demanderait à toute la famille une photo de gens dont l'arbre a déjà le portrait.
     * Massive, fausse, et sur la page d'accueil de la contribution.
     */
    public function testLaNatureAucunePhotoSeTaitQuandLePortNEstPasBranche(): void
    {
        self::assertNotContains(
            NatureDeLacune::AucunePhoto,
            $this->naturesPour($this->fiche(id: 1, vivante: true), medias: [], photos: false),
        );
    }

    /* ─────────────────── La mémoire vivante ─────────────────── */

    /**
     * Sans cette règle, l'arbre rendrait des centaines de questions **justes et
     * inutiles** : « qui était la mère de cet homme né en 1743 ? ».
     */
    public function testAucuneQuestionSurQuelquUnDontPersonneNAPuEntendreParler(): void
    {
        $aieul = $this->fiche(id: 1, vivante: false, naissance: $this->annee(1743), deces: $this->annee(1801));

        self::assertSame([], $this->detecter([$aieul]));
    }

    public function testUnePersonneVivanteEstToujoursDansLaMemoire(): void
    {
        $vivante = $this->fiche(id: 1, vivante: true, naissance: $this->annee(1990, 'Saule'));

        self::assertNotSame([], $this->detecter([$vivante]));
    }

    public function testUneBorneDeDecesApresLePlancherSuffit(): void
    {
        $recent = $this->fiche(id: 1, vivante: false, naissance: $this->annee(1890), deces: $this->annee(1971));

        self::assertNotSame([], $this->detecter([$recent]));
    }

    /**
     * **La passe rapportée : « mon père m'a parlé de son père ».**.
     *
     * L'aïeul mort en 1900 n'est dans la mémoire de personne ; son fils, mort en 1980,
     * l'est. On peut donc demander au petit-fils ce qu'il sait du premier.
     */
    public function testUnParentDeQuelquUnDeLaMemoireYEntreAussi(): void
    {
        $aieul = $this->fiche(id: 1, vivante: false, naissance: $this->annee(1860), deces: $this->annee(1900), enfantIds: [2]);
        $fils = $this->fiche(id: 2, vivante: false, naissance: $this->annee(1895), deces: $this->annee(1980), pereId: 1);

        $sujets = $this->sujetsDetectes([$aieul, $fils]);

        self::assertContains(1, $sujets, 'Le père d’une personne de la mémoire vivante doit y entrer.');
        self::assertContains(2, $sujets);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  UNE SEULE PASSE. JAMAIS UN POINT FIXE.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * C'est la discipline des conjoints de l'ADR 0003, et elle se casse exactement de la
     * même façon : itérer jusqu'à stabilité remonterait l'arbre de proche en proche
     * jusqu'à la racine, et le filtre ne filtrerait plus rien. On relirait le code en
     * croyant y lire une règle, et il n'en resterait rien.
     *
     * ── L'ORDRE DES FICHES EST CHOISI, ET C'EST TOUT LE CAS ─────────────────────
     *
     * Une première version de ce cas rangeait les fiches de l'aïeul vers le
     * descendant. **Elle passait même avec le code saboté** : quand la boucle atteignait
     * l'aïeul, l'intermédiaire n'avait pas encore été ajouté, et l'ordre d'itération
     * masquait le point fixe. Le test avait l'air de garder la règle ; il ne gardait que
     * l'ordre — mesuré, en cassant le code exprès.
     *
     * Les fiches sont donc rangées **du descendant vers l'aïeul**, ce qui garantit que
     * l'intermédiaire est déjà dans l'ensemble étendu quand on examine les deux fiches
     * qui ne doivent pas y entrer. Et il y en a **deux**, une par chemin de propagation :
     *
     * ```
     *   3  père      mort en 1980   → mémoire DIRECTE
     *   2  grand-père mort en 1900   → entre par une passe (son enfant, 3, est direct)
     *   4  oncle      mort en 1890   → ne doit PAS entrer par le chemin PARENT   (son père est 2)
     *   1  arrière    mort en 1850   → ne doit PAS entrer par le chemin ENFANT   (son enfant est 2)
     * ```
     *
     * Sans le cas n° 4, sabotter le seul test de parenté laisserait le test vert.
     */
    public function testLaPasseSupplementaireNeSePropagePasDUnCranAuSuivant(): void
    {
        $pere = $this->fiche(id: 3, vivante: false, naissance: $this->annee(1900), deces: $this->annee(1980), pereId: 2);
        $grandPere = $this->fiche(id: 2, vivante: false, naissance: $this->annee(1850), deces: $this->annee(1900), pereId: 1, enfantIds: [3, 4]);
        $oncle = $this->fiche(id: 4, vivante: false, naissance: $this->annee(1860), deces: $this->annee(1890), pereId: 2);
        $arriere = $this->fiche(id: 1, vivante: false, naissance: $this->annee(1820), deces: $this->annee(1850), enfantIds: [2]);

        $sujets = $this->sujetsDetectes([$pere, $grandPere, $oncle, $arriere]);

        self::assertContains(3, $sujets, 'Le père est dans la mémoire directe.');
        self::assertContains(2, $sujets, 'Le grand-père y entre par une passe, son fils étant direct.');

        self::assertNotContains(
            4,
            $sujets,
            'L’oncle ne doit PAS entrer par son PÈRE : celui-ci n’est lui-même là que par report.',
        );
        self::assertNotContains(
            1,
            $sujets,
            'L’arrière-grand-père ne doit PAS entrer par son ENFANT : ce serait un point fixe, qui avalerait l’arbre.',
        );
    }

    /** Un conjoint compte comme un parent ou un enfant pour la passe rapportée. */
    public function testUnConjointDeLaMemoireYEntreAussi(): void
    {
        $epoux = $this->fiche(id: 1, vivante: false, naissance: $this->annee(1870), deces: $this->annee(1910), conjointIds: [2]);
        $epouse = $this->fiche(id: 2, vivante: false, naissance: $this->annee(1880), deces: $this->annee(1960), conjointIds: [1]);

        self::assertContains(1, $this->sujetsDetectes([$epoux, $epouse]));
    }

    /* ─────────────────── L'ordre ─────────────────── */

    /**
     * L'ordre des lacunes suit l'ordre des fiches reçues, qui vient du port trié par
     * identifiant. Un ordre instable ferait varier la page d'un chargement à l'autre
     * sans qu'aucune donnée n'ait changé — et l'on chercherait la cause dans la base.
     */
    public function testLOrdreDesLacunesSuitCeluiDesFiches(): void
    {
        $lacunes = $this->detecter([
            $this->fiche(id: 7, vivante: true),
            $this->fiche(id: 3, vivante: true),
        ]);

        $sujets = array_values(array_unique(array_map(static fn (Lacune $l): int => $l->sujetId, $lacunes)));

        self::assertSame([7, 3], $sujets);
    }

    /* ─────────────────── Montage ─────────────────── */

    /**
     * @param list<FicheEtudiee> $fiches
     * @param array<int, int>    $medias
     *
     * @return list<Lacune>
     */
    private function detecter(array $fiches, array $medias = [], bool $photos = false): array
    {
        return $this->detecteur->detecter($fiches, $medias, $photos, self::PLANCHER);
    }

    /**
     * @param array<int, int> $medias
     *
     * @return list<NatureDeLacune>
     */
    private function naturesPour(FicheEtudiee $fiche, array $medias = [], bool $photos = true): array
    {
        return array_values(array_map(
            static fn (Lacune $l): NatureDeLacune => $l->nature,
            $this->detecter([$fiche], $medias, $photos),
        ));
    }

    /**
     * @param list<FicheEtudiee> $fiches
     *
     * @return list<int>
     */
    private function sujetsDetectes(array $fiches): array
    {
        return array_values(array_unique(array_map(
            static fn (Lacune $l): int => $l->sujetId,
            $this->detecter($fiches),
        )));
    }

    /**
     * @param list<int> $conjointIds
     * @param list<int> $enfantIds
     */
    private function fiche(
        int $id,
        string $prenoms = 'Prune',
        string $nom = 'Bréval',
        bool $vivante = true,
        ?UncertainDate $naissance = null,
        ?UncertainDate $deces = null,
        ?int $pereId = null,
        ?int $mereId = null,
        array $conjointIds = [],
        array $enfantIds = [],
    ): FicheEtudiee {
        // Par défaut : **vivante, et sans rien d'autre de renseigné**. Deux propriétés
        // voulues. Vivante, elle est dans la mémoire vivante sans qu'un cas ait à poser
        // de dates — la règle de mémoire se teste à part, et ne doit pas masquer les
        // autres. Vide, toutes ses lacunes se déclarent : un cas ne renseigne donc que ce
        // qu'il veut faire TAIRE, ce qui rend visible à la lecture ce qu'il exerce.
        //
        // Le corollaire est que les cas de décès passent `vivante: false` explicitement,
        // et c'est juste : on ne demande jamais son décès à une personne vivante.
        return new FicheEtudiee(
            id: $id,
            nomAffiche: trim($prenoms.' '.$nom),
            prenoms: $prenoms,
            nom: $nom,
            sexe: 'feminin',
            vivante: $vivante,
            naissance: $naissance ?? new UncertainDate(null, DateQualifier::Inconnue, null, null, null),
            deces: $deces ?? new UncertainDate(null, DateQualifier::Inconnue, null, null, null),
            pereId: $pereId,
            mereId: $mereId,
            conjointIds: $conjointIds,
            enfantIds: $enfantIds,
        );
    }

    private function annee(int $annee, ?string $lieu = null): UncertainDate
    {
        return new UncertainDate(
            (string) $annee,
            DateQualifier::Exacte,
            new \DateTimeImmutable($annee.'-01-01'),
            new \DateTimeImmutable($annee.'-12-31'),
            $lieu,
        );
    }
}
