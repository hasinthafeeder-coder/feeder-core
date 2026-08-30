<?php

namespace Feeder\Core\Product\MarketLanguages;

use Feeder\Core\Contracts\Product\ProductMarketLanguageDefinition;

class SriLankaProductLanguages implements ProductMarketLanguageDefinition
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
                'code' => 'si',
                'label' => 'Sinhala',
                'tab_label' => 'සිංහල',
                'placeholder' => 'Sinhala description',
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
