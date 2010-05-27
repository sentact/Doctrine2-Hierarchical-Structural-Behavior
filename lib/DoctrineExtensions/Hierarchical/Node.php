<?php

namespace DoctrineExtensions\Hierarchical;


interface Node
{
    public function __construct($entity, $hm);

    public function getDepth();
    public function getSiblings();
    public function getChildren();
    public function getNumberOfChildren();

    public function getDescendants();
    public function getNumberOfDescendants();

    public function getFirstChild();
    public function getLastChild();

    public function getFirstSibling();
    public function getLastSibling();
    public function getPrevSibling();
    public function getNextSibling();

    public function isSiblingOf($entity);
    public function isChildOf($entity);
    public function isDescendantOf($entity);

    public function addChild($entity);
    public function addSibling($pos=null, $entity);
    //public function insertAsLastChildOf($entity);
    //public function insertAsFirstChildOf($entity);
    //public function insertAsNextSiblingOf($entity);
    //public function insertAsPrevSiblingOf($entity);

    public function getRoot();
    public function isRoot();
    public function isLeaf();

    public function getAncestors();
    public function getParent($update=false);

    public function move($target, $pos=null);
    //public function moveAsFirstChildOf($entity);
    //public function moveAsLastChildOf($entity);
    //public function moveAsNextSiblingOf($entity);
    //public function moveAsPrevSiblingOf($entity);

    //public function delete();

    public function hasChildren();
    public function hasParent();

    public function unwrap();
}
