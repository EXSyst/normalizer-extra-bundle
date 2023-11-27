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

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Proxy\Proxy;

class EntityInitializer extends DoctrineInitializer
{
    /** {@inheritdoc} */
    public function collect($object): bool
    {
        if (!($object instanceof Proxy)) {
            return false;
        }
        if ($object->__isInitialized()) {
            return false;
        }
        $this->objects[\spl_object_hash($object)] = $object;

        return true;
    }

    /** {@inheritdoc} */
    public function process(): void
    {
        if (empty($this->objects)) {
            return;
        }

        $objects = $this->objects;
        $this->objects = [];

        $objects = \array_filter($objects, function (Proxy $object): bool {
            return !$object->__isInitialized();
        });

        $objectsByClass = [];

        foreach ($objects as $hash => $object) {
            $class = \get_parent_class($object);
            $objectsByClass[$class][$hash] = $object;
        }

        foreach ($objectsByClass as $class => $objects) {
            $manager = $this->doctrine->getManagerForClass($class);
            if ($manager instanceof EntityManagerInterface) {
                $metadata = $this->metadata[$class] ?? ($this->metadata[$class] = $manager->getClassMetadata($class));
                $identities = \array_map([$this, 'getIdentifier'], \array_values($objects));
                // We just run the query without using its result.
                // Doctrine will automatically connect the dots between our query's results and our uninitialized collections.
                if (\is_array($identities[0])) {
                    $this->runCompositeKeyBatchLoadQuery($manager, $class, \sprintf('SELECT e FROM %s e WHERE ', $class), $identities);
                } else {
                    $manager->createQuery(\sprintf('SELECT e FROM %s e WHERE e.%s IN (:ids)', $class, $metadata->getIdentifierFieldNames()[0]))
                        ->setParameter('ids', $identities)
                        ->getResult();
                }
                // If all went well, we turned the foreach below into a no-op.
                // We still keep it in the common trunk (instead of putting it in an else branch) as a fail-safe,
                // as a caller may cause an infinite loop if we don't initialize the entities we said we would.
            }
            foreach ($objects as $object) {
                $object->__load();
            }
        }
    }
}
