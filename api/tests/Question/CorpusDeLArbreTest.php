<?php

declare(strict_types=1);

namespace App\Tests\Question;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Question\Contract\FicheEtudiee;
use App\Module\Question\Service\CorpusDeLArbre;
use PHPUnit\Framework\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  CE FICHIER DÉCIDE DE CE QUI QUITTE L'APPLICATION.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * {@see CorpusDeLArbre} est **le seul endroit du produit où des données de personnes
 * vivantes sortent** (ADR 0008 § 1). L'arbitrage est rendu et n'est pas à relitiger ;
 * ce qui se vérifie ici, c'est qu'il ne part **que** ce qui a été décidé.
 *
 * Un champ qui s'ajouterait à ce corpus ne ferait rougir aucun test de comportement : la
 * page continuerait de servir ses questions, et le champ partirait chez le tiers sans que
 * rien ne le signale. D'où une affirmation sur la **liste close des clés**, et non sur
 * la présence de celles qu'on attend.
 *
 * Aucune personne réelle : noms inventés (ADR 0006).
 */
final class CorpusDeLArbreTest extends TestCase
{
    /**
     * **La liste des clés est close.**.
     *
     * C'est le cas qui tombe le jour où quelqu'un ajoute `citations`, `medias`,
     * `note` ou `compte_rattache` « pour que le modèle comprenne mieux ». Chacun de ces
     * ajouts est plausible, et chacun élargirait ce qui sort sans décision.
     */
    public function testLeCorpusNePorteQueLesClesDecidees(): void
    {
        $corpus = (new CorpusDeLArbre())->pour([$this->complete()]);

        self::assertSame(['personnes'], array_keys($corpus));

        $personne = $corpus['personnes'][0];
        self::assertSame(
            ['n', 'nom', 'sexe', 'vivante', 'naissance', 'deces', 'pere', 'mere', 'conjoints', 'enfants'],
            array_keys($personne),
        );
    }

    /**
     * **Les identifiants internes ne sortent pas.**.
     *
     * Ce n'est pas une pseudonymisation — le nom part juste à côté, et prétendre le
     * contraire serait se mentir. C'est une hygiène : l'identifiant d'une ligne de base
     * n'a aucune raison de quitter l'application, et rien dans la réponse du modèle ne
     * doit pouvoir être relu comme désignant une ligne.
     */
    public function testLesIdentifiantsInternesNeSortentPas(): void
    {
        $corpus = (new CorpusDeLArbre())->pour([
            $this->fiche(id: 4021, nom: 'Prune Bréval', pereId: 9000),
            $this->fiche(id: 9000, nom: 'Thym Bréval'),
        ]);

        $serialise = json_encode($corpus, \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('4021', $serialise);
        self::assertStringNotContainsString('9000', $serialise);

        // La parenté, elle, traverse — traduite en rangs locaux au corpus.
        self::assertSame(1, $corpus['personnes'][0]['n']);
        self::assertSame(2, $corpus['personnes'][0]['pere']);
    }

    /**
     * **Les dates sont réduites à l'ANNÉE.**.
     *
     * Le jour et le mois n'aident en rien à formuler une question, et ce sont eux qu'une
     * date de naissance a de plus identifiant.
     */
    public function testLesDatesSortentALAnneePres(): void
    {
        $fiche = $this->fiche(
            id: 1,
            nom: 'Prune Bréval',
            naissance: new UncertainDate(
                '14 MAR 1930',
                DateQualifier::Exacte,
                new \DateTimeImmutable('1930-03-14'),
                new \DateTimeImmutable('1930-03-14'),
                'Saule',
            ),
        );

        $corpus = (new CorpusDeLArbre())->pour([$fiche]);

        self::assertSame('1930', $corpus['personnes'][0]['naissance']);
        self::assertStringNotContainsString('03', json_encode($corpus['personnes'][0]['naissance'], \JSON_THROW_ON_ERROR));
    }

    /** Une fourchette garde ses deux bornes : « je sais » et « j'estime » se formulent autrement. */
    public function testUneFourchetteGardeSesDeuxBornes(): void
    {
        $fiche = $this->fiche(
            id: 1,
            nom: 'Prune Bréval',
            naissance: new UncertainDate(
                'BET 1901 AND 1909',
                DateQualifier::Entre,
                new \DateTimeImmutable('1901-01-01'),
                new \DateTimeImmutable('1909-12-31'),
                null,
            ),
        );

        self::assertSame('1901-1909', (new CorpusDeLArbre())->pour([$fiche])['personnes'][0]['naissance']);
    }

    /**
     * **Le lieu ne part pas.**.
     *
     * Il n'aide pas à formuler la question — « savez-vous où elle est née » se pose
     * précisément quand on ne l'a pas —, et un lieu de naissance est, avec la date, ce
     * qui identifie le plus sûrement une personne vivante.
     */
    public function testLesLieuxNeSortentPas(): void
    {
        $corpus = (new CorpusDeLArbre())->pour([$this->complete()]);

        self::assertStringNotContainsString('Saule', json_encode($corpus, \JSON_THROW_ON_ERROR));
    }

    /**
     * Les clés vides sont retirées.
     *
     * Sur 274 fiches dont la plupart n'ont ni conjoint ni date, elles représentent
     * l'essentiel du volume — et un corpus plus court est aussi un corpus dont le modèle
     * lit mieux ce qui reste.
     */
    public function testUneFicheNueNePorteQueCeQuElleSait(): void
    {
        $corpus = (new CorpusDeLArbre())->pour([$this->fiche(id: 1, nom: 'Prune Bréval')]);

        self::assertSame(['n', 'nom', 'sexe', 'vivante'], array_keys($corpus['personnes'][0]));
    }

    /**
     * **`vivante` part, et c'est voulu** : sans lui, le modèle écrirait une question au
     * passé sur quelqu'un de vivant, ce qu'aucune excuse ne rattrape.
     */
    public function testLEtatVivantTraverse(): void
    {
        $corpus = (new CorpusDeLArbre())->pour([
            $this->fiche(id: 1, nom: 'Prune Bréval', vivante: true),
            $this->fiche(id: 2, nom: 'Thym Bréval', vivante: false),
        ]);

        self::assertTrue($corpus['personnes'][0]['vivante']);

        // **`false` est conservé, contrairement à `null` et à `[]`.** Le filtre du
        // corpus ne retire que l'inconnu et le vide, jamais une valeur fausse : pour un
        // modèle, « vivante: false » et l'absence de la clé ne se lisent pas pareil. La
        // seconde se devine, et une devinette sur l'état vivant est exactement ce qu'on
        // ne veut pas lui laisser faire.
        self::assertFalse($corpus['personnes'][1]['vivante']);
    }

    /** Un parent absent du flux ne fabrique pas un rang inventé. */
    public function testUnParentAbsentDuFluxNEstPasNomme(): void
    {
        $corpus = (new CorpusDeLArbre())->pour([$this->fiche(id: 1, nom: 'Prune Bréval', pereId: 777)]);

        self::assertArrayNotHasKey('pere', $corpus['personnes'][0]);
    }

    /* ─────────────── Montage ─────────────── */

    private function complete(): FicheEtudiee
    {
        return $this->fiche(
            id: 1,
            nom: 'Prune Bréval',
            vivante: true,
            naissance: new UncertainDate(
                '1930',
                DateQualifier::Annee,
                new \DateTimeImmutable('1930-01-01'),
                new \DateTimeImmutable('1930-12-31'),
                'Saule, Ombrage',
            ),
            deces: new UncertainDate(
                '1998',
                DateQualifier::Annee,
                new \DateTimeImmutable('1998-01-01'),
                new \DateTimeImmutable('1998-12-31'),
                'Saule, Ombrage',
            ),
            pereId: 1,
            mereId: 1,
            conjointIds: [1],
            enfantIds: [1],
        );
    }

    /**
     * @param list<int> $conjointIds
     * @param list<int> $enfantIds
     */
    private function fiche(
        int $id,
        string $nom,
        bool $vivante = false,
        ?UncertainDate $naissance = null,
        ?UncertainDate $deces = null,
        ?int $pereId = null,
        ?int $mereId = null,
        array $conjointIds = [],
        array $enfantIds = [],
    ): FicheEtudiee {
        $inconnue = new UncertainDate(null, DateQualifier::Inconnue, null, null, null);

        return new FicheEtudiee(
            id: $id,
            nomAffiche: $nom,
            prenoms: explode(' ', $nom)[0],
            nom: explode(' ', $nom)[1] ?? '',
            sexe: 'feminin',
            vivante: $vivante,
            naissance: $naissance ?? $inconnue,
            deces: $deces ?? $inconnue,
            pereId: $pereId,
            mereId: $mereId,
            conjointIds: $conjointIds,
            enfantIds: $enfantIds,
        );
    }
}
