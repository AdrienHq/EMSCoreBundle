<?php

namespace EMS\CoreBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\QueryBuilder;
use EMS\CoreBundle\Entity\Environment;
use Throwable;

/**
 * EnvironmentRepository.
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class EnvironmentRepository extends EntityRepository
{
    public function findAll()
    {
        return $this->findBy([]);
    }

    public function findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
    {
        if (empty($orderBy)) {
            $orderBy = ['orderKey' => 'asc'];
        }

        return parent::findBy($criteria, $orderBy, $limit, $offset);
    }

    public function findOneByName(string $name)
    {
        return parent::findOneBy(['name' => $name]);
    }

    public function findOneById(string $id)
    {
        return parent::findOneBy(['id' => $id]);
    }

    public function findAllAliases()
    {
        $qb = $this->createQueryBuilder('e', 'e.alias');
        $qb->select('e.alias, e.name, e.managed');

        return $qb->getQuery()->getResult();
    }

    public function getEnvironmentsStats()
    {
        /** @var QueryBuilder $qb */
        $qb = $this->createQueryBuilder('e')
        ->select('e as environment', 'count(r) as counter')
        ->leftJoin('e.revisions', 'r')
        ->groupBy('e.id')
        ->orderBy('e.orderKey', 'ASC');

        return $qb->getQuery()->getResult();
    }

    public function getDeletedRevisionsPerEnvironment(Environment $environment)
    {
        /** @var QueryBuilder $qb */
        $qb = $this->createQueryBuilder('e');
        $qb->select('count(r) as counter')
            ->leftJoin('e.revisions', 'r')
            ->where($qb->expr()->eq('r.deleted', ':true'))
            ->andWhere($qb->expr()->eq('e', ':environment'))
            ->groupBy('e.id')
            ->orderBy('e.orderKey', 'ASC')
            ->setParameters([
                ':true' => true,
                ':environment' => $environment,
            ]);

        try {
            return $qb->getQuery()->getSingleScalarResult();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public function countRevisionPerEnvironment(Environment $env)
    {
        /** @var QueryBuilder $qb */
        $qb = $this->createQueryBuilder('e');

        $qb->select('count(r) as counter')
        ->where($qb->expr()->eq('e.id', $env->getId()))
        ->leftJoin('e.revisions', 'r')
        ->groupBy('e.id');

        try {
            return $qb->getQuery()->getSingleScalarResult();
        } catch (NonUniqueResultException $e) {
            return 0;
        }
    }

    public function findAvailableEnvironements(Environment $defaultEnv)
    {
        /** @var QueryBuilder $qb */
        $qb = $this->createQueryBuilder('e');
        $qb->where($qb->expr()->neq('e.id', ':defaultEnvId'));
        $qb->andWhere($qb->expr()->neq('e.managed', ':false'));
        $qb->orderBy('e.orderKey', 'ASC');
        $qb->setParameters([
                'false' => false,
                'defaultEnvId' => $defaultEnv->getId(),
        ]);

        return $qb->getQuery()->getResult();
    }

    public function findManagedIndexes()
    {
        $qb = $this->createQueryBuilder('e');
        $qb->select('e.alias alias');
        $qb->where($qb->expr()->eq('e.managed', ':true'));
        $qb->setParameters([':true' => true]);
        $qb->orderBy('e.orderKey', 'ASC');

        return $qb->getQuery()->getResult();
    }

    public function findByName($name)
    {
        return $this->findOneBy([
                'deleted' => false,
                'name' => $name,
        ]);
    }

    public function findAllAsAssociativeArray($field)
    {
        $qb = $this->createQueryBuilder('e');
        $qb->select('e.'.$field.' key, e.name name, e.color color, e.alias alias, e.managed managed, e.baseUrl baseUrl, e.circles circles, e.extra');

        $out = [];
        $result = $qb->getQuery()->getResult();
        foreach ($result as $record) {
            $out[$record['key']] = [
                    'color' => $record['color'],
                    'name' => $record['name'],
                    'alias' => $record['alias'],
                    'managed' => $record['managed'],
                    'baseUrl' => $record['baseUrl'],
                    'circles' => $record['circles'],
                    'extra' => $record['extra'],
            ];
        }

        return $out;
    }
}