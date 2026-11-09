<?php

namespace App\Libraries\Integrations;

enum IntegrationError: string
{
    case PermissionDenied         = 'permission_denied';
    case SettingInvalid           = 'setting_invalid';
    case PaymentMethodUnknown     = 'payment_method_unknown';
    case PaymentMethodProtected   = 'payment_method_protected';
    case PaymentMethodName        = 'payment_method_name';
    case PaymentMethodNameTaken   = 'payment_method_name_taken';
    case PaymentMethodDisabled    = 'payment_method_disabled';
    case TranslationKind          = 'translation_kind';
    case TranslationEntityMissing = 'translation_entity_missing';
    case TranslationLanguage      = 'translation_language';
    case TranslationName          = 'translation_name';
    case TranslationNote          = 'translation_note';
    case TranslationNotFound      = 'translation_not_found';
    case ApiKeyNotFound           = 'api_key_not_found';
    case ApiKeyName               = 'api_key_name';
    case ApiKeyScopes             = 'api_key_scopes';
    case ApiKeyBranch             = 'api_key_branch';
    case ApiKeyRate               = 'api_key_rate';
    case ApiKeyExpiry             = 'api_key_expiry';
    case ApiKeyRevoked            = 'api_key_revoked';
    case RuleNotFound             = 'rule_not_found';
    case RuleKind                 = 'rule_kind';
    case RuleName                 = 'rule_name';
    case RuleNameTaken            = 'rule_name_taken';
    case RuleThreshold            = 'rule_threshold';
    case RuleInUse                = 'rule_in_use';
    case NotificationNotFound     = 'notification_not_found';
}
