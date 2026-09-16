<?php

declare(strict_types=1);

namespace App\Tests\Proposal\Double;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
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

    public function appliquerChamps(SubjectKind $kind, int $id, array $valeurs): EcritureAppliquee
    {
        ++self::$ecritures;

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
            'divorced' => false,
            'divorce' => UncertainDate::inconnue(),
        ]);

        self::$couples[$cle] = $id;
        self::$famillesCreees[] = $id;

        return new LigneCreee($id, $libelle);
    }

    public function porterUnion(int $familleId, UncertainDate $mariage, bool $divorce, UncertainDate $rupture): void
    {
        ++self::$ecritures;

        $cle = EtatCible::cleDe(SubjectKind::Famille, $familleId);
        self::$lignes[$cle]['valeurs']['marriage'] = $mariage;
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

    /** @return list<int> les enfants rattachés à cette famille, dans l'ordre d'ajout */
    public function enfantsDe(int $familleId): array
    {
        return array_keys(self::$filiations[$familleId] ?? []);
    }

    public function empreinteDeLArbre(): string
    {
        // Une empreinte **dérivée de la donnée** : elle change d'elle-même à chaque
        // écriture, exactement comme celle du § 2.3 du modèle, et il n'y a rien à
        // incrémenter.
        return hash('sha256', serialize([self::$lignes, self::$filiations]));
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
