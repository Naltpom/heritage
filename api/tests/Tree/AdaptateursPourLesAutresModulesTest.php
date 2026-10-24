<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Service\SettingService;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Security\ResolutionDuSujetInterface;
use App\Module\Membership\Service\Port\PortArbre;
use App\Module\Question\Contract\FicheEtudiee;
use App\Module\Tree\Entity\Citation;
use App\Module\Tree\Entity\Fact;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Enum\FactType;
use App\Module\Tree\Service\ChronometreDuGraphe;
use App\Module\Tree\Service\ReglagesDeLArbreParSettings;
use App\Module\Tree\Service\ReglePersonneVivante;
use App\Module\Tree\Service\TreeFingerprint;
use App\Module\Tree\Service\VueDeLArbrePourMembership;
use App\Module\Tree\Service\VueDeLArbrePourQuestions;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Les trois coutures par lesquelles `Membership`, `Media` et `Question` lisent l'arbre
 * sans l'importer.
 *
 * Elles sont éprouvées ensemble parce qu'elles partagent leur mode de défaillance : **une
 * traduction incomplète ne se voit pas**. Une personne manquante dans la vue de
 * `Membership` donne un cercle amputé, une personne oubliée dans la résolution d'un sujet
 * de `Media` donne une photo publiée, un parent perdu par la vue de `Question` donne une
 * question qui demande à une petite-fille le nom d'une aïeule que l'arbre connaît déjà.
 * Dans les trois cas la réponse reste plausible, aucune exception n'est levée, et rien ne
 * devient rouge.
 *
 * Chacune est interrogée là où elle se juge : la résolution des sujets par son **interface**,
 * puisque ce qui compte est que l’alias soit branché ; la vue de `Membership` par sa classe,
 * son alias étant remplacé par un double sous `when@test` (voir plus bas).
 */
final class AdaptateursPourLesAutresModulesTest extends ArbreWebTestCase
{
    /**
     * On demande **la classe**, pas l'interface, et c'est délibéré.
     *
     * `config/services/membership.yaml` remplace `PortArbre` par un arbre en mémoire sous
     * `when@test` — c'est ce qui permet aux tests du cercle de tourner sans charger la
     * moindre table `tree_*`, et il ne faut pas le défaire. Passer par l'interface ici
     * éprouverait donc leur double et non notre adaptateur. Le conteneur de test a
     * d'ailleurs le droit d'avoir *inliné* celui-ci, que plus rien n'y référence.
     *
     * Qu'il satisfasse bien {@see PortArbre} n'a pas à être vérifié à l'exécution :
     * la clause `implements` le dit, et phpstan la contrôle.
     *
     * On l'assemble donc à la main. Ses quatre dépendances sont des services sans état,
     * et c'est justement ce qui rend le geste sûr : il n'y a pas de configuration cachée
     * à reproduire. L'alias de production, lui, est vérifié par `lint:container`.
     */
    private function portMembership(): VueDeLArbrePourMembership
    {
        $reglages = self::getContainer()->get(SettingService::class);
        \assert($reglages instanceof SettingService);

        $port = new VueDeLArbrePourMembership(
            $this->em(),
            new ReglePersonneVivante(),
            new ReglagesDeLArbreParSettings($reglages),
            new TreeFingerprint($this->em()),
        );

        return $port;
    }

    /** Le même port, avec un cache et un chronomètre : chaque appel est une requête neuve. */
    private function portMembershipEnCache(ArrayAdapter $cache, ChronometreDuGraphe $chrono): VueDeLArbrePourMembership
    {
        $reglages = self::getContainer()->get(SettingService::class);
        \assert($reglages instanceof SettingService);

        return new VueDeLArbrePourMembership(
            $this->em(),
            new ReglePersonneVivante(),
            new ReglagesDeLArbreParSettings($reglages),
            new TreeFingerprint($this->em()),
            chrono: $chrono,
            cacheArbre: $cache,
        );
    }

    private function resolution(): ResolutionDuSujetInterface
    {
        $port = self::getContainer()->get(ResolutionDuSujetInterface::class);
        \assert($port instanceof ResolutionDuSujetInterface);

        return $port;
    }

    // ── Membership ───────────────────────────────────────────────────────────────

    /**
     * **L'arbre rendu est complet, sans filtre.** C'est contre-intuitif pour un port qui
     * sert la confidentialité, et c'est pourtant la seule forme juste : le cercle de `u`
     * contient des personnes que le *lecteur* n'a pas le droit de voir — c'est même tout
     * l'objet de la bascule « voir comme un membre ». Un cercle amputé est faux, et un
     * cercle faux est une fuite ou une fiche muette selon le sens de l'erreur.
     */
    /**
     * La vue est relue en base **une fois par état de l'arbre** (mesurée en production à
     * 800 ms par requête, à 3 904 personnes). Un arbre inchangé la sert du cache, à
     * l'identique ; un arbre modifié change l'empreinte, donc la clé, et la relit ; au
     * milieu d'une transaction, le cache est écarté — Doctrine y rend des entités pas
     * encore envoyées, que l'empreinte lue en SQL ignore.
     */
    public function testLaVueSeRelitDuCacheTantQueLArbreNeChangePas(): void
    {
        $cache = new ArrayAdapter();

        $premiere = $this->portMembershipEnCache($cache, $chrono = new ChronometreDuGraphe())->vue();
        self::assertStringContainsString('cache=miss', $chrono->enTete());

        $seconde = $this->portMembershipEnCache($cache, $chrono = new ChronometreDuGraphe())->vue();
        self::assertStringContainsString('cache=hit', $chrono->enTete());
        self::assertEquals($premiere, $seconde, 'Servie du cache, la vue est la même.');

        $this->personnes['cousin']->setDisplayName('Pyroxène Renommé');
        $this->em()->flush();

        $apres = $this->portMembershipEnCache($cache, $chrono = new ChronometreDuGraphe())->vue();
        self::assertStringContainsString('cache=miss', $chrono->enTete(), 'Un arbre modifié ne se sert pas du cache.');
        self::assertSame('Pyroxène Renommé', $apres->personnes[$this->idDe('cousin')]->libelle);

        $this->em()->getConnection()->beginTransaction();
        try {
            $this->portMembershipEnCache($cache, $chrono = new ChronometreDuGraphe())->vue();
            self::assertStringContainsString('cache=transaction', $chrono->enTete());
        } finally {
            $this->em()->getConnection()->rollBack();
        }
    }

    public function testLaVueRendLArbreCompletSansAucunFiltre(): void
    {
        $vue = $this->portMembership()->vue();

        self::assertCount(\count($this->personnes), $vue->personnes, 'Les six personnes de l’arbre de test, vivantes comprises.');
        self::assertArrayHasKey($this->idDe('cousin'), $vue->personnes, 'Pyroxène est vivant et hors de tout cercle : il est pourtant là.');
    }

    /**
     * L'adjacence est reconstruite par `VueDeLArbre` depuis les seules familles. On
     * vérifie qu'on lui a donné de quoi : époux et enfants, en identifiants nus.
     */
    public function testLAdjacenceSeReconstruitDepuisLesFamilles(): void
    {
        $vue = $this->portMembership()->vue();

        $ego = $this->idDe('ego');
        self::assertSame([$this->idDe('aieul'), $this->idDe('aieule')], $vue->parents[$ego] ?? [], 'L’ego est enfant du couple d’aïeux.');
        self::assertContains($ego, $vue->enfants[$this->idDe('aieule')] ?? []);

        // Un conjoint est un **coépoux**, pas « quelqu'un de la même famille » : les
        // enfants n'y entrent pas, et c'est cette nuance qui décide de la dernière passe
        // de la formule de l'ADR 0003.
        self::assertSame([$this->idDe('aieule')], $vue->conjoints[$this->idDe('aieul')] ?? []);
        self::assertNotContains($ego, $vue->conjoints[$this->idDe('aieul')] ?? []);
    }

    /**
     * L'état vivant vient de `ReglePersonneVivante` — **la seule définition de « vivant »
     * du produit**, celle qui décide aussi de ce que la sérialisation masque. Une seconde,
     * écrite dans l'adaptateur, finirait par en décider autrement.
     */
    public function testLEtatVivantVientDeLaRegleEtNonDeLAbsenceDeDate(): void
    {
        $vue = $this->portMembership()->vue();

        self::assertTrue($vue->personnes[$this->idDe('cousin')]->vivante, 'Né vers 1934, aucun décès enregistré.');
        self::assertFalse($vue->personnes[$this->idDe('aieule')]->vivante, 'Décès enregistré en 1987.');
        self::assertFalse(
            $vue->personnes[$this->idDe('sansDate')]->vivante,
            'Ni date ni décès : c’est le repli par génération qui tranche, et sans lui 27 ancêtres seraient masqués.',
        );
    }

    /**
     * **Les prénoms sont rendus bruts.** Le fournisseur de placement leur substitue `'?'`
     * — `pre or "?"` dans `graphe.py`, pour qu'une fiche sans prénom mesure quand même un
     * caractère. Cette substitution est juste pour mesurer une boîte et **fausse pour
     * composer un libellé** : le § 4.2 du contrat interdit le `?. Romarin` qui en
     * sortirait, « le point isolé en dirait autant qu'une absence tout en étant plus laid ».
     */
    public function testUnePersonneSansPrenomNeRecoitPasDePointDInterrogation(): void
    {
        $anonyme = new Person('Muguet');
        $anonyme->setSurname('Muguet')->setGeneration(-6)->setDeceased(true);

        $em = $this->em();
        $em->persist($anonyme);
        $em->flush();

        $id = $anonyme->getId();
        self::assertNotNull($id);

        $personne = $this->portMembership()->vue()->personnes[$id];

        self::assertSame('', $personne->prenoms);
        self::assertSame('Muguet', $personne->libelleMinimal(), 'Sans prénom, le libellé minimal s’arrête au nom — pas de « ?. Muguet ».');
    }

    /**
     * L'empreinte entre dans la clé de cache `cercle.{empreinte}.{memberId}.{N}`. Une
     * implémentation qui rendrait une constante ne casserait rien tant que l'arbre ne
     * bouge pas — et servirait un cercle périmé au premier changement validé.
     */
    public function testLEmpreinteEstCelleDeLArbreEtChangeAvecLui(): void
    {
        $avant = $this->portMembership()->empreinte();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $avant);

        $this->personnes['aieule']->setSurname('Sureau-Sarriette');
        $this->em()->flush();

        self::ensureKernelShutdown();
        $this->client = static::createClient();

        self::assertNotSame($avant, $this->portMembership()->empreinte(), 'L’empreinte est recalculée depuis la base, jamais un compteur.');
    }

    // ── Media ────────────────────────────────────────────────────────────────────

    public function testUnSujetPersonneNEngageQuElleMeme(): void
    {
        $resolu = $this->resolution()->resoudre(MediaSubject::Personne, $this->idDe('aieule'));

        self::assertNotNull($resolu);
        self::assertSame('Marne Dolomite Sureau', $resolu->libelle);
        self::assertSame([$this->idDe('aieule')], $resolu->personIds);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  UNE UNION ENGAGE **LES DEUX** ÉPOUX. C'est la règle qui décide de tout.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * L'appelant conclut « visible **si toutes** les personnes le sont », et le motif va
     * contre l'intuition : *une preuve d'union en dit autant sur l'un des époux que sur
     * l'autre*. Rendre le seul « principal » publierait la photo de mariage de l'époux
     * masqué sous prétexte que sa femme est dans le cercle du lecteur.
     */
    public function testUnSujetFamilleEngageLesDeuxEpoux(): void
    {
        $id = $this->familles['aieux']->getId();
        self::assertNotNull($id);

        $resolu = $this->resolution()->resoudre(MediaSubject::Famille, $id);

        self::assertNotNull($resolu);
        self::assertSame([$this->idDe('aieul'), $this->idDe('aieule')], $resolu->personIds);
        self::assertSame('Émeri Sarriette × Marne Dolomite Sureau', $resolu->libelle, 'Le libellé nomme les deux époux, exactement comme la protection porte sur les deux.');
    }

    /** Une citation suit **ce qu'elle étaye** : ici, la personne. */
    public function testUneCitationSuitLaPersonneQuElleEtaye(): void
    {
        $citation = $this->em()->createQuery(
            'SELECT c FROM '.Citation::class.' c WHERE c.person = :p',
        )->setParameter('p', $this->personnes['cousin'])->getSingleResult();

        $id = $citation->getId();
        self::assertNotNull($id);

        $resolu = $this->resolution()->resoudre(MediaSubject::Citation, $id);

        self::assertNotNull($resolu);
        self::assertSame([$this->idDe('cousin')], $resolu->personIds);
    }

    /**
     * « N'existe pas » et « pas le droit » ne se distinguent pas côté appelant, qui rend
     * `404` dans les deux cas. Ici, le premier : la ligne n'est pas là.
     */
    public function testUnSujetInexistantRendNull(): void
    {
        self::assertNull($this->resolution()->resoudre(MediaSubject::Personne, 999999));
        self::assertNull($this->resolution()->resoudre(MediaSubject::Famille, 999999));
        self::assertNull($this->resolution()->resoudre(MediaSubject::Citation, 999999));
    }

    /**
     * **Un sujet qui n'engage personne est fermé.** Traiter « rien à protéger » comme
     * « ouvert » ferait d'un bogue de ce module — une famille sans époux résolu, une
     * citation orpheline — une fuite de données de personnes vivantes.
     *
     * On rend bien un sujet, pas `null` : la ligne **existe**, et confondre les deux
     * empêcherait d'en diagnostiquer la cause.
     */
    public function testUnSujetQuiNEngagePersonneEstDecritMaisFerme(): void
    {
        $famille = new Family();
        $em = $this->em();
        $em->persist($famille);
        $em->flush();

        $id = $famille->getId();
        self::assertNotNull($id);

        $resolu = $this->resolution()->resoudre(MediaSubject::Famille, $id);

        self::assertNotNull($resolu, 'La ligne existe : on la décrit.');
        self::assertSame([], $resolu->personIds, 'Elle n’engage personne — l’appelant lit cela comme « masqué ».');
    }

    /**
     * **La question groupée répond exactement comme la question unitaire**, cas par cas :
     * c'est elle que le graphe pose, et une personne qu'elle oublierait publierait un
     * média que la fiche refuse.
     *
     * Une citation de chaque sorte — sur une personne, sur le fait d'une personne, sur une
     * famille, sur le fait d'une famille, orpheline —, une famille avec et sans époux, et
     * un identifiant qui n'existe pas.
     */
    public function testLaQuestionGroupeeRepondCommeLaQuestionUnitaire(): void
    {
        $em = $this->em();
        $source = $em->createQuery('SELECT c FROM '.Citation::class.' c WHERE c.person = :p')
            ->setParameter('p', $this->personnes['cousin'])
            ->getSingleResult()
            ->getSource();

        $sansEpoux = new Family();
        $faitDePersonne = new Fact(FactType::Residence, $this->personnes['aieule']);
        $faitDeFamille = new Fact(FactType::Residence, null, $this->familles['aieux']);
        $citations = [
            (new Citation($source))->setPerson($this->personnes['cousin']),
            (new Citation($source))->setFact($faitDePersonne),
            (new Citation($source))->setFamily($this->familles['aieux']),
            (new Citation($source))->setFact($faitDeFamille),
            (new Citation($source))->setFamily($sansEpoux),
            new Citation($source),
        ];

        foreach ([$sansEpoux, $faitDePersonne, $faitDeFamille, ...$citations] as $entite) {
            $em->persist($entite);
        }
        $em->flush();

        $sujets = [
            [MediaSubject::Personne, [$this->idDe('aieule'), $this->idDe('cousin'), 999999]],
            [MediaSubject::Famille, [(int) $this->familles['aieux']->getId(), (int) $sansEpoux->getId(), 999999]],
            [MediaSubject::Citation, [...array_map(static fn (Citation $c): int => (int) $c->getId(), $citations), 999999]],
        ];

        foreach ($sujets as [$kind, $ids]) {
            $attendu = [];

            foreach ($ids as $id) {
                $resolu = $this->resolution()->resoudre($kind, $id);

                if (null !== $resolu) {
                    $attendu[$id] = $resolu->personIds;
                }
            }

            $obtenu = $this->resolution()->personnesEngagees($kind, $ids);
            ksort($obtenu);
            ksort($attendu);

            self::assertSame($attendu, $obtenu, $kind->value);
        }

        self::assertSame([], $this->resolution()->personnesEngagees(MediaSubject::Personne, []));
    }

    // ── Question ─────────────────────────────────────────────────────────────────

    /**
     * Assemblé à la main, pour la même raison que la vue de `Membership` : ses trois
     * dépendances sont des services sans état, et l'alias de production est vérifié par
     * `lint:container`.
     */
    private function portQuestions(): VueDeLArbrePourQuestions
    {
        $reglages = self::getContainer()->get(SettingService::class);
        \assert($reglages instanceof SettingService);

        return new VueDeLArbrePourQuestions(
            $this->em(),
            new ReglePersonneVivante(),
            new ReglagesDeLArbreParSettings($reglages),
        );
    }

    /** @return array<int, FicheEtudiee> indexé par identifiant */
    private function fichesEtudiees(): array
    {
        $indexees = [];

        foreach ($this->portQuestions()->fiches() as $fiche) {
            $indexees[$fiche->id] = $fiche;
        }

        return $indexees;
    }

    /**
     * **Le flux est complet et non filtré**, vivants compris — arbitrage de l'ADR 0008,
     * et nécessité de la détection : un flux partiel produirait des lacunes *fantômes*,
     * « cette personne n'a pas de mère » parce que la mère n'était pas dans le flux.
     *
     * Le filtre du cercle vit ailleurs, en un point unique de `Question`.
     */
    public function testLaVuePourQuestionsRendLArbreEntierSansFiltre(): void
    {
        $fiches = $this->fichesEtudiees();

        self::assertCount(\count($this->personnes), $fiches);
        self::assertArrayHasKey($this->idDe('cousin'), $fiches, 'Pyroxène est vivant : il est là quand même.');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  LA PARENTÉ SE LIT SUR LE COUPLE, PAS SUR UN PARENT GARDIEN.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * C'est le cas que cette couture peut rater en silence. Un `innerJoin` recopié d'un
     * autre écran ferait disparaître le parent **manquant** — c'est-à-dire exactement la
     * lacune qu'on cherche. Ici les deux parents existent, et les deux doivent sortir,
     * lus séparément sur `husband` et `wife` de la famille qui porte l'enfant.
     */
    public function testLesDeuxParentsSortentDeLaFamilleQuiPorteLEnfant(): void
    {
        $fiches = $this->fichesEtudiees();
        $ego = $fiches[$this->idDe('ego')];

        self::assertSame($this->idDe('aieul'), $ego->pereId);
        self::assertSame($this->idDe('aieule'), $ego->mereId);
    }

    /** Une fiche sans famille d'enfance porte **deux** lacunes, et c'est la donnée utile. */
    public function testUneFicheSansFamilleDEnfanceNAniPereNiMere(): void
    {
        $fiches = $this->fichesEtudiees();
        $aieul = $fiches[$this->idDe('aieul')];

        self::assertNull($aieul->pereId);
        self::assertNull($aieul->mereId);
    }

    /** Les enfants remontent aux deux parents, les conjoints dans les deux sens. */
    public function testLesEnfantsEtLesConjointsSontRendusDesDeuxCotes(): void
    {
        $fiches = $this->fichesEtudiees();

        self::assertContains($this->idDe('ego'), $fiches[$this->idDe('aieul')]->enfantIds);
        self::assertContains($this->idDe('ego'), $fiches[$this->idDe('aieule')]->enfantIds);

        self::assertSame([$this->idDe('aieule')], $fiches[$this->idDe('aieul')]->conjointIds);
        self::assertSame([$this->idDe('aieul')], $fiches[$this->idDe('aieule')]->conjointIds);

        // Un conjoint est un **coépoux**, jamais « quelqu'un de la même famille » : les
        // enfants n'y entrent pas.
        self::assertNotContains($this->idDe('ego'), $fiches[$this->idDe('aieul')]->conjointIds);
    }

    /**
     * **`vivante` vient de la règle, jamais de `tree_person.deceased`.**.
     *
     * Pyroxène n'a aucun décès enregistré et la règle du § 9.2 le classe vivant à
     * quatre-vingt-douze ans. C'est ce booléen-là qui décide qu'on ne lui demandera pas
     * son lieu de décès — et, au jalon 4, ce qui partira chez un tiers. Deux définitions
     * de « vivant » divergeraient ici au pire endroit du produit.
     */
    public function testLEtatVivantDeLaVuePourQuestionsVientDeLaRegle(): void
    {
        $fiches = $this->fichesEtudiees();

        self::assertTrue($fiches[$this->idDe('cousin')]->vivante, 'Aucun décès enregistré : la règle ferme.');
        self::assertFalse($fiches[$this->idDe('aieule')]->vivante, 'Décès de 1987 enregistré.');
    }

    public function testLeLibelleSuitLArbreEtRendNullPourUnInconnu(): void
    {
        $port = $this->portQuestions();

        self::assertSame('Natron Romarin', $port->libelleDe($this->idDe('ego')));
        self::assertNull($port->libelleDe(999999));
    }
}
