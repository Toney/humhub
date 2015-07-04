<?php

/**
 * @link https://www.humhub.org/
 * @copyright Copyright (c) 2015 HumHub GmbH & Co. KG
 * @license https://www.humhub.com/licences
 */

namespace humhub\modules\post\models;

use Yii;
use humhub\modules\content\components\ContentActiveRecord;

/**
 * This is the model class for table "post".
 *
 * @property integer $id
 * @property string $message_2trash
 * @property string $message
 * @property string $url
 * @property string $created_at
 * @property integer $created_by
 * @property string $updated_at
 * @property integer $updated_by
 */
class Post extends ContentActiveRecord
{

    public $wallEditRoute = '//post/post/edit';

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'post';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['message'], 'required'],
            [['message'], 'string'],
            [['created_at', 'updated_at'], 'safe'],
            [['created_by', 'updated_by'], 'integer'],
            [['url'], 'string', 'max' => 255]
        ];
    }

    public function beforeSave($insert)
    {
        // Prebuild Previews for URLs in Message
        \humhub\models\UrlOembed::preload($this->message);

        // Check if Post Contains an Url
        if (preg_match('/http(.*?)(\s|$)/i', $this->message)) {
            // Set Filter Flag
            $this->url = 1;
        }

        return parent::beforeSave($insert);
    }

    /**
     * Before Save Addons
     *
     * @return type
     */
    public function afterSave($insert, $changedAttributes)
    {

        parent::afterSave($insert, $changedAttributes);

        // Handle mentioned users
        \humhub\modules\user\models\Mentioning::parse($this, $this->message);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'message' => 'Message',
            'url' => 'Url',
            'created_at' => 'Created At',
            'created_by' => 'Created By',
            'updated_at' => 'Updated At',
            'updated_by' => 'Updated By',
        ];
    }

    public function getWallOut()
    {
        return \humhub\modules\post\widgets\Wall::widget(['post' => $this]);
    }

    /**
     * @inheritdoc
     */
    public function getContentTitle()
    {
        return Yii::t('PostModule.models_Post', 'Post');
    }

    /**
     * @inheritdoc
     */
    public function getContentPreview($maxLength = 0)
    {
        if ($maxLength == 0) {
            return $this->message;
        }

        return \humhub\libs\Helpers::truncateText($this->message, $maxLength);
    }

}
