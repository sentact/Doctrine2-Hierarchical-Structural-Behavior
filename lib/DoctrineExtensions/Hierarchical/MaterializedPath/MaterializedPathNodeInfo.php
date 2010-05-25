<?php

namespace DoctrineExtensions\Hierarchical\MaterializedPath;

interface MaterializedPathNodeInfo
{
    /**
     * Retrieves the Entity identifier field name
     *
     * @return string
     */
    public function getIdFieldName();

    /**
     * Retrieves the Entity path field name
     *
     * @return string
     */
    public function getPathFieldName();

    /**
     * Retrieves the Entity parent_id field name
     *
     * @return string
     */
    public function getParentIdFieldName();
    
    /**
     * Retrieves the Entity depth field name
     *
     * @return string
     */
    public function getDepthFieldName();

    /**
     * Retrieves the Entity numChildren field name
     *
     * @return string
     */
    public function getNumChildrenFieldName();

    /**
     * Returns the Node level order by
     * 
     * @return array Array of fieldNames, or empty array
     */
    public function getNodeOrderBy();

    /**
     * Returns the alphabet used for path generation
     *
     * Recommended Default: '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'
     * 
     * @return string
     */
    public function getAlphabet();

    /**
     * Returns the step length for path
     *
     * Recommended Default: 4
     * 
     * @return integer
     */
    public function getStepLength();
}