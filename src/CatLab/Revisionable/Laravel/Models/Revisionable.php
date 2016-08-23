<?php

namespace CatLab\Revisionable\Laravel\Models;

use CatLab\Revisionable\Laravel\Contracts\RevisionableAttributes;
use CatLab\Revisionable\laravel\Exceptions;
use CatLab\Revisionable\Laravel\Exceptions\InvalidAttributeTypeException;
use CatLab\Revisionable\Laravel\Exceptions\InvalidChildTypeExceptions;
use CatLab\Revisionable\Laravel\Exceptions\SaveCalledException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Foundation\Auth\User;

/**
 * Class Revisionable
 * @package CatLab\Revisionable\Model
 */
abstract class Revisionable extends Model
{
    const REV_LATEST = 'latest';

    /**
     * @var string[]
     */
    private $revisionTags = [];

    /**
     * @var Model[]
     */
    private $attributeCache = [];

    /**
     * @var HasMany
     */
    private $childrenCache = [];

    /**
     * @var
     */
    private $alteredChildren = [];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    abstract function attributes() : HasMany;

    /**
     * Get the model that will keep track of the revision id.
     * @return Revisionable
     */
    protected abstract function getRootRevisionable() : Revisionable;

    /**
     * @param $revisionId
     * @return RevisionableAttributes
     */
    public function getRevisionedAttributes($revisionId)
    {
        if (!isset($this->attributeCache[$revisionId])) {
            $this->attributeCache[$revisionId] = $this->getRevisionedAttributesFresh($revisionId);
        }

        $result = $this->attributeCache[$revisionId];
        return $result;
    }

    /**
     * @param $revisionId
     * @return mixed
     */
    private function getRevisionedAttributesFresh($revisionId)
    {
        if ($revisionId === null) {
            $revisionId = 0;
        }

        if ($revisionId === self::REV_LATEST) {
            $attributes = $this
                ->attributes()
                ->orderBy('id', 'desc')
                ->first();
        } else {
            if (isset($this->revisionTags[$revisionId])) {
                $revisionId = $this->revisionTags[$revisionId];
            }

            $attributes = $this
                ->attributes()
                ->where('revision', '<=', $revisionId)
                ->orderBy('id', 'desc')->first();
        }

        if (!isset($attributes)) {
            $model = $this->attributes()->getModel();
            $attributes = new $model;

            $relationship = $this->attributes();

            $fk = $relationship->getPlainForeignKey();
            $attributes->$fk = $this->id;
        }

        return $attributes;
    }

    /**
     * We want all children that have bene created after the revision point, and there were not
     * removed yet OR were removed after the revision id.
     *
     * @param string $childProperty
     * @param $revisionId
     * @return Model[]
     */
    public function getRevisionedChildren($childProperty, $revisionId)
    {
        if (!isset($this->childrenCache[$revisionId])) {
            $this->childrenCache[$revisionId] = [];
        }

        if (!isset($this->childrenCache[$revisionId][$childProperty])) {
            /** @var HasMany $children */
            $children = call_user_func([ $this, $childProperty ]);
            $this->processRevisionedChildrenQueryBuilder($childProperty, $children, $revisionId);

            $this->childrenCache[$revisionId][$childProperty] = $children;
        }
        return $this->childrenCache[$revisionId][$childProperty];
    }

    /**
     * @param null $childProperty
     * @param null $revisionId
     */
    public function clearChildrenCache($childProperty = null, $revisionId = null)
    {
        if (isset($childProperty) && isset($revisionId)) {
            if (isset($this->childrenCache[$revisionId]) && isset($this->childrenCache[$revisionId][$childProperty])) {
                unset($this->childrenCache[$revisionId][$childProperty]);
            }
        } elseif (isset($childProperty)) {
            foreach ($this->childrenCache as $revId => $rev) {
                unset($this->childrenCache[$revId][$childProperty]);
            }
        } elseif (isset($revisionId)) {
            unset($this->childrenCache[$revisionId]);
        } else {
            $this->childrenCache = [];
        }
    }

    /**
     * @param $children
     * @param $childProperty
     * @param $revisionId
     */
    public function addRevisionedChildren($children, $childProperty, $revisionId)
    {
        if (!isset($this->alteredChildren)) {
            $this->alteredChildren[$childProperty] = [];
        }

        foreach ($children as $child) {
            $child->created_at_revision = $revisionId + 1;
            $this->alteredChildren[$childProperty][] = $child;
        }
    }

    /**
     * @param $children
     * @param $childProperty
     * @param $revisionId
     */
    public function editRevisionedChildren($children, $childProperty, $revisionId)
    {
        if (!isset($this->alteredChildren)) {
            $this->alteredChildren[$childProperty] = [];
        }

        foreach ($children as $child) {
            $this->alteredChildren[$childProperty][] = $child;
        }
    }

    /**
     * @param $children
     * @param $childProperty
     * @param $revisionId
     */
    public function removeRevisionedChildren($children, $childProperty, $revisionId)
    {
        if (!isset($this->alteredChildren)) {
            $this->alteredChildren[$childProperty] = [];
        }

        // Don't actually remove the childrens.
        // Instead just set removed_at_revision to the current revision.
        foreach ($children as $child) {
            $child->removed_at_revision = $revisionId + 1;
            $this->alteredChildren[$childProperty][] = $child;
        }
    }

    /**
     * @param $childProperty
     * @param HasOneOrMany $children
     * @param $revisionId
     */
    protected function processRevisionedChildrenQueryBuilder($childProperty, HasOneOrMany $children, $revisionId)
    {
        $children
            ->where('created_at_revision', '<=', $revisionId)
            ->where(function(Builder $query) use ($revisionId) {
                $query->whereNull('removed_at_revision')
                    ->orWhere('removed_at_revision', '>', $revisionId);
            });
    }

    /**
     * This is a bit strange, but this allows us to show a resource context variable by passing it through
     * this getter. See BookResourceDefinition to see what I mean.
     * @param $revisionId
     * @return mixed
     */
    public function getRevisionId($revisionId)
    {
        return $revisionId;
    }

    /**
     * @param $currentRevision
     * @param User $author
     * @return bool
     * @throws InvalidChildTypeExceptions
     */
    public function saveRevision($currentRevision, User $author = null)
    {
        $this->saveRevisionedRecursively($currentRevision, $author);
    }

    /**
     * @param array $options
     * @throws SaveCalledException
     * @return void
     */
    public function save(array $options = [])
    {
        throw new SaveCalledException("Model::save() was called on a revisionable model. Please call saveRevision");
    }

    /**
     * @param int $currentRevision
     * @return $this
     */
    private function increaseRevision(int $currentRevision)
    {
        $nextRevision = $currentRevision + 1;

        if (isset($this->revision)) {
            $this->setRevision($nextRevision);
        }

        return $this;
    }

    /**
     * @return int
     */
    public function getRevision()
    {
        return $this->getRootRevisionable()->revision;
    }

    /**
     * @param int $revision
     */
    private function setRevision(int $revision)
    {
        $this->getRootRevisionable()->setRevisionAndSave($revision);
    }

    /**
     * @param $revision
     */
    private function setRevisionAndSave($revision)
    {
        $this->revision = $revision;
        parent::save();
    }

    /**
     * @param int $currentRevision
     * @param User $author
     * @return bool
     * @throws InvalidChildTypeExceptions
     */
    private function saveRevisionedRecursively(int $currentRevision, User $author = null)
    {
        $changed = false;

        $nextRevision = $currentRevision + 1;
        $this->increaseRevision($currentRevision);

        // Also save this one.
        parent::save();

        $oldAttributes = $this->getRevisionedAttributes($currentRevision);
        if (! ($oldAttributes instanceof RevisionableAttributes)) {
            throw InvalidAttributeTypeException::create('save', $oldAttributes);
        }
        
        $dirtyAttributeFields = $oldAttributes->getDirty();

        $attributeFk = $this->attributes()->getPlainForeignKey();

        if (
            !$oldAttributes->exists() ||
            count($dirtyAttributeFields) > 0
        ) {
            /*
             * Make a new revision and push it to the attributes collection
             */

            /** @var RevisionableAttributes $newAttributes */
            $newAttributes = $oldAttributes->replicate();
            $newAttributes->$attributeFk = $this->id;

            // Make sure that this revision is the latest revision
            // (should only be triggered if a previous script crashed)
            $latestRevision = $this->getRevisionedAttributes(self::REV_LATEST);
            if ($latestRevision) {
                if ($latestRevision->revision && $latestRevision->revision > $nextRevision) {
                    $nextRevision = $latestRevision->revision + 1;
                }
            }

            $newAttributes->revision = $nextRevision;

            if ($author) {
                $newAttributes->author()->associate($author);
            } else {
                $newAttributes->author()->dissociate();
            }

            $newAttributes->save();

            $changed = $changed || true;
        }

        // Check for children
        if (isset($this->alteredChildren)) {
            $relationships = $this->alteredChildren;

            foreach ($relationships as $attributeName => $relationship) {
                foreach ($relationship as $child) {
                    /*
                     * For new entities we need to set the foreign key.
                     */

                    /** @var HasMany $relationshipQueryBuilder */
                    $relationshipQueryBuilder = call_user_func([ $this, $attributeName ]);

                    $fk = $relationshipQueryBuilder->getPlainForeignKey();
                    $child->$fk = $this->id;

                    if ($child instanceof Revisionable) {
                        $changed = $child->saveRevisionedRecursively($currentRevision, $author) || $changed;
                    } elseif ($child instanceof Model) {
                        $child->save();
                    } else {
                        throw InvalidChildTypeExceptions::create('save', $child);
                    }
                }
            }
        }

        $this->clearChildrenCache();

        if ($this->getRevision() !== $nextRevision) {
            $this->setRevision($nextRevision);
        }

        return $changed;
    }

    /**
     * @param $name
     * @param $revisionId
     * @return $this
     */
    protected function setRevisionTag($name, $revisionId)
    {
        $this->revisionTags[$name] = $revisionId;
        return $this;
    }
}