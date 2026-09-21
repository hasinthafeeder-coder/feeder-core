<?php

namespace Feeder\Core\Services\Courier\Fardar;

/**
 * Provider-aware Fardar Domestic create-parcel status → user-facing message map.
 *
 * Never includes credential values. Status 211 / 212 must stay non-sensitive.
 */
final class FardarStatusMessages
{
    /**
     * @var array<int, string>
     */
    private const MESSAGES = [
        200 => 'Parcel created successfully.',
        201 => 'Fardar rejected the request: inactive client.',
        202 => 'Fardar rejected the order ID.',
        203 => 'Fardar rejected the parcel weight.',
        204 => 'Fardar rejected the parcel description.',
        205 => 'Fardar rejected the recipient name.',
        206 => 'Fardar rejected contact number 1.',
        207 => 'Fardar rejected contact number 2.',
        208 => 'Fardar rejected the delivery address.',
        209 => 'Fardar rejected the recipient city.',
        210 => 'Fardar could not insert the parcel. Try again.',
        211 => 'Fardar rejected the API key.',
        212 => 'Fardar rejected the client credentials.',
        213 => 'Fardar rejected the exchange value.',
        214 => 'Fardar is in system maintenance mode.',
    ];

    public static function forStatus(int $status): string
    {
        return self::MESSAGES[$status]
            ?? 'Fardar rejected the parcel request (status '.$status.').';
    }

    public static function isSuccess(int $status): bool
    {
        return $status === 200;
    }
}
