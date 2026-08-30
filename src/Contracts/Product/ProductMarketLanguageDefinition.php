<?php

namespace Feeder\Core\Contracts\Product;

interface ProductMarketLanguageDefinition
{
    /**
     * @return list<array{
     *     code: string,
     *     label: string,
     *     tab_label: string,
     *     placeholder: string
     * }>
     */
    public function supportedLanguages(): array;
}
