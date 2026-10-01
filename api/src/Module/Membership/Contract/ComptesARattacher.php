<?php

declare(strict_types=1);

namespace App\Module\Membership\Contract;

use App\Module\Core\Entity\User;

/**
 * Les comptes inscrits **sans fiche**, vus depuis un autre module — ADR 0013.
 *
 * Déclaré par `Membership`, qui l'implémente
 * (`Service\ComptesARattacherParMembership`) ; consommé par
 * `Assistant`, qui calcule la suggestion de rattachement et orchestre « créer la fiche et
 * rattacher ». La règle 4 de l'ADR 0002 interdit à `Assistant` de lire `mbr_member` : il
 * passe par ici, et par ici seulement.
 *
 * ## Ce que ce contrat ne fait pas
 *
 * - **Il ne lit pas la suggestion.** `Membership` la stocke et la purge ; son schéma
 *   appartient au module qui l'écrit. Le blob fait l'aller-retour tel quel.
 * - **Il ne filtre rien.** Les données rendues parlent de personnes vivantes : l'appelant
 *   est une route `ROLE_ADMIN`, refusée sous la bascule « voir comme un membre », et
 *   c'est lui qui en répond.
 */
interface ComptesARattacher
{
    /** Le compte, s'il est `a_rattacher` ET `en_attente` ; sinon `null` (inconnu, déjà rattaché, refusé). */
    public function declaration(int $memberId): ?DeclarationDuCompte;

    /**
     * Écrit le blob et `attachment_suggestion_at = $le`, puis flush. Sans effet si
     * `declaration()` rendrait null **au moment de l'écriture** — et non au moment où
     * l'appelant a lu la déclaration : l'analyse dure jusqu'à 90 s, et le compte a pu être
     * refusé ou rattaché entre-temps, par une autre requête. L'implémentation relit donc la
     * ligne sous verrou avant d'écrire.
     *
     * @param array<string, mixed> $suggestion
     *
     * @return bool `false` si rien n'a été écrit (compte refusé, rattaché ou disparu)
     */
    public function enregistrerLaSuggestion(int $memberId, array $suggestion, \DateTimeImmutable $le): bool;

    /**
     * Relit le compte **sous verrou**, dans la transaction de l'appelant, et exige qu'il soit
     * encore `a_rattacher` et `en_attente`. À appeler **avant** de créer quoi que ce soit :
     * le compte lu par {@see declaration()} a pu être refusé depuis, par un autre
     * administrateur ou un autre onglet, et l'objet en mémoire n'en sait rien. Le verrou
     * tient jusqu'à la fin de la transaction — un refus concurrent attend.
     * N'ouvre PAS de transaction : l'appelant le fait.
     *
     * @throws \App\Module\Core\Exception\NotFoundException compte inconnu
     * @throws \App\Module\Core\Exception\ConflictException `compte_deja_decide`
     */
    public function verrouillerPourLeRattachement(int $memberId): void;

    /** Une personne est-elle déjà réclamée par un compte (tous statuts) ? */
    public function estDejaRattachee(int $personId): bool;

    /**
     * Rattache à une fiche **qui vient d'être créée dans la même transaction** et active le compte.
     * NE relit PAS `PortArbre::vue()` : elle est mémoïsée par requête et ne verrait pas la fiche neuve.
     * Fait tout ce que fait l'activation : `core_user` et `mbr_member` actifs, matchKind `manuel_admin`,
     * purge, portrait en attente déplacé sur la fiche, audit, notification `compteActive`.
     * N'ouvre PAS de transaction : l'appelant le fait.
     *
     * @return array<string, mixed> la charge `MembreAdministration`
     *
     * @throws \App\Module\Core\Exception\NotFoundException compte ou utilisateur disparu
     * @throws \App\Module\Core\Exception\ConflictException `personne_deja_rattachee`, ou `compte_deja_decide` (plus `a_rattacher`/`en_attente`)
     */
    public function rattacherAFicheCreee(int $memberId, int $personId, string $personLabel, User $administrateur): array;
}
