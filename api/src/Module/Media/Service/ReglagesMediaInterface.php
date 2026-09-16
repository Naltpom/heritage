<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

/**
 * Les deux réglages d'administration du module (§ 2.2 du modèle de données, § 10.2 du
 * contrat) : `media.taille_max_octets` et `media.mimes_autorises`.
 *
 * Ils sont dans `core_setting` et non dans un paramètre de conteneur parce que
 * l'administrateur doit pouvoir les changer **sans redéploiement**. Une interface plutôt
 * qu'un appel direct, parce que la moitié des tests de ce module n'a aucune raison de
 * monter une base pour savoir qu'un JPEG est accepté.
 */
interface ReglagesMediaInterface
{
    /** 20 Mio par défaut. Le même plafond est posé dans nginx et dans PHP — les trois doivent concorder. */
    public function tailleMaxOctets(): int;

    /**
     * La liste blanche, appliquée **après** reniflage des octets d'en-tête.
     *
     * @return list<string>
     */
    public function mimesAutorises(): array;
}
