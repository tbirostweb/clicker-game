<?php

namespace App\Repository;

use App\Entity\Player;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Player>
 */
class PlayerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Player::class);
    }

    private const SORT_COLUMNS = [
        // Ranks by time actually spent playing (window focused), not fastest
        // completion -- rewards sustained engagement over speedrunning.
        'active' => ['field' => 'p.activeSeconds', 'direction' => 'DESC'],
        'rebirths' => ['field' => 'p.rebirth', 'direction' => 'DESC'],
        'score' => ['field' => 'p.score', 'direction' => 'DESC'],
        'trophies' => ['field' => 'p.trophyCount', 'direction' => 'DESC'],
    ];

    /**
     * @return Player[]
     */
    public function findTopRuns(int $limit, string $sort = 'active'): array
    {
        return $this->topRunsQuery($limit, $sort)->getResult();
    }

    /** Query used by findTopRuns (exposed for the EXPLAIN regression test). */
    public function topRunsQuery(int $limit, string $sort = 'active'): Query
    {
        $column = self::SORT_COLUMNS[$sort] ?? self::SORT_COLUMNS['active'];

        return $this->createQueryBuilder('p')
            ->orderBy($column['field'], $column['direction'])
            ->setMaxResults($limit)
            ->getQuery();
    }

    /**
     * Ids of runs inactive since $before (last update, or creation for runs
     * never updated) that are NOT visible on any leaderboard sort (top
     * $protectTop of each), so a purge never changes what players see.
     *
     * @return list<int>
     */
    public function findPurgeableInactiveIds(\DateTimeImmutable $before, int $protectTop): array
    {
        $protected = [];
        foreach (array_keys(self::SORT_COLUMNS) as $sort) {
            foreach ($this->topRunsQuery($protectTop, $sort)->getResult() as $run) {
                $protected[$run->getId()] = true;
            }
        }

        $ids = $this->createQueryBuilder('p')
            ->select('p.id')
            ->where('COALESCE(p.updatedAt, p.createdAt) < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_filter(array_map('intval', $ids), static fn (int $id) => !isset($protected[$id])));
    }

    /** Forgets Idempotency-Keys (and their encrypted token) older than $before. */
    public function expireIdempotencyKeys(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('p')
            ->update()
            ->set('p.idempotencyKeyHash', 'NULL')
            ->set('p.idempotencyTokenBox', 'NULL')
            ->set('p.idempotencyCreatedAt', 'NULL')
            ->where('p.idempotencyCreatedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    //    /**
    //     * @return Player[] Returns an array of Player objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('p.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Player
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
