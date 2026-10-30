<?php

declare(strict_types=1);

namespace App\Tests\Proposal\Double;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\FinDeRecherche;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Contract\CitationAcreer;
use App\Module\Proposal\Contract\FiliationEtablie;
use App\Module\Proposal\Contract\FiliationPrevue;
use App\Module\Proposal\Contract\InventaireDeSuppression;
use App\Module\Proposal\Contract\LienARetirer;
use App\Module\Proposal\Contract\LienRetire;
use App\Module\Proposal\Contract\MotifDeRattachement;
use App\Module\Proposal\Contract\NoteAcreer;
use App\Module\Proposal\Contract\RetraitDeLien;
use App\Module\Proposal\Contract\SignatureDeDemande;
use App\Module\Proposal\Contract\SourceCitable;
use App\Module\Proposal\Contract\VocabulaireDAnnotation;
use App\Module\Proposal\Enum\LinkKind;
use App\Module\Proposal\Service\Port\EcritureAppliquee;
use App\Module\Proposal\Service\Port\EtatCible;
use App\Module\Proposal\Service\Port\LigneCreee;
use App\Module\Proposal\Service\Port\PersonneAcreer;
use App\Module\Proposal\Service\Port\PortArbre;
use Doctrine\ORM\OptimisticLockException;

/**
 * Un `Tree` en mémoire, pour éprouver `Proposal` **sans** `Tree`.
 *
 * Ce n'est pas un pis-aller en attendant le vrai module : c'est le bénéfice direct du
 * port. `Proposal` n'écrit jamais dans `tree_*` — il appelle une interface —, donc un
 * test peut lui donner un arbre dont il contrôle exactement les versions, les valeurs
 * et le moment où le verrou optimiste se déclenche. Éprouver la distinction entre
 * `demande_caduque` et `verrou_concurrent` contre une vraie base demanderait deux
 * connexions concurrentes et un ordonnancement ; ici, c'est une ligne.
 *
 * Le compteur {@see $ecritures} sert le test le plus important du lot après le calque :
 * **une demande déposée ne touche pas la table cible**.
 */
final class ArbreEnMemoire implements PortArbre
{
    /** @var array<string, array{libelle: string, version: int, valeurs: array<string, mixed>}> */
    private static array $lignes = [];

    /**
     * Les signatures reçues par `appliquerChamps()`, dans l'ordre — de quoi affirmer que le
     * validateur passe le déposant et la date du dépôt (ADR 0023).
     *
     * @var list<SignatureDeDemande>
     */
    private static array $signatures = [];

    /** @var array<int, array{pere: bool, mere: bool}> les côtés où le test a posé un parent lié */
    private static array $cotes = [];

    /** Nombre d'appels à `appliquerChamps` — doit rester à zéro tant que rien n'est validé. */
    private static int $ecritures = 0;

    /** Quand il est vrai, la prochaine écriture lève comme le ferait `#[ORM\Version]`. */
    private static bool $verrouConcurrent = false;

    /** @var list<int> les personnes insérées par une validation */
    private static array $personnesCreees = [];

    /** @var list<int> les familles insérées par une validation — jamais celles qui existaient */
    private static array $famillesCreees = [];

    /** @var array<string, int> couple normalisé → identifiant de famille, le `uniq_tree_family_couple` du double */
    private static array $couples = [];

    /** @var array<int, array<int, int|null>> famille → enfant → rang dans la fratrie */
    private static array $filiations = [];

    /** @var array<int, string> les sources que le test a posées : identifiant => titre */
    private static array $sources = [];

    /**
     * Les citations créées par une validation, dans l'ordre.
     *
     * C'est le pendant de {@see $personnesCreees} pour `tree_citation`, et c'est ce
     * qui permet à un test de `Proposal` d'affirmer **ce qui a été écrit** — la
     * personne visée, la source, la page, le `QUAY` — sans monter de schéma.
     *
     * @var list<array{id: int, personneId: int, sourceId: int, page: string|null, quay: int|null, dataText: string|null, scope: string, url: string|null}>
     */
    private static array $citationsCreees = [];

    /**
     * Les notes créées par une validation, dans l'ordre.
     *
     * `auteurUserId` y est enregistré exprès : c'est la seule façon, côté `Proposal`,
     * de vérifier que la note porte le **demandeur** et non l'administrateur qui valide.
     *
     * @var list<array{id: int, personneId: int, corps: string, scope: string, auteurUserId: int|null, auteurLabel: string|null}>
     */
    private static array $notesCreees = [];

    /** @var list<int> les personnes effacées par une validation — le pendant de {@see} */
    private static array $personnesSupprimees = [];

    private static int $prochainIdentifiant = 1000;

    /**
     * Le nombre d'appels au port — **le compteur du test « une demande déposée ne touche
     * pas la table cible »**.
     */
    public function ecritures(): int
    {
        return self::$ecritures;
    }

    /** @return list<int> les personnes insérées par une validation */
    public function personnesCreees(): array
    {
        return self::$personnesCreees;
    }

    /** @return list<int> les familles insérées — jamais celles qui existaient déjà */
    public function famillesCreees(): array
    {
        return self::$famillesCreees;
    }

    /**
     * Arme ou désarme la collision de transaction que `#[ORM\Version]` produirait.
     *
     * Accesseur et non propriété publique : l'état du double est statique — il tient le
     * rôle des tables, qui survivent à un redémarrage du noyau — et une propriété
     * publique statique lue à travers une instance est une confusion que PHPStan
     * signale à juste titre.
     */
    public function simulerVerrouConcurrent(bool $actif): void
    {
        self::$verrouConcurrent = $actif;
    }

    /**
     * L'état est **statique**, et ce n'est pas une facilité.
     *
     * Ce double tient le rôle des tables `tree_*` : elles survivent évidemment à une
     * requête HTTP. Or le harnais rebâtit le noyau pour simuler une seconde requête —
     * c'est la seule façon fidèle d'éprouver le conseil « réessayez » de
     * `verrou_concurrent`, Doctrine fermant l'`EntityManager` après un échec de
     * transaction. Un état d'instance disparaîtrait à ce redémarrage, et l'arbre se
     * viderait entre deux requêtes d'un même scénario — alors que l'arbre, lui, ne se
     * vide pas entre deux requêtes.
     *
     * Le prix est cette remise à zéro explicite entre deux tests, appelée par le socle.
     */
    public static function oublierTout(): void
    {
        self::$lignes = [];
        self::$couples = [];
        self::$filiations = [];
        self::$personnesCreees = [];
        self::$famillesCreees = [];
        self::$ecritures = 0;
        self::$verrouConcurrent = false;
        self::$signatures = [];
        self::$cotes = [];
        self::$sources = [];
        self::$citationsCreees = [];
        self::$notesCreees = [];
        self::$personnesSupprimees = [];
        self::$prochainIdentifiant = 1000;
    }

    /**
     * @param array<string, mixed> $valeurs
     */
    public function poser(SubjectKind $kind, int $id, string $libelle, int $version, array $valeurs): self
    {
        self::$lignes[EtatCible::cleDe($kind, $id)] = [
            'libelle' => $libelle,
            'version' => $version,
            'valeurs' => $valeurs,
        ];

        return $this;
    }

    /**
     * Simule une écriture venue d'ailleurs — une autre demande validée entre-temps, un
     * import, une correction en base. C'est ce qui crée les trois jours que le verrou
     * n° 1 ne voit pas passer.
     */
    public function modifierAilleurs(SubjectKind $kind, int $id, string $champ, mixed $valeur): self
    {
        $cle = EtatCible::cleDe($kind, $id);
        self::$lignes[$cle]['valeurs'][$champ] = $valeur;
        ++self::$lignes[$cle]['version'];

        return $this;
    }

    /** Fait disparaître une ligne : suppression ou fusion côté `Tree`. */
    public function supprimer(SubjectKind $kind, int $id): self
    {
        unset(self::$lignes[EtatCible::cleDe($kind, $id)]);

        return $this;
    }

    public function valeur(SubjectKind $kind, int $id, string $champ): mixed
    {
        return self::$lignes[EtatCible::cleDe($kind, $id)]['valeurs'][$champ] ?? null;
    }

    public function version(SubjectKind $kind, int $id): ?int
    {
        return self::$lignes[EtatCible::cleDe($kind, $id)]['version'] ?? null;
    }

    public function chargerCible(SubjectKind $kind, int $id): ?EtatCible
    {
        $ligne = self::$lignes[EtatCible::cleDe($kind, $id)] ?? null;

        if (null === $ligne) {
            return null;
        }

        return new EtatCible($kind, $id, $ligne['libelle'], $ligne['version'], $ligne['valeurs']);
    }

    public function appliquerChamps(SubjectKind $kind, int $id, array $valeurs, ?SignatureDeDemande $signature = null): EcritureAppliquee
    {
        ++self::$ecritures;

        if (null !== $signature) {
            self::$signatures[] = $signature;
        }

        if (self::$verrouConcurrent) {
            // Le port doit laisser remonter cette exception telle quelle : c'est elle,
            // et elle seule, qui devient `verrou_concurrent`.
            throw new OptimisticLockException('Une autre transaction a touché la ligne.', null);
        }

        $cle = EtatCible::cleDe($kind, $id);
        $avant = self::$lignes[$cle]['version'];

        foreach ($valeurs as $champ => $valeur) {
            self::$lignes[$cle]['valeurs'][$champ] = $valeur;
        }

        // La règle de `Tree\Family` (ADR 0020) : un mariage daté ou situé est un mariage.
        $mariage = self::$lignes[$cle]['valeurs']['marriage'] ?? null;
        if (SubjectKind::Famille === $kind && $mariage instanceof UncertainDate && $mariage->estRenseignee()) {
            self::$lignes[$cle]['valeurs']['married'] = true;
        }

        self::$lignes[$cle]['version'] = $avant + 1;

        return new EcritureAppliquee($avant, $avant + 1, self::$lignes[$cle]['libelle']);
    }

    public function creerPersonne(PersonneAcreer $fiche): LigneCreee
    {
        ++self::$ecritures;

        if (self::$verrouConcurrent) {
            throw new OptimisticLockException('Une autre transaction a touché la ligne.', null);
        }

        $id = $this->prochainId(SubjectKind::Personne);
        $libelle = trim($fiche->givenNames.' '.$fiche->surname);

        $this->poser(SubjectKind::Personne, $id, $libelle, 1, [
            'givenNames' => $fiche->givenNames,
            'surname' => $fiche->surname,
            'marriedName' => $fiche->marriedName,
            'nickname' => $fiche->nickname,
            'sex' => $fiche->sex,
            'deceased' => $fiche->deceased,
            'birth' => $fiche->birth,
            'death' => $fiche->death,
            'forcePrivacy' => false,
            'privacyNote' => null,
        ]);

        self::$personnesCreees[] = $id;

        return new LigneCreee($id, $libelle);
    }

    public function trouverOuCreerFamille(?int $personneA, ?int $personneB): LigneCreee
    {
        ++self::$ecritures;

        $membres = array_values(array_filter([$personneA, $personneB], static fn (?int $id): bool => null !== $id));
        sort($membres);
        $cle = implode(':', $membres);

        // Le double reproduit `uniq_tree_family_couple` : un couple déjà connu rend la
        // famille existante, et `creee` passe à `false`. C'est ce qui permet au test
        // « une validation crée une famille, et une seule » de dire quelque chose.
        if (isset(self::$couples[$cle])) {
            $id = self::$couples[$cle];

            return new LigneCreee($id, self::$lignes[EtatCible::cleDe(SubjectKind::Famille, $id)]['libelle'], false);
        }

        $id = $this->prochainId(SubjectKind::Famille);
        $libelle = implode(' × ', array_map(
            fn (int $personne): string => self::$lignes[EtatCible::cleDe(SubjectKind::Personne, $personne)]['libelle'] ?? 'p'.$personne,
            $membres,
        ));

        $this->poser(SubjectKind::Famille, $id, $libelle, 1, [
            'marriage' => UncertainDate::inconnue(),
            'married' => null,
            'divorced' => false,
            'divorce' => UncertainDate::inconnue(),
        ]);

        self::$couples[$cle] = $id;
        self::$famillesCreees[] = $id;

        return new LigneCreee($id, $libelle);
    }

    /**
     * Les époux d'une famille, lus à l'envers de la carte des couples.
     *
     * Le double n'a pas de colonnes `husband_id` / `wife_id` : il tient un index
     * `couple normalisé → identifiant`, qui suffit à répondre. Une famille posée par
     * {@see poser()} sans passer par {@see poserCouple()} rend donc un tableau vide —
     * c'est le cas d'un décor qui ne s'intéresse pas aux époux, pas un défaut.
     *
     * @return list<int>
     */
    public function epouxDeFamille(int $familleId): array
    {
        foreach (self::$couples as $cle => $id) {
            if ($id !== $familleId) {
                continue;
            }

            // `(string)` : la clé d'un couple à un seul époux (« 7 ») est devenue un entier
            // en entrant dans le tableau — PHP convertit les clés numériques.
            return array_values(array_map(
                static fn (string $membre): int => (int) $membre,
                array_filter(explode(':', (string) $cle), static fn (string $membre): bool => '' !== $membre),
            ));
        }

        return [];
    }

    /**
     * Pose une famille **avec ses époux**, comme l'import en pose.
     *
     * {@see poser()} ne prend pas d'époux : elle écrit une ligne et ses valeurs, et le
     * couple n'en est pas une. Cette méthode-ci nourrit en plus l'index des couples, ce
     * qui rend la famille reconnaissable par {@see epouxDeFamille()} et retrouvable par
     * {@see trouverOuCreerFamille()} — le décor d'un test qui désigne un couple existant.
     */
    /**
     * Dit de quels côtés une personne a un parent lié (ADR 0023). Le double ne range pas
     * `(husband, wife)` : le test pose la réponse que `cotesConnus()` rendra.
     */
    public function poserCotesConnus(int $personneId, bool $pere, bool $mere): self
    {
        self::$cotes[$personneId] = ['pere' => $pere, 'mere' => $mere];

        return $this;
    }

    public function cotesConnus(int $personneId): array
    {
        return self::$cotes[$personneId] ?? ['pere' => false, 'mere' => false];
    }

    /** @return list<SignatureDeDemande> */
    public static function signaturesRecues(): array
    {
        return self::$signatures;
    }

    public function poserCouple(int $familleId, string $libelle, int ...$epoux): self
    {
        $membres = array_values($epoux);
        sort($membres);

        self::$couples[implode(':', $membres)] = $familleId;

        return $this->poser(SubjectKind::Famille, $familleId, $libelle, 1, [
            'marriage' => UncertainDate::inconnue(),
            'married' => null,
            'divorced' => false,
            'divorce' => UncertainDate::inconnue(),
        ]);
    }

    public function porterUnion(int $familleId, UncertainDate $mariage, ?bool $marie, bool $divorce, UncertainDate $rupture): void
    {
        ++self::$ecritures;

        $cle = EtatCible::cleDe(SubjectKind::Famille, $familleId);
        self::$lignes[$cle]['valeurs']['marriage'] = $mariage;
        // La règle de `Tree\Family`, que le double reproduit pour que les tests de
        // `Proposal` lisent ce que l'arbre porterait : un mariage daté ou situé est un
        // mariage (ADR 0020).
        self::$lignes[$cle]['valeurs']['married'] = $mariage->estRenseignee() ? true : $marie;
        self::$lignes[$cle]['valeurs']['divorced'] = $divorce;
        self::$lignes[$cle]['valeurs']['divorce'] = $rupture;
    }

    public function rattacherEnfant(int $familleId, int $personneId, ?int $rangDansLaFratrie): void
    {
        ++self::$ecritures;

        // Rattacher deux fois le même enfant ne lève pas : la validation peut rejouer un
        // lien que l'arbre porte déjà, et le port l'exige explicitement.
        self::$filiations[$familleId][$personneId] = $rangDansLaFratrie;
    }

    /**
     * La mesure d'un retrait, **réduite à ce que le double sait** : la présence du lien.
     *
     * Le double ne connaît ni les dates d'une union, ni ses notes, ni le sexe des époux :
     * il n'annonce donc jamais une union supprimée, ni un enfant rattaché à l'autre parent.
     * Ce qu'un retrait fait réellement à l'arbre est éprouvé contre le vrai port
     * (`tests/Tree/LienRetireParUneDemandeTest`).
     */
    public function prevoirRetraitDeLien(LienARetirer $lien, bool $familleProtegee): ?RetraitDeLien
    {
        $famille = self::$lignes[EtatCible::cleDe(SubjectKind::Famille, $lien->familleId)] ?? null;
        $personne = self::$lignes[EtatCible::cleDe(SubjectKind::Personne, $lien->personneId)] ?? null;

        if (null === $famille || null === $personne) {
            return null;
        }

        $present = LinkKind::ConjointDe === $lien->nature
            ? \in_array($lien->personneId, $this->epouxDeFamille($lien->familleId), true)
            : \array_key_exists($lien->personneId, self::$filiations[$lien->familleId] ?? []);

        return new RetraitDeLien(
            $lien->nature->value,
            $lien->personneId,
            $personne['libelle'],
            $lien->familleId,
            $famille['libelle'],
            $lien->parentId,
            blocage: $present ? null : 'Ce lien n’existe pas.',
        );
    }

    public function retirerLien(LienARetirer $lien, bool $familleProtegee): LienRetire
    {
        ++self::$ecritures;

        $retrait = $this->prevoirRetraitDeLien($lien, $familleProtegee);

        if (null === $retrait || $retrait->estBloque()) {
            throw new \RuntimeException('Le lien ne peut pas être retiré.');
        }

        if (LinkKind::EnfantDe === $lien->nature) {
            unset(self::$filiations[$lien->familleId][$lien->personneId]);
        } else {
            foreach (self::$couples as $cle => $id) {
                if ($id === $lien->familleId) {
                    unset(self::$couples[$cle]);
                    $restants = array_values(array_filter(
                        explode(':', (string) $cle),
                        static fn (string $membre): bool => '' !== $membre && (int) $membre !== $lien->personneId,
                    ));
                    self::$couples[implode(':', $restants)] = $id;
                }
            }
        }

        $cle = EtatCible::cleDe(SubjectKind::Famille, $lien->familleId);
        $avant = self::$lignes[$cle]['version'];
        ++self::$lignes[$cle]['version'];

        return new LienRetire($retrait, $avant, self::$lignes[$cle]['version']);
    }

    /** @return list<int> les enfants rattachés à cette famille, dans l'ordre d'ajout */
    public function enfantsDe(int $familleId): array
    {
        return array_keys(self::$filiations[$familleId] ?? []);
    }

    /** Pose une source dans l'arbre du double, comme l'import en pose. */
    public function poserSource(int $id, string $titre): self
    {
        self::$sources[$id] = $titre;

        return $this;
    }

    /**
     * Les citations qu'une validation a créées, dans l'ordre.
     *
     * @return list<array{id: int, personneId: int, sourceId: int, page: string|null, quay: int|null, dataText: string|null, scope: string, url: string|null}>
     */
    public function citationsCreees(): array
    {
        return self::$citationsCreees;
    }

    /**
     * Les notes qu'une validation a créées, dans l'ordre.
     *
     * @return list<array{id: int, personneId: int, corps: string, scope: string, auteurUserId: int|null, auteurLabel: string|null}>
     */
    public function notesCreees(): array
    {
        return self::$notesCreees;
    }

    public function creerNote(NoteAcreer $note): LigneCreee
    {
        ++self::$ecritures;

        if (null === $this->chargerCible(SubjectKind::Personne, $note->personneId)) {
            throw new \RuntimeException(\sprintf('La personne %d a disparu pendant la validation.', $note->personneId));
        }

        $id = $this->prochainId(SubjectKind::Note);

        self::$notesCreees[] = [
            'id' => $id,
            'personneId' => $note->personneId,
            'corps' => $note->corps,
            'scope' => $note->scope,
            'auteurUserId' => $note->auteurUserId,
            'auteurLabel' => $note->auteurLabel,
        ];

        // Le libellé du double reprend la règle du port réel — la première ligne —,
        // parce qu'un test de `Proposal` affirme sur ce libellé et qu'un double plus
        // simple ferait passer au vert une troncature que la production ne fait pas.
        $premiere = trim((string) (preg_split('/\R/u', $note->corps, 2) ?: [''])[0]);

        return new LigneCreee($id, mb_strlen($premiere) > 120 ? mb_substr($premiere, 0, 119).'…' : $premiere);
    }

    public function chargerSource(int $id): ?SourceCitable
    {
        $titre = self::$sources[$id] ?? null;

        return null === $titre ? null : new SourceCitable($id, $titre);
    }

    /**
     * Le vocabulaire **recopié**, et c'est la seule copie assumée du double.
     *
     * `Tree` le construit depuis `NoteScope::cases()` et `CitationQuality::cases()` ; ce
     * double ne peut pas les nommer — il vit dans les tests de `Proposal`, où la règle 3
     * de l'ADR 0002 s'applique comme ailleurs. Les valeurs sont donc écrites ici, et
     * `tests/Tree` vérifie séparément que les deux listes coïncident : un double qui
     * accepterait une portée que l'arbre refuse ferait passer au vert un dépôt que la
     * production rejette.
     */
    public function vocabulaireDAnnotation(): VocabulaireDAnnotation
    {
        return new VocabulaireDAnnotation(
            ['generale', 'naissance', 'deces', 'mariage', 'divorce', 'source'],
            [0, 1, 2, 3],
        );
    }

    public function creerCitation(CitationAcreer $citation): LigneCreee
    {
        ++self::$ecritures;

        // Les deux mêmes refus que l'implémentation réelle : une ligne disparue entre le
        // dépôt et la validation lève, elle ne rend pas une citation muette. Un double
        // plus permissif que la production ferait passer au vert un chemin qui échoue.
        if (null === $this->chargerCible(SubjectKind::Personne, $citation->personneId)) {
            throw new \RuntimeException(\sprintf('La personne %d a disparu pendant la validation.', $citation->personneId));
        }

        if (!isset(self::$sources[$citation->sourceId])) {
            throw new \RuntimeException(\sprintf('La source %d a disparu pendant la validation.', $citation->sourceId));
        }

        $id = $this->prochainId(SubjectKind::Citation);

        $this->poser(SubjectKind::Citation, $id, self::$sources[$citation->sourceId], 1, [
            'page' => $citation->page,
            'quay' => $citation->quay,
            'dataText' => $citation->dataText,
            'url' => $citation->url,
        ]);

        self::$citationsCreees[] = [
            'id' => $id,
            'personneId' => $citation->personneId,
            'sourceId' => $citation->sourceId,
            'page' => $citation->page,
            'quay' => $citation->quay,
            'dataText' => $citation->dataText,
            'scope' => $citation->scope,
            'url' => $citation->url,
        ];

        return new LigneCreee($id, self::$sources[$citation->sourceId]);
    }

    /**
     * L'inventaire de ce qu'une suppression emporterait, **calculé** et non déclaré.
     *
     * Le double tient le rôle des tables : il recompte donc ses notes, ses citations, ses
     * couples et ses filiations à chaque appel, comme le port réel relit la base. Un
     * inventaire que le test poserait à la main ne prouverait qu'une chose — que le test
     * sait compter — et laisserait passer un applicateur qui effacerait autre chose que ce
     * qui a été annoncé.
     *
     * Deux écarts assumés avec la production, parce que le double n'a pas ces tables :
     * `faits` vaut toujours `0`, et `medias` aussi — ce dernier étant de toute façon
     * ajouté par `Proposal` lui-même, via `PortMedias`.
     */
    public function inventaireDeSuppression(int $personneId): ?InventaireDeSuppression
    {
        $ligne = self::$lignes[EtatCible::cleDe(SubjectKind::Personne, $personneId)] ?? null;

        if (null === $ligne) {
            return null;
        }

        $unions = [];
        $descendants = [];

        foreach ($this->unionsDe($personneId) as $familleId => $membres) {
            $enfants = array_keys(self::$filiations[$familleId] ?? []);

            $unions[] = [
                'familleId' => $familleId,
                'libelle' => self::$lignes[EtatCible::cleDe(SubjectKind::Famille, $familleId)]['libelle'] ?? 'Union',
                'enfants' => \count($enfants),
                // L'union part quand la personne en est le seul membre. Le double ne
                // reproduit pas la seconde raison du port réel — la collision avec
                // `uniq_tree_family_couple` —, et c'est dit ici pour que personne ne
                // conclue de sa verdeur que le cas est couvert.
                'supprimee' => [$personneId] === $membres,
            ];

            foreach ($enfants as $enfant) {
                $descendants[] = [
                    'personneId' => $enfant,
                    'displayName' => self::$lignes[EtatCible::cleDe(SubjectKind::Personne, $enfant)]['libelle'] ?? 'p'.$enfant,
                ];
            }
        }

        return new InventaireDeSuppression(
            $personneId,
            $ligne['libelle'],
            \count($this->notesDe($personneId)),
            \count($this->citationsDe($personneId)),
            0,
            $this->filiationsDe($personneId),
            $unions,
            $descendants,
            [] === $descendants ? 0 : $this->compterLaDescendance($personneId),
            finsDeRecherche: \count(array_filter(
                [$ligne['valeurs']['finDeRecherchePere'] ?? null, $ligne['valeurs']['finDeRechercheMere'] ?? null],
                static fn (mixed $fin): bool => $fin instanceof FinDeRecherche,
            )),
        );
    }

    /**
     * La descendance transitive, comptée comme le port réel la compte.
     *
     * Un double qui rendrait le seul nombre d'enfants directs ferait passer au vert un
     * message de blocage qui annonce « 1 fiche descend de celle-ci » là où la production en
     * annonce vingt-trois — c'est-à-dire le seul chiffre qui arrête la main, faux dans le
     * sens rassurant.
     */
    private function compterLaDescendance(int $personneId): int
    {
        $vus = [$personneId => true];
        $generation = [$personneId];
        $total = 0;

        while ([] !== $generation) {
            $suivante = [];

            foreach ($generation as $parent) {
                foreach (array_keys($this->unionsDe($parent)) as $familleId) {
                    foreach (array_keys(self::$filiations[$familleId] ?? []) as $enfant) {
                        if (isset($vus[$enfant])) {
                            continue;
                        }

                        $vus[$enfant] = true;
                        $suivante[] = $enfant;
                        ++$total;
                    }
                }
            }

            $generation = $suivante;
        }

        return $total;
    }

    /**
     * Efface la fiche et ce qu'elle porte, et rend l'inventaire de ce qui est parti.
     *
     * **Les deux mêmes refus que l'implémentation réelle**, et c'est le point : un double
     * plus permissif que la production ferait passer au vert un chemin qui échoue. Une
     * fiche disparue lève ; une fiche qui porte des descendants lève aussi, parce que
     * détacher une branche est exactement ce que tout ce circuit existe pour empêcher.
     */
    public function supprimerPersonne(int $personneId): InventaireDeSuppression
    {
        ++self::$ecritures;

        $inventaire = $this->inventaireDeSuppression($personneId);

        if (null === $inventaire) {
            throw new \RuntimeException(\sprintf('La personne %d a disparu pendant la validation : il n\'y a plus rien à supprimer.', $personneId));
        }

        if ([] !== $inventaire->descendants) {
            throw new \RuntimeException(\sprintf('La personne %d est le parent de %d fiche(s) : la supprimer les détacherait de l\'arbre.', $personneId, \count($inventaire->descendants)));
        }

        foreach ($this->unionsDe($personneId) as $familleId => $membres) {
            if ([$personneId] === $membres) {
                unset(self::$lignes[EtatCible::cleDe(SubjectKind::Famille, $familleId)], self::$filiations[$familleId]);
                self::$couples = array_filter(self::$couples, static fn (int $id): bool => $id !== $familleId);

                continue;
            }

            // L'union survit, amputée d'un époux : sa clé de couple est recomposée sur le
            // membre restant. Sans ce report, `trouverOuCreerFamille()` retrouverait
            // ensuite l'ancienne famille sous l'ancien couple, et le double affirmerait
            // l'existence d'une union entre une personne et une fiche effacée.
            $restants = array_values(array_filter($membres, static fn (int $id): bool => $id !== $personneId));
            unset(self::$couples[implode(':', $membres)]);
            self::$couples[implode(':', $restants)] = $familleId;
        }

        self::$notesCreees = array_values(array_filter(
            self::$notesCreees,
            static fn (array $note): bool => $note['personneId'] !== $personneId,
        ));

        self::$citationsCreees = array_values(array_filter(
            self::$citationsCreees,
            static fn (array $citation): bool => $citation['personneId'] !== $personneId,
        ));

        foreach (self::$filiations as $familleId => $enfants) {
            unset(self::$filiations[$familleId][$personneId]);
        }

        unset(self::$lignes[EtatCible::cleDe(SubjectKind::Personne, $personneId)]);

        self::$personnesSupprimees[] = $personneId;

        return $inventaire;
    }

    /** @return list<int> les personnes effacées par une validation, dans l'ordre */
    public function personnesSupprimees(): array
    {
        return self::$personnesSupprimees;
    }

    /**
     * Les unions dont la personne est un membre, indexées par identifiant de famille.
     *
     * @return array<int, list<int>> famille => membres du couple, triés
     */
    private function unionsDe(int $personneId): array
    {
        $unions = [];

        foreach (self::$couples as $cle => $familleId) {
            // `(string) $cle` et non `$cle` : la clé d'un couple à un seul membre est
            // « 17 », et PHP convertit toute clé de tableau numérique en entier. Sans ce
            // cast, une union de parent seul — le cas exact d'une fiche qu'on supprime —
            // fait lever `explode()`.
            $membres = array_map(intval(...), array_filter(explode(':', (string) $cle), static fn (string $part): bool => '' !== $part));

            if (\in_array($personneId, $membres, true)) {
                $unions[$familleId] = array_values($membres);
            }
        }

        return $unions;
    }

    /** @return list<array{id: int, personneId: int, corps: string, scope: string, auteurUserId: int|null, auteurLabel: string|null}> */
    private function notesDe(int $personneId): array
    {
        return array_values(array_filter(
            self::$notesCreees,
            static fn (array $note): bool => $note['personneId'] === $personneId,
        ));
    }

    /** @return list<array{id: int, personneId: int, sourceId: int, page: string|null, quay: int|null, dataText: string|null, scope: string, url: string|null}> */
    private function citationsDe(int $personneId): array
    {
        return array_values(array_filter(
            self::$citationsCreees,
            static fn (array $citation): bool => $citation['personneId'] === $personneId,
        ));
    }

    /** Les filiations où la personne est **l'enfant** — sa propre parenté. */
    private function filiationsDe(int $personneId): int
    {
        $compte = 0;

        foreach (self::$filiations as $enfants) {
            if (\array_key_exists($personneId, $enfants)) {
                ++$compte;
            }
        }

        return $compte;
    }

    /**
     * Le double ne connaît ni le sexe ni `tree_child` au-delà d'un index : il rend la
     * famille du couple désigné, comme le faisait le chemin d'avant. Les règles de
     * rattachement (complément, union unique, retrait des familles à un parent) sont
     * éprouvées contre le **vrai** port, dans `tests/Tree/FiliationSansDoublonTest` —
     * les réécrire ici donnerait un second moteur à maintenir, qui finirait par mentir
     * dans la direction rassurante.
     */
    public function prevoirFiliation(?int $enfantId, ?int $parentA, ?int $parentB, bool $unionUniqueAdmise): FiliationPrevue
    {
        $membres = array_values(array_filter([$parentA, $parentB], static fn (?int $id): bool => null !== $id));
        sort($membres);
        $id = self::$couples[implode(':', $membres)] ?? null;

        return new FiliationPrevue(
            2 === \count($membres) ? MotifDeRattachement::Couple : MotifDeRattachement::ParentSeul,
            $id,
            null === $id ? implode(' × ', $membres) : self::$lignes[EtatCible::cleDe(SubjectKind::Famille, $id)]['libelle'],
        );
    }

    public function etablirFiliation(int $enfantId, ?int $parentA, ?int $parentB, bool $unionUniqueAdmise, ?int $rangDansLaFratrie): FiliationEtablie
    {
        $famille = $this->trouverOuCreerFamille($parentA, $parentB);
        $this->rattacherEnfant($famille->id, $enfantId, $rangDansLaFratrie);

        return new FiliationEtablie(
            $famille->id,
            $famille->libelle,
            $famille->creee,
            null !== $parentA && null !== $parentB ? MotifDeRattachement::Couple : MotifDeRattachement::ParentSeul,
            self::$lignes[EtatCible::cleDe(SubjectKind::Personne, $enfantId)]['libelle'] ?? 'p'.$enfantId,
        );
    }

    public function supprimerFamilleVide(int $familleId): ?string
    {
        return null;
    }

    /**
     * Une empreinte **dérivée de la donnée**, et dont le périmètre est celui de l'ADR 0005.
     *
     * ── Ce double a menti, et voici sur quoi ────────────────────────────────────────
     *
     * Il hachait **toutes** ses lignes. Un test de `Proposal` a donc pu affirmer, et voir
     * passer au vert, qu'ajouter une citation changeait l'empreinte de l'arbre. C'est
     * faux en production : `TreeFingerprint` ne couvre que `tree_person`, `tree_family`
     * et `tree_child` — « ni `med_media`, ni `tree_citation`, ni `tree_source`, ni
     * `tree_fact`, ni `tree_note` : rien de tout cela ne déplace un nœud ni ne modifie
     * une parenté ». Élargir l'empreinte ferait recalculer le placement des 377 nœuds et
     * tous les cercles de visibilité pour un graphe rigoureusement identique.
     *
     * Le double se restreint donc aux mêmes portées structurantes. Un double plus large
     * que la production ne rend pas les tests plus sévères : il les rend faux dans la
     * direction rassurante.
     */
    public function empreinteDeLArbre(): string
    {
        $structurantes = array_filter(
            self::$lignes,
            static fn (string $cle): bool => str_starts_with($cle, SubjectKind::Personne->value.':')
                || str_starts_with($cle, SubjectKind::Famille->value.':'),
            \ARRAY_FILTER_USE_KEY,
        );

        return hash('sha256', serialize([$structurantes, self::$filiations]));
    }

    /** Rien à différer : le double ne tient aucune colonne dérivée. */
    public function enUnLot(\Closure $ecritures): mixed
    {
        return $ecritures();
    }

    /**
     * Un identifiant hors de portée des fiches posées à la main par les tests (17, 55,
     * 61…), pour qu'une ligne créée ne puisse jamais se confondre avec une ligne de
     * départ.
     */
    private function prochainId(SubjectKind $kind): int
    {
        do {
            $id = ++self::$prochainIdentifiant;
        } while (isset(self::$lignes[EtatCible::cleDe($kind, $id)]));

        return $id;
    }
}
