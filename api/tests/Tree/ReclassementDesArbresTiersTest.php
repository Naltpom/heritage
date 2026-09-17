<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Tree\Entity\Citation;
use App\Module\Tree\Entity\Source;
use App\Module\Tree\Enum\NoteScope;
use App\Module\Tree\Enum\SourceKind;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * `heritage:reclasser-les-arbres-tiers` — la seule pièce de ce lot qui écrit.
 *
 * ## Pourquoi elle mérite ses propres tests
 *
 * Tout le reste du lot est du **rendu** : le masquage des adresses et le marquage se
 * défont en rechargeant une page. Celle-ci change `tree_source.source_kind` en base, sur
 * la vraie, et c'est un administrateur qui la lancera à la main sur 483 citations.
 *
 * Deux propriétés décident donc de tout, et elles sont vérifiées ici :
 *
 * 1. **elle ne fait rien sans qu'on le lui demande** — la simulation est le défaut, et
 *    l'option nomme l'écriture (`--ecrire`) et non son absence, pour qu'on ne puisse pas
 *    écrire en oubliant un drapeau ;
 * 2. **elle ne reclasse que ce qu'elle peut prouver** — une adresse d'arbre en ligne,
 *    affichée dans le tableau avant que quoi que ce soit ne bouge. Reconnaître une source
 *    à son titre aurait vieilli au premier import suivant.
 */
final class ReclassementDesArbresTiersTest extends ArbreWebTestCase
{
    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **LA SIMULATION EST LE DÉFAUT** : sans `--ecrire`, rien ne bouge.
     */
    public function testSansEcrireElleNeChangeRien(): void
    {
        $source = $this->poserUneSource(SourceKind::Autre, 'https://sites-cf.mhcache.com/site-42/notre-famille');

        $sortie = $this->lancer();

        self::assertStringContainsString('Rien n’a été écrit', $sortie);
        self::assertStringContainsString('--ecrire', $sortie, 'Elle dit comment l’appliquer.');

        $this->em()->clear();
        self::assertSame(SourceKind::Autre, $this->relire($source)->getSourceKind());
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **LE CAS RÉEL** : un site familial hébergé par MyHeritage est un arbre en ligne.
     *
     * « CADIC-BROSSARD » et « Site Web Famille Destombes » sont classés `autre` dans ce
     * fonds et pèsent quarante et une citations. Ils vivent sur `sites-cf.mhcache.com`, le
     * cache de sites de MyHeritage — c'est cette adresse, et non leur titre, qui les
     * désigne.
     */
    public function testUnSiteFamilialHebergeParMyHeritageEstReclasse(): void
    {
        $source = $this->poserUneSource(SourceKind::Autre, 'https://sites-cf.mhcache.com/site-42/notre-famille');

        $sortie = $this->lancer(ecrire: true);

        self::assertStringContainsString('1 source(s) reclassée(s)', $sortie);

        $this->em()->clear();
        self::assertSame(SourceKind::ArbreTiers, $this->relire($source)->getSourceKind());
    }

    /**
     * L'adresse qui justifie le reclassement est **montrée**, source par source.
     *
     * Un reclassement en masse qu'on ne peut pas justifier ligne à ligne n'est pas un
     * reclassement, c'est une retouche. L'administrateur doit pouvoir lire pourquoi avant
     * de confirmer — et, s'il n'est pas d'accord sur une ligne, ne pas lancer.
     */
    public function testElleMontreLAdresseQuiJustifieChaqueReclassement(): void
    {
        $this->poserUneSource(SourceKind::Autre, 'https://sites-cf.mhcache.com/site-42/notre-famille');

        $sortie = $this->lancer();

        self::assertStringContainsString('sites-cf.mhcache.com', $sortie);
        self::assertStringContainsString('adresse de fiche d’arbre qui le prouve', $sortie);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **UNE SOURCE D'ARCHIVES N'EST PAS TOUCHÉE**, même si elle porte une adresse.
     *
     * C'est le pendant du masquage : la règle vise les domaines d'arbres, pas le
     * protocole. Une source qui donne le point d'entrée d'un registre numérisé est
     * exactement ce qu'on veut garder — et voir reclassée en « arbre d'un autre
     * utilisateur » serait un contresens que personne ne rattraperait.
     */
    public function testUneSourceDArchivesNEstPasReclassee(): void
    {
        $source = $this->poserUneSource(
            SourceKind::ActeEtatCivil,
            'Point d’entrée : https://archives.dordogne.fr/archives-numerisees/genealogie',
        );

        $sortie = $this->lancer(ecrire: true);

        self::assertStringContainsString('Aucune source à reclasser', $sortie);

        $this->em()->clear();
        self::assertSame(SourceKind::ActeEtatCivil, $this->relire($source)->getSourceKind());
    }

    /**
     * ════════════════════════════════════════════════════════════════════════════
     * **UN RELEVÉ PUBLIÉ PAR MYHERITAGE N'EST PAS RECLASSÉ** — le piège du fonds.
     *
     * « Recensement français de 1931 » porte une adresse `myheritage.fr`, comme l'arbre d'un
     * inconnu. Mais son chemin dit `/research/collection-…` : c'est un **relevé indexé**,
     * qui vaut ce que vaut un index, et **53 des 109 adresses du fonds sont de cette
     * forme** — le plus gros groupe.
     *
     * Le reclasser écrirait une faussete en base et ferait tomber d'un cran la confiance de
     * huit citations qui ne le méritent pas. C'est exactement le genre de donnée fausse
     * entrée par un outil que ce lot répare ; on ne la rejoue pas dans l'autre sens.
     */
    public function testUnReleveIndexePublieParMyHeritageNEstPasReclasse(): void
    {
        $source = $this->poserUneSource(
            SourceKind::Recensement,
            'https://www.myheritage.fr/research/collection-20396/recensement-francais-de-1931?s=1667972',
        );

        $sortie = $this->lancer(ecrire: true);

        self::assertStringContainsString('Aucune source à reclasser', $sortie);

        $this->em()->clear();
        self::assertSame(SourceKind::Recensement, $this->relire($source)->getSourceKind(), 'Un recensement reste un recensement.');
    }

    /** Une source sans la moindre adresse ne se reclasse pas non plus : rien ne le prouve. */
    public function testUneSourceSansAdresseNEstPasReclassee(): void
    {
        $source = $this->poserUneSource(SourceKind::Autre, 'Geneanet, arbre de quelqu’un');

        $this->lancer(ecrire: true);

        $this->em()->clear();
        self::assertSame(SourceKind::Autre, $this->relire($source)->getSourceKind(), 'Un titre n’est pas une preuve : seule l’adresse en est une.');
    }

    /**
     * **Idempotente** : rejouée, elle ne trouve plus rien.
     *
     * C'est ce qui la rend sûre à lancer quand on doute de l'état de la colonne, et c'est
     * la propriété que sa voisine `heritage:recalculer-les-cles-de-recherche` revendique
     * pour la même raison.
     */
    public function testRejoueeElleNeTrouvePlusRien(): void
    {
        $this->poserUneSource(SourceKind::Autre, 'https://www.myheritage.fr/profile-AB12CD34-1000222/quelquun');

        $this->lancer(ecrire: true);
        $this->em()->clear();

        self::assertStringContainsString('Aucune source à reclasser', $this->lancer(ecrire: true));
    }

    /**
     * Elle compte les citations emportées, et c'est ce chiffre qui décide.
     *
     * « 1 source » ne dit rien ; « 40 citations » dit ce que l'écran va changer. C'est le
     * même raisonnement que le total de descendance d'une suppression : le nombre qui
     * compte n'est pas celui des lignes touchées mais celui de ce qu'elles portent.
     */
    public function testElleCompteLesCitationsEmportees(): void
    {
        $source = $this->poserUneSource(SourceKind::Autre, 'https://www.myheritage.fr/profile-AB12CD34-1000222/quelquun');

        $em = $this->em();
        foreach ([1, 2, 3] as $rang) {
            $em->persist((new Citation($source, NoteScope::Generale))
                ->setPerson($this->personnes['aieule'])
                ->setPage('vue '.$rang));
        }
        $em->flush();

        self::assertStringContainsString('3 citation(s)', $this->lancer());
    }

    // ────────────────────────────────────────────────────────────── le gréement

    /**
     * Une source portant l'adresse dans sa **note**, comme le GEDCOM les range.
     *
     * Les six adresses d'archives de ce fonds y sont toutes, et c'est aussi là qu'une
     * source d'arbre en ligne porte la sienne quand aucune de ses citations ne l'a.
     */
    private function poserUneSource(SourceKind $fonds, string $note): Source
    {
        $source = (new Source('Source de test', $fonds))->setNote($note);

        $this->em()->persist($source);
        $this->em()->flush();

        return $source;
    }

    private function relire(Source $source): Source
    {
        $relue = $this->em()->getRepository(Source::class)->find($source->getId());
        self::assertInstanceOf(Source::class, $relue);

        return $relue;
    }

    private function lancer(bool $ecrire = false): string
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $testeur = new CommandTester($application->find('heritage:reclasser-les-arbres-tiers'));

        $testeur->execute($ecrire ? ['--ecrire' => true] : []);

        return $testeur->getDisplay();
    }
}
