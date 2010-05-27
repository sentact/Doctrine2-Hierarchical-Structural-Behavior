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
     * Returns the QueryBuilder factory
     *
     * @return MaterializedPathQueryFactory
     **/
    public function getQueryFactory()
    {
        return $this->qbFactory;
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
     * undocumented function
     *
     * @return void
     **/
    public function delete($entity)
    {
        $node = $this->getNode($entity);
        $qb = $this->qbFactory
            ->getBaseQueryBuilder();
        $qb->where($qb->expr()->eq('e.' . $this->getIdFieldName(), $node->getId()));
        $this->deleteQuerySet($qb);
    }

    public function deleteQuerySet($qb, $knownChildren = false)
    {
        if ($knownChildren) {
            $batch = 20;
            $i = 0;
            $iterableResult = $qb->getQuery()->iterate();
            while (($row = $iterableResult->next()) !== false) {
                $this->em->remove($row[0]);
                if (($i++ % $batch) == 0) {
                    $this->em->flush();
                    $this->em->clear();
                }
            }
            $this->em->flush();
            $this->em->clear();
            return;
        }
        $expr = $qb->expr();
        $qb->orderBy('e.' . $this->getDepthFieldName())
            ->addOrderBy('e.' . $this->getPathFieldName());

        $removed = array();
        foreach ($qb->getQuery()->getResult() as $node) {
            $node = $this->getNode($node);
            $found = false;
            $range = array_slice(range(1, strlen($node->getPath()) / $this->getStepLength()), 0, -1);
            foreach ($range as $depth) {
                $path = PathHelper::getBasePath($node, $node->getPath(), $depth);
                if (isset($removed[$path])) {
                    // already removing a parent of this node
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $removed[$node->getPath()] = $node;
            }
        }

        $parents = array();
        $toRemove = array();
        foreach ($removed as $path => $node) {
            $parentPath = PathHelper::getBasePath($node, $node->getPath(), $node->getDepth() - 1);
            if ($parentPath) {
                if (!isset($parents[$parentPath])) {
                    $parents[$parentPath] = $node->getParent(true);
                }
                $parent = $parents[$parentPath];
                if ($parent && $parent->getNumberOfChildren() > 0) {
                    $parent->setValue($this->getNumChildrenFieldName(), $parent->getNumberOfChildren() - 1);
                }
            }
            if (!$node->isLeaf()) {
                $toRemove[] = $expr->like('e.' . $this->getPathFieldName(), $expr->literal($node->getPath() . '%'));
            } else {
                $toRemove[] = $expr->eq('e.' . $this->getPathFieldName(), $expr->literal($node->getPath()));
            }
        }
        if ($toRemove) {
            $orX = $expr->orX();
            $orX->addMultiple($toRemove);
            $qb->where($orX);
            $this->deleteQuerySet($qb, true);
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
