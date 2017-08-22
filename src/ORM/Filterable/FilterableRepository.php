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
trait FilterableRepository
{
    //============================================================================================
    /**
     * @var ClassMetadata|null
     */
    protected $classMetadata;

    protected $rootAlias = 'e';

    const VALUE_NOT_NULL = 'not_null';

    const COMPARE_EQ = 'eq';
    const COMPARE_GT = 'gt';
    const COMPARE_GTE = 'gte';
    const COMPARE_LT = 'lt';
    const COMPARE_LTE = 'lte';

    const DATEINTERVAL_FORMAT = 'P%YY%MM%DDT%HH%IM%SS';

    protected function getCompareTypes()
    {
        return array(
            self::COMPARE_GT,
            self::COMPARE_GTE,
            self::COMPARE_LT,
            self::COMPARE_LTE,
        );
    }

    public static function getDefinedFilters() // to abstract
    {
        return array(
            'enabled'
        );
    }

    public static function getBlockedFilters() // rename to getPropertiesNamesBlockedToFilter ?
    {
        return array(
//            'createdAt'
        );
    }

    public function addDefinedFilters(array $filters)
    {
        return array();
    }

    public function filterBy(array $filters, QueryBuilder $qb = null)
    {
        // Remove defined and blocked filters
        $filters = array_filter($filters, function($f) {
            return (!in_array($f, $this::getDefinedFilters(), true) && !in_array($f, $this::getBlockedFilters(), true));
        },ARRAY_FILTER_USE_KEY);

        // Check if trait is used in EntityRepository
        if ($this instanceof EntityRepository) {
            $this->classMetadata = $this->getClassMetadata();
        } else {
            return null;
        }

        // Check QueryBuilder and alias.
        if ($qb === null) {
            $qb = $this->createQueryBuilder('e');
        } else {
            if (null === $alias = isset($qb->getRootAliases()[0]) ?? null) {
                $qb->select('e')->from($this->getClassName(), 'e');
            } else {
                $this->rootAlias = $alias;
            }
        }


        foreach ($filters as $property => $value) {
            // if value is null, just do it
            if ($value === null) {
                $this->filterValueIsNull($qb, $property);
                continue;
            }
            // if value is const VALUE_NOT_NULL, also do it
            if ($value === self::VALUE_NOT_NULL) {
                $this->filterValueIsNotNull($qb, $property);
                continue;
            }

            /**
             * IMPORTANT
             */
            if ($this->classMetadata->hasField($property)) {

                $this->filterField($qb, $property, $value);

            } elseif ($this->classMetadata->hasAssociation($property)) {

                $this->filterAssociation($qb, $property, $value);
            }
        }

        return $qb;
    }

    private function filterField(QueryBuilder $qb, $property, $value)
    {
        /**
         * TODO zmien aby property było JUZ z aliasem, do tego reflection class na assocjacje
         */
        switch ($this->getFieldMappingType($property)) {
            // group numbers
            case Type::SMALLINT:
            case Type::BIGINT:
            case Type::INTEGER:
            case Type::DECIMAL:
            case Type::FLOAT:
                $this->filterNumber($qb, $property, $value);
                break;
            case Type::STRING:
            case Type::TEXT:
            case Type::GUID:
            case Type::BINARY:
            case Type::BLOB:
                $this->filterString($qb, $property, $value);
                break;
            case Type::BOOLEAN:
                $this->filterBoolean($qb, $property, $value);
                break;
            case Type::DATE:
            case Type::DATE_IMMUTABLE:
                $this->filterDateTime($qb, $property, $value, false);
                break;
            case Type::DATETIME:
            case Type::DATETIME_IMMUTABLE:
            case Type::DATETIMETZ:
            case Type::DATETIMETZ_IMMUTABLE:
            case Type::TIME:
            case Type::TIME_IMMUTABLE:
                $this->filterDateTime($qb, $property, $value, true);
                break;
            case Type::DATEINTERVAL:
                $this->filterDateInterval($qb, $property, $value);
                break;
            case Type::TARRAY:
            case Type::SIMPLE_ARRAY:
            case Type::JSON:
            case Type::JSON_ARRAY:
                /**
                 * TODO
                 */
                break;
            case Type::OBJECT:
                /**
                 * TODO
                 */
                break;
            default;
                break;

        }
    }

    protected function filterAssociation(QueryBuilder $qb, $association, $value)
    {
        $associationMappings = $this->getClassMetadata()->getAssociationMapping($association);

        if (!is_array($value)) {
            $qb->andWhere($qb->expr()->eq(
                sprintf('%s.%s', $this->rootAlias, $association),
                $qb->expr()->literal($value)
            ));
        } else {
            // check if associated entity repository is filterable
            $associatedRepository = $this->getEntityManager()->getRepository($associationMappings['targetEntity']);
//            if ($associatedRepository && $associatedRepository instanceof FilterableRepositoryInterface) {
//
//            }


            // join
            $joinedAlias = $this->rootAlias .'_'. $association;
            $qb->leftJoin(sprintf('%s.%s', $this->rootAlias, $association), $joinedAlias);

            foreach ($value as $assocProp => $assocVal ) {
                /**
                 * TODO sprawdzenie czy ma wlasność
                 */
                $this->filterField($qb, $assocProp, $assocVal);
                $qb->andWhere($qb->expr()->eq(
                    sprintf('%s.%s', $joinedAlias, $assocProp),
                    $qb->expr()->literal($assocVal )
                ));
            }
        }
    }

    protected function getFieldMappingType($property)
    {
        return $this->classMetadata->getFieldMapping($property)['type'];
    }

    protected function filterNumber(QueryBuilder $qb, $property, $value)
    {
        /**
         * TODO null
         */
        if (is_array($value)) {
            $filterIn = true;
            foreach ($this->getCompareTypes() as $cpt) {
                if (array_key_exists($cpt, $value)) {
                    $qb->andWhere($qb->expr()->$cpt(
                        sprintf('%s.%s', $this->rootAlias, $property),
                        $qb->expr()->literal($value[$cpt])
                    ));
                    $filterIn = false;
                }
            }
            if ($filterIn) {
                $qb->andWhere($qb->expr()->in(sprintf('%s.%s', $this->rootAlias, $property), array_values($value)));
            }
        } else {
            $qb->andWhere($qb->expr()->eq(
                sprintf('%s.%s', $this->rootAlias, $property),
                $value
            ));
        }
    }

    protected function filterString(QueryBuilder $qb, $property, $value, $mode = 'full')
    {
        /**
         * TODO
         */
        switch ($mode) {
            case 'full':
                $value = '%' . $value . '%';
                break;
            default:
                break;
        }
        $qb->andWhere(
            $qb->expr()->like(
                sprintf('%s.%s', $this->rootAlias, $property),
                $qb->expr()->literal($value)
            )
        );
    }

    protected function filterBoolean(QueryBuilder $qb, $property, $value)
    {
        $qb->andWhere($qb->expr()->eq(
            sprintf('%s.%s', $this->rootAlias, $property),
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
                sprintf('%s.%s', $this->rootAlias, $property),
                $qb->expr()->literal($parameter)
            ));
        }

        if (is_array($value)) {
            foreach ($this->getCompareTypes() as $compareType) {
                if (isset($value[$compareType]) && $value[$compareType] instanceof \DateTime) {
                    $parameter = $withTime ? $value[$compareType]->format('Y-m-d H:i:s') : $value['from']->format('Y-m-d');
                    $qb->andWhere($qb->expr()->$compareType(
                        sprintf('%s.%s', $this->rootAlias, $property),
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
            $parameter = $value->format(self::DATEINTERVAL_FORMAT);
            $qb->andWhere($qb->expr()->eq(
                sprintf('%s.%s', $this->rootAlias, $property),
                $qb->expr()->literal($parameter)
            ));
        }

        if (is_array($value)) {
            foreach ($this->getCompareTypes() as $compareType) {
                if (isset($value[$compareType]) && $value[$compareType] instanceof \DateInterval) {
                    $parameter = $value->format(self::DATEINTERVAL_FORMAT);
                    $qb->andWhere($qb->expr()->$compareType(
                        sprintf('%s.%s', $this->rootAlias, $property),
                        $qb->expr()->literal($parameter)
                    ));
                }
            }
        }
    }

    protected function filterValueIsNull(QueryBuilder $qb, $property)
    {
        $qb->andWhere($qb->expr()->isNull(sprintf('%s.%s', $this->rootAlias, $property)));
    }

    protected function filterValueIsNotNull(QueryBuilder $qb, $property)
    {
        $qb->andWhere($qb->expr()->isNotNull(sprintf('%s.%s', $this->rootAlias, $property)));
    }


    //============================================================================================
}
