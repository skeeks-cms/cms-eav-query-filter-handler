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
use yii\db\Expression;
use yii\db\Query;
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
     * @var array
     */
    public $openedPropertyIds = [];


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
            ->cmsSite()
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
        $query->select(['cms_content_element.id as id']);

        /*$ids = $query->asArray()->all();
        $this->elementIds = array_keys($ids);*/
        $this->elementIds = [];
        if ($ids = $query->column()) {
            if ($ids) {
                $string_ids = implode(",", $ids);
                $child_ids = CmsContentElement::find()->select(['id'])->where(new Expression("parent_content_element_id in ({$string_ids})"))->column();
                if ($child_ids) {
                    $ids = array_merge($ids, $child_ids);
                }

                $ids = implode(",", $ids);
                $this->elementIds = CmsContentElement::find()
                    ->andWhere(new Expression("id in ({$ids})"))
                    ->select(['id']);

            } else {
                $this->elementIds = [];
            }


            //$this->elementIds = $ids;
        }


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


    protected $_elementEnums = null;

    protected function _getElementEnums($property_id = null)
    {
        if ($this->_elementEnums === null) {

            $property_types = [];
            if ($this->_rps) {
                /**
                 * @var $rp CmsContentProperty
                 */
                foreach ($this->_rps as $rp) {
                    if ($rp->property_type == PropertyType::CODE_ELEMENT) {
                        $property_types[$rp->id] = $rp->id;
                    }
                }
            }

            $this->_elementEnums = [];

            /*$options = \skeeks\cms\models\CmsContentElementProperty::find()->from([
                'map' => \skeeks\cms\models\CmsContentElementProperty::tableName(),
            ])
                ->leftJoin(['e' => CmsContentElement::tableName()], 'e.id = map.value_element_id')
                ->select(['e.id as key', 'e.name as value', 'map.value_element_id', 'map.property_id'])
                ->indexBy('key')
                ->groupBy('key')
                ->andWhere(['map.element_id' => $this->elementIds])
                ->andWhere(['>', 'map.value_element_id', 0])
                ->andWhere(['>', 'e.id', 0])
                ->andWhere(['is not', 'map.value_element_id', null])
                ->andWhere(['map.property_id' => $property_types])
                ->orderBy(['value' => SORT_ASC])
                ->asArray()
                ->all();*/

            //print_R($options);die;
            $q = (new Query())
                ->select([
                    'key'              => 'cce.id',
                    'value_element_id' => 'ccep.value_element_id',
                    'value'            => 'cce.name',
                    'property_id'      => 'ccep.property_id',
                    //'total'      => new Expression("count(1)"),
                ])
                ->from([
                    'cce' => CmsContentElement::tableName(),
                ])
                ->join("INNER JOIN", ['ccep' => CmsContentElementProperty::tableName()], 'ccep.value_element_id = cce.id') // ["ccep.value_element_id" => new Expression('cce.id')]
                /*->join("INNER JOIN", [
                    'ccep' => CmsContentElementProperty::find()
                            ->select(['value_element_id', 'property_id', 'value_string', 'element_id'])
                            ->where(['is not', CmsContentElementProperty::tableName() . '.value_element_id', null])
                            ->andWhere(['in', CmsContentElementProperty::tableName() . '.property_id', $property_types])
                ], 'ccep.value_element_id = cce.id')*/
                ->join("INNER JOIN", ['product' => CmsContentElement::find()->select(['id'])->where(['in', CmsContentElement::tableName().".id", $this->elementIds])],
                    'product.id = ccep.element_id') //["product.id" => new Expression('ccep.element_id')]
                ->andWhere(['is not', 'ccep.value_element_id', null])
                ->andWhere(['in', 'ccep.property_id', $property_types])
                ->groupBy(['cce.id'])
                ->orderBy(['cce.name' => SORT_ASC])

                /*->join("INNER JOIN", [
                    'ccep' => CmsContentElementProperty::find()
                            ->select(['value_element_id', 'property_id', 'value_string'])
                            ->where(['is not', CmsContentElementProperty::tableName() . '.value_element_id', null])
                            ->andWhere(['in', CmsContentElementProperty::tableName() . '.element_id', $this->elementIds])
                            ->andWhere(['in', CmsContentElementProperty::tableName() . '.property_id', $property_types])
                ], 'ccep.value_element_id = cce.id')
                ->groupBy(['cce.id'])
                ->orderBy(['ccep.value_string' => SORT_ASC])*/

                //->join("INNER JOIN", ['product' => CmsContentElement::tableName()], 'product.id = ccep.element_id') //["product.id" => new Expression('ccep.element_id')]
                //->join("INNER JOIN", ['product' => CmsContentElement::find()->select(['id'])->where(['in', CmsContentElement::tableName() . ".id", $this->elementIds])], 'product.id = ccep.element_id') //["product.id" => new Expression('ccep.element_id')]
                //->andWhere(['is not', 'ccep.value_element_id', null])
                //->andWhere(['in', 'ccep.property_id', $property_types])

            ;

            /*$q = (new Query())
                ->select([
                    'key'              => 'cce.id',
                    'value_element_id' => 'ccep.value_element_id',
                    'value'            => 'cce.name',
                    'property_id'      => 'ccep.property_id',
                    //'total'      => new Expression("count(1)"),
                ])
                ->from([
                    'ccep' => CmsContentElementProperty::tableName(),
                ])
                ->join("INNER JOIN", ['cce' => CmsContentElement::tableName()], 'ccep.value_element_id = cce.id') // ["ccep.value_element_id" => new Expression('cce.id')]
                //->join("INNER JOIN", ['product' => CmsContentElement::tableName()], 'product.id = ccep.element_id') //["product.id" => new Expression('ccep.element_id')]
                ->andWhere(['is not', 'ccep.value_element_id', null])
                ->andWhere(['in', 'ccep.element_id', $this->elementIds])
                //->andWhere(['in', 'product.id', $this->elementIds])
                ->andWhere(['in', 'ccep.property_id', $property_types])
                //->groupBy(['cce.id'])
                ->orderBy(['ccep.value_string' => SORT_ASC])
            ;*/

            //print_r($q->createCommand()->rawSql);die;

            $options = $q->all();
            /*print_r($options);die;*/
            /*$elementIdsString = implode(",", $this->elementIds);
            $result = \Yii::$app->db->createCommand(<<<SQL
SELECT 
    cce.id as 'key',
    cce.name as value,
    ccep.property_id as property_id,
    ccep.value_element_id,
    count(*) as total
FROM 
	`cms_content_element` as cce
    LEFT JOIN `cms_content_element_property` ccep ON ccep.value_element_id = cce.id 
    LEFT JOIN `cms_content_element` product ON product.id = ccep.element_id 
WHERE 
	ccep.value_element_id is not null AND
    product.id in ({$elementIdsString})
GROUP BY cce.id
SQL
            )->queryAll();*/


            //print_r($options->createCommand()->rawSql);die;

            if ($options) {
                foreach ($options as $row) {
                    //$this->_elementEnums[$row['property_id']][$row['key']] = $row['value'] . " ({$row['total']})";
                    $this->_elementEnums[$row['property_id']][$row['key']] = $row['value'];
                }
            }

        }

                //print_r($this->_elementEnums);

        if ($property_id) {
            return ArrayHelper::getValue($this->_elementEnums, $property_id);
        }

        return $this->_elementEnums;
    }


    protected $_listEnums = null;

    protected function _getListEnums($property_id = null)
    {
        if ($this->_listEnums === null) {

            $property_types = [];
            if ($this->_rps) {
                /**
                 * @var $rp CmsContentProperty
                 */
                foreach ($this->_rps as $rp) {
                    if ($rp->property_type == PropertyType::CODE_LIST) {
                        $property_types[$rp->id] = $rp->id;
                    }
                }
            }

            $this->_listEnums = [];


            $q = (new Query())
                ->select([
                    'key'           => 'ccep.value_enum_id',
                    'value_enum_id' => 'ccep.value_enum_id',
                    'value'         => 'enum.value',
                    'property_id'   => 'ccep.property_id',
                    //'total'      => new Expression("count(1)"),
                ])
                ->from([
                    'ccep' => CmsContentElementProperty::tableName(),
                ])
                ->join("INNER JOIN", ['product' => CmsContentElement::find()->select(['id'])->where(['in', CmsContentElement::tableName().".id", $this->elementIds])], 'product.id = ccep.element_id')
                ->innerJoin(['enum' => CmsContentPropertyEnum::tableName()], 'enum.id = ccep.value_enum_id')
                ->andWhere(['is not', 'ccep.value_enum_id', null])
                ->andWhere(['>', 'ccep.value_enum_id', 0])
                ->andWhere(['in', 'ccep.property_id', $property_types])
                ->groupBy(['ccep.value_enum_id'])
                ->orderBy(['value' => SORT_ASC]);


            /*$q = \skeeks\cms\models\CmsContentElementProperty::find()
                ->from([
                    'map' => \skeeks\cms\models\CmsContentElementProperty::tableName(),
                ])
                ->innerJoin(['enum' => CmsContentPropertyEnum::tableName()], 'enum.id = map.value_enum_id')
                ->select([
                    'enum.id as key', 
                    'enum.value as value', 
                    'map.value_enum_id', 
                    'map.property_id'
                ])
                ->indexBy('key')
                ->groupBy('key')
                ->andWhere(['map.element_id' => $this->elementIds])
                ->andWhere(['>', 'map.value_enum_id', 0])
                ->andWhere(['>', 'enum.id', 0])
                ->andWhere(['is not', 'map.value_enum_id', null])
                ->andWhere(['map.property_id' => $property_types])
                ->orderBy(['value' => SORT_ASC]);*/

            //print_r($q->createCommand()->rawSql);die;

            $options = $q
                //->asArray()
                ->all();

            //print_r($options);die;

            if ($options) {
                foreach ($options as $row) {
                    $this->_listEnums[$row['property_id']][$row['key']] = $row['value'];
                }
            }

        }

        if ($property_id) {
            return ArrayHelper::getValue($this->_listEnums, $property_id);
        }

        return $this->_listEnums;
    }

    protected $_rp_options = null;

    /**
     * @param null $property_id
     * @return mixed|null
     * @deprecated
     */
    protected function _getRpOptions($property_id = null)
    {
        if ($this->_rp_options === null) {

            $property_types_elements = [];
            $property_types_list = [];
            $property_types = [];
            if ($this->_rps) {
                /**
                 * @var $rp CmsContentProperty
                 */
                foreach ($this->_rps as $rp) {
                    if ($rp->property_type == PropertyType::CODE_LIST) {
                        $property_types[$rp->id] = $rp->id;
                        $property_types_list[$rp->id] = $rp->id;
                    }

                    if ($rp->property_type == PropertyType::CODE_ELEMENT) {
                        $property_types[$rp->id] = $rp->id;
                        $property_types_elements[$rp->id] = $rp->id;
                    }
                }
            }

            $this->_listEnums = [];

            $property_types_list_string = "";
            if ($property_types_list) {
                $property_types_list_string = implode(",", $property_types_list);
            }

            $property_types_elements_string = "";
            if ($property_types_elements) {
                $property_types_elements_string = implode(",", $property_types_elements);
            }

            $q = \skeeks\cms\models\CmsContentElementProperty::find()->from([
                'map' => \skeeks\cms\models\CmsContentElementProperty::tableName(),
            ])
                ->leftJoin(['enum' => CmsContentPropertyEnum::tableName()], 'enum.id = map.value_enum')
                ->leftJoin(['e' => CmsContentElement::tableName()], 'e.id = map.value_enum')
                ->select([
                    'map.value_enum as key',
                    //'enum.value as value',
                    new Expression("IF(map.property_id in ({$property_types_elements_string}), e.name, enum.value) as value"),
                    'map.value_enum',
                    'map.property_id',
                ])
                ->indexBy('key')
                ->groupBy('key')
                ->andWhere(['map.element_id' => $this->elementIds])
                ->andWhere(['>', 'map.value_enum', 0])
                //->andWhere(['>', 'enum.id', 0])
                ->andWhere(['is not', 'map.value_enum', null])
                ->andWhere(['map.property_id' => $property_types]);


            $options = $q->asArray()
                ->all();


            if ($options) {
                foreach ($options as $row) {
                    $this->_rp_options[$row['property_id']][$row['key']] = $row['value'];
                }
            }

        }

        if ($property_id) {
            return ArrayHelper::getValue($this->_rp_options, $property_id);
        }

        return $this->_rp_options;
    }


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
            $options = $this->_getElementEnums($rp->id);
            //$options = $this->_getRpOptions($rp->id);

        } elseif ($rp->property_type == \skeeks\cms\relatedProperties\PropertyType::CODE_LIST) {

            $options = $this->_getListEnums($rp->id);
            //$options = $this->_getRpOptions($rp->id);

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


    protected $_minMaxValues = null;

    public function _getMinMaxValues($property_id = null)
    {
        if ($this->_minMaxValues === null) {

            $property_types = [];
            if ($this->_rps) {
                /**
                 * @var $rp CmsContentProperty
                 */
                foreach ($this->_rps as $rp) {
                    if ($rp->property_type == PropertyType::CODE_NUMBER) {
                        $property_types[$rp->id] = $rp->id;
                    }
                }
            }

            $this->_minMaxValues = [];

            $q = (new Query())
                ->select([
                    'max'         => new Expression('MAX(ccep.value_enum)'),
                    'min'         => new Expression('MIN(ccep.value_enum)'),
                    'property_id'   => 'ccep.property_id',
                    //'total'      => new Expression("count(1)"),
                ])
                ->from([
                    'ccep' => CmsContentElementProperty::tableName(),
                ])
                ->join("INNER JOIN", ['product' => CmsContentElement::find()->select(['id'])->where(['in', CmsContentElement::tableName().".id", $this->elementIds])], 'product.id = ccep.element_id')
                ->andWhere(['in', 'ccep.property_id', $property_types])
                ->groupBy(['ccep.property_id'])
            ;

            $this->_minMaxValues = $q->indexBy('property_id')->all();
        }

        if ($property_id) {
            return ArrayHelper::getValue($this->_minMaxValues, $property_id);
        }

        return $this->_minMaxValues;
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
        
        if ($data = $this->_getMinMaxValues($rp->id)) {
            return $data['max'];
        }

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


                if ($value && isset($value['value_enum'])) {
                    $value = (float) $value['value_enum'];
                }
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
        
        if ($data = $this->_getMinMaxValues($rp->id)) {
            return $data['min'];
        }

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

                if ($value && isset($value['value_enum'])) {
                    $value = (float) $value['value_enum'];
                }
                

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

            $this->_applyToQuery($activeQuery, $unionQuery);

        }

        return $this;
    }

    protected function _applyToQuery(ActiveQuery $activeQuery, $unionQuery)
    {
        $activeQuery->joinWith("childrenContentElements as childrenContentElements");
        $activeQuery->andWhere([
            'or',
            ['in', CmsContentElement::tableName().'.id', $unionQuery],
            ['in', 'childrenContentElements.id', $unionQuery],
        ]);
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
