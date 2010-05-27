<?php

namespace DoctrineExtensions\Hierarchical\MaterializedPath;

use DoctrineExtensions\Hierarchical\MaterializedPath\MaterializedPathNodeInfo,
    DoctrineExtensions\Hierarchical\MaterializedPath\MaterializedPathNodeDecorator,
    DoctrineExtensions\Hierarchical\AbstractManager,
    DoctrineExtensions\Hierarchical\Node,
    Doctrine\ORM\EntityManager,
    Doctrine\ORM\Mapping\ClassMetaData,
    Doctrine\ORM\PersistentCollection,
    Doctrine\ORM\NoResultException;



class MaterializedPathManager extends AbstractManager implements MaterializedPathNodeInfo
{
     /**
     * __construct
     *
     * @param Doctrine\ORM\EntityManager $em
     * @param Doctrine\ORM\Mapping\ClassMetadata $meta
     * @return void
     */
    public function __construct(EntityManager $em, ClassMetadata $meta)
    {
        parent::__construct($em, $meta);
        $this->qbFactory = new MaterializedPathQueryFactory($this, $meta);
    }

    /**
     * Decorates the entity with the appropriate Node decorator
     *
     * @param mixed $entity
     * @return DoctrineExtensions\Hierarchical\Node
     */
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

    /**
     * Adds the entity as a root node
     *
     * Decorates via getNode() as needed
     *
     * @param mixed $entity
     * @return DoctrineExtensions\Hierarchical\Node
     */
    public function addRoot($entity)
    {
        $node = $this->getNode($entity);
        $entity = $node->unwrap();
        if ($node->getId()) {
            throw new HierarchicalException('This entity is already initialized and can not be made a root node');
        }

        $this->em->getConnection()->beginTransaction();
        try {
            $lastRoot = $this->getLastRootNode();
            if ($lastRoot && $this->getNodeOrderBy()) {
                return $lastRoot->addSibling('sorted-sibling', $this);
            }

            if ($lastRoot) {
                $newPath = PathHelper::incPath($this->prototype, $lastRoot->getPath());
            } else {
                $newPath = PathHelper::getPath($this->prototype, null, 1, 1);
            }

            $node->setValue($this->getDepthFieldName(), 1);
            $node->setValue($this->getPathFieldName(), $newPath);
            $this->em->persist($entity);
            $this->em->flush();
            $this->em->getConnection()->commit();
        } catch (\Exception $e) {
            $this->em->getConnection()->rollback();
            $this->em->close();
            throw $e;
        }
        return $node;
    }

    /**
     * Returns all root nodes of the tree
     *
     * @return Collection
     **/
    public function getRootNodes()
    {
        return $this->getNodes($this->qbFactory
            ->getRootNodeQueryBuilder()
            ->getQuery()
            ->getResult());
    }

    /**
     * Retrns the first root node in the tree
     *
     * @return Node|null
     **/
    public function getFirstRootNode()
    {
        $qb = $this->_qbFactory
            ->getRootNodeQueryBuilder()
            ->setMaxResults(1);
        try {
            return $qb->getQuery()->getSingleResult();
        } catch (NoResultsException $e) {
            return null;
        }
    }

    /**
     * Returns last root of the tree or null
     *
     * @return Node|null
     **/
    public function getLastRootNode()
    {
        $qb = $this->qbFactory
            ->getRootNodeQueryBuilder()
            ->orderBy('e.' . $this->getPathFieldName(), 'DESC')
            ->setMaxResults(1);
        try {
            return $this->getNode($qb->getQuery()->getSingleResult());
        } catch (NoResultException $e) {
            return null;
        }
    }



    /**
     * BEGIN MaterializedPathNodeInfo Implementation
     **/

    /**
     * Retrieves the Entity identifier field name
     *
     * @return string
     */
    public function getIdFieldName()
    {
        return $this->prototype->getIdFieldName();
    }

    /**
     * Retrieves the Entity path field name
     *
     * @return string
     */
    public function getPathFieldName()
    {
        return $this->prototype->getPathFieldName();
    }

    /**
     * Retrieves the Entity parent_id field name
     *
     * @return string
     */
    public function getParentIdFieldName()
    {
        return $this->prototype->getParentIdFieldName();
    }

    /**
     * Retrieves the Entity depth field name
     *
     * @return string
     */
    public function getDepthFieldName()
    {
        return $this->prototype->getDepthFieldName();
    }

    /**
     * Retrieves the Entity numChildren field name
     *
     * @return string
     */
    public function getNumChildrenFieldName()
    {
        return $this->prototype->getNumChildrenFieldName();
    }

    /**
     * Returns the Node level order by
     *
     * @return array Array of fieldNames, or empty array
     */
    public function getNodeOrderBy()
    {
        return $this->prototype->getNodeOrderBy();
    }

    /**
     * Returns the alphabet used for path generation
     *
     * Recommended Default: '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'
     *
     * @return string
     */
    public function getAlphabet()
    {
        return $this->prototype->getAlphabet();
    }

    /**
     * Returns the step length for path
     *
     * Recommended Default: 4
     *
     * @return integer
     */
    public function getStepLength()
    {
        return $this->prototype->getStepLength();
    }

    /**
     * END MaterializedPathNodeInfo Implementation
     **/
}
