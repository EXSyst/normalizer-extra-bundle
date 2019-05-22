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

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Proxy\Proxy;

class CollectionInitializer extends DoctrineInitializer
{
    /** @var EntityInitializer */
    private $entityInitializer;

    public function __construct(ManagerRegistry $doctrine, ?EntityInitializer $entityInitializer)
    {
        parent::__construct($doctrine);
        $this->entityInitializer = $entityInitializer;
    }

    /** {@inheritdoc} */
    public function collect($object): bool
    {
        if (!($object instanceof PersistentCollection)) {
            return false;
        }
        if ($object->isInitialized()) {
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

        $objects = \array_filter($objects, function (PersistentCollection $object): bool {
            return !$object->isInitialized();
        });

        // We're going to do PARTIAL queries on the owners so we have to ensure they're initialized beforehand,
        // otherwise we're going to corrupt the uninitialized ones.
        if (null !== $this->entityInitializer) {
            $shouldProcess = false;
            foreach ($objects as $hash => $object) {
                if ($this->entityInitializer->collect($object->getOwner())) {
                    $shouldProcess = true;
                }
            }
            if ($shouldProcess) {
                $this->entityInitializer->process();
            }
        } else {
            // Someone probably had a good reason to write { collection_batching: true, entity_batching: false }
            // in their configuration …
            foreach ($objects as $hash => $object) {
                $owner = $object->getOwner();
                if ($owner instanceof Proxy && !$owner->__isInitialized()) {
                    $owner->__load();
                }
            }
        }

        $objectsByClassAndField = [];

        foreach ($objects as $hash => $object) {
            $objectsByClassAndField[self::getClass($object->getOwner())][$object->getMapping()['fieldName']][$hash] = $object;
        }

        foreach ($objectsByClassAndField as $class => $objectsByField) {
            $manager = $this->doctrine->getManagerForClass($class);
            foreach ($objectsByField as $field => $objects) {
                if ($manager instanceof EntityManagerInterface) {
                    $metadata = $this->metadata[$class] ?? ($this->metadata[$class] = $manager->getClassMetadata($class));
                    $identities = \array_map([$this, 'getOwnerIdentifier'], \array_values($objects));
                    // We just run the query without using its result.
                    // Doctrine will automatically connect the dots between our query's results and our uninitialized collections.
                    if (\is_array($identities[0])) {
                        $this->runCompositeKeyBatchLoadQuery($manager, $class, \sprintf('SELECT PARTIAL e.{%s}, t FROM %s e LEFT JOIN e.%s t WHERE ', \implode(', ', $metadata->getIdentifierFieldNames()), $class, $field), $identities);
                    } else {
                        $manager->createQuery(\sprintf('SELECT PARTIAL e.{%3$s}, t FROM %s e LEFT JOIN e.%s t WHERE e.%s IN (:ids)', $class, $field, $metadata->getIdentifierFieldNames()[0]))
                            ->setParameter('ids', $identities)
                            ->getResult();
                    }
                    // If all went well, we turned the foreach below into a no-op.
                    // We still keep it in the common trunk (instead of putting it in an else branch) as a fail-safe,
                    // as a caller may cause an infinite loop if we don't initialize the collections we said we would.
                }
                foreach ($objects as $object) {
                    $object->initialize();
                }
            }
        }
    }

    /** @internal */
    public function getOwnerIdentifier(PersistentCollection $collection)
    {
        return $this->getIdentifier($collection->getOwner());
    }
}
