<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Service\SettingService;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Statistiques\Contract\NatureDuFait;
use App\Module\Statistiques\Contract\PersonneDuReleve;
use App\Module\Statistiques\Contract\PorteeDeLAppui;
use App\Module\Tree\Entity\Fact;
use App\Module\Tree\Enum\FactType;
use App\Module\Tree\Service\ReglagesDeLArbreParSettings;
use App\Module\Tree\Service\ReglePersonneVivante;
use App\Module\Tree\Service\VueDeLArbrePourStatistiques;

/**
 * L'adaptateur réel de `Statistiques\Contract\PortStatistiquesArbre`, sur une vraie base.
 *
 * Les tests du module statistique jouent le port par un relevé en mémoire ; c'est ici
 * qu'on prouve que la traduction lit bien les tables — les enfants d'une famille, une
 * citation avec sa portée, la racine des branches, l'état vivant calculé — et que le jeton
 * de cache bouge quand l'arbre bouge.
 */
final class PortDesStatistiquesTest extends ArbreWebTestCase
{
    private function port(): VueDeLArbrePourStatistiques
    {
        $reglages = self::getContainer()->get(SettingService::class);
        \assert($reglages instanceof SettingService);

        return new VueDeLArbrePourStatistiques($this->em(), new ReglePersonneVivante(), new ReglagesDeLArbreParSettings($reglages));
    }

    /** @return array<int, PersonneDuReleve> */
    private function personnesParId(): array
    {
        $parId = [];
        foreach ($this->port()->releve()->personnes as $personne) {
            $parId[$personne->id] = $personne;
        }

        return $parId;
    }

    public function testLeReleveEstCompletEtSansFiltre(): void
    {
        $releve = $this->port()->releve();

        self::assertCount(\count($this->personnes), $releve->personnes, 'Toutes les fiches, vivants hors cercle compris : le cercle s’applique plus loin.');
        self::assertCount(2, $releve->familles);
        self::assertSame($this->idDe('ego'), $releve->racineId, 'La racine des branches traverse.');

        $cousin = $this->personnesParId()[$this->idDe('cousin')];
        self::assertTrue($cousin->vivant, 'L’état vivant vient de la règle calculée du § 9.2.');
        self::assertSame(DateQualifier::Environ, $cousin->naissance->getQualifier(), 'Les dates traversent sans être aplaties.');
    }

    public function testUneFamilleTraverseAvecSesEnfants(): void
    {
        $aieux = null;
        foreach ($this->port()->releve()->familles as $famille) {
            if ($famille->id === $this->familles['aieux']->getId()) {
                $aieux = $famille;
            }
        }

        self::assertNotNull($aieux);
        self::assertSame($this->idDe('aieul'), $aieux->mariId);
        self::assertSame($this->idDe('aieule'), $aieux->epouseId);
        self::assertSame([$this->idDe('ego')], $aieux->enfants);
        self::assertSame(-3, $aieux->generation);
    }

    public function testUneCitationTraverseAvecSaPortee(): void
    {
        $appuis = $this->port()->releve()->appuis;

        self::assertCount(1, $appuis);
        self::assertSame($this->idDe('cousin'), $appuis[0]->personId);
        self::assertSame(PorteeDeLAppui::Naissance, $appuis[0]->portee);
    }

    public function testUneProfessionTraverseEtLeJetonBouge(): void
    {
        $avant = $this->port()->jeton();

        $profession = new Fact(FactType::Profession, $this->personnes['aieul']);
        $profession->setLabel('Tisserand')->setHappened(new UncertainDate('1930', DateQualifier::Annee, new \DateTimeImmutable('1930-01-01'), new \DateTimeImmutable('1930-12-31')));
        $this->em()->persist($profession);
        $this->em()->flush();

        // Un port neuf : celui d'avant a mémorisé son relevé pour la requête.
        $port = $this->port();

        self::assertNotSame($avant, $port->jeton(), 'Un fait ajouté change le jeton : le relevé en cache ne survit pas à l’écriture.');

        $faits = $port->releve()->faits;
        self::assertCount(1, $faits);
        self::assertSame(NatureDuFait::Profession, $faits[0]->nature);
        self::assertSame('Tisserand', $faits[0]->libelle);
        self::assertSame($this->idDe('aieul'), $faits[0]->personId);
    }
}
