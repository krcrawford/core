<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Core\Bridge\Doctrine\Orm\Filter;

use ApiPlatform\Core\Bridge\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Core\Exception\InvalidArgumentException;
use Doctrine\DBAL\Types\Type as DBALType;
use Doctrine\ORM\QueryBuilder;

/**
 * Filters the collection by numeric values.
 *
 * Filters collection by equality of numeric properties.
 *
 * For each property passed, if the resource does not have such property or if
 * the value is not numeric, the property is ignored.
 *
 * @author Amrouche Hamza <hamza.simperfit@gmail.com>
 * @author Teoh Han Hui <teohhanhui@gmail.com>
 */
class NumericFilter extends AbstractContextAwareFilter
{
    /**
     * Type of numeric in Doctrine.
     *
     * @see http://doctrine-orm.readthedocs.org/projects/doctrine-dbal/en/latest/reference/types.html
     */
    const DOCTRINE_NUMERIC_TYPES = [
        DBALType::BIGINT => true,
        DBALType::DECIMAL => true,
        DBALType::FLOAT => true,
        DBALType::INTEGER => true,
        DBALType::SMALLINT => true,
    ];

    /**
     * {@inheritdoc}
     */
    public function getDescription(string $resourceClass): array
    {
        $description = [];

        $properties = $this->properties;
        if (null === $properties) {
            $properties = array_fill_keys($this->getClassMetadata($resourceClass)->getFieldNames(), null);
        }

        foreach ($properties as $property => $unused) {
            if (!$this->isPropertyMapped($property, $resourceClass) || !$this->isNumericField($property, $resourceClass)) {
                continue;
            }

            $description[$property] = [
                'property' => $property,
                'type' => $this->getType((string) $this->getDoctrineFieldType($property, $resourceClass)),
                'required' => false,
            ];
        }

        return $description;
    }

    /**
     * Gets the PHP type corresponding to this Doctrine type.
     */
    private function getType(string $doctrineType = null): string
    {
        if (null === $doctrineType || DBALType::DECIMAL === $doctrineType) {
            return 'string';
        }

        if (DBALType::FLOAT === $doctrineType) {
            return 'float';
        }

        return 'int';
    }

    /**
     * {@inheritdoc}
     */
    protected function filterProperty(string $property, $value, QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, string $operationName = null)
    {
        if (
            !$this->isPropertyEnabled($property, $resourceClass) ||
            !$this->isPropertyMapped($property, $resourceClass)
        ) {
            return;
        }

        if (!is_numeric($value)) {
            $this->logger->notice('Invalid filter ignored', [
                'exception' => new InvalidArgumentException(sprintf('Invalid numeric value for "%s::%s" property', $resourceClass, $property)),
            ]);

            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $field = $property;

        if ($this->isPropertyNested($property, $resourceClass)) {
            list($alias, $field) = $this->addJoinsForNestedProperty($property, $alias, $queryBuilder, $queryNameGenerator, $resourceClass);
        }

        if (!isset(self::DOCTRINE_NUMERIC_TYPES[(string) $this->getDoctrineFieldType($property, $resourceClass)])) {
            $this->logger->notice('Invalid filter ignored', [
                'exception' => new InvalidArgumentException(sprintf('The field "%s" of class "%s" is not a doctrine numeric type.', $field, $resourceClass)),
            ]);

            return;
        }

        $valueParameter = $queryNameGenerator->generateParameterName($field);

        $queryBuilder
            ->andWhere(sprintf('%s.%s = :%s', $alias, $field, $valueParameter))
            ->setParameter($valueParameter, $value, (string) $this->getDoctrineFieldType($property, $resourceClass));
    }

    /**
     * Determines whether the given property refers to a numeric field.
     */
    protected function isNumericField(string $property, string $resourceClass): bool
    {
        $propertyParts = $this->splitPropertyParts($property, $resourceClass);
        $metadata = $this->getNestedMetadata($resourceClass, $propertyParts['associations']);

        return isset(self::DOCTRINE_NUMERIC_TYPES[(string) $metadata->getTypeOfField($propertyParts['field'])]);
    }
}
