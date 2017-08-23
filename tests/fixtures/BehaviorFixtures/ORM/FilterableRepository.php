<?php

namespace BehaviorFixtures\ORM;

use Abryb\DoctrineBehaviors\ORM\Filterable\FilterableRepositoryInterface;
use Abryb\DoctrineBehaviors\ORM\Filterable\FilterableRepositoryTrait;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * @author     Leszek Prabucki <leszek.prabucki@gmail.com>
 */
class FilterableRepository extends EntityRepository implements FilterableRepositoryInterface
{
    use FilterableRepositoryTrait;

    /**
     * Function to override settings
     *
     * @return array
     */
    protected static function setFilterableSettings() : array
    {
        return array();
    }

    /**
     * Filterable settings
     *
     * @return array
     */
    protected function getFilterableSettings() : array
    {
        return array_merge($this::$filterableSettings, $this::setFilterableSettings());
    }

    /**
     * @return array
     */
    public static function getDefinedFilters(): array
    {
        return array();
    }

    /**
     * @return array
     */
    public static function getBlockedFilters(): array
    {
        return array();
    }

    /**
     * @param QueryBuilder $qb
     * @param $filterName
     * @param $value
     * @return mixed
     */
    public function applyDefinedFilter(QueryBuilder $qb, $filterName, $value)
    {

    }
}

