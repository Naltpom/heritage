<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

use App\Module\Core\ValueObject\ComposantesDeDate;

/**
 * **La réciprocité des vivants** — ADR 0016, réponse au RGPD : ce qu'un lecteur voit de la
 * **date de naissance** et du **portrait** d'une personne vivante qu'il a déjà le droit de
 * voir.
 *
 * C'est un **second filtre**, posé après {@see CercleDeVisibiliteInterface} et jamais à sa
 * place : le cercle décide si la fiche est servie, la réciprocité ne fait que **réduire**
 * deux de ses données. Elle ne touche ni aux défunts, ni au lieu de naissance, ni au nom.
 *
 * ## Les règles
 *
 * **Date de naissance** — un lecteur V voit la composante c (année, mois, jour) d'un vivant T
 * si et seulement si V partage c **et** T partage c. Ce que T « partage », c'est le
 * consentement de son compte s'il en a un ; sinon l'autorisation signée qu'un
 * administrateur a déposée ; sinon rien. Trois exceptions donnent tout : T est décédé,
 * T est la fiche de V, V porte l'option d'administration « ignorer la restriction ».
 *
 * **Portrait** — un vivant est servi net si V a lui-même une photo (validée ou en attente),
 * flou sinon. L'option d'administration **ne s'applique pas** aux photos.
 *
 * ## Comme le cercle : lecteur implicite, défaut fermé, filtrage serveur
 *
 * Le lecteur est résolu par l'implémentation, bascule « voir comme » comprise — pour la
 * raison que {@see CercleDeVisibiliteInterface} donne. Sans adaptateur branché,
 * {@see ReciprociteFermee} ne laisse sortir aucune composante ni aucun portrait net.
 * Et une composante retenue **n'est pas envoyée** : ni dans `raw`, ni dans les bornes, ni
 * dans un âge, ni dans une clé de tri.
 */
interface ReciprociteInterface
{
    /** Les composantes de naissance de cette personne que le lecteur courant peut voir. */
    public function naissance(int $personId): ComposantesDeDate;

    /**
     * La même question pour une liste — le graphe et la recherche en posent des centaines.
     *
     * @param list<int> $personIds
     *
     * @return array<int, ComposantesDeDate>
     */
    public function naissances(array $personIds): array;

    /** Le portrait de cette personne peut-il être servi net au lecteur courant ? */
    public function portraitNet(int $personId): bool;

    /**
     * Ce que le lecteur courant partage lui-même ; `null` s'il n'a pas de rattachement
     * (compte d'amorçage, lecteur sans session).
     */
    public function partageDuLecteur(): ?ComposantesDeDate;

    /** Le lecteur porte-t-il l'option « ignorer la restriction de date de naissance » ? */
    public function restrictionIgnoree(): bool;

    /** Le lecteur a-t-il une photo de lui (validée ou en attente) ? */
    public function lecteurAUnePhoto(): bool;

    /**
     * Une empreinte de tout ce qui fait varier les réponses ci-dessus pour ce lecteur :
     * ses propres choix, l'option d'administration, sa photo, et les consentements de
     * toutes les personnes. Elle entre dans les ETags et les clés de cache des vues qui
     * portent des naissances ou des portraits.
     */
    public function empreinte(): string;
}
