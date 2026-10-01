<?php

declare(strict_types=1);

namespace App\Tests\Assistant;

use App\Module\Assistant\Contract\ArbrePourLAssistant;
use App\Module\Assistant\Contract\ArbrePourLaConsultation;
use App\Module\Assistant\Contract\DossierDeLaPersonne;
use App\Module\Assistant\Contract\FaitDeLArbre;
use App\Module\Assistant\Contract\FicheDeLArbre;
use App\Module\Assistant\Contract\PersonneDeLArbre;
use App\Module\Assistant\Contract\SourceCitee;
use App\Module\Assistant\Contract\UnionDeLArbre;
use App\Module\Assistant\Exception\AssistantEnErreur;
use App\Module\Assistant\Service\AgentDeConsultation;
use App\Module\Assistant\Service\EchangePasse;
use App\Module\Assistant\Service\EntreeDeQuestion;
use App\Module\Assistant\Service\MentionsDeFiches;
use App\Module\Assistant\Service\OutilsDeConsultation;
use App\Module\Assistant\Service\ReglagesDeLAssistant;
use App\Module\Assistant\Service\TransportDuModele;
use App\Module\Assistant\Service\VueDuLecteur;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Regime;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\ValueObject\UncertainDate;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * La boucle de « Poser une question », contre un modèle **scripté**, sans réseau.
 *
 * Ce que ces tests gardent, dans l'ordre d'importance :
 *
 * 1. **Le modèle ne reçoit rien d'un vivant hors cercle** : ni son nom, ni ses dates, ni
 *    son lieu — absent des recherches, réduit à « Personne vivante » partout ailleurs.
 *    C'est le contrat de ce mode, et la différence avec l'assistant de proposition.
 * 2. **Les sources partent avec leur appui** — acte, index, arbre en ligne — pour que la
 *    réponse puisse dire d'où vient ce qu'elle affirme.
 * 3. **Les bornes** : huit tours, le dernier contraint à `repondre`, puis `502`.
 * 4. **Le journal ne contient ni la question ni la réponse.**
 *
 * La scène : Marne (17), défunte, mariée à Tuf (18), vivant **hors du cercle** du lecteur,
 * mère de Pivoine (19), défunte. Le lecteur est la personne 30.
 */
final class AgentDeConsultationTest extends TestCase
{
    private const string NOM_CACHE = 'Tuf Sureau';

    private const string LIEU_CACHE = 'Quimperlé';

    private TransportScripte $transport;

    private JournalEnMemoire $journal;

    public function testLeModeleNeVoitQueLeCercleDuLecteur(): void
    {
        $agent = $this->agent(
            TransportScripte::appel('chercher_personnes', ['requete' => 'Sureau']),
            TransportScripte::appel('chercher_par_lieu', ['lieu' => 'Brest']),
            TransportScripte::appel('lire_personne', ['personId' => 18]),
            TransportScripte::appel('lire_personne', ['personId' => 17]),
            TransportScripte::appel('repondre', ['texte' => 'Réponse.', 'personIds' => [17]]),
        );

        $resultat = $agent->repondre(new EntreeDeQuestion('Que sait-on de Marne ?', [], 30));

        self::assertSame(['texte' => 'Réponse.', 'personIds' => [17]], $resultat->arguments);
        self::assertSame(5, $resultat->tours);
        self::assertSame(['chercher_personnes' => 1, 'chercher_par_lieu' => 1, 'lire_personne' => 2], $resultat->appelsDOutils);

        $tout = json_encode($this->transport->charges, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString(self::NOM_CACHE, $tout, 'Le nom d’un vivant hors cercle ne part jamais chez le tiers.');
        self::assertStringNotContainsString('Tuf', $tout);
        self::assertStringNotContainsString(self::LIEU_CACHE, $tout);
        self::assertStringNotContainsString('1990', $tout);

        $reponses = $this->reponsesDesOutils();

        // Recherche par nom et par lieu : Tuf est **absent**, pas réduit.
        self::assertSame([17, 19], array_column($reponses[0]['personnes'], 'personId'));
        self::assertSame([CercleDeVisibiliteInterface::NOM_MASQUE], $reponses[0]['personnes'][0]['conjoints'], 'Un conjoint caché est nommé par le masque.');
        self::assertSame([17], array_column($reponses[1]['personnes'], 'personId'));
        self::assertFalse($reponses[1]['tronque']);

        // Sa fiche, lue par son numéro : la forme réduite, et rien d'autre.
        self::assertSame(['personId', 'nom', 'vivant', 'confidentielle', 'remarque'], array_keys($reponses[2]));

        // La fiche de Marne : le conjoint caché réduit, l'enfant visible entier, la parenté
        // vue du lecteur, les sources avec leur appui, les faits, les notes.
        $marne = $reponses[3];
        self::assertSame(['personId' => 18, 'nom' => CercleDeVisibiliteInterface::NOM_MASQUE, 'vivant' => true, 'confidentielle' => true], $marne['unions'][0]['conjoint']);
        self::assertSame('1950, Brest', $marne['unions'][0]['mariage'], 'Les dates d’union restent : elles sont à la famille.');
        self::assertSame('Pivoine Sureau', $marne['unions'][0]['enfants'][0]['nom']);
        self::assertSame('arrière-grand-mère', $marne['parenteAvecLeLecteur']);
        self::assertSame('arbre', $marne['sources'][0]['appui']);
        self::assertSame('tenu par un arbre en ligne', $marne['sources'][0]['appuiLibelle']);
        self::assertSame('Profession', $marne['faits'][0]['type']);
        self::assertSame(['Institutrice à Brest.'], $marne['notes']);

        // Les outils offerts, et le lecteur situé.
        self::assertSame(
            ['chercher_personnes', 'lire_personne', 'chercher_par_lieu', 'repondre'],
            array_column($this->transport->charges[0]['tools'][0]['functionDeclarations'], 'name'),
        );
        self::assertStringContainsString('personne n° 30', $this->transport->charges[0]['contents'][0]['parts'][0]['text']);
        self::assertSame(['mode' => 'ANY'], $this->transport->charges[0]['toolConfig']['functionCallingConfig']);
    }

    public function testLaConsigneExigeLesSourcesEtLeJeNeSaisPas(): void
    {
        $agent = $this->agent(TransportScripte::appel('repondre', ['texte' => 'Je ne sais pas.', 'personIds' => []]));
        $agent->repondre(new EntreeDeQuestion('Qui était le père de Pivoine ?'));

        $consigne = $this->transport->charges[0]['systemInstruction']['parts'][0]['text'];
        self::assertStringContainsString('en français', $consigne);
        self::assertStringContainsString('Je ne sais pas', $consigne);
        self::assertStringContainsString('« arbre » → repris d’un arbre en ligne', $consigne);
        self::assertStringContainsString('[Prénom Nom](personne:12)', $consigne);
        self::assertStringContainsString('n’a pas de fiche dans l’arbre', $this->transport->charges[0]['contents'][0]['parts'][0]['text'], 'Sans fiche, le modèle sait qu’il ne peut pas situer « moi ».');
    }

    public function testLHistoriquePrecedeLaQuestionEnToursAlternes(): void
    {
        $agent = $this->agent(TransportScripte::appel('repondre', ['texte' => 'Son père n’est pas dans l’arbre.', 'personIds' => []]));

        $agent->repondre(new EntreeDeQuestion('Et son père ?', [
            new EchangePasse('Que sait-on de Marne ?', 'Marne est née à Brest.', [17]),
        ], 30));

        $contenus = $this->transport->charges[0]['contents'];
        self::assertSame(['user', 'model', 'user'], array_column($contenus, 'role'));
        self::assertSame('Que sait-on de Marne ?', $contenus[0]['parts'][0]['text']);
        self::assertSame("Marne est née à Brest.\n\n(Fiches citées : 17.)", $contenus[1]['parts'][0]['text']);
        self::assertStringContainsString('« Et son père ? »', $contenus[2]['parts'][0]['text']);
    }

    public function testSansRepondreAuHuitiemeTourLAgentEchoueEtNeJournaliseRienDuContenu(): void
    {
        $reponses = [];
        // Le modèle tourne en rond : huit lectures, jamais de réponse.
        for ($i = 0; $i < ReglagesDeLAssistant::TOURS_MAXIMAUX; ++$i) {
            $reponses[] = TransportScripte::appel('lire_personne', ['personId' => 17]);
        }

        $agent = $this->agent(...$reponses);

        try {
            $agent->repondre(new EntreeDeQuestion('Question secrète sur Marne ?', [], 30));
            self::fail('L’agent devait échouer.');
        } catch (AssistantEnErreur $e) {
            self::assertSame(502, $e->statutHttp());
        }

        self::assertCount(ReglagesDeLAssistant::TOURS_MAXIMAUX, $this->transport->charges);
        self::assertSame(['mode' => 'ANY', 'allowedFunctionNames' => ['repondre']], end($this->transport->charges)['toolConfig']['functionCallingConfig'], 'Le dernier tour est contraint à « repondre ».');

        $journal = json_encode($this->journal->lignes, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('secrète', $journal);
        self::assertStringNotContainsString('Marne', $journal);
    }

    public function testUnTransportEnPanneDevientUne502SansContenuAuJournal(): void
    {
        $agent = $this->agent(new \RuntimeException('HTTP 500 renvoyé pour « https://gemini.test/… »'));

        $this->expectException(AssistantEnErreur::class);

        try {
            $agent->repondre(new EntreeDeQuestion('Où est née Marne ?'));
        } finally {
            self::assertCount(1, $this->journal->lignes);
            self::assertStringNotContainsString('Où est née', json_encode($this->journal->lignes, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
        }
    }

    public function testUnAppelMalFormeRendUneErreurAuModeleQuiPeutSeCorriger(): void
    {
        $agent = $this->agent(
            TransportScripte::appel('lire_personne', ['personId' => 'dix-sept']),
            TransportScripte::appel('chercher_par_lieu', []),
            TransportScripte::appel('repondre', ['texte' => 'Je ne sais pas.', 'personIds' => []]),
        );

        $agent->repondre(new EntreeDeQuestion('Marne ?'));

        $reponses = $this->reponsesDesOutils();
        self::assertArrayHasKey('erreur', $reponses[0]);
        self::assertArrayHasKey('erreur', $reponses[1]);
    }

    public function testLesMentionsSontVerifieesContreLArbreEtLeCercle(): void
    {
        $mentions = new MentionsDeFiches(new ArbreDeConsultation(), new VueDuLecteur(new CercleSansTuf()));

        $nettoye = $mentions->nettoyer(
            'Voir [Marne Dolomite Sureau](personne:17), [Tuf Sureau](personne:18) et [Inconnu](personne:404).',
            [17, 17.0, '19', 404, 'x'],
        );

        self::assertSame(\sprintf('Voir [Marne Dolomite Sureau](personne:17), [%s](personne:18) et Inconnu.', CercleDeVisibiliteInterface::NOM_MASQUE), $nettoye['texte']);
        self::assertSame([17, 18, 19], $nettoye['personIds']);

        self::assertSame([
            ['personId' => 18, 'displayName' => CercleDeVisibiliteInterface::NOM_MASQUE, 'visibilite' => 'restreinte'],
        ], $mentions->personnes([18, 404]));
    }

    /** @return list<array<string, mixed>> les réponses des outils, dans l'ordre */
    private function reponsesDesOutils(): array
    {
        $reponses = [];

        foreach (end($this->transport->charges)['contents'] as $contenu) {
            foreach ($contenu['parts'] as $part) {
                if (isset($part['functionResponse'])) {
                    $reponses[] = $part['functionResponse']['response'];
                }
            }
        }

        return $reponses;
    }

    /** @param array<string, mixed>|\Throwable ...$reponses */
    private function agent(array|\Throwable ...$reponses): AgentDeConsultation
    {
        $this->transport = new TransportScripte(array_values($reponses));
        $this->journal = new JournalEnMemoire();

        return new AgentDeConsultation(
            $this->transport,
            new OutilsDeConsultation(new ArbreDeConsultation(), new ArbreDeConsultation(), new VueDuLecteur(new CercleSansTuf())),
            new ReglagesDeLAssistant(null),
            $this->journal,
        );
    }
}

/** Un modèle qui rejoue son script et garde les charges reçues. */
final class TransportScripte implements TransportDuModele
{
    /** @var list<array<string, mixed>> */
    public array $charges = [];

    /** @param list<array<string, mixed>|\Throwable> $reponses */
    public function __construct(private array $reponses)
    {
    }

    public function generer(string $modele, array $charge, float $delai): array
    {
        // Copie profonde par aller-retour JSON : la boucle continue d'ajouter des tours au
        // tableau qu'elle nous a passé, et l'on veut ce qui est parti à CET appel.
        $this->charges[] = json_decode(json_encode($charge, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        $reponse = array_shift($this->reponses) ?? throw new \LogicException('Script épuisé.');

        if ($reponse instanceof \Throwable) {
            throw $reponse;
        }

        return $reponse;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public static function appel(string $nom, array $arguments): array
    {
        return ['candidates' => [['content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => $nom, 'args' => $arguments]]]]]]];
    }
}

final class JournalEnMemoire extends AbstractLogger
{
    /** @var list<array{niveau: mixed, message: string, contexte: array<mixed>}> */
    public array $lignes = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->lignes[] = ['niveau' => $level, 'message' => (string) $message, 'contexte' => $context];
    }
}

/** Le lecteur voit tout le monde sauf Tuf (18), vivant hors de son cercle. */
final class CercleSansTuf implements CercleDeVisibiliteInterface
{
    public function regime(): Regime
    {
        return Regime::Cercle;
    }

    public function voitPersonne(int $personId): bool
    {
        return 18 !== $personId;
    }

    public function filtrerPersonnes(array $personIds): array
    {
        return array_values(array_filter(array_unique($personIds), fn (int $id): bool => $this->voitPersonne($id)));
    }
}

/**
 * Marne (17, défunte) mariée à Tuf (18, vivant, né à Quimperlé en 1990), mère de Pivoine
 * (19, défunte). Les deux ports de l'assistant, en mémoire.
 */
final class ArbreDeConsultation implements ArbrePourLAssistant, ArbrePourLaConsultation
{
    public function chercher(string $requete, int $limite): array
    {
        return [$this->marne([], [$this->tuf()]), $this->tuf(), $this->pivoine()];
    }

    public function personne(int $personneId): ?PersonneDeLArbre
    {
        return match ($personneId) {
            17 => $this->marne(),
            18 => $this->tuf(),
            19 => $this->pivoine(),
            default => null,
        };
    }

    public function fiche(int $personneId): ?FicheDeLArbre
    {
        return match ($personneId) {
            17 => new FicheDeLArbre($this->marne(), 7, 'Marne Dolomite', 'Sureau', null, null, true, [], [
                new UnionDeLArbre(5, $this->tuf(), new UncertainDate('1950', DateQualifier::Annee, new \DateTimeImmutable('1950-01-01'), new \DateTimeImmutable('1950-12-31'), 'Brest'), false, [$this->pivoine()]),
            ], []),
            18 => new FicheDeLArbre($this->tuf(), 3, 'Tuf', 'Sureau', null, null, false, [], [], []),
            default => null,
        };
    }

    public function dossier(int $personneId): ?DossierDeLaPersonne
    {
        return 17 === $personneId
            ? new DossierDeLaPersonne(
                [new FaitDeLArbre('Profession', 'Institutrice', UncertainDate::inconnue(), null, null, null)],
                [new SourceCitee('arbre', 'tenu par un arbre en ligne', null, 'Arbre de Jean', 'Arbre d’un autre utilisateur', null, null, null)],
                ['Institutrice à Brest.'],
            )
            : null;
    }

    public function parente(int $egoPersonneId, int $personneId): ?string
    {
        return 30 === $egoPersonneId && 17 === $personneId ? 'arrière-grand-mère' : null;
    }

    public function parLieuEtPeriode(?string $lieu, ?int $anneeMin, ?int $anneeMax, string $evenement, int $limite): array
    {
        return [$this->marne(), $this->tuf()];
    }

    /**
     * @param list<PersonneDeLArbre> $parents
     * @param list<PersonneDeLArbre> $conjoints
     */
    private function marne(array $parents = [], array $conjoints = []): PersonneDeLArbre
    {
        return new PersonneDeLArbre(
            17,
            'Marne Dolomite Sureau',
            Sex::Feminin,
            false,
            new UncertainDate('1927', DateQualifier::Annee, new \DateTimeImmutable('1927-01-01'), new \DateTimeImmutable('1927-12-31'), 'Brest'),
            UncertainDate::inconnue(),
            $parents,
            $conjoints,
        );
    }

    private function tuf(): PersonneDeLArbre
    {
        return new PersonneDeLArbre(18, 'Tuf Sureau', Sex::Masculin, true, new UncertainDate('12 APR 1990', DateQualifier::Exacte, new \DateTimeImmutable('1990-04-12'), new \DateTimeImmutable('1990-04-12'), 'Quimperlé'), UncertainDate::inconnue());
    }

    private function pivoine(): PersonneDeLArbre
    {
        return new PersonneDeLArbre(19, 'Pivoine Sureau', Sex::Feminin, false, UncertainDate::inconnue(), UncertainDate::inconnue());
    }
}
