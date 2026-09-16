<?php

declare(strict_types=1);

namespace App\Tests\Core;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * `framework.trusted_hosts` est-il ACTIF, et refuse-t-il vraiment un `Host` étranger ?
 *
 * ## Ce que ce test remplace
 *
 * La liste blanche existait depuis longtemps dans `config/packages/framework.yaml`, et
 * elle ne protégeait rien : son repli valait `.*`, c'est-à-dire tout hôte. La mesure qui
 * l'a montré, sur la pile de développement :
 *
 *     curl -H 'Host: evil.attaquant.test' -H 'Cookie: PHPSESSID=<session admin>' \
 *          http://127.0.0.1:8000/api/session
 *     → HTTP 200, avec la charge de session complète de l'administrateur.
 *
 * **Le piège de mesure, qui a déjà trompé une relecture :** `curl` ne sort PAS le cookie
 * de son bocal quand on écrase `Host`, parce que le bocal est indexé par domaine. Sans le
 * `-H 'Cookie: …'` explicite, on mesure un `401` — qui ne prouve rien du tout — et l'on
 * conclut que la protection fonctionne. Elle ne fonctionnait pas.
 *
 * ## Pourquoi ce test passe par le noyau et non par le YAML
 *
 * Lire la valeur dans le fichier ne prouverait qu'une chose : ce qui est écrit. Entre le
 * YAML et le comportement il y a le processeur `default:` (qui retombe sur le paramètre
 * quand la variable est absente **ou vide**), le découpage sur les virgules fait par
 * `Kernel::boot()`, et la compilation des motifs par `Request::setTrustedHosts()`. Chacun
 * de ces trois étages peut annuler la protection sans rien changer au fichier. On mesure
 * donc sur un noyau réellement démarré, par des requêtes HTTP complètes.
 *
 * ## La cible : une route qui n'existe pas
 *
 * `/api/ceci-n-existe-pas` est choisi exprès. Le contrôle de `Host` a lieu dans
 * `Request::getHost()`, appelé par `RouterListener` **avant** tout contrôleur et avant
 * toute requête en base. Deux requêtes identiques au seul `Host` près rendent donc `404`
 * ou `400`, et rien d'autre ne peut expliquer l'écart : ni la base, ni la session, ni un
 * contrôleur. C'est la plus courte distance entre la cause et la mesure.
 */
final class HotesAutorisesTest extends WebTestCase
{
    /**
     * Une route volontairement absente : voir l'en-tête. Le refus contre `404` isole le
     * seul contrôle qui nous intéresse.
     */
    private const CIBLE = '/api/ceci-n-existe-pas';

    /**
     * La même chose HORS du préfixe `/api`, et elle est ici pour une raison précise.
     *
     * Le refus d'un `Host` est une `SuspiciousOperationException`, que `RouterListener`
     * convertit en `BadRequestHttpException` — donc `400`, le code attendu. Sur `/api`,
     * {@see \App\Module\Core\EventSubscriber\ApiExceptionSubscriber} retraduit **toute**
     * `BadRequestHttpException` en `422 donnees_invalides`, parce que la seule qu'il
     * connaissait venait de `json_login` avec un corps JSON illisible (§ 15.2 du contrat,
     * qui ne prévoit pas de `400`).
     *
     * La protection, elle, est intacte : le refus a lieu dans `RouterListener`, avant le
     * pare-feu et avant tout contrôleur. Seul le CODE diffère, et il diffère sur le seul
     * préfixe `/api`. Cette cible hors préfixe le démontre : même requête, même hôte
     * étranger, `400` ici et `422` là-bas. C'est ce qui permet de dire que l'écart vient
     * du souscripteur et non du contrôle d'hôte — et de le corriger un jour sans avoir à
     * redécouvrir où il se produisait.
     */
    private const CIBLE_HORS_API = '/ceci-n-existe-pas';

    /**
     * Le code rendu sur `/api` pour un hôte refusé : `422`, et non `400`. Voir
     * {@see self::CIBLE_HORS_API} pour l'explication et la démonstration.
     */
    private const REFUS_SUR_API = 422;

    /**
     * Le repli de développement, recopié ici **pour être comparé** à ce que le conteneur
     * porte vraiment (voir {@see testLeRepliDeDeveloppementEstCeluiQueLesTablesDecrivent}).
     *
     * Les deux tables d'hôtes ci-dessous sont dérivées de cette expression à la main : si
     * elle change sans qu'on les revoie, elles décriraient un contrat périmé sans le dire.
     * C'est ce que l'assertion d'égalité empêche — modifier le YAML fait rougir ce test,
     * et la seule façon de le rendre vert est de rouvrir les tables.
     */
    private const REPLI_ATTENDU = '^(localhost|127\.0\.0\.1|\[::1\]|api)(:\d+)?$';

    /**
     * Les hôtes que le développement local doit servir. Chacun correspond à un chemin
     * d'appel réel, et non à une variation d'écriture :
     *
     *   localhost          la sonde de l'image (`HEALTHCHECK … http://localhost:8000`)
     *   localhost:8080     le front servi par compose, dans le navigateur
     *   127.0.0.1          l'API jointe directement
     *   127.0.0.1:8000     idem, avec le port publié par compose
     *   127.0.0.1:5173     le serveur Vite, qui relaie sans réécrire le `Host`
     *   [::1]              la même boucle locale en IPv6
     *   [::1]:8000         idem, avec port
     *   api                le nom de service compose, depuis le réseau interne
     *   api:8000           idem, avec port
     *
     * @return list<string>
     */
    private static function hotesAcceptes(): array
    {
        return [
            'localhost',
            'localhost:8080',
            '127.0.0.1',
            '127.0.0.1:8000',
            '127.0.0.1:5173',
            '[::1]',
            '[::1]:8000',
            'api',
            'api:8000',
        ];
    }

    /**
     * Les hôtes qui doivent être refusés. Les six derniers ne sont pas du remplissage :
     * ce sont les contournements que produit une expression **non ancrée**. Un motif
     * `localhost` sans `^` ni `$` accepterait `localhost.attaquant.test` et
     * `attaquant-localhost.test` — et c'est très exactement le trou qu'on croit fermer en
     * posant une liste blanche. `Request::setTrustedHosts()` n'ajoute pas les ancres pour
     * nous : il se borne à envelopper le motif dans `{…}i`.
     *
     * @return list<string>
     */
    private static function hotesRefuses(): array
    {
        return [
            'evil.attaquant.test',
            'heritage.example.net',
            'localhost.attaquant.test',
            'attaquant-localhost.test',
            '127.0.0.1.attaquant.test',
            'api.attaquant.test',
            'xlocalhost',
            'apix',
        ];
    }

    /**
     * Le contrat est-il toujours celui que les deux tables décrivent ?
     *
     * On compare la valeur EFFECTIVE portée par le noyau démarré, et non le texte du
     * fichier. `Request::getTrustedHosts()` rend les motifs tels que le noyau les a
     * compilés : un motif par entrée, enveloppé dans `{…}i`. S'il en rendait zéro, la
     * protection serait inactive — c'était l'état de départ — et toutes les assertions de
     * refus plus bas seraient vertes pour la mauvaise raison. C'est pourquoi la TAILLE
     * est affirmée ici, et pas seulement le contenu.
     */
    public function testLeRepliDeDeveloppementEstCeluiQueLesTablesDecrivent(): void
    {
        self::createClient();

        $motifs = Request::getTrustedHosts();

        self::assertCount(
            1,
            $motifs,
            'Le noyau doit porter exactement un motif d’hôte de confiance. Zéro signifie que '
            .'`framework.trusted_hosts` est inactif et que rien n’est protégé ; plus d’un '
            .'signifie que `HERITAGE_HOTES_AUTORISES` a été renseignée pour cette suite, et '
            .'les tables d’hôtes de ce test ne décrivent plus ce qui est mesuré.',
        );

        self::assertSame(
            \sprintf('{%s}i', self::REPLI_ATTENDU),
            $motifs[0],
            'Le repli `app.hotes_autorises_defaut` de config/packages/framework.yaml a changé. '
            .'Ce test ne cherche pas à l’imposer : il exige que les deux tables d’hôtes '
            .'ci-dessus soient revues en même temps, faute de quoi elles décriraient un '
            .'contrat périmé sans jamais le signaler.',
        );
    }

    /**
     * Le cas légitime. Sans lui, un `trusted_hosts` cassé — une expression qui ne
     * correspond à rien — passerait pour une protection réussie : tout serait refusé, y
     * compris le développement, et seuls les tests de refus seraient verts.
     *
     * C'est le défaut que le repli `.*` cherchait à éviter et qui a coûté la protection
     * entière. Il est désormais mesuré au lieu d'être redouté.
     */
    public function testUnHoteLegitimeEstServi(): void
    {
        $acceptes = self::hotesAcceptes();

        self::assertCount(9, $acceptes, 'La table des hôtes acceptés a changé de taille : un parcours dont on ne connaît pas la cardinalité ne prouve rien.');

        $client = self::createClient();

        foreach ($acceptes as $hote) {
            $client->request('GET', \sprintf('http://%s%s', $hote, self::CIBLE));

            self::assertSame(
                404,
                $client->getResponse()->getStatusCode(),
                \sprintf(
                    'L’hôte « %s » est du développement local légitime et doit être servi. Un refus '
                    .'ici veut dire que la liste blanche met l’application par terre — le cas que '
                    .'le repli `.*` voulait éviter.',
                    $hote,
                ),
            );
        }
    }

    /**
     * Le cas attaquant : c'est la mesure de départ, refaite à l'envers.
     */
    public function testUnHoteEtrangerEstRefuse(): void
    {
        $refuses = self::hotesRefuses();

        self::assertCount(8, $refuses, 'La table des hôtes refusés a changé de taille : un parcours dont on ne connaît pas la cardinalité ne prouve rien.');

        $client = self::createClient();

        foreach ($refuses as $hote) {
            $client->request('GET', \sprintf('http://%s%s', $hote, self::CIBLE));

            self::assertSame(
                self::REFUS_SUR_API,
                $client->getResponse()->getStatusCode(),
                \sprintf(
                    'L’hôte « %s » doit être refusé par `framework.trusted_hosts`. Un 404 ici veut '
                    .'dire que la requête a été routée normalement : le `Host` du client est '
                    .'accepté tel quel, et une réponse authentifiée partirait vers un domaine '
                    .'attaquant.',
                    $hote,
                ),
            );
        }
    }

    /**
     * Le refus, mesuré hors du préfixe `/api`, rend bien le `400` attendu.
     *
     * Deux choses d'un coup, et c'est pour cela que ce test existe séparément :
     *
     *   1. le contrôle d'hôte produit le code juste — `400`, pas `422` ;
     *   2. le `422` mesuré sur `/api` vient donc du seul
     *      {@see \App\Module\Core\EventSubscriber\ApiExceptionSubscriber}, qui replie
     *      toute `BadRequestHttpException` sur `422 donnees_invalides`.
     *
     * Le jour où ce souscripteur distinguera un hôte refusé d'un corps JSON illisible,
     * c'est {@see self::REFUS_SUR_API} qu'il faudra corriger, et ce test-ci restera vrai.
     * Il est l'ancre qui dit où est la cause.
     */
    public function testHorsDuPrefixeApiLeRefusRendBienQuatreCents(): void
    {
        $client = self::createClient();

        $client->request('GET', 'http://evil.attaquant.test'.self::CIBLE_HORS_API);
        self::assertSame(
            400,
            $client->getResponse()->getStatusCode(),
            'Hors `/api`, un hôte refusé doit rendre le `400` de `BadRequestHttpException`, sans '
            .'retraduction. Si ce code change, ce n’est plus le contrôle d’hôte qu’on mesure.',
        );

        $client->request('GET', 'http://localhost'.self::CIBLE_HORS_API);
        self::assertSame(
            404,
            $client->getResponse()->getStatusCode(),
            'Le témoin : même chemin, hôte légitime. Sans lui, un `400` universel — une liste '
            .'blanche qui refuse tout — passerait pour un succès.',
        );
    }

    /**
     * Le refus doit précéder l'authentification, et non la suivre.
     *
     * C'est la forme exacte du défaut mesuré au départ : la session était lue, la charge
     * de l'administrateur composée, puis rendue — à un client qui prétendait parler à
     * `evil.attaquant.test`. Un `400` sur `/api/session` **sans session** ne prouverait
     * pas grand-chose ; ce qu'il faut vérifier, c'est que le `Host` est jugé avant que
     * quoi que ce soit d'autre n'ait lieu. `/api/connexion` sert de témoin : c'est une
     * route `POST` qui existe, avec des identifiants recevables dans le corps. Elle doit
     * être refusée, et non rendre la `200` ou la `401` qu'elle rendrait si la requête
     * parvenait au pare-feu.
     *
     * Le corps est du JSON valide et complet, exprès : le code de refus mesuré est
     * `422 donnees_invalides`, qui est aussi celui d'un corps illisible. Un corps
     * VOLONTAIREMENT correct est ce qui distingue les deux — s'il était vide ou malformé,
     * ce test passerait au vert même sans aucune liste blanche.
     */
    public function testLeRefusPrecedeToutTraitementDeLaRequete(): void
    {
        $client = self::createClient();

        $client->request(
            'POST',
            'http://evil.attaquant.test/api/connexion',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'amorcage@heritage.test', 'password' => 'peu-importe'], \JSON_THROW_ON_ERROR),
        );

        self::assertSame(
            self::REFUS_SUR_API,
            $client->getResponse()->getStatusCode(),
            'Le contrôle du `Host` doit avoir lieu dans `RouterListener`, donc avant le pare-feu, '
            .'le contrôleur et la base. Une 401 ou une 200 ici signifie que la requête a été '
            .'traitée et qu’une charge de session peut partir vers un domaine attaquant.',
        );
    }
}
