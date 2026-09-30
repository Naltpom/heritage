<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\Sex;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Tree\Entity\Citation;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Entity\Source;
use App\Module\Tree\Enum\CitationQuality;
use App\Module\Tree\Enum\NoteScope;
use App\Module\Tree\Enum\SourceKind;

/**
 * Le lien d'une citation — l'adresse de la pièce dans la visionneuse d'archives — se
 * pose aussi sur une citation **déjà validée**, par `modification_citation`.
 *
 * Sur l'arbre réel, parce que la valeur courante vient du vrai port : une clé `url`
 * absente de l'état de la citation ferait refuser la demande au dépôt.
 */
final class LienDeCitationParUneDemandeTest extends BaseDeDemandeSurArbreReel
{
    public function testUneDemandeValideePoseLeLienDeLaCitation(): void
    {
        $citationId = $this->poserUneCitation();

        $demande = $this->deposer($citationId, 'https://archives.sylvanie.example/ark:/12345/vue12');
        $resultat = $this->validation()->valider($demande, $this->admin());

        self::assertSame('validee', $resultat->resultat);

        $this->em()->clear();
        $citation = $this->em()->find(Citation::class, $citationId);
        self::assertInstanceOf(Citation::class, $citation);
        self::assertSame('https://archives.sylvanie.example/ark:/12345/vue12', $citation->getUrl());
    }

    public function testUnLienEnJavascriptEstRefuseAuDepot(): void
    {
        $citationId = $this->poserUneCitation();

        $this->expectException(DonneesInvalides::class);

        $this->deposer($citationId, 'javascript:alert(1)');
    }

    private function poserUneCitation(): int
    {
        $personne = new Person('Opale Sarriette');
        $personne->setGivenNames('Opale')->setSurname('Sarriette')->setSex(Sex::Feminin);
        $source = new Source('État civil de Sylvanie', SourceKind::Autre);

        $this->em()->persist($personne);
        $this->em()->persist($source);

        $citation = (new Citation($source, NoteScope::Naissance))
            ->setPerson($personne)
            ->setPage('AD Sylvanie, 5 MI 118, vue 12')
            ->setQuay(CitationQuality::ActeOuRegistre);

        $this->em()->persist($citation);
        $this->em()->flush();

        return (int) $citation->getId();
    }

    private function deposer(int $citationId, string $lien): ChangeRequest
    {
        return $this->deposerCorps([
            'kind' => 'modification_citation',
            'message' => 'Le lien de la visionneuse.',
            'soumettre' => true,
            'targetKind' => 'citation',
            'targetId' => $citationId,
            'ops' => [[
                'seq' => 1,
                'opKind' => 'champ',
                'subjectKind' => 'citation',
                'subjectId' => $citationId,
                'field' => 'url',
                'valueKind' => 'texte',
                'oldValue' => null,
                'newValue' => $lien,
                'baseVersion' => 1,
            ]],
        ]);
    }
}
