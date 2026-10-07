<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Tree\ValueObject\JeuDePlacement;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Le jeu complet de l'arbre, relu en base **une fois par état de l'arbre** — pour le
 * chemin de lecture du graphe seulement ({@see ArbreVuDepuis}).
 *
 * ## Pourquoi il existe
 *
 * Mesuré en production le 07/10/2026 : 600 à 680 ms à 3 904 personnes, à chaque
 * affichage du graphe — l'hydratation Doctrine de tout `tree_person`, `tree_family` et
 * `tree_child`, pour un résultat identique d'un F5 à l'autre.
 *
 * ## La clé
 *
 * La règle du placement ({@see PlacementPartage}) : l'empreinte de l'arbre, plus ce qui
 * change le jeu **sans toucher l'arbre** — le seuil « vivant », l'année (on devient
 * défunt par l'âge au 1er janvier, {@see ReglePersonneVivante}) et la racine. Aucun
 * déclencheur à tenir : un arbre modifié change l'empreinte, l'entrée périmée n'est plus
 * atteinte et le TTL la ramasse.
 *
 * ## Pourquoi une enveloppe, et pas un cache dans {@see JeuDePlacementDoctrine}
 *
 * {@see RecalculDesColonnesDerivees} lit le même jeu **au milieu d'une écriture**, dans
 * une transaction, avec des entités modifiées et pas encore envoyées : Doctrine les lui
 * rend telles qu'en mémoire, l'empreinte (lue en SQL) les ignore. Un cache à cet endroit
 * lui servirait l'arbre d'avant l'écriture. Celui-ci n'est branché que sur la lecture, et
 * s'efface de lui-même dès qu'une transaction est ouverte.
 */
final readonly class JeuDePlacementEnCache implements FournisseurDeJeuDePlacement
{
    /** À changer avec la forme de {@see JeuDePlacement} : le pool survit aux déploiements. */
    private const string PREFIXE_CLE = 'jeu.v1.';

    public function __construct(
        private FournisseurDeJeuDePlacement $base,
        private TreeFingerprint $empreinte,
        private ReglagesDeLArbre $reglages,
        private CacheInterface $cacheArbre,
        private Connection $connexion,
        private ChronometreDuGraphe $chrono = new ChronometreDuGraphe(),
    ) {
    }

    public function jeuComplet(): JeuDePlacement
    {
        if ($this->connexion->isTransactionActive()) {
            $this->chrono->note('jeu', 'cache=transaction');

            return $this->base->jeuComplet();
        }

        $calcule = false;
        $jeu = $this->cacheArbre->get($this->cle(), function (ItemInterface $entree) use (&$calcule): JeuDePlacement {
            $entree->expiresAfter(PlacementPartage::TTL);
            $calcule = true;

            return $this->base->jeuComplet();
        });
        $this->chrono->note('jeu', $calcule ? 'cache=miss' : 'cache=hit', remplace: $calcule);

        \assert($jeu instanceof JeuDePlacement);

        return $jeu;
    }

    public function cle(): string
    {
        return \sprintf(
            '%s%s.s%d.a%s.r%s',
            self::PREFIXE_CLE,
            $this->empreinte->empreinte(),
            $this->reglages->seuilVivantAnnees(),
            (new \DateTimeImmutable())->format('Y'),
            $this->reglages->personneRacineId() ?? '-',
        );
    }
}
