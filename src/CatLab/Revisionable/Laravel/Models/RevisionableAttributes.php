<?php

namespace CatLab\Revisionable\Laravel\Models;

use CatLab\Revisionable\Laravel\Contracts\RevisionableAttributes as RevisionableAttributesContract;
use Illuminate\Database\Eloquent\Model;

/**
 * Class RevisionableAttributes
 * @package CatLab\Revisionable\Model
 */
abstract class RevisionableAttributes extends Model implements RevisionableAttributesContract
{

}