<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Command;

use App\Module\Gedcom\Dto\RapportImport;
use App\Module\Gedcom\Service\ImporteurGedcom;
use App\Module\Gedcom\Service\TotauxAttendus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `heritage:import-gedcom` — l'import des vraies données.
 *
 * **Une commande locale, pas une fixture** (§ 9 du dossier de mission) : les données
 * généalogiques réelles concernent des personnes vivantes, ne sont pas versionnées, et
 * vivent dans une autre arborescence que le dépôt. C'est pourquoi le chemin du fichier
 * est un **argument ou un réglage**, jamais un chemin en dur : sur une autre machine,
 * l'atelier sera ailleurs ou n'existera pas.
 *
 * Le rapport final affiche le tableau des totaux **attendus contre obtenus**, et la
 * commande sort en erreur au moindre écart. Un import silencieusement incomplet est le
 * pire résultat possible : l'application marcherait, avec un arbre amputé que personne
 * ne remarquerait.
 */
#[AsCommand(
    name: 'heritage:import-gedcom',
    description: 'Importe un fichier GEDCOM dans l’arbre, et contrôle les dénombrements.',
)]
final class ImportGedcomCommand extends Command
{
    public function __construct(
        private readonly ImporteurGedcom $importeur,
        private readonly TotauxAttendus $reference,
        /**
         * Le réglage `app.gedcom.fichier`, alimenté par la variable d'environnement
         * `GEDCOM_FILE`.
         *
         * **Câblé par attribut et non par `arguments:` en YAML**, et ce n'est pas une
         * question de goût : le prototype `App\: resource: '../src/'` de
         * `config/services.yaml` est traité **après** les imports de
         * `config/services/*.yaml`, et il écrase en silence toute définition concrète
         * venue d'un fichier de module. La déclaration est là, elle ne s'applique pas, et
         * on ne le découvre qu'au premier appel. L'attribut vit sur la classe que le
         * prototype enregistre : il ne peut pas être effacé par elle.
         *
         * Se relire son propre YAML ne prouve rien ici ; `bin/console debug:container`, si.
         */
        #[Autowire('%app.gedcom.fichier%')]
        private readonly ?string $fichierParDefaut = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'fichier',
                InputArgument::OPTIONAL,
                'Chemin du fichier GEDCOM. À défaut, la variable d’environnement GEDCOM_FILE.',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Lit, compte et signale sans rien écrire. C’est le mode dans lequel on découvre qu’un fichier est mauvais avant d’avoir touché la base.',
            )
            ->addOption(
                'ego',
                null,
                InputOption::VALUE_REQUIRED,
                'Identifiant GEDCOM de la personne racine (« @I500001@ »). À défaut, la personne la plus profondément enracinée.',
            )
            ->addOption(
                'premier-import',
                null,
                InputOption::VALUE_NONE,
                'Assume un arbre vide sous des comptes déjà créés — le cas d’un premier import après heritage:creer-administrateur.',
            )
            ->addOption(
                'attendus',
                null,
                InputOption::VALUE_REQUIRED,
                'Totaux de contrôle pour un fichier sans jeu de référence : « personnes=8,familles=3 ».',
            )
            ->setHelp(<<<'AIDE'
                Importe un GEDCOM dans les tables <info>tree_*</info> et journalise l'exécution
                dans <info>ged_run</info> / <info>ged_issue</info>.

                  <info>%command.full_name% C:/www/myHeritage/arbre-romarin-v5.ged --dry-run</info>
                  <info>%command.full_name% C:/www/myHeritage/arbre-romarin-v5.ged</info>

                L'import est <comment>idempotent</comment> : personnes, familles et sources sont
                retrouvées par leur identifiant GEDCOM, et un second passage ne crée pas de doublon.

                Il <comment>refuse de s'exécuter</comment> si l'arbre est vide alors que des comptes
                existent : c'est la signature d'un schéma détruit sous des comptes vivants, et
                repeupler l'arbre réattribuerait les identifiants que les rattachements désignent.
                Pour un premier import légitime, après <info>heritage:creer-administrateur</info>,
                passer <info>--premier-import</info>.

                Les totaux attendus sont indexés par l'empreinte du fichier. Pour un autre
                fichier, les fournir à la main avec <info>--attendus</info>.
                AIDE)
        ;
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $style = new SymfonyStyle($entree, $sortie);

        $fichier = $entree->getArgument('fichier');
        $fichier = \is_string($fichier) && '' !== $fichier ? $fichier : $this->fichierParDefaut;

        if (null === $fichier || '' === $fichier) {
            $style->error([
                'Aucun fichier GEDCOM indiqué.',
                'Passer le chemin en argument, ou définir GEDCOM_FILE dans .env.local.',
            ]);

            return Command::INVALID;
        }

        $attendus = null;
        $option = $entree->getOption('attendus');

        if (\is_string($option) && '' !== $option) {
            try {
                $attendus = $this->reference->depuisOption($option);
            } catch (\InvalidArgumentException $erreur) {
                $style->error($erreur->getMessage());

                return Command::INVALID;
            }
        }

        $ego = $entree->getOption('ego');
        $aBlanc = true === $entree->getOption('dry-run');

        $style->title($aBlanc ? 'Import GEDCOM — exécution à blanc' : 'Import GEDCOM');

        try {
            $rapport = $this->importeur->importer(
                $fichier,
                $aBlanc,
                \is_string($ego) && '' !== $ego ? $ego : null,
                $attendus,
                true === $entree->getOption('premier-import'),
            );
        } catch (\Throwable $erreur) {
            $style->error(explode("\n", $erreur->getMessage()));
            $style->writeln('<comment>Rien n’a été écrit : la transaction a été annulée.</comment>');

            return Command::FAILURE;
        }

        $this->afficher($style, $rapport, $aBlanc);

        if (!$rapport->estControle()) {
            $style->warning([
                'Ce fichier n’a pas de jeu de totaux de référence : les dénombrements ci-dessus sont rapportés, pas contrôlés.',
                'Empreinte : '.$rapport->sha256,
            ]);

            return Command::SUCCESS;
        }

        if (!$rapport->estConforme()) {
            $style->error('Les dénombrements ne retombent pas. Ne pas corriger les totaux attendus : chercher pourquoi.');

            return Command::FAILURE;
        }

        $style->success(\sprintf(
            '%s — les %d totaux de contrôle tombent juste (%.1f s).',
            $aBlanc ? 'Lecture à blanc conforme' : 'Import conforme',
            \count((array) $rapport->attendus),
            $rapport->secondes,
        ));

        return Command::SUCCESS;
    }

    private function afficher(SymfonyStyle $style, RapportImport $rapport, bool $aBlanc): void
    {
        $style->definitionList(
            ['Fichier' => $rapport->fichier],
            ['Empreinte SHA-256' => $rapport->sha256],
            ['Taille' => \sprintf('%s octets', number_format($rapport->octets, 0, ',', ' '))],
            ['Jeu de caractères annoncé' => $rapport->charset ?? '(non déclaré)'],
            ['Personne racine' => $rapport->ego],
            ['Exécution ged_run' => null === $rapport->runId ? '(non journalisée)' : '#'.$rapport->runId],
        );

        $style->section('Totaux de contrôle');

        $lignes = [];
        $conforme = true;

        foreach (TotauxAttendus::LIBELLES as $cle => $libelle) {
            $obtenu = $rapport->obtenus[$cle] ?? 0;
            $attendu = $rapport->attendus[$cle] ?? null;
            $verdict = match (true) {
                null === $attendu => '—',
                $attendu === $obtenu => 'ok',
                default => 'ÉCART',
            };

            $conforme = $conforme && 'ÉCART' !== $verdict;

            $lignes[] = [
                $libelle,
                null === $attendu ? '—' : (string) $attendu,
                (string) $obtenu,
                'ÉCART' === $verdict ? \sprintf('<error> %s </error>', $verdict) : $verdict,
                // D'où vient le nombre. Les médias et les marques `RESN privacy` ne sont
                // pas persistés : les relever en base serait impossible, et les faire
                // passer pour un décompte de base serait malhonnête.
                $this->releve($cle, $aBlanc),
            ];
        }

        $style->table(['Contrôle', 'attendu', 'obtenu', '', 'relevé sur'], $lignes);

        $this->afficherAnomalies($style, $rapport);
    }

    private function releve(string $cle, bool $aBlanc): string
    {
        if ($aBlanc) {
            return 'fichier';
        }

        return \in_array($cle, ['medias', 'resn_privacy'], true) ? 'fichier' : 'base';
    }

    private function afficherAnomalies(SymfonyStyle $style, RapportImport $rapport): void
    {
        $parSeverite = $rapport->anomalies->parSeverite();
        $parCode = $rapport->anomalies->parCode();

        if ([] === $parCode) {
            $style->writeln(' Aucune anomalie relevée.');

            return;
        }

        $style->section('Anomalies');
        $style->writeln(\sprintf(
            ' %d erreur(s), %d avertissement(s), %d information(s) — écrites dans ged_issue.',
            $parSeverite['erreur'],
            $parSeverite['avertissement'],
            $parSeverite['info'],
        ));
        $style->newLine();

        $lignes = [];

        foreach ($parCode as $code => $nombre) {
            $lignes[] = [$code, (string) $nombre];
        }

        $style->table(['code', 'occurrences'], $lignes);
    }
}
