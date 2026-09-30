<?php

declare(strict_types=1);

namespace App\Module\Tree\Controller;

use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Tree\Service\ServiceDesSources;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Les sources, côté administration — ADR 0012, § 10.7 du contrat d'API.
 *
 * ```
 * GET  /api/administration/sources?q=&page=&parPage=   liste paginée, par titre, avec citationCount
 * POST /api/administration/sources                      créer                              201 · 422
 * GET  /api/administration/sources/{id}                 détail                             200 · 404
 * PUT  /api/administration/sources/{id}                 remplacer la notice                200 · 404 · 422
 * ```
 *
 * `access_control` met déjà tout `^/api/administration` derrière `ROLE_ADMIN` ;
 * l'attribut le redit, pour que la protection suive le contrôleur si on le déplace.
 * Chaque écriture est refusée sous la bascule « voir comme un membre ».
 *
 * Pas de `DELETE` : voir l'en-tête de {@see ServiceDesSources}.
 */
#[Route('/api/administration/sources')]
#[IsGranted('ROLE_ADMIN')]
final class SourcesAdministrationController extends AbstractController
{
    public function __construct(
        private readonly ServiceDesSources $sources,
        private readonly BasculeVoirComme $bascule,
    ) {
    }

    #[Route('', name: 'tree_admin_sources', methods: ['GET'])]
    public function lister(Request $requete): JsonResponse
    {
        return new JsonResponse($this->sources->lister(
            $requete->query->getString('q') ?: null,
            $requete->query->getInt('page', 1),
            $requete->query->getInt('parPage', ServiceDesSources::PAR_PAGE_DEFAUT),
        ));
    }

    #[Route('', name: 'tree_admin_source_creer', methods: ['POST'])]
    public function creer(Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();

        return new JsonResponse(
            $this->sources->normaliser($this->sources->creer($this->corps($requete))),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/{id}', name: 'tree_admin_source', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id): JsonResponse
    {
        return new JsonResponse($this->sources->normaliser($this->sources->trouver($id)));
    }

    #[Route('/{id}', name: 'tree_admin_source_modifier', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function modifier(int $id, Request $requete): JsonResponse
    {
        $this->bascule->refuserSiActive();
        $source = $this->sources->trouver($id);

        return new JsonResponse($this->sources->normaliser($this->sources->modifier($source, $this->corps($requete))));
    }

    /** @return array<mixed> */
    private function corps(Request $requete): array
    {
        $corps = json_decode($requete->getContent(), true);

        if (!\is_array($corps)) {
            throw new ValidationException(['corps' => 'Un objet JSON est attendu.']);
        }

        return $corps;
    }
}
