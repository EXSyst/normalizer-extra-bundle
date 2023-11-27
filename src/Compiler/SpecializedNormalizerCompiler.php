<?php

/*
 * This file is part of exsyst/normalizer-extra-bundle.
 *
 * Copyright (C) EXSyst
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EXSyst\NormalizerExtraBundle\Compiler;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\ORMInvalidArgumentException;
use EXSyst\DynamicClassGenerationBundle\Compiler\ClassGeneratorInterface;
use EXSyst\DynamicClassGenerationBundle\Compiler\ResolvedClassInfo;
use EXSyst\DynamicClassGenerationBundle\Helper\StreamWriter;
use EXSyst\NormalizerExtraBundle\Doctrine\FlushProofUpdater;
use EXSyst\NormalizerExtraBundle\Metadata\ClassFactory;
use EXSyst\NormalizerExtraBundle\Metadata\GroupsSecurity;
use EXSyst\NormalizerExtraBundle\Metadata\NormalizableMetadataProviderInterface;
use EXSyst\NormalizerExtraBundle\Metadata\NormalizableProperty;
use EXSyst\NormalizerExtraBundle\Normalizer\SpecializedNormalizer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\PropertyInfo\Type;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;

class SpecializedNormalizerCompiler implements ClassGeneratorInterface
{
    private const PREFIX = 'EXSyst\\NormalizerExtraBundle\\__CG__\\SpecializedNormalizer\\';
    private const SUFFIX = 'Normalizer';

    private NormalizableMetadataProviderInterface $metadataProvider;
    private ManagerRegistry $doctrine;
    private ?AuthorizationCheckerInterface $authorizationChecker;
    private ContainerInterface $container;

    public function __construct(NormalizableMetadataProviderInterface $metadataProvider, ManagerRegistry $doctrine, ?AuthorizationCheckerInterface $authorizationChecker, ContainerInterface $container)
    {
        $this->metadataProvider = $metadataProvider;
        $this->doctrine = $doctrine;
        $this->authorizationChecker = $authorizationChecker;
        $this->container = $container;
    }

    public static function getNormalizerClassFor(string $className): string
    {
        return self::PREFIX.$className.self::SUFFIX;
    }

    public function getForClass(string $className): SpecializedNormalizer
    {
        $normalizerClass = self::getNormalizerClassFor($className);

        return new $normalizerClass($this->doctrine, $this->authorizationChecker, $this->container);
    }

    public function generate(ResolvedClassInfo $class): bool
    {
        $rest = $class->getRest();
        $suffixLength = \strlen(self::SUFFIX);
        if (\strlen($rest) < $suffixLength || 0 !== \substr_compare($rest, self::SUFFIX, -$suffixLength, $suffixLength)) {
            return false;
        }
        $fd = \fopen($class->getPath(), 'wb');
        if (false === $fd) {
            throw new IOException('Cannot open temporary file to compile class '.$class->getClass());
        }
        try {
            $this->compileNormalizerClass(new StreamWriter($fd), $class);
        } finally {
            \fclose($fd);
        }

        return true;
    }

    private function compileNormalizerClass(StreamWriter $fd, ResolvedClassInfo $class): void
    {
        $normalizerClass = $class->getClass();
        $nsPos = \strrpos($normalizerClass, '\\');
        $className = \substr($class->getRest(), 0, -\strlen(self::SUFFIX));

        $helperIndex = 0;
        $helpers = [];
        $factory = $this->metadataProvider->getFactory($className);
        if (isset($factory) && isset($factory->service) && !isset($helpers[$factory->service])) {
            $helpers[$factory->service] = 'helper'.$helperIndex++;
        }
        $properties = $this->metadataProvider->getNormalizableProperties($className);
        $inverses = new \SplObjectStorage();
        $attributes = [];
        $groupsAttributes = [];
        \ksort($properties);
        foreach ($properties as $property => $meta) {
            $attributes[$property] = true;
            foreach ($meta->groups as $group) {
                $groupsAttributes[$group][$property] = true;
            }
            if (isset($meta->getHelper) && !isset($helpers[$meta->getHelper])) {
                $helpers[$meta->getHelper] = 'helper'.$helperIndex++;
            }
            if (isset($meta->getForUpdateHelper) && !isset($helpers[$meta->getForUpdateHelper])) {
                $helpers[$meta->getForUpdateHelper] = 'helper'.$helperIndex++;
            }
            if (isset($meta->setHelper) && !isset($helpers[$meta->setHelper])) {
                $helpers[$meta->setHelper] = 'helper'.$helperIndex++;
            }

            $primaryType = $meta->type;
            $target = null !== $primaryType ? ($primaryType->isCollection() ? (null !== $primaryType->getCollectionValueType() ? $primaryType->getCollectionValueType()->getClassName() : null) : $primaryType->getClassName()) : null;
            $inverseMeta = (null !== $target && null !== $meta->inverseSubProperty) ? ($this->metadataProvider->getNormalizableProperties($target)[$meta->inverseSubProperty] ?? null) : null;
            $inverses[$meta] = $inverseMeta;
            if (isset($inverseMeta->getHelper) && !isset($helpers[$inverseMeta->getHelper])) {
                $helpers[$inverseMeta->getHelper] = 'helper'.$helperIndex++;
            }
            if (isset($inverseMeta->getForUpdateHelper) && !isset($helpers[$inverseMeta->getForUpdateHelper])) {
                $helpers[$inverseMeta->getForUpdateHelper] = 'helper'.$helperIndex++;
            }
            if (isset($inverseMeta->setHelper) && !isset($helpers[$inverseMeta->setHelper])) {
                $helpers[$inverseMeta->setHelper] = 'helper'.$helperIndex++;
            }
        }
        \ksort($groupsAttributes);
        foreach ($groupsAttributes as &$entry) {
            \ksort($entry);
        }
        unset($entry);

        $groupsSecurity = $this->metadataProvider->getGroupsSecurity($className) ?? new GroupsSecurity();
        $groupsReadSecurity = \array_intersect_key($groupsSecurity->readAttributes, $groupsAttributes);
        $groupsWriteSecurity = \array_intersect_key($groupsSecurity->writeAttributes, $groupsAttributes);
        \ksort($groupsReadSecurity);
        \ksort($groupsWriteSecurity);

        $groupsInitializers = \array_intersect_key($this->metadataProvider->getGroupsInitializers($className) ?? [], $groupsAttributes);
        \ksort($groupsInitializers);
        foreach ($groupsInitializers as $service) {
            if (isset($service) && !isset($helpers[$service])) {
                $helpers[$service] = 'helper'.$helperIndex++;
            }
        }

        /* ----- File header ----- */

        $fd
            ->printfln('<?php')
            ->printfln()
            ->printfln('namespace %s;', \substr($normalizerClass, 0, $nsPos))
            ->printfln()
            ->printfln('use %s;', ContainerInterface::class)
            ->printfln('use %s;', ManagerRegistry::class)
            ->printfln('use %s;', FlushProofUpdater::class)
            ->printfln('use %s;', AuthorizationCheckerInterface::class)
            ->printfln('use %s;', ExtraAttributesException::class)
            ->printfln('use %s;', ORMInvalidArgumentException::class)
            ->printfln('use %s as Base;', SpecializedNormalizer::class)
            ->printfln('use %s as T;', $className)
            ->printfln()
            ->printfln('class %s extends Base', \substr($normalizerClass, $nsPos + 1))
            ->printfln('{')
            ->indent()
        ;

        /* ----- Fields ----- */

        foreach ($helpers as $service => $helper) {
            $fd
                ->printfln('/** @var object the %s service */', $service)
                ->printfln('private $%s;', $helper)
                ->printfln()
            ;
        }

        /* ----- Constructor ----- */

        $fd
            ->printfln('public function __construct(ManagerRegistry $doctrine, ?AuthorizationCheckerInterface $authorizationChecker, ContainerInterface $container)')
            ->printfln('{')
            ->indent()
        ;
        foreach ($helpers as $service => $helper) {
            $fd->printfln('$this->%s = $container->get(%s);', $helper, \var_export($service, true));
        }
        if (!empty($helpers)) {
            $fd->printfln();
        }
        $fd
            ->printfln('$this->doctrine = $doctrine;')
            ->printfln('$this->authorizationChecker = $authorizationChecker;')
            ->printfln()
            ->printfln('$this->attributes = %s;', \var_export($attributes, true))
            ->printfln('$this->groupsAttributes = %s;', \var_export($groupsAttributes, true))
            ->outdent()
            ->printfln('}')
            ->printfln()
        ;

        /* ----- Depth-first Normalization ----- */

        $fd
            ->printfln('/** @param T $object */')
            ->printfln('public function normalize($object, $format = null, array $context = [])')
            ->printfln('{')
            ->indent()
            ->printfln('if (isset($context[\'breadth_first_helper\']) && $context[\'breadth_first_helper\']->getCurrentObject() === $object) {')
            ->indent()
            ->printfln('$this->normalizeBreadthFirst($object, $format, $context);')
            ->printfln()
            ->printfln('return null;')
            ->outdent()
            ->printfln('}')
            ->printfln()
        ;
        self::emitAttributesCalculation($fd, $groupsReadSecurity, $groupsAttributes, $properties);
        if (\count($groupsInitializers) > 0) {
            foreach ($groupsInitializers as $group => $service) {
                $checks = [];
                foreach ($groupsAttributes[$group] as $property => $_) {
                    $checks[$property] = \sprintf('($attributes[%s] ?? false)', \var_export($property, true));
                }
                $fd
                    ->printfln('if (%s) {', \implode(' || ', $checks))
                    ->indent()
                    ->printfln('$this->%s->initialize($object);', $helpers[$service])
                    ->outdent()
                    ->printfln('}')
                ;
            }
            $fd->printfln();
        }
        $fd
            ->printfln('$normalized = [];')
            ->printfln()
        ;
        foreach ($properties as $property => $meta) {
            if (!isset($meta->getTemplate)) {
                continue;
            }
            $expression = self::generateGet($meta, $helpers, '$object');
            $fd
                ->printfln('if ($attributes[%s] ?? false) {', \var_export($property, true))
                ->indent()
            ;
            $primaryType = $meta->type;
            if (self::requiresNormalization($primaryType)) {
                /** @var NormalizableProperty|null $inverseMeta */
                $inverseMeta = $inverses[$meta];
                $fd
                    ->printfln('$normalized[%s] = $this->normalizer->normalize(%s, $format, [', \var_export($property, true), $expression)
                    ->indent()
                    ->printfln('\'shape\'             => $context[\'shape\'][%s] ?? null,', \var_export($property, true))
                ;
                if (null !== $meta->readGroups) {
                    $fd->printfln('\'groups\'            => $subGroups[%s] ?? (($context[\'force_groups\'] ?? false) ? ($context[\'groups\'] ?? null) : %s),', \var_export($property, true), \var_export($meta->readGroups, true));
                } else {
                    $fd->printfln('\'groups\'            => $subGroups[%s] ?? $context[\'groups\'] ?? null,', \var_export($property, true));
                }
                $fd
                    ->printfln('\'inline_property\'   => %s,', \var_export($meta->inlineSubProperty, true))
                    ->printfln('\'index_by_property\' => %s,', \var_export($meta->indexBySubProperty, true))
                    ->printfln('\'skip_property\'     => %s,', \var_export(null !== $inverseMeta && null !== $inverseMeta->type && $inverseMeta->type->isCollection() ? null : $meta->inverseSubProperty, true))
                    ->printfln('\'inbound_property\'  => %s,', \var_export($property, true))
                    ->outdent()
                    ->printfln('] + $context);')
                ;
            } else {
                $fd->printfln('$normalized[%s] = %s;', \var_export($property, true), $expression);
            }
            $fd
                ->outdent()
                ->printfln('}')
            ;
        }
        $fd
            ->printfln()
            ->printfln('if (isset($context[\'inline_property\']) && isset($normalized[$context[\'inline_property\']])) {')
            ->indent()
            ->printfln('$inlined = $normalized[$context[\'inline_property\']];')
            ->printfln('unset($normalized[$context[\'inline_property\']]);')
            ->printfln('$normalized += $inlined;')
            ->outdent()
            ->printfln('}')
            ->printfln()
            ->printfln('return $normalized;')
            ->outdent()
            ->printfln('}')
            ->printfln()
        ;

        /* ----- Breadth-first Normalization ----- */

        $fd
            ->printfln('/** @param T $object */')
            ->printfln('private function normalizeBreadthFirst($object, $format = null, array $context = []): void')
            ->printfln('{')
            ->indent()
            ->printfln('$helper = $context[\'breadth_first_helper\'];')
            ->printfln('$normalized = &$helper->getCurrentBindPoint();')
            ->printfln()
            ->printfln('if (isset($context[__CLASS__.\'::breadth_first_attributes\'])) {')
            ->indent()
            ->printfln('$secondPass = true;')
            ->printfln('$attributes = $context[__CLASS__.\'::breadth_first_attributes\'];')
            ->printfln('unset($context[__CLASS__.\'::breadth_first_attributes\']);')
            ->outdent()
            ->printfln('} else {')
            ->indent()
            ->printfln('$secondPass = false;')
        ;
        $this->emitAttributesCalculation($fd, $groupsReadSecurity, $groupsAttributes, $properties);
        $fd
            ->outdent()
            ->printfln('}')
            ->printfln()
        ;
        if (\count($groupsInitializers) > 0) {
            $fd
                ->printfln('if (!$secondPass) {')
                ->indent()
                ->printfln('$newPass = false;')
            ;
            foreach ($groupsInitializers as $group => $service) {
                $checks = [];
                foreach ($groupsAttributes[$group] as $property => $_) {
                    $checks[$property] = \sprintf('($attributes[%s] ?? false)', \var_export($property, true));
                }
                $fd
                    ->printfln('if ((%s) && $this->%s->collect($object)) {', \implode(' || ', $checks), $helpers[$service])
                    ->indent()
                    ->printfln('$helper->registerInitializer($this->%s);', $helpers[$service])
                    ->printfln('$newPass = true;')
                    ->outdent()
                    ->printfln('}')
                ;
            }
            $fd
                ->printfln('if ($newPass) {')
                ->indent()
                ->printfln('$helper->bind($normalized, $this, $object, $format, [')
                ->indent()
                ->printfln('__CLASS__.\'::breadth_first_attributes\' => $attributes,')
                ->outdent()
                ->printfln('] + $context);')
                ->printfln()
                ->printfln('return;')
                ->outdent()
                ->printfln('}')
                ->outdent()
                ->printfln('}')
                ->printfln()
            ;
        }
        $fd
            ->printfln('if (null === $normalized) {')
            ->indent()
            ->printfln('$normalized = [];')
            ->outdent()
            ->printfln('} else {')
            ->indent()
            ->printfln('$attributes = \\array_diff_key($attributes, $normalized);')
            ->outdent()
            ->printfln('}')
            ->printfln()
            ->printfln('$callContinuation = true;')
        ;
        foreach ($properties as $property => $meta) {
            if (!isset($meta->getTemplate)) {
                continue;
            }
            $expression = self::generateGet($meta, $helpers, '$object');
            $fd
                ->printfln('if ($attributes[%s] ?? false) {', \var_export($property, true))
                ->indent()
            ;
            $primaryType = $meta->type;
            if (self::requiresNormalization($primaryType)) {
                /** @var NormalizableProperty|null $inverseMeta */
                $inverseMeta = $inverses[$meta];
                $fd
                    ->printfln('if (%s === ($context[\'inline_property\'] ?? null)) {', \var_export($property, true))
                    ->indent()
                    ->printfln('$bindPoint = &$normalized;')
                    ->printfln('$callContinuation = false;')
                    ->outdent()
                    ->printfln('} else {')
                    ->indent()
                    ->printfln('$normalized[%s] = null;', \var_export($property, true))
                    ->printfln('$bindPoint = &$normalized[%s];', \var_export($property, true))
                    ->outdent()
                    ->printfln('}')
                    ->printfln('$helper->bind($bindPoint, $this->normalizer, %s, $format, [', $expression)
                    ->indent()
                    ->printfln('\'shape\'             => $context[\'shape\'][%s] ?? null,', \var_export($property, true))
                ;
                if (null !== $meta->readGroups) {
                    $fd->printfln('\'groups\'            => $subGroups[%s] ?? (($context[\'force_groups\'] ?? false) ? ($context[\'groups\'] ?? null) : %s),', \var_export($property, true), \var_export($meta->readGroups, true));
                } else {
                    $fd->printfln('\'groups\'            => $subGroups[%s] ?? $context[\'groups\'] ?? null,', \var_export($property, true));
                }
                $fd
                    ->printfln('\'inline_property\'   => %s,', \var_export($meta->inlineSubProperty, true))
                    ->printfln('\'index_by_property\' => %s,', \var_export($meta->indexBySubProperty, true))
                    ->printfln('\'skip_property\'     => %s,', \var_export(null !== $inverseMeta && null !== $inverseMeta->type && $inverseMeta->type->isCollection() ? null : $meta->inverseSubProperty, true))
                    ->printfln('\'inbound_property\'  => %s,', \var_export($property, true))
                    ->printfln('\'continuation\'      => (%s === ($context[\'inline_property\'] ?? null)) ? ($context[\'continuation\'] ?? null) : null,', \var_export($property, true))
                    ->outdent()
                    ->printfln('] + $context);')
                    ->printfln('unset($bindPoint);')
                ;
            } else {
                $fd->printfln('$normalized[%s] = %s;', \var_export($property, true), $expression);
            }
            $fd
                ->outdent()
                ->printfln('}')
            ;
        }
        $fd
            ->printfln('if ($callContinuation && isset($context[\'continuation\'])) {')
            ->indent()
            ->printfln('$context[\'continuation\']();')
            ->outdent()
            ->printfln('}')
            ->outdent()
            ->printfln('}')
            ->printfln()
        ;

        /* ----- Denormalization ----- */

        $fd
            ->printfln('public function denormalize($data, $class, $format = null, array $context = [])')
            ->printfln('{')
            ->indent()
            ->printfln('if (null === $data || $data instanceof T) {')
            ->indent()
            ->printfln('return $data;')
            ->outdent()
            ->printfln('}')
            ->printfln()
            ->printfln('$data = ($context[\'force_properties\'] ?? []) + $data;')
            ->printfln('$attributes = $this->getWriteAttributeSet($context, $data);')
            ->printfln()
            ->printfln('if (isset($context[\'inline_property\'])) {')
            ->indent()
            ->printfln('$inlined = \\array_diff_key($data, $attributes);')
            ->printfln('$data = \\array_intersect_key($data, $attributes);')
            ->printfln('$attributes[$context[\'inline_property\']] = true;')
            ->printfln('$data[$context[\'inline_property\']] = $inlined;')
            ->outdent()
            ->printfln('}')
            ->printfln()
            ->printfln('$object = null;')
            ->printfln('if (isset($context[\'object_to_populate\']) && $context[\'object_to_populate\'] instanceof T) {')
            ->indent()
            ->printfln('$object = $context[\'object_to_populate\'];')
            ->outdent()
            ->printfln('}')
        ;
        $this->emitInstantiateFromORM($fd, $groupsAttributes, $properties, $className, $helpers);
        $this->emitInstantiateWithFactoryOrConstructor($fd, $groupsAttributes, $properties, $className, $factory, $helpers);
        $fd
            ->printfln('$class = \\get_class($object);')
            ->printfln('if (T::class !== $class) {')
            ->indent()
            ->printfln('return $this->denormalizer->denormalize($data, $class, $format, [')
            ->indent()
            ->printfln('\'object_to_populate\' => $object,')
            ->outdent()
            ->printfln('] + $context);')
            ->outdent()
            ->printfln('}')
            ->printfln()
            ->printfln('if (isset($context[\'pre_write\']) && !empty($attributes) && !$context[\'pre_write\']($object)) {')
            ->indent()
            ->printfln('$attributes = [];')
            ->outdent()
            ->printfln('}')
            ->printfln()
        ;
        $this->emitSecurityChecks($fd, $groupsWriteSecurity, $groupsAttributes);
        $fd
            ->printfln('if ($context[\'strict\'] ?? false) {')
            ->indent()
            ->printfln('$extraAttributes = \\array_diff_key($data, $attributes);')
            ->printfln('if (\\count($extraAttributes) > 0) {')
            ->indent()
            ->printfln('throw new ExtraAttributesException(\\array_keys($extraAttributes));')
            ->outdent()
            ->printfln('}')
            ->outdent()
            ->printfln('}')
            ->printfln()
        ;
        foreach ($properties as $property => $meta) {
            $primaryType = $meta->type;
            /** @var NormalizableProperty|null $inverseMeta */
            $inverseMeta = $inverses[$meta];
            if (self::isCollectionObject($primaryType)) {
                if (!isset($meta->getTemplate) && !isset($meta->getForUpdateTemplate)) {
                    continue;
                }
                $expression = self::generateGetForUpdate($meta, $helpers, '$object');
                $fd
                    ->printfln('if ($attributes[%s] ?? false) {', \var_export($property, true))
                    ->indent()
                ;
                if ($meta->autoPersist || $meta->autoRemove) {
                    $fd->printfln('$updater = FlushProofUpdater::fromManagerAndContext($this->doctrine->getManagerForClass(%s), $context);', \var_export(self::getDenormalizationClass($primaryType->getCollectionValueType()), true));
                }
                self::emitDenormalizeCall($fd, $meta, '', $expression, $inverseMeta, $helpers);
                $fd
                    ->outdent()
                    ->printfln('}')
                ;
            } else {
                if (!isset($meta->setTemplate)) {
                    continue;
                }
                $fd
                    ->printfln('if ($attributes[%s] ?? false) {', \var_export($property, true))
                    ->indent()
                ;
                if (self::requiresDenormalization($primaryType)) {
                    self::emitDenormalizeCall($fd, $meta, '$value = ', 'null', $inverseMeta, $helpers);
                    $expression = '$value';
                } else {
                    $expression = \sprintf('$data[%s]', \var_export($property, true));
                }
                $assignment = self::generateSet($meta, $helpers, '$object', $expression);
                $oldExpression = self::generateGet($meta, $helpers, '$object');
                $inverseRemove = self::generateRemove($inverseMeta, $helpers, '$previousValue');
                if (null !== $oldExpression && (null !== $inverseRemove || $meta->autoPersist || $meta->autoRemove)) {
                    $fd
                        ->printfln('if (!\\is_object($data[%s])) {', \var_export($property, true))
                        ->indent()
                    ;
                    if ($meta->autoPersist || $meta->autoRemove) {
                        $fd->printfln('$updater = FlushProofUpdater::fromManagerAndContext($this->doctrine->getManagerForClass(%s), $context);', \var_export($primaryType->getClassName(), true));
                    }
                    if ($meta->autoPersist) {
                        $fd->printfln('$updater->persist(%s);', $expression);
                    }
                    $fd
                        ->printfln('$previousValue = %s;', $oldExpression)
                        ->printfln('if (null !== $previousValue) {')
                        ->indent()
                    ;
                    if (null !== $inverseRemove) {
                        $fd->printfln('%s', $inverseRemove);
                    }
                    if ($meta->autoRemove) {
                        $this->emitRemoveFromORM($fd, $inverseMeta, $helpers, '$previousValue');
                    }
                    $fd
                        ->outdent()
                        ->printfln('}')
                        ->outdent()
                        ->printfln('}')
                    ;
                }
                $fd
                    ->printfln('%s', $assignment)
                    ->outdent()
                    ->printfln('}')
                ;
            }
        }
        if (isset($groupsAttributes['identity'])) {
            $fd
                ->printfln()
                ->printfln('if (!empty($attributes)) {')
                ->indent()
                ->printfln('FlushProofUpdater::fromManagerAndContext($this->doctrine->getManagerForClass(T::class), $context)->update($object);')
                ->outdent()
                ->printfln('}')
            ;
        }
        $fd
            ->printfln()
            ->printfln('return $object;')
            ->outdent()
            ->printfln('}')
            ->printfln()
        ;

        /* ----- Support checks ----- */

        $fd
            ->printfln('public function supportsNormalization($data, $format = null)')
            ->printfln('{')
            ->indent()
            ->printfln('return $data instanceof T;')
            ->outdent()
            ->printfln('}')
            ->printfln()
            ->printfln('public function supportsDenormalization($data, $type, $format = null)')
            ->printfln('{')
            ->indent()
            ->printfln('return T::class === $type;')
            ->outdent()
            ->printfln('}')
        ;

        /* ----- File footer ----- */

        $fd
            ->outdent()
            ->printfln('}')
        ;
    }

    private static function emitAttributesCalculation(StreamWriter $fd, array $groupsReadSecurity, array $groupsAttributes, array $properties): void
    {
        $fd
            ->printfln('$attributes = $this->getReadAttributeSet($context);')
            ->printfln('$subGroups = [];')
            ->printfln()
        ;
        self::emitSecurityChecks($fd, $groupsReadSecurity, $groupsAttributes);
        foreach ($properties as $property => $meta) {
            if ($meta->alwaysNormalize) {
                $fd
                    ->printfln('if (!($attributes[%s] ?? false)) {', \var_export($property, true))
                    ->indent()
                    ->printfln('$attributes[%s] = true;', \var_export($property, true))
                    ->printfln('$subGroups[%s] = [];', \var_export($property, true))
                    ->outdent()
                    ->printfln('}')
                    ->printfln()
                ;
            }
        }
        $fd
            ->printfln('if (isset($context[\'skip_property\'])) {')
            ->indent()
            ->printfln('unset($attributes[$context[\'skip_property\']]);')
            ->printfln('unset($subGroups[$context[\'skip_property\']]);')
            ->outdent()
            ->printfln('}')
            ->printfln()
            ->printfln('if (isset($context[\'inline_property\'])) {')
            ->indent()
            ->printfln('$attributes[$context[\'inline_property\']] = true;')
            ->printfln('$context[\'shape\'][$context[\'inline_property\']] = $context[\'shape\'] ?? null;')
            ->printfln('unset($subGroups[$context[\'inline_property\']]);')
            ->outdent()
            ->printfln('}')
            ->printfln()
            ->printfln('if (isset($context[\'index_by_property\'])) {')
            ->indent()
            ->printfln('$attributes[$context[\'index_by_property\']] = true;')
            ->printfln('$context[\'shape\'][$context[\'index_by_property\']] = $context[\'shape\'] ?? null;')
            ->printfln('unset($subGroups[$context[\'index_by_property\']]);')
            ->outdent()
            ->printfln('}')
            ->printfln()
        ;
    }

    private static function emitInstantiateFromORM(StreamWriter $fd, array $groupsAttributes, array $properties, string $className, array $helpers): void
    {
        if (isset($groupsAttributes['identity'])) {
            $checks = self::generateChecks($groupsAttributes['identity']);
            if (\count($checks) > 1) {
                $fd
                    ->printfln('if (null === $object && (%s)) {', \implode(' || ', $checks))
                    ->indent()
                ;
                foreach ($checks as $property => $check) {
                    $fd
                        ->printfln('if (!(%s)) {', $check)
                        ->indent()
                        ->printfln('throw new \\RuntimeException(\'Cannot retrieve instance of %s because part of the primary key has been specified but %s has been omitted\');', $className, $property)
                        ->outdent()
                        ->printfln('}')
                    ;
                }
            } else {
                $fd
                    ->printfln('if (null === $object && %s) {', \reset($checks))
                    ->indent()
                ;
            }
            $identifier = [];
            $i = 0;
            foreach ($groupsAttributes['identity'] as $property => $_) {
                $fd
                    ->printfln('if (!isset($data[%s])) {', \var_export($property, true))
                    ->indent()
                    ->printfln('throw new \\RuntimeException(\'Cannot retrieve instance of %s because primary key property %s is null\');', $className, $property)
                    ->outdent()
                    ->printfln('}')
                ;
            }
            foreach ($groupsAttributes['identity'] as $property => $_) {
                $meta = $properties[$property];
                if (self::requiresDenormalization($meta->type)) {
                    self::emitDenormalizeCall($fd, $meta, \sprintf('$identity%d = ', $i), 'null', null, $helpers);
                    $identifier[$meta->originalName] = \sprintf('$identity%d', $i);
                } else {
                    $identifier[$meta->originalName] = \sprintf('$data[%s]', \var_export($property, true));
                }
                ++$i;
            }
            $fd
                ->printfln('try {')
                ->indent()
                ->printfln('$object = $this->doctrine->getManagerForClass(T::class)->find(T::class, [')
                ->indent()
            ;
            foreach ($identifier as $property => $expression) {
                $fd->printfln('%s => %s,', \var_export($property, true), $expression);
            }
            $fd
                ->outdent()
                ->printfln(']);')
                ->outdent()
                ->printfln('} catch (ORMInvalidArgumentException $ex) {')
                ->indent()
                ->printfln('// Deserializing a relationship with attributes, an end of which is an entity in the process of creation.')
                ->printfln('// Ignore the exception and carry on creating a new relationship object.')
                ->outdent()
                ->printfln('}')
                ->outdent()
                ->printfln('}')
            ;
            $fd
                ->printfln('if (null === $object && ($context[\'strict_find\'] ?? false)) {')
                ->indent()
                ->printfln('return null;')
                ->outdent()
                ->printfln('}')
            ;
        }
    }

    private function emitInstantiateWithFactoryOrConstructor(StreamWriter $fd, array $groupsAttributes, array $properties, string $className, ?ClassFactory $factory, array $helpers): void
    {
        $fd
            ->printfln('if (null === $object) {')
            ->indent()
        ;
        if (null !== $factory) {
            $reflClass = new \ReflectionClass(isset($factory->service) ? $this->container->get($factory->service) : ($factory->class ?? $className));
            $reflConstructor = $reflClass->getMethod($factory->method);
        } else {
            $reflClass = new \ReflectionClass($className);
            $reflConstructor = ($reflClass->isInterface() || $reflClass->isAbstract()) ? null : $reflClass->getConstructor();
        }
        if ($reflConstructor) {
            $parameters = $reflConstructor->getParameters();
            $canConstruct = true;
            foreach ($parameters as $parameter) {
                if (!isset($properties[$parameter->name]) && !$parameter->isVariadic() && !$parameter->isDefaultValueAvailable()) {
                    $canConstruct = false;
                    break;
                }
            }
            if ($canConstruct) {
                $propertiesByOriginalName = [];
                foreach ($properties as $property) {
                    $propertiesByOriginalName[$property->originalName ?? $property->name] = $property;
                }
                $args = [];
                foreach ($parameters as $i => $parameter) {
                    $property = $propertiesByOriginalName[$parameter->name] ?? null;
                    $primaryType = (null === $property) ? null : $property->type;
                    if ($parameter->isVariadic()) {
                        if (null !== $property) {
                            if (self::requiresDenormalization($primaryType)) {
                                $fd->printfln('if ($attributes[%s] ?? false) {', \var_export($property->name, true));
                                self::emitDenormalizeCall($fd, $property, \sprintf('$param%d = ', $i), 'null', null, $helpers);
                                $fd
                                    ->printfln('} else {')
                                    ->indent()
                                    ->printfln('$param%d = [];', $i)
                                    ->outdent()
                                    ->printfln('}')
                                ;
                            } else {
                                $fd->printfln('$param%d = ($attributes[%s] ?? false) ? $data[%2$s] : [];', $i, \var_export($property->name, true));
                            }
                            $fd
                                ->printfln('if (!\\is_array($param%d)) {', $i)
                                ->indent()
                                ->printfln('throw new \\RuntimeException(\'Cannot construct instance of %s because %s parameter %s must be an array, or omitted\');', $className, (null !== $factory) ? 'factory' : 'constructor', $property->name)
                                ->outdent()
                                ->printfln('}')
                            ;
                            $args[] = \sprintf('...$param%d', $i);
                        }
                    } elseif ($parameter->isDefaultValueAvailable()) {
                        if (null !== $property) {
                            if (self::requiresDenormalization($primaryType)) {
                                $fd->printfln('if ($attributes[%s] ?? false) {', \var_export($property->name, true));
                                self::emitDenormalizeCall($fd, $property, \sprintf('$param%d = ', $i), 'null', null, $helpers);
                                $fd
                                    ->printfln('} else {')
                                    ->indent()
                                    ->printfln('$param%d = %s;', $i, \var_export($parameter->getDefaultValue(), true))
                                    ->outdent()
                                    ->printfln('}')
                                ;
                                $args[] = \sprintf('$param%d', $i);
                            } else {
                                $args[] = \sprintf('($attributes[%s] ?? false) ? $data[%1$s] : %s', \var_export($property->name, true), \var_export($parameter->getDefaultValue(), true));
                            }
                        } else {
                            $args[] = \var_export($parameter->getDefaultValue(), true);
                        }
                    } else {
                        $fd
                            ->printfln('if (!($attributes[%s] ?? false)) {', \var_export($property->name, true))
                            ->indent()
                            ->printfln('throw new \\RuntimeException(\'Cannot construct instance of %s because %s parameter %s has been omitted or is not writable in the current context\');', $className, (null !== $factory) ? 'factory' : 'constructor', $property->name)
                            ->outdent()
                            ->printfln('}')
                        ;
                        if (self::requiresDenormalization($primaryType)) {
                            self::emitDenormalizeCall($fd, $property, \sprintf('$param%d = ', $i), 'null', null, $helpers);
                            $args[] = \sprintf('$param%d', $i);
                        } else {
                            $args[] = \sprintf('$data[%s]', \var_export($property->name, true));
                        }
                    }
                }
                $fd->printfln('$object = %s(%s);', (null !== $factory) ? (isset($factory->service) ? \sprintf('$this->%s->%s', $helpers[$factory->service], $factory->method) : \sprintf('\\%s::%s', $factory->class ?? $className, $factory->method)) : 'new T', implode(', ', $args));
                foreach ($parameters as $i => $parameter) {
                    $property = $propertiesByOriginalName[$parameter->name] ?? null;
                    if (null !== $property) {
                        $fd
                            ->printfln('unset($data[%s]);', \var_export($parameter->name, true))
                            ->printfln('unset($attributes[%s]);', \var_export($parameter->name, true))
                        ;
                    }
                }
            } else {
                $fd->printfln('throw new \\LogicException(\'Cannot construct instance of %s because of a %s parameter which cannot be handled by the denormalizer\');', $className, (null !== $factory) ? 'factory' : 'constructor');
            }
        } elseif (!$reflClass->isInterface() && !$reflClass->isAbstract()) {
            $fd->printfln('$object = new T();');
        } else {
            $fd->printfln('throw new \\LogicException(\'Cannot construct instance of %s because it is an abstract class or interface\');', $className);
        }
        $fd
            ->outdent()
            ->printfln('}')
            ->printfln()
        ;
        if (isset($groupsAttributes['identity'])) {
            foreach ($groupsAttributes['identity'] as $property => $_) {
                $fd
                    ->printfln('unset($data[%s]);', \var_export($property, true))
                    ->printfln('unset($attributes[%s]);', \var_export($property, true))
                ;
            }
        }
    }

    private static function emitSecurityChecks(StreamWriter $fd, array $groupsSecurity, array $groupsAttributes): void
    {
        if (\count($groupsSecurity) > 0) {
            $fd
                ->printfln('if (isset($context[\'check_authorizations\'])) {')
                ->indent()
            ;
            foreach ($groupsSecurity as $group => $securityAttribute) {
                $checks = self::generateChecks($groupsAttributes[$group]);
                $fd
                    ->printfln('if ((%s) && !$this->authorizationChecker->isGranted(%s, $object)) {', \implode(' || ', $checks), \var_export($securityAttribute, true))
                    ->indent()
                ;
                foreach ($groupsAttributes[$group] as $property => $_) {
                    $fd->printfln('unset($attributes[%s]);', \var_export($property, true));
                }
                $fd
                    ->outdent()
                    ->printfln('}')
                ;
            }
            $fd
                ->outdent()
                ->printfln('}')
                ->printfln()
            ;
        }
    }

    private static function emitDenormalizeCall(StreamWriter $fd, NormalizableProperty $meta, string $assignment = '$value = ', string $objectToPopulate = 'null', ?NormalizableProperty $inverseMeta = null, ?array $helpers = null): void
    {
        $primaryType = $meta->type;
        $fd
            ->printfln(self::isNullable($primaryType) ? '%s(null === $data[%s]) ? null : $this->denormalizer->denormalize($data[%2$s], %s, $format, [' : '%s$this->denormalizer->denormalize($data[%s], %s, $format, [', $assignment, \var_export($meta->name, true), \var_export(self::getDenormalizationClass($primaryType), true))
            ->indent()
            ->printfln('\'object_to_populate\' => %s,', $objectToPopulate)
        ;
        if (null !== $meta->writeGroups) {
            $fd->printfln('\'groups\'             => %s,', \var_export($meta->writeGroups, true));
        }
        $fd
            ->printfln('\'auto_persist\'       => %s,', $meta->autoPersist ? 'true' : 'false')
            ->printfln('\'strict_find\'        => %s,', $meta->autoPersist ? 'false' : 'true')
            ->printfln('\'inline_property\'    => %s,', \var_export($meta->inlineSubProperty, true))
            ->printfln('\'index_by_property\'  => %s,', \var_export($meta->indexBySubProperty, true))
            ->printfln('\'inbound_property\'   => %s,', \var_export($meta->name, true))
        ;
        if (null !== $inverseMeta) {
            $fd->printfln('\'force_properties\'   => \\is_array($data[%s]) ? [%s => %s] : null,', \var_export($meta->name, true), \var_export($inverseMeta->name, true), self::isCollectionObject($inverseMeta->type) ? \sprintf('isset($data[%s][%s]) ? [\'$merge\', $data[%1$s][%2$s], [\'$add\', $object]] : (\array_key_exists(%2$s, $data[%1$s]) ? [$object] : [\'$add\', $object])', \var_export($meta->name, true), \var_export($inverseMeta->name, true)) : '$object');
        } else {
            $fd->printfln('\'force_properties\'   => null,');
        }
        $isCollectionObject = self::isCollectionObject($primaryType);
        $inverseRemove = ($isCollectionObject && null !== $helpers) ? self::generateRemove($inverseMeta, $helpers, '$element') : null;
        if ($isCollectionObject && (null !== $inverseRemove || $meta->autoRemove)) {
            $fd
                ->printfln('\'on_remove\'          => function ($element) use ($object%s): void {', $meta->autoRemove ? ', $updater' : '')
                ->indent()
            ;
            if (null !== $inverseRemove) {
                $fd->printfln('%s', $inverseRemove);
            }
            if ($meta->autoRemove) {
                if (null === $inverseMeta) {
                    throw new \TypeError('Property '.$meta->name.' must have an inverse');
                }
                self::emitRemoveFromORM($fd, $inverseMeta, $helpers, '$element');
            }
            $fd
                ->outdent()
                ->printfln('},')
            ;
        } else {
            $fd->printfln('\'on_remove\'          => null,');
        }
        $fd
            ->printfln('\'pre_write\'          => null,')
            ->outdent()
            ->printfln('] + $context);')
        ;
    }

    private static function emitRemoveFromORM(StreamWriter $fd, NormalizableProperty $inverseMeta, array $helpers, string $previousValue): void
    {
        if (self::isCollectionObject($inverseMeta->type)) {
            if (isset($inverseMeta->getTemplate) || isset($inverseMeta->getForUpdateTemplate)) {
                $inverseExpression = self::generateGetForUpdate($inverseMeta, $helpers, $previousValue);
                $fd
                    ->printfln('if (0 === \\count(%s)) {', $inverseExpression)
                    ->indent()
                    ->printfln('$updater->remove(%s);', $previousValue)
                    ->outdent()
                    ->printfln('}')
                ;
            }
        } else {
            $fd->printfln('$updater->remove(%s);', $previousValue);
        }
    }

    private static function generateChecks(array $properties): array
    {
        $checks = [];
        foreach ($properties as $property => $_) {
            $checks[$property] = \sprintf('($attributes[%s] ?? false)', \var_export($property, true));
        }

        return $checks;
    }

    private static function generateRemove(?NormalizableProperty $meta, array $helpers, string $old): ?string
    {
        if (null === $meta) {
            return null;
        }

        $add = null;
        $remove = null;
        if (self::isCollectionObject($meta->type)) {
            if (isset($meta->getTemplate) || isset($meta->getForUpdateTemplate)) {
                $inverseExpression = self::generateGetForUpdate($meta, $helpers, $old);
                $remove = \sprintf('%s->removeElement($object);', $inverseExpression);
            }
        } elseif (null === $meta->type || $meta->type->isNullable()) {
            if (isset($meta->setTemplate)) {
                $remove = self::generateSet($meta, $helpers, $old, 'null');
            }
        }

        return $remove;
    }

    private static function generateGet(NormalizableProperty $meta, array $helpers, string $object): ?string
    {
        return isset($meta->getTemplate)
            ? (isset($meta->getHelper)
                ? \sprintf($meta->getTemplate, $object, '$this->'.$helpers[$meta->getHelper])
                : \sprintf($meta->getTemplate, $object))
            : null;
    }

    private static function generateGetForUpdate(NormalizableProperty $meta, array $helpers, string $object): ?string
    {
        return isset($meta->getForUpdateTemplate)
            ? (isset($meta->getForUpdateHelper)
                ? \sprintf($meta->getForUpdateTemplate, $object, '$this->'.$helpers[$meta->getForUpdateHelper])
                : \sprintf($meta->getForUpdateTemplate, $object))
            : self::generateGet($meta, $helpers, $object);
    }

    private static function generateSet(NormalizableProperty $meta, array $helpers, string $object, string $value): ?string
    {
        return isset($meta->setTemplate)
            ? (isset($meta->setHelper)
                ? \sprintf($meta->setTemplate, $object, $value, '$this->'.$helpers[$meta->setHelper])
                : \sprintf($meta->setTemplate, $object, $value))
            : null;
    }

    private static function requiresNormalization(?Type $type): bool
    {
        return null === $type || Type::BUILTIN_TYPE_ARRAY === $type->getBuiltinType() || Type::BUILTIN_TYPE_OBJECT === $type->getBuiltinType();
    }

    private static function requiresDenormalization(?Type $type): bool
    {
        return null !== $type && (Type::BUILTIN_TYPE_ARRAY === $type->getBuiltinType() || Type::BUILTIN_TYPE_OBJECT === $type->getBuiltinType()) && (!$type->isCollection() || self::requiresDenormalization($type->getCollectionValueType()));
    }

    private static function getDenormalizationClass(Type $type): string
    {
        return $type->isCollection() ? (self::getDenormalizationClass($type->getCollectionValueType()).'[]') : ($type->getClassName() ?? $type->getBuiltinType());
    }

    private static function isNullable(?Type $type): bool
    {
        return null === $type || $type->isNullable();
    }

    private static function isCollectionObject(?Type $type): bool
    {
        return null !== $type && $type->isCollection() && Type::BUILTIN_TYPE_OBJECT === $type->getBuiltinType();
    }
}
