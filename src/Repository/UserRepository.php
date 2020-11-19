<?php

namespace EMS\CoreBundle\Repository;


use EMS\CoreBundle\Core\User\UserList;

/**
 * UserRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class UserRepository extends \Doctrine\ORM\EntityRepository implements UserRepositoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function findForRoleAndCircles($role, $circles)
    {
        $resultSet = $this->createQueryBuilder('u')
            ->where('u.roles like :role')
            ->andWhere('u.enabled = :enabled')
            ->setParameters([
                    'role' => '%"' . $role . '"%',
                    'enabled' => true,
            ])->getQuery()->getResult();
            
        if (!empty($circles)) {
            /**@var \EMS\CoreBundle\Entity\UserInterface $user*/
            foreach ($resultSet as $idx => $user) {
                if (empty(array_intersect($circles, $user->getCircles()))) {
                    unset($resultSet[$idx]);
                }
            }
        }
        return $resultSet;
    }
    
    /**
     *  {@inheritDoc}
     */
    public function getUsersEnabled() : UserList
    {
        $resultSet = $this->findBy([
            'enabled' => true
        ]);
        return new UserList($resultSet);
    }
}
