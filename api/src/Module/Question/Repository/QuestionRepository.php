<?php

declare(strict_types=1);

namespace App\Module\Question\Repository;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Question\Entity\Question;
use App\Module\Question\Enum\EtatDeQuestion;
use App\Module\Question\Enum\NatureDeLacune;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see Question}.
 *
 * **Aucune de ces méthodes n'applique le cercle de visibilité**, et c'est délibéré :
 * le filtre est appliqué à la **sérialisation**, jamais dans un dépôt ni dans un
 * contrôleur (ADR 0003). Un dépôt qui filtrerait serait un neuvième endroit où la règle
 * de confidentialité est écrite, donc un neuvième endroit où elle peut diverger.
 *
 * Ce que ces méthodes garantissent, c'est l'autre moitié : **elles ne rendent jamais
 * les questions d'un autre destinataire.** Le `destinataireUserId` est un paramètre
 * obligatoire de chacune, jamais une option.
 *
 * @extends ServiceEntityRepository<Question>
 */
class QuestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Question::class);
    }

    /**
     * Les questions **servables** d'un compte, à cet instant.
     *
     * C'est-à-dire : celles qui sont `posee`, **plus** celles qui sont `differee` dont
     * la date de relance est passée. Les deux dans la même requête, parce qu'elles
     * s'affichent dans la même liste — une question différée revenue n'est pas signalée
     * comme telle au membre, qui n'a pas à se souvenir de ce qu'il a repoussé.
     *
     * Ni `inconnue` ni `repondue` n'en sortent jamais : ce sont les deux états
     * définitifs (ADR 0008, § 8).
     *
     * @return list<Question>
     */
    public function servablesPour(int $destinataireUserId, \DateTimeImmutable $maintenant): array
    {
        /** @var list<Question> $lignes */
        $lignes = $this->createQueryBuilder('q')
            ->andWhere('q.destinataireUserId = :compte')
            ->andWhere('q.etat = :posee OR (q.etat = :differee AND q.relancerApres <= :maintenant)')
            ->setParameter('compte', $destinataireUserId)
            ->setParameter('posee', EtatDeQuestion::Posee)
            ->setParameter('differee', EtatDeQuestion::Differee)
            ->setParameter('maintenant', $maintenant)
            // L'ordre d'affichage est celui de `NatureDeLacune::rang()`, qui ne se
            // calcule pas en SQL : la requête rend un ordre **stable** — le plus ancien
            // d'abord — et le tri produit est posé par le service, sur les objets. Un
            // ordre stable ici suffit à ce que deux chargements successifs montrent la
            // même page, qui est tout ce qu'on demande à la base.
            ->orderBy('q.createdAt', 'ASC')
            ->addOrderBy('q.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $lignes;
    }

    /**
     * Les questions **déjà connues** de ce compte, indexées par signature.
     *
     * C'est la moitié de l'idempotence de la détection : elle calcule les signatures des
     * lacunes qu'elle trouve, les confronte à cette carte, et ne crée que ce qui manque.
     * On charge **tous les états**, y compris `inconnue` et `repondue` — c'est
     * précisément là qu'est la mémoire, et en omettre un reposerait ce à quoi il a été
     * répondu.
     *
     * @return array<string, Question> indexé par `signature`
     */
    public function connuesPour(int $destinataireUserId): array
    {
        /** @var list<Question> $lignes */
        $lignes = $this->createQueryBuilder('q')
            ->andWhere('q.destinataireUserId = :compte')
            ->setParameter('compte', $destinataireUserId)
            ->getQuery()
            ->getResult();

        $parSignature = [];

        foreach ($lignes as $question) {
            $parSignature[$question->getSignature()] = $question;
        }

        return $parSignature;
    }

    /**
     * Une question précise d'un compte précis.
     *
     * Le `destinataireUserId` est dans la clause et non vérifié après coup : répondre à
     * la question d'autrui doit rendre **404 et non 403**, comme partout ailleurs dans
     * ce produit (§ 15.3 du contrat d'API). Un `403` apprendrait que la ligne existe —
     * et une question nomme une personne.
     */
    public function pourCompte(int $id, int $destinataireUserId): ?Question
    {
        return $this->createQueryBuilder('q')
            ->andWhere('q.id = :id')
            ->andWhere('q.destinataireUserId = :compte')
            ->setParameter('id', $id)
            ->setParameter('compte', $destinataireUserId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Les questions portant sur la **même lacune**, chez tous les destinataires.
     *
     * « À qui d'autre a-t-on posé celle-ci, et qu'en ont-ils dit ? » — la question à
     * poser **avant d'en interroger un de plus** : si trois personnes ont
     * déjà répondu « je ne sais pas » sur le lieu de décès d'un aïeul, en interroger une
     * quatrième a peu de chances d'aboutir, et beaucoup de lasser.
     *
     * C'est aussi pour cette méthode que le destinataire n'entre **pas** dans la
     * signature : mêlé au haché, il aurait rendu cette requête impossible.
     *
     * @return list<Question>
     */
    public function memeLacuneChezLesAutres(NatureDeLacune $nature, SubjectKind $sujetKind, int $sujetId): array
    {
        /** @var list<Question> $lignes */
        $lignes = $this->createQueryBuilder('q')
            ->andWhere('q.signature = :signature')
            ->setParameter('signature', Question::signatureDe($nature, $sujetKind, $sujetId))
            ->getQuery()
            ->getResult();

        return $lignes;
    }
}
