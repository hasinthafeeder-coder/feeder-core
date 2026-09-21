<?php

namespace Feeder\Core\Services\Courier\CredentialSchema;

final class CourierCredentialField
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $inputType,
        public readonly bool $secret,
        public readonly bool $required,
        public readonly bool $maskable = false,
        public readonly int $maskVisibleChars = 4,
        public readonly ?string $helpText = null,
    ) {
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     input_type: string,
     *     secret: bool,
     *     required: bool,
     *     maskable: bool,
     *     help_text: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'input_type' => $this->inputType,
            'secret' => $this->secret,
            'required' => $this->required,
            'maskable' => $this->maskable,
            'help_text' => $this->helpText,
        ];
    }
}
