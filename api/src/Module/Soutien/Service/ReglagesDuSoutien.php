<?php

declare(strict_types=1);

namespace App\Module\Soutien\Service;

use App\Module\Core\Service\SettingService;

/**
 * Les deux réglages du soutien, lus dans `core_setting` — modifiables depuis
 * l'administration, sans redéploiement.
 *
 * - `soutien.liberapay` : le pseudo du compte Liberapay qui reçoit les dons. **Vide, la page
 *   « Soutenir le site » dit qu'elle n'est pas encore ouverte**, et rien ne sort vers
 *   Liberapay. Un pseudo malformé est lu comme vide : il finit dans une URL.
 * - `soutien.objectif_mensuel` : ce que coûte le serveur par mois, en euros entiers — la
 *   jauge se remplit vers lui. `0` retire la jauge.
 */
final class ReglagesDuSoutien
{
    public const string CLE_PSEUDO = 'soutien.liberapay';

    public const string CLE_OBJECTIF = 'soutien.objectif_mensuel';

    /** Le prix du serveur au moment où la page a été écrite. */
    public const int OBJECTIF_PAR_DEFAUT = 45;

    /**
     * Ce que Liberapay accepte dans un pseudo : lettres, chiffres, `_`, `.` et `-`, trente-deux
     * au plus. Le pseudo est interpolé dans un chemin d'URL ; tout le reste est refusé ici,
     * avant qu'il n'y arrive.
     */
    private const string FORME_DU_PSEUDO = '/^[A-Za-z0-9_.-]{1,32}$/';

    public function __construct(
        private readonly SettingService $reglages,
    ) {
    }

    /** Le pseudo Liberapay, ou `null` si le soutien n'est pas configuré. */
    public function pseudo(): ?string
    {
        $pseudo = trim($this->reglages->texte(self::CLE_PSEUDO, ''));

        // `.` et `..` passent la forme et désigneraient un autre chemin que le profil.
        if (1 !== preg_match(self::FORME_DU_PSEUDO, $pseudo) || '' === trim($pseudo, '.')) {
            return null;
        }

        return $pseudo;
    }

    /** L'objectif mensuel, en euros. */
    public function objectifMensuel(): int
    {
        return max(0, $this->reglages->entier(self::CLE_OBJECTIF, self::OBJECTIF_PAR_DEFAUT));
    }
}
