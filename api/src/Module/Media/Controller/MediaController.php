<?php

declare(strict_types=1);

namespace App\Module\Media\Controller;

use App\Module\Core\Contract\AnnuaireDesMembresInterface;
use App\Module\Core\Entity\User;
use App\Module\Core\Exception\ConflictException;
use App\Module\Core\Exception\FichierTropVolumineuxException;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Exception\TooManyRequestsException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Http\BlocDate;
use App\Module\Media\Security\FabriqueDeLecteur;
use App\Module\Media\Security\LecteurMedia;
use App\Module\Media\Service\AdresseDuMedia;
use App\Module\Media\Service\CatalogueMedia;
use App\Module\Media\Service\CircuitDeValidationMedia;
use App\Module\Media\Service\NormaliseurMedia;
use App\Module\Media\Service\PivotementMedia;
use App\Module\Media\Service\RequeteDeTeleversement;
use App\Module\Media\Service\StockageMedia;
use App\Module\Media\Service\SuppressionMedia;
use App\Module\Media\Service\TeleversementMedia;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Les six routes de médias du § 7 du contrat, plus la rotation d'une image.
 *
 * ## Ce que le contrôleur fait, et ce qu'il ne fait pas
 *
 * Il traduit HTTP en types, appelle **un** service, et rend. Aucune décision
 * d'autorisation n'est prise ici : le cercle de visibilité est appliqué dans les
 * services et la sérialisation, jamais dans le contrôleur (§ 8 du dossier). Un
 * contrôleur qui déciderait produirait une seconde source de vérité, et les deux
 * finiraient par diverger sur la route qu'on oublie de relire.
 *
 * ## Les octets ne sortent que par ici
 *
 * `BinaryFileResponse` sert le fichier **en flux**, sans charger 20 Mio en mémoire. Ni
 * `X-Sendfile` ni `X-Accel-Redirect` ne sont activés : le § 7.4 les exclut nommément,
 * et pour une raison qui tient — déléguer l'envoi à nginx donnerait au client une URL
 * qu'il pourrait partager, et ce lien ne repasserait pas par le cercle.
 *
 * ## Aucune enveloppe d'erreur n'est composée ici
 *
 * Les services lèvent des `Core\Exception\DomainException` et
 * `Core\EventSubscriber\ApiExceptionSubscriber` les traduit. Il n'y a donc pas un
 * `try/catch` dans ce fichier, et c'est volontaire : une enveloppe composée à la main
 * dans chaque module donne quatre variantes de `{"erreur":{…}}` qui divergent au premier
 * champ ajouté, et le front doit alors gérer quatre formes pour la même chose.
 *
 * Deux codes du § 15.2 manquaient au noyau — `413 fichier_trop_volumineux` et
 * `415 type_de_fichier_refuse`. Ils sont comblés par deux sous-classes de la **même**
 * racine, dans `Media\Exception`, en attendant leur déplacement dans `Core` : voir
 * {@see FichierTropVolumineuxException}.
 *
 * ## L'en-tête de cache n'est pas posé ici non plus
 *
 * `Core\EventSubscriber\SecurityHeadersSubscriber` pose `Cache-Control: no-store` et
 * `Vary: Cookie` sur toute réponse `/api` sans `ETag`, celles de ce module comprises.
 */
#[Route('/api/medias')]
final class MediaController extends AbstractController
{
    public function __construct(
        private readonly FabriqueDeLecteur $lecteurs,
        private readonly TeleversementMedia $televersement,
        private readonly CatalogueMedia $catalogue,
        private readonly SuppressionMedia $suppression,
        private readonly NormaliseurMedia $normaliseur,
        private readonly StockageMedia $stockage,
        /**
         * 20 dépôts par heure et par compte (§ 1.9 du contrat) — **sauf pour un
         * administrateur**, qui en est exempté depuis le 17 septembre 2026. La décision,
         * sa portée exacte et ce qu'il reste de freins sont sur {@see self::limiter()} :
         * les lire avant de raisonner sur ce plafond.
         *
         * Le limiteur est déclaré dans `config/services/media.yaml` plutôt que sous
         * `framework.rate_limiter` : un module configure ce qui lui appartient dans SON
         * fichier (règle 1 de l'ADR 0002), et `framework.yaml` n'est à personne en
         * particulier. Un `RateLimiterFactory` est un service ordinaire — il n'a rien
         * qu'un `framework.rate_limiter` saurait faire et qu'une définition explicite ne
         * sache pas.
         *
         * L'identifiant `media.limiteur_televersement` n'est **pas** un nom de classe, et
         * c'est ce qui le met hors d'atteinte de l'écrasement silencieux décrit sur
         * {@see StockageMedia} : la ressource `App\: ../src/`
         * ne réenregistre que des identifiants qui sont des classes de `src/`. Vérifié
         * avec `bin/console debug:container media.limiteur_televersement`.
         */
        #[Autowire(service: 'media.limiteur_televersement')]
        private readonly RateLimiterFactoryInterface $limiteur,
        /**
         * La bascule « voir comme un membre » (§ 10.4). Elle n'interdit rien en lecture
         * — c'est tout son intérêt : l'administrateur vérifie ce qu'un membre voit — mais
         * **toute écriture** doit être refusée tant qu'elle est active. Sans ce refus, un
         * média serait déposé sous l'identité empruntée pendant que l'écran montre
         * quelqu'un d'autre, et `uploaded_by_user_id` porterait le mauvais compte.
         *
         * L'appel ne coûte rien hors bascule : il rend immédiatement.
         */
        private readonly BasculeVoirComme $bascule,
        private readonly AnnuaireDesMembresInterface $annuaire,
        private readonly CircuitDeValidationMedia $circuit,
        private readonly PivotementMedia $pivotement,
    ) {
    }

    #[Route('', name: 'media_televerser', methods: ['POST'])]
    public function televerser(Request $requete): Response
    {
        $this->bascule->refuserSiActive();

        $lecteur = $this->lecteur();
        $this->limiter($lecteur);

        $fichier = $requete->files->get('fichier');

        if (!$fichier instanceof UploadedFile) {
            throw new ValidationException(['fichier' => 'Aucun fichier reçu.']);
        }

        $this->verifierLeTransfert($fichier);

        $resultat = $this->televersement->televerser(
            new RequeteDeTeleversement(
                cheminTemporaire: $fichier->getPathname(),
                // `getClientOriginalName()` vient du client et n'est jamais utilisé
                // pour lire ou écrire : il finit en libellé, rien de plus.
                nomOrigine: $fichier->getClientOriginalName(),
                kind: $this->enumObligatoire(MediaKind::class, $requete->request->get('kind'), 'kind'),
                subjectKind: $this->enumObligatoire(MediaSubject::class, $requete->request->get('subjectKind'), 'subjectKind'),
                subjectId: $this->entier($requete->request->get('subjectId'), 'subjectId'),
                demandeId: $this->entier($requete->request->get('demandeId'), 'demandeId'),
                brouillonRef: $this->chaine($requete->request->get('brouillonRef'), 'brouillonRef'),
                caption: $this->chaine($requete->request->get('caption'), 'caption'),
                taken: BlocDate::depuisJson($this->chaine($requete->request->get('taken'), 'taken')),
            ),
            $lecteur,
        );

        return $this->charge(
            $this->normaliseur->normaliser($resultat->media, $lecteur, $resultat->avertissements),
            Response::HTTP_CREATED,
        );
    }

    /**
     * **Le portrait de sa propre personne — direct, sans validation.**.
     *
     * Tout média passe par le circuit de demande ; celui-ci non, et c'est une décision
     * du commanditaire : c'est ton visage. La règle « un seul portrait actif » tient
     * quand même — l'ancien passe à `remplace`, il reste visible sur la fiche — parce
     * que c'est {@see CircuitDeValidationMedia::valider()} qui l'applique, exactement
     * comme après une validation d'administrateur.
     *
     * `409 compte_non_rattache` : le compte n'a pas de personne validée. Un compte en
     * attente ne peut pas poser un portrait sur une fiche qu'il ne fait que réclamer.
     */
    #[Route('/portrait-du-compte', name: 'media_portrait_du_compte', methods: ['POST'])]
    public function portraitDuCompte(Request $requete): Response
    {
        $this->bascule->refuserSiActive();

        $lecteur = $this->lecteur();
        $this->limiter($lecteur);

        $personneId = $this->annuaire->personneDuCompte($lecteur->userId);

        if (null === $personneId) {
            throw new ConflictException('compte_non_rattache', 'Votre compte n’est rattaché à aucune personne validée.');
        }

        $fichier = $requete->files->get('fichier');

        if (!$fichier instanceof UploadedFile) {
            throw new ValidationException(['fichier' => 'Aucun fichier reçu.']);
        }

        $this->verifierLeTransfert($fichier);

        $resultat = $this->televersement->televerser(
            new RequeteDeTeleversement(
                cheminTemporaire: $fichier->getPathname(),
                nomOrigine: $fichier->getClientOriginalName(),
                kind: MediaKind::Portrait,
                subjectKind: MediaSubject::Personne,
                subjectId: $personneId,
            ),
            $lecteur,
        );

        $this->circuit->valider($resultat->media);

        return $this->charge(
            $this->normaliseur->normaliser($resultat->media, $lecteur, $resultat->avertissements),
            Response::HTTP_CREATED,
        );
    }

    /**
     * Liste **non paginée**, bornée par le sujet : tableau nu, sans enveloppe (§ 1.5).
     */
    #[Route('', name: 'media_lister', methods: ['GET'])]
    public function lister(Request $requete): Response
    {
        $lecteur = $this->lecteur();

        $sujetKind = $this->enumObligatoire(MediaSubject::class, $requete->query->get('subjectKind'), 'subjectKind');
        $sujetId = $this->entier($requete->query->get('subjectId'), 'subjectId');

        if (null === $sujetId) {
            throw new ValidationException(['subjectId' => 'Le sujet est obligatoire.']);
        }

        $medias = $this->catalogue->lister(
            $sujetKind,
            $sujetId,
            $lecteur,
            $this->enumFacultatif(MediaKind::class, $requete->query->get('kind'), 'kind'),
            $this->enumFacultatif(MediaStatus::class, $requete->query->get('status'), 'status'),
        );

        return $this->charge(array_map(
            fn (Media $media): array => $this->normaliseur->normaliser($media, $lecteur),
            $medias,
        ));
    }

    #[Route('/{id}', name: 'media_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id): Response
    {
        $lecteur = $this->lecteur();

        return $this->charge($this->normaliseur->normaliser($this->catalogue->voir($id, $lecteur), $lecteur));
    }

    #[Route('/{id}/fichier', name: 'media_fichier', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function fichier(int $id, Request $requete): Response
    {
        // `voir()` d'abord, **toujours** : il lève `404` si le lecteur n'a rien à voir
        // ici, et la revalidation d'`ETag` n'a donc jamais lieu avant le cercle.
        $lecteur = $this->lecteur();
        $media = $this->catalogue->voir($id, $lecteur);

        return $this->repondre(
            $requete,
            $this->octets(
                $this->stockage->chemin($media->getStorageName()),
                $media->getMimeType(),
                $this->nomAffiche($media, $lecteur),
                $media->getSha256(),
            ),
        );
    }

    #[Route('/{id}/vignette', name: 'media_vignette', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function vignette(int $id, Request $requete): Response
    {
        $media = $this->catalogue->voir($id, $this->lecteur());
        $vignette = $media->getThumbnailName();

        if (null === $vignette) {
            // Pas de vignette : un PDF, ou une génération qui a échoué. `404` plutôt
            // qu'un substitut servi par l'API — c'est l'interface qui choisit son
            // substitut, elle sait dans quel contexte elle l'affiche.
            throw new NotFoundException();
        }

        return $this->repondre(
            $requete,
            $this->octets(
                $this->stockage->chemin($vignette, vignette: true),
                // Le type de la vignette suit celui de l'original : elle est produite
                // dans le même format pour ne pas aplatir une transparence.
                $media->getMimeType(),
                null,
                // Empreinte distincte de celle de l'original : les deux fichiers n'ont
                // pas les mêmes octets, et leur donner le même `ETag` ferait rendre
                // `304` à la vignette pour un original déjà en cache.
                'v'.$media->getSha256(),
            ),
        );
    }

    /**
     * Applique la revalidation `If-None-Match`, **et seulement ici**.
     *
     * Elle est appelée après que l'autorisation a été tranchée par
     * {@see CatalogueMedia::voir()} — c'est tout le sujet, et c'est écrit deux fois
     * exprès : voir {@see octets()}. Un `304` est une réponse, et la servir à qui vient
     * de perdre l'accès lui apprend que la ressource existe encore.
     */
    private function repondre(Request $requete, Response $reponse): Response
    {
        $reponse->isNotModified($requete);

        return $reponse;
    }

    #[Route('/{id}', name: 'media_supprimer', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function supprimer(int $id): Response
    {
        $this->bascule->refuserSiActive();

        $lecteur = $this->lecteur();
        $media = $this->catalogue->voir($id, $lecteur);

        $this->suppression->supprimer($media, $lecteur);

        return $this->charge(['supprime' => true, 'id' => $id]);
    }

    /**
     * Quart de tour d'une image, **dans le sens horaire** : `{"angle": 90|180|270}`.
     *
     * Rend la même charge que `GET /api/medias/{id}` : `width` et `height` y sont déjà
     * permutés, et `url` / `vignetteUrl` portent une nouvelle version (`?v=`) — c'est ce
     * changement d'URL qui fait relire l'image au navigateur, voir {@see AdresseDuMedia}.
     */
    #[Route('/{id}/pivoter', name: 'media_pivoter', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function pivoter(int $id, Request $requete): Response
    {
        $this->bascule->refuserSiActive();

        $lecteur = $this->lecteur();
        $media = $this->catalogue->voir($id, $lecteur);

        $corps = json_decode($requete->getContent(), true);
        $angle = \is_array($corps) ? ($corps['angle'] ?? null) : null;

        if (!\is_int($angle)) {
            throw new ValidationException(['angle' => 'L\'angle doit valoir 90, 180 ou 270 degrés (sens horaire).']);
        }

        $this->pivotement->pivoter($media, $angle, $lecteur);

        return $this->charge($this->normaliseur->normaliser($media, $lecteur));
    }

    /**
     * `access_control` ferme déjà `^/api` derrière `ROLE_USER` et
     * `JsonAuthenticationEntryPoint` rend le `401` du § 15.1. Cette garde reste parce
     * qu'une route qui sert des octets de photos de famille ne s'en remet pas entièrement
     * à une ligne de configuration qu'elle ne possède pas : si la règle disparaissait
     * d'un remaniement de `security.yaml`, elle rendrait `401` au lieu de servir le
     * fichier à un anonyme.
     *
     * `UnauthorizedHttpException` plutôt qu'une exception maison : le souscripteur du
     * noyau la traduit déjà en `authentification_requise`, avec la même enveloppe que
     * le point d'entrée.
     */
    private function lecteur(): LecteurMedia
    {
        $utilisateur = $this->getUser();

        if (!$utilisateur instanceof User) {
            throw new UnauthorizedHttpException('cookie', 'Authentification requise.');
        }

        return $this->lecteurs->depuisUtilisateur($utilisateur);
    }

    /**
     * Le plafond par compte, et non par IP : une famille derrière la même connexion
     * partage son adresse, et un plafond par IP punirait le second déposant.
     *
     * ## L'administrateur en est exempté — décision du commanditaire, 17 septembre 2026
     *
     * **Ce n'est pas un oubli, et ce n'est pas un principe extensible.** Le cas d'usage
     * qui l'a motivée est nommé : l'import d'un lot documenté de **76 pages d'actes
     * notariés** passe par le compte d'administration, et les 20 dépôts par heure du
     * § 1.9 le bloquaient **sur son propre arbre** pendant que l'import tournait — le
     * `429` ne tombait pas sur l'import, il tombait sur tout ce que le commanditaire
     * tentait de faire d'autre dans l'heure suivante. Le plafond, écrit contre un abus,
     * n'atteignait ici que le seul usage légitime qui en approche le volume.
     *
     * L'exemption est donc **bornée à `ROLE_ADMIN`** : ni « tout compte authentifié », ni
     * « le propriétaire du sujet ». Un membre ordinaire reste plafonné exactement comme
     * avant, et c'est lui que le § 1.9 protège — ce sont les membres de la famille qui
     * useront de l'application au quotidien, et eux dont un compte volé remplirait le
     * disque. Élargir cette condition, c'est supprimer le plafond.
     *
     * ## Ce qui borne encore un administrateur, et ce qui ne le borne plus
     *
     * À lire avant de supposer qu'un autre garde-fou prend le relais : **il n'en prend
     * aucun sur le nombre**. Vérifié sur `src/Module/Media/` le 17 septembre 2026 — le
     * module n'a **ni garde d'espace disque, ni quota par compte, ni quota global**. Le
     * limiteur était le seul frein au *nombre* de dépôts ; pour un administrateur, il
     * n'en reste plus.
     *
     * Ce qui subsiste ne borne que le *fichier*, un par un :
     *
     * - la **taille** — `ReglagesMediaInterface::tailleMaxOctets()` (20 Mio par défaut),
     *   doublée en amont par `upload_max_filesize`/`post_max_size` de `docker/php.ini` et
     *   le `client_max_body_size` de nginx ;
     * - la **surface** — `PurgeDesMetadonnees::PIXELS_MAX`, 32 000 000 pixels, qui garde
     *   la mémoire de GD et non le disque.
     *
     * Multiplier le premier par un nombre de dépôts désormais non borné donne la seule
     * mesure honnête du risque accepté ici. Si un quota d'espace apparaît un jour, c'est
     * ce paragraphe qu'il faut corriger en même temps.
     *
     * ## D'où vient le rôle
     *
     * De `LecteurMedia::$administrateur`, que {@see FabriqueDeLecteur} tient de
     * `Core\Entity\User::getRoles()` — la source de vérité du projet. Ne pas redemander
     * `isGranted('ROLE_ADMIN')` ici : ce serait une seconde lecture du rôle à côté de
     * celle qui décide déjà de la file de validation et des champs privilégiés du § 7.2,
     * et c'est exactement la divergence que {@see FabriqueDeLecteur} raconte avoir payée
     * sur le régime.
     */
    private function limiter(LecteurMedia $lecteur): void
    {
        if ($lecteur->administrateur) {
            return;
        }

        $limite = $this->limiteur->create((string) $lecteur->userId)->consume();

        if (!$limite->isAccepted()) {
            throw new TooManyRequestsException(max(1, $limite->getRetryAfter()->getTimestamp() - time()));
        }
    }

    /**
     * PHP refuse lui-même les transferts au-delà de `upload_max_filesize` et de
     * `post_max_size`, **avant** que la moindre ligne de ce module s'exécute. Sans cette
     * traduction, le front recevrait une réponse sans corps JSON sur exactement le cas
     * que l'utilisateur rencontre le plus : le fichier trop gros (§ 7.1).
     */
    private function verifierLeTransfert(UploadedFile $fichier): void
    {
        if ($fichier->isValid()) {
            return;
        }

        throw match ($fichier->getError()) {
            \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => new FichierTropVolumineuxException(
                (int) UploadedFile::getMaxFilesize(),
            ),
            default => new ValidationException(['fichier' => 'Le transfert du fichier a échoué.']),
        };
    }

    /**
     * Le nom d'origine n'est rendu qu'au déposant et aux administrateurs (§ 7.2). Le
     * `Content-Disposition` d'un tiers porte donc un nom neutre construit sur
     * l'identifiant — un en-tête est une porte de sortie comme une autre.
     */
    private function nomAffiche(Media $media, LecteurMedia $lecteur): string
    {
        $original = $media->getOriginalName();

        if (null !== $original
            && ($lecteur->administrateur || $media->getUploadedByUserId() === $lecteur->userId)) {
            return $original;
        }

        return 'media-'.(int) $media->getId().'.'.$this->stockage->extensionPour($media->getMimeType());
    }

    /**
     * Sert les octets, avec un `ETag` — et **après** que l'autorisation a été tranchée.
     *
     * ## L'ordre est la règle de sécurité, et il s'optimise mal
     *
     * Les appelants de cette méthode ont **déjà** appelé `CatalogueMedia::voir()`, qui
     * lève `404` si le lecteur n'a rien à voir ici. La revalidation d'`ETag` ne se fait
     * donc jamais avant le cercle, et c'est volontairement irréversible : **un `304` est
     * une réponse**. Le servir à quelqu'un qui vient de perdre l'accès — une personne
     * repassée en vivante, un membre sorti du cercle — lui apprendrait que la ressource
     * existe toujours, et que ses octets n'ont pas changé. Il reçoit `404`, quel que soit
     * son `If-None-Match`.
     *
     * La tentation inverse est réelle : comparer l'`ETag` d'abord coûterait une requête
     * de moins sur les vignettes d'une liste. C'est exactement le raccourci qu'un
     * profilage suggérera un jour, et `tests/Media/CacheDesOctetsTest.php` existe pour
     * le faire échouer.
     *
     * ## Pourquoi `private, no-cache` et non `no-store`
     *
     * Les octets d'un média sont **immuables** : le nom de stockage est tiré au hasard à
     * l'enregistrement et le fichier n'est jamais réécrit. Seule l'**autorisation**
     * bouge. En `no-store`, une fiche de dix preuves retéléchargeait ses dix vignettes à
     * chaque navigation. En `no-cache`, le navigateur garde les octets et revalide :
     * l'autorisation est rejouée à chaque fois — c'est ce que `no-cache` veut dire — et
     * le transfert n'a lieu que si l'empreinte a changé.
     *
     * `private` parce qu'un cache partagé ne doit jamais réutiliser une réponse dont le
     * contenu dépend du lecteur.
     *
     * L'`ETag` est le **`sha256` du fichier stocké**, pas un horodatage : c'est déjà
     * l'identité du contenu, il est stable entre deux redéploiements, et il ne fuit rien
     * — il n'est rendu que sur une ressource que le lecteur vient d'obtenir le droit de
     * lire. `SecurityHeadersSubscriber` laisse passer un `Cache-Control` posé ici dès
     * lors qu'un `ETag` est présent.
     */
    private function octets(string $chemin, string $mime, ?string $nom, string $empreinte): Response
    {
        if (!is_file($chemin)) {
            // La ligne existe, le fichier non : sauvegarde restaurée sans son volume
            // (§ 6.1 du modèle). `404`, comme pour tout ce qui ne peut pas être servi.
            throw new NotFoundException();
        }

        $reponse = new BinaryFileResponse($chemin);
        $reponse->headers->set('Content-Type', $mime);
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->setEtag($empreinte);
        $reponse->headers->set('Cache-Control', 'private, no-cache');

        $reponse->setContentDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            $nom ?? '',
            // Repli ASCII : l'en-tête ne transporte pas « Épinaille » tel quel, et un
            // nom entièrement non-ASCII rendrait un repli vide, donc invalide.
            'media.'.pathinfo($chemin, \PATHINFO_EXTENSION),
        );

        return $reponse;
    }

    /**
     * `Cache-Control: no-store` n'est pas posé ici : `SecurityHeadersSubscriber` le pose
     * sur toute réponse `/api` sans `ETag`. Le doubler donnerait deux endroits à corriger
     * le jour où la politique change, et c'est toujours le second qu'on oublie.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $charge
     */
    private function charge(array $charge, int $statut = Response::HTTP_OK): JsonResponse
    {
        return $this->json($charge, $statut);
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T
     */
    private function enumObligatoire(string $enum, mixed $valeur, string $champ): \BackedEnum
    {
        $lu = $this->enumFacultatif($enum, $valeur, $champ);

        if (null === $lu) {
            throw new ValidationException([$champ => 'Valeur obligatoire.']);
        }

        return $lu;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T|null
     */
    private function enumFacultatif(string $enum, mixed $valeur, string $champ): ?\BackedEnum
    {
        if (null === $valeur || '' === $valeur) {
            return null;
        }

        if (!\is_string($valeur)) {
            throw new ValidationException([$champ => 'Valeur attendue sous forme de chaîne.']);
        }

        // Les valeurs d'énumération ne sont ni traduites ni recasées (§ 1.2) : `actif`
        // est refusé s'il arrive en `Actif`, sinon deux écritures désigneraient la même
        // ligne et la comparaison de valeurs d'op divergerait.
        $lu = $enum::tryFrom($valeur);

        if (null === $lu) {
            throw new ValidationException([$champ => 'Valeur inconnue.']);
        }

        return $lu;
    }

    private function entier(mixed $valeur, string $champ): ?int
    {
        if (null === $valeur || '' === $valeur) {
            return null;
        }

        if (!\is_string($valeur) && !\is_int($valeur)) {
            throw new ValidationException([$champ => 'Entier attendu.']);
        }

        $entier = filter_var($valeur, \FILTER_VALIDATE_INT);

        if (!\is_int($entier) || $entier < 1) {
            throw new ValidationException([$champ => 'Entier positif attendu.']);
        }

        return $entier;
    }

    /**
     * Le texte libre d'un champ **multipart**, ou `null` s'il est vide.
     *
     * ## L'encodage est vérifié ici, et c'est un défaut du serveur qu'on corrige
     *
     * Une légende envoyée en Latin-1 — `0xC9 0x6C` pour « Él » — traversait ce
     * contrôleur sans être regardée, puis cassait chez PostgreSQL, qui n'accepte que
     * de l'UTF-8 : `invalid byte sequence for encoding "UTF8"`. L'API rendait **500**
     * (incidents `6ade9d62220a8fcd` puis `1c65c7044967e174`, reproduit le 17 septembre
     * 2026 sur la pile de développement).
     *
     * **Le 500 n'était pas la faute du client.** Un serveur qui reçoit une entrée qu'il
     * ne sait pas stocker doit la refuser en le disant ; s'effondrer dessus transforme
     * une erreur de saisie en panne, et une panne ne s'explique à personne — le
     * déposant voit « Erreur interne » sur une photo parfaitement valide, et rien ne
     * lui indique que c'est sa légende qui est en cause.
     *
     * ## Pourquoi ce garde-fou est au module Media et **pas** à un point commun
     *
     * Parce que c'est la seule entrée qui en manquait. Recensé sur toute l'API le
     * 17 septembre 2026, et mesuré route par route :
     *
     * - les **corps JSON** sont déjà couverts — `json_decode()` refuse par norme
     *   l'UTF-8 invalide et rend `null`, que les contrôleurs traduisent en `422`
     *   (mesuré sur `POST /api/session/mot-de-passe` : `donnees_invalides`) ;
     * - la **chaîne de requête** l'est aussi, incidemment : le terme de
     *   `GET /api/arbre/recherche` passe par la normalisation de clé de
     *   {@see \App\Module\Tree\Serializer\NormaliseurDeRequete::mots()}, qui écarte
     *   l'octet fautif (mesuré : `200`, aucun résultat) ;
     * - le **nom de fichier** client est assaini avant d'arriver ici (mesuré : stocké
     *   `acte-L?lise.jpg`, `201`) ;
     * - les **champs multipart** ne sont lus nulle part ailleurs : `request->get()`
     *   n'apparaît dans aucun autre module, et `UploadedFile` non plus — `POST
     *   /api/medias` est la seule route multipart de l'API.
     *
     * Un garde-fou global sur « toutes les entrées texte » n'aurait donc rien couvert
     * de plus, et aurait ajouté un second contrôle là où `json_decode()` en fait déjà
     * un. Si une deuxième route multipart apparaît un jour, c'est à ce moment-là que la
     * question d'un point commun se posera — et ce sera une décision à prendre, pas un
     * réflexe.
     *
     * ## On refuse, on ne répare pas
     *
     * `mb_check_encoding()` et non `iconv()` ni `mb_convert_encoding()` : deviner
     * l'encodage d'une légende pour la réécrire, c'est modifier le texte que quelqu'un
     * a saisi sans le lui dire, et se tromper en silence sur les rares cas ambigus. Le
     * client sait quel encodage il a employé ; le serveur, non. On lui rend donc le
     * champ fautif et la raison, et il renvoie de l'UTF-8.
     *
     * La vérification porte sur les **trois** champs libres — `caption`,
     * `brouillonRef`, `taken` — parce qu'ils finissent tous en colonne texte, et non
     * sur le seul qui a provoqué l'incident.
     */
    private function chaine(mixed $valeur, string $champ): ?string
    {
        if (!\is_string($valeur) || '' === trim($valeur)) {
            return null;
        }

        if (!mb_check_encoding($valeur, 'UTF-8')) {
            throw new ValidationException([$champ => 'Le texte doit être encodé en UTF-8.']);
        }

        return trim($valeur);
    }
}
