<?php

declare(strict_types=1);

namespace App\Module\Tree\Command;

use App\Module\Tree\Entity\Citation;
use App\Module\Tree\Entity\Source;
use App\Module\Tree\Enum\SourceKind;
use App\Module\Tree\Service\AdressesDArbresEnLigne;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `heritage:reclasser-les-arbres-tiers` — ranger les sites familiaux là où ils sont.
 *
 * ## Le décompte qui la motive
 *
 * Sur les 483 citations de l'arbre, **194** reposent sur une source déjà classée
 * `arbre_tiers` — Geneanet, Filae, MyHeritage. Mais **41 autres** reposent sur des sources
 * classées `autre` qui sont, elles aussi, des arbres en ligne : « CADIC-BROSSARD » en pèse
 * quarante à elle seule, « Site Web Famille Destombes » une.
 *
 * Tant qu'elles restent `autre`, elles s'affichent comme n'importe quelle source, et deux
 * fiches sur cinq de cet arbre paraissent mieux tenues qu'elles ne le sont.
 *
 * ## Pourquoi c'est une commande, et pas une liste dans le code
 *
 * Parce que le critère est **une preuve, pas un nom**. Reconnaître « CADIC-BROSSARD » par
 * son titre serait deviner ; le reconnaître parce que ses citations pointent vers
 * `sites-cf.mhcache.com` — le cache de sites de MyHeritage — est une mesure. C'est ce que
 * fait cette commande : elle lit les adresses portées par `page`, `data_text`, `note` et
 * `text`, et ne reclasse que ce dont elle peut montrer l'hébergeur.
 *
 * **Le domaine ne suffit pas, et c'est le piège de ce fonds.** MyHeritage publie sous le
 * même `myheritage.fr` les fiches d'arbres de ses utilisateurs **et** des relevés indexés :
 * le recensement français de 1931, celui de 1921, l'index des avis de décès. Ces
 * collections-là valent ce que vaut un index, pas ce que vaut un arbre, et les reclasser
 * en `arbre_tiers` écrirait une faussete que personne ne rattraperait. C'est le **chemin**
 * de l'adresse qui les sépare, et {@see AdressesDArbresEnLigne::designeUneFicheDArbre()}
 * qui le lit.
 *
 * Une liste de titres écrite en dur, elle, aurait vieilli au premier import suivant.
 *
 * ## Pourquoi une commande et pas une migration
 *
 * Deux raisons, dans l'ordre de leur poids :
 *
 * - **c'est un jugement, pas une reprise mécanique.** Reclasser une source change ce que
 *   l'arbre dit de lui-même : quarante fiches passent de « source non qualifiée » à
 *   « repose sur l'arbre d'un autre ». Cela se lit avant de se faire, d'où la **simulation
 *   par défaut** : contrairement à sa voisine, celle-ci n'écrit rien tant qu'on ne lui
 *   passe pas `--ecrire`. L'option nomme l'écriture et non son absence, pour qu'on ne
 *   puisse pas écrire en oubliant un drapeau ;
 * - **une migration s'exécute au déploiement**, sans que personne ne regarde. Le § 14.3 du
 *   modèle veut que les règles de fond soient tenues par du code exerçable, pas par du SQL
 *   joué une fois dans le noir.
 *
 * ## Ce qu'elle ne touche pas
 *
 * **Ni `tree_citation.quay`, ni aucune URL.** Le `QUAY` dit ce que *cette lecture-ci*
 * vaut et appartient à celui qui l'a posée ; l'adresse reste en base — « garde-les en DB
 * uniquement pour l'IA » — et c'est la sérialisation qui la retire du rendu. Cette
 * commande ne change **qu'une colonne**, `tree_source.source_kind`, et la sérialisation
 * fait le reste : {@see \App\Module\Tree\Serializer\AppuiDUneCitation} retient le plus bas
 * du fonds et de l'usage, si bien qu'une source reclassée fait basculer ses citations sans
 * qu'aucune autre ligne ne bouge.
 *
 * `tree_source` ne porte pas de `#[ORM\Version]` : reclasser ne périme aucune demande en
 * attente, contrairement à ce que fait sa voisine sur `tree_person`.
 *
 * ## Idempotente
 *
 * Rejouée, elle ne trouve plus rien : une source déjà `arbre_tiers` n'est pas recomptée.
 */
#[AsCommand(
    name: 'heritage:reclasser-les-arbres-tiers',
    description: 'Classe en « arbre_tiers » les sources dont les adresses pointent vers un arbre en ligne. Simulation par défaut.',
)]
final class ReclasserLesArbresTiersCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AdressesDArbresEnLigne $adresses,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'ecrire',
            null,
            InputOption::VALUE_NONE,
            'Applique réellement le reclassement. Sans cette option, la commande se contente de montrer ce qu’elle ferait.',
        );
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $style = new SymfonyStyle($entree, $sortie);
        $ecrire = true === $entree->getOption('ecrire');

        $candidates = $this->candidates();

        if ([] === $candidates) {
            $style->success('Aucune source à reclasser : aucune ne porte d’adresse désignant la fiche d’un arbre. '
                .'Les liens vers des relevés indexés — recensements, index des décès — ne comptent pas : ce sont des relevés, pas des arbres.');

            return Command::SUCCESS;
        }

        $lignes = [];
        $citationsTouchees = 0;

        foreach ($candidates as $candidate) {
            $source = $candidate['source'];
            $citationsTouchees += $candidate['citations'];

            $lignes[] = [
                (string) $source->getId(),
                $source->getTitle(),
                $source->getSourceKind()->value,
                $candidate['preuve'],
                (string) $candidate['citations'],
            ];
        }

        $style->table(['id', 'titre', 'classement actuel', 'adresse de fiche d’arbre qui le prouve', 'citations'], $lignes);

        if (!$ecrire) {
            $style->warning(\sprintf(
                '%d source(s) à reclasser, portant %d citation(s). Rien n’a été écrit : relancez avec --ecrire.',
                \count($candidates),
                $citationsTouchees,
            ));

            return Command::SUCCESS;
        }

        foreach ($candidates as $candidate) {
            $candidate['source']->setSourceKind(SourceKind::ArbreTiers)->toucher();
        }

        $this->em->flush();

        $style->success(\sprintf(
            '%d source(s) reclassée(s) en « arbre_tiers ». %d citation(s) s’affichent désormais comme tenues par un arbre en ligne.',
            \count($candidates),
            $citationsTouchees,
        ));

        return Command::SUCCESS;
    }

    /**
     * Les sources qui portent une adresse d'arbre en ligne sans être classées comme telles.
     *
     * @return list<array{source: Source, preuve: string, citations: int}>
     */
    private function candidates(): array
    {
        $candidates = [];

        /** @var list<Source> $sources */
        $sources = $this->em->getRepository(Source::class)->findAll();

        foreach ($sources as $source) {
            if (SourceKind::ArbreTiers === $source->getSourceKind()) {
                continue;
            }

            $preuve = $this->premiereAdresseDArbre($source);

            if (null === $preuve) {
                continue;
            }

            $candidates[] = [
                'source' => $source,
                'preuve' => $preuve,
                'citations' => $this->compterLesCitations($source),
            ];
        }

        return $candidates;
    }

    /**
     * La première adresse d'arbre en ligne trouvée sur la source ou sur ses citations.
     *
     * Elle est **rendue** et non seulement détectée : c'est elle que la commande affiche
     * avant de reclasser, et un reclassement qu'on ne peut pas justifier ligne à ligne
     * n'est pas un reclassement, c'est une retouche en masse.
     */
    private function premiereAdresseDArbre(Source $source): ?string
    {
        $textes = [$source->getNote(), $source->getText(), $source->getPublication(), $source->getTitle()];

        foreach ($this->citationsDe($source) as $citation) {
            $textes[] = $citation->getPage();
            $textes[] = $citation->getDataText();
            $textes[] = $citation->getUrl();
        }

        foreach ($textes as $texte) {
            if (null === $texte) {
                continue;
            }

            preg_match_all('~https?://[^\s<>"\']+~i', $texte, $trouvees);

            foreach ($trouvees[0] as $url) {
                // **`designeUneFicheDArbre()` et non `estUnArbreEnLigne()`**, et la
                // différence est tout sauf cosmétique. La seconde s'arrête au domaine ;
                // or MyHeritage héberge sous le même domaine 53 liens vers des **relevés
                // indexés** — recensements de 1921 et de 1931, index des décès — et 49
                // vers des fiches d'arbres.
                //
                // Reclasser un recensement en « arbre d'un autre utilisateur » écrirait
                // une faussete en base et ferait tomber d'un cran la confiance de
                // citations qui ne le méritent pas. On masque un lien sur ce qu'il coûte
                // à l'écran ; on classe une source sur ce qu'elle **est**.
                if ($this->adresses->designeUneFicheDArbre($url)) {
                    return $url;
                }
            }
        }

        return null;
    }

    /** @return list<Citation> */
    private function citationsDe(Source $source): array
    {
        /** @var list<Citation> $citations */
        $citations = $this->em
            ->createQuery(\sprintf('SELECT c FROM %s c WHERE IDENTITY(c.source) = :source', Citation::class))
            ->setParameter('source', $source->getId())
            ->getResult();

        return $citations;
    }

    private function compterLesCitations(Source $source): int
    {
        return (int) $this->em
            ->createQuery(\sprintf('SELECT COUNT(c.id) FROM %s c WHERE IDENTITY(c.source) = :source', Citation::class))
            ->setParameter('source', $source->getId())
            ->getSingleScalarResult();
    }
}
