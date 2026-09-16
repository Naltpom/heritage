<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

use App\Module\Core\Entity\Setting;
use App\Module\Core\Enum\SettingType;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lit les deux réglages du module dans `core_setting`, avec un repli en dur.
 *
 * **Couture avec `Core`.** L'ADR 0002 veut qu'un module en appelle un autre par son
 * *service* applicatif, jamais par son dépôt. Le service de réglages de `Core` n'existe
 * pas encore ; en attendant, cette classe lit l'entité `Core\Setting` — `Core` est le
 * noyau partagé, et sa liste blanche de `use` le prévoit. Quand `Core\Service\ReglageService`
 * (ou son nom définitif) sera posé, il n'y a qu'ici à changer, et la signature du port
 * ne bouge pas.
 *
 * **Le repli n'est pas une commodité de test, c'est une garantie de démarrage.** Les
 * valeurs par défaut sont celles que la migration initiale insère (§ 2.2 du modèle) : si
 * la ligne manque — base non migrée, réglage supprimé à la main, test sans schéma —, le
 * module applique la même politique qu'avec la ligne, au lieu de refuser tout ou, pire,
 * d'accepter tout. Une liste blanche qui se vide silencieusement accepte n'importe quel
 * type de fichier.
 */
final class ReglagesMedia implements ReglagesMediaInterface
{
    /** 20 Mio — « les actes numérisés de `recherche/` montent à plusieurs Mio » (§ 2.2 du modèle). */
    public const int TAILLE_MAX_PAR_DEFAUT = 20_971_520;

    /** @var list<string> */
    public const array MIMES_PAR_DEFAUT = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    /**
     * Mémoïsation des **valeurs résolues**, pour la durée de la requête.
     *
     * Ce sont les scalaires qui sont mémoïsés, pas l'entité : un téléversement contrôle
     * la taille puis le type, et les deux contrôles d'un même dépôt doivent appliquer la
     * même politique même si l'administrateur la change entre les deux. Garder l'entité
     * ne l'aurait pas garanti — un objet géré par Doctrine change de valeur sous la main
     * de celui qui le tient.
     */
    private ?int $tailleMax = null;

    /** @var list<string>|null */
    private ?array $mimes = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function tailleMaxOctets(): int
    {
        return $this->tailleMax ??= $this->resoudreLaTaille();
    }

    public function mimesAutorises(): array
    {
        return $this->mimes ??= $this->resoudreLesMimes();
    }

    private function resoudreLaTaille(): int
    {
        $reglage = $this->lire('media.taille_max_octets');

        if (!$reglage instanceof Setting || SettingType::Entier !== $reglage->getValueType()) {
            return self::TAILLE_MAX_PAR_DEFAUT;
        }

        $valeur = filter_var($reglage->getValue(), \FILTER_VALIDATE_INT);

        return \is_int($valeur) && $valeur > 0 ? $valeur : self::TAILLE_MAX_PAR_DEFAUT;
    }

    /** @return list<string> */
    private function resoudreLesMimes(): array
    {
        $reglage = $this->lire('media.mimes_autorises');

        if (!$reglage instanceof Setting || SettingType::Json !== $reglage->getValueType()) {
            return self::MIMES_PAR_DEFAUT;
        }

        try {
            $decode = json_decode($reglage->getValue(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::MIMES_PAR_DEFAUT;
        }

        if (!\is_array($decode)) {
            return self::MIMES_PAR_DEFAUT;
        }

        $mimes = array_values(array_filter($decode, \is_string(...)));

        // Une liste blanche vide n'est pas « tout est permis », mais elle n'est pas non
        // plus une configuration plausible : c'est le symptôme d'une valeur cassée.
        return [] === $mimes ? self::MIMES_PAR_DEFAUT : $mimes;
    }

    /**
     * Une requête DQL plutôt que `getRepository(Setting::class)`, pour deux raisons qui
     * vont dans le même sens : l'ADR 0002 veut qu'un module n'appelle pas le **dépôt**
     * d'un autre, et passer par le dépôt ferait dépendre cette lecture du registre
     * Doctrine du conteneur — donc du démarrage de l'application entière.
     */
    private function lire(string $cle): ?Setting
    {
        try {
            $trouve = $this->em
                ->createQuery('SELECT s FROM '.Setting::class.' s WHERE s.settingKey = :cle')
                ->setParameter('cle', $cle)
                ->setMaxResults(1)
                ->getOneOrNullResult();
        } catch (DbalException) {
            // Base absente ou table non migrée : on ne fait pas échouer un
            // téléversement sur la lecture d'un réglage, on applique le défaut.
            //
            // Volontairement **étroit** : attraper `\Throwable` ici masquerait une
            // faute de programmation — un mapping cassé, un service mal câblé — en la
            // déguisant en « réglage absent », et le module continuerait de tourner
            // avec des valeurs par défaut sans que personne le sache.
            return null;
        }

        return $trouve instanceof Setting ? $trouve : null;
    }
}
