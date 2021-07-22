<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\elements\db;

use Craft;
use craft\db\Query;
use craft\db\QueryAbortedException;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\UserGroup;
use yii\base\InvalidConfigException;
use yii\db\Connection;

/**
 * EntryQuery represents a SELECT SQL statement for entries in a way that is independent of DBMS.
 *
 * @property string|string[]|Section $section The handle(s) of the section(s) that resulting entries must belong to.
 * @property string|string[]|EntryType $type The handle(s) of the entry type(s) that resulting entries must have.
 * @property string|string[]|UserGroup $authorGroup The handle(s) of the user group(s) that resulting entries’ authors must belong to.
 * @method Entry[]|array all($db = null)
 * @method Entry|array|null one($db = null)
 * @method Entry|array|null nth(int $n, ?Connection $db = null)
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0.0
 * @doc-path entries.md
 * @supports-structure-params
 * @supports-site-params
 * @supports-title-param
 * @supports-slug-param
 * @supports-status-param
 * @supports-uri-param
 * @supports-draft-params
 * @supports-revision-params
 * @replace {element} entry
 * @replace {elements} entries
 * @replace {twig-method} craft.entries()
 * @replace {myElement} myEntry
 * @replace {element-class} \craft\elements\Entry
 */
class EntryQuery extends ElementQuery
{
    // General parameters
    // -------------------------------------------------------------------------

    /**
     * @var bool Whether to only return entries that the user has permission to edit.
     * @used-by editable()
     */
    public bool $editable = false;

    /**
     * @var int|int[]|null The section ID(s) that the resulting entries must be in.
     * ---
     * ```php
     * // fetch entries in the News section
     * $entries = \craft\elements\Entry::find()
     *     ->section('news')
     *     ->all();
     * ```
     * ```twig
     * {# fetch entries in the News section #}
     * {% set entries = craft.entries()
     *     .section('news')
     *     .all() %}
     * ```
     * @used-by section()
     * @used-by sectionId()
     */
    public $sectionId;

    /**
     * @var int|int[]|null The entry type ID(s) that the resulting entries must have.
     * ---
     * ```php{4}
     * // fetch Article entries in the News section
     * $entries = \craft\elements\Entry::find()
     *     ->section('news')
     *     ->type('article')
     *     ->all();
     * ```
     * ```twig{4}
     * {# fetch entries in the News section #}
     * {% set entries = craft.entries()
     *     .section('news')
     *     .type('article')
     *     .all() %}
     * ```
     * @used-by EntryQuery::type()
     * @used-by typeId()
     */
    public $typeId;

    /**
     * @var int|int[]|null The user ID(s) that the resulting entries’ authors must have.
     * @used-by authorId()
     */
    public $authorId;

    /**
     * @var int|int[]|null The user group ID(s) that the resulting entries’ authors must be in.
     * ---
     * ```php
     * // fetch entries authored by people in the Authors group
     * $entries = \craft\elements\Entry::find()
     *     ->authorGroup('authors')
     *     ->all();
     * ```
     * ```twig
     * {# fetch entries authored by people in the Authors group #}
     * {% set entries = craft.entries()
     *     .authorGroup('authors')
     *     .all() %}
     * ```
     * @used-by authorGroup()
     * @used-by authorGroupId()
     */
    public $authorGroupId;

    /**
     * @var mixed The Post Date that the resulting entries must have.
     * ---
     * ```php
     * // fetch entries written in 2018
     * $entries = \craft\elements\Entry::find()
     *     ->postDate(['and', '>= 2018-01-01', '< 2019-01-01'])
     *     ->all();
     * ```
     * ```twig
     * {# fetch entries written in 2018 #}
     * {% set entries = craft.entries()
     *     .postDate(['and', '>= 2018-01-01', '< 2019-01-01'])
     *     .all() %}
     * ```
     * @used-by postDate()
     */
    public $postDate;

    /**
     * @var string|array|\DateTime The maximum Post Date that resulting entries can have.
     * ---
     * ```php
     * // fetch entries written before 4/4/2018
     * $entries = \craft\elements\Entry::find()
     *     ->before('2018-04-04')
     *     ->all();
     * ```
     * ```twig
     * {# fetch entries written before 4/4/2018 #}
     * {% set entries = craft.entries()
     *     .before('2018-04-04')
     *     .all() %}
     * ```
     * @used-by before()
     */
    public $before;

    /**
     * @var string|array|\DateTime The minimum Post Date that resulting entries can have.
     * ---
     * ```php
     * // fetch entries written in the last 7 days
     * $entries = \craft\elements\Entry::find()
     *     ->after((new \DateTime())->modify('-7 days'))
     *     ->all();
     * ```
     * ```twig
     * {# fetch entries written in the last 7 days #}
     * {% set entries = craft.entries()
     *     .after(now|date_modify('-7 days'))
     *     .all() %}
     * ```
     * @used-by after()
     */
    public $after;

    /**
     * @var mixed The Expiry Date that the resulting entries must have.
     * @used-by expiryDate()
     */
    public $expiryDate;

    /**
     * @inheritdoc
     */
    protected array $defaultOrderBy = ['entries.postDate' => SORT_DESC];

    /**
     * @inheritdoc
     */
    public function __construct($elementType, array $config = [])
    {
        // Default status
        if (!isset($config['status'])) {
            $config['status'] = [
                Entry::STATUS_LIVE,
            ];
        }

        parent::__construct($elementType, $config);
    }

    /**
     * @inheritdoc
     */
    public function __set($name, $value)
    {
        switch ($name) {
            case 'section':
                $this->section($value);
                break;
            case 'type':
                $this->type($value);
                break;
            case 'authorGroup':
                $this->authorGroup($value);
                break;
            default:
                parent::__set($name, $value);
        }
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        if (!isset($this->withStructure)) {
            $this->withStructure = true;
        }

        parent::init();
    }

    /**
     * Sets the [[$editable]] property.
     *
     * @param bool $value The property value (defaults to true)
     * @return self self reference
     * @uses $editable
     */
    public function editable(bool $value = true): self
    {
        $this->editable = $value;
        return $this;
    }

    /**
     * Narrows the query results based on the sections the entries belong to.
     *
     * Possible values include:
     *
     * | Value | Fetches entries…
     * | - | -
     * | `'foo'` | in a section with a handle of `foo`.
     * | `'not foo'` | not in a section with a handle of `foo`.
     * | `['foo', 'bar']` | in a section with a handle of `foo` or `bar`.
     * | `['not', 'foo', 'bar']` | not in a section with a handle of `foo` or `bar`.
     * | a [[Section|Section]] object | in a section represented by the object.
     *
     * ---
     *
     * ```twig
     * {# Fetch entries in the Foo section #}
     * {% set {elements-var} = {twig-method}
     *     .section('foo')
     *     .all() %}
     * ```
     *
     * ```php
     * // Fetch entries in the Foo section
     * ${elements-var} = {php-method}
     *     ->section('foo')
     *     ->all();
     * ```
     *
     * @param string|string[]|Section|null $value The property value
     * @return self self reference
     * @uses $sectionId
     */
    public function section($value): self
    {
        // If the value is a section handle, swap it with the section
        if (is_string($value) && ($section = Craft::$app->getSections()->getSectionByHandle($value))) {
            $value = $section;
        }

        if ($value instanceof Section) {
            $this->sectionId = [$value->id];
            if ($value->structureId) {
                $this->structureId = $value->structureId;
            } else {
                $this->withStructure = false;
            }
        } else if ($value !== null) {
            $this->sectionId = (new Query())
                ->select(['id'])
                ->from([Table::SECTIONS])
                ->where(Db::parseParam('handle', $value))
                ->column();
        } else {
            $this->sectionId = null;
        }

        return $this;
    }

    /**
     * Narrows the query results based on the sections the entries belong to, per the sections’ IDs.
     *
     * Possible values include:
     *
     * | Value | Fetches entries…
     * | - | -
     * | `1` | in a section with an ID of 1.
     * | `'not 1'` | not in a section with an ID of 1.
     * | `[1, 2]` | in a section with an ID of 1 or 2.
     * | `['not', 1, 2]` | not in a section with an ID of 1 or 2.
     *
     * ---
     *
     * ```twig
     * {# Fetch entries in the section with an ID of 1 #}
     * {% set {elements-var} = {twig-method}
     *     .sectionId(1)
     *     .all() %}
     * ```
     *
     * ```php
     * // Fetch entries in the section with an ID of 1
     * ${elements-var} = {php-method}
     *     ->sectionId(1)
     *     ->all();
     * ```
     *
     * @param int|int[]|null $value The property value
     * @return self self reference
     * @uses $sectionId
     */
    public function sectionId($value): self
    {
        $this->sectionId = $value;
        return $this;
    }

    /**
     * Narrows the query results based on the entries’ entry types.
     *
     * Possible values include:
     *
     * | Value | Fetches entries…
     * | - | -
     * | `'foo'` | of a type with a handle of `foo`.
     * | `'not foo'` | not of a type with a handle of `foo`.
     * | `['foo', 'bar']` | of a type with a handle of `foo` or `bar`.
     * | `['not', 'foo', 'bar']` | not of a type with a handle of `foo` or `bar`.
     * | an [[EntryType|EntryType]] object | of a type represented by the object.
     *
     * ---
     *
     * ```twig
     * {# Fetch entries in the Foo section with a Bar entry type #}
     * {% set {elements-var} = {twig-method}
     *     .section('foo')
     *     .type('bar')
     *     .all() %}
     * ```
     *
     * ```php
     * // Fetch entries in the Foo section with a Bar entry type
     * ${elements-var} = {php-method}
     *     ->section('foo')
     *     ->type('bar')
     *     ->all();
     * ```
     *
     * @param string|string[]|EntryType|null $value The property value
     * @return self self reference
     * @uses $typeId
     */
    public function type($value): self
    {
        if ($value instanceof EntryType) {
            $this->typeId = [$value->id];
        } else if ($value !== null) {
            $this->typeId = (new Query())
                ->select(['id'])
                ->from([Table::ENTRYTYPES])
                ->where(Db::parseParam('handle', $value))
                ->column();
        } else {
            $this->typeId = null;
        }

        return $this;
    }

    /**
     * Narrows the query results based on the entries’ entry types, per the types’ IDs.
     *
     * Possible values include:
     *
     * | Value | Fetches entries…
     * | - | -
     * | `1` | of a type with an ID of 1.
     * | `'not 1'` | not of a type with an ID of 1.
     * | `[1, 2]` | of a type with an ID of 1 or 2.
     * | `['not', 1, 2]` | not of a type with an ID of 1 or 2.
     *
     * ---
     *
     * ```twig
     * {# Fetch entries of the entry type with an ID of 1 #}
     * {% set {elements-var} = {twig-method}
     *     .typeId(1)
     *     .all() %}
     * ```
     *
     * ```php
     * // Fetch entries of the entry type with an ID of 1
     * ${elements-var} = {php-method}
     *     ->typeId(1)
     *     ->all();
     * ```
     *
     * @param int|int[]|null $value The property value
     * @return self self reference
     * @uses $typeId
     */
    public function typeId($value): self
    {
        $this->typeId = $value;
        return $this;
    }

    /**
     * Narrows the query results based on the entries’ authors.
     *
     * Possible values include:
     *
     * | Value | Fetches entries…
     * | - | -
     * | `1` | with an author with an ID of 1.
     * | `'not 1'` | not with an author with an ID of 1.
     * | `[1, 2]` | with an author with an ID of 1 or 2.
     * | `['not', 1, 2]` | not with an author with an ID of 1 or 2.
     *
     * ---
     *
     * ```twig
     * {# Fetch entries with an author with an ID of 1 #}
     * {% set {elements-var} = {twig-method}
     *     .authorId(1)
     *     .all() %}
     * ```
     *
     * ```php
     * // Fetch entries with an author with an ID of 1
     * ${elements-var} = {php-method}
     *     ->authorId(1)
     *     ->all();
     * ```
     *
     * @param int|int[]|null $value The property value
     * @return self self reference
     * @uses $authorId
     */
    public function authorId($value): self
    {
        $this->authorId = $value;
        return $this;
    }

    /**
     * Narrows the query results based on the user group the entries’ authors belong to.
     *
     * Possible values include:
     *
     * | Value | Fetches entries…
     * | - | -
     * | `'foo'` | with an author in a group with a handle of `foo`.
     * | `'not foo'` | not with an author in a group with a handle of `foo`.
     * | `['foo', 'bar']` | with an author in a group with a handle of `foo` or `bar`.
     * | `['not', 'foo', 'bar']` | not with an author in a group with a handle of `foo` or `bar`.
     * | a [[UserGroup|UserGroup]] object | with an author in a group represented by the object.
     *
     * ---
     *
     * ```twig
     * {# Fetch entries with an author in the Foo user group #}
     * {% set {elements-var} = {twig-method}
     *     .authorGroup('foo')
     *     .all() %}
     * ```
     *
     * ```php
     * // Fetch entries with an author in the Foo user group
     * ${elements-var} = {php-method}
     *     ->authorGroup('foo')
     *     ->all();
     * ```
     *
     * @param string|string[]|UserGroup|null $value The property value
     * @return self self reference
     * @uses $authorGroupId
     */
    public function authorGroup($value): self
    {
        if ($value instanceof UserGroup) {
            $this->authorGroupId = $value->id;
        } else if ($value !== null) {
            $this->authorGroupId = (new Query())
                ->select(['id'])
                ->from([Table::USERGROUPS])
                ->where(Db::parseParam('handle', $value))
                ->column();
        } else {
            $this->authorGroupId = null;
        }

        return $this;
    }

    /**
     * Narrows the query results based on the user group the entries’ authors belong to, per the groups’ IDs.
     *
     * Possible values include:
     *
     * | Value | Fetches entries…
     * | - | -
     * | `1` | with an author in a group with an ID of 1.
     * | `'not 1'` | not with an author in a group with an ID of 1.
     * | `[1, 2]` | with an author in a group with an ID of 1 or 2.
     * | `['not', 1, 2]` | not with an author in a group with an ID of 1 or 2.
     *
     * ---
     *
     * ```twig
     * {# Fetch entries with an author in a group with an ID of 1 #}
     * {% set {elements-var} = {twig-method}
     *     .authorGroupId(1)
     *     .all() %}
     * ```
     *
     * ```php
     * // Fetch entries with an author in a group with an ID of 1
     * ${elements-var} = {php-method}
     *     ->authorGroupId(1)
     *     ->all();
     * ```
     *
     * @param int|int[]|null $value The property value
     * @return self self reference
     * @uses $authorGroupId
     */
    public function authorGroupId($value): self
    {
        $this->authorGroupId = $value;
        return $this;
    }

    /**
     * Narrows the query results based on the entries’ post dates.
     *
     * Possible values include:
     *
     * | Value | Fetches entries…
     * | - | -
     * | `'>= 2018-04-01'` | that were posted on or after 2018-04-01.
     * | `'< 2018-05-01'` | that were posted before 2018-05-01
     * | `['and', '>= 2018-04-04', '< 2018-05-01']` | that were posted between 2018-04-01 and 2018-05-01.
     *
     * ---
     *
     * ```twig
     * {# Fetch entries posted last month #}
     * {% set start = date('first day of last month')|atom %}
     * {% set end = date('first day of this month')|atom %}
     *
     * {% set {elements-var} = {twig-method}
     *     .postDate(['and', ">= #{start}", "< #{end}"])
     *     .all() %}
     * ```
     *
     * ```php
     * // Fetch entries posted last month
     * $start = (new \DateTime('first day of last month'))->format(\DateTime::ATOM);
     * $end = (new \DateTime('first day of this month'))->format(\DateTime::ATOM);
     *
     * ${elements-var} = {php-method}
     *     ->postDate(['and', ">= {$start}", "< {$end}"])
     *     ->all();
     * ```
     *
     * @param mixed $value The property value
     * @return self self reference
     * @uses $postDate
     */
    public function postDate($value): self
    {
        $this->postDate = $value;
        return $this;
    }

    /**
     * Narrows the query results to only entries that were posted before a certain date.
     *
     * Possible values include:
     *
     * | Value | Fetches entries…
     * | - | -
     * | `'2018-04-01'` | that were posted before 2018-04-01.
     * | a [[\DateTime|DateTime]] object | that were posted before the date represented by the object.
     *
     * ---
     *
     * ```twig
     * {# Fetch entries posted before this month #}
     * {% set firstDayOfMonth = date('first day of this month') %}
     *
     * {% set {elements-var} = {twig-method}
     *     .before(firstDayOfMonth)
     *     .all() %}
     * ```
     *
     * ```php
     * // Fetch entries posted before this month
     * $firstDayOfMonth = new \DateTime('first day of this month');
     *
     * ${elements-var} = {php-method}
     *     ->before($firstDayOfMonth)
     *     ->all();
     * ```
     *
     * @param string|\DateTime $value The property value
     * @return self self reference
     * @uses $before
     */
    public function before($value): self
    {
        $this->before = $value;
        return $this;
    }

    /**
     * Narrows the query results to only entries that were posted on or after a certain date.
     *
     * Possible values include:
     *
     * | Value | Fetches entries…
     * | - | -
     * | `'2018-04-01'` | that were posted after 2018-04-01.
     * | a [[\DateTime|DateTime]] object | that were posted after the date represented by the object.
     *
     * ---
     *
     * ```twig
     * {# Fetch entries posted this month #}
     * {% set firstDayOfMonth = date('first day of this month') %}
     *
     * {% set {elements-var} = {twig-method}
     *     .after(firstDayOfMonth)
     *     .all() %}
     * ```
     *
     * ```php
     * // Fetch entries posted this month
     * $firstDayOfMonth = new \DateTime('first day of this month');
     *
     * ${elements-var} = {php-method}
     *     ->after($firstDayOfMonth)
     *     ->all();
     * ```
     *
     * @param string|\DateTime $value The property value
     * @return self self reference
     * @uses $after
     */
    public function after($value): self
    {
        $this->after = $value;
        return $this;
    }

    /**
     * Narrows the query results based on the entries’ expiry dates.
     *
     * Possible values include:
     *
     * | Value | Fetches entries…
     * | - | -
     * | `':empty:'` | that don’t have an expiry date.
     * | `':notempty:'` | that have an expiry date.
     * | `'>= 2020-04-01'` | that will expire on or after 2020-04-01.
     * | `'< 2020-05-01'` | that will expire before 2020-05-01
     * | `['and', '>= 2020-04-04', '< 2020-05-01']` | that will expire between 2020-04-01 and 2020-05-01.
     *
     * ---
     *
     * ```twig
     * {# Fetch entries expiring this month #}
     * {% set nextMonth = date('first day of next month')|atom %}
     *
     * {% set {elements-var} = {twig-method}
     *     .expiryDate("< #{nextMonth}")
     *     .all() %}
     * ```
     *
     * ```php
     * // Fetch entries expiring this month
     * $nextMonth = (new \DateTime('first day of next month'))->format(\DateTime::ATOM);
     *
     * ${elements-var} = {php-method}
     *     ->expiryDate("< {$nextMonth}")
     *     ->all();
     * ```
     *
     * @param mixed $value The property value
     * @return self self reference
     * @uses $expiryDate
     */
    public function expiryDate($value): self
    {
        $this->expiryDate = $value;
        return $this;
    }

    /**
     * Narrows the query results based on the entries’ statuses.
     *
     * Possible values include:
     *
     * | Value | Fetches entries…
     * | - | -
     * | `'live'` _(default)_ | that are live.
     * | `'pending'` | that are pending (enabled with a Post Date in the future).
     * | `'expired'` | that are expired (enabled with an Expiry Date in the past).
     * | `'disabled'` | that are disabled.
     * | `['live', 'pending']` | that are live or pending.
     *
     * ---
     *
     * ```twig
     * {# Fetch disabled entries #}
     * {% set {elements-var} = {twig-method}
     *     .status('disabled')
     *     .all() %}
     * ```
     *
     * ```php
     * // Fetch disabled entries
     * ${elements-var} = {element-class}::find()
     *     ->status('disabled')
     *     ->all();
     * ```
     */
    public function status($value): self
    {
        return parent::status($value);
    }

    /**
     * @inheritdoc
     */
    protected function beforePrepare(): bool
    {
        $this->_normalizeSectionId();
        $this->_normalizeTypeId();

        // See if 'section', 'type', or 'authorGroup' were set to invalid handles
        if ($this->sectionId === [] || $this->typeId === [] || $this->authorGroupId === []) {
            return false;
        }

        $this->joinElementTable('entries');

        $this->query->select([
            'entries.sectionId',
            'entries.typeId',
            'entries.authorId',
            'entries.postDate',
            'entries.expiryDate',
        ]);

        if ($this->postDate) {
            $this->subQuery->andWhere(Db::parseDateParam('entries.postDate', $this->postDate));
        } else {
            if ($this->before) {
                $this->subQuery->andWhere(Db::parseDateParam('entries.postDate', $this->before, '<'));
            }
            if ($this->after) {
                $this->subQuery->andWhere(Db::parseDateParam('entries.postDate', $this->after, '>='));
            }
        }

        if ($this->expiryDate) {
            $this->subQuery->andWhere(Db::parseDateParam('entries.expiryDate', $this->expiryDate));
        }

        if ($this->typeId) {
            $this->subQuery->andWhere(['entries.typeId' => $this->typeId]);
        }

        if (Craft::$app->getEdition() === Craft::Pro) {
            if ($this->authorId) {
                $this->subQuery->andWhere(Db::parseNumericParam('entries.authorId', $this->authorId));
            }

            if ($this->authorGroupId) {
                $this->subQuery
                    ->innerJoin(['usergroups_users' => Table::USERGROUPS_USERS], '[[usergroups_users.userId]] = [[entries.authorId]]')
                    ->andWhere(Db::parseNumericParam('usergroups_users.groupId', $this->authorGroupId));
            }
        }

        $this->_applyEditableParam();
        $this->_applySectionIdParam();
        $this->_applyRefParam();

        return parent::beforePrepare();
    }

    /**
     * @inheritdoc
     */
    protected function statusCondition(string $status)
    {
        $currentTimeDb = Db::prepareDateForDb(new \DateTime());

        switch ($status) {
            case Entry::STATUS_LIVE:
                return [
                    'and',
                    [
                        'elements.enabled' => true,
                        'elements_sites.enabled' => true,
                    ],
                    ['<=', 'entries.postDate', $currentTimeDb],
                    [
                        'or',
                        ['entries.expiryDate' => null],
                        ['>', 'entries.expiryDate', $currentTimeDb],
                    ],
                ];
            case Entry::STATUS_PENDING:
                return [
                    'and',
                    [
                        'elements.enabled' => true,
                        'elements_sites.enabled' => true,
                    ],
                    ['>', 'entries.postDate', $currentTimeDb],
                ];
            case Entry::STATUS_EXPIRED:
                return [
                    'and',
                    [
                        'elements.enabled' => true,
                        'elements_sites.enabled' => true,
                    ],
                    ['not', ['entries.expiryDate' => null]],
                    ['<=', 'entries.expiryDate', $currentTimeDb],
                ];
            default:
                return parent::statusCondition($status);
        }
    }

    /**
     * Applies the 'editable' param to the query being prepared.
     *
     * @throws QueryAbortedException
     */
    private function _applyEditableParam(): void
    {
        if (!$this->editable) {
            return;
        }

        $user = Craft::$app->getUser()->getIdentity();

        if (!$user) {
            throw new QueryAbortedException();
        }

        // Limit the query to only the sections the user has permission to edit
        $this->subQuery->andWhere([
            'entries.sectionId' => Craft::$app->getSections()->getEditableSectionIds(),
        ]);

        // Enforce the editPeerEntries permissions for non-Single sections
        foreach (Craft::$app->getSections()->getEditableSections() as $section) {
            if ($section->type != Section::TYPE_SINGLE && !$user->can('editPeerEntries:' . $section->uid)) {
                $this->subQuery->andWhere([
                    'or',
                    ['not', ['entries.sectionId' => $section->id]],
                    ['entries.authorId' => $user->id],
                ]);
            }
        }
    }

    /**
     * Normalizes the typeId param to an array of IDs or null
     *
     * @throws InvalidConfigException
     */
    private function _normalizeTypeId(): void
    {
        if (empty($this->typeId)) {
            $this->typeId = is_array($this->typeId) ? [] : null;
        } else if (is_numeric($this->typeId)) {
            $this->typeId = [$this->typeId];
        } else if (!is_array($this->typeId) || !ArrayHelper::isNumeric($this->typeId)) {
            $this->typeId = (new Query())
                ->select(['id'])
                ->from([Table::ENTRYTYPES])
                ->where(Db::parseNumericParam('id', $this->typeId))
                ->column();
        }
    }

    /**
     * Applies the 'sectionId' param to the query being prepared.
     */
    private function _applySectionIdParam(): void
    {
        if ($this->sectionId) {
            $this->subQuery->andWhere(['entries.sectionId' => $this->sectionId]);

            // Should we set the structureId param?
            if (!isset($this->structureId) && count($this->sectionId) === 1) {
                $structureId = (new Query())
                    ->select(['structureId'])
                    ->from([Table::SECTIONS])
                    ->where(Db::parseNumericParam('id', $this->sectionId))
                    ->andWhere(['type' => Section::TYPE_STRUCTURE])
                    ->scalar();
                $this->structureId = (int)$structureId ?: false;
            }
        }
    }

    /**
     * Normalizes the sectionId param to an array of IDs or null
     */
    private function _normalizeSectionId(): void
    {
        if (empty($this->sectionId)) {
            $this->sectionId = is_array($this->sectionId) ? [] : null;
        } else if (is_numeric($this->sectionId)) {
            $this->sectionId = [$this->sectionId];
        } else if (!is_array($this->sectionId) || !ArrayHelper::isNumeric($this->sectionId)) {
            $this->sectionId = (new Query())
                ->select(['id'])
                ->from([Table::SECTIONS])
                ->where(Db::parseNumericParam('id', $this->sectionId))
                ->column();
        }
    }

    /**
     * Applies the 'ref' param to the query being prepared.
     */
    private function _applyRefParam(): void
    {
        if (!$this->ref) {
            return;
        }

        $refs = $this->ref;
        if (!is_array($refs)) {
            $refs = is_string($refs) ? StringHelper::split($refs) : [$refs];
        }

        $joinSections = false;
        $condition = ['or'];

        foreach ($refs as $ref) {
            $parts = array_filter(explode('/', $ref));

            if (!empty($parts)) {
                if (count($parts) == 1) {
                    $condition[] = Db::parseParam('elements_sites.slug', $parts[0]);
                } else {
                    $condition[] = [
                        'and',
                        Db::parseParam('sections.handle', $parts[0]),
                        Db::parseParam('elements_sites.slug', $parts[1]),
                    ];
                    $joinSections = true;
                }
            }
        }

        $this->subQuery->andWhere($condition);

        if ($joinSections) {
            $this->subQuery->innerJoin(['sections' => Table::SECTIONS], '[[sections.id]] = [[entries.sectionId]]');
        }
    }

    /**
     * @inheritdoc
     * @since 3.5.0
     */
    protected function cacheTags(): array
    {
        $tags = [];
        // If the type is set, go with that instead of the section
        if ($this->typeId) {
            foreach ($this->typeId as $typeId) {
                $tags[] = "entryType:$typeId";
            }
        } else if ($this->sectionId) {
            foreach ($this->sectionId as $sectionId) {
                $tags[] = "section:$sectionId";
            }
        }
        return $tags;
    }
}
