<?php

namespace Feeder\Core\Product\MarketLanguages;

use Feeder\Core\Contracts\Product\ProductMarketLanguageDefinition;

class FallbackProductLanguages implements ProductMarketLanguageDefinition
{
    public function supportedLanguages(): array
    {
        return [
            [
                'code' => 'en',
                'label' => 'English',
                'tab_label' => 'English',
                'placeholder' => 'English description',
            ],
        ];
    }
}
