<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Core\Enum\NiveauDePartage;
use App\Module\Core\Enum\Regime;
use App\Module\Core\Repository\UserRepository;
use App\Module\Core\Security\ReciprociteInterface;
use App\Module\Core\ValueObject\ComposantesDeDate;
use App\Module\Core\ValueObject\PartageDeNaissance;
use App\Module\Membership\Contract\PortraitsDesComptes;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Repository\MemberRepository;
use App\Module\Membership\Service\Port\PortArbre;
use App\Module\Membership\Service\Port\VueDeLArbre;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * **L'unique implémentation** de {@see ReciprociteInterface} — la réciprocité des vivants
 * (ADR 0016) : ce qu'un lecteur voit de la naissance et du portrait d'un vivant.
 *
 * ## Qui est le lecteur
 *
 * Celui de {@see SourceDuLecteur}, comme pour le cercle : sous la bascule « voir comme », le
 * membre **emprunté** — ses consentements, son option, sa photo. Sous une incarnation
 * (ADR 0010), le compte incarné est déjà celui de la session. L'écran montre donc ce que
 * ce membre voit vraiment.
 *
 * ## Ce que partage une personne
 *
 * Un niveau par composante — `tous`, `proches`, `personne` (révision du 2026-10-07). Celui
 * du compte rattaché à la fiche, **quel que soit son statut** — un compte en attente a déjà
 * dit ce qu'il voulait montrer. À défaut de compte, l'autorisation signée active. À défaut,
 * le défaut : `proches`. Le compte l'emporte toujours : c'est ce que garantit l'ordre de
 * construction de {@see self::partages()}, et non l'archivage de l'autorisation, qui n'en
 * est que le ménage.
 *
 * ## Les proches du lecteur
 *
 * Calculés une fois par requête par {@see CalculDesProches}, depuis la fiche du lecteur.
 * La relation est symétrique : la règle « chacun la montre à l'autre » se lit donc sur un
 * seul booléen ({@see PartageDeNaissance::vuPar()}).
 *
 * ## Le coût
 *
 * Deux requêtes par requête HTTP au plus — les consentements tiennent en quelques dizaines
 * de lignes —, plus une pour la photo du lecteur. Tout est mémorisé ; le graphe pose ses
 * 274 questions à une table en mémoire.
 */
final class Reciprocite implements ReciprociteInterface, ResetInterface
{
    private ?LecteurDuCercle $lecteur = null;

    private ?VueDeLArbre $vue = null;

    /** @var array<int, PartageDeNaissance>|null person_id ⇒ ce que la personne partage */
    private ?array $partages = null;

    /** @var array<int, true>|null les proches du lecteur */
    private ?array $proches = null;

    /** @var array{membre: ?Member, chargé: bool} */
    private array $membre = ['membre' => null, 'chargé' => false];

    private ?bool $photo = null;

    private ?bool $ignoree = null;

    public function __construct(
        private readonly SourceDuLecteur $lecteurs,
        private readonly PortArbre $arbre,
        private readonly MemberRepository $membres,
        private readonly UserRepository $utilisateurs,
        private readonly PortraitsDesComptes $portraits,
        private readonly Connection $connexion,
        private readonly CalculDesProches $calculDesProches = new CalculDesProches(),
    ) {
    }

    /**
     * Oublie tout ce qui a été mémorisé : entre deux requêtes d'un même processus, et après
     * un changement de consentement dans la requête courante.
     */
    public function reset(): void
    {
        $this->lecteur = null;
        $this->vue = null;
        $this->partages = null;
        $this->proches = null;
        $this->membre = ['membre' => null, 'chargé' => false];
        $this->photo = null;
        $this->ignoree = null;
    }

    public function naissance(int $personId): ComposantesDeDate
    {
        if (!$this->vue()->estVivante($personId) || $this->estLeLecteur($personId)) {
            return ComposantesDeDate::tout();
        }

        if (Regime::Aucun === $this->lecteur()->regime) {
            return ComposantesDeDate::aucune();
        }

        if ($this->restrictionIgnoree()) {
            return ComposantesDeDate::tout();
        }

        // Sans fiche, le lecteur n'a rien à montrer : il ne voit rien.
        $lecteur = $this->partageDuLecteur() ?? PartageDeNaissance::uniforme(NiveauDePartage::Personne);
        $partage = $this->partages()[$personId] ?? PartageDeNaissance::parDefaut();

        return $partage->vuPar($lecteur, isset($this->proches()[$personId]));
    }

    public function naissances(array $personIds): array
    {
        $resultat = [];

        foreach ($personIds as $id) {
            $resultat[$id] ??= $this->naissance($id);
        }

        return $resultat;
    }

    public function portraitNet(int $personId): bool
    {
        // L'option d'administration ne joue pas ici : décision du commanditaire, elle ne
        // couvre que les dates.
        return !$this->vue()->estVivante($personId)
            || $this->estLeLecteur($personId)
            || $this->lecteurAUnePhoto();
    }

    public function partageDuLecteur(): ?PartageDeNaissance
    {
        return $this->membreDuLecteur()?->getPartageDeNaissance();
    }

    public function restrictionIgnoree(): bool
    {
        if (null !== $this->ignoree) {
            return $this->ignoree;
        }

        $userId = $this->userIdEffectif();

        return $this->ignoree = $userId > 0
            && true === $this->utilisateurs->find($userId)?->ignoreLaRestrictionDeNaissance();
    }

    public function lecteurAUnePhoto(): bool
    {
        if (null !== $this->photo) {
            return $this->photo;
        }

        $userId = $this->userIdEffectif();

        return $this->photo = $userId > 0
            && $this->portraits->aUnePhoto($userId, $this->membreDuLecteur()?->getPersonId());
    }

    public function empreinte(): string
    {
        $partages = [];
        foreach ($this->partages() as $id => $partage) {
            $partages[] = $id.':'.$partage->code();
        }
        sort($partages);
        $proches = array_keys($this->proches());
        sort($proches);

        return hash('sha256', implode('|', [
            $this->lecteur()->regime->value,
            (string) ($this->membreDuLecteur()?->getPersonId() ?? '-'),
            $this->partageDuLecteur()?->code() ?? '-',
            $this->restrictionIgnoree() ? 'i' : '-',
            $this->lecteurAUnePhoto() ? 'p' : '-',
            implode(',', $partages),
            implode(',', $proches),
        ]));
    }

    /**
     * Ce que chaque personne partage : les autorisations actives d'abord, puis les comptes
     * par-dessus — **le compte l'emporte**, même s'il ne partage avec personne.
     *
     * @return array<int, PartageDeNaissance>
     */
    private function partages(): array
    {
        if (null !== $this->partages) {
            return $this->partages;
        }

        $partages = [];

        $autorisations = $this->connexion->fetchAllAssociative(
            'SELECT person_id, share_year, share_month, share_day FROM mbr_autorisation_naissance WHERE archived_at IS NULL',
        );
        foreach ($autorisations as $ligne) {
            $partages[(int) $ligne['person_id']] = self::lireLaLigne($ligne['share_year'], $ligne['share_month'], $ligne['share_day']);
        }

        $comptes = $this->connexion->fetchAllAssociative(
            'SELECT person_id, birth_share_year, birth_share_month, birth_share_day FROM mbr_member WHERE person_id IS NOT NULL',
        );
        foreach ($comptes as $ligne) {
            $partages[(int) $ligne['person_id']] = self::lireLaLigne($ligne['birth_share_year'], $ligne['birth_share_month'], $ligne['birth_share_day']);
        }

        return $this->partages = $partages;
    }

    /**
     * Une valeur inconnue (ce que `ck_mbr_*_niveaux` interdit) vaut `personne` : le défaut
     * est fermé.
     */
    private static function lireLaLigne(mixed $annee, mixed $mois, mixed $jour): PartageDeNaissance
    {
        $niveau = static fn (mixed $valeur): NiveauDePartage => NiveauDePartage::tryFrom((string) $valeur) ?? NiveauDePartage::Personne;

        return new PartageDeNaissance($niveau($annee), $niveau($mois), $niveau($jour));
    }

    /** @return array<int, true> les proches du lecteur — aucun sans fiche */
    private function proches(): array
    {
        if (null !== $this->proches) {
            return $this->proches;
        }

        $personId = $this->membreDuLecteur()?->getPersonId();

        return $this->proches = null === $personId ? [] : $this->calculDesProches->proches($this->vue(), $personId);
    }

    /** Sa propre fiche : on voit toujours tout de soi. */
    private function estLeLecteur(int $personId): bool
    {
        return $personId === $this->membreDuLecteur()?->getPersonId();
    }

    /**
     * Le `mbr_member` du lecteur effectif — l'emprunté sous la bascule. Il peut exister sans
     * que le lecteur ait d'ancre : un administrateur rattaché lit par un cercle complet.
     */
    private function membreDuLecteur(): ?Member
    {
        if ($this->membre['chargé']) {
            return $this->membre['membre'];
        }

        $lecteur = $this->lecteur();
        $membre = null !== $lecteur->memberId
            ? $this->membres->find($lecteur->memberId)
            : ($lecteur->userId > 0 ? $this->membres->pourUtilisateur($lecteur->userId) : null);

        $this->membre = ['membre' => $membre, 'chargé' => true];

        return $membre;
    }

    /** Le compte dont on lit l'option et la photo : celui du membre emprunté sous la bascule. */
    private function userIdEffectif(): int
    {
        $lecteur = $this->lecteur();

        if ($lecteur->sousBascule) {
            return $this->membreDuLecteur()?->getUserId() ?? 0;
        }

        return $lecteur->userId;
    }

    private function lecteur(): LecteurDuCercle
    {
        return $this->lecteur ??= $this->lecteurs->courant();
    }

    private function vue(): VueDeLArbre
    {
        return $this->vue ??= $this->arbre->vue();
    }
}
