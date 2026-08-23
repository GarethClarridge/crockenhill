<?php

declare(strict_types=1);

namespace App\Data;

readonly class OosSemanticFinding
{
    /**
     * @param  list<int>  $lineIds
     * @param  list<string>  $repairableFields
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $lineIds,
        public array $repairableFields = [],
        public ?string $groupId = null,
    ) {}

    /** @return array{code:string,message:string,line_ids:list<int>,repairable_fields:list<string>,group_id:?string} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'line_ids' => $this->lineIds,
            'repairable_fields' => $this->repairableFields,
            'group_id' => $this->groupId,
        ];
    }
}
