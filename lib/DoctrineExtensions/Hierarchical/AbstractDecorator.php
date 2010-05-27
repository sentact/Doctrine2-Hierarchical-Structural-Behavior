<?php

namespace DoctrineExtensions\Hierarchical;


class AbstractDecorator
{
    protected $classMetadata;

    protected $entity;

    protected $hm;

    public function __construct($entity, $hm)
    {
        $this->classMetadata = $hm->getEntityManager()->getClassMetadata(get_class($entity));
        $this->entity = $entity;
        $this->hm = $hm;
    }

    public function unwrap()
    {
        return $this->entity;
    }

    public function getClassMetadata()
    {
        return $this->classMetadata;
    }

    public function getHierarchicalManager()
    {
        return $this->hm;
    }

    protected function _getNode($entity)
    {
        return $this->hm->getNode($entity);
    }

    public function getValue($fieldName)
    {
        return $this->classMetadata->reflFields[$fieldName]->getValue($this->entity);
    }

    public function setValue($fieldName, $value)
    {
        $this->classMetadata->reflFields[$fieldName]->setValue($this->entity, $value);
    }
    // ...
}