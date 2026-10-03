<?php

namespace App\Repository;

use App\Entity\Player;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
        $column = self::SORT_COLUMNS[$sort] ?? self::SORT_COLUMNS['active'];

        return $this->createQueryBuilder('p')
            ->orderBy($column['field'], $column['direction'])
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
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
