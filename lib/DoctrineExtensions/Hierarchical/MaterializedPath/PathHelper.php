<?php

namespace DoctrineExtensions\Hierarchical\MaterializedPath;

class PathHelper
{
    /**
     * Returns the base path of another path up to the specified depth
     *
     * @param Node $node
     * @param string $path 
     * @param string $depth 
     * @return string
     */
    public static function getBasePath($node, $path, $depth)
    {
        if (!$path) {
            return '';
        }
        return substr($path, 0, $depth * $node->getStepLength());
    }

    /**
     * Builds a path given base path, depth, and integer value of the new step
     *
     * @param Node $node
     * @param string $path 
     * @param integer $depth 
     * @param integer $newStep 
     * @return string
     */
    public static function getPath($node, $path, $depth, $newStep)
    {
        $parentPath = self::getBasePath($node, $path, $depth - 1);
        $key = strtoupper(self::int2str($newStep, strlen($node->getAlphabet())));
        $pad = str_repeat('0', $node->getStepLength() - strlen($key));
        return "{$parentPath}{$pad}{$key}";
    }

    /**
     * Returns the path for the next sibling of a given node path
     *
     * @param Node $node
     * @param string $path 
     * @return string
     */
    public static function incPath($node, $path)
    {
        $first = substr($path, 0, -$node->getStepLength());
        $last = substr($path, -$node->getStepLength());
        $newPos = self::str2int($last, strlen($node->getAlphabet())) + 1;
        $key = self::int2str($newPos, strlen($node->getAlphabet()));
        if (strlen($key) > $node->getStepLength()) {
            throw new \Exception("Path overflow from: '{$path}'");
        }
        $pad = str_repeat('0', $node->getStepLength() - strlen($key));
        return "{$first}{$pad}{$key}";
    }

    /**
     * Returns the integer value of the last step in a path
     *
     * @param Node $node
     * @param string $path
     * @return integer
     **/
    public static function getLastPosInPath($node, $path)
    {
        $last = substr($path, -$node->getStepLength());
        return self::str2int($last, strlen($node->getAlphabet()));
    }

    /**
     * Returns the parent path for a given path
     *
     * @param Node $node
     * @param string $path 
     * @return string
     */
    public static function getParentPathFromPath($node, $path)
    {
        if (!$path) {
            return '';
        }
        return substr($path, 0, strlen($path) - $node->getStepLength());
    }

    /**
     * The interval of all possible children paths for a node
     *
     * @param Node $node
     * @param string $path 
     * @return array Index 0: start - Index 1: end
     */
    public static function getChildrenPathInterval($node, $path)
    {
        return array(
            $path . str_repeat(substr($node->getAlphabet(), 0, 1), $node->getStepLength()),
            $path . str_repeat(substr($node->getAlphabet(), -1), $node->getStepLength())
        );
    }
    
    public static function str2int($path, $base = 36)
    {
        return base_convert($path, $base, 10);
    }
    
    public static function int2str($int, $base = 36)
    {
        return strtoupper(base_convert($int, 10, $base));
    }
}