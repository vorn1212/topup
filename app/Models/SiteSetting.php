<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One branding setting (key and value). Read and written only through App\Services\SiteSettings.
 */
class SiteSetting extends Model
{
    protected $table = 'site_settings';

    protected $fillable = ['key', 'value'];
}
