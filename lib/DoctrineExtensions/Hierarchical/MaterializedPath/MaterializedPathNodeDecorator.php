<?php

namespace DoctrineExtensions\Hierarchical\MaterializedPath;

use DoctrineExtensions\Hierarchical\AbstractDecorator,
    DoctrineExtensions\Hierarchical\Node,
    DoctrineExtensions\Hierarchical\HierarchicalException,
    Doctrine\ORM\NoResultException;


class MaterializedPathNodeDecorator extends AbstractDecorator implements Node
{
    protected $_parent;

    protected $_children;

    
    public function __construct($entity, $hm)
    {
        parent::__construct($entity, $hm);
        $this->_qbFactory = new MaterializedPathQueryFactory($entity, $hm);
    }


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

    public function getId()
    {
        return $this->_getValue($this->getIdFieldName());
    }

    public function setParent($entity)
    {
        $this->_parent = $this->_getNode($entity);
        $this->_setValue($this->getParentIdFieldName(), $entity->getId());
    }

    /**
     * Adds this node as a root of the tree
     *
     * @return Node
     */
    public function addRoot()
    {
        if ($this->_getValue($this->getIdFieldName())) {
            throw new HierarchicalException('This entity is already initialized and can not be made a root node');
        }

        $em = $this->_hm->getEntityManager();
        $em->getConnection()->beginTransaction();
        try {
            $lastRoot = $this->getLastRootNode();
            if ($lastRoot && $this->getNodeOrderBy()) {
                return $lastRoot->addSibling('sorted-sibling', $this);
            }

            if ($lastRoot) {
                $newPath = $this->_incPath($lastRoot->getPath());
            } else {
                $newPath = $this->_getPath(null, 1, 1);
            }
            
            $this->_setValue($this->getDepthFieldName(), 1);
            $this->_setValue($this->getParentIdFieldName(), null);
            $this->_setValue($this->getPathFieldName(), $newPath);
            $em->persist($this->_entity);
            $em->flush();
            $em->getConnection()->commit();
        } catch (\Exception $e) {
            $em->getConnection()->rollback();
            $em->close();
            throw $e;
        }
    }

    /**
     * Returns all root nodes of the tree
     *
     * @return Collection
     **/
    public function getRootNodes()
    {
        return $this->_qbFactory
            ->getRootNodeQueryBuilder($this)
            ->getQuery()
            ->getResult();
    }

    /**
     * Retrns the first root node in the tree
     *
     * @return Node|null
     **/
    public function getFirstRootNode()
    {
        $qb = $this->_qbFactory
            ->getRootNodeQueryBuilder($this)
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
        $qb = $this->_qbFactory
            ->getRootNodeQueryBuilder($this)
            ->orderBy('e.' . $this->getPathFieldName(), 'DESC')
            ->setMaxResults(1);
        try {
            return $this->_getNode($qb->getQuery()->getSingleResult());
        } catch (NoResultException $e) {
            return null;
        }
    }

    /**
     * Returns the root node of the current node
     *
     * @return Node
     **/
    public function getRoot()
    {
        $qb = $this->_qbFactory->getBaseQueryBuilder($this);
        $expr = $qb->expr();
        $rootPath = substr($this->getPath(), 0, $this->getStepLength());
        $qb->where($expr->eq('e.' . $this->getPathFieldName(), $expr->literal($rootPath)));
        return $qb->getQuery()->getSingleResult();
    }

    /**
     * Is this node a root?
     *
     * @return boolean
     **/
    public function isRoot()
    {
        return ! $this->hasParent();
    }

    /**
     * Returns all of the node's siblings, including the node itself
     *
     * @return void
     */
    public function getSiblings()
    {
        $qb = $this->_qbFactory->getSiblingQueryBuilder($this);
        $q = $qb->getQuery();
        return $q->getResult();
    }

    public function getChildren()
    {
        if ($this->isLeaf()) {
            return array();
        }

        $qb = $this->_qbFactory->getBaseQueryBuilder($this);
        $expr = $qb->expr();
        $andX = $qb->andX();
        die;
        $andX->add($expr->eq('e.' . $this->getDepthFieldName(), $this->getDepth() + 1));
        $andX->add($expr->between('e.' . $this->_getChildrenPathInterval($this->getPath())));
        $qb->where($andX);
        $q = $qb->getQuery();
        return $q->getResult();
    }

    public function getNextSibling()
    {
        $qb = $this->_qbFactory->getSiblingQueryBuilder($this);

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
        $qb = $this->_qbFactory->getTreeQueryBuilder($this, $this);

        $expr = $qb->expr();
        $qb->andWhere($expr->not($expr->eq('e.' . $this->getIdFieldName(), $this->_getValue($this->getIdFieldName()))));
        $q = $qb->getQuery();
        return $q->getResult();
    }

    /**
     * Returns all descendants
     *
     * @return void
     **/
    public function getNumberOfDescendants()
    {
        $qb = $this->_qbFactory->getTreeQueryBuilder($this, $this);

        $expr = $qb->expr();
        $qb->select('COUNT(e)');
        $qb->andWhere($expr->not($expr->eq('e.' . $this->getIdFieldName(), $this->_getValue($this->getIdFieldName()))));
        return $qb->getQuery()->getSingleScalarResult();
    }

    public function getPrevSibling()
    {
        $qb = $this->_qbFactory->getSiblingQueryBuilder($this);

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
        $qb = $this->_qbFactory->getSiblingQueryBuilder($this);
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

    /**
     * The rightmost node's child
     *
     * @return void
     **/
    public function getLastChild()
    {

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
        $pos = $this->_processAddSiblingPos($pos);
        $node = $this->_getNode($entity);
        $entity = $node->unwrap();
        if ($entity === $this->_entity) {
            throw new \IllegalArgumentException('Node cannot be added as sibling of itself.');
        }

        $this->_class->reflFields[$this->getDepthFieldName()]->setValue($entity, $this->getDepth());
        
        if ($pos == 'sorted-sibling') {
            $siblingQb = $this->_qbFactory->getSortedPosQueryBuilder($this, $this->_qbFactory->getSiblingQueryBuilder($this), $node);
            
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
        $node->setParent($this->getParent());
        $queries = array();
        list($_, $newPath) = $this->_moveAddSibling($pos, $newPos, $this->getDepth(), $this, $siblingQb, $queries, null, false);
        
        $parentPath = $this->_getBasePath($newPath, $this->getDepth() - 1);
        if ($parentPath) {
            $queries[] = $this->_qbFactory->getUpdateNumChildrenQueryBuilder($this, $parentPath, 'inc');
        }
        
        $em = $this->_hm->getEntityManager();
        $em->getConnection()->beginTransaction();
        try {
            foreach ($queries as $qb) {
                $q = $qb->getQuery();
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
        return $this->getNumberOfChildren() > 0;
    }

    public function hasParent()
    {
        return !is_null($this->_getValue($this->getParentIdFieldName()));
    }

    public function isLeaf()
    {
        return $this->getNumberOfChildren() == 0;
    }

    /**
     * Returns the ancestors of the current node
     *
     * @return 
     **/
    public function getAncestors()
    {
        $paths = array();
        foreach (range(0, strlen($this->getPath()) - 1, $this->getStepLength()) as $pos) {
            if (0 == $pos) {
                continue;
            }
            $paths[] = substr($this->getPath(), 0, $pos);
        }

        $qb = $this->_qbFactory->getBaseQueryBuilder($this);
        $qb->where($qb->expr()->in('e.' . $this->getPathFieldName(), $paths));
        $qb->orderBy('e.' . $this->getDepthFieldName());
        return $qb->getQuery()->getResult();
    }

    /**
     * Returns the parent node of the current node object, caches result locally
     *
     * @param boolean $update True to update cached parent
     * @return Node
     **/
    public function getParent($update = false)
    {
        $depth = strlen($this->getPath()) / $this->getStepLength();
        if ($depth <= 1) {
            return;
        }
        if ($update) {
            unset($this->_parent);
        } else if ($this->_parent) {
            return $this->_parent;
        }
        
        $parentPath = $this->_getBasePath($this->getPath(), $this->getDepth() - 1);
        
        $qb = $this->_qbFactory->getBaseQueryBuilder($this);
        $qb->where($qb->expr()->eq('e.' . $this->getPathFieldName(), $parentPath));
        $parent = $qb->getSingleResult();
        $this->setParent($parent);
        return $parent;
    }

    /**
     * Moves the current node and all descendants to the position
     * relative to the target node
     *
     * @return void
     **/
    public function move($target, $pos = null)
    {
        $pos = $this->_processMovePos($pos);
        $oldPath = $this->getPath();
        
        // Initializes variables and if moving to a child, attempts to move to a sibling if possible
        // If it can't be done then we are adding the first child
        list($pos, $target, $newDepth, $siblings, $newPos) = $this->_fixMoveToChild($pos, $target, $target->getDepth());
        
        if ($target->isDescendantOf($this)) {
            throw new \InvalidArgumentException("Cannot move node to a descendant");
        }
        
        if ($oldPath == $target->getPath()
            && (
                ($pos == 'left')
                || (in_array($pos, array('right', 'last-sibling'))
                    && $target->getPath() == $target->getLastSibling()->getPath()
                )
                || ($pos == 'first-sibling' && $target->getPath() == $target->getFirstSibling()->getPath())
            )
        ) {
            // All special cases, not actually moving the node
            return;
        }

        if ('sorted-sibling' == $pos) {
            $siblings = $this->_getSortedPosQueryBuilder($this->_qbFactory->getSiblingQueryBuilder($target), $this);
            try {
                $sibling = $siblings->getQuery();
                $sibling->setMaxResults(1);
                $path = $this->_getNode($sibling->getSingleResult())->getPath();
                $newPos = $this->_getLastPosInPath($path);
            } catch (\Exception $e) {
                $newPos = null;
            }
            if (null === $newPos) {
                $pos = 'last-sibling';
            }
        }
        
        $queries = array();
        list($oldPath, $newPath) = $this->_moveAddSibling($pos, $newPos, $newDepth, $target, $siblings, $queries, $oldPath, true);
        
        $this->_getUpdatesAfterMove($oldPath, $newPath, $queries);
        
        $em = $this->_hm->getEntityManager();
        $em->getConnection()->beginTransaction();
        try {
            foreach ($queries as $qb) {
                $q = $qb->getQuery();
                $q->execute();
            }
            $em->getConnection()->commit();
        } catch (\Exception $e) {
            $em->getConnection()->rollback();
            $em->close();
            throw $e;
        }
    }

    /**
     * Returns the base path of another path up to the specified depth
     *
     * @param string $path 
     * @param string $depth 
     * @return string
     */
    protected function _getBasePath($path, $depth)
    {
        return PathHelper::getBasePath($this, $path, $depth);
    }

    /**
     * Builds a path given base path, depth, and integer value of the new step
     *
     * @param string $path 
     * @param integer $depth 
     * @param integer $newStep 
     * @return string
     */
    protected function _getPath($path, $depth, $newStep)
    {
        return PathHelper::getPath($this, $path, $depth, $newStep);
    }

    /**
     * Returns the path for the next sibling of a given node path
     *
     * @param string $path 
     * @return string
     */
    protected function _incPath($path)
    {
        return PathHelper::incPath($this, $path);
    }

    /**
     * Returns the integer value of the last step in a path
     *
     * @param string $path
     * @return integer
     **/
    protected function _getLastPosInPath($path)
    {
        return PathHelper::getLastPosInPath($this, $path);
    }

    /**
     * Returns the parent path for a given path
     *
     * @param string $path 
     * @return string
     */
    protected function _getParentPathFromPath($path)
    {
        return PathHelper::getParentPathFromPath($this, $path);
    }

    /**
     * The interval of all possible children paths for a node
     *
     * @param string $path 
     * @return array Index 0: start - Index 1: end
     */
    protected function _getChildrenPathInterval($path)
    {
        return PathHelper::getChildrenPathInterval($this, $path);
    }

    /**
     * Handles reordering of nodes and branches when adding and removing nodes
     *
     * @param string $pos 
     * @param string $newPos
     * @param string $newDepth 
     * @param string $target 
     * @param array $siblings 
     * @param array $queries Array of QueryBuilder objects for this transaction
     * @param string $oldPath 
     * @param string $moveBranch 
     * @return array Index 0 - old path, Index 1 - new path
     */
    protected function _moveAddSibling($pos, $newPos, $newDepth, $target, &$siblings, &$queries, $oldPath = null, $moveBranch = false)
    {
        $target = $this->_getNode($target);
        if ($pos == 'last-sibling'
            || ($pos == 'right' && $target == $target->getLastSibling())
        ) {
            // last node
            $last = $this->_getNode($target->getLastSibling());
            $newPath = $this->_incPath($last->getPath());
            if ($moveBranch) {
                $queries[] = $this->_qbFactory->getNewPathInBranchesQueryBuilder($this, $oldPath, $newPath);
            }
        } else {

            if (null === $newPos) {
                $baseNum = $this->_getLastPosInPath($target->getPath());
                $siblings = $this->_qbFactory->getSiblingQueryBuilder($this);
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

            $nodes = $q->getResult();
            foreach ($nodes as $node) {
                // Move the right siblings and their branches one step to the right
                $node = $this->_getNode($node);
                $nextPath = $this->_incPath($node->getPath());
                $queries[] = $this->_qbFactory->getNewPathInBranchesQueryBuilder($this, $node->getPath(), $nextPath);
                
                if ($moveBranch) {
                    if (0 === strpos($oldPath, $node->getPath())) {
                        // If moving to a parent, update oldpath since we just
                        // increased the path of the entire branch
                        $oldPath = $nextPath . substr($oldPath, strlen($nextPath));
                    }
                    if (0 === strpos($target->getPath(), $node->getPath())) {
                        // If target moved, update the target entity
                        $this->_class->reflFields[$this->getPathFieldName()]->setValue(
                            $target, $nextPath . substr($target->getPath(), strlen($nextPath))
                        );
                    }
                }
            }
            
            if ($moveBranch) {
                $queries[] = $this->_qbFactory->getNewPathInBranchesQueryBuilder($this, $oldPath, $newPath);
            }
        }
        return array($oldPath, $newPath);
    }

    /**
     * Update stuff when moving to a child
     *
     * @param string $pos
     * @param Node $target
     * @param integer $newDepth
     * @return array
     **/
    protected function _fixMoveToChild($pos, $target, $newDepth)
    {
        $newDepth = $target->getDepth();
        $siblings = array();
        
        if (in_array($pos, array('first-child', 'last-child', 'sorted-child'))) {
            $parent = $target;
            $newDepth++;
            if ($target->isLeaf()) {
                $newPos = 1;
                $pos = 'first-sibling';
                $siblings = array(); // TODO - hmm
            } else {
                $target = $target->getLastChild();
                $pos = str_replace('child', 'sibling', $pos);
            }
            $this->_class->reflValues[$this->getNumChildrenFieldName()]->setValue($parent, $parent->getNumberOfChildren() + 1);
        }
        return array($pos, $target, $newDepth, $siblings, $newPos);
    }

    /**
     * Updates the array of QueryBuilder Objects needed after moving nodes
     *
     * @param string $oldPath 
     * @param string $newPath 
     * @param array &$queries 
     * @return void
     */
    protected function _getUpdatesAfterMove($oldPath, $newPath, &$queries)
    {
        $oldParentPath = $this->_getParentPathFromPath($oldPath);
        $newParentPath = $this->_getParentPathFromPath($newPath);

        if (!$oldParentPath && $newParentPath
            || $oldParentPath && !$newParentPath
            || ($oldParentPath != $newParentPath)
        ) {
            // Node canged parent, update count
            if ($oldParentPath) {
                $queries[] = $this->_qbFactory->getUpdateNumChildrenQueryBuilder($this, $oldParentPath, 'dec');
            }
            
            if ($newParentPath) {
                $queries[] = $this->_qbFactory->getUpdateNumChildrenQueryBuilder($this, $newParentPath, 'inc');
            }
        }
    }


    public function _processAddSiblingPos($pos = null)
    {
        if (null === $pos) {
            if ($this->getNodeOrderBy()) {
                $pos = 'sorted-sibling';
            } else {
                $pos = 'last-sibling';
            }
        }
        $valid = array('first-sibling', 'left', 'right', 'last-sibling', 'sorted-sibling');
        if (! in_array($pos, $valid)) {
            throw new \InvalidArgumentException("Invalid relative position: {$pos}");
        }
        if ($this->getNodeOrderBy() && $pos != 'sorted-sibling') {
            throw new \InvalidArgumentException("Must use 'sorted-sibling' in addSibling when nodeOrderBy is enabled");
        }
        if ($pos == 'sorted-sibling' && !$this->getNodeOrderBy()) {
            throw new \InvalidArgumentException("getNodeOrderBy specifies no fields");
        }
        return $pos;
    }

    public function _processMovePos($pos = null)
    {
        if (null === $pos) {
            if ($this->getNodeOrderBy()) {
                $pos = 'sorted-sibling';
            } else {
                $pos = 'last-sibling';
            }
        }
        $valid = array('first-sibling', 'left', 'right', 'last-sibling', 'sorted-sibling', 'first-child', 'last-child', 'sorted-child');
        if (!in_array($pos, array('first-sibling'))) {
            throw new \InvalidArgumentException("Invalid relative position: {$pos}");
        }
        if ($this->getNodeOrderBy() 
            && $pos != 'sorted-sibling'
            && $pos != 'sorted-child'
        ) {
            throw new \InvalidArgumentException("Must use 'sorted-sibling' or 'sorted-child' in addSibling when nodeOrderBy is enabled");
        }
        if (($pos == 'sorted-sibling' || $pos == 'sorted-child') && !$this->getNodeOrderBy()) {
            throw new \InvalidArgumentException("getNodeOrderBy specifies no fields");
        }
        return $pos;
    }
}
