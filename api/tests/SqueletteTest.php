<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Test de fumée du squelette.
 *
 * Il ne vérifie aucune règle métier : il vérifie que le conteneur se compile et
 * démarre. C'est le filet qui attrape le plus tôt possible une configuration
 * cassée par l'un des modules — un service mal déclaré, un mapping Doctrine
 * pointant vers un dossier absent, un fichier `config/routes/<module>.yaml`
 * invalide — sans attendre qu'une requête HTTP le révèle.
 */
final class SqueletteTest extends KernelTestCase
{
    public function testLeConteneurDemarre(): void
    {
        self::bootKernel();

        $conteneur = self::getContainer();

        self::assertSame('test', self::$kernel->getEnvironment());
        self::assertTrue($conteneur->has('router'), 'Le routeur doit être disponible dans le conteneur.');
        self::assertTrue($conteneur->has('doctrine'), 'Doctrine doit être enregistré.');
    }

    /**
     * L'arborescence modulaire est un contrat entre les agents qui travaillent en
     * parallèle : si un dossier de module disparaît, le mapping Doctrine et le
     * chargement des routes tombent en même temps. On le vérifie explicitement.
     *
     * **Huit depuis le 2026-09-23** : `Question` (la page « Questions », ADR 0008). Il
     * n'y entre qu'avec son contrôleur et son fichier de routes — un module listé ici
     * sans eux ferait échouer ce test sur une absence voulue, et l'on apprendrait à le
     * lire comme du bruit.
     *
     * **Neuf depuis le 2026-09-27** : `Tirage` (les tirages au sort récurrents, ADR 0009).
     *
     * **Huit de nouveau depuis le 2026-10-06** : `Gedcom` est supprimé avec l'import
     * GEDCOM (ADR 0018) — dossiers, fichier de routes et fichier de services compris.
     */
    public function testLesHuitModulesSontPresents(): void
    {
        $racine = \dirname(__DIR__);

        foreach (['Core', 'Tree', 'Membership', 'Proposal', 'Media', 'Timeline', 'Question', 'Tirage'] as $module) {
            foreach (['Controller', 'Entity', 'Enum', 'Repository', 'Service'] as $couche) {
                self::assertDirectoryExists(
                    $racine.'/src/Module/'.$module.'/'.$couche,
                    \sprintf('Le dossier %s/%s du module est attendu par la configuration.', $module, $couche),
                );
            }

            self::assertFileExists(
                $racine.'/config/routes/'.strtolower($module).'.yaml',
                \sprintf('Le module %s doit avoir son propre fichier de routes.', $module),
            );
            self::assertFileExists(
                $racine.'/config/services/'.strtolower($module).'.yaml',
                \sprintf('Le module %s doit avoir son propre fichier de services.', $module),
            );
        }
    }

    /**
     * Garde-fou sur l'ORDRE DE CHARGEMENT des fichiers de services.
     *
     * `config/services.yaml` importe `services/*.yaml`, et le prototype `App\:` qui
     * enregistre toutes les classes de `src/` vit dans `services/_autoload.yaml`. Ce
     * n'est pas un détail d'esthétique : `YamlFileLoader::loadContent()` traite
     * toujours `parseImports()` avant `parseDefinitions()`, et le prototype appelle
     * `setDefinition()` sans condition — donc tout fichier chargé APRÈS lui l'emporte,
     * et tout fichier chargé AVANT lui est écrasé en silence.
     *
     * Tant que le prototype était dans `services.yaml`, il passait forcément en
     * dernier et écrasait les `arguments`, `tags` et `_instanceof` de chaque module,
     * sans le moindre message : on obtenait une erreur d'autowiring sur un service
     * pourtant explicitement configuré. Le préfixe « _ » (0x5F, avant toutes les
     * minuscules) est ce qui place désormais le prototype en tête du glob.
     *
     * Un commentaire ne protège pas un invariant ; ce test le fait. Si quelqu'un
     * renomme le fichier, il voit un test rouge plutôt qu'un conteneur cassé trois
     * modules plus loin.
     */
    public function testLePrototypeEstChargeAvantLesFichiersDeModule(): void
    {
        $dossier = \dirname(__DIR__).'/config/services';

        $fichiers = glob($dossier.'/*.yaml');
        self::assertIsArray($fichiers);

        // On AFFIRME LA TAILLE de l'ensemble parcouru, on ne se contente pas de le
        // parcourir. Un contrôle qui itère sur un ensemble devenu vide passe au vert
        // en ne vérifiant plus rien : il cesse d'informer sans cesser de rassurer.
        // Attendu : les sept fichiers de module, plus `_autoload.yaml`.
        self::assertGreaterThanOrEqual(
            8,
            \count($fichiers),
            \sprintf(
                'config/services/ doit contenir au moins les 7 fichiers de module et _autoload.yaml, '
                .'or le glob en rend %d : %s',
                \count($fichiers),
                implode(', ', array_map('basename', $fichiers)),
            ),
        );

        // `glob()` trie par défaut, et c'est exactement ce tri que Symfony applique
        // en développant le motif de l'import.
        $premier = basename($fichiers[0]);

        self::assertSame(
            '_autoload.yaml',
            $premier,
            "Le prototype «App\» doit être le PREMIER fichier chargé de config/services/, "
            ."or le glob commence par «{$premier}». Un fichier de module chargé avant lui "
            .'se ferait écraser en silence : ses arguments, tags et _instanceof seraient '
            .'perdus sans message. Ne pas renommer _autoload.yaml.',
        );

        // Le prototype ne doit pas non plus réapparaître dans services.yaml, sinon il
        // repasserait en dernier et on retomberait exactement sur le défaut corrigé.
        //
        // On analyse le YAML plutôt que le texte : le fichier PARLE du prototype dans
        // son en-tête pour expliquer pourquoi il n'y est plus, et une recherche de
        // chaîne prendrait ce commentaire pour une déclaration.
        $pointEntree = Yaml::parseFile(\dirname(__DIR__).'/config/services.yaml');
        self::assertIsArray($pointEntree);
        self::assertArrayNotHasKey(
            'services',
            $pointEntree,
            'config/services.yaml ne doit déclarer aucun service : les définitions de ce '
            .'fichier sont chargées APRÈS les imports (parseImports avant '
            .'parseDefinitions) et écraseraient celles des modules.',
        );
    }
}
