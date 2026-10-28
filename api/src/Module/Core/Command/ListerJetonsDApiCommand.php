<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Entity\JetonDApi;
use App\Module\Core\Entity\User;
use App\Module\Core\Repository\JetonDApiRepository;
use App\Module\Core\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Liste les jetons d'API (ADR 0021 « Jetons d'API »).
 *
 * Jamais l'empreinte, et a fortiori jamais le jeton : le préfixe suffit à reconnaître une
 * ligne. Par défaut, seuls les jetons **utilisables** (ni révoqués ni expirés) sont
 * montrés ; `--tous` ajoute les autres.
 */
#[AsCommand(
    name: 'heritage:jeton-api:lister',
    description: 'Liste les jetons d’API (sans jamais montrer leur empreinte).',
)]
final class ListerJetonsDApiCommand extends Command
{
    public function __construct(
        private readonly JetonDApiRepository $jetons,
        private readonly UserRepository $utilisateurs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('utilisateur', null, InputOption::VALUE_REQUIRED, 'Id ou courriel : ne montrer que les jetons de ce compte')
            ->addOption('tous', null, InputOption::VALUE_NONE, 'Inclure les jetons révoqués et expirés');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $reference = $input->getOption('utilisateur');
        if (null !== $reference) {
            $reference = (string) $reference;
            $compte = ctype_digit($reference)
                ? $this->utilisateurs->find((int) $reference)
                : $this->utilisateurs->findOneBy(['email' => $reference]);
            if (!$compte instanceof User) {
                $io->error(\sprintf('Aucun compte pour « %s ».', $reference));

                return Command::FAILURE;
            }
            $lignes = $this->jetons->duCompte((int) $compte->getId());
        } else {
            $lignes = $this->jetons->findBy([], ['id' => 'ASC']);
        }

        $maintenant = new \DateTimeImmutable();
        if (!$input->getOption('tous')) {
            $lignes = array_values(array_filter($lignes, static fn (JetonDApi $j): bool => $j->estUtilisable($maintenant)));
        }

        if ([] === $lignes) {
            $io->writeln('Aucun jeton.');

            return Command::SUCCESS;
        }

        $format = static fn (?\DateTimeImmutable $d): string => null === $d ? '—' : $d->format('Y-m-d H:i');

        $io->table(
            ['Id', 'Libellé', 'Préfixe', 'Portée', 'Propriétaire', 'Créé', 'Expire', 'Révoqué', 'Dernier usage'],
            array_map(static fn (JetonDApi $j): array => [
                $j->getId(),
                $j->getLibelle(),
                $j->getTokenPrefix(),
                $j->getPortee()->value,
                $j->getUser()->getEmail(),
                $format($j->getCreatedAt()),
                $format($j->getExpiresAt()).($j->estExpire($maintenant) ? ' (expiré)' : ''),
                $format($j->getRevokedAt()),
                null === $j->getLastUsedAt() ? '—' : $format($j->getLastUsedAt()).' ('.($j->getLastUsedIp() ?? 'IP inconnue').')',
            ], $lignes),
        );

        return Command::SUCCESS;
    }
}
