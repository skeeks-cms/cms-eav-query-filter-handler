<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\eavqueryfilter;

use skeeks\cms\models\CmsContentElement;
use skeeks\cms\models\CmsContentElementProperty;
use skeeks\cms\models\CmsContentProperty;
use skeeks\cms\models\CmsContentPropertyEnum;
use skeeks\cms\relatedProperties\PropertyType;
use skeeks\yii2\queryfilter\IQueryFilterHandler;
use yii\base\DynamicModel;
use yii\base\InvalidConfigException;
use yii\data\DataProviderInterface;
use yii\db\ActiveQuery;
use yii\db\QueryInterface;
use yii\helpers\ArrayHelper;
use yii\widgets\ActiveForm;

/**
 * @author Semenov Alexander <semenov@skeeks.com>
 */
class CmsEavQueryFilterHandler extends DynamicModel implements IQueryFilterHandler
{
    /**
     * @var string
     */
    public $viewFile = '@skeeks/cms/eavqueryfilter/eav-filters';

    /**
     * @var ActiveQuery
     */
    protected $_baseQuery;

    /**
     * @var
     */
    public $elementIds;


    /**
     * @var CmsContentProperty[]
     */
    protected $_rps = [];

    /**
     * @return string
     */
    public function formName()
    {
        return 'eav';
    }

    /**
     * @return mixed|void
     * @throws InvalidConfigException
     */
    public function init()
    {
        parent::init();

        if (!$this->baseQuery) {
            throw new InvalidConfigException('Не указан базовый запрос');
        }
    }

    /**
     * @param QueryInterface $baseQuery
     * @return $this
     */
    public function setBaseQuery(QueryInterface $baseQuery)
    {
        $this->_baseQuery = clone $baseQuery;
        return $this;
    }

    /**
     * @return ActiveQuery
     */
    public function getBaseQuery()
    {
        return $this->_baseQuery;
    }


    /**
     * @return \skeeks\cms\query\CmsActiveQuery
     */
    public function getRPQuery()
    {
        return \skeeks\cms\models\CmsContentProperty::find()
            ->joinWith('cmsContentProperty2trees as map')
            ->joinWith('cmsContentProperty2contents as cmap')
            //->select([\skeeks\cms\models\CmsContentProperty::tableName().'.id', 'code'])
            ->andWhere([
                '!=',
                'property_type',
                \skeeks\cms\relatedProperties\PropertyType::CODE_FILE,
            ])
            ->andWhere([
                'NOT IN',
                'property_type',
                [
                    \skeeks\cms\relatedProperties\PropertyType::CODE_STRING
                    ,
                    \skeeks\cms\relatedProperties\PropertyType::CODE_TREE,
                ],
            ])
            /*->andWhere([
                'cmap.cms_content_id' => $shopFilters->content_id,
            ])*/
            //->andWhere(
            //[
            //'or',
            //['map.cms_tree_id' => $childIds]
            //['map.cms_tree_id' => null],
            //]
            //)
            ->groupBy('code')
            ->orderBy([\skeeks\cms\models\CmsContentProperty::tableName().'.priority' => SORT_ASC]);
    }

    /**
     * @param $query
     * @return $this
     */
    public function initRPByQuery($query)
    {
        if ($rps = $query->all()) {
            foreach ($rps as $rp) {
                $this->_rpInit($rp);
            }
        }

        /**
         * @var $query \yii\db\ActiveQuery
         */
        $query = clone $this->baseQuery;
        $query->with = [];
        $query->select(['cms_content_element.id as mainId', 'cms_content_element.id as id'])->indexBy('mainId');
        $ids = $query->asArray()->all();
        $this->elementIds = array_keys($ids);

        return $this;
    }

    /**
     * @param CmsContentProperty $rp
     */
    protected function _rpInit(CmsContentProperty $rp)
    {
        $name = $this->getAttributeName($rp->id);

        if (in_array($rp->property_type, [
            PropertyType::CODE_NUMBER,
            PropertyType::CODE_RANGE,
        ])) {
            $this->defineAttribute($this->getAttributeNameRangeFrom($rp->id), '');
            $this->defineAttribute($this->getAttributeNameRangeTo($rp->id), '');
            $this->addRule([
                $this->getAttributeNameRangeFrom($rp->id),
                $this->getAttributeNameRangeTo($rp->id),
            ], "safe");
        }
        $this->defineAttribute($name, "");
        $this->addRule([$name], "safe");
        $this->_rps[$name] = $rp;
    }

    protected $_relatedOptions = [];

    public function getOprionsByRp(CmsContentProperty $rp)
    {
        $options = [];

        /*$cacheKey = $this->cacheKey."_rp_options_{$rp->id}";
        $options = \Yii::$app->cache->get($cacheKey);
        if ($this->enableCache && $options) {
            return $options;
        }*/


        if (isset($this->_relatedOptions[$rp->id])) {
            return $this->_relatedOptions[$rp->id];
        }

        /*if ($this->onlyExistsFilters && !$this->elementIds) {
            return [];
        }*/

        if ($rp->property_type == \skeeks\cms\relatedProperties\PropertyType::CODE_ELEMENT) {
            $propertyType = $rp->handler;

            $options = \skeeks\cms\models\CmsContentElementProperty::find()->from([
                'map' => \skeeks\cms\models\CmsContentElementProperty::tableName(),
            ])
                ->leftJoin(['e' => CmsContentElement::tableName()], 'e.id = map.value_enum')
                ->select(['e.id as key', 'e.name as value', 'map.value_enum'])
                ->indexBy('key')
                ->groupBy('key')
                ->andWhere(['map.element_id' => $this->elementIds])
                ->andWhere(['map.property_id' => $rp->id])
                ->andWhere(['>', 'map.value_enum', 0])
                ->andWhere(['>', 'e.id', 0])
                ->andWhere(['is not', 'map.value_enum', null])
                ->asArray()
                ->all();

            if (!$options) {
                return [];
            }

            $options = \yii\helpers\ArrayHelper::map(
                $options, 'key', 'value'
            );

        } elseif ($rp->property_type == \skeeks\cms\relatedProperties\PropertyType::CODE_LIST) {

            $options = \skeeks\cms\models\CmsContentElementProperty::find()->from([
                'map' => \skeeks\cms\models\CmsContentElementProperty::tableName(),
            ])
                ->leftJoin(['enum' => CmsContentPropertyEnum::tableName()], 'enum.id = map.value_enum')
                //->leftJoin(['p' => CmsContentProperty::tableName()], 'p.id = enum.property_id')
                ->select(['enum.id as key', 'enum.value as value', 'map.value_enum'])
                ->indexBy('key')
                ->groupBy('key')
                ->andWhere(['map.element_id' => $this->elementIds])
                ->andWhere(['map.property_id' => $rp->id])
                ->andWhere(['>', 'map.value_enum', 0])
                ->andWhere(['>', 'enum.id', 0])
                ->andWhere(['is not', 'map.value_enum', null])
                ->asArray()
                ->all();


            /*$options =
                CmsContentPropertyEnum::find()
                    ->andWhere([CmsContentPropertyEnum::tableName() . '.id' => $this->elementIds])
                    ->joinWith('property as p')
                    ->joinWith('property.elementProperties as map')
                    ->andWhere(['p.id' => $property->id])
                    ->andWhere(['is not', 'map.value_enum', null])
                    ->select([
                        CmsContentPropertyEnum::tableName() . '.id',
                        CmsContentPropertyEnum::tableName() . '.value',
                        CmsContentPropertyEnum::tableName() . '.property_id'
                    ])
            ;*/

            if (!$options) {
                return [];
            }

            $options = \yii\helpers\ArrayHelper::map(
                $options, 'key', 'value'
            );

        } elseif ($rp->property_type == \skeeks\cms\relatedProperties\PropertyType::CODE_BOOL) {
            $availables = [];
            if ($this->elementIds) {
                $availables = \skeeks\cms\models\CmsContentElementProperty::find()
                    ->select(['value_bool'])
                    ->indexBy('value_bool')
                    ->groupBy('value_bool')
                    ->andWhere(['element_id' => $this->elementIds])
                    ->andWhere(['property_id' => $rp->id])
                    ->asArray()
                    ->all();

                $availables = array_keys($availables);
            }

            if ($this->onlyExistsFilters && !$availables) {
                return [];
            }

            $options = [];
            foreach ($availables as $value) {
                $labal = $value;
                if ($value == 0) {
                    $label = \Yii::t('skeeks/cms', 'No');
                } else {
                    if ($value == 1) {
                        $label = \Yii::t('skeeks/cms', 'Yes');
                    }
                }
                $options[$value] = $label;
            }
        }

        $this->_relatedOptions[$rp->id] = $options;

        /*if ($this->enableCache) {
            \Yii::$app->cache->set($cacheKey, $options);
        }*/

        return $options;
    }


    /**
     * @param $rp
     *
     * @return null
     */
    public function getMaxValue($rp)
    {
        /*$cacheKey = $this->cacheKey."_max_{$rp->id}";
        $value = \Yii::$app->cache->get($cacheKey);
        if (!$this->enableCache) {
            $value = null;
        }*/

        $value = 0;
        if (!$value) {
            if ($this->elementIds) {
                $value = \skeeks\cms\models\CmsContentElementProperty::find()
                    ->select(['value_enum'])
                    ->andWhere(['element_id' => $this->elementIds])
                    ->andWhere(['property_id' => $rp->id])
                    ->asArray()
                    ->orderBy(['value_enum' => SORT_DESC])
                    ->limit(1)
                    ->one();


                $value = (float)$value['value_enum'];
                /*$value = (float)$value['value_enum'];

                if ($this->enableCache) {
                    \Yii::$app->cache->set($cacheKey, $value);
                }*/
            }
        }

        return $value;
    }

    /**
     * @param $rp
     *
     * @return null
     */
    public function getMinValue($rp)
    {
        /*$cacheKey = $this->cacheKey."_min_{$rp->id}";
        $value = \Yii::$app->cache->get($cacheKey);
        if (!$this->enableCache) {
            $value = null;
        }*/

        $value = 0;
        if (!$value) {
            if ($this->elementIds) {
                $value = \skeeks\cms\models\CmsContentElementProperty::find()
                    ->select(['value_enum'])
                    ->andWhere(['element_id' => $this->elementIds])
                    ->andWhere(['property_id' => $rp->id])
                    ->asArray()
                    ->orderBy(['value_enum' => SORT_ASC])
                    ->limit(1)
                    ->one();

                $value = (float)$value['value_enum'];

                /*if ($this->enableCache) {
                    \Yii::$app->cache->set($cacheKey, $value);
                }*/
            }
        }

        return $value;
    }

    public $_prefixRange = "r";

    /**
     * @param $propertyCode
     * @return string
     */
    public function getAttributeNameRangeFrom($rp_id)
    {
        return $this->getAttributeName($rp_id).$this->_prefixRange."From";
    }
    /**
     * @param $propertyCode
     * @return string
     */
    public function getAttributeNameRangeTo($rp_id)
    {
        return $this->getAttributeName($rp_id).$this->_prefixRange."To";
    }

    public function getAttributeName($rp_id)
    {
        return 'f'.$rp_id;
    }

    public function applyToQuery(QueryInterface $activeQuery)
    {
        $classSearch = CmsContentElementProperty::class;
        $tableName = CmsContentElement::tableName();

        $elementIdsGlobal = [];
        $applyFilters = false;
        $unionQueries = [];

        foreach ($this->toArray() as $code => $value) {

            if ($property = $this->getRPByCode($code)) {

                if ($property->property_type == \skeeks\cms\relatedProperties\PropertyType::CODE_NUMBER) {
                    $elementIds = [];

                    $query = $classSearch::find()->select(['element_id as id'])->where([
                        "property_id" => $property->id,
                    ])->indexBy('element_id');

                    if ($fromValue = $this->{$this->getAttributeNameRangeFrom($property->id)}) {
                        $applyFilters = true;

                        $query->andWhere(['>=', 'value_num', (float)$fromValue]);
                    }

                    if ($toValue = $this->{$this->getAttributeNameRangeTo($property->id)}) {

                        $applyFilters = true;

                        $query->andWhere(['<=', 'value_num', (float)$toValue]);
                    }

                    if (!$fromValue && !$toValue) {
                        continue;
                    }

                    $unionQueries[] = $query;
                    //$elementIds = $query->all();

                } else {
                    if (!$value) {
                        continue;
                    }

                    $applyFilters = true;

                    if ($property->property_type == \skeeks\cms\relatedProperties\PropertyType::CODE_STRING) {
                        $query = $classSearch::find()->select(['element_id as id'])
                            ->where([
                                "property_id" => $property->id,
                            ])
                            ->andWhere([
                                'like',
                                'value',
                                $value,
                            ]);

                        /*->indexBy('element_id')
                        ->all();*/
                        $unionQueries[] = $query;

                    } else {
                        if ($property->property_type == \skeeks\cms\relatedProperties\PropertyType::CODE_BOOL) {
                            $query = $classSearch::find()->select(['element_id as id'])->where([
                                "value_bool"  => $value,
                                "property_id" => $property->id,
                            ]);
                            //print_r($query->createCommand()->rawSql);die;
                            //$elementIds = $query->indexBy('element_id')->all();
                            $unionQueries[] = $query;
                        } else {
                            if (in_array($property->property_type, [
                                \skeeks\cms\relatedProperties\PropertyType::CODE_ELEMENT
                                ,
                                \skeeks\cms\relatedProperties\PropertyType::CODE_LIST
                                ,
                                \skeeks\cms\relatedProperties\PropertyType::CODE_TREE,
                            ])) {
                                $query = $classSearch::find()->select(['element_id as id'])->where([
                                    "value_enum"  => $value,
                                    "property_id" => $property->id,
                                ]);
                                //print_r($query->createCommand()->rawSql);die;
                                //$elementIds = $query->indexBy('element_id')->all();
                                $unionQueries[] = $query;
                            } else {
                                $query = $classSearch::find()->select(['element_id as id'])->where([
                                    "value"       => $value,
                                    "property_id" => $property->id,
                                ]);
                                //print_r($query->createCommand()->rawSql);die;
                                //$elementIds = $query->indexBy('element_id')->all();
                                $unionQueries[] = $query;
                            }
                        }
                    }
                }


            }

        }

        if ($applyFilters) {
            if ($unionQueries) {
                /**
                 * @var $unionQuery ActiveQuery
                 */
                $lastQuery = null;
                $unionQuery = null;
                $unionQueriesStings = [];
                foreach ($unionQueries as $query) {
                    if ($lastQuery) {
                        $lastQuery->andWhere(['in', 'element_id', $query]);
                        $lastQuery = $query;
                        continue;
                    }

                    if ($unionQuery === null) {
                        $unionQuery = $query;
                    } else {
                        $unionQuery->andWhere(['in', 'element_id', $query]);
                        $lastQuery = $query;
                    }
                }
            }
            $activeQuery->andWhere(['in', $tableName.'.id', $unionQuery]);
        }

        return $this;
    }

    /**
     * @param DataProviderInterface $dataProvider
     * @return $this
     */
    public function applyToDataProvider(DataProviderInterface $dataProvider)
    {
        return $this->applyToQuery($dataProvider->query);
    }

    /**
     * @param ActiveForm $form
     * @return string
     */
    public function render(ActiveForm $form)
    {
        return \Yii::$app->view->render($this->viewFile, [
            'form'    => $form,
            'handler' => $this,
        ]);
    }


    /**
     * @param $name
     * @return CmsContentProperty
     */
    public function getRPByCode($name)
    {
        return ArrayHelper::getValue($this->_rps, $name);
    }

}
