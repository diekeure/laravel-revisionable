<?php

namespace CatLab\Revisionable\Laravel\Exceptions;

/**
 * Class InvalidAttributeTypeException
 * @package CatLab\Revisionable\laravel\Exceptions\InvalidChildType
 */
class InvalidAttributeTypeException extends RevisionableException
{
    /**
     * @param $action
     * @param $child
     * @return InvalidChildTypeExceptions
     */
    public static function create($action, $child)
    {
        return new self("Could not {$action} attributes: RevisionableAttributes expected, but " . get_class($child) . " found.");
    }
}