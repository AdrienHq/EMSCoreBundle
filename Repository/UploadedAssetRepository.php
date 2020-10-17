<?php

namespace EMS\CoreBundle\Repository;

use Doctrine\ORM\NonUniqueResultException;
use EMS\CoreBundle\Entity\UploadedAsset;

/**
 * UploadedAssetRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class UploadedAssetRepository extends \Doctrine\ORM\EntityRepository
{
    const PAGE_SIZE = 100;

    /**
     * @return int
     */
    public function countHashes()
    {
        $qb = $this->createQueryBuilder('ua');
        $qb->select('count(DISTINCT ua.sha1)')
            ->where($qb->expr()->eq('ua.available', ':true'));
        $qb->setParameters([
            ':true' => true
        ]);

        try {
            return intval($qb->getQuery()->getSingleScalarResult());
        } catch (NonUniqueResultException $e) {
            return 0;
        }
    }


    /**
     * @param integer $page
     * @return array
     */
    public function getHashes($page)
    {
        $qb = $this->createQueryBuilder('ua');
        $qb->select('ua.sha1 as hash')
            ->where($qb->expr()->eq('ua.available', ':true'))
            ->orderBy('ua.sha1', 'ASC')
            ->groupBy('ua.sha1')
            ->setFirstResult(UploadedAssetRepository::PAGE_SIZE * $page)
            ->setMaxResults(UploadedAssetRepository::PAGE_SIZE);
        $qb->setParameters([
            ':true' => true
        ]);

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * @param string $hash
     * @return mixed
     */
    public function dereference($hash)
    {

        $qb = $this->createQueryBuilder('ua');
        $qb->update()
            ->set('ua.available', ':false')
            ->set('ua.status', ':status')
            ->where($qb->expr()->eq('ua.available', ':true'))
            ->andWhere($qb->expr()->eq('ua.sha1', ':hash'));
        $qb->setParameters([
            ':true' => true,
            ':false' => false,
            ':hash' => $hash,
            ':status' => 'cleaned',
        ]);

        return $qb->getQuery()->execute();
    }

    public function getInProgress(string $hash, string $user): ?UploadedAsset
    {
        $uploadedAsset = $this->findOneBy([
            'sha1' => $hash,
            'available' => false,
            'user' => $user,
        ]);
        if ($uploadedAsset === null || $uploadedAsset instanceof UploadedAsset) {
            return $uploadedAsset;
        }
        throw new \RuntimeException(\sprintf('Unexpected class object %s', get_class($uploadedAsset)));
    }
}
