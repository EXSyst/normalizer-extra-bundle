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

use Doctrine\Common\Annotations\Reader;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\ClassMetadata;
use EXSyst\NormalizerExtraBundle\Annotation\Association;
use EXSyst\NormalizerExtraBundle\Annotation\Collection;
use EXSyst\NormalizerExtraBundle\Annotation\Factory;
use EXSyst\NormalizerExtraBundle\Annotation\GroupsInitializers;
use EXSyst\NormalizerExtraBundle\Annotation\GroupsReadSecurity;
use EXSyst\NormalizerExtraBundle\Annotation\GroupsWriteSecurity;
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
    private Reader $annotationReader;

    public function __construct(PropertyInfoExtractorInterface $propertyInfoExtractor, ClassMetadataFactoryInterface $classMetadataFactory, ?ClassDiscriminatorResolverInterface $classDiscriminatorResolver, ?NameConverterInterface $nameConverter, ?ManagerRegistry $doctrine, Reader $annotationReader)
    {
        $this->propertyInfoExtractor = $propertyInfoExtractor;
        $this->classMetadataFactory = $classMetadataFactory;
        $this->classDiscriminatorResolver = $classDiscriminatorResolver;
        $this->nameConverter = $nameConverter;
        $this->doctrine = $doctrine;
        $this->annotationReader = $annotationReader;
    }

    /** {@inheritdoc} */
    public function getFactory(string $className): ?ClassFactory
    {
        $reflClass = new \ReflectionClass($className);
        /** @var Factory|null $factoryAnnotation */
        $factoryAnnotation = $this->annotationReader->getClassAnnotation($reflClass, Factory::class);

        if (null === $factoryAnnotation) {
            return null;
        }

        $factory = new ClassFactory();
        $factory->service = $factoryAnnotation->service;
        $factory->class = $factoryAnnotation->class;
        $factory->method = $factoryAnnotation->method;

        return $factory;
    }

    /** {@inheritdoc} */
    public function getGroupsSecurity(string $className): ?GroupsSecurity
    {
        $parentClass = \get_parent_class($className);
        $parent = (false !== $parentClass) ? $this->getGroupsSecurity($parentClass) : null;

        $reflClass = new \ReflectionClass($className);
        /** @var GroupsReadSecurity|null $readAnnotation */
        $readAnnotation = $this->annotationReader->getClassAnnotation($reflClass, GroupsReadSecurity::class);
        /** @var GroupsWriteSecurity|null $writeAnnotation */
        $writeAnnotation = $this->annotationReader->getClassAnnotation($reflClass, GroupsWriteSecurity::class);

        if (null === $readAnnotation && null === $writeAnnotation) {
            return $parent;
        }

        $security = new GroupsSecurity();
        $security->readAttributes = (null === $readAnnotation) ? [] : $readAnnotation->value;
        $security->writeAttributes = (null === $writeAnnotation) ? [] : $writeAnnotation->value;

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
        /** @var GroupsInitializers|null $initializersAnnotation */
        $initializersAnnotation = $this->annotationReader->getClassAnnotation($reflClass, GroupsInitializers::class);

        return (null !== $initializersAnnotation) ? ($initializersAnnotation->value + ($parent ?? [])) : $parent;
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

            /** @var Association|null $associationAnnotation */
            $associationAnnotation = null;
            /** @var Collection|null $collectionAnnotation */
            $collectionAnnotation = null;

            if (null !== $reflGetter) {
                $associationAnnotation = $this->annotationReader->getMethodAnnotation($reflGetter, Association::class);
                $collectionAnnotation = $this->annotationReader->getMethodAnnotation($reflGetter, Collection::class);
            }
            if (null !== $reflProperty) {
                $associationAnnotation = $associationAnnotation ?? $this->annotationReader->getPropertyAnnotation($reflProperty, Association::class);
                $collectionAnnotation = $collectionAnnotation ?? $this->annotationReader->getPropertyAnnotation($reflProperty, Collection::class);
            }

            $associationAnnotation = $this->getSyntheticAssociationAnnotation($doctrineMetadata, $property->originalName, $associationAnnotation);

            if (null !== $associationAnnotation) {
                $property->inverseSubProperty = $associationAnnotation->inversedBy;
                $property->inlineSubProperty = $associationAnnotation->inline;
                $property->autoPersist = $associationAnnotation->autoPersist;
                $property->autoRemove = $associationAnnotation->autoRemove ?? $associationAnnotation->autoPersist;
                $property->readGroups = $associationAnnotation->readGroups;
                $property->writeGroups = $associationAnnotation->writeGroups;
                $autoToMany = null !== $property->type && $property->type->isCollection();
                $toMany = $associationAnnotation->toMany ?? $autoToMany;
                $valueType = (null !== $associationAnnotation->target) ? new Type(Type::BUILTIN_TYPE_OBJECT, !$toMany && ($autoToMany || $property->type->isNullable()), $associationAnnotation->target) : ($autoToMany ? $property->type->getCollectionValueType() : $property->type);
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
            if (null !== $collectionAnnotation) {
                $property->indexBySubProperty = $collectionAnnotation->indexBy;
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

    private function getSyntheticAssociationAnnotation(?ClassMetadata $metadata, string $name, ?Association $physicalAssociation): ?Association
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
