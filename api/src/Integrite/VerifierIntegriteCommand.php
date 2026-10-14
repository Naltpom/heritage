<?php

declare(strict_types=1);

namespace App\Integrite;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `heritage:verifier-integrite` — parcourt toutes les références inter-modules et signale
 * celles qui pendent (ADR 0002, quatrième mesure ; cahier des charges : § 10.8 de
 * `docs/modele-donnees.md`).
 *
 * **Lecture seule.** Elle ne répare rien : une référence pendante a toujours une histoire —
 * un `TRUNCATE` à la main, une suppression passée par un client SQL — et la réparer à
 * l'aveugle effacerait la seule trace qui permette de la reconstituer.
 *
 * ## Le code de sortie
 *
 * - `0` : aucune violation. Des références **tolérées** peuvent pendre — un historique, une
 *   preuve, une demande qui deviendra caduque — et le rapport les montre avec leur raison ;
 * - `1` : au moins une violation, ou un contrôle qui n'a pas pu se faire (cible sans
 *   propriétaire) ;
 * - `2` : option invalide.
 *
 * ## `--format=json`
 *
 * Pour une machine : la CI, un script de surveillance. Le texte est pour un humain et peut
 * changer de mise en page ; les clés JSON, elles, sont un contrat.
 */
#[AsCommand(
    name: 'heritage:verifier-integrite',
    description: 'Parcourt les références inter-modules (ADR 0002, § 10.8 du modèle) et signale celles qui pendent. Lecture seule.',
)]
final class VerifierIntegriteCommand extends Command
{
    /** Au-delà, la colonne des identifiants pendants se résume : le JSON les porte tous. */
    private const int IDENTIFIANTS_AFFICHES = 8;

    public function __construct(private readonly VerificationDIntegrite $verification)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'texte (par défaut) ou json.', 'texte');
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $style = new SymfonyStyle($entree, $sortie);
        $format = $entree->getOption('format');

        if (!\in_array($format, ['texte', 'json'], true)) {
            $style->error(\sprintf('Format inconnu : « %s ». Attendu : texte ou json.', \is_scalar($format) ? (string) $format : ''));

            return Command::INVALID;
        }

        $rapport = $this->verification->verifier();

        if ('json' === $format) {
            $sortie->writeln(json_encode($rapport->versJson(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

            return $rapport->estSain() ? Command::SUCCESS : Command::FAILURE;
        }

        $this->ecrireLeTexte($style, $rapport);

        return $rapport->estSain() ? Command::SUCCESS : Command::FAILURE;
    }

    private function ecrireLeTexte(SymfonyStyle $style, RapportDIntegrite $rapport): void
    {
        $style->title('Intégrité des références inter-modules');

        $lignes = [];

        foreach ($rapport->controles as $controle) {
            $lignes[] = [
                match ($controle->etat()) {
                    'sain' => 'ok',
                    'violation' => 'VIOLATION',
                    default => 'tolérée',
                },
                $controle->module,
                $controle->reference->libelle(),
                $controle->reference->cible->value,
                $controle->lignesRelevees(),
                $controle->lignesPendantes(),
                $this->identifiants(array_keys($controle->pendants)),
            ];
        }

        $style->table(['État', 'Module', 'Référence', 'Cible', 'Lignes', 'Pendantes', 'Identifiants introuvables'], $lignes);

        foreach ($rapport->tolerees() as $controle) {
            $style->note(\sprintf('%s → %s : %d ligne(s) pendante(s), tolérée(s) — %s.', $controle->reference->libelle(), $controle->reference->cible->value, $controle->lignesPendantes(), (string) $controle->reference->tolerance));
        }

        foreach ($rapport->anomalies as $anomalie) {
            $style->error($anomalie);
        }

        $violations = $rapport->violations();

        if ([] !== $violations) {
            $style->error(\sprintf(
                "%d référence(s) pendante(s) sur %d contrôle(s) :\n%s",
                \count($violations),
                \count($rapport->controles),
                implode("\n", array_map(
                    static fn (ControleDIntegrite $c): string => \sprintf('- %s → %s : %d ligne(s), identifiants %s', $c->reference->libelle(), $c->reference->cible->value, $c->lignesPendantes(), implode(', ', array_keys($c->pendants))),
                    $violations,
                )),
            ));

            return;
        }

        if ([] === $rapport->anomalies) {
            $style->success(\sprintf('%d contrôle(s), aucune référence pendante hors tolérance (%d tolérée(s)).', \count($rapport->controles), \count($rapport->tolerees())));
        }
    }

    /** @param list<int> $identifiants */
    private function identifiants(array $identifiants): string
    {
        if (\count($identifiants) <= self::IDENTIFIANTS_AFFICHES) {
            return implode(', ', $identifiants);
        }

        return implode(', ', \array_slice($identifiants, 0, self::IDENTIFIANTS_AFFICHES)).\sprintf(' … (+%d)', \count($identifiants) - self::IDENTIFIANTS_AFFICHES);
    }
}
