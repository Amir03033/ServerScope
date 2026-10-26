<?php

namespace App\Services\Dependencies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Route;

/**
 * The monitored resource types that can take part in dependencies,
 * incidents and correlation, addressed as "type:id" keys that match the
 * morph map (server, domain, page, service).
 */
class ResourceCatalog
{
    public const TYPES = [
        'server' => 'Server',
        'domain' => 'Website / domein',
        'page' => 'Websitepagina',
        'service' => 'Service',
    ];

    public static function key(Model $resource): string
    {
        return $resource->getMorphClass().':'.$resource->getKey();
    }

    public static function supports(string $type): bool
    {
        return isset(self::TYPES[$type]) && Relation::getMorphedModel($type) !== null;
    }

    public static function find(string $type, int|string|null $id): ?Model
    {
        if ($id === null || ! self::supports($type)) {
            return null;
        }

        $class = Relation::getMorphedModel($type);
        $query = $class::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $query->withTrashed();
        }

        return $query->find($id);
    }

    /** Resolve a "type:id" key. */
    public static function fromKey(?string $key): ?Model
    {
        if ($key === null || ! preg_match('/^([a-z_]+):(\d+)$/', $key, $m)) {
            return null;
        }

        return self::find($m[1], (int) $m[2]);
    }

    public static function name(?Model $resource): string
    {
        if ($resource === null) {
            return '—';
        }

        if (method_exists($resource, 'displayName')) {
            return $resource->displayName();
        }

        return (string) ($resource->getAttribute('name') ?? self::key($resource));
    }

    public static function typeLabel(string $type): string
    {
        return self::TYPES[$type] ?? $type;
    }

    public static function url(?Model $resource): ?string
    {
        if ($resource === null) {
            return null;
        }

        return match ($resource->getMorphClass()) {
            'server' => route('servers.show', $resource),
            'domain' => route('domains.show', $resource),
            'page' => Route::has('pages.show') ? route('pages.show', $resource) : null,
            'service' => route('servers.show', $resource->getAttribute('server_id')),
            default => null,
        };
    }

    /**
     * Options for a resource picker, grouped by type.
     *
     * @return array<string, array<string, string>> type label => [key => name]
     */
    public static function options(): array
    {
        $options = [];

        foreach (array_keys(self::TYPES) as $type) {
            if (! self::supports($type)) {
                continue;
            }

            $class = Relation::getMorphedModel($type);
            $models = $class::query()->limit(1000)->get();

            foreach ($models->sortBy(fn (Model $m) => mb_strtolower(self::name($m))) as $model) {
                $options[self::TYPES[$type]][self::key($model)] = self::name($model);
            }
        }

        return $options;
    }
}
