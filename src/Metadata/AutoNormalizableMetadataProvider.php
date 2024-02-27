<?php

/*
 * This file is part of exsyst/normalizer-extra-bundle.
 *
 * Copyright (C) EXSyst
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EXSyst\NormalizerExtraBundle\Metadata;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\ClassMetadata;
use EXSyst\NormalizerExtraBundle\Attribute\Association;
use EXSyst\NormalizerExtraBundle\Attribute\Collection;
use EXSyst\NormalizerExtraBundle\Attribute\Factory;
use EXSyst\NormalizerExtraBundle\Attribute\GroupsInitializers;
use EXSyst\NormalizerExtraBundle\Attribute\GroupsReadSecurity;
use EXSyst\NormalizerExtraBundle\Attribute\GroupsWriteSecurity;
use EXSyst\NormalizerExtraBundle\Reflection;
use Symfony\Component\PropertyInfo\PropertyInfoExtractorInterface;
use Symfony\Component\PropertyInfo\Type;
use Symfony\Component\Serializer\Mapping\ClassDiscriminatorMapping;
use Symfony\Component\Serializer\Mapping\ClassDiscriminatorResolverInterface;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;

class AutoNormalizableMetadataProvider implements NormalizableMetadataProviderInterface
{
    private PropertyInfoExtractorInterface $propertyInfoExtractor;
    private ClassMetadataFactoryInterface $classMetadataFactory;
    private ?ClassDiscriminatorResolverInterface $classDiscriminatorResolver;
    private ?NameConverterInterface $nameConverter;
    private ?ManagerRegistry $doctrine;

    public function __construct(PropertyInfoExtractorInterface $propertyInfoExtractor, ClassMetadataFactoryInterface $classMetadataFactory, ?ClassDiscriminatorResolverInterface $classDiscriminatorResolver, ?NameConverterInterface $nameConverter, ?ManagerRegistry $doctrine)
    {
        $this->propertyInfoExtractor = $propertyInfoExtractor;
        $this->classMetadataFactory = $classMetadataFactory;
        $this->classDiscriminatorResolver = $classDiscriminatorResolver;
        $this->nameConverter = $nameConverter;
        $this->doctrine = $doctrine;
    }

    /**
     * @template T
     * @param \ReflectionAttribute<T>[] $attributes
     * @return T|null
     */
    private static function newInstanceOfFirst(array $attributes): ?object
    {
        return empty($attributes) ? null : \reset($attributes)->newInstance();
    }

    /** {@inheritdoc} */
    public function getFactory(string $className): ?ClassFactory
    {
        $reflClass = new \ReflectionClass($className);
        /** @var Factory|null $factoryAttribute */
        $factoryAttribute = self::newInstanceOfFirst($reflClass->getAttributes(Factory::class));

        if (null === $factoryAttribute) {
            return null;
        }

        $factory = new ClassFactory();
        $factory->service = $factoryAttribute->service;
        $factory->class = $factoryAttribute->class;
        $factory->method = $factoryAttribute->method;

        return $factory;
    }

    /** {@inheritdoc} */
    public function getGroupsSecurity(string $className): ?GroupsSecurity
    {
        $parentClass = \get_parent_class($className);
        $parent = (false !== $parentClass) ? $this->getGroupsSecurity($parentClass) : null;

        $reflClass = new \ReflectionClass($className);
        /** @var GroupsReadSecurity|null $readAttribute */
        $readAttribute = self::newInstanceOfFirst($reflClass->getAttributes(GroupsReadSecurity::class));
        /** @var GroupsWriteSecurity|null $writeAttribute */
        $writeAttribute = self::newInstanceOfFirst($reflClass->getAttributes(GroupsWriteSecurity::class));

        if (null === $readAttribute && null === $writeAttribute) {
            return $parent;
        }

        $security = new GroupsSecurity();
        $security->readAttributes = (null === $readAttribute) ? [] : $readAttribute->value;
        $security->writeAttributes = (null === $writeAttribute) ? [] : $writeAttribute->value;

        if (null !== $parent) {
            $security->readAttributes += $parent->readAttributes;
            $security->writeAttributes += $parent->writeAttributes;
        }

        return $security;
    }

    /** {@inheritdoc} */
    public function getGroupsInitializers(string $className): ?array
    {
        $parentClass = \get_parent_class($className);
        $parent = (false !== $parentClass) ? $this->getGroupsInitializers($parentClass) : null;

        $reflClass = new \ReflectionClass($className);
        /** @var GroupsInitializers|null $initializersAttribute */
        $initializersAttribute = self::newInstanceOfFirst($reflClass->getAttributes(GroupsInitializers::class));

        return (null !== $initializersAttribute) ? ($initializersAttribute->value + ($parent ?? [])) : $parent;
    }

    /** {@inheritdoc} */
    public function getNormalizableProperties(string $className): array
    {
        /** @var NormalizableProperty[] $properties */
        $properties = [];

        foreach ($this->propertyInfoExtractor->getProperties($className) as $originalName) {
            $name = (null === $this->nameConverter) ? $originalName : $this->nameConverter->normalize($originalName, $className);
            $properties[$name] = new NormalizableProperty($name);
            $properties[$name]->originalName = $originalName;
        }

        $metadata = $this->classMetadataFactory->getMetadataFor($className);

        foreach ($metadata->getAttributesMetadata() as $originalName => $meta) {
            $name = (null === $this->nameConverter) ? $originalName : $this->nameConverter->normalize($originalName, $className);
            if (!isset($properties[$name])) {
                $properties[$name] = new NormalizableProperty($name);
                $properties[$name]->originalName = $originalName;
            }
            $properties[$name]->groups = $meta->getGroups();
        }

        $reflClass = new \ReflectionClass($className);
        $doctrineMetadata = $this->getDoctrineClassMetadata($className);
        foreach ($properties as $property) {
            if (null !== $doctrineMetadata && $doctrineMetadata->isIdentifier($property->originalName)) {
                if (!\in_array('identity', $property->groups)) {
                    $property->groups[] = 'identity';
                }
                $property->alwaysNormalize = true;
            }
            $property->type = $this->propertyInfoExtractor->getTypes($className, $property->originalName)[0] ?? null;

            $reflGetter = self::getAnyMethodWithArity($reflClass, 0, 'get'.\ucfirst($property->originalName), 'is'.\ucfirst($property->originalName), 'has'.\ucfirst($property->originalName), 'can'.\ucfirst($property->originalName));
            $reflSetter = self::getAnyMethodWithArity($reflClass, 1, 'set'.\ucfirst($property->originalName));
            $reflProperty = Reflection::getNonPublicProperty($reflClass, $property->originalName);

            $property->getTemplate = (null !== $reflGetter)
                ? \sprintf('%%s->%s()', \str_replace('%', '%%', $reflGetter->name))
                : (null !== $reflProperty && $reflProperty->isPublic() ? \sprintf('%%s->%s', \str_replace('%', '%%', $property->originalName)) : null);
            $property->setTemplate = (null !== $reflSetter)
                ? \sprintf('%%s->%s(%%s);', \str_replace('%', '%%', $reflSetter->name))
                : (null !== $reflProperty && $reflProperty->isPublic() ? \sprintf('%%s->%s = %%s;', \str_replace('%', '%%', $property->originalName)) : null);

            if (!isset($property->getTemplate) && !isset($property->setTemplate)) {
                continue;
            }

            /** @var Association|null $associationAttribute */
            $associationAttribute = null;
            /** @var Collection|null $collectionAttribute */
            $collectionAttribute = null;

            if (null !== $reflGetter) {
                $associationAttribute = self::newInstanceOfFirst($reflGetter->getAttributes(Association::class));
                $collectionAttribute = self::newInstanceOfFirst($reflGetter->getAttributes(Collection::class));
            }
            if (null !== $reflProperty) {
                $associationAttribute = $associationAttribute ?? self::newInstanceOfFirst($reflProperty->getAttributes(Association::class));
                $collectionAttribute = $collectionAttribute ?? self::newInstanceOfFirst($reflProperty->getAttributes(Collection::class));
            }

            $associationAttribute = $this->getSyntheticAssociationAttribute($doctrineMetadata, $property->originalName, $associationAttribute);

            if (null !== $associationAttribute) {
                $property->inverseSubProperty = $associationAttribute->inversedBy;
                $property->inlineSubProperty = $associationAttribute->inline;
                $property->autoPersist = $associationAttribute->autoPersist;
                $property->autoRemove = $associationAttribute->autoRemove ?? $associationAttribute->autoPersist;
                $property->readGroups = $associationAttribute->readGroups;
                $property->writeGroups = $associationAttribute->writeGroups;
                $autoToMany = null !== $property->type && $property->type->isCollection();
                $toMany = $associationAttribute->toMany ?? $autoToMany;
                $valueType = (null !== $associationAttribute->target) ? new Type(Type::BUILTIN_TYPE_OBJECT, !$toMany && ($autoToMany || $property->type->isNullable()), $associationAttribute->target) : ($autoToMany ? $property->type->getCollectionValueType() : $property->type);
                if ($toMany) {
                    if ($autoToMany) {
                        $property->type = new Type($property->type->getBuiltinType(), $property->type->isNullable(), $property->type->getClassName(), $property->type->isCollection(), $property->type->getCollectionKeyType(), $valueType);
                    } else {
                        $property->type = new Type(Type::BUILTIN_TYPE_OBJECT, false, \Traversable::class, true, new Type(Type::BUILTIN_TYPE_INT), $valueType);
                    }
                } else {
                    $property->type = $valueType;
                }
            }
            if (null !== $collectionAttribute) {
                $property->indexBySubProperty = $collectionAttribute->indexBy;
            }
        }
        $properties = \array_filter($properties, function (NormalizableProperty $property): bool {
            return isset($property->getTemplate) || isset($property->setTemplate);
        });

        $mapping = $this->resolveMappingForClass($className);
        if (null !== $mapping) {
            foreach ($mapping->getTypesMapping() as $type => $typeClass) {
                if (\is_a($className, $typeClass, true)) {
                    $originalName = $mapping->getTypeProperty();
                    $name = (null === $this->nameConverter) ? $originalName : $this->nameConverter->normalize($originalName, $className);
                    $property = new NormalizableProperty($name);
                    $property->type = new Type(Type::BUILTIN_TYPE_STRING);
                    $property->alwaysNormalize = true;
                    $property->getTemplate = \str_replace('%', '%%', \var_export($type, true));
                    $properties[$name] = $property;
                    break;
                }
            }
        }

        return $properties;
    }

    private static function getAnyMethodWithArity(\ReflectionClass $class, int $arity, string ...$methods): ?\ReflectionMethod
    {
        foreach ($methods as $method) {
            try {
                $reflMethod = $class->getMethod($method);
                if ($reflMethod->getNumberOfParameters() >= $arity && $reflMethod->getNumberOfRequiredParameters() <= $arity) {
                    return $reflMethod;
                }
            } catch (\ReflectionException $e) {
                // ignore
            }
        }

        return null;
    }

    private function getDoctrineClassMetadata(string $className): ?ClassMetadata
    {
        if (null === $this->doctrine) {
            return null;
        }

        $manager = $this->doctrine->getManagerForClass($className);
        if (null === $manager) {
            return null;
        }

        return $manager->getClassMetadata($className);
    }

    private function getSyntheticAssociationAttribute(?ClassMetadata $metadata, string $name, ?Association $physicalAssociation): ?Association
    {
        if (null === $metadata || !$metadata->hasAssociation($name)) {
            return $physicalAssociation;
        }

        $association = (null !== $physicalAssociation) ? clone $physicalAssociation : new Association();
        $association->target = $association->target ?? $metadata->getAssociationTargetClass($name);
        $association->toMany = $association->toMany ?? $metadata->isCollectionValuedAssociation($name);
        $association->inversedBy = $association->inversedBy ?? $metadata->getAssociationMappedByTargetField($name) ?? $metadata->associationMappings[$name]['inversedBy'] ?? null;

        return $association;
    }

    private function resolveMappingForClass(string $className): ?ClassDiscriminatorMapping
    {
        if (null === $this->classDiscriminatorResolver) {
            return null;
        }

        $reflectionClass = new \ReflectionClass($className);
        if ($parentClass = $reflectionClass->getParentClass()) {
            return $this->classDiscriminatorResolver->getMappingForClass($parentClass->getName());
        }

        foreach ($reflectionClass->getInterfaceNames() as $interfaceName) {
            if (null !== ($interfaceMapping = $this->classDiscriminatorResolver->getMappingForClass($interfaceName))) {
                return $interfaceMapping;
            }
        }

        return null;
    }
}
