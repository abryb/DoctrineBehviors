<?php

namespace Abryb\DoctrineBehaviors\ORM\Filterable;


use Doctrine\ORM\QueryBuilder;

interface FilterableRepositoryInterface
{
    // Define SettingKeys;
    const KEY_DEFAULT_ALIAS = 'default_alias';
    const KEY_DEFAULT_NOT_NULL = 'not_null';
    const KEY_DEFAULT_NOT_LIKE = 'not_like';
    const KEY_STRING_TYPES_ALLOW = 'string_type';
    const KEY_GLOBAL_STRING_ALLOW_MAX = 'global_string_allow_max';
    const KEY_GLOBAL_STRING_ALLOW_MIN = 'global_string_allow_min';

    // SettingsValues default
    const VALUE_NOT_NULL_DEFAULT = 'not_null';
    const VALUE_NOT_LIKE_DEFAULT = 'not_like';

    /**
     * Default From alias if building new query.
     */
    const DEFAULT_ALIAS = 'e';

//    const STRING_ALLOW_REGEX      = 4; // TODO
    const STRING_ALLOW_CONTAINING = 3;
    const STRING_ALLOW_ENDING     = 2;
    const STRING_ALLOW_BEGINNING  = 1;
    const STRING_ALLOW_EQUAL_ONLY = 0;

    /**
     * These are used to $qb->expr()->{}()
     */
    const COMPARE_EQ  = 'eq';
    const COMPARE_GT  = 'gt';
    const COMPARE_GTE = 'gte';
    const COMPARE_LT  = 'lt';
    const COMPARE_LTE = 'lte';

    const FORMAT_DATE_INTERVAL = 'P%YY%MM%DDT%HH%IM%SS';

    /**
     * @param array $filters
     * @param QueryBuilder|null $qb
     * @param null $joinedAlias is used when called by other repository with join
     * @return mixed
     */
    public function filterBy(array $filters, QueryBuilder $qb = null, $joinedAlias = null) : QueryBuilder;

    /**
     * Filters defined by developer. Filterable will NOT filter by properties with name like one of defined. (Default override).
     * Foreach filter name in array. Filterable will pass filterName, QueryBuilder and value to applyDefinedFilter.
     * <code>
     *     $repository::getDefinedFilters(); // array('articles', 'groups')
     * </code>
     * @return array
     */
    public static function getDefinedFilters() : array;

    /**
     * Filters blocked.
     * <code>
     *     $repository::getBlockedFilters(); // array('description', 'password')
     * </code>
     *
     * @return array
     */
    public static function getBlockedFilters() : array;

    /**
     * <code>
     *     public function applyDefinedFilter(QueryBuilder $qb, $filterName, $value) : array
     *     {
     *          if ($filterName = 'groups') {
     *              $qb->andWhere('e.groups = :group');
     *              $qb->setParameter('group', $value);
     *          }
     *     }
     * </code>
     * @param QueryBuilder $qb
     * @param string $filterName
     * @param mixed $value
     */
    public function applyDefinedFilter(QueryBuilder $qb, $filterName, $value);
}