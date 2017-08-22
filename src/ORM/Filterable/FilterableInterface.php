<?php

namespace Abryb\DoctrineBehaviors\ORM\Filterable;


class FilterableInterface
{
    const VALUE_NOT_NULL = 'not_null';

    const COMPARE_EQ = 'eq';
    const COMPARE_GT = 'gt';
    const COMPARE_GTE = 'gte';
    const COMPARE_LT = 'lt';
    const COMPARE_LTE = 'lte';

    const DATEINTERVAL_FORMAT = 'P%YY%MM%DDT%HH%IM%SS';
}