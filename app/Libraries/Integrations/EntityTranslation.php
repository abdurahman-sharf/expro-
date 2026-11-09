<?php

namespace App\Libraries\Integrations;

final readonly class EntityTranslation
{
    public function __construct(
        public int $id,
        public TranslationKind $kind,
        public string $key,
        public string $language,
        public string $name,
        public string $addressNote,
        public string $entityName = '',
    ) {
    }
}
