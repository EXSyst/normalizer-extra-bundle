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

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Proxy\Proxy;
use Doctrine\ORM\UnitOfWork;

class FlushProofUpdater
{
    /** @var ObjectManager */
    private $objectManager;

    /** @var UnitOfWork|null */
    private $flushingUnitOfWork;

    public function __construct(ObjectManager $objectManager, bool $flushing = false)
    {
        $this->objectManager = $objectManager;
        $this->flushingUnitOfWork = ($flushing && $objectManager instanceof EntityManagerInterface) ? $objectManager->getUnitOfWork() : null;
    }

    public static function fromManagerAndContext(ObjectManager $objectManager, array $context): self
    {
        return new FlushProofUpdater($objectManager, \in_array($objectManager, $context['flushing_object_managers'] ?? [], true));
    }

    public static function fromClassAndContext($entityOrClass, array $context): ?self
    {
        if (empty($context['flushing_object_managers'])) {
            return null;
        }

        $reflClass = new \ReflectionClass($entityOrClass);
        if ($reflClass->implementsInterface(Proxy::class)) {
            $reflClass = $reflClass->getParentClass();
            if (!$reflClass) {
                return null;
            }
        }
        $class = $reflClass->getName();

        /** @var ObjectManager $objectManager */
        foreach ($context['flushing_object_managers'] as $objectManager) {
            if (!$objectManager->getMetadataFactory()->isTransient($class)) {
                return new FlushProofUpdater($objectManager, true);
            }
        }

        return null;
    }

    public function getObjectManager(): ObjectManager
    {
        return $this->objectManager;
    }

    public function isFlushing(): bool
    {
        return null !== $this->flushingUnitOfWork;
    }

    public function persist($entity): void
    {
        if (null !== $this->flushingUnitOfWork) {
            if (UnitOfWork::STATE_NEW === $this->flushingUnitOfWork->getEntityState($entity, UnitOfWork::STATE_NEW)) {
                $this->flushingUnitOfWork->persist($entity);
                $this->flushingUnitOfWork->computeChangeSet($this->objectManager->getClassMetadata(\get_class($entity)), $entity);
            } else {
                $this->flushingUnitOfWork->recomputeSingleEntityChangeSet($this->objectManager->getClassMetadata(\get_class($entity)), $entity);
            }
        } else {
            $this->objectManager->persist($entity);
        }
    }

    public function update($entity): void
    {
        if (null !== $this->flushingUnitOfWork || $this->objectManager instanceof EntityManagerInterface) {
            $unitOfWork = $this->flushingUnitOfWork ?? $this->objectManager->getUnitOfWork();
            if (UnitOfWork::STATE_MANAGED === $unitOfWork->getEntityState($entity, UnitOfWork::STATE_NEW)) {
                if (null !== $this->flushingUnitOfWork) {
                    $unitOfWork->recomputeSingleEntityChangeSet($this->objectManager->getClassMetadata(\get_class($entity)), $entity);
                } else {
                    $this->objectManager->persist($entity);
                }
            }
        }
    }

    public function remove($entity): void
    {
        $this->objectManager->remove($entity);
    }

    public function updateCollection(Collection $collection): void
    {
        if (null !== $this->flushingUnitOfWork && $collection instanceof PersistentCollection) {
            $entity = $collection->getOwner();
            if (UnitOfWork::STATE_MANAGED === $this->flushingUnitOfWork->getEntityState($entity, UnitOfWork::STATE_NEW)) {
                $this->flushingUnitOfWork->computeChangeSet($this->objectManager->getClassMetadata(\get_class($entity)), $entity);
            }
        }
    }

    public static function updateCollectionUsingContext(Collection $collection, array $context): void
    {
        if ($collection instanceof PersistentCollection) {
            $updater = self::fromClassAndContext($collection->getOwner(), $context);
            if (null !== $updater) {
                $updater->updateCollection($collection);
            }
        }
    }

    public function deleteCollection(Collection $collection): void
    {
        if ($collection instanceof PersistentCollection) {
            $entity = $collection->getOwner();
            $fieldName = $collection->getMapping()['fieldName'];
            $metadata = $this->objectManager->getClassMetadata(\get_class($entity));
            if ($metadata->getFieldValue($entity, $fieldName) === $collection) {
                $metadata->setFieldValue($entity, $fieldName, new ArrayCollection());
                if (null !== $this->flushingUnitOfWork && UnitOfWork::STATE_MANAGED === $this->flushingUnitOfWork->getEntityState($entity, UnitOfWork::STATE_NEW)) {
                    $this->flushingUnitOfWork->scheduleCollectionDeletion($collection);
                }
            }
        }
    }

    public function flush(): self
    {
        if (null === $this->flushingUnitOfWork) {
            if ($this->objectManager instanceof EntityManagerInterface) {
                $this->flushingUnitOfWork = $this->objectManager->getUnitOfWork();
            }
            try {
                $this->objectManager->flush();
            } finally {
                $this->flushingUnitOfWork = null;
            }
        }

        return $this;
    }
}
