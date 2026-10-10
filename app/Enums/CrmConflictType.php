<?php

namespace App\Enums;

enum CrmConflictType: string
{
    case MeridianMismatch = 'meridian_mismatch';
    case OrderMeridianMismatch = 'order_meridian_mismatch';
    case TypeChange = 'type_change';
    case AmbiguousDomain = 'ambiguous_domain';
    case DivergentDomains = 'divergent_domains';
    case InvalidSalesforceId = 'invalid_salesforce_id';
    case DuplicateContradictoryRow = 'duplicate_contradictory_row';
    case LinkConflict = 'link_conflict';
}
