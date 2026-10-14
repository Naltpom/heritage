<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Service\CleDeRecherche;
use App\Module\Tree\Entity\Source;
use App\Module\Tree\Enum\SourceKind;
use App\Module\Tree\Repository\SourceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les sources, gérées par l'administration — ADR 0012.
 *
 * Une source est une **notice bibliographique** : « État civil de Paris », « Fichier des
 * personnes décédées ». Elle ne dit rien d'une personne ; ce qu'elle établit passe par une
 * citation, et une citation passe par une demande (ADR 0004, `ajout_citation`). Créer ou
 * corriger la notice ne passe donc **pas** par la file : c'est un geste d'administration,
 * tracé comme les autres par le journal mécanique de `Core` (`AuditDoctrineSubscriber`).
 *
 * Trois choses que ce service ne fait pas, délibérément :
 *
 * - **il ne pose jamais `gedcom_xref`.** Cette colonne porte la référence des sources
 *   venues du fichier d'origine (`@S500001@`) : une source créée ici n'en a pas, et lui
 *   en inventer une ferait passer pour une notice du fichier ce qui n'en est pas une.
 *   `null` ne percute pas la contrainte d'unicité, et la référence d'une source
 *   existante n'est jamais réécrite — c'est son identifiant stable. (Elle était aussi
 *   la clé du ré-import GEDCOM, qui retrouvait une source par son xref et l'écrasait ;
 *   l'import a été supprimé, ADR 0018.)
 * - **il ne supprime pas.** `tree_citation.source_id` est en `RESTRICT`, mais une source
 *   jamais citée peut encore être désignée par une demande `ajout_citation` en attente —
 *   que `Tree` ne voit pas, et dont la validation tomberait en `500`
 *   (`EcritureDeLArbrePourProposal`, « la source a disparu pendant la validation ») ;
 * - **il ne pagine pas en SQL** : voir {@see SourceRepository::toutesParTitre()}.
 */
final class ServiceDesSources
{
    public const int PAR_PAGE_MAX = 100;

    public const int PAR_PAGE_DEFAUT = 50;

    /** Les longueurs des colonnes (`tree_source`), et un plafond pour les `TEXT`. */
    private const int LONGUEUR_TITRE = 255;
    private const int LONGUEUR_AUTEUR = 255;
    private const int LONGUEUR_TYPE_DE_MEDIA = 60;
    private const int LONGUEUR_TEXTE = 10_000;

    public function __construct(
        private readonly SourceRepository $sources,
        private readonly EntityManagerInterface $em,
        private readonly CleDeRecherche $cle,
    ) {
    }

    /**
     * @return array{elements: list<array<string, mixed>>, total: int, page: int, parPage: int}
     */
    public function lister(?string $recherche, int $page, int $parPage): array
    {
        $page = max(1, $page);
        $parPage = min(self::PAR_PAGE_MAX, max(1, $parPage));

        $sources = $this->sources->toutesParTitre();

        $terme = null === $recherche ? '' : $this->cle->replier($recherche);
        if ('' !== $terme) {
            $sources = array_values(array_filter(
                $sources,
                fn (Source $source): bool => str_contains($this->cle->replier($source->getTitle()), $terme),
            ));
        }

        $citations = $this->sources->nombresDeCitations();

        return [
            'elements' => array_map(
                fn (Source $source): array => $this->normaliser($source, $citations[(int) $source->getId()] ?? 0),
                \array_slice($sources, ($page - 1) * $parPage, $parPage),
            ),
            'total' => \count($sources),
            'page' => $page,
            'parPage' => $parPage,
        ];
    }

    /** @throws NotFoundException */
    public function trouver(int $id): Source
    {
        return $this->sources->find($id) ?? throw new NotFoundException('Cette source n’existe pas.');
    }

    /**
     * @param array<mixed> $corps
     *
     * @throws ValidationException
     */
    public function creer(array $corps): Source
    {
        $valeurs = $this->lire($corps);

        $source = new Source($valeurs['title'], $valeurs['sourceKind']);
        $this->appliquer($source, $valeurs);

        $this->em->persist($source);
        $this->em->flush();

        return $source;
    }

    /**
     * Remplacement complet de la notice, comme tout `PUT` : un champ facultatif absent
     * est vidé. `gedcomXref` n'est ni lu ni touché.
     *
     * @param array<mixed> $corps
     *
     * @throws ValidationException
     */
    public function modifier(Source $source, array $corps): Source
    {
        $valeurs = $this->lire($corps);

        $source
            ->setTitle($valeurs['title'])
            ->setSourceKind($valeurs['sourceKind']);
        $this->appliquer($source, $valeurs);
        $source->toucher();

        $this->em->flush();

        return $source;
    }

    /** @return array<string, mixed> */
    public function normaliser(Source $source, ?int $nombreDeCitations = null): array
    {
        return [
            'id' => $source->getId(),
            'gedcomXref' => $source->getGedcomXref(),
            'title' => $source->getTitle(),
            'author' => $source->getAuthor(),
            'publication' => $source->getPublication(),
            'text' => $source->getText(),
            'note' => $source->getNote(),
            'mediaType' => $source->getMediaType(),
            'sourceKind' => $source->getSourceKind()->value,
            'sourceKindLabel' => $source->getSourceKind()->label(),
            'citationCount' => $nombreDeCitations ?? $this->sources->nombreDeCitations($source),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $source->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Lit et valide le corps. Toutes les fautes sont rendues d'un coup, champ par champ.
     *
     * @param array<mixed> $corps
     *
     * @return array{title: string, sourceKind: SourceKind, author: ?string, publication: ?string, text: ?string, note: ?string, mediaType: ?string}
     *
     * @throws ValidationException
     */
    private function lire(array $corps): array
    {
        $fautes = [];

        $titre = $this->texte($corps, 'title', self::LONGUEUR_TITRE, $fautes);
        if (null === $titre && !isset($fautes['title'])) {
            $fautes['title'] = 'Le titre est obligatoire.';
        }

        $brut = $corps['sourceKind'] ?? null;
        $genre = \is_string($brut) ? SourceKind::tryFrom($brut) : null;
        if (null === $genre) {
            $fautes['sourceKind'] = \sprintf(
                'Le type de source est l’un de : %s.',
                implode(', ', array_map(static fn (SourceKind $k): string => $k->value, SourceKind::cases())),
            );
        }

        $valeurs = [
            'author' => $this->texte($corps, 'author', self::LONGUEUR_AUTEUR, $fautes),
            'publication' => $this->texte($corps, 'publication', self::LONGUEUR_TEXTE, $fautes),
            'text' => $this->texte($corps, 'text', self::LONGUEUR_TEXTE, $fautes),
            'note' => $this->texte($corps, 'note', self::LONGUEUR_TEXTE, $fautes),
            'mediaType' => $this->texte($corps, 'mediaType', self::LONGUEUR_TYPE_DE_MEDIA, $fautes),
        ];

        if ([] !== $fautes || null === $titre || null === $genre) {
            throw new ValidationException($fautes);
        }

        return ['title' => $titre, 'sourceKind' => $genre] + $valeurs;
    }

    /**
     * Un champ texte facultatif : absent, `null` ou blanc valent `null` ; sinon une chaîne
     * rognée, d'au plus `$longueur` caractères.
     *
     * @param array<mixed>          $corps
     * @param array<string, string> $fautes
     */
    private function texte(array $corps, string $champ, int $longueur, array &$fautes): ?string
    {
        $valeur = $corps[$champ] ?? null;
        if (null === $valeur) {
            return null;
        }
        if (!\is_string($valeur)) {
            $fautes[$champ] = 'Une chaîne de caractères est attendue.';

            return null;
        }

        $valeur = trim($valeur);
        if ('' === $valeur) {
            return null;
        }
        if (mb_strlen($valeur, 'UTF-8') > $longueur) {
            $fautes[$champ] = \sprintf('%d caractères au plus.', $longueur);

            return null;
        }

        return $valeur;
    }

    /**
     * @param array{author: ?string, publication: ?string, text: ?string, note: ?string, mediaType: ?string} $valeurs
     */
    private function appliquer(Source $source, array $valeurs): void
    {
        $source
            ->setAuthor($valeurs['author'])
            ->setPublication($valeurs['publication'])
            ->setText($valeurs['text'])
            ->setNote($valeurs['note'])
            ->setMediaType($valeurs['mediaType']);
    }
}
