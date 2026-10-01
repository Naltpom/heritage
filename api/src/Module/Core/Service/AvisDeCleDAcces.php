<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Entity\Passkey;
use App\Module\Core\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * « Une clé d'accès a été ajoutée à votre compte » — le courriel qui suit chaque ajout.
 *
 * Une clé d'accès est un moyen de connexion **permanent**. Si elle a été ajoutée par
 * quelqu'un d'autre (session volée, poste partagé dans les dix minutes d'une connexion —
 * voir {@see PreuveDIdentiteRecente}), ce courriel est la seule chance que le titulaire
 * l'apprenne : il dit quoi faire — supprimer la clé dans Mon compte, changer son mot de
 * passe, ce qui retire **toutes** les clés (§ 2.1).
 *
 * **Au mieux de nos moyens, jamais bloquant.** Contrairement au changement de courriel,
 * dont le lien EST la preuve, l'avis n'est qu'une information : sans SMTP configuré
 * (`null://…`, état normal du produit, § 6.1) il ne part pas, et une panne du relais est
 * journalisée sans faire échouer l'ajout — la clé est déjà en base, la réponse doit le
 * dire.
 *
 * Le courriel ne porte ni l'identifiant de la clé ni sa partie publique : le nom, la
 * date, et un lien vers Mon compte.
 */
final readonly class AvisDeCleDAcces
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private UrlPublique $urlPublique,
        // Même paramètre que `ChangementDeCourriel` : voir son commentaire.
        #[Autowire(param: 'app.core.mailer_dsn')]
        private string $dsn,
        #[Autowire(param: 'app.core.expediteur')]
        private string $expediteur,
    ) {
    }

    public function prevenirDeLAjout(User $compte, Passkey $cle): void
    {
        $dsn = trim($this->dsn);

        if ('' === $dsn || str_starts_with($dsn, 'null:')) {
            return;
        }

        try {
            $lien = $this->urlPublique->versLeFront('/compte');
        } catch (\LogicException) {
            // Adresse publique absente : l'avis part quand même, sans lien.
            $lien = null;
        }

        $courriel = (new Email())
            ->to($compte->getEmail())
            ->subject('Une clé d’accès a été ajoutée à votre compte Heritage')
            ->text(
                "Bonjour,\n\n"
                .\sprintf(
                    "Une clé d'accès « %s » a été ajoutée à votre compte Heritage le %s.\n"
                    ."Elle permet de se connecter sans mot de passe, avec Face ID, une empreinte ou Windows Hello.\n\n",
                    $cle->getNom(),
                    $cle->getCreatedAt()->format('d/m/Y à H:i'),
                )
                ."Si c'est vous, il n'y a rien à faire.\n\n"
                ."Si ce n'est pas vous : connectez-vous, supprimez cette clé dans Mon compte, "
                ."puis changez votre mot de passe — cela retire toutes les clés d'accès du compte.\n"
                .(null !== $lien ? "\n".$lien."\n" : ''),
            );

        if ('' !== trim($this->expediteur)) {
            $courriel->from($this->expediteur);
        }

        try {
            $this->mailer->send($courriel);
        } catch (TransportExceptionInterface $panne) {
            $this->logger->error('Envoi de l’avis d’ajout de clé d’accès impossible.', ['exception' => $panne]);
        }
    }
}
