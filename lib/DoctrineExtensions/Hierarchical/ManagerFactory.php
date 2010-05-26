<?php

namespace DoctrineExtensions\Hierarchical;

use DoctrineExtensions\Hierarchical\MaterializedPath\MaterializedPathNodeInfo,
    DoctrineExtensions\Hierarchical\AdjacencyList\AdjacencyListNodeInfo,
    DoctrineExtensions\Hierarchical\NestedSet\NestedSetNodeInfo,
    Doctrine\ORM\EntityManager;

class HierarchicalManagerFactory
{
    public static function getManager(EntityManager $em, $entity)
    {
        if ($entity instanceof MaterializedPathNodeInfo) {
            return new MaterializedPathHiearchicalManager($em);
        } elseif ($entity instanceof NestedSetNodeInfo) {
            return new NestedSetHierarchicalManager($em);
        }

        throw new HierarchicalException('Provided entity does not implement any known Node interface');
    }
}
