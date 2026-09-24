<?php

declare(strict_types=1);

namespace App\Module\Assistant\Controller;

use App\Module\Assistant\Contract\AccesAuModele;
use App\Module\Assistant\Exception\AssistantIndisponible;
use App\Module\Assistant\Service\AnalyseParLAssistant;
use App\Module\Assistant\Service\DepotDepuisLAnalyse;
use App\Module\Assistant\Service\DocumentJoint;
use App\Module\Assistant\Service\EntreeDAnalyse;
use App\Module\Core\Contract\AnnuaireDesMembresInterface;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\Regime;
use App\Module\Core\Exception\ForbiddenException;
use App\Module\Core\Exception\TooManyRequestsException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\Service\BasculeVoirComme;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Proposer avec l'assistant » — deux routes, et **aucune n'écrit dans l'arbre**.
 *
 * | Route | Ce qu'elle fait |
 * |---|---|
 * | `POST /api/assistant/analyses` | lit texte et documents, fait tourner le modèle, rend des demandes **prêtes à déposer** — sans rien déposer |
 * | `POST /api/assistant/analyses/{analyseId}/demandes/{cle}` | dépose **une** de ces demandes, au clic du membre, par `DepotDeDemande` |
 *
 * ── Les contrôles, dans l'ordre, et pourquoi cet ordre ───────────────────────────
 *
 * 1. **`409 vue_comme_membre_active`** — un administrateur sous la bascule « voir comme
 *    un membre » n'écrit pas, et l'analyse est le premier geste d'une écriture : la même
 *    règle que `POST /api/demandes`, par la même méthode ({@see BasculeVoirComme::refuserSiActive()}).
 * 2. **`403 regime_en_attente`** — un compte sans rattachement actif ne contribue pas.
 *    Le régime est **lu** chez `Core` (`CercleDeVisibiliteInterface::regime()`), jamais
 *    déduit du statut du compte : c'est le contrôle de `Proposal\Service\FabriqueDeLecteur`.
 * 3. **`503 assistant_indisponible`** — pas de clé. Avant le quota : une installation
 *    sans clé ne doit pas consommer les analyses du jour de ses membres.
 * 4. **`422`** — l'entrée. Avant le quota, pour la même raison : un fichier refusé n'a
 *    rien coûté.
 * 5. **`429`** — le quota, consommé en dernier, juste avant l'appel qui coûte.
 *
 * Le dépôt reprend 1 et 2, pas 3 (il n'appelle pas le modèle) ni 5 (« pas de quota sur
 * le dépôt »).
 */
#[Route('/api/assistant')]
final class AssistantController extends AbstractController
{
    /** Le contrat de l'assistant : 0 à 8 000 caractères. */
    private const int TEXTE_MAXIMAL = 8000;

    /** 0 à 6 documents par analyse. */
    private const int DOCUMENTS_MAXIMAUX = 6;

    /** 10 Mio par document : ces octets partent entiers vers le modèle, à chaque tour. */
    private const int TAILLE_MAXIMALE = 10 * 1024 * 1024;

    /**
     * Tous documents confondus. Le trajet entier est borné à 25 Mo par requête
     * (nginx de l'hôte et du front, `post_max_size`), et le modèle refuse au-delà
     * d'environ 20 Mo de documents en ligne : mieux vaut un 422 qui le dit qu'un
     * 502 venu de Google. Le front allège les photos avant l'envoi.
     */
    private const int TAILLE_TOTALE_MAXIMALE = 18 * 1024 * 1024;

    public function __construct(
        private readonly BasculeVoirComme $bascule,
        private readonly CercleDeVisibiliteInterface $cercle,
        private readonly Security $securite,
        private readonly AnnuaireDesMembresInterface $annuaire,
        /**
         * 20 analyses par 24 heures glissantes et par compte. Déclaré dans
         * `config/services/assistant.yaml`, sur le modèle de `media.limiteur_televersement`.
         */
        #[Autowire(service: 'assistant.limiteur_analyses')]
        private readonly RateLimiterFactoryInterface $limiteur,
    ) {
    }

    #[Route('/analyses', name: 'assistant_analyser', methods: ['POST'])]
    public function analyser(Request $requete, AccesAuModele $acces, AnalyseParLAssistant $analyse): JsonResponse
    {
        $userId = $this->verifierQuIlPeutContribuer();

        if (!$acces->configure()) {
            throw new AssistantIndisponible();
        }

        $entree = $this->lireLEntree($requete, $userId);

        // **Tout compte est compté, administrateur compris** — à la différence des
        // téléversements. Chaque analyse est un appel payant chez un tiers, qui porte des
        // documents entiers ; l'exemption du dépôt en volume ne vaut pas ici.
        $limite = $this->limiteur->create((string) $userId)->consume();

        if (!$limite->isAccepted()) {
            throw new TooManyRequestsException(
                max(1, $limite->getRetryAfter()->getTimestamp() - time()),
                'Vous avez atteint les 20 analyses de la journée. Le formulaire reste à votre disposition.',
            );
        }

        return $this->charge($analyse->analyser($entree, $userId, $limite->getRemainingTokens()));
    }

    #[Route(
        '/analyses/{analyseId}/demandes/{cle}',
        name: 'assistant_deposer',
        requirements: ['analyseId' => '[a-f0-9]{32}', 'cle' => 'd[1-9]\d{0,2}'],
        methods: ['POST'],
    )]
    public function deposer(string $analyseId, string $cle, Request $requete, DepotDepuisLAnalyse $depot): JsonResponse
    {
        $userId = $this->verifierQuIlPeutContribuer();

        return $this->charge($depot->deposer($userId, $analyseId, $cle, $this->message($requete)), 201);
    }

    /**
     * Les deux refus communs aux deux routes. Rend l'identifiant du compte.
     */
    private function verifierQuIlPeutContribuer(): int
    {
        $this->bascule->refuserSiActive();

        $utilisateur = $this->securite->getUser();
        $userId = $utilisateur instanceof User ? $utilisateur->getId() : null;

        if (null === $userId) {
            // Hors d'atteinte en pratique — `access_control` ferme `^/api` —, mais un
            // contrôle qui suppose la configuration juste n'en est pas un.
            throw new ForbiddenException();
        }

        if (Regime::Aucun === $this->cercle->regime()) {
            throw ForbiddenException::regimeEnAttente();
        }

        return $userId;
    }

    private function lireLEntree(Request $requete, int $userId): EntreeDAnalyse
    {
        $texte = $requete->request->get('texte');

        if (null !== $texte && !\is_string($texte)) {
            throw new ValidationException(['texte' => 'Le texte est une chaîne.']);
        }

        $texte ??= '';

        if (mb_strlen($texte) > self::TEXTE_MAXIMAL) {
            throw new ValidationException(['texte' => \sprintf('Le texte dépasse %d caractères.', self::TEXTE_MAXIMAL)]);
        }

        $documents = $this->lireLesDocuments($requete);

        if ('' === trim($texte) && [] === $documents) {
            throw new ValidationException(['texte' => 'Écrivez quelques mots ou joignez au moins un document.']);
        }

        return new EntreeDAnalyse($texte, $documents, $this->personneDeContexte($requete), $this->annuaire->personneDuCompte($userId));
    }

    /**
     * @return list<DocumentJoint>
     */
    private function lireLesDocuments(Request $requete): array
    {
        $brut = $requete->files->get('fichiers');
        $fichiers = match (true) {
            null === $brut => [],
            $brut instanceof UploadedFile => [$brut],
            \is_array($brut) => array_values($brut),
            default => throw new ValidationException(['fichiers' => 'Les documents sont envoyés sous « fichiers[] ».']),
        };

        if (\count($fichiers) > self::DOCUMENTS_MAXIMAUX) {
            throw new ValidationException(['fichiers' => \sprintf('%d documents au plus par analyse.', self::DOCUMENTS_MAXIMAUX)]);
        }

        $documents = [];
        $total = 0;

        foreach ($fichiers as $fichier) {
            if (!$fichier instanceof UploadedFile) {
                throw new ValidationException(['fichiers' => 'Un des documents n’a pas été reçu.']);
            }

            $nom = $fichier->getClientOriginalName();

            if (!$fichier->isValid()) {
                throw new ValidationException(['fichiers' => \in_array($fichier->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                    ? \sprintf('« %s » dépasse la taille admise (10 Mo).', $nom)
                    : \sprintf('Le transfert de « %s » a échoué.', $nom)]);
            }

            if ($fichier->getSize() > self::TAILLE_MAXIMALE) {
                throw new ValidationException(['fichiers' => \sprintf('« %s » dépasse 10 Mo.', $nom)]);
            }

            $total += (int) $fichier->getSize();
            if ($total > self::TAILLE_TOTALE_MAXIMALE) {
                throw new ValidationException(['fichiers' => 'Les documents dépassent 18 Mo en tout : retirez-en un ou envoyez des photos moins lourdes.']);
            }
            $octets = (string) file_get_contents($fichier->getPathname());
            $type = self::typeRenifle($octets);

            if (null === $type) {
                throw new ValidationException(['fichiers' => \sprintf('« %s » n’est ni une image (JPEG, PNG, WebP, HEIC) ni un PDF.', $nom)]);
            }

            $documents[] = new DocumentJoint($type, $octets, $nom);
        }

        return $documents;
    }

    /**
     * Le type **reniflé sur les octets**, jamais celui qu'annonce le navigateur : même
     * règle que le module `Media` (`DetecteurDeType`), recopiée plutôt qu'importée — la
     * frontière des modules l'interdit, et ce sont quatre signatures.
     *
     * HEIC s'ajoute ici parce que c'est le format natif des photos d'iPhone — « Prendre en
     * photo » un acte depuis un téléphone le produit — et que Gemini le lit.
     */
    private static function typeRenifle(string $octets): ?string
    {
        $entete = substr($octets, 0, 16);

        return match (true) {
            str_starts_with($entete, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($entete, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($entete, 'RIFF') && 'WEBP' === substr($entete, 8, 4) => 'image/webp',
            str_starts_with($entete, '%PDF-') => 'application/pdf',
            'ftyp' === substr($entete, 4, 4) && \in_array(substr($entete, 8, 4), ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis'], true) => 'image/heic',
            'ftyp' === substr($entete, 4, 4) && \in_array(substr($entete, 8, 4), ['mif1', 'msf1', 'heif'], true) => 'image/heif',
            default => null,
        };
    }

    private function personneDeContexte(Request $requete): ?int
    {
        $brut = $requete->request->get('personId');

        if (null === $brut || '' === $brut) {
            return null;
        }

        if (!\is_string($brut) || 1 !== preg_match('/^\d{1,9}$/', $brut)) {
            throw new ValidationException(['personId' => 'L’identifiant de la fiche est un entier.']);
        }

        return (int) $brut;
    }

    private function message(Request $requete): ?string
    {
        $brut = $requete->getContent();

        if ('' === trim($brut)) {
            return null;
        }

        try {
            $corps = json_decode($brut, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException(['corps' => 'Le corps de la requête n’est pas du JSON valide.']);
        }

        $message = \is_array($corps) ? ($corps['message'] ?? null) : null;

        if (null !== $message && !\is_string($message)) {
            throw new ValidationException(['message' => 'Le mot au demandeur est une chaîne.']);
        }

        return $message;
    }

    /** @param array<string, mixed> $charge */
    private function charge(array $charge, int $statut = 200): JsonResponse
    {
        $reponse = new JsonResponse($charge, $statut);
        // Une analyse cite des personnes et des documents du membre : aucun cache
        // intermédiaire ne doit la garder.
        $reponse->headers->set('Cache-Control', 'no-store');

        return $reponse;
    }
}
