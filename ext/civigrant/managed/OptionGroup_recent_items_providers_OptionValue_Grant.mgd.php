<?php
use CRM_Grant_ExtensionUtil as E;

// Prevent errors during upgrades from < 5.53 that don't yet have the option group
$optionGroup = \Civi\Api4\OptionGroup::get(FALSE)
  ->addWhere('name', '=', 'recent_items_providers')
  ->selectRowCount()
  ->execute();
if (!$optionGroup->count()) {
  return [];
}

return [
  [
    'name' => 'OptionGroup_recent_items_providers_OptionValue_Grant',
    'entity' => 'OptionValue',
    'cleanup' => 'always',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'recent_items_providers',
        'label' => E::ts('Grants'),
        'value' => 'Grant',
        'name' => 'Grants',
        'grouping' => NULL,
        'filter' => 0,
        'is_default' => FALSE,
        'description' => NULL,
        'is_optgroup' => FALSE,
        'is_reserved' => FALSE,
        'is_active' => TRUE,
        'icon' => NULL,
        'color' => NULL,
        'component_id' => NULL,
        'domain_id' => NULL,
        'visibility_id' => NULL,
      ],
    ],
  ],
];
