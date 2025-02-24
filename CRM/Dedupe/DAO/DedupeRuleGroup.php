<?php

/**
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 *
 * Generated from xml/schema/CRM/Dedupe/DedupeRuleGroup.xml
 * DO NOT EDIT.  Generated by CRM_Core_CodeGen
 * (GenCodeChecksum:7bcb6791c8bff662caa873128c101da8)
 */

/**
 * Database access object for the DedupeRuleGroup entity.
 */
class CRM_Dedupe_DAO_DedupeRuleGroup extends CRM_Core_DAO {
  const EXT = 'civicrm';
  const TABLE_ADDED = '1.8';

  /**
   * Static instance to hold the table name.
   *
   * @var string
   */
  public static $_tableName = 'civicrm_dedupe_rule_group';

  /**
   * Should CiviCRM log any modifications to this table in the civicrm_log table.
   *
   * @var bool
   */
  public static $_log = FALSE;

  /**
   * Unique dedupe rule group id
   *
   * @var int|string|null
   *   (SQL type: int unsigned)
   *   Note that values will be retrieved from the database as a string.
   */
  public $id;

  /**
   * The type of contacts this group applies to
   *
   * @var string|null
   *   (SQL type: varchar(12))
   *   Note that values will be retrieved from the database as a string.
   */
  public $contact_type;

  /**
   * The weight threshold the sum of the rule weights has to cross to consider two contacts the same
   *
   * @var int|string
   *   (SQL type: int)
   *   Note that values will be retrieved from the database as a string.
   */
  public $threshold;

  /**
   * Whether the rule should be used for cases where usage is Unsupervised, Supervised OR General(programatically)
   *
   * @var string
   *   (SQL type: varchar(12))
   *   Note that values will be retrieved from the database as a string.
   */
  public $used;

  /**
   * Name of the rule group
   *
   * @var string|null
   *   (SQL type: varchar(64))
   *   Note that values will be retrieved from the database as a string.
   */
  public $name;

  /**
   * Label of the rule group
   *
   * @var string|null
   *   (SQL type: varchar(255))
   *   Note that values will be retrieved from the database as a string.
   */
  public $title;

  /**
   * Is this a reserved rule - a rule group that has been optimized and cannot be changed by the admin
   *
   * @var bool|string
   *   (SQL type: tinyint)
   *   Note that values will be retrieved from the database as a string.
   */
  public $is_reserved;

  /**
   * Class constructor.
   */
  public function __construct() {
    $this->__table = 'civicrm_dedupe_rule_group';
    parent::__construct();
  }

  /**
   * Returns localized title of this entity.
   *
   * @param bool $plural
   *   Whether to return the plural version of the title.
   */
  public static function getEntityTitle($plural = FALSE) {
    return $plural ? ts('Dedupe Rule Groups') : ts('Dedupe Rule Group');
  }

  /**
   * Returns all the column names of this table
   *
   * @return array
   */
  public static function &fields() {
    if (!isset(Civi::$statics[__CLASS__]['fields'])) {
      Civi::$statics[__CLASS__]['fields'] = [
        'id' => [
          'name' => 'id',
          'type' => CRM_Utils_Type::T_INT,
          'title' => ts('Rule Group ID'),
          'description' => ts('Unique dedupe rule group id'),
          'required' => TRUE,
          'where' => 'civicrm_dedupe_rule_group.id',
          'table_name' => 'civicrm_dedupe_rule_group',
          'entity' => 'DedupeRuleGroup',
          'bao' => 'CRM_Dedupe_BAO_DedupeRuleGroup',
          'localizable' => 0,
          'html' => [
            'type' => 'Number',
          ],
          'readonly' => TRUE,
          'add' => '1.8',
        ],
        'contact_type' => [
          'name' => 'contact_type',
          'type' => CRM_Utils_Type::T_STRING,
          'title' => ts('Contact Type'),
          'description' => ts('The type of contacts this group applies to'),
          'maxlength' => 12,
          'size' => CRM_Utils_Type::TWELVE,
          'where' => 'civicrm_dedupe_rule_group.contact_type',
          'table_name' => 'civicrm_dedupe_rule_group',
          'entity' => 'DedupeRuleGroup',
          'bao' => 'CRM_Dedupe_BAO_DedupeRuleGroup',
          'localizable' => 0,
          'html' => [
            'type' => 'Select',
          ],
          'pseudoconstant' => [
            'table' => 'civicrm_contact_type',
            'keyColumn' => 'name',
            'labelColumn' => 'label',
            'condition' => 'parent_id IS NULL',
          ],
          'add' => '1.8',
        ],
        'threshold' => [
          'name' => 'threshold',
          'type' => CRM_Utils_Type::T_INT,
          'title' => ts('Threshold'),
          'description' => ts('The weight threshold the sum of the rule weights has to cross to consider two contacts the same'),
          'required' => TRUE,
          'where' => 'civicrm_dedupe_rule_group.threshold',
          'table_name' => 'civicrm_dedupe_rule_group',
          'entity' => 'DedupeRuleGroup',
          'bao' => 'CRM_Dedupe_BAO_DedupeRuleGroup',
          'localizable' => 0,
          'html' => [
            'type' => 'Text',
          ],
          'add' => '1.8',
        ],
        'used' => [
          'name' => 'used',
          'type' => CRM_Utils_Type::T_STRING,
          'title' => ts('Length'),
          'description' => ts('Whether the rule should be used for cases where usage is Unsupervised, Supervised OR General(programatically)'),
          'required' => TRUE,
          'maxlength' => 12,
          'size' => CRM_Utils_Type::TWELVE,
          'where' => 'civicrm_dedupe_rule_group.used',
          'table_name' => 'civicrm_dedupe_rule_group',
          'entity' => 'DedupeRuleGroup',
          'bao' => 'CRM_Dedupe_BAO_DedupeRuleGroup',
          'localizable' => 0,
          'html' => [
            'type' => 'Radio',
          ],
          'pseudoconstant' => [
            'callback' => 'CRM_Core_SelectValues::getDedupeRuleTypes',
          ],
          'add' => '4.3',
        ],
        'name' => [
          'name' => 'name',
          'type' => CRM_Utils_Type::T_STRING,
          'title' => ts('Name'),
          'description' => ts('Name of the rule group'),
          'maxlength' => 64,
          'size' => CRM_Utils_Type::BIG,
          'where' => 'civicrm_dedupe_rule_group.name',
          'table_name' => 'civicrm_dedupe_rule_group',
          'entity' => 'DedupeRuleGroup',
          'bao' => 'CRM_Dedupe_BAO_DedupeRuleGroup',
          'localizable' => 0,
          'add' => '2.1',
        ],
        'title' => [
          'name' => 'title',
          'type' => CRM_Utils_Type::T_STRING,
          'title' => ts('Title'),
          'description' => ts('Label of the rule group'),
          'maxlength' => 255,
          'size' => CRM_Utils_Type::HUGE,
          'where' => 'civicrm_dedupe_rule_group.title',
          'table_name' => 'civicrm_dedupe_rule_group',
          'entity' => 'DedupeRuleGroup',
          'bao' => 'CRM_Dedupe_BAO_DedupeRuleGroup',
          'localizable' => 0,
          'html' => [
            'type' => 'Text',
          ],
          'add' => '4.1',
        ],
        'is_reserved' => [
          'name' => 'is_reserved',
          'type' => CRM_Utils_Type::T_BOOLEAN,
          'title' => ts('Reserved?'),
          'description' => ts('Is this a reserved rule - a rule group that has been optimized and cannot be changed by the admin'),
          'required' => TRUE,
          'where' => 'civicrm_dedupe_rule_group.is_reserved',
          'default' => '0',
          'table_name' => 'civicrm_dedupe_rule_group',
          'entity' => 'DedupeRuleGroup',
          'bao' => 'CRM_Dedupe_BAO_DedupeRuleGroup',
          'localizable' => 0,
          'html' => [
            'type' => 'CheckBox',
          ],
          'add' => '4.1',
        ],
      ];
      CRM_Core_DAO_AllCoreTables::invoke(__CLASS__, 'fields_callback', Civi::$statics[__CLASS__]['fields']);
    }
    return Civi::$statics[__CLASS__]['fields'];
  }

  /**
   * Return a mapping from field-name to the corresponding key (as used in fields()).
   *
   * @return array
   *   Array(string $name => string $uniqueName).
   */
  public static function &fieldKeys() {
    if (!isset(Civi::$statics[__CLASS__]['fieldKeys'])) {
      Civi::$statics[__CLASS__]['fieldKeys'] = array_flip(CRM_Utils_Array::collect('name', self::fields()));
    }
    return Civi::$statics[__CLASS__]['fieldKeys'];
  }

  /**
   * Returns the names of this table
   *
   * @return string
   */
  public static function getTableName() {
    return self::$_tableName;
  }

  /**
   * Returns if this table needs to be logged
   *
   * @return bool
   */
  public function getLog() {
    return self::$_log;
  }

  /**
   * Returns the list of fields that can be imported
   *
   * @param bool $prefix
   *
   * @return array
   */
  public static function &import($prefix = FALSE) {
    $r = CRM_Core_DAO_AllCoreTables::getImports(__CLASS__, 'dedupe_rule_group', $prefix, []);
    return $r;
  }

  /**
   * Returns the list of fields that can be exported
   *
   * @param bool $prefix
   *
   * @return array
   */
  public static function &export($prefix = FALSE) {
    $r = CRM_Core_DAO_AllCoreTables::getExports(__CLASS__, 'dedupe_rule_group', $prefix, []);
    return $r;
  }

  /**
   * Returns the list of indices
   *
   * @param bool $localize
   *
   * @return array
   */
  public static function indices($localize = TRUE) {
    $indices = [];
    return ($localize && !empty($indices)) ? CRM_Core_DAO_AllCoreTables::multilingualize(__CLASS__, $indices) : $indices;
  }

}
