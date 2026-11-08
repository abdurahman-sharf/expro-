<?php

namespace App\Libraries\Documents;

/**
 * An extra field as it is defined: what it is called, what kind of value it holds, the options of a
 * list, whether a value is required and whether the value prints on the documents.
 */
final readonly class CustomFieldDefinition
{
    /**
     * @param list<string> $options
     */
    public function __construct(
        public int $id,
        public string $entity,
        public string $name,
        public CustomFieldType $type,
        public array $options,
        public bool $required,
        public bool $printable,
        public int $sortOrder,
        public bool $active,
    ) {
    }
}
