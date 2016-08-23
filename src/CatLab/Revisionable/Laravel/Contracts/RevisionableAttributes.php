<?php

namespace CatLab\Revisionable\Laravel\Contracts;

/**
 * Interface RevisionableAttributes
 * @package CatLab\Revisionable\Laravel\Contracts
 */
interface RevisionableAttributes
{
    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function author();
}