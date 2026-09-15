<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['group', 'key', 'value', 'type'])]
class Setting extends Model
{
    public static function value(string $group, string $key, mixed $default = null): mixed
    {
        $row = static::query()->where('group', $group)->where('key', $key)->first();
        if (! $row) {
            return $default;
        }

        return match ($row->type) {
            'number' => is_numeric($row->value) ? (float) $row->value : $default,
            'boolean' => in_array((string) $row->value, ['1', 'true'], true),
            'json' => json_decode((string) $row->value, true) ?? $default,
            default => $row->value ?? $default,
        };
    }
}
