<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }

    /**
     * En test, chaque processus compile son conteneur dans SON PROPRE dossier.
     *
     * Motif, mesuré et non supposé. Sur cette machine il y a en permanence plusieurs
     * suites PHPUnit en vol — une par agent. Quand deux d'entre elles démarrent au
     * même instant sur un cache froid, les deux écrivent le conteneur compilé dans
     * le même `var/cache/test/`, et Windows refuse le renommage concurrent :
     *
     *     Cannot rename "var\cache\test\App3A6A.tmp"
     *     to "var/cache/test/App_KernelTestDebugContainer.xml.meta"
     *     Accès refusé (code: 5)
     *
     * Deux exécutions simultanées ont rendu 76 erreurs identiques de cette forme,
     * toutes sans rapport avec le code testé. Comme pour la base SQLite et les pools
     * de cache, le remède est de ne rien partager entre processus.
     *
     * Le dossier est désigné par `tests/bootstrap.php`, qui le nomme d'après le
     * numéro de processus et le supprime en fin d'exécution. En son absence — un
     * `bin/console --env=test` lancé à la main, qui ne passe pas par le bootstrap —
     * on retombe sur le comportement normal de Symfony.
     *
     * Le coût est une compilation de conteneur par processus. C'est le prix du
     * déterminisme, et il ne se paie qu'en test : `dev` et `prod` ne sont pas touchés.
     */
    public function getCacheDir(): string
    {
        if ('test' === $this->environment && isset($_SERVER['TEST_KERNEL_CACHE_DIR'])) {
            $dossier = $_SERVER['TEST_KERNEL_CACHE_DIR'];
            \assert(\is_string($dossier));

            return $dossier;
        }

        return parent::getCacheDir();
    }
}
