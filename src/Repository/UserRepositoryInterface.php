<?php

namespace EMS\CoreBundle\Repository;

/**
 * UserRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
interface UserRepositoryInterface
{
    public function findForRoleAndCircles($role, $circles);
}
