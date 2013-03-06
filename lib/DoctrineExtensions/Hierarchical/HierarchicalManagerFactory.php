<?php

namespace DoctrineExtensions\Hierarchical;

use Doctrine\ORM\EntityManager,
    DoctrineExtensions\Hierarchical\MaterializedPath\MaterializedPathManager,
    DoctrineExtensions\Hierarchical\NestedSet\NestedSetManager;

class HierarchicalManagerFactory
{
    /**
     * Factory method to create a Hierarchical Manager for the specified class
     *
     * @param EntityManager $em
     * @param string $className
     * @return AbstractManager
     */
    public static function getManager(EntityManager $em, $className)
    {
        $meta = $em->getClassMetadata($className);
        $reflClass = $meta->reflClass;
        if ($reflClass->implementsInterface('DoctrineExtensions\Hierarchical\MaterializedPath\MaterializedPathNodeInfo')) {
            return new MaterializedPathManager($em, $meta);
        } elseif ($reflClass->implementsInterface('NestedSetNodeInfo')) {
            return new NestedSetHierarchicalManager($em, $meta);
        }

        throw new HierarchicalException('Named entity does not implement any known Node interface');
    }
}
