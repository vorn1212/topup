<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Game extends Model
{
    /** logo, banner and provider are set by code, never mass assigned. */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'provider_category_id',
        'status',
        'sort_order',
        'meta_title',
        'meta_description',
        'validation_category_id',
        'provider_category_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => CatalogStatus::class,
            'sort_order' => 'integer',
        ];
    }

    public function fields(): HasMany
    {
        return $this->hasMany(GameField::class)->orderBy('sort_order')->orderBy('id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class)->orderBy('sort_order')->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CatalogStatus::Active->value);
    }

    /** Adds min_price: the cheapest active, available package. */
    public function scopeWithStartingPrice(Builder $query): Builder
    {
        return $query->withMin([
            'products as min_price' => fn ($q) => $q->where('status', 'active')->where('is_available', true),
        ], 'selling_price');
    }

    public function logoUrl(): ?string
    {
        return $this->logo ? asset('storage/'.$this->logo) : null;
    }

    public const PACKAGE_ICON_PATTERN = '#^games/package-icons/[A-Za-z0-9]{40}\.png$#';

    /** The default icon shown on every package of this game that has no icon of its own. */
    public function packageIconUrl(): ?string
    {
        return is_string($this->package_icon) && preg_match(self::PACKAGE_ICON_PATTERN, $this->package_icon) === 1
            ? asset('storage/'.$this->package_icon)
            : null;
    }

    public function bannerUrl(): ?string
    {
        return $this->banner ? asset('storage/'.$this->banner) : null;
    }

    public function seoTitle(): string
    {
        return filled($this->meta_title) ? $this->meta_title : "{$this->name} Top-Up";
    }

    public function seoDescription(): string
    {
        if (filled($this->meta_description)) {
            return $this->meta_description;
        }

        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $this->description)));

        return $text !== ''
            ? Str::limit($text, 155, '')
            : "Top up {$this->name} instantly. Pay with your wallet balance and get your items in minutes.";
    }

    public function seoImage(): ?string
    {
        return $this->bannerUrl() ?? $this->logoUrl();
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'game';
        $slug = $base;
        $i = 2;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
