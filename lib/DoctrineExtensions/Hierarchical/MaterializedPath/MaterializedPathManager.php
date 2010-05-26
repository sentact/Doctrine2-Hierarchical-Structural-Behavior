<?php

namespace DoctrineExtensions\Hierarchical\MaterializedPath;

use DoctrineExtensions\Hierarchical\MaterializedPath\MaterializedPathNodeInfo,
    DoctrineExtensions\Hierarchical\MaterializedPath\MaterializedPathNodeDecorator,
    DoctrineExtensions\Hierarchical\AbstractManager,
    DoctrineExtensions\Hierarchical\Node,
    Doctrine\ORM\EntityManager,
    Doctrine\ORM\PersistentCollection;


class MaterializedPathManager extends AbstractManager
{
    public function getNode($entity)
    {
        if ($entity instanceof Node) {
            if ($entity instanceof MaterializedPathNodeDecorator) {
                return $entity;
            } else {
                throw new \InvalidArgumentException('Provided node is not of type MaterializedPathNodeDecorator');
            }
        } elseif (! $entity instanceof MaterializedPathNodeInfo) {
                throw new \InvalidArgumentException('Provided entity is not of type MaterializedPathNodeInfo');
        }

        return new MaterializedPathNodeDecorator($entity, $this);
    }

    public function addRoot($entity)
    {
        $entity = $this->getNode($entity);

        $entity->addRoot();

        return $entity;
    }
}
