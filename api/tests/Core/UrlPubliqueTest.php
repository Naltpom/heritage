<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Service\UrlPublique;
use App\Module\Membership\Service\ServiceDInvitations;
use PHPUnit\Framework\TestCase;

/**
 * L'adresse publique est **configurée**, jamais déduite de la requête.
 *
 * Ce test ferme une vulnérabilité réelle. Le lien d'invitation était bâti sur
 * `Request::getSchemeAndHttpHost()` ; l'en-tête `Host` vient du client, l'API lui fait
 * confiance (`trusted_proxies`), et aucun `trusted_hosts` ne le bornait. Une requête au
 * `Host` falsifié faisait donc fabriquer **par nous** un lien vers un domaine attaquant,
 * expédié **dans un courriel authentique, depuis notre adresse**, à une personne réelle
 * de la famille — qui n'avait aucun moyen de le distinguer d'un vrai.
 *
 * La classe n'a pas de `RequestStack` : c'est la garantie, et le dernier cas la vérifie
 * par réflexion pour qu'un remaniement ne la réintroduise pas « pour faire pratique ».
 */
final class UrlPubliqueTest extends TestCase
{
    public function testElleBatitUnLienDepuisLaConfiguration(): void
    {
        $url = new UrlPublique('https://heritage.example.net');

        self::assertSame('https://heritage.example.net', $url->base());
        self::assertSame('https://heritage.example.net/inscription/abc123', $url->versLeFront('/inscription/abc123'));
        self::assertSame('heritage.example.net', $url->hote());
    }

    /** Une barre oblique finale de trop ne doit pas produire `//inscription`. */
    public function testLaBarreObliqueFinaleEstAbsorbee(): void
    {
        $url = new UrlPublique('https://heritage.example.net/');

        self::assertSame('https://heritage.example.net/inscription/x', $url->versLeFront('/inscription/x'));
        self::assertSame('https://heritage.example.net/inscription/x', $url->versLeFront('inscription/x'));
    }

    /**
     * **Pas de repli silencieux.** Un repli sur le `Host` de la requête rouvrirait le
     * trou au moment précis où la configuration manque, c'est-à-dire quand personne ne
     * regarde. On préfère une erreur franche à un courriel d'hameçonnage signé par nous.
     */
    public function testSansConfigurationElleLeveAuLieuDeDeviner(): void
    {
        foreach ([null, '', '   '] as $valeur) {
            try {
                (new UrlPublique($valeur))->base();
                self::fail('Une adresse publique absente doit lever, pas être devinée.');
            } catch (\LogicException $erreur) {
                self::assertStringContainsString('HERITAGE_URL_PUBLIQUE', $erreur->getMessage());
            }
        }
    }

    /**
     * Le garde-fou structurel : **rien de ce service ne vient de la requête**.
     *
     * Une liste d'exemples ne prouve que les exemples. Cette assertion-ci prouve qu'il
     * n'existe aucun chemin par lequel une valeur envoyée par le client entrerait dans
     * une URL sortante — c'est-à-dire la vulnérabilité elle-même, et non ses symptômes.
     */
    public function testAucuneDependanceALaRequete(): void
    {
        $code = $this->codeSansCommentaires(UrlPublique::class);

        foreach (['RequestStack', 'getSchemeAndHttpHost', 'getHost', 'HTTP_HOST'] as $interdit) {
            self::assertStringNotContainsString(
                $interdit,
                $code,
                \sprintf('L’adresse publique ne doit rien tirer de la requête (« %s »).', $interdit),
            );
        }

        $constructeur = (new \ReflectionClass(UrlPublique::class))->getConstructor();
        self::assertNotNull($constructeur);
        self::assertCount(1, $constructeur->getParameters(), 'Un seul argument : l’adresse configurée.');
    }

    /**
     * Et la même garantie là où elle compte vraiment : le service qui **envoie les
     * courriels** ne doit plus connaître la requête du tout.
     */
    public function testLeServiceDInvitationsNeLitPlusLaRequete(): void
    {
        $code = $this->codeSansCommentaires(ServiceDInvitations::class);

        self::assertStringNotContainsString('getSchemeAndHttpHost', $code);
        self::assertStringNotContainsString('RequestStack', $code);
        self::assertStringContainsString('UrlPublique', $code);
    }

    /**
     * Le **code** d'une classe, commentaires retirés.
     *
     * Indispensable ici : les deux classes visées *expliquent* la vulnérabilité dans leur
     * en-tête, et nomment donc `getSchemeAndHttpHost` en toutes lettres. Chercher dans le
     * fichier brut ferait tomber le test sur sa propre documentation — et la réaction
     * naturelle serait d'effacer l'explication, c'est-à-dire de perdre le motif tout en
     * gardant le test. On lit ce que PHP exécute, pas ce qu'on lui raconte.
     */
    private function codeSansCommentaires(string $classe): string
    {
        $source = (string) file_get_contents((string) (new \ReflectionClass($classe))->getFileName());

        $code = '';
        foreach (token_get_all($source) as $jeton) {
            if (\is_array($jeton) && \in_array($jeton[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= \is_array($jeton) ? $jeton[1] : $jeton;
        }

        return $code;
    }
}
