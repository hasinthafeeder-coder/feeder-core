<?php

namespace Feeder\Core\Product\MarketLanguages;

use Feeder\Core\Contracts\Product\ProductMarketLanguageDefinition;

class MalaysiaProductLanguages implements ProductMarketLanguageDefinition
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
            [
                'code' => 'ms',
                'label' => 'Malay',
                'tab_label' => 'Bahasa Melayu',
                'placeholder' => 'Malay description',
            ],
            [
                'code' => 'ta',
                'label' => 'Tamil',
                'tab_label' => 'தமிழ்',
                'placeholder' => 'Tamil description',
            ],
        ];
    }
}
