<?php

namespace DoctrineExtensions\Hierarchical\EnumeratedPath;

use DoctrineExtensions\Hierarchical\AbstractDecorator,
    DoctrineExtensions\Hierarchical\Node,
    DoctrineExtensions\Hierarchical\HierarchicalException,
    Doctrine\ORM\NoResultException;

class EnumeratedPathNodeDecorator extends AbstractDecorator implements Node
{
    protected $_parent;

    protected $_children;

    // Delegate support for Decorator object

    /**
     * Retrieves the Entity identifier field name
     *
     * @return string
     */
    public function getIdFieldName()
    {
        return $this->_entity->getIdFieldName();
    }

    /**
     * Retrieves the Entity path field name
     *
     * @return string
     */
    public function getPathFieldName()
    {
        return $this->_entity->getPathFieldName();
    }

    /**
     * Retrieves the Entity parent_id field name
     *
     * @return string
     */
    public function getParentIdFieldName()
    {
        return $this->_entity->getParentIdFieldName();
    }

    /**
     * Retrieves the Entity depth field name
     *
     * @return string
     */
    public function getDepthFieldName()
    {
        return $this->_entity->getDepthFieldName();
    }

    /**
     * Retrieves the Entity numChildren field name
     *
     * @return string
     */
    public function getNumChildrenFieldName()
    {
        return $this->_entity->getNumChildrenFieldName();
    }

    /**
     * Returns the Node level order by
     *
     * @return array Array of fieldNames, or empty array
     */
    public function getNodeOrderBy()
    {
        return $this->_entity->getNodeOrderBy();
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
        return $this->_entity->getAlphabet();
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
        return $this->_entity->getStepLength();
    }

    // End of delegate support of Decorator object

    /**
     * Returns the depth (level) of the node
     * @param integer
     */
    public function getDepth()
    {
        return $this->_getValue($this->getDepthFieldName());
    }

    public function getPath()
    {
        return $this->_getValue($this->getPathFieldName());
    }

    public function setParent($entity)
    {
        $this->_parent = $this->_getNode($entity);
    }


    public function createRoot()
    {
        if ($this->_getValue($this->getParentIdFieldName())) {
            throw new HierarchicalException('This entity is already initialized and can not be made a root node');
        }

        $this->_setValue($this->getDepthFieldName(), 1);
        $this->_setValue($this->getParentIdFieldName(), null);

        $this->_hm->getEntityManager()->persist($this->_entity);
        $this->_setValue($this->getPathFieldName(), $this->_getPath(null, 1, 1));
    }

    /**
     * Returns all of the node's siblings, including the node itself
     *
     * @return void
     */
    public function getSiblings()
    {
        $qb = $this->_getSiblingQueryBuilder();
        $q = $qb->getQuery();
        return $q->getResult();
    }

    public function getChildren()
    {
        if ($this->isLeaf()) {
            return array();
        }

        $qb = $this->_getBaseQueryBuilder();
        $expr = $qb->expr();
        $andX = $qb->andX();

        $andX->add($expr->eq('e.' . $this->getDepthFieldName(), $this->getDepth() + 1));
        $andX->add($expr->between('e.' . $this->_getChildrenPathInterval($this->getPath())));
        $qb->where($andX);
        $q = $qb->getQuery();
        return $q->getResult();
    }

    public function getNextSibling()
    {
        $qb = $this->_getSiblingQueryBuilder();

        $expr = $qb->expr();
        $qb->andWhere($expr->gt('e.' . $this->getPathFieldName(), $this->getPath()));
        $q = $qb->getQuery();
        $q->setMaxResults(1);
        try {
            return $q->getSingleResult();
        } catch (NoResultException $e) {
            return null;
        }
    }

    public function getDescendants()
    {
        $qb = $this->_getTreeQueryBuilder($this);

        $expr = $qb->expr();
        $qb->andWhere($expr->not($expr->eq($this->getIdFieldName(), $this->_getValue($this->getIdFieldName()))));
        $q = $qb->getQuery();
        return $q->getResult();
    }

    public function getPrevSibling()
    {
        $qb = $this->_getSiblingQueryBuilder();

        $expr = $qb->expr();
        $qb->andWhere($expr->lt('e.' . $this->getPathFieldName(), $this->getPath()));
        $qb->orderBy('e.' . $this->getPathFieldName(), 'DESC');
        $q = $qb->getQuery();
        $q->setMaxResults(1);
        try {
            return $q->getSingleResult();
        } catch (NoResultException $e) {
            return null;
        }
    }

    public function getLastSibling()
    {
        $qb = $this->_getSiblingQueryBuilder();
        $expr = $qb->expr();
        $qb->orderBy('e.' . $this->getPathFieldName(), 'DESC');
        $q = $qb->getQuery();
        $q->setMaxResults(1);
        try {
            return $q->getSingleResult();
        } catch (NoResultException $e) {
            return null;
        }
    }
    
    public function getNumberOfChildren()
    {
        return $this->_getValue($this->getNumChildrenFieldName());
    }

    public function isSiblingOf($entity)
    {
        $node = $this->_getNode($entity);
        $res = $this->getDepth() == $node->getDepth();
        if ($this->getDepth() > 1) {
            // make sure non-root nodes share parent
            $parentPath = $this->_getBasePath($this->getPath(), $this->getDepth() - 1);
            return $res && 0 === strpos($node->getPath(), $parentPath);
        }
        return $res;
    }

    public function isChildOf($entity)
    {
        $node = $this->_getNode($entity);

        return 0 === strpos($this->getPath(), $node->getPath())
            && $this->getDepth() == $node->getDepth() + 1;
    }

    public function isDescendantOf($entity)
    {
        $node = $this->_getNode($entity);
        return 0 === strpos($this->getPath(), $node->getPath())
            && $this->getDepth() > $node->getDepth();
    }

    public function addChild($entity)
    {
        if (! $this->isLeaf() && $this->getNodeOrderBy()) {
            // There are child nodes and a NodeOrderBy has been specified
            // Use sorted insertion to addSiblings instead
            return $this->getLastChild()->addSibling('sorted-sibling', $entity);
        }

        $node = $this->_getNode($entity);
        $entity = $node->unwrap();
        if ($entity === $this->_entity) {
            throw new \IllegalArgumentException('Node cannot be added as child of itself.');
        }

        $this->_class->reflFields[$this->getDepthFieldName()]->setValue($entity, $this->getDepth() + 1);

        if (!$this->isLeaf()) {
            $newPath = $this->_incPath($this->getLastChild()->getPath());
            $this->_class->reflFields[$this->getPathFieldName()]->setValue($entity, $newPath);
        } else {
            $newPath = $this->_getPath($this->getPath(), $node->getDepth(), 1);
            $this->_class->reflFields[$this->getPathFieldName()]->setValue($entity, $newPath);
            // TODO if strlen($newPath) > MAX_LEN throw exception
        }

        $this->_hm->getEntityManager()->persist($entity);

        $node->setParent($this);
        $this->_setValue($this->getNumChildrenFieldName(), $this->getNumberOfChildren() + 1);
        return $node;
    }

    public function addSibling($pos = null, $entity)
    {
        xdebug_break();
        $pos = $this->_processAddSiblingPos($pos);
        $node = $this->_getNode($entity);
        $entity = $node->unwrap();
        if ($entity === $this->_entity) {
            throw new \IllegalArgumentException('Node cannot be added as sibling of itself.');
        }

        $this->_class->reflFields[$this->getDepthFieldName()]->setValue($entity, $this->getDepth());
        
        if ($pos == 'sorted-sibling') {
            $siblingQb = $this->_getSortedPosQueryBuilder($this->_getSiblingQueryBuilder(), $node);
            
            try {
                $q = $siblingQb->getQuery();
                $q->setMaxResults(1);
                $sibling = $q->getSingleResult();
                $sNode = $this->_getNode($sibling);
                $newPos = $this->_getLastPosInPath($sNode->getPath());
            } catch (NoResultException $e) {
                $newPos = null;
            }
            
            if (null === $newPos) {
                $pos = 'last-sibling';
            }
        } else {
            $newPos = null;
            $siblingQb = array();
        }

        $queries = array();
        list($_, $newPath) = $this->_moveAddSibling($pos, $newPos, $this->getDepth(), $this, $siblingQb, $queries, null, false);
        
        $parentPath = $this->_getBasePath($newPath, $this->getDepth() - 1);
        if ($parentPath) {
            $queries[] = $this->_getQbUpdateNumChild($parentPath, 'inc');
        }
        
        $em = $this->_hm->getEntityManager();
        $em->getConnection()->beginTransaction();
        try {
            foreach ($queries as $qb) {
                $q = $qb->getQuery();
                var_dump($q->getSql());
                $q->execute();
            }
            $this->_class->reflFields[$this->getPathFieldName()]->setValue($entity, $newPath);
            $em->persist($entity);
            $em->flush();
            $em->getConnection()->commit();
        } catch (\Exception $e) {
            $em->getConnection()->rollback();
            $em->close();
            throw $e;
        }
    }

    public function hasChildren()
    {
        // TODO
        $qb = $this->_getBaseQueryBuilder();

    }

    public function hasParent()
    {
        return is_null($this->_getValue($this->getParentFieldName()));
    }

    public function isLeaf()
    {
        return $this->getNumberOfChildren() == 0;
    }

    protected function _getBasePath($path, $depth)
    {
        if (!$path) {
            return '';
        }
        return substr($path, 0, $depth * $this->getStepLength());
    }
    
    protected function _getPath($path, $depth, $newStep)
    {
        $parentPath = $this->_getBasePath($path, $depth - 1);
        $key = strtoupper(base_convert($newStep, 10, strlen($this->getAlphabet())));
        $pad = str_repeat('0', $this->getStepLength() - strlen($key));
        return "{$parentPath}{$pad}{$key}";
    }

    protected function _incPath($path)
    {
        $last = substr($path, -$this->getStepLength());
        $newPos = base_convert($last, strlen($this->getAlphabet()), 10) + 1;
        $key = base_convert($newPos, 10, strlen($this->getAlphabet()));
        if (strlen($key) > $this->getStepLength()) {
            throw new \Exception("Path overflow from: '{$path}'");
        }
        $pad = str_repeat('0', $this->getStepLength() - strlen($key));
        return "{$last}{$pad}{$key}";
    }

    protected function _getChildrenPathInterval($path)
    {
        return array(
            $path . str_repeat(substr($this->getAlphabet(), 0, 1), $this->getStepLength()),
            $path . str_repeat(substr($this->getAlphabet(), -1), $this->getStepLength())
        );
    }

    protected function _getBaseQueryBuilder()
    {
        return $this->_hm->getEntityManager()->createQueryBuilder()
            ->select('e')
            ->from($this->_class->name, 'e')
            ->orderBy('e.' . $this->getPathFieldName());
    }

    protected function _getTreeQueryBuilder($parent = null)
    {
        $qb = $this->_getBaseQueryBuilder();
        if (null == $parent) {
            return $qb;
        }

        $expr = $qb->expr();
        $andX = $qb->andX();

        if ($parent->isLeaf()) {
            $andX->add($expr->eq($this->getIdFieldName(), $parent->_getValue($this->getIdFieldName())));
        } else {
            $andX->add($expr->like($this->getPathFieldName(), $expr->literal($parent->getPath() . '%')));
            $andX->add($expr->gte($this->getDepthFieldName(), $parent->getDepth()));
        }
        $qb->where($andX);
        return $qb;
    }

    protected function _getSiblingQueryBuilder()
    {
        $qb = $this->_getBaseQueryBuilder();
        $expr = $qb->expr();
        $andX = $expr->andX();

        $andX->add($expr->eq('e.' . $this->getDepthFieldName(), $this->getDepth()));

        if ($this->getDepth() > 1) {
            $parentPath = $this->_getBasePath(
                $this->getPath(),
                $this->getDepth() - 1
            );
            $pathInterval = $this->_getChildrenPathInterval($parentPath);

            $andX->add($expr->between('e.' . $this->getPathFieldName(), $expr->literal((string) $pathInterval[0]), $expr->literal($pathInterval[1])));
        }
        $qb->where($andX);
        return $qb;
    }
    
    protected function _getSortedPosQueryBuilder($siblingQueryBuilder, $node)
    {
        $node = $this->_getNode($entity);
        $entity = $node->unwrap();

        $qb = $siblingQueryBuilder;
        $expr = $qb->expr();
        $orX = $qb->expr();

        $fields = $filters = array();
        
        foreach ($this->getNodeOrderBy() as $field) {
            $value = $this->_class->reflFields[$field]->getValue($entity);

            $andX = $qb->expr();
            foreach ($fields as $f => $v) {
                $andX->add($expr->eq('e.' . $f, $v));
            }
            $andX->add($expr->gt('e.' . $field, $value));
            $filters[] = $andX;
            $fields[$field] = $value;
        }
        foreach ($filters as $andX) {
            $orX->add($andX);
        }
        $qb->andWhere($orX);
        return $qb;
    }
    
    protected function _getLastPosInPath($path)
    {
        return base_convert(substr($path, -$this->getStepLength()), strlen($this->getAlphabet()), 10);
    }
    
    protected function _moveAddSibling($pos, $newPos, $newDepth, $target, &$siblings, &$queries, $oldPath = null, $moveBranch = false)
    {
        $target = $this->_getNode($target);
        if ($pos == 'last-sibling'
            || ($pos == 'right' && $target == $target->getLastSibling())
        ) {
            // last node
            $last = $target->getLastSibling();
            $newPath = $this->_incPath($last->getPath());
            if ($moveBranch) {
                $queries[] = $this->_getQbNewpathInBranches($oldPath, $newPath);
            }
            
        } else {
            if (null === $newPos) {
                $siblings = $this->_getSiblingQueryBuilder();
                $baseNum = $this->_getLastPosInPath($target->getPath());
                $expr = $siblings->expr();
                switch ($pos) {
                    case 'left':
                        $gte = $expr->gte('e.' . $this->getPathFieldName(), $target->getPath());
                        $siblings->andWhere($gte);
                        $newPos = $baseNum;
                        break;
                    
                    case 'right':
                        $gt = $expr->gt('e.' . $this->getPathFieldName(), $target->getPath());
                        $siblings->andWhere($gte);
                        $newPos = $baseNum + 1;
                        break;

                    case 'first-sibling':
                        $newPos = 1;
                        break;
                }
            }
            
            $newPath = $this->_getPath($target->getPath(), $newDepth, $newPos);

            $siblings->orderBy('e.' . $this->getPathFieldName(), 'DESC');
            $q = $siblings->getQuery();
            var_dump($q->getSql());
            $nodes = $q->getResult();
            foreach ($nodes as $node) {
                $node = $this->_getNode($node);
                $incPath = $this->_incPath($node->getPath());
                $queries[] = $this->_getQbNewpathInBranches($node->getPath(), $incPath);
                
                if ($moveBranch) {
                    if (0 === strpos($oldPath, $node->getPath())) {
                        // If moving to a parent, update oldpath since we just
                        // increased the path of the entire branch
                        $oldPath = $incPath . substr($oldPath, strlen($incPath));
                    }
                    if (0 === strpos($target->getPath(), $node->getPath())) {
                        $this->_class->reflFields[$this->getPathFieldName()]->setValue($target, $incPath . substr($target->getPath(), strlen($incPath)));
                    }
                }
            }
            
            if ($moveBranch) {
                $queries[] = $this->_getQbNewpathInBranches($oldPath, $newPath);
            }
        }
        return array($oldPath, $newPath);
    }

    protected function _getQbNewpathInBranches($oldPath, $newPath)
    {
        $qb = $this->_hm->getEntityManager()->createQueryBuilder()
            ->update($this->_class->name, 'e');

        $expr = $qb->expr();
        
        $sets = array();
        
        $substr = $expr->substring('e.' . $this->getPathFieldName(), strlen($oldPath)+1, $expr->length('e.' . $this->getPathFieldName()));
        $concat = $expr->concat($newPath, $substr);
        $pathSet = $qb->set('e.' . $this->getPathFieldName(), $concat);
        $sets[] = $pathSet;
        
        if (strlen($oldPath) != strlen($newPath)) {
            $len = $expr->length($concat);
            $quot = $expr->quot($len, $this->getStepLength());
            $depthSet = $expr->set('e.' . $this->getDepthFieldName(), $quot);
            $sets[] = $depthSet;
        }
        $qb->where($expr->like('e.' . $this->getPathFieldName(), $expr->literal($oldPath . '%')));
        return $qb;
    }
    
    protected function _getQbUpdateNumChild($path, $incdec='inc')
    {
        $qb = $this->_hm->getEntityManager()->createQueryBuilder()
            ->update($this->_class->name, 'e');
        
        $expr = $qb->expr();
        
        $dir = ($incdec == 'inc') ? '+' : '-';
        $rval = $this->getNumChildrenFieldName() . $dir . '1';
        $qb->set('e.' . $this->getNumChildrenFieldName(), 'e.' . $rval);
        $qb->where($expr->eq('e.' . $this->getPathFieldName(), $path));
        return $qb;
    }
}