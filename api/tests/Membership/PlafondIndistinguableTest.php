<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Module\Membership\Entity\ClaimAttempt;
use App\Module\Membership\Enum\ClaimResult;
use App\Module\Membership\Repository\ClaimAttemptRepository;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LE TEST QUI PROTÈGE LE PRODUIT.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Le § 6.1 du dossier de mission désigne le lien d'invitation comme « le principal risque
 * du produit », parce qu'un lien mal gardé devient un oracle permettant d'énumérer les
 * personnes vivantes de l'arbre.
 *
 * Le garde-fou est un plafond de tentatives par jeton. Sa valeur n'est pas ce qui compte :
 * **ce qui compte est que son dépassement ne se voie pas.** Une `429` — la convention de
 * tout le reste du projet (§ 1.9) — annoncerait au porteur que la limite existe, donc que
 * ses requêtes précédentes portaient, donc qu'il y a quelque chose à trouver. C'est la
 * seule exception du contrat à cette convention (§ 4.2, § 17.1 divergence 1), et elle est
 * écrite deux fois dans le contrat parce qu'un back raisonnable écrit spontanément
 * l'inverse.
 *
 * Ce fichier compare donc les deux réponses **champ par champ**. Il ne vérifie pas qu'elles
 * se ressemblent : il vérifie qu'elles sont identiques, en-têtes compris.
 */
final class PlafondIndistinguableTest extends CasDeTestMembership
{
    /**
     * Les deux réponses — recherche infructueuse et plafond atteint — sont **le même
     * octet à octet**, et aucune ne porte de `Retry-After`.
     */
    public function testLeDepassementEstIndistinguableDUneRechercheInfructueuse(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur);

        // Première tentative : une recherche qui ne trouve rien, sous le plafond. C'est la
        // réponse de référence.
        $this->chercher($jeton, 'Introuvable', 'Personne', '1800-01-01');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $infructueuse = $this->charge();
        $enTetesInfructueuse = $this->enTetesDeQuota();

        // Quatre de plus : le plafond du réglage vaut 5, la sixième le dépasse.
        for ($i = 0; $i < 4; ++$i) {
            $this->chercher($jeton, 'Introuvable', 'Personne', '1800-01-01');
        }

        // La sixième. Elle cherche **une personne qui existe** : sous le plafond, elle
        // aurait rendu un candidat. C'est ce qui fait la valeur de ce test — comparer deux
        // recherches vides ne prouverait rien.
        $this->chercher($jeton, 'Romarin', 'Natron', '1980-07-27');

        $auPlafond = $this->charge();
        $enTetesAuPlafond = $this->enTetesDeQuota();

        self::assertSame(
            200,
            $this->client->getResponse()->getStatusCode(),
            'Le plafond par jeton rend 200, jamais 429 : une 429 annoncerait que la limite existe.',
        );
        self::assertSame(
            $infructueuse,
            $auPlafond,
            'Le corps du plafond doit être celui d’une recherche infructueuse, champ par champ.',
        );
        self::assertSame(
            ['candidats' => [], 'tentativesRestantes' => null],
            $auPlafond,
            'La forme exacte du § 4.2 : aucun candidat, et `tentativesRestantes` toujours nul.',
        );
        self::assertSame(
            $enTetesInfructueuse,
            $enTetesAuPlafond,
            'Aucun en-tête ne doit distinguer les deux réponses.',
        );
        self::assertFalse(
            $this->client->getResponse()->headers->has('Retry-After'),
            'Pas de Retry-After : il dirait au porteur du jeton que la limite existe.',
        );
    }

    /**
     * Le serveur, lui, **sait** la différence — et il l'écrit.
     *
     * C'est l'autre moitié de la règle, et elle n'est pas moins importante : le plafond
     * doit être invisible du client **et** lisible de l'administrateur. `mbr_claim_attempt`
     * distingue `aucun_candidat` de `limite_atteinte` ; la réponse HTTP ne les distingue
     * pas. Un module qui rendrait les deux indistinguables *aussi* côté serveur aurait
     * fermé l'oracle en rendant le sondage invisible, ce qui est le contraire du but.
     */
    public function testLeJournalDistingueCeQueLaReponseNeDistinguePas(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur);

        for ($i = 0; $i < 6; ++$i) {
            $this->chercher($jeton, 'Introuvable', 'Personne', '1800-01-01');
        }

        $depot = self::getContainer()->get(ClaimAttemptRepository::class);
        \assert($depot instanceof ClaimAttemptRepository);

        $lignes = $depot->findBy([], ['id' => 'ASC']);
        self::assertCount(6, $lignes, 'Chaque appel écrit une ligne, y compris au plafond.');

        $issues = array_map(
            static fn (ClaimAttempt $ligne): string => $ligne->getResult()->value,
            array_values($lignes),
        );

        self::assertSame(
            [
                ClaimResult::AucunCandidat->value,
                ClaimResult::AucunCandidat->value,
                ClaimResult::AucunCandidat->value,
                ClaimResult::AucunCandidat->value,
                ClaimResult::AucunCandidat->value,
                ClaimResult::LimiteAtteinte->value,
            ],
            $issues,
            'Les cinq premières sont des recherches vides, la sixième est un plafond — côté serveur seulement.',
        );
    }

    /**
     * Les critères ne sont **jamais** stockés en clair.
     *
     * « Ils désignent une personne vivante, souvent réelle, et ce journal serait sinon
     * exactement la fuite qu'il est censé documenter » (§ 4.3 du modèle). L'empreinte
     * suffit à ce qu'on lui demande : mille tentatives avec mille empreintes différentes
     * sont un balayage.
     */
    public function testLesCriteresNeSontJamaisJournalisesEnClair(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur);

        $this->chercher($jeton, 'Romarin', 'Natron', '1980-07-27');

        $depot = self::getContainer()->get(ClaimAttemptRepository::class);
        \assert($depot instanceof ClaimAttemptRepository);

        $lignes = $depot->findAll();
        self::assertCount(1, $lignes);

        $empreinte = $lignes[0]->getQueryHash();

        self::assertSame(64, \strlen($empreinte), 'Le query_hash est un sha256 hexadécimal.');
        self::assertStringNotContainsStringIgnoringCase('romarin', $empreinte);
        self::assertStringNotContainsStringIgnoringCase('natron', $empreinte);
        self::assertStringNotContainsString('1980', $empreinte);
    }

    /**
     * Deux écritures du même critère, à la casse et aux accents près, donnent **la même**
     * empreinte : « ROMARIN » et « Romarin » ne comptent pas comme deux sondes distinctes.
     */
    public function testLEmpreinteEstCalculeeSurDesCriteresNormalises(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur);

        $this->chercher($jeton, 'ROMARIN', 'Natron', '1980-07-27');
        $this->chercher($jeton, 'romarin', 'natron', '1980-07-27');

        $depot = self::getContainer()->get(ClaimAttemptRepository::class);
        \assert($depot instanceof ClaimAttemptRepository);

        $lignes = $depot->findAll();
        self::assertCount(2, $lignes);
        self::assertSame($lignes[0]->getQueryHash(), $lignes[1]->getQueryHash());
    }

    private function chercher(string $jeton, string $nom, string $prenoms, string $naissance): void
    {
        $this->requete('POST', '/api/inscription/rattachement', [
            'jeton' => $jeton,
            'surname' => $nom,
            'givenNames' => $prenoms,
            'birthDate' => $naissance,
        ]);
    }

    /**
     * Les en-têtes susceptibles de trahir un quota. On compare des **directives
     * nommées**, jamais la chaîne entière d'un en-tête composite : Symfony complète
     * lui-même `Cache-Control`, et une assertion sur la chaîne casserait au prochain
     * composant qui y touche sans que le comportement ait changé.
     *
     * @return array<string, string|null>
     */
    private function enTetesDeQuota(): array
    {
        $enTetes = $this->client->getResponse()->headers;

        $releve = [];

        foreach (['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset', 'X-RateLimit-Retry-After'] as $nom) {
            $releve[$nom] = $enTetes->get($nom);
        }

        return $releve;
    }
}
