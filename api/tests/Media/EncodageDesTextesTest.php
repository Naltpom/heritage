<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Tests\Core\CoreWebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  UN TEXTE QUI N'EST PAS EN UTF-8 EST REFUSÉ — ET UN ACCENT LÉGITIME PASSE
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Deux tests qui ne se lisent pas séparément, comme ceux de
 * {@see PlafondDeDepotTest}. Le premier vérifie le refus ; **le second vérifie que ce
 * refus n'a pas emporté les accents avec lui**, et c'est celui-là qui coûterait le plus
 * cher s'il manquait. Ce projet porte des légendes d'actes en français : « Élise »,
 * « Fougerie », « décédée ». Un garde-fou qui refuserait tout octet au-dessus de 0x7F
 * passerait le premier test et rendrait l'application inutilisable, sans qu'aucune
 * mesure ne le dise.
 *
 * ── L'incident, et pourquoi c'est le serveur qu'on corrige ──────────────────────
 *
 * Une légende envoyée en Latin-1 traversait le contrôleur sans être regardée et cassait
 * chez PostgreSQL, qui n'accepte que de l'UTF-8 : l'API rendait **500** (incidents
 * `6ade9d62220a8fcd`, puis `1c65c7044967e174` en reproduction le 17 septembre 2026).
 * Le client envoyait une entrée que le serveur ne sait pas stocker ; le serveur devait
 * la refuser en le disant, pas s'effondrer dessus. Voir
 * {@see \App\Module\Media\Controller\MediaController::chaine()} — c'est là qu'est écrit
 * pourquoi ce garde-fou appartient à ce module et à aucun autre.
 *
 * ── Ce que ces tests prouvent, et ce qu'ils ne prouvent pas ─────────────────────
 *
 * Ils prouvent **le refus à l'entrée**, qui est le contrat corrigé. Ils ne prouvent
 * pas l'effondrement d'origine : le harnais tourne sur SQLite, qui stocke sans broncher
 * n'importe quelle suite d'octets. Le `500` de PostgreSQL a été mesuré à part, contre la
 * pile de développement, et c'est ce qui a motivé la correction — pas l'inverse.
 *
 * ── Pourquoi aucun de ces deux dépôts n'écrit de fichier ────────────────────────
 *
 * Le contrôleur construit `RequeteDeTeleversement` **avant** d'appeler le service : la
 * légende fautive est donc refusée sans que rien soit stocké. Et la légende valide, elle,
 * va bien jusqu'au service — ce qui est exactement ce qu'on veut prouver — mais y est
 * arrêtée à l'**étape 1**, le régime du lecteur (§ 12.1 du contrat), qui précède la
 * taille, le reniflage, la purge et l'écriture dans
 * {@see \App\Module\Media\Service\TeleversementMedia::televerser()}. C'est voulu : aucun
 * de ces tests n'a besoin de `MEDIA_RACINE`, donc aucun ne peut laisser un fichier
 * derrière lui.
 */
final class EncodageDesTextesTest extends CoreWebTestCase
{
    /**
     * « Él » en Latin-1 : `0x4C 0xC9 0x6C`.
     *
     * Écrit en échappements pour que **ce fichier-ci reste lisible en UTF-8** — un test
     * sur l'encodage qui ne serait lui-même pas décodable serait sauté en silence par
     * tout balayage du dépôt, et c'est précisément ce que `docs/controle-donnees-reelles.md`
     * § 6 interdit.
     *
     * Invalide par construction : `0xC9` annonce une séquence de deux octets et attend
     * une continuation entre `0x80` et `0xBF` ; `0x6C` n'en est pas une.
     */
    private const string LEGENDE_LATIN1 = "L\xC9lise au mariage";

    /** La même légende, correctement encodée — celle qu'un client bien réglé envoie. */
    private const string LEGENDE_UTF8 = 'Élise au mariage';

    /**
     * L'octet fautif entre, et ressort en `422` avec le champ nommé.
     *
     * On assertionne **le champ** et pas seulement le code : « données invalides » sans
     * indication de champ laisse le déposant chercher dans un formulaire qui porte aussi
     * un fichier, une date et un sujet.
     */
    public function testUneLegendeQuiNEstPasEnUtf8EstRefuseeEnQuatreCentVingtDeux(): void
    {
        self::assertFalse(
            mb_check_encoding(self::LEGENDE_LATIN1, 'UTF-8'),
            'La légende de ce test doit bien être de l’UTF-8 invalide, sans quoi il ne prouve rien.',
        );

        $this->creerUtilisateur('deposante@example.net');
        $this->connexion('deposante@example.net');
        self::assertResponseIsSuccessful('La connexion de la déposante doit réussir.');

        $this->televerser(self::LEGENDE_LATIN1);

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $this->client->getResponse()->getStatusCode(),
            'Une entrée que le serveur ne sait pas stocker se refuse ; elle ne le fait pas tomber en 500.',
        );

        $charge = $this->charge();
        self::assertIsArray($charge['erreur']);
        self::assertSame('donnees_invalides', $charge['erreur']['code']);
        self::assertIsArray($charge['erreur']['champs']);
        self::assertArrayHasKey(
            'caption',
            $charge['erreur']['champs'],
            'Le refus nomme le champ fautif : le formulaire porte aussi un fichier, une date et un sujet.',
        );
    }

    /**
     * **Le test qui garde l'autre.** « Élise » en UTF-8 doit franchir la garde.
     *
     * On ne peut pas l'affirmer par un `201` : le compte d'essai n'a pas de rattachement,
     * et le service refuse toute écriture à un régime en attente (§ 12.1). Ce qu'on
     * affirme est plus exact, et suffit — **le dépôt est allé jusqu'au service**, donc la
     * légende a franchi le contrôleur, seul endroit où l'encodage est jugé. Si le
     * garde-fou se mettait à refuser les accents, la réponse deviendrait un `422` portant
     * le champ `caption`, et les deux assertions ci-dessous tomberaient au rouge.
     */
    public function testUneLegendeAccentueeCorrectementEncodeeFranchitLaGarde(): void
    {
        $this->creerUtilisateur('deposante@example.net');
        $this->connexion('deposante@example.net');
        self::assertResponseIsSuccessful('La connexion de la déposante doit réussir.');

        $this->televerser(self::LEGENDE_UTF8);

        $reponse = $this->client->getResponse();
        $charge = $this->charge();
        self::assertIsArray($charge['erreur']);

        $champs = $charge['erreur']['champs'];

        self::assertTrue(
            null === $champs || (\is_array($champs) && !\array_key_exists('caption', $champs)),
            \sprintf(
                'Une légende française correctement encodée doit passer la garde d’encodage ; reçu %d %s.',
                $reponse->getStatusCode(),
                json_encode($charge['erreur'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            ),
        );

        // Et le dépôt est bien allé plus loin que le contrôleur : c'est le service qui
        // l'arrête, sur le régime du compte d'essai — pas sa légende.
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $reponse->getStatusCode(),
            'La légende est acceptée par le contrôleur ; le dépôt atteint le service, qui l’arrête au régime (§ 12.1).',
        );
    }

    /**
     * Un dépôt complet — fichier JPEG réel, énumérations valides — dont **seule la
     * légende** varie. Tout le reste est correct, pour que le code de retour ne puisse
     * s'expliquer que par elle.
     *
     * Le sujet `999999` n'existe pas, et c'est délibéré : voir l'en-tête de la classe.
     */
    private function televerser(string $legende): void
    {
        $chemin = tempnam(sys_get_temp_dir(), 'heritage-encodage-');
        self::assertIsString($chemin, 'Le fichier d’essai doit pouvoir être créé.');

        file_put_contents($chemin, FabriqueDeFichiers::jpegNu(40, 30));

        try {
            $this->client->request(
                'POST',
                '/api/medias',
                parameters: [
                    'kind' => 'preuve',
                    'subjectKind' => 'personne',
                    'subjectId' => '999999',
                    'caption' => $legende,
                ],
                // `test: true` : le fichier n'est pas passé par `move_uploaded_file()`.
                files: ['fichier' => new UploadedFile($chemin, 'acte.jpg', 'image/jpeg', null, true)],
                server: ['HTTP_ACCEPT' => 'application/json'],
            );
        } finally {
            @unlink($chemin);
        }
    }
}
