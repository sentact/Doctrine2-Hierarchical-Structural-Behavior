<?php

namespace DoctrineExtensions\Hierarchical\MaterializedPath;

use DoctrineExtensions\Hierarchical\AbstractDecorator,
    DoctrineExtensions\Hierarchical\Node,
    DoctrineExtensions\Hierarchical\HierarchicalException,
    Doctrine\ORM\NoResultException;


class MaterializedPathQueryFactory
{
    protected $_class;

    protected $_entity;

    protected $_hm;

    public function __construct($entity, $hm)
    {
        $this->_class = $hm->getEntityManager()->getClassMetadata(get_class($entity));
        $this->_entity = $entity;
        $this->_hm = $hm;
    }

    /**
     * Returns a basic QueryBuilder which will select the entire table ordered by path
     *
     * @param  $node 
     * @return void
     */
    public function getBaseQueryBuilder($node)
    {
        return $this->_hm->getEntityManager()->createQueryBuilder()
            ->select('e')
            ->from($this->_class->name, 'e')
            ->orderBy('e.' . $node->getPathFieldName());
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
            $value = $this->_class->reflFields[$field]->getValue($entity);

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

    public function getSiblingQueryBuilder($node)
    {
        $qb = $this->getBaseQueryBuilder($node);
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
}