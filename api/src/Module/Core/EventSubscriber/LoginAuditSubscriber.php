<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Security\CleDAccesBadge;
use App\Module\Core\Security\GoogleBadge;
use App\Module\Core\Security\JetonDApiAuthentifie;
use App\Module\Core\Security\RequeteParJetonDApi;
use App\Module\Core\Service\GenerateurDeJetonDApi;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessToken\AccessTokenExtractorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Connexions, **tentatives échouées** et déconnexions au journal d'audit.
 *
 * Les tentatives échouées ne sont pas un luxe : sur une application familiale privée dont
 * l'unique porte est un formulaire de connexion, c'est la seule trace qui distingue « un
 * cousin a oublié son mot de passe » de « quelqu'un balaie la liste des adresses ». Elles
 * ont leur index partiel dédié (`idx_core_audit_echecs`, § 2.4 du modèle) précisément
 * parce qu'on les interroge isolément.
 *
 * Une tentative bloquée par `login_throttling` passe aussi par `LoginFailureEvent` : elle
 * est donc consignée comme les autres, ce qui est voulu — c'est le pic d'échecs qui rend
 * un balayage visible, pas son interruption.
 *
 * **Le mot de passe tenté n'est jamais écrit**, sous aucune forme. Seul l'identifiant
 * l'est ; c'est déjà ce que l'IP et l'horodatage disent.
 *
 * **La méthode est écrite** dans `changes.methode` des connexions et des échecs :
 * `mot_de_passe` ou `cle_d_acces` (§ 2.4 du contrat). Elle se lit sur le passeport
 * ({@see CleDAccesBadge}). Une tentative par clé d'accès dont la clé est inconnue porte
 * l'identifiant fixe `cle_d_acces_inconnue` : ni l'identifiant de la clé ni la réponse de
 * l'appareil ne sont écrits.
 *
 * ## Le jeton d'API (ADR 0021 § 7)
 *
 * L'`access_token` du pare-feu `api_jetons` émet `LoginSuccessEvent` à **chaque** requête :
 * ses succès ne sont pas des connexions, et n'écrivent rien — à deux requêtes par seconde,
 * le journal ne dirait plus rien. Ce que fait un jeton s'y lit ailleurs : chaque écriture
 * sous un jeton le nomme (`api_token_id`, `AuditDoctrineSubscriber`).
 *
 * Ses **échecs** s'écrivent, comme ceux du mot de passe : `changes.methode = "jeton_api"`
 * et `changes.prefixe`, le **préfixe seulement** — jamais le jeton présenté, même faux, ni
 * son empreinte ({@see self::prefixePresente()}). L'identifiant n'est connu que d'un jeton
 * reconnu dont le compte est refusé après coup (suspendu) : le courriel du compte et le
 * jeton (`api_token_id`, `api_token_label`) sont alors écrits ; un jeton inconnu n'en
 * laisse aucun. Une requête est « par jeton » quand elle porte `Authorization: Bearer` sur
 * `/api` ({@see RequeteParJetonDApi}) : c'est elle, et elle seule, que juge `api_jetons`.
 *
 * Un en-tête `Bearer` que l'extracteur de Symfony ne lit pas (`Bearer` seul, caractère
 * interdit, schéma en minuscules) n'atteint pas l'authentificateur : aucun échec n'est
 * émis, le point d'entrée répond `401`, et rien n'est écrit ici.
 */
final class LoginAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequeteParJetonDApi $requeteParJeton,
        // L'extracteur du pare-feu (`token_extractors: header`) : le jeton relu ici est
        // celui que `JetonDApiHandler` a jugé, au caractère près.
        #[Autowire(service: 'security.access_token_extractor.header')]
        private readonly AccessTokenExtractorInterface $extracteurDeJeton,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'surConnexion',
            LoginFailureEvent::class => 'surEchec',
            LogoutEvent::class => 'surDeconnexion',
        ];
    }

    public function surConnexion(LoginSuccessEvent $event): void
    {
        // Un succès par jeton d'API n'est pas une connexion (voir l'en-tête).
        if ($this->requeteParJeton->matches($event->getRequest())
            || null !== JetonDApiAuthentifie::depuisLeJetonDeSecurite($event->getAuthenticatedToken())) {
            return;
        }

        $utilisateur = $event->getUser();

        $this->consigner(
            AuditAction::Connexion,
            $event->getRequest(),
            $utilisateur instanceof User ? $utilisateur->getId() : null,
            $utilisateur->getUserIdentifier(),
            $this->methode($event->getPassport()),
        );
    }

    public function surEchec(LoginFailureEvent $event): void
    {
        if ($this->requeteParJeton->matches($event->getRequest())) {
            $this->consignerLEchecDUnJeton($event);

            return;
        }

        $badge = $event->getPassport()?->getBadge(UserBadge::class);
        $identifiant = $badge instanceof UserBadge ? $badge->getUserIdentifier() : null;

        // `actor_user_id` reste nul : on ne veut pas résoudre l'identifiant en compte
        // pour une tentative échouée, ce serait interroger la base sur une saisie
        // arbitraire à chaque coup d'un balayage.
        $this->consigner(AuditAction::ConnexionEchouee, $event->getRequest(), null, $identifiant, $this->methode($event->getPassport()));
    }

    public function surDeconnexion(LogoutEvent $event): void
    {
        $utilisateur = $event->getToken()?->getUser();

        if (null === $utilisateur) {
            return;
        }

        $this->consigner(
            AuditAction::Deconnexion,
            $event->getRequest(),
            $utilisateur instanceof User ? $utilisateur->getId() : null,
            $utilisateur->getUserIdentifier(),
        );
    }

    /**
     * `null` quand il n'y a pas de passeport — une requête si mal formée que
     * l'authentificateur n'en a pas construit : on ne sait alors rien de la méthode.
     *
     * @return array{methode: string}|null
     */
    private function methode(?Passport $passeport): ?array
    {
        if (null === $passeport) {
            return null;
        }

        return ['methode' => match (true) {
            $passeport->hasBadge(CleDAccesBadge::class) => 'cle_d_acces',
            $passeport->hasBadge(GoogleBadge::class) => 'google',
            default => 'mot_de_passe',
        }];
    }

    /**
     * Voir l'en-tête, « Le jeton d'API ». Le passeport n'existe que si le jeton a été
     * reconnu : un jeton inconnu, expiré, révoqué ou mal formé échoue dans
     * `JetonDApiHandler`, avant lui.
     */
    private function consignerLEchecDUnJeton(LoginFailureEvent $event): void
    {
        $requete = $event->getRequest();
        $badge = $event->getPassport()?->getBadge(UserBadge::class);
        $jeton = $badge instanceof UserBadge ? JetonDApiAuthentifie::depuisAttributs($badge->getAttributes()) : null;

        $this->consigner(
            AuditAction::ConnexionEchouee,
            $requete,
            null,
            null !== $jeton && $badge instanceof UserBadge ? $badge->getUserIdentifier() : null,
            ['methode' => 'jeton_api', 'prefixe' => $this->prefixePresente($requete)],
            $jeton,
        );
    }

    /**
     * Le préfixe du jeton présenté, ou `null` — jamais le jeton, même faux, ni son
     * empreinte (ADR 0021 § 7).
     *
     * Seule une chaîne qui **commence comme un jeton d'Heritage** — `hrt_` et 8 caractères
     * base64url — en laisse un : ses {@see GenerateurDeJetonDApi::LONGUEUR_PREFIXE} premiers
     * caractères, ceux qu'affiche `heritage:jeton-api:lister`, et pas un de plus (8 sur 43
     * ne reconstruisent rien). Elle doit aussi être plus longue que lui : une chaîne de la
     * longueur du préfixe serait écrite entière. Une chaîne qui ne commence pas par `hrt_`
     * est peut-être le secret d'un autre système, collé au mauvais endroit : elle ne laisse
     * rien.
     */
    private function prefixePresente(Request $requete): ?string
    {
        $presente = $this->extracteurDeJeton->extractAccessToken($requete);

        if (null === $presente || \strlen($presente) <= GenerateurDeJetonDApi::LONGUEUR_PREFIXE) {
            return null;
        }

        $prefixe = substr($presente, 0, GenerateurDeJetonDApi::LONGUEUR_PREFIXE);
        $forme = \sprintf(
            '/^%s[A-Za-z0-9_-]{%d}\z/',
            preg_quote(GenerateurDeJetonDApi::PREFIXE, '/'),
            GenerateurDeJetonDApi::LONGUEUR_PREFIXE - \strlen(GenerateurDeJetonDApi::PREFIXE),
        );

        return 1 === preg_match($forme, $prefixe) ? $prefixe : null;
    }

    /** @param array<string, mixed>|null $changes */
    private function consigner(
        AuditAction $action,
        Request $requete,
        ?int $acteurId,
        ?string $acteurEmail,
        ?array $changes = null,
        ?JetonDApiAuthentifie $jeton = null,
    ): void {
        $this->em->persist(new AuditLog(
            $action,
            'Core\\User',
            null !== $acteurId ? (string) $acteurId : null,
            null,
            $changes,
            $acteurId,
            $acteurEmail,
            $requete->getClientIp(),
            $this->tronquer($requete->headers->get('User-Agent')),
            apiTokenId: $jeton?->id,
            apiTokenLabel: $jeton?->libelle,
        ));

        $this->em->flush();
    }

    private function tronquer(?string $valeur): ?string
    {
        if (null === $valeur || '' === $valeur) {
            return null;
        }

        return mb_substr($valeur, 0, 255);
    }
}
