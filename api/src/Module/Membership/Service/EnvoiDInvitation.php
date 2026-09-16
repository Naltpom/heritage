<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * L'envoi du lien d'invitation par courriel — **le second des deux chemins** exigés par le
 * § 6.1 du dossier : « le lien est affiché à l'administrateur pour copie manuelle *et*
 * peut être envoyé par courriel si un SMTP est configuré. Les deux chemins doivent
 * exister. ».
 *
 * ## Un envoi qui échoue ne fait pas échouer l'invitation
 *
 * Règle du § 3.1 du contrat, et elle se justifie en une phrase : « perdre l'invitation
 * parce que Mailpit est éteint serait absurde — le lien est affiché de toute façon ».
 * L'invitation est donc créée, la réponse porte `courrielEnvoye: false` et un
 * avertissement lisible, et l'administrateur copie le lien à la main.
 *
 * C'est aussi pourquoi cette classe **ne lève jamais** : elle rend un verdict, pas une
 * exception. Une exception remonterait dans la transaction de création et ferait
 * exactement ce que le contrat interdit.
 *
 * ## `null://null` n'est pas un SMTP configuré
 *
 * Le transport nul de Symfony **réussit silencieusement** : sans ce contrôle, un
 * déploiement sans SMTP répondrait `courrielEnvoye: true` et personne ne recevrait rien —
 * la pire des deux pannes, celle qui ne se voit pas. `api/.env` pose `null://null` par
 * défaut, donc le cas nominal en développement est justement celui-là.
 */
final readonly class EnvoiDInvitation
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        #[Autowire(env: 'MAILER_DSN')]
        private string $dsn,
        #[Autowire(param: 'app.membership.expediteur')]
        private string $expediteur,
    ) {
    }

    public function smtpConfigure(): bool
    {
        $dsn = trim($this->dsn);

        return '' !== $dsn && !str_starts_with($dsn, 'null:');
    }

    /**
     * @return list<string> les avertissements à joindre à la réponse ; vide si l'envoi a eu lieu
     */
    public function envoyer(string $destinataire, string $lien, ?string $note): array
    {
        if (!$this->smtpConfigure()) {
            return ['Aucun SMTP n\'est configuré : le courriel n\'a pas été envoyé. Le lien reste à copier à la main.'];
        }

        $courriel = (new Email())
            ->to($destinataire)
            ->subject('Votre invitation à rejoindre Heritage')
            ->text($this->corps($lien, $note));

        if ('' !== trim($this->expediteur)) {
            $courriel->from($this->expediteur);
        }

        try {
            $this->mailer->send($courriel);
        } catch (TransportExceptionInterface $panne) {
            // Le message de la panne va au journal, pas à la réponse : il porte le nom
            // d'hôte du relais et parfois un identifiant de compte.
            $this->logger->error('Envoi de l\'invitation impossible.', ['exception' => $panne]);

            return ['L\'envoi du courriel a échoué. Le lien reste à copier à la main.'];
        }

        return [];
    }

    private function corps(string $lien, ?string $note): string
    {
        $corps = "Bonjour,\n\n"
            ."Vous êtes invité à rejoindre l'arbre généalogique familial Heritage.\n\n"
            ."Rendez-vous sur ce lien pour créer votre compte :\n"
            .$lien."\n\n"
            ."Ce lien est personnel, il ne sert qu'une fois et il expire.\n";

        if (null !== $note && '' !== trim($note)) {
            $corps .= "\n".trim($note)."\n";
        }

        return $corps;
    }
}
