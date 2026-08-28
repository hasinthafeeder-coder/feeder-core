<?php

namespace Feeder\Core\Support;

use Illuminate\Support\Facades\Schema;

class UserProfileSchema
{
    public static function hasIdentityDocumentColumns(): bool
    {
        return Schema::hasColumn('user_profiles', 'identity_document_type')
            && Schema::hasColumn('user_profiles', 'identity_document_number');
    }
}
