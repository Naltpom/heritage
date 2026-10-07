<?php

declare(strict_types=1);

namespace App\Module\Soutien\Service;

use App\Module\Soutien\Entity\Releve;
use App\Module\Soutien\Repository\ReleveRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le relevé du jour : une photographie par jour du profil Liberapay, prise **à la première
 * lecture de la page** dans la journée.
 *
 * ## Un relevé à la lecture, pas un cron
 *
 * Même choix que la page « Questions » : rien à ordonnancer, aucun conteneur de plus à
 * surveiller. Le prix : un jour sans visite n'a pas de relevé. Le bilan le sait — il
 * prolonge le dernier relevé connu jusqu'au suivant ({@see BilanDuSoutien}), ce qui est
 * exactement ce que fait un don récurrent tant que personne n'y touche. Qui veut un relevé
 * quotidien garanti lance `heritage:soutien:relever` depuis une boucle de l'hôte.
 *
 * ## Une panne ne se repique pas avant une heure
 *
 * Un Liberapay muet coûterait sinon quatre secondes à **chaque** affichage de la page. On
 * retient l'heure de l'échec dans le pool de `Tree` (comme le coupe-circuit des Questions),
 * et la page affiche le dernier relevé connu en attendant.
 */
final class ReleveDuJour
{
    public const int SECONDES_APRES_UN_ECHEC = 3600;

    private const string CLE_ECHEC = 'soutien.liberapay.echec';

    public function __construct(
        private readonly ReglagesDuSoutien $reglages,
        private readonly SourceLiberapay $source,
        private readonly ReleveRepository $releves,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $horloge,
        private readonly LoggerInterface $journal,
        #[Autowire(service: 'cache.arbre')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Le relevé d'aujourd'hui, pris s'il manque. `null` : soutien non configuré, ou
     * Liberapay indisponible — la page se rabat sur les relevés précédents.
     *
     * @param bool $forcer passer outre l'attente après un échec (la commande, pas la page)
     */
    public function assurer(bool $forcer = false): ?Releve
    {
        $pseudo = $this->reglages->pseudo();
        if (null === $pseudo) {
            return null;
        }

        $maintenant = $this->horloge->now();
        $existant = $this->releves->duJour($pseudo, $maintenant);
        if (null !== $existant) {
            return $existant;
        }

        if (!$forcer && $this->echecRecent()) {
            return null;
        }

        try {
            $profil = $this->source->profil($pseudo);
        } catch (LiberapayIndisponible $e) {
            $this->journal->warning('Soutien : relevé Liberapay impossible.', ['pseudo' => $pseudo, 'raison' => $e->getMessage()]);
            $this->retenirLEchec();

            if ($forcer) {
                throw $e;
            }

            return null;
        }

        $releve = new Releve($pseudo, $maintenant, $profil->recuParSemaineCentimes, $profil->devise, $profil->donateurs, $maintenant);
        // Deux membres qui ouvrent la page dans la même seconde, au premier passage du jour,
        // feraient tomber le second sur la contrainte d'unicité. Une famille ; on l'accepte.
        $this->em->persist($releve);
        $this->em->flush();

        return $releve;
    }

    private function echecRecent(): bool
    {
        try {
            return $this->cache->getItem(self::CLE_ECHEC)->isHit();
        } catch (\Throwable) {
            return false;
        }
    }

    private function retenirLEchec(): void
    {
        try {
            $item = $this->cache->getItem(self::CLE_ECHEC);
            $item->set(true);
            $item->expiresAfter(self::SECONDES_APRES_UN_ECHEC);
            $this->cache->save($item);
        } catch (\Throwable) {
            // Un cache illisible ne fait pas tomber la page : on repiquera, voilà tout.
        }
    }
}
