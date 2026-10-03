<?php

declare(strict_types=1);

namespace App\Module\Membership\Contract;

/**
 * La photo déposée par un compte **qui n'a pas encore de fiche validée** — ADR 0013.
 *
 * Déclaré ici, parce que c'est `Membership` qui décide du moment où elle change de main :
 * au rattachement, elle devient le portrait de la fiche ; au refus, elle disparaît.
 * Implémenté par `Media`
 * (`Media\Service\PortraitsDesComptesEnAttente`), qui possède la table,
 * le fichier et la règle « un seul portrait actif ».
 *
 * Tant que le compte attend, la photo n'est visible que de son titulaire et des
 * administrateurs : c'est la règle ordinaire d'un média sans sujet
 * (`Media\Service\CatalogueMedia::lisible()`), pas une exception écrite pour ce cas.
 */
interface PortraitsDesComptes
{
    /** @return array{mediaId: int, url: string, vignetteUrl: string}|null le portrait en attente du compte */
    public function portraitEnAttente(int $userId): ?array;

    /**
     * Le compte a-t-il une photo de lui — portrait de sa fiche actif ou en attente, ou
     * portrait de compte en attente ? C'est la condition de la réciprocité des portraits
     * (ADR 0016) : sans photo de soi, les portraits des vivants sont servis floutés.
     */
    public function aUnePhoto(int $userId, ?int $personId): bool;

    /** Déplace le portrait en attente sur la fiche et le valide (il devient le portrait actif). Rend false s'il n'y en a pas. */
    public function rattacherALaFiche(int $userId, int $personId, string $personLabel): bool;

    /**
     * Supprime le portrait en attente (refus d'inscription) : la ligne **dans la transaction
     * de l'appelant**, les fichiers **plus tard** — la fermeture rendue les efface, et
     * l'appelant la joue une fois sa transaction validée, jamais avant. Un `unlink()` ne
     * s'annule pas ; un refus, si.
     *
     * @return \Closure(): void l'effacement des fichiers, à jouer après le commit (sans effet s'il n'y en a pas)
     */
    public function ecarter(int $userId): \Closure;
}
