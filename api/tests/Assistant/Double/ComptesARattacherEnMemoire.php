<?php

declare(strict_types=1);

namespace App\Tests\Assistant\Double;

use App\Module\Core\Entity\User;
use App\Module\Core\Exception\ConflictException;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Membership\Contract\ComptesARattacher;
use App\Module\Membership\Contract\DeclarationDuCompte;
use App\Module\Membership\Service\ComptesARattacherParMembership;

/**
 * Les comptes « à rattacher », **en mémoire** : le côté `Membership` du rattachement
 * assisté, réduit à ce que le contrat promet.
 *
 * Il ne touche pas la base, et c'est ce qui rend lisible le test « rien n'est écrit » :
 * quand {@see echouerAuRattachement()} est armé, l'erreur tombe **après** que `Proposal` a
 * déposé et validé la fiche, dans la transaction englobante — si une ligne `prp_*` reste
 * en base, c'est l'assistant qui a mal tenu sa transaction, pas ce double.
 *
 * État statique, comme {@see ModeleScripte} : le client HTTP rebâtit le noyau à chaque
 * requête, et les comptes, eux, ne disparaissent pas entre deux requêtes.
 *
 * ── Deux modes, et le réel par défaut ────────────────────────────────────────────
 *
 * Ce double est l'alias de test de l'interface **pour tout le conteneur** : un alias qui
 * rendrait toujours la mémoire ferait que l'implémentation réelle de `Membership` ne serait
 * jamais éprouvée à travers les routes de l'assistant. D'où un commutateur :
 *
 * - {@see oublierTout()} passe en mémoire — les tests unitaires du rattachement assisté ;
 * - {@see brancherSurMembership()} délègue à {@see ComptesARattacherParMembership} — le
 *   test d'intégration, qui fait le parcours entier sur de vraies lignes `mbr_member`.
 *
 * Le mode par défaut est le réel : un test qui n'a rien demandé voit la production.
 */
final class ComptesARattacherEnMemoire implements ComptesARattacher
{
    private static bool $enMemoire = false;

    /** @var array<int, DeclarationDuCompte> */
    private static array $declarations = [];

    /** @var array<int, true> */
    private static array $reclamees = [];

    /** @var list<array{memberId: int, personId: int, personLabel: string, administrateur: string}> */
    private static array $rattachements = [];

    private static ?\Throwable $erreur = null;

    /** @var (\Closure(int): void)|null */
    private static ?\Closure $avantLeVerrou = null;

    public function __construct(private readonly ComptesARattacherParMembership $reel)
    {
    }

    /** Délègue désormais à l'implémentation réelle de `Membership`. */
    public static function brancherSurMembership(): void
    {
        self::oublierTout();
        self::$enMemoire = false;
    }

    public static function oublierTout(): void
    {
        self::$enMemoire = true;
        self::$declarations = [];
        self::$reclamees = [];
        self::$rattachements = [];
        self::$erreur = null;
        self::$avantLeVerrou = null;
    }

    public static function poser(DeclarationDuCompte $declaration): void
    {
        self::$declarations[$declaration->memberId] = $declaration;
    }

    public static function reclamer(int $personId): void
    {
        self::$reclamees[$personId] = true;
    }

    public static function echouerAuRattachement(\Throwable $erreur): void
    {
        self::$erreur = $erreur;
    }

    /**
     * Joue `$geste` juste **avant** la relecture sous verrou, dans les deux modes — de quoi
     * simuler une autre requête (un refus) qui passe entre la lecture de la déclaration et
     * la transaction de « Créer la fiche et rattacher ».
     *
     * @param \Closure(int): void $geste reçoit l'identifiant du membre
     */
    public static function avantLeVerrou(\Closure $geste): void
    {
        self::$avantLeVerrou = $geste;
    }

    /** @return list<array{memberId: int, personId: int, personLabel: string, administrateur: string}> */
    public static function rattachements(): array
    {
        return self::$rattachements;
    }

    /** @return array<string, mixed>|null le blob gardé, tel que l'assistant l'a écrit */
    public static function suggestionGardee(int $memberId): ?array
    {
        return self::$declarations[$memberId]->suggestion ?? null;
    }

    public function declaration(int $memberId): ?DeclarationDuCompte
    {
        if (!self::$enMemoire) {
            return $this->reel->declaration($memberId);
        }

        return self::$declarations[$memberId] ?? null;
    }

    public function enregistrerLaSuggestion(int $memberId, array $suggestion, \DateTimeImmutable $le): bool
    {
        if (!self::$enMemoire) {
            return $this->reel->enregistrerLaSuggestion($memberId, $suggestion, $le);
        }

        $d = self::$declarations[$memberId] ?? null;

        if (null === $d) {
            return false;
        }

        self::$declarations[$memberId] = new DeclarationDuCompte(
            $d->memberId,
            $d->userId,
            $d->email,
            $d->surname,
            $d->givenNames,
            $d->birthDate,
            $d->description,
            $d->invitationPersonId,
            // Aller-retour JSON : c'est ce que ferait la colonne `jsonb`, et un blob qui
            // ne survivrait pas à l'encodage doit casser ici, pas en production.
            json_decode(json_encode($suggestion, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            $le,
        );

        return true;
    }

    public function verrouillerPourLeRattachement(int $memberId): void
    {
        if (null !== self::$avantLeVerrou) {
            (self::$avantLeVerrou)($memberId);
        }

        if (!self::$enMemoire) {
            $this->reel->verrouillerPourLeRattachement($memberId);

            return;
        }

        if (!isset(self::$declarations[$memberId])) {
            throw new ConflictException('compte_deja_decide', 'Ce compte a déjà été activé ou refusé.');
        }
    }

    public function estDejaRattachee(int $personId): bool
    {
        if (!self::$enMemoire) {
            return $this->reel->estDejaRattachee($personId);
        }

        return isset(self::$reclamees[$personId]);
    }

    public function rattacherAFicheCreee(int $memberId, int $personId, string $personLabel, User $administrateur): array
    {
        if (!self::$enMemoire) {
            return $this->reel->rattacherAFicheCreee($memberId, $personId, $personLabel, $administrateur);
        }

        if (null !== self::$erreur) {
            throw self::$erreur;
        }

        if (!isset(self::$declarations[$memberId])) {
            throw new NotFoundException();
        }

        unset(self::$declarations[$memberId]);
        self::$reclamees[$personId] = true;
        self::$rattachements[] = ['memberId' => $memberId, 'personId' => $personId, 'personLabel' => $personLabel, 'administrateur' => $administrateur->getEmail()];

        return ['id' => $memberId, 'status' => 'actif', 'matchKind' => 'manuel_admin', 'invitationId' => null, 'linkedAt' => null, 'activatedAt' => null, 'personId' => $personId, 'personLabel' => $personLabel];
    }
}
