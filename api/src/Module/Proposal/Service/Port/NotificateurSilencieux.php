<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service\Port;

use App\Module\Proposal\Entity\ChangeRequest;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Implémentation de repli de {@see NotificateurDeDemandes}, en attendant le module de
 * notification.
 *
 * Elle **n'échoue pas** et ne bloque donc aucune validation : une demande se valide
 * aujourd'hui même si personne n'est prévenu. Mais elle **journalise chaque
 * événement**, pour que l'absence de notification soit visible dans les journaux
 * plutôt que silencieuse — une file de validation dont personne n'est averti est
 * indistinguable d'une file vide, et c'est le genre de panne qu'on met des semaines à
 * remarquer.
 *
 * Une fois l'alias de `config/services/proposal.yaml` posé sur l'implémentation réelle,
 * ce fichier n'a plus qu'une utilité : servir de double dans les tests qui ne
 * s'intéressent pas aux notifications. C'est à ce titre qu'il reste.
 */
final readonly class NotificateurSilencieux implements NotificateurDeDemandes
{
    public function __construct(private LoggerInterface $journal = new NullLogger())
    {
    }

    public function demandeSoumise(ChangeRequest $demande): void
    {
        $this->tracer('demande_soumise', $demande);
    }

    public function demandeValidee(ChangeRequest $demande, bool $rejouee): void
    {
        $this->tracer($rejouee ? 'demande_rejouee' : 'demande_validee', $demande);
    }

    public function demandeRefusee(ChangeRequest $demande, string $message): void
    {
        $this->tracer('demande_refusee', $demande);
    }

    public function demandeCaduque(ChangeRequest $demande, array $champsEnConflit): void
    {
        $this->tracer('demande_caduque', $demande);
    }

    private function tracer(string $evenement, ChangeRequest $demande): void
    {
        $this->journal->info('Notification non émise : aucun module de notification n\'est branché.', [
            'evenement' => $evenement,
            'demande' => $demande->getId(),
            'auteur' => $demande->getAuthorUserId(),
        ]);
    }
}
