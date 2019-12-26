<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\models;

use craft\base\Model;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\records\GqlSchema as GqlSchemaRecord;
use craft\validators\UniqueValidator;

/**
 * GraphQL schema class
 *
 * @property bool $isPublic Whether this is the public schema
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.3.0
 */
class GqlSchema extends Model
{
    // Constants
    // =========================================================================

    /**
     * The public schema token value.
     */
    const PUBLIC_TOKEN = '__PUBLIC__';

    // Properties
    // =========================================================================

    /**
     * @var int|null ID
     */
    public $id;

    /**
     * @var string Schema name
     */
    public $name;

    /**
     * @var string The access token
     */
    public $accessToken;

    /**
     * @var bool Is the schema enabled
     */
    public $enabled = true;

    /**
     * @var \DateTime|null Date expires
     */
    public $expiryDate;

    /**
     * @var \DateTime|null Date last used
     */
    public $lastUsed;

    /**
     * @var array The schema’s scope
     */
    public $scope = [];

    /**
     * @var \DateTime|null Date created
     */
    public $dateCreated;

    /**
     * @var string $uid
     */
    public $uid;

    /**
     * @var bool Whether this is a temporary schema
     * @since 3.3.12
     */
    public $isTemporary = false;

    /**
     * @var array Instance cache for the extracted scope pairs
     * @since 3.3.16
     */
    private $_cachedPairs = [];

    // Public Methods
    // =========================================================================

    public function __construct($config = [])
    {
        parent::__construct($config);

        if (is_string($this->scope)) {
            $this->scope = Json::decodeIfJson($this->scope);
        }
    }

    /**
     * @inheritdoc
     */
    public function datetimeAttributes(): array
    {
        $attributes = parent::datetimeAttributes();
        $attributes[] = 'expiryDate';
        $attributes[] = 'lastUsed';
        return $attributes;
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        $rules = parent::rules();
        $rules[] = [['name', 'accessToken'], 'required'];
        $rules[] = [
            ['name', 'accessToken'],
            UniqueValidator::class,
            'targetClass' => GqlSchemaRecord::class,
        ];

        return $rules;
    }

    /**
     * Use the translated group name as the string representation.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->name;
    }

    /**
     * Returns whether this is the public schema.
     *
     * @return bool
     */
    public function getIsPublic(): bool
    {
        return $this->accessToken === self::PUBLIC_TOKEN;
    }

    /**
     * Return whether this schema can perform an action
     *
     * @param $name
     * @return bool
     */
    public function has(string $name): bool
    {
        return is_array($this->scope) && in_array($name, $this->scope, true);
    }

    /**
     * Return all scope pairs.
     *
     * @return array
     * @since 3.3.16
     */
    public function getAllScopePairs(): array
    {
        if (!empty($this->_cachedPairs)) {
            return $this->_cachedPairs;
        }

        foreach ((array)$this->scope as $permission) {
            if (preg_match('/:([\w-]+)$/', $permission, $matches)) {
                $action = $matches[1];
                $permission = StringHelper::removeRight($permission, ':' . $action);
                $parts = explode('.', $permission);

                if (count($parts) === 2) {
                    $this->_cachedPairs[$action][$parts[0]][] = $parts[1];
                }
            }
        }

        return $this->_cachedPairs;
    }

    /**
     * Return all scope pairs.
     *
     * @param string $action
     * @return array
     * @since 3.3.16
     */
    public function getAllScopePairsForAction(string $action = 'read'): array
    {
        $pairs = $this->getAllScopePairs();

        return $pairs[$action] ?? [];
    }
}