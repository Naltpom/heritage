<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Service\AuditService;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use App\Module\Membership\Repository\MemberRepository;
use App\Module\Membership\Service\Port\PersonneDeLArbre;
use App\Module\Membership\Service\Port\PortArbre;
use App\Module\Membership\Service\Port\VueDeLArbre;
use App\Module\Noel\Contract\IdentiteDuCompte;
use App\Module\Noel\Contract\PortComptesPourNoel;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le côté `Membership` du contrat `Noel\Contract\PortComptesPourNoel`.
 *
 * Un compte né sur le site de Noël devient un membre **à rattacher**, exactement comme
 * une inscription sans fiche : même fabrique ({@see Member::aRattacher()}), même avis aux
 * administrateurs, même onglet « À rattacher » pour le relier à sa fiche — simplement
 * sans invitation (`invitation_id` est nullable).
 *
 * **Sauf correspondance certaine** ({@see ficheReconnue()}) : la fiche est alors posée et
 * le compte activé d'office, comme une invitation pré-liée confirmée — mais sans
 * invitation, sur la seule foi de l'identité déclarée. Décision du commanditaire
 * (2026-10-19), plus large que `MatchKind::activeLeCompte()` qui refuse d'activer une
 * recherche confirmée ; d'où les critères plus stricts que la recherche floue : même nom
 * normalisé, date au jour près — la fiche doit la porter —, une seule fiche, libre.
 *
 * L'identité rendue est la **déclaration** du compte quand il en a une ; sinon la fiche à
 * laquelle il est rattaché (son prénom, son nom, son année de naissance). L'arbre n'est
 * ouvert que si un compte en a besoin, et une seule fois.
 */
final class ComptesPourNoel implements PortComptesPourNoel
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MemberRepository $membres,
        private readonly NotificationDesAdministrateurs $notifications,
        private readonly PortArbre $arbre,
        private readonly RechercheDeRattachement $recherche,
        private readonly NormalisationDeNom $noms,
        private readonly ConsentementDeNaissance $consentements,
        private readonly AuditService $audit,
    ) {
    }

    public function declarerUnCompte(
        int $userId,
        string $email,
        string $nomDeNaissance,
        string $prenom,
        \DateTimeImmutable $naissance,
    ): bool {
        $fiche = $this->ficheReconnue($nomDeNaissance, $prenom, $naissance);

        if (null === $fiche) {
            $membre = Member::aRattacher($userId, $email, $nomDeNaissance, $prenom, $naissance, 'Inscription depuis le site du tirage de Noël (noel2026).');
            $this->em->persist($membre);
            $this->em->flush();

            $this->notifications->compteARattacher($membre);

            return false;
        }

        // Activation automatique : pas d'auteur, `activated_by_user_id` reste nul (§ 4.5).
        $membre = (new Member($userId, $email, $fiche->id, $fiche->libelle, MatchKind::RechercheConfirmee))->activer();
        $this->em->persist($membre);
        $this->consentements->archiverPourRattachement($fiche->id);
        $this->em->flush();

        $this->audit->enregistrer(
            AuditAction::Modification,
            Member::class,
            (string) $membre->getId(),
            null,
            [
                'status' => MemberStatus::Actif->value,
                'userId' => $userId,
                'matchKind' => MatchKind::RechercheConfirmee->value,
                'source' => 'noel2026',
            ],
        );
        $this->notifications->compteRattacheDOffice($membre);

        return true;
    }

    /**
     * La fiche que l'identité déclarée désigne **à coup sûr**, ou `null`.
     *
     * La recherche floue de l'inscription par invitation ({@see RechercheDeRattachement})
     * fait le gros du tri — vivante, nom proche, un prénom en commun, date compatible — ;
     * on y ajoute ce qu'une activation sans administrateur exige : le nom **identique**
     * une fois normalisé, une fiche datée **au jour** (`22.5.1949`, pas `1949`), un seul
     * candidat, et une fiche que personne n'a encore réclamée.
     */
    private function ficheReconnue(string $nom, string $prenom, \DateTimeImmutable $naissance): ?PersonneDeLArbre
    {
        $criteres = CriteresDeRattachement::depuisLeCorps([
            'surname' => $nom,
            'givenNames' => $prenom,
            'birthDate' => $naissance->format('Y-m-d'),
        ]);
        $nomNormalise = $this->noms->normaliser($nom);

        $candidats = array_values(array_filter(
            $this->recherche->chercher($this->vue(), $criteres, 2),
            fn (PersonneDeLArbre $p): bool => $this->noms->normaliser($p->nom) === $nomNormalise
                && 1 === preg_match('/^\d{1,2}\.\d{1,2}\.\d{4}$/', trim($p->etiquetteNaissance)),
        ));

        if (1 !== \count($candidats) || null !== $this->membres->pourPersonne($candidats[0]->id)) {
            return null;
        }

        return $candidats[0];
    }

    public function identites(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        /** @var list<Member> $membres */
        $membres = $this->membres->findBy(['userId' => $userIds]);
        $vue = null;
        $identites = [];

        foreach ($membres as $membre) {
            $prenom = $membre->getDeclaredGivenNames();
            $nom = $membre->getDeclaredSurname();
            $naissance = $membre->getDeclaredBirthDate();
            $annee = null;
            $personId = $membre->getPersonId();

            if (null !== $personId && (null === $prenom || null === $nom || null === $naissance)) {
                $vue ??= $this->vue();
                $personne = $vue->personne($personId);

                if (null !== $personne) {
                    $prenom ??= trim(explode(' ', trim($personne->prenoms))[0]);
                    $nom ??= $personne->nom;
                    $annee = $personne->anneeNaissance;
                } elseif (null !== $membre->getPersonLabel()) {
                    $mots = explode(' ', trim($membre->getPersonLabel()), 2);
                    $prenom ??= $mots[0];
                    $nom ??= $mots[1] ?? '';
                }
            }

            if (null === $prenom || '' === $prenom) {
                continue;
            }

            $identites[$membre->getUserId()] = new IdentiteDuCompte(
                $membre->getUserId(),
                $prenom,
                $nom ?? '',
                $naissance,
                $annee,
                $personId,
            );
        }

        return $identites;
    }

    private function vue(): VueDeLArbre
    {
        try {
            return $this->arbre->vue();
        } catch (\Throwable) {
            return VueDeLArbre::vide();
        }
    }
}
