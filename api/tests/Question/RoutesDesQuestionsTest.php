<?php

declare(strict_types=1);

namespace App\Tests\Question;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Question\Entity\Question;
use App\Module\Question\Enum\EtatDeQuestion;
use App\Module\Question\Repository\QuestionRepository;
use App\Tests\Membership\Double\ArbreEnMemoire as ArbreDeMembership;
use App\Tests\Proposal\Double\ArbreEnMemoire as ArbreDeProposal;
use App\Tests\Tree\ArbreWebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les quatre routes de la page « Questions », **de bout en bout et en vrai HTTP**.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  CE QUI EST RÉEL ICI, ET CE QUI NE L'EST PAS.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * **Réels** : le pare-feu, la session, les quatre routes, le contrôleur, le sérialiseur,
 * la formule du cercle de `Membership`, l'adaptateur de `Tree`, le service de dépôt de
 * `Proposal`, et la vraie base `qst_question`.
 *
 * **Doublés, et pas par ce fichier** : les deux ports `PortArbre` de `Membership` et de
 * `Proposal`, que `when@test` remplace pour tout l'environnement. On les **peuple** avec
 * l'arbre semé, faute de quoi rien ne fonctionnerait — voir
 * {@see refleterLArbreDansLesDoublesDesAutresModules()}, qui dit pourquoi et ce que cela
 * ne relâche pas.
 *
 * {@see PageDesQuestionsTest} éprouve la logique avec des ports doublés ; **celui-ci
 * éprouve le câblage**, qui est exactement ce qu'un test à doubles ne peut pas voir. Un
 * alias manquant dans `config/services/question.yaml` ne fait rougir aucun test unitaire
 * et fait tomber la page entière.
 *
 * Il hérite du harnais de `Tree` plutôt que d'en monter un second : celui-ci sème déjà un
 * arbre de six personnes inventées, sait créer un compte et l'ouvrir. Trois recettes de
 * remise à zéro ont déjà divergé dans ce dépôt ; on n'en écrit pas une quatrième.
 *
 * ## Le rattachement est ce qui donne un cercle — et donc des questions
 *
 * Un compte sans `mbr_member` **actif** relève du régime `Aucun` : pas de cercle du tout,
 * donc aucune question sur une personne vivante. C'est le défaut fermé de l'ADR 0003, et
 * on le vérifie plutôt que de le contourner.
 *
 * Aucune personne réelle : l'arbre semé est celui du harnais, entièrement inventé.
 */
final class RoutesDesQuestionsTest extends ArbreWebTestCase
{
    private const string OPALE = 'opale@example.invalid';
    private const string TUF = 'tuf@example.invalid';

    protected function setUp(): void
    {
        parent::setUp();
        $this->refleterLArbreDansLesDoublesDesAutresModules();
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  POURQUOI CE TEST DOIT RECOPIER L'ARBRE DANS DEUX DOUBLES D'AUTRES MODULES.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * L'environnement de test remplace **globalement** deux ports par des doubles en
     * mémoire, et c'est le bon choix pour les suites qui les possèdent :
     *
     * ```
     * config/services/membership.yaml  when@test   Membership\…\PortArbre → double
     * config/services/proposal.yaml    when@test   Proposal\…\PortArbre   → double
     * ```
     *
     * Conséquence pour **ce** fichier, qui veut éprouver le câblage réel : le cercle de
     * `Membership` ne voit pas l'arbre semé — donc aucune question n'est servie —, et le
     * dépôt de `Proposal` ne trouve pas la fiche visée — donc aucune réponse n'aboutit.
     * **Les deux échecs sont muets** : une page vide et un refus, tous deux plausibles.
     * C'est mesuré, pas supposé : la première version de ce fichier rendait
     * `{"questions":[],"total":0}` avec un `200`, sans rien pour dire pourquoi.
     *
     * On recopie donc l'arbre semé dans les deux doubles. Ce n'est **pas** un
     * contournement du filtre : la vraie formule du cercle tourne, sur les vraies
     * données, et c'est elle qui décide. Seule sa source de lecture change.
     *
     * *C'est le geste que `tests/Tree` a déjà dû faire pour l'écriture* — « le test de
     * `Tree` réinjecte l'implémentation réelle dans son propre conteneur pour combler
     * exactement ce trou » (`config/services/tree.yaml`). On peuple ici plutôt qu'on ne
     * réinjecte, parce que les doubles exposent une API de peuplement et que
     * reconstruire deux chaînes de services à la main en figerait les dépendances.
     */
    private function refleterLArbreDansLesDoublesDesAutresModules(): void
    {
        ArbreDeMembership::reinitialiser();
        ArbreDeProposal::oublierTout();

        $proposal = self::getContainer()->get(ArbreDeProposal::class);
        \assert($proposal instanceof ArbreDeProposal);

        foreach ($this->personnes as $personne) {
            $id = $personne->getId();
            \assert(null !== $id);

            $naissance = $personne->getBirth()->getMax();

            ArbreDeMembership::ajouterPersonne(
                $id,
                $personne->getGivenNames(),
                $personne->getSurname(),
                // `deceased` suffit ici : ce double ne sert qu'à donner une topologie au
                // calcul du cercle. La définition fine de « vivant » est éprouvée chez
                // `Tree`, sur la vraie règle.
                !$personne->isDeceased(),
                null === $naissance ? null : (int) $naissance->format('Y'),
                libelle: $personne->getDisplayName(),
            );

            // La surface éditable EXACTE : « une ligne qui rendrait moins de champs que
            // la surface rend indétectable une caducité sur les champs manquants ».
            $proposal->poser(SubjectKind::Personne, $id, $personne->getDisplayName(), 1, [
                'givenNames' => $personne->getGivenNames(),
                'surname' => $personne->getSurname(),
                'marriedName' => $personne->getMarriedName(),
                'nickname' => $personne->getNickname(),
                'sex' => $personne->getSex()->value,
                'deceased' => $personne->isDeceased(),
                'birth' => $personne->getBirth(),
                'death' => $personne->getDeath(),
                'forcePrivacy' => $personne->isForcePrivacy(),
                'privacyNote' => $personne->getPrivacyNote(),
            ]);
        }

        // Les enfants se relisent en base plutôt que sur l'entité : `Tree\Family` n'a pas
        // de collection d'enfants, la filiation vivant dans `tree_child`.
        /** @var list<array{famille: int, personne: int}> $filiations */
        $filiations = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT family_id AS famille, person_id AS personne FROM tree_child',
        );

        foreach ($this->familles as $famille) {
            $familleId = $famille->getId();
            \assert(null !== $familleId);

            $epoux = array_values(array_filter([
                $famille->getHusband()?->getId(),
                $famille->getWife()?->getId(),
            ]));

            $enfants = array_values(array_map(
                static fn (array $ligne): int => (int) $ligne['personne'],
                array_filter($filiations, static fn (array $ligne): bool => (int) $ligne['famille'] === $familleId),
            ));

            ArbreDeMembership::ajouterFamille($epoux, $enfants);
        }
    }

    /* ───────────────── La page ───────────────── */

    /**
     * **Le câblage tient de bout en bout** : le `GET` rejoue la détection, crée les
     * lignes manquantes et sert des questions rédigées — sans aucun appel sortant.
     */
    public function testLaPageSertDesQuestionsAUnMembreRattache(): void
    {
        $this->rattacher(self::OPALE, 'ego');
        $this->connexionDe(self::OPALE);

        $this->requete('/api/questions');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $charge = $this->charge();

        self::assertArrayHasKey('questions', $charge);
        self::assertIsArray($charge['questions']);
        self::assertNotSame([], $charge['questions'], 'L’arbre semé est incomplet : il doit produire des lacunes.');

        // La mention que l'ADR 0008 exige : le front doit pouvoir dire qui a écrit les
        // questions sans avoir à le deviner.
        self::assertSame(0, $charge['redigeesParModele'], 'Aucun modèle n’est branché : tout vient du repli.');

        $premiere = $charge['questions'][0];
        self::assertIsArray($premiere);
        self::assertSame('regles', $premiere['redacteur']);
        self::assertIsString($premiere['texte']);
        self::assertNotSame('', $premiere['texte']);
        self::assertSame(EtatDeQuestion::Posee->value, $premiere['etat']);
    }

    /**
     * **Rejouer le `GET` ne crée rien de plus.** C'est ce qui rend acceptable qu'une
     * lecture écrive : l'index unique s'en charge, et la page reste stable.
     */
    public function testDeuxOuverturesSuccessivesRendentLaMemePage(): void
    {
        $this->rattacher(self::OPALE, 'ego');
        $this->connexionDe(self::OPALE);

        $this->requete('/api/questions');
        $premiere = $this->charge();

        $this->requete('/api/questions');
        $seconde = $this->charge();

        self::assertSame($premiere['total'], $seconde['total']);
        self::assertGreaterThan(0, $premiere['total']);
    }

    /**
     * **Un compte sans rattachement n'a pas de cercle**, donc aucune question sur une
     * personne vivante — le défaut fermé de l'ADR 0003, vu de l'extérieur.
     *
     * Il peut en recevoir sur les **personnes décédées**, qui sont un fait historique
     * visible de tout compte connecté (règle 1 du contrat du cercle). Ce qu'on vérifie
     * donc n'est pas « zéro question » mais « aucune question sur un vivant » — et c'est
     * la formulation juste, celle qui ne devient pas fausse le jour où une aïeule décédée
     * produit une lacune.
     */
    public function testUnCompteSansRattachementNObtientAucuneQuestionSurUnVivant(): void
    {
        $this->connexionDe(self::TUF);

        $this->requete('/api/questions');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $vivants = [$this->idDe('ego'), $this->idDe('conjointe'), $this->idDe('cousin')];

        foreach ($this->charge()['questions'] as $question) {
            self::assertIsArray($question);
            self::assertIsArray($question['sujet']);
            self::assertNotContains(
                $question['sujet']['id'],
                $vivants,
                \sprintf('Un compte sans cercle a reçu une question sur un vivant : « %s ».', $question['texte']),
            );
        }
    }

    /* ───────────────── Les trois écritures ───────────────── */

    public function testJeNeSaisPasFermeLaQuestionEtElleNeRevientPas(): void
    {
        $id = $this->premiereQuestion();

        $this->poster("/api/questions/$id/je-ne-sais-pas");

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(EtatDeQuestion::Inconnue->value, $this->charge()['etat']);

        $this->requete('/api/questions');
        self::assertNotContains($id, $this->identifiantsServis());
    }

    public function testPlusTardRepousseLaQuestion(): void
    {
        $id = $this->premiereQuestion();

        $this->poster("/api/questions/$id/plus-tard");

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $charge = $this->charge();
        self::assertSame(EtatDeQuestion::Differee->value, $charge['etat']);
        self::assertSame(1, $charge['reports']);

        $this->requete('/api/questions');
        self::assertNotContains($id, $this->identifiantsServis(), 'Elle revient plus tard, pas tout de suite.');
    }

    /**
     * **Une réponse dépose dans la file de l'ADR 0004, et `tree_person` n'est pas
     * touchée.** C'est la règle centrale du chantier, et voici où elle se prouve pour de
     * bon : la fiche visée est relue après coup, champ par champ.
     */
    public function testUneReponseTexteDeposeUneDemandeSansToucherALArbre(): void
    {
        $id = $this->premiereQuestion();
        $question = $this->questionDe($id);
        $personneId = $question->getSujetId();

        $avant = $this->empreinteDeLaFiche($personneId);

        $this->poster("/api/questions/$id/reponse", ['texte' => 'Elle est née à Saule, je crois.']);

        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $charge = $this->charge();
        self::assertSame(EtatDeQuestion::Repondue->value, $charge['etat']);
        self::assertIsArray($charge['demandes']);
        self::assertCount(1, $charge['demandes'], 'Une réponse en texte dépose exactement une demande.');
        self::assertIsArray($charge['demandes'][0]);
        self::assertGreaterThan(0, $charge['demandes'][0]['id']);

        self::assertSame(
            $avant,
            $this->empreinteDeLaFiche($personneId),
            'La fiche ne doit PAS avoir changé : une réponse propose, elle n’écrit pas.',
        );
    }

    /**
     * **Une réponse vide n'est pas « je ne sais pas ».**.
     *
     * L'accepter en silence marquerait la question `repondue` sans rien déposer : le
     * membre croirait avoir contribué, l'administrateur ne verrait rien, et la question
     * ne reviendrait jamais. C'est la seule perte irréparable de ce circuit.
     */
    public function testUneReponseVideEstRefuseeEtLaQuestionResteOuverte(): void
    {
        $id = $this->premiereQuestion();

        $this->poster("/api/questions/$id/reponse", []);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSame('reponse_invalide', $this->charge()['erreur']['code']);

        self::assertSame(EtatDeQuestion::Posee, $this->questionDe($id)->getEtat());
    }

    /** Répondre deux fois : `409` avec `rejouable = false` — « rechargez », pas « réessayez ». */
    public function testRepondreADeuxReprisesRend409NonRejouable(): void
    {
        $id = $this->premiereQuestion();

        $this->poster("/api/questions/$id/reponse", ['texte' => 'À Saule.']);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $this->poster("/api/questions/$id/reponse", ['texte' => 'À Saule, finalement.']);

        self::assertSame(Response::HTTP_CONFLICT, $this->client->getResponse()->getStatusCode());

        $erreur = $this->charge()['erreur'];
        self::assertSame('question_deja_tranchee', $erreur['code']);
        self::assertFalse(
            $erreur['details']['rejouable'],
            'Un front écrit contre « 409 ⇒ Réessayer » boucle sans fin si on ne le lui dit pas.',
        );
    }

    /* ───────────────── Ce que la réponse ne dit pas ───────────────── */

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  LA QUESTION D'AUTRUI REND `404`, JAMAIS `403`.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * Un `403` apprendrait que la ligne existe. Or une question **nomme une personne** :
     * « n° 412 existe mais n'est pas à vous » se recoupe avec le reste pour reconstituer
     * à qui l'on demande quoi, donc qui connaît qui. C'est le § 15.3 du contrat d'API
     * appliqué à une porte de sortie nouvelle.
     *
     * Et la question **existe réellement** : le cas ne prouverait rien sur un
     * identifiant inventé, qui rendrait `404` de toute façon.
     */
    public function testLaQuestionDAutruiRend404EtNonPas403(): void
    {
        $idDOpale = $this->premiereQuestion();

        $this->connexionDe(self::TUF);
        $this->poster("/api/questions/$idDOpale/je-ne-sais-pas");

        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $this->client->getResponse()->getStatusCode(),
            'Un 403 dirait à Tuf que cette question existe, donc qu’on interroge quelqu’un sur quelqu’un.',
        );
        self::assertSame('question_introuvable', $this->charge()['erreur']['code']);

        // Et elle est bien restée intacte chez son destinataire.
        self::assertSame(EtatDeQuestion::Posee, $this->questionDe($idDOpale)->getEtat());
    }

    public function testUnIdentifiantInexistantRendLeMeme404(): void
    {
        $this->rattacher(self::OPALE, 'ego');
        $this->connexionDe(self::OPALE);

        $this->poster('/api/questions/999999/je-ne-sais-pas');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        self::assertSame('question_introuvable', $this->charge()['erreur']['code']);
    }

    /** Une page de questions nomme des personnes : elle ne se met en cache nulle part. */
    public function testLaPageNeSeMetPasEnCache(): void
    {
        $this->rattacher(self::OPALE, 'ego');
        $this->connexionDe(self::OPALE);

        $this->requete('/api/questions');

        // Symfony ajoute `private` de lui-même sur une réponse de session ; c'est le
        // `no-store` qui compte, et c'est lui qui interdit jusqu'au cache du navigateur.
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    /* ───────────────── Montage ───────────────── */

    /** Rattache Opale, l'ouvre, charge la page, et rend l'identifiant de la première question. */
    private function premiereQuestion(): int
    {
        $this->rattacher(self::OPALE, 'ego');
        $this->connexionDe(self::OPALE);

        $this->requete('/api/questions');
        $identifiants = $this->identifiantsServis();

        self::assertNotSame([], $identifiants, 'Le harnais doit produire au moins une question.');

        return $identifiants[0];
    }

    /** @return list<int> */
    private function identifiantsServis(): array
    {
        $questions = $this->charge()['questions'];
        self::assertIsArray($questions);

        return array_values(array_map(
            static function (mixed $q): int {
                \assert(\is_array($q) && \is_int($q['id']));

                return $q['id'];
            },
            $questions,
        ));
    }

    /** @param array<string, mixed> $corps */
    private function poster(string $chemin, array $corps = []): void
    {
        $this->client->request('POST', $chemin, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode($corps, \JSON_THROW_ON_ERROR));
    }

    private function questionDe(int $id): Question
    {
        $depot = $this->em()->getRepository(Question::class);
        \assert($depot instanceof QuestionRepository);

        $this->em()->clear();

        $question = $depot->find($id);
        self::assertInstanceOf(Question::class, $question);

        return $question;
    }

    /**
     * Tout ce qu'une réponse pourrait écrire sur la fiche, en une chaîne.
     *
     * On compare l'**état complet** et non le seul champ visé : une écriture parasite sur
     * `version`, `display_name` ou un bloc date serait exactement le genre de fuite que
     * ce test existe pour attraper, et elle passerait sous un contrôle champ par champ.
     */
    private function empreinteDeLaFiche(int $personneId): string
    {
        $this->em()->clear();

        $ligne = $this->em()->getConnection()->fetchAssociative(
            'SELECT * FROM tree_person WHERE id = ?',
            [$personneId],
        );

        self::assertIsArray($ligne);

        return json_encode($ligne, \JSON_THROW_ON_ERROR);
    }

    /** Un compte actif **réellement rattaché** : c'est ce qui lui donne un cercle. */
    private function rattacher(string $courriel, string $personne): void
    {
        $utilisateur = $this->creerUtilisateur($courriel);
        $identifiant = $utilisateur->getId();
        self::assertNotNull($identifiant);

        $membre = new Member(
            $identifiant,
            $courriel,
            $this->idDe($personne),
            $this->personnes[$personne]->getDisplayName(),
            MatchKind::PreLie,
        );
        $membre->activer();

        $em = $this->em();
        $em->persist($membre);
        $em->flush();
    }

    private function connexionDe(string $courriel): void
    {
        if (null === $this->em()->getRepository(User::class)->findOneBy(['email' => $courriel])) {
            $this->creerUtilisateur($courriel);
        }

        $this->client->request('POST', '/api/connexion', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['email' => $courriel, 'password' => 'motdepasse-de-test'], \JSON_THROW_ON_ERROR));

        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            'La connexion de test doit réussir.',
        );
    }
}
