<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Exception\ConflictException;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Security\ReciprociteInterface;
use App\Module\Core\Service\AuditService;
use App\Module\Core\ValueObject\ComposantesDeDate;
use App\Module\Membership\Entity\AutorisationDeNaissance;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Repository\AutorisationDeNaissanceRepository;
use App\Module\Membership\Repository\MemberRepository;
use App\Module\Membership\Service\Port\PortArbre;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * **Les écritures** de la réciprocité des vivants (ADR 0016) : le consentement d'un compte,
 * l'autorisation signée d'un vivant sans compte, et le ménage au rattachement.
 *
 * Chaque geste laisse une ligne au journal d'audit — `partage_naissance`,
 * `autorisation_naissance` — qui est **la preuve du consentement** : qui, quand, quoi,
 * d'où. Le journal automatique de Doctrine double ces lignes d'un diff mécanique ; celles-ci
 * disent ce que le diff ne dit pas, la source du choix.
 */
final readonly class ConsentementDeNaissance
{
    /** Le plafond d'une autorisation : un scan de quelques pages, une capture d'écran. */
    public const int TAILLE_MAX = 10 * 1024 * 1024;

    public function __construct(
        private EntityManagerInterface $em,
        private MemberRepository $membres,
        private AutorisationDeNaissanceRepository $autorisations,
        private StockageDesAutorisations $stockage,
        private PortArbre $arbre,
        private AuditService $audit,
        private ReciprociteInterface $reciprocite,
    ) {
    }

    /**
     * Lit `{annee, mois, jour}` dans un corps de requête. Une clé absente vaut `false` ; une
     * clé présente doit être un booléen.
     *
     * @param array<mixed> $corps
     * @param string       $prefixe le chemin de champ des erreurs (`partageNaissance.`)
     *
     * @return array{0: ComposantesDeDate, 1: array<string, string>} les composantes et les erreurs de champ
     */
    public static function lire(array $corps, string $prefixe = ''): array
    {
        $erreurs = [];
        $valeurs = [];

        foreach (['annee', 'mois', 'jour'] as $cle) {
            $valeur = $corps[$cle] ?? false;

            // Le multipart n'a pas de booléens : « true », « 1 », « on » y sont des oui.
            if (\is_string($valeur)) {
                $valeur = \in_array(strtolower($valeur), ['1', 'true', 'on', 'oui'], true)
                    ? true
                    : (\in_array(strtolower($valeur), ['0', 'false', 'off', 'non', ''], true) ? false : $valeur);
            }

            if (!\is_bool($valeur)) {
                $erreurs[$prefixe.$cle] = 'Doit être vrai ou faux.';
                continue;
            }

            $valeurs[$cle] = $valeur;
        }

        return [
            new ComposantesDeDate($valeurs['annee'] ?? false, $valeurs['mois'] ?? false, $valeurs['jour'] ?? false),
            $erreurs,
        ];
    }

    /**
     * Le choix du titulaire d'un compte. **Ne vide pas** l'unité de travail quand
     * `$flush` est faux : l'inscription l'appelle dans sa propre transaction.
     *
     * @param 'inscription'|'profil' $source
     */
    public function enregistrer(Member $membre, ComposantesDeDate $partage, string $source, bool $flush = true): void
    {
        $avant = $membre->getPartageDeNaissance();
        $membre->partagerLaNaissance($partage, $source);

        if ($flush) {
            $this->em->flush();
        }

        $this->audit->enregistrer(
            AuditAction::PartageNaissance,
            'membre',
            null !== $membre->getId() ? (string) $membre->getId() : null,
            $membre->getUserEmail(),
            ['avant' => $avant->enTableau(), 'apres' => $partage->enTableau(), 'source' => $source],
        );

        $this->oublier();
    }

    /**
     * Dépose l'autorisation signée d'un vivant **sans compte**. L'autorisation active
     * précédente, s'il y en a une, est archivée en `remplacee`.
     *
     * @throws NotFoundException   personne inconnue
     * @throws ConflictException   un compte est rattaché à la personne : seul son choix compte
     * @throws ValidationException personne décédée, document absent, trop gros ou d'un type refusé
     */
    public function deposerAutorisation(
        int $personId,
        string $cheminTemporaire,
        ComposantesDeDate $partage,
        ?string $note,
        User $administrateur,
    ): AutorisationDeNaissance {
        $this->verifierLaPersonne($personId);

        $taille = @filesize($cheminTemporaire);
        if (false === $taille || 0 === $taille) {
            throw new ValidationException(['document' => 'Le document est vide ou illisible.']);
        }
        if ($taille > self::TAILLE_MAX) {
            throw new ValidationException(['document' => 'Le document dépasse 10 Mo.']);
        }

        // Le type **réel**, lu dans les octets : l'extension et l'en-tête du client ne
        // prouvent rien.
        $mime = (new \finfo(\FILEINFO_MIME_TYPE))->file($cheminTemporaire);
        if (false === $mime || !isset(StockageDesAutorisations::EXTENSIONS[$mime])) {
            throw new ValidationException(['document' => 'Le document doit être un PDF ou une image (JPEG, PNG, WebP).']);
        }

        $empreinte = (string) hash_file('sha256', $cheminTemporaire);
        $nom = $this->stockage->deposer($cheminTemporaire, $mime);
        $adminId = (int) $administrateur->getId();
        $note = null !== $note && '' !== trim($note) ? mb_substr(trim($note), 0, 1000) : null;

        try {
            $autorisation = $this->em->wrapInTransaction(function () use ($personId, $partage, $nom, $mime, $taille, $empreinte, $note, $adminId): AutorisationDeNaissance {
                $precedente = $this->autorisations->activePour($personId);
                if (null !== $precedente) {
                    $precedente->archiver(AutorisationDeNaissance::ARCHIVE_REMPLACEE, $adminId);
                    // Vidé seul : `uniq_mbr_autorisation_active` refuserait la nouvelle tant
                    // que l'ancienne est active en base.
                    $this->em->flush();
                }

                $autorisation = new AutorisationDeNaissance($personId, $partage, $nom, $mime, $taille, $empreinte, $note, $adminId);
                $this->em->persist($autorisation);
                $this->em->flush();

                return $autorisation;
            });
        } catch (\Throwable $e) {
            $this->stockage->supprimer($nom);

            throw $e;
        }

        $this->audit->enregistrer(
            AuditAction::AutorisationNaissance,
            'personne',
            (string) $personId,
            null,
            ['geste' => 'deposee', 'partage' => $partage->enTableau(), 'sha256' => $empreinte],
        );

        $this->oublier();

        return $autorisation;
    }

    /**
     * Retire l'autorisation active (archivée en `retiree`, le document reste : c'est la
     * trace de ce qui a été consenti jusque-là).
     *
     * @throws NotFoundException aucune autorisation active
     */
    public function retirerAutorisation(int $personId, User $administrateur): void
    {
        $autorisation = $this->autorisations->activePour($personId)
            ?? throw new NotFoundException('Aucune autorisation active pour cette personne.');

        $autorisation->archiver(AutorisationDeNaissance::ARCHIVE_RETIREE, $administrateur->getId());
        $this->em->flush();

        $this->audit->enregistrer(
            AuditAction::AutorisationNaissance,
            'personne',
            (string) $personId,
            null,
            ['geste' => 'retiree', 'sha256' => $autorisation->getDocumentSha256()],
        );

        $this->oublier();
    }

    /**
     * Un compte vient d'être rattaché à la personne : son consentement seul compte
     * désormais, l'autorisation active est archivée en `compte_rattache`. **Ne vide pas**
     * l'unité de travail — l'appelant rattache dans sa propre transaction.
     */
    public function archiverPourRattachement(int $personId, ?int $parUserId = null): void
    {
        $this->autorisations->activePour($personId)
            ?->archiver(AutorisationDeNaissance::ARCHIVE_COMPTE_RATTACHE, $parUserId);
    }

    private function verifierLaPersonne(int $personId): void
    {
        $vue = $this->arbre->vue();

        if (null === $vue->personne($personId)) {
            throw new NotFoundException('Personne introuvable.');
        }

        if (!$vue->estVivante($personId)) {
            throw new ValidationException(['personId' => 'Une personne décédée n’a pas besoin d’autorisation : sa date est visible de tous.']);
        }

        if (null !== $this->membres->pourPersonne($personId)) {
            throw new ConflictException('compte_rattache', 'Un compte est rattaché à cette personne : seul son propre choix de partage compte.');
        }
    }

    /** Les réponses mémorisées de la réciprocité ne valent plus après une écriture. */
    private function oublier(): void
    {
        if ($this->reciprocite instanceof ResetInterface) {
            $this->reciprocite->reset();
        }
    }
}
