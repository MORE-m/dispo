<?php

namespace App\Services\StandardOffer;

/**
 * BL-P4-03b / PO-BLP403B-1 / STD-001: ausdrückliche Feldklassifikation für
 * Calc→Vorlage. Keine Heuristik über Freitextinhalte.
 */
final class StandardOfferFieldClassification
{
    /**
     * Strukturelle Kundendaten – niemals in Draft/Publish/Persistenz der Vorlage.
     *
     * @var list<string>
     */
    public const STRIP_HEADER_KEYS = [
        'customer_name',
        'agency_name',
        'advisor_id',
        'advisor_name',
        'contact_name',
        'contact_email',
        'contact_phone',
        'ansprechpartner',
        'ansprechpartner_name',
        'ansprechpartner_email',
        'ansprechpartner_phone',
        'mediaberater',
        'target_budget_nn',
        'budget_strategy',
        'budget_proposal_id',
        'budget_proposal_status',
    ];

    /**
     * Freitext, der Kundenbezüge enthalten kann – nicht still in die Vorlage
     * übernehmen; sichtbare Prüfstufe ({@see proposal_review}).
     *
     * @var list<string>
     */
    public const FREE_TEXT_REVIEW_HEADER_KEYS = [
        'campaign',
        'product_title',
        'briefing',
    ];

    /**
     * System-Dyn-Felder ohne Kundenstammbezug, die Average-Vorlagen behalten dürfen.
     *
     * @var list<string>
     */
    public const TEMPLATE_SAFE_HEADER_DYNAMIC_KEYS = [
        'campaign_period',
        'period_open',
    ];

    /**
     * Positions-Dyn-Felder ohne Kundenstammbezug.
     *
     * @var list<string>
     */
    public const TEMPLATE_SAFE_POSITION_DYNAMIC_KEYS = [
        'period_open',
        'position_flight_period',
    ];

    /**
     * @var list<string>
     */
    public const TEMPLATE_SAFE_POSITION_STRUCTURAL_KEYS = [
        'client_key',
        'inventory_id',
        'advertising_medium_id',
        'schema_fingerprint',
        'spot_method',
        'calculation_method_key',
        'length_seconds',
        'component_calculation_strategy',
        'component_profile',
        'components',
        'total_spot_count',
        'needs_spot_redistribution',
        'position_discount_percent',
        'ae_percent',
        'plan_rows',
        'time_ranges',
        'planner_entries',
        'position_discounts',
        'pricing_settlement_mode',
        'fixed_price_nn',
        'effective_pay_factor_percent',
        'effective_total_discount_percent',
        'dynamic_field_values',
        'price_year',
    ];

    public function isStripHeaderKey(string $key): bool
    {
        return in_array($key, self::STRIP_HEADER_KEYS, true);
    }

    public function isFreeTextReviewHeaderKey(string $key): bool
    {
        return in_array($key, self::FREE_TEXT_REVIEW_HEADER_KEYS, true);
    }

    public function isTemplateSafeHeaderDynamicKey(string $key): bool
    {
        return in_array($key, self::TEMPLATE_SAFE_HEADER_DYNAMIC_KEYS, true);
    }

    public function isTemplateSafePositionDynamicKey(string $key): bool
    {
        return in_array($key, self::TEMPLATE_SAFE_POSITION_DYNAMIC_KEYS, true);
    }
}
