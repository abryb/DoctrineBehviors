<?php

/*
 * This file is part of the KnpDoctrineBehaviors package.
 *
 * (c) KnpLabs <http://knplabs.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Abryb\DoctrineBehaviors\ORM\Filterable;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Doctrine\DBAL\Types\Type;

/**
 * Filterable trait.
 *
 * Should be used inside entity repository, that needs to be filterable
 */
trait FilterableRepositoryTrait
{
    /**
     * @var ClassMetadata|null
     */
    protected $classMetadata;

    /**
     * @var string root entity alias
     */
    protected $rootAlias = self::DEFAULT_ALIAS;

    /**
     * @return array of compare types
     */
    protected function getCompareTypes()
    {
        return array(
            self::COMPARE_GT,
            self::COMPARE_GTE,
            self::COMPARE_LT,
            self::COMPARE_LTE,
        );
    }

    /**
     * THE settings of filterable
     *
     * @var array
     */
    protected static $filterableSettings = array(

        self::KEY_DEFAULT_ALIAS => self::DEFAULT_ALIAS,
        self::KEY_DEFAULT_NOT_NULL => self::VALUE_NOT_NULL_DEFAULT,
        self::KEY_DEFAULT_NOT_LIKE => self::VALUE_NOT_LIKE_DEFAULT,
        self::KEY_GLOBAL_STRING_ALLOW_MIN => self::STRING_ALLOW_EQUAL_ONLY,
        self::KEY_GLOBAL_STRING_ALLOW_MAX => self::STRING_ALLOW_CONTAINING,
        self::KEY_STRING_TYPES_ALLOW => array(
            Type::STRING => self::STRING_ALLOW_CONTAINING,
            Type::TEXT => self::STRING_ALLOW_BEGINNING,
            Type::GUID => self::STRING_ALLOW_EQUAL_ONLY,
            Type::BINARY => self::STRING_ALLOW_BEGINNING,
            Type::BLOB => self::STRING_ALLOW_BEGINNING,
        ),
    );

    /**
     * Function to override settings
     *
     * @return array
     */
    abstract protected static function setFilterableSettings() : array;

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
    abstract public static function getDefinedFilters(): array;

    /**
     * @return array
     */
    abstract public static function getBlockedFilters(): array ;

    /**
     * @param QueryBuilder $qb
     * @param $filterName
     * @param $value
     * @return mixed
     */
    abstract public function applyDefinedFilter(QueryBuilder $qb, $filterName, $value);

    /**
     * @param array             $filters
     * @param QueryBuilder|null $qb
     * @param null              $joinedAlias
     *
     * @return QueryBuilder
     */
    public function filterBy(array $filters, QueryBuilder $qb = null, $joinedAlias = null) : QueryBuilder
    {
        // Check if trait is used in EntityRepository
        if ($this instanceof EntityRepository) {
            $this->classMetadata = $this->getClassMetadata();
        } else {
            throw new \LogicException('Please use \'FilterableRepositoryTrait\' in EntityRepository implementing \'FilterableRepositoryInterface\'');
        }

        // Remove blocked filters
        $filters = array_filter($filters, function ($f) {
            return !in_array($f, $this::getBlockedFilters(), true);
        }, ARRAY_FILTER_USE_KEY);


        // Check QueryBuilder and alias.
        if ($qb === null) {
            $qb = $this->createQueryBuilder(self::DEFAULT_ALIAS);
        } else {
            if ($joinedAlias) {
                $this->rootAlias = $joinedAlias;

            } elseif (null === $alias = isset($qb->getRootAliases()[0]) ? $qb->getRootAliases()[0] : null) {
                $qb->select(self::DEFAULT_ALIAS)->from($this->getClassName(), self::DEFAULT_ALIAS);

            } else {
                $isRootThisClass = array_filter($qb->getRootEntities(), function ($class) {
                    return $class === $this->getEntityName();
                });
                if ($isRootThisClass) {
                    $this->rootAlias = $alias;
                }
            }
        }

        // Iterate through filters
        foreach ($filters as $property => $value) {

            // Define aliasProperty as alias.property
            $aliasProperty = sprintf('%s.%s', $this->rootAlias, $property);

            //Apply defined filter if exists
            if (in_array($property, $this::getDefinedFilters())) {
                $this->applyDefinedFilter($qb, $property, $value);
                continue;
            }
            // if value is null, just do it
            if ($value === null) {
                $this->filterValueIsNull($qb, $aliasProperty);
                continue;
            }
            // if value is const VALUE_NOT_NULL, also do it
            if ($value === $this->getSetting(self::KEY_DEFAULT_NOT_NULL)) {
                $this->filterValueIsNotNull($qb, $aliasProperty);
                continue;
            }

            /*
             * Important part
             */

            // If entity has property
            if ($this->classMetadata->hasField($property)) {

                $this->filterField($qb, $aliasProperty, $value);

                // If entity has association
            } elseif ($this->classMetadata->hasAssociation($property)) {

                $this->filterAssociation($qb, $aliasProperty, $value);
            }
        }

        return $qb;
    }

    protected function filterField(QueryBuilder $qb, $aliasProperty, $value)
    {
        $property = explode('.', $aliasProperty)[1];
        $stringType = self::STRING_ALLOW_CONTAINING;

        switch ($this->getFieldMappingType($property)) {
            // group numbers
            case Type::SMALLINT:
            case Type::BIGINT:
            case Type::INTEGER:
            case Type::DECIMAL:
            case Type::FLOAT:
                $this->filterNumber($qb, $aliasProperty, $value);
                break;
            case Type::STRING:
                $this->filterString($qb, $aliasProperty, $value, Type::STRING);
                break;
            case Type::TEXT:
                $this->filterString($qb, $aliasProperty, $value, Type::TEXT);
                break;
            case Type::GUID:
                $this->filterString($qb, $aliasProperty, $value, Type::GUID);
                break;
            case Type::BINARY:
                $this->filterString($qb, $aliasProperty, $value, Type::BINARY);
                break;
            case Type::BLOB:
                $this->filterString($qb, $aliasProperty, $value, TYPE::BLOB);
                break;
            case Type::BOOLEAN:
                $this->filterBoolean($qb, $aliasProperty, $value);
                break;
//            case Type::DATE_IMMUTABLE: // wait for stable 2.6 doctrine/orm
            case Type::DATE:
                $this->filterDateTime($qb, $aliasProperty, $value, false);
                break;
//            case Type::DATETIME_IMMUTABLE: // wait for stable 2.6 doctrine/orm
//            case Type::DATETIMETZ_IMMUTABLE: // wait for stable 2.6 doctrine/orm
//            case Type::TIME_IMMUTABLE: // wait for stable 2.6 doctrine/orm
            case Type::DATETIME:
            case Type::DATETIMETZ:
            case Type::TIME:
                $this->filterDateTime($qb, $aliasProperty, $value, true);
                break;
            /*case Type::DATEINTERVAL: // wait for stable 2.6 doctrine/orm
                $this->filterDateInterval($qb, $property, $value); // wait for stable 2.6 doctrine/orm
                break;*/
//            case Type::JSON: // wait for stable 2.6 doctrine/orm
            case Type::TARRAY:
                $this->filterTArray($qb, $aliasProperty, $value);
                break;
            case Type::SIMPLE_ARRAY:
                $this->filterSimpleArray($qb, $aliasProperty, $value);
                break;
            case Type::JSON_ARRAY:
                $this->filterJsonArray($qb, $aliasProperty, $value);
                break;
            case Type::OBJECT:
                $this->filterObject($qb, $aliasProperty, $value);
                break;
            default;
                break;

        }
    }

    protected function filterAssociation(QueryBuilder $qb, $aliasAssociation, $value)
    {
        $association = explode('.', $aliasAssociation)[1];

        // If $value is not array do '='
        if (!is_array($value)) {
            $qb->andWhere($qb->expr()->eq(
                $aliasAssociation,
                $qb->expr()->literal($value)
            ));
            // Else if value is Sequential array do 'in'
        } elseif ($this->isSequentialArray($value)) {
            $qb->andWhere($qb->expr()->in(
                $aliasAssociation,
                $qb->expr()->literal($value)
            ));
            // Else pass array to filterBy of associated repository if exists
        } else {
            // getRepository of associacion
            $associationMappings = $this->getClassMetadata()->getAssociationMapping($association);
            $associatedRepository = $this->getEntityManager()->getRepository($associationMappings['targetEntity']);

            // Check if associated repository is filterable. If not throw Exception
            if (!($associatedRepository && $associatedRepository instanceof FilterableRepositoryInterface)) {
                return;
//                $message = sprintf('Trying to call filter by association %s of class %s but %s is not %s',$association,$associationMappings['targetEntity'],$this->getClassName(),get_class(FilterableRepositoryInterface::class));
//                throw new \LogicException($message);
            }

            // Join association
            $joinedAlias = $this->rootAlias . '_' . $association;
            $qb->leftJoin($aliasAssociation, $joinedAlias);

            // Run filterBy method of associated repository
            $associatedRepository->filterBy($value, $qb, $joinedAlias);
        }
    }

    protected function filterNumber(QueryBuilder $qb, $property, $value)
    {
        if ($this->isSequentialArray($value)) {
            $qb->andWhere($qb->expr()->in($property, array_values($value)));
            return;
        }
        if (is_array($value)) {
            foreach ($this->getCompareTypes() as $cpt) {
                if (array_key_exists($cpt, $value)) {
                    $qb->andWhere($qb->expr()->$cpt(
                        $property,
                        $qb->expr()->literal($value[$cpt])
                    ));
                }
            }
        } else {
            $qb->andWhere($qb->expr()->eq(
                $property,
                $qb->expr()->literal($value)
            ));
        }
    }

    protected function filterString(QueryBuilder $qb, $property, $value, $type = Type::STRING)
    {
        $like = 'like';
        $KEY_DEFAULT_NOT_LIKE = $this->getSetting(self::KEY_DEFAULT_NOT_LIKE);
        $KEY_GLOBAL_STRING_ALLOW_MIN = $this->getSetting(self::KEY_GLOBAL_STRING_ALLOW_MIN);
        $KEY_GLOBAL_STRING_ALLOW_MAX = $this->getSetting(self::KEY_GLOBAL_STRING_ALLOW_MAX);
        $KEY_STRING_TYPES_ALLOW_type = $this->getSetting(self::KEY_STRING_TYPES_ALLOW)[$type];

        // if string  - check if it begins with 'not_like' value, change for not_like mode and remove 'not_like' from beginning
        if (is_string($value)) {
            $isNotLike = substr($value,0, count($KEY_DEFAULT_NOT_LIKE));
            if ($isNotLike  === self::KEY_DEFAULT_NOT_LIKE) {
                $like = 'notLike';
                if (substr($value, 0, strlen($isNotLike)) == $isNotLike) {
                    $value = substr($value, strlen($isNotLike));
                }
            }
        }
        // If array - search for 'not_like' key, if exists change 'like' for 'not_like' and remove array keys.
        if (is_array($value) && array_key_exists($KEY_DEFAULT_NOT_LIKE, $value)) {
            $like = 'notLike';
            $value = $value[self::KEY_DEFAULT_NOT_LIKE];
        }

        // Now calculate type of search, bigger from global and particular wins.
        $type = max($KEY_GLOBAL_STRING_ALLOW_MIN, min($KEY_STRING_TYPES_ALLOW_type, $KEY_GLOBAL_STRING_ALLOW_MAX));

        // Trim $value from '%' signs
        $value = trim($value, '%');

        switch ($type) {
            case self::STRING_ALLOW_EQUAL_ONLY:
                break;
            case self::STRING_ALLOW_BEGINNING :
                $value = $value.'%';
                break;
            case self::STRING_ALLOW_ENDING    :
                $value = '%'.$value;
                break;
            case self::STRING_ALLOW_CONTAINING:
                $value = '%'.$value.'%';
                break;
            default:
                break;
        }

        $qb->andWhere(
            $qb->expr()->$like(
                $property,
                $qb->expr()->literal($value)
            )
        );

    }

    protected function filterBoolean(QueryBuilder $qb, $property, $value)
    {
        $qb->andWhere($qb->expr()->eq(
            $property,
            $qb->expr()->literal($value)
        ));
    }

    protected function filterDateTime(QueryBuilder $qb, $property, $value, $withTime)
    {
        if (isset($value['from'])) {
            $value['gte'] = $value['from'];
            unset($value['from']);
        }
        if (isset($value['to'])) {
            $value['lte'] = $value['to'];
            unset($value['to']);
        }

        if (is_array($value)) {
            foreach ($this->getCompareTypes() as $compareType) {
                if (isset($value[$compareType]) && is_string($value[$compareType])) {
                    try {
                        $value[$compareType] = new \DateTime($value[$compareType]);
                    } catch (\Exception $e) {
                        unset($value[$compareType]);
                    }
                }
            }
        } elseif (is_string($value)) {
            try {
                $value = new \DateTime($value);
            } catch (\Exception $e) {
                $value = null;
            }
        }


        if ($value instanceof \DateTime) {
            $parameter = $withTime ? $value->format('Y-m-d H:i:s') : $value->format('Y-m-d');
            $qb->andWhere($qb->expr()->eq(
                $property,
                $qb->expr()->literal($parameter)
            ));
        }

        if (is_array($value)) {
            foreach ($this->getCompareTypes() as $compareType) {
                if (isset($value[$compareType]) && $value[$compareType] instanceof \DateTime) {
                    $parameter = $withTime ? $value[$compareType]->format('Y-m-d H:i:s') : $value['from']->format('Y-m-d');
                    $qb->andWhere($qb->expr()->$compareType(
                        $property,
                        $qb->expr()->literal($parameter)
                    ));
                }
            }
        }

        return $this;
    }

    protected function filterDateInterval(QueryBuilder $qb, $property, $value)
    {
        /**
         * TODO improve
         */
        if (is_array($value)) {
            foreach ($this->getCompareTypes() as $compareType) {
                if (isset($value[$compareType]) && is_string($value[$compareType])) {
                    try {
                        $value[$compareType] = new \DateInterval($value[$compareType]);
                    } catch (\Exception $e) {
                        unset($value[$compareType]);
                    }
                }
            }
        } elseif (is_string($value)) {
            try {
                $value = new \DateInterval($value);
            } catch (\Exception $e) {
                $value = null;
            }
        }


        if ($value instanceof \DateInterval) {
            $parameter = $value->format(self::FORMAT_DATE_INTERVAL);
            $qb->andWhere($qb->expr()->eq(
                $property,
                $qb->expr()->literal($parameter)
            ));
        }

        if (is_array($value)) {
            foreach ($this->getCompareTypes() as $compareType) {
                if (isset($value[$compareType]) && $value[$compareType] instanceof \DateInterval) {
                    $parameter = $value->format(self::FORMAT_DATE_INTERVAL);
                    $qb->andWhere($qb->expr()->$compareType(
                        $property,
                        $qb->expr()->literal($parameter)
                    ));
                }
            }
        }
    }

    protected function filterSimpleArray(QueryBuilder $qb, $aliasProperty, $value)
    {
        /**
         * TODO change to regex or improve
         */
        $search = null;
        try {
            if (is_array($value)) {
                $search = implode(',', $value);
            } else {
                $search = (string) $value;
            }
        } catch (\Exception $e) {

        }
        if ($search) {
            $this->filterString($qb, $aliasProperty, $search);
        }
    }

    protected function filterTArray(QueryBuilder $qb, $aliasProperty, $value)
    {
        /**
         * TODO change to regex or improve
         */
        $search = null;
        try {
            if (is_array($value)) {
                $search = serialize($value);
            } else {
                $search = (string) $value;
            }
        } catch (\Exception $e) {

        }
        if ($search) {
            $this->filterString($qb, $aliasProperty, $search);
        }
    }

    protected function filterJsonArray(QueryBuilder $qb, $aliasProperty, $value)
    {
        /**
         * TODO change to regex or improve
         */
        $search = null;
        try {
            if (is_array($value)) {
                $search = json_encode($value);
            } else {
                $search = (string) $value;
            }
        } catch (\Exception $e) {

        }
        if ($search) {
            $this->filterString($qb, $aliasProperty, $search);
        }
    }

    protected function filterObject(QueryBuilder $qb, $aliasProperty, $value)
    {
        /**
         * TODO change to regex or improve
         */
        $search = null;
        try {
            if (is_object($value) || is_array($value)) {
                $search = serialize($value);
            } else {
                $search = (string) $value;
            }
        } catch (\Exception $e) {

        }
        if ($search) {
            $this->filterString($qb, $aliasProperty, $search);
        }
    }

    private function  getSetting(string $name)
    {
        return $this->getFilterableSettings()[$name];
    }


    protected function filterValueIsNull(QueryBuilder $qb, $property)
    {
        $qb->andWhere($qb->expr()->isNull($property));
    }

    protected function filterValueIsNotNull(QueryBuilder $qb, $property)
    {
        $qb->andWhere($qb->expr()->isNotNull($property));
    }

    protected function getFieldMappingType($property)
    {
        return $this->classMetadata->getFieldMapping($property)['type'];
    }

    protected function isAssociationArray($array)
    {
        if (!(is_array($array) && $array === array())) return false;
        return array_keys($array) !== range(0, count($array) - 1);
    }

    protected function isSequentialArray($array)
    {
        if (!is_array($array)) return false;
        return array_keys($array) === range(0, count($array) - 1);
    }
}