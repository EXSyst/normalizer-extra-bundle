<?php

/*
 * This file is part of exsyst/normalizer-extra-bundle.
 *
 * Copyright (C) EXSyst
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EXSyst\NormalizerExtraBundle\Doctrine;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Proxy\Proxy;
use EXSyst\NormalizerExtraBundle\Initializer\InitializerInterface;

abstract class DoctrineInitializer implements InitializerInterface
{
    protected ManagerRegistry $doctrine;

    /** @var ClassMetadata[] */
    protected array $metadata = [];

    /** @var string[] */
    protected array $templates = [];

    /** @var object[] */
    protected array $objects = [];

    public function __construct(ManagerRegistry $doctrine)
    {
        $this->doctrine = $doctrine;
    }

    /** {@inheritdoc} */
    abstract public function collect(object $object): bool;

    /** {@inheritdoc} */
    abstract public function process(): void;

    /** {@inheritdoc} */
    public function initialize(object $object): void
    {
        if ($this->collect($object)) {
            $this->process();
        }
    }

    protected static function getClass(object $object): string
    {
        return ($object instanceof Proxy) ? \get_parent_class($object) : \get_class($object);
    }

    /** @internal */
    public function getIdentifier(object $object)
    {
        $class = self::getClass($object);
        $identifier = \array_values($this->getClassMetadata($class)->getIdentifierValues($object));
        foreach ($identifier as &$value) {
            if (\is_object($value)) {
                $value = $this->getIdentifier($value);
            }
        }

        return (\count($identifier) > 1) ? $identifier : $identifier[0];
    }

    protected function runCompositeKeyBatchLoadQuery(EntityManagerInterface $manager, string $class, string $part0, array $identities): void
    {
        $template = $this->getClassIdentifierQueryTemplate($class);
        $parts = [$part0];
        $i = 0;
        foreach ($identities as $_) {
            $parts[] = \sprintf($template, $i++);
            $parts[] = ' OR ';
        }
        \array_pop($parts);
        $query = $manager->createQuery(\implode('', $parts));
        $i = 0;
        foreach ($identities as $identity) {
            $j = 0;
            foreach ($identity as $value) {
                $query->setParameter(\sprintf('id%d_%d', $j, $i), $value);
                ++$j;
            }
            ++$i;
        }
        $query->getResult();
    }

    protected function getClassMetadata(string $class): ClassMetadata
    {
        return $this->metadata[$class] ?? ($this->metadata[$class] = $this->doctrine->getManagerForClass($class)->getClassMetadata($class));
    }

    protected function getClassIdentifierQueryTemplate(string $class): string
    {
        if (isset($this->templates[$class])) {
            return $this->templates[$class];
        }

        $parts = ['('];
        $i = 0;
        foreach ($this->getClassMetadata($class)->getIdentifierFieldNames() as $name) {
            $parts[] = \sprintf('e.%s = :id%d_%%1$d', $name, $i++);
            $parts[] = ' AND ';
        }
        \array_pop($parts);
        $parts[] = ')';
        $template = \implode('', $parts);
        $this->templates[$class] = $template;

        return $template;
    }
}
