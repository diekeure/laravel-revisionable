<?php

namespace CatLab\Revisionable\Laravel\Exceptions;

/**
 * Class InvalidChildTypeExceptions
 * @package CatLab\Revisionable\laravel\Exceptions\InvalidChildType
 */
class InvalidChildTypeExceptions extends RevisionableException
{
    /**
     * @param $action
     * @param $child
     * @return InvalidChildTypeExceptions
     */
    public static function create($action, $child)
    {
        return new self("Could not {$action} child: Model expected, but " . get_class($child) . " found.");
    }
}