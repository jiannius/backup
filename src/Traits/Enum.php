<?php

namespace Jiannius\Backup\Traits;

use Illuminate\Support\Collection;
use Illuminate\Support\Stringable;

trait Enum
{
    /**
     * Resolve a case from its name or value (case-insensitive; passes through real cases).
     */
    public static function get($name): mixed
    {
        if (! is_string($name)) {
            return $name;
        }

        if ($value = static::tryFrom($name)) {
            return $value;
        }

        $name = str($name)->upper()->replace('-', '_')->replace(' ', '_')->toString();

        return static::all(false)->first(fn ($case): bool => $case->is($name));
    }

    /**
     * All cases as a collection (excludes TRASHED unless $filtered is false).
     */
    public static function all(bool $filtered = true): Collection
    {
        $cases = collect(static::cases());

        return $filtered
            ? $cases->filter(fn ($case): bool => $case->isNot('TRASHED'))->values()
            : $cases;
    }

    /**
     * The case as a select option array.
     *
     * @return array{value: string, label: string}
     */
    public function option(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
        ];
    }

    /**
     * The case as an array including a humanized label.
     *
     * @return array{value: string, label: string}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
        ];
    }

    /**
     * A humanized label derived from the case value.
     */
    public function label(): string
    {
        return str()->headline($this->value);
    }

    /**
     * Whether this case matches any of the given names or values.
     */
    public function is(): bool
    {
        $val = func_num_args() > 1 ? func_get_args() : (array) func_get_arg(0);

        return in_array($this->value, $val, true) || in_array($this->name, $val, true);
    }

    /**
     * Whether this case matches none of the given names or values.
     */
    public function isNot(...$val): bool
    {
        return ! $this->is(...$val);
    }

    /**
     * The case name (or value) as a Stringable.
     */
    public function str(string $type = 'name'): Stringable
    {
        return new Stringable($this->{$type});
    }

    /**
     * The case name (or value) in snake_case.
     */
    public function snake(string $type = 'name'): string
    {
        return (string) str($this->{$type})->lower()->snake();
    }

    /**
     * The case name (or value) as a URL slug.
     */
    public function slug(string $type = 'name'): string
    {
        return (string) str($this->{$type})->lower()->slug();
    }
}
