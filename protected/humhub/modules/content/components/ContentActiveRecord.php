<?php

/**
 * @link https://www.humhub.org/
 * @copyright Copyright (c) 2017 HumHub GmbH & Co. KG
 * @license https://www.humhub.com/licences
 */

namespace humhub\modules\content\components;

use humhub\modules\content\models\Movable;
use humhub\modules\content\permissions\CreatePublicContent;
use humhub\modules\post\permissions\CreatePost;
use humhub\modules\topic\models\Topic;
use humhub\modules\topic\widgets\TopicLabel;
use humhub\modules\user\behaviors\Followable;
use humhub\modules\user\models\User;
use Yii;
use yii\base\Exception;
use humhub\modules\content\widgets\WallEntry;
use humhub\widgets\Label;
use humhub\libs\BasePermission;
use humhub\modules\content\permissions\ManageContent;
use humhub\components\ActiveRecord;
use humhub\modules\content\models\Content;
use humhub\modules\content\interfaces\ContentOwner;
use yii\base\InvalidConfigException;

/**
 * ContentActiveRecord is the base ActiveRecord [[\yii\db\ActiveRecord]] for Content.
 *
 * Each instance automatically belongs to a [[\humhub\modules\content\models\Content]] record which is accessible via the content attribute.
 * This relations will be automatically added/updated and is also available before this record is inserted.
 *
 * The Content record/model holds all neccessary informations/methods like:
 * - Related ContentContainer (must be set before save!)
 * - Visibility
 * - Meta information (created_at, created_by, ...)
 * - Wall handling, archiving, pinning, ...
 *
 * Before adding a new ContentActiveRecord instance, you need at least assign an ContentContainer.
 *
 * Example:
 *
 * ```php
 * $post = new Post();
 * $post->contentContainer = $space;
 * $post->contentVisibility = Content::VISIBILITY_PRIVATE; // optional
 * $post->contentTitle = "Hello world";
 * $post->message = "My first post in Humhub!";
 * $post->save();
 * ```
 *
 * Note: If the underlying Content record cannot be saved or validated an Exception will thrown.
 *
 * @property Content $content
 * @property User $createdBy
 * @property ContentContainerActiveRecord $contentContainer the container to which this content belongs to (automatically stored)
 * @property string $contentTitle the title of the content (automatically stored)
 * @property int $contentVisibility the visibility of this content (automatically stored)
 * @mixin Followable
 * @author Luke
 */
class ContentActiveRecord extends ActiveRecord implements ContentOwner, Movable
{

    /**
     * @see \humhub\modules\content\widgets\WallEntry
     * @var string the WallEntry widget class
     */
    public $wallEntryClass = "";

    /**
     * @var boolean should the originator automatically follows this content when saved.
     */
    public $autoFollow = true;

    /**
     * Note: this may not be implemented by legacy modules
     *
     * @var string related moduleId
     * @since 1.3
     */
    protected $moduleId;

    /**
     * The stream channel where this content should displayed.
     * Set to null when this content should not appear on streams.
     *
     * @since 1.2
     * @var string|null the stream channel
     */
    protected $streamChannel = 'default';

    /**
     * Holds an extra manage permission by providing one of the following
     *
     *  - BasePermission class string
     *  - Array of type ['class' => '...', 'callback' => '...']
     *  - Anonymous function
     *  - BasePermission instance
     *
     * @var string permission instance
     * @since 1.2.1
     */
    protected $managePermission = ManageContent::class;

    /**
     * If set to true this flag will prevent default ContentCreated Notifications and Activities.
     * This can be used e.g. for sub content entries, whose creation is not worth mentioning.
     *
     * @var bool
     * @since 1.2.3
     */
    public $silentContentCreation = false;

    /**
     * @var Content used to cache the content relation in order to avoid the relation to be overwritten in the insert process
     * @see https://github.com/humhub/humhub/issues/3110
     * @since 1.3
     */
    protected $initContent;

    /**
     * @var bool|string defines if the Movable behaviour of this ContentContainerActiveRecord type is active.
     * @see Content::move()
     * @since 1.3
     */
    protected $canMove = false;

    /**
     * ContentActiveRecord constructor accepts either an configuration array as first argument or an ContentContainerActiveRecord
     * and visibility settings.
     *
     * Use as follows:
     *
     * `$model = new MyContent(['myField' => 'value']);`
     *
     * or
     *
     * `$model = new MyContent($space1, Content::VISIBILITY_PUBLIC, ['myField' => 'value']);`
     *
     * or
     *
     * `$model = new MyContent($space1, ['myField' => 'value']);`
     *
     * @param array|ContentContainerActiveRecord $contentContainer either the configuration or contentcontainer
     * @param int|array $visibility
     * @param array $config
     * @throws Exception
     */
    public function __construct($contentContainer = [], $visibility = null, $config = [])
    {
        if (is_array($contentContainer)) {
            parent::__construct($contentContainer);
        } elseif ($contentContainer instanceof ContentContainerActiveRecord) {
            $this->content->setContainer($contentContainer);
            if (is_array($visibility)) {
                $config = $visibility;
            } elseif ($visibility !== null) {
                $this->content->visibility = $visibility;
            }
            parent::__construct($config);
        } else {
            parent::__construct([]);
        }
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        $this->attachBehavior('FollowableBehavior', Followable::class);
    }

    /**
     * @inheritdoc
     */
    public function __get($name)
    {
        /**
         * Ensure there is always a corresponding Content record
         * @see Content
         */
        if ($name == 'content') {
            $content = $this->initContent = (empty($this->initContent)) ? parent::__get('content') : $this->initContent;

            if (!$content) {
                $content = $this->initContent = new Content();
                $content->setPolymorphicRelation($this);
            }

            if (!$this->isRelationPopulated('content')) {
                $this->populateRelation('content', $content);
            }

            return $content;
        }

        return parent::__get($name);
    }

    /**
     * Returns the name of this type of content.
     * You need to override this method in your content implementation.
     *
     * @return string the name of the content
     */
    public function getContentName()
    {
        return static::class;
    }

    /**
     * Can be used to define an icon for this content type e.g.: 'fa-calendar'.
     * @return string
     */
    public function getIcon()
    {
        return null;
    }

    /**
     * Returns either Label widget instances or strings.
     *
     * Subclasses should call `paren::getLabels()` as follows:
     *
     * ```php
     * public function getLabels($labels = [], $includeContentName = true)
     * {
     *    return parent::getLabels([Label::info('someText')->sortOrder(5)]);
     * }
     * ```
     *
     * @param array $labels
     * @param bool $includeContentName
     * @return Label[]|\string[] content labels used for example in wallentrywidget
     * @throws \Exception
     */
    public function getLabels($labels = [], $includeContentName = true)
    {
        if ($this->content->isPinned()) {
            $labels[] = Label::danger(Yii::t('ContentModule.base', 'Pinned'))->icon('fa-map-pin')->sortOrder(100);
        }

        if ($this->content->isArchived()) {
            $labels[] = Label::warning(Yii::t('ContentModule.base', 'Archived'))->icon('fa-archive')->sortOrder(200);
        }

        if ($this->content->isPublic()) {
            $labels[] = Label::info(Yii::t('ContentModule.base', 'Public'))->icon('fa-globe')->sortOrder(300);
        }

        if ($includeContentName) {
            $labels[] = Label::defaultType($this->getContentName())->icon($this->getIcon())->sortOrder(400);
        }

        foreach (Topic::findByContent($this->content)->all() as $topic) {
            /** @var $topic Topic */
            $labels[] = TopicLabel::forTopic($topic);
        }

        return Label::sort($labels);
    }

    /**
     * Returns a description of this particular content.
     *
     * This will be used to create a text preview of the content record. (e.g. in Activities or Notifications)
     * You need to override this method in your content implementation.
     *
     * @return string description of this content
     */
    public function getContentDescription()
    {
        return "";
    }

    /**
     * Returns the $managePermission settings interpretable by an PermissionManager instance.
     *
     * @return null|object|string
     * @see ContentActiveRecord::$managePermission
     * @since 1.2.1
     */
    public function getManagePermission()
    {
        if (!$this->hasManagePermission()) {
            return null;
        }

        if (is_string($this->managePermission)) { // Simple Permission class specification
            return $this->managePermission;
        }

        if (is_array($this->managePermission)) {
            if (isset($this->managePermission['class'])) { // ['class' => '...', 'callback' => '...']
                $handler = $this->managePermission['class'] . '::' . $this->managePermission['callback'];
                return call_user_func($handler, $this);
            }
            // Simple Permission array specification
            return $this->managePermission;
        }

        if (is_callable($this->managePermission)) { // anonymous function
            return $this->managePermission($this);
        }

        if ($this->managePermission instanceof BasePermission) {
            return $this->managePermission;
        }

        return null;
    }

    /**
     * Determines weather or not this records has an additional managePermission set.
     *
     * @return boolean
     * @since 1.2.1
     */
    public function hasManagePermission()
    {
        return !empty($this->managePermission);
    }

    /**
     * Returns the wall output widget of this content.
     *
     * @param array $params optional parameters for WallEntryWidget
     * @return string
     * @throws \Exception
     */
    public function getWallOut($params = [])
    {
        if (!empty($this->wallEntryClass)) {
            $params['contentObject'] = $this;
            return call_user_func($this->wallEntryClass . '::widget', $params);
        }

        return "";
    }

    /**
     * Returns an instance of the assigned wall entry widget instance. This can be used to check matadata fields
     * of the related widget.
     *
     * @return null|WallEntry for this class by wallEntryClass property , null will be
     * returned if this wallEntryClass is empty
     */
    public function getWallEntryWidget()
    {
        if (empty($this->wallEntryClass)) {
            return null;
        }

        if (is_subclass_of($this->wallEntryClass, WallEntry::class)) {
            $class = $this->wallEntryClass;
            $widget = new $class;
            $widget->contentObject = $this;
            return $widget;
        }

        if ($this->wallEntryClass) {
            $class = $this->wallEntryClass;
            $widget = new $class;
            return $widget;
        }

        return null;
    }

    /**
     * @inheritdoc
     * @throws Exception
     */
    public function beforeSave($insert)
    {
        if (!$this->content->validate()) {
            throw new Exception(
                'Could not validate associated Content record! (' . $this->content->getErrorMessage() . ')'
            );
        }

        $this->content->setAttribute('stream_channel', $this->streamChannel);
        return parent::beforeSave($insert);
    }

    /**
     * @inheritdoc
     */
    public function afterSave($insert, $changedAttributes)
    {
        // Auto follow this content
        if ($this->autoFollow) {
            $this->follow($this->content->created_by);
        }

        // Set polymorphic relation
        if ($insert) {
            $this->populateRelation('content', $this->initContent);
            $this->content->object_model = static::getObjectModel();
            $this->content->object_id = $this->getPrimaryKey();
        }

        // Always save content
        $this->content->save();

        parent::afterSave($insert, $changedAttributes);
    }

    /**
     * Returns the class used in the polymorphic content relation.
     * By default this function will return the static class.
     *
     * Subclasses of existing content record classes may overwrite this function in order to remain the actual
     * base type as follows:
     *
     * ```
     * public static function getObjectModel() {
     *     return BaseType::class
     * }
     * ```
     *
     * This will force the usage of the `BaseType` class when creating, deleting or querying the content relation.
     * This is used in cases in which a subclass extends the a base record class without implementing a custom content type.
     *
     * @return string
     */
    public static function getObjectModel()
    {
        return static::class;
    }

    /**
     * @inheritdoc
     */
    public function afterDelete()
    {
        $content = Content::findOne(['object_id' => $this->getPrimaryKey(), 'object_model' => static::getObjectModel()]);
        if ($content !== null) {
            $content->delete();
        }

        parent::afterDelete();
    }

    /**
     * @return User the owner of this content record
     */
    public function getOwner()
    {
        return $this->content->createdBy;
    }

    /**
     * Checks if the given user or the current logged in user if no user was given, is the owner of this content
     * @param null $user
     * @return bool
     * @throws \Throwable
     * @since 1.3
     */
    public function isOwner($user = null)
    {
        if (!$user && !Yii::$app->user->isGuest) {
            $user = Yii::$app->user->getIdentity();
        } elseif (!$user) {
            return false;
        }

        return $this->content->created_by === $user->getId();

    }

    /**
     * Related Content model
     *
     * @return \yii\db\ActiveQuery|ActiveQueryContent
     */
    public function getContent()
    {
        return $this->hasOne(Content::class, ['object_id' => 'id'])
            ->andWhere(['content.object_model' => static::getObjectModel()]);
    }

    /**
     * Returns an ActiveQueryContent to find content.
     *
     * Results of this query will be static typed.
     * In order to force a specific type as query result this function needs to be overwritten as:
     *
     * ```
     * public static function find()
     * {
     *   return new ActiveQueryContent(MyBaseType::class);
     * }
     * ```
     *
     * {@inheritdoc}
     * @return ActiveQueryContent
     */
    public static function find()
    {
        return new ActiveQueryContent(static::class);
    }

    /**
     * Returns the id of the module related to this content type
     * Note: This may not be implemented by some legacy modules
     *
     * @since 1.3
     */
    public function getModuleId()
    {
        return $this->moduleId;
    }

    /**
     * Can be overwritten to define additional model specific checks.
     *
     * This function should also validate all existing sub-content entries to prevent data inconsistency.
     *
     * > Note: Default checks for the underlying content are automatically handled within the [[Content::canMove()]]
     * @param ContentContainerActiveRecord|null $container
     * @return bool|string
     * @throws InvalidConfigException
     */
    public function canMove(ContentContainerActiveRecord $container = null)
    {
        if (!$this->canMove) {
            return Yii::t('ContentModule.base', 'This content type can\'t be moved.');
        }

        if ($container && is_string($this->canMove) && is_subclass_of($this->canMove, BasePermission::class)) {
            $ownerPermissions = $container->getPermissionManager($this->content->createdBy);
            if (!$ownerPermissions->can($this->canMove)) {
                return Yii::t('ContentModule.base', 'The author of this content is not allowed to create this type of content within this space.');
            }
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    final public function move(ContentContainerActiveRecord $container = null, $force = false)
    {
        return $this->content->move($container, $force);
    }

    /**
     * This function is called after the content hast been moved and can be overwritten
     * in order to define model specific logic as moving sub-content or other related.
     * @param ContentContainerActiveRecord|null $container
     */
    public function afterMove(ContentContainerActiveRecord $container = null)
    {
    }

    public function setContentTitle($title)
    {
        $this->content->title = $title;
    }

    public function getContentTitle()
    {
        return $this->content->title;
    }

    public function setContentVisibility($visibilty)
    {
        $this->content->visibility = (int)$visibilty;
    }

    public function getContentVisibility()
    {
        return $this->content->visibility;
    }

    public function setContentContainer(ContentContainerActiveRecord $container)
    {
        $this->content->container = $container;
    }

    public function getContentContainer()
    {
        return $this->content->container;
    }

    public function validateContentVisibility($attribute, $params, $validator)
    {
        $visibility = (int)$this->$attribute;

        if (!in_array($visibility, [Content::VISIBILITY_PUBLIC, Content::VISIBILITY_PRIVATE, Content::VISIBILITY_OWNER])) {
            $this->addError('contentVisibility', 'Invalid visibility mode!');
        }

        if ($visibility === Content::VISIBILITY_PUBLIC && !$this->contentContainer->can(CreatePublicContent::class)) {
            $this->addError('contentVisibility', 'You are not allowed to create public content.');
        }
    }

    public function validateContentTitle($attribute, $params, $validator)
    {
        $container = $this->$attribute;
        // ToDo: Validate title
    }

    public function validateContentContainer($attribute, $params, $validator)
    {
        if (!($this->$attribute instanceof ContentContainerActiveRecord)) {
            $this->addError('contentContainer', 'Invalid content container given!');
            return;
        }

        /** @var ContentContainerActiveRecord $container */
        $container = $this->$attribute;

        // ToDo: Introduce Create permission?
        if (!$this->contentContainer->getPermissionManager()->can(new CreatePost())) {
            $this->addError('contentContainer', 'You are not allowed to create this content.');
            return;
        }
    }

}
