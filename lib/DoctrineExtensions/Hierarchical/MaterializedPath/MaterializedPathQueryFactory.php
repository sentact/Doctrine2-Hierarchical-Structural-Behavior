<?php

namespace DoctrineExtensions\Hierarchical\MaterializedPath;

use DoctrineExtensions\Hierarchical\AbstractDecorator,
    DoctrineExtensions\Hierarchical\Node,
    DoctrineExtensions\Hierarchical\HierarchicalException,
    DoctrineExtensions\Hierarchical\MaterializedPath\MaterializedPathManager,
    Doctrine\ORM\NoResultException,
    Doctrine\ORM\Mapping\ClassMetadata;


class MaterializedPathQueryFactory
{
    /**
     * @var DoctrineExtensions\Hierarchical\MaterializedPath\MaterializedPathManager
     */
    protected $hm;

    /**
     * @var Doctrine\ORM\Mapping\ClassMetadata
     */
    protected $classMetadata;

    /**
     * ReadOnly prototype entity grabbed from ClassMetadata
     *
     * @var string
     **/
    protected $prototype;

    public function __construct(MaterializedPathManager $hm, ClassMetadata $meta)
    {
        $this->hm = $hm;
        $this->classMetadata = $meta;
        $this->prototype = $meta->newInstance();
    }

    /**
     * Returns a basic QueryBuilder which will select the entire table ordered by path
     *
     * @param  $node
     * @return void
     */
    public function getBaseQueryBuilder()
    {
        return $this->hm->getEntityManager()
            ->createQueryBuilder()
            ->select('e')
            ->from($this->classMetadata->name, 'e')
            ->orderBy('e.' . $this->prototype->getPathFieldName());
    }

    /**
     * Returns a QueryBuilder of nodes that must be moved to the right
     *
     * @param string $siblingQueryBuilder
     * @param string $node
     * @return void
     * @author David Abdemoulaie
     */
    public function getSortedPosQueryBuilder($siblingQueryBuilder, $node)
    {
        $entity = $node->unwrap();

        $qb = $siblingQueryBuilder;
        $expr = $qb->expr();
        $orX = $qb->expr();

        $fields = $ands = array();

        foreach ($entity->getNodeOrderBy() as $field) {
            $value = $this->classMetadata->reflFields[$field]->getValue($entity);

            $andX = $qb->expr();
            foreach ($fields as $f => $v) {
                $andX->add($expr->eq('e.' . $f, $v));
            }
            $andX->add($expr->gt('e.' . $field, $value));
            $ands[] = $andX;
            $fields[$field] = $value;
        }
        foreach ($ands as $andX) {
            $orX->add($andX);
        }
        $qb->andWhere($orX);
        return $qb;
    }

    /**
     * Returns a QueryBuilder to grab the siblings of the given node
     *
     * @param string $node
     * @return void
     * @author David Abdemoulaie
     */
    public function getSiblingQueryBuilder($node)
    {
        $qb = $this->getBaseQueryBuilder();
        $expr = $qb->expr();
        $andX = $expr->andX();

        $andX->add($expr->eq('e.' . $node->getDepthFieldName(), $node->getDepth()));

        if ($node->getDepth() > 1) {
            $parentPath = PathHelper::getBasePath(
                $node,
                $node->getPath(),
                $node->getDepth() - 1
            );
            $pathInterval = PathHelper::getChildrenPathInterval($node, $parentPath);

            $andX->add($expr->between('e.' . $node->getPathFieldName(), $expr->literal($pathInterval[0]), $expr->literal($pathInterval[1])));
        }
        $qb->where($andX);
        return $qb;
    }

    /**
     * Returns a QueryBuilder to grab the children of this node
     *
     * @return void
     **/
    public function getChildrenQueryBuilder($node)
    {
        $qb = $this->getBaseQueryBuilder();
        $expr = $qb->expr();
        $andX = $expr->andX();

        $andX->add($expr->eq('e.' . $node->getDepthFieldName(), $node->getDepth() + 1));
        $interval = PathHelper::getChildrenPathInterval($node, $node->getPath());
        $andX->add($expr->between('e.' . $node->getPathFieldName(), $expr->literal($interval[0]), $expr->literal($interval[1])));
        $qb->where($andX);
        return $qb;
    }

    /**
     * Returns the query builder needed to update the numChildren value of a node
     *
     * @param Node $node
     * @param string $path
     * @param string $incDec inc|dec
     * @return QueryBuilder
     */
    public function getUpdateNumChildrenQueryBuilder($node, $path, $dir = 'inc')
    {
        $dir = ($dir == 'inc') ? '+' : '-';

        $qb = $this->hm->getEntityManager()
            ->createQueryBuilder()
            ->update($this->classMetadata->name, 'e');

        $rval = $node->getNumChildrenFieldName() . $dir . '1';
        $qb->set('e.' . $node->getNumChildrenFieldName(), 'e.' . $rval);
        $qb->where($qb->expr()->eq('e.' . $node->getPathFieldName(), $qb->expr()->literal($path)));
        return $qb;
    }


    public function getTreeQueryBuilder($node, $parent = null)
    {
        $qb = $this->getBaseQueryBuilder();
        if (null == $parent) {
            return $qb;
        }

        $expr = $qb->expr();
        $andX = $expr->andX();
        // TODO sets a value on parent
        if ($parent->isLeaf()) {
            $andX->add($expr->eq('e.' . $node->getIdFieldName(), $parent->getId()));
        } else {
            $andX->add($expr->like('e.' . $node->getPathFieldName(), $expr->literal($parent->getPath() . '%')));
            $andX->add($expr->gte('e.' . $node->getDepthFieldName(), $parent->getDepth()));
        }
        $qb->where($andX);
        return $qb;
    }

    /**
     * Returns a QueryBuilder object to update the depth of all nodes in a branch
     *
     * @param string $path
     * @return QueryBuilder
     **/
    public function getUpdateDepthInBranchQueryBuilder($node, $path)
    {
        $qb = $this->hm->getEntityManager()
            ->createQueryBuilder()
            ->update($this->classMetadata->name, 'e');

        $expr = $qb->expr();

        $rval = $expr->length('e.' . $node->getPathFieldName());
        $rval = $expr->quot($rval, $node->getStepLength());
        $qb->set('e.' . $node->getDepthFieldName(), $rval);

        $where = $qb->like('e.' . $node->getPathFieldName(), $expr->literal($path . '%'));
        $qb->where($where);
        return $qb;
    }


    /**
     * Returns the QueryBuilder necessary to move a branch
     *
     * @param string $oldPath
     * @param string $newPath
     * @return QueryBuilder
     */
    public function getNewPathInBranchesQueryBuilder($node, $oldPath, $newPath)
    {
        // TODO abstract to getBaseUpdateQb
        $qb = $this->hm->getEntityManager()
            ->createQueryBuilder()
            ->update($this->classMetadata->name, 'e');

        $expr = $qb->expr();

        $sets = array();

        $substr = $expr->substring('e.' . $node->getPathFieldName(), strlen($oldPath)+1);
        $concat = $expr->concat($expr->literal($newPath), $substr);
        $qb->set('e.' . $node->getPathFieldName(), $concat);

        if (strlen($oldPath) != strlen($newPath)) {
            // Depth change required
            $newLength = $expr->length($concat);
            $quot = $expr->quot($newLength, $node->getStepLength());
            $qb->set('e.' . $node->getDepthFieldName(), $quot);
        }
        $qb->where($expr->like('e.' . $node->getPathFieldName(), $expr->literal($oldPath . '%')));
        return $qb;
    }

    /**
     * Returns a QueryBuilder for all root nodes in tree
     *
     * @param Node $node
     * @return QueryBuilder
     **/
    public function getRootNodeQueryBuilder()
    {
        $qb = $this->getBaseQueryBuilder();
        $qb->where($qb->expr()->eq('e.' . $this->prototype->getDepthFieldName(), 1));
        return $qb;
    }
}
