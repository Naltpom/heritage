<?php

declare(strict_types=1);

namespace App\Module\Timeline\Service;

use App\Module\Timeline\Entity\Preference;
use App\Module\Timeline\Repository\PreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lecture et écriture de `tml_preference` — les routes `GET` et `PUT` du § 9.2.
 *
 * ## La séparation lecture / écriture est le cœur de ce fichier
 *
 * {@see self::pour()} ne touche **jamais** la base en écriture, pas même pour créer la
 * ligne manquante d'un compte qui ouvre la vue pour la première fois. Le § 9.1 est
 * explicite : *« un paramètre fourni n'écrit pas la préférence — c'est `PUT` qui
 * écrit »*, et le motif donné vaut aussi pour un `GET` nu : *« sans cette séparation,
 * chaque exploration d'un réglage devient une écriture, et la préférence ne veut plus
 * rien dire. »*
 *
 * Il y a une seconde raison, plus froide : un `GET` qui écrit n'est plus idempotent. Le
 * front recharge la chronologie à chaque changement de bande ; une ligne créée à chaque
 * lecture, c'est un `INSERT` par mouvement de curseur, et une table qui grossit à la
 * navigation.
 *
 * ## La préférence appartient au compte, pas au membre emprunté
 *
 * Sous la bascule « voir comme un membre » (§ 10.4), l'administrateur voit **les données**
 * d'un autre — c'est tout l'objet de la bascule. Sa largeur de bande et son orientation,
 * elles, restent les siennes : ce sont des réglages de la personne assise devant l'écran,
 * pas des données de l'arbre. D'où `getUserId()` réel et non `identiteEffective()`.
 *
 * L'inverse serait par ailleurs une écriture chez quelqu'un d'autre, et le § 10.4 range
 * toute écriture sous bascule parmi les `409` — on ne va pas en ouvrir une par la porte
 * des préférences.
 */
final class ServiceDesPreferences
{
    public function __construct(
        private readonly PreferenceRepository $depot,
        private readonly EntityManagerInterface $em,
        private readonly ReglagesDeLaChronologie $reglages,
    ) {
    }

    /** La préférence enregistrée du compte, ou le défaut du projet. **Sans écrire.** */
    public function pour(int $userId): PreferenceDeLecture
    {
        $ligne = $this->depot->findOneBy(['userId' => $userId]);

        return null === $ligne
            ? PreferenceDeLecture::defaut($this->reglages->bandeParDefaut())
            : PreferenceDeLecture::depuisLEntite($ligne);
    }

    /**
     * `PUT` — **remplacement complet**, jamais une fusion.
     *
     * Le § 9.2 refuse un `PATCH` et exige les quatre champs, « aucune ambiguïté sur ce que
     * “absent” veut dire ». La validation a eu lieu en amont
     * ({@see NormaliseurDeRequete::preferenceSoumise()}) : on n'arrive ici qu'avec des
     * valeurs déjà typées et déjà bornées. `ck_tml_preference_bande` reste le filet, pas
     * le contrôle — une violation de contrainte remonterait en `500`, c'est-à-dire en
     * panne, là où c'est une saisie invalide.
     */
    public function remplacer(int $userId, PreferenceDeLecture $soumise): PreferenceDeLecture
    {
        $ligne = $this->depot->findOneBy(['userId' => $userId]) ?? new Preference($userId);

        $ligne
            ->setBandYears($soumise->bandYears)
            ->setOrientation($soumise->orientation)
            ->setBranches($soumise->branches)
            ->setShowUncertainty($soumise->showUncertainty)
            ->toucher();

        $this->em->persist($ligne);
        $this->em->flush();

        return PreferenceDeLecture::depuisLEntite($ligne);
    }
}
