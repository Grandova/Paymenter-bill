<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Extension
{
    protected $table = 'extensions';

    public function newQuery($excludeDeleted = true)
    {
        return parent::newQuery($excludeDeleted)->where('type', 'server');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'server_id');
    }
}
