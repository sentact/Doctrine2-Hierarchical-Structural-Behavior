<?php

namespace DoctrineExtensions\Hierarchical;


class AbstractDecorator
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

    public function unwrap()
    {
        return $this->_entity;
    }

    public function getClassMetadata()
    {
        return $this->_class;
    }

    public function getHierarchicalManager()
    {
        return $this->_hm;
    }

    protected function _getNode($entity)
    {
        return $this->_hm->getNode($entity);
    }

    protected function _getValue($fieldName)
    {
        return $this->_class->reflFields[$fieldName]->getValue($this->_entity);
    }

    protected function _setValue($fieldName, $value)
    {
        $this->_class->reflFields[$fieldName]->setValue($this->_entity, $value);
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
        if (! in_array($pos, array('first-sibling', 'left', 'right', 'last-sibling', 'sorted-sibling'))) {
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
    // ...
}