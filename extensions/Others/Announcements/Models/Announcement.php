<?php

namespace Paymenter\Extensions\Others\Announcements\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $table = 'ext_announcements';

    protected $fillable = [
        'title',
        'content',
        'description',
        'published_at',
        'is_active',
        'slug',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('published_at', '<=', now());
    }
}
