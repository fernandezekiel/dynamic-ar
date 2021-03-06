<?php
/**
 * @link https://github.com/tom--/dynamic-ar
 * @copyright Copyright (c) 2015 Spinitron LLC
 * @license http://opensource.org/licenses/ISC
 */

namespace spinitron\dynamicAr;

use Yii;
use yii\base\UnknownPropertyException;
use yii\db\ActiveQuery;
use yii\db\Connection;

/**
 * DynamicActiveRecord represents queries on relational data with structured dynamic attributes.
 *
 * DynamicActiveQuery adds an abstraction for writing queries that involve
 * the dynamic attributes of a DynamicAccessRecord. This is only possible on
 * a DBMS that supports querying elements in serialized data structures.
 *
 * > NOTE: In this version only Maria 10.0+ is supported.
 *
 * @author Tom Worster <fsb@thefsb.org>
 * @author Danil Zakablukovskii danil.kabluk@gmail.com
 */
class DynamicActiveQuery extends ActiveQuery
{
    /**
     * @var Connection
     */
    private $db;
    /**
     * @var string
     */
    private $dynamicColumn;

    /**
     * Convert index value to closure, that will get decoded dynamic attribute, in case if indexing attribute is dynamic
     * @param callable|string $column
     * @return $this
     */
    public function indexBy($column)
    {
        if (!$this->asArray) {
            return parent::indexBy($column);
        }

        /** @var DynamicActiveRecord $modelClass */
        $modelClass = $this->modelClass;
        $this->indexBy = function ($row) use ($column, $modelClass) {
            if (isset($row[$column])) {
                return $row[$column];
            }

            $dynamicColumn = $modelClass::dynamicColumn();
            if (!isset($row[$dynamicColumn])) {
                throw new UnknownPropertyException("Dynamic column {$dynamicColumn} does not exist - wasn't set in select");
            }

            $dynamicAttributes = DynamicActiveRecord::dynColDecode($row[$dynamicColumn]);
            $value = $this->getDotNotatedValue($dynamicAttributes, $column);

            return $value;
        };

        return $this;
    }

    /**
     * Maria-specific preparation for building a query that includes a dynamic column.
     *
     * @param \yii\db\QueryBuilder $builder
     *
     * @return \yii\db\Query
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function prepare($builder)
    {
        /** @var DynamicActiveRecord $modelClass */
        $modelClass = $this->modelClass;
        $this->dynamicColumn = $modelClass::dynamicColumn();

        if (empty($this->dynamicColumn)) {
            /** @var string $modelClass */
            throw new \yii\base\InvalidConfigException(
                $modelClass . '::dynamicColumn() must return an attribute name'
            );
        }

        if (empty($this->select)) {
            $this->select[] = '*';
        }

        if (is_array($this->select) && in_array('*', $this->select)) {
            $this->db = $modelClass::getDb();
            $this->select[$this->dynamicColumn] =
                'COLUMN_JSON(' . $this->db->quoteColumnName($this->dynamicColumn) . ')';
        }

        return parent::prepare($builder);
    }

    /**
     * Generate DB command from ActiveQuery with Maria-specific SQL for dynamic columns.
     *
     * This implementation is the best hack I could manage. A dynamic attribute name
     * can appear anywhere that a schema attribute name could appear (select, join, where, ...).
     * It needs to be converted to the Maria SQL for accessing dynamic columns.
     * Because SQL is statically-typed and there is no schema to refer to for dynamic
     * attributes, the accessor SQL must specify the the dyn-col's type, e.g.
     *
     * ```sql
     * WHERE COLUMN_GET(details, 'color' as char) = 'black'
     *```
     *
     * In which details is the blob column containing all the dynamic columns, 'color' is the
     * name of a dynamic column that may or may not appear in any given table record, and
     * char means the value should be cast to char before it is compared with 'black'.
     * `COLUMN_GET(details, 'color' as char)` is the "accessor SQL".
     *
     * So I faced two problems:
     *    1. How to identify a dynamic attribute name in an ActiveQuery?
     *    2. How to choose the type to which it should be cast in the SQL?
     *
     * The operating and design concept of DynamicAR is "an attribute that doesn't appear in the
     * schema and doesn't have a magic get-/setter is assumed to be a dynamic attribute".
     * So, in order to infer from the properties of an AQ instance the attribute names
     * that need to be converted to dynamic column accessor SQL, I need to go through
     * the AQ to identify
     * all the column names and remove those in the schema. But I don't know how to
     * identify column names in an AQ instance. Even if I did, there's problem 2.
     *
     * The only way I can imagine to infer datatype from an AQ instance is to look
     * at the context. If the attribute is compared with a bound parameter, that's a clue.
     * If it is being used in an SQL function, e.g. CONCAT(), or being compared with a
     * schema column, that suggests something. But if it is on its own in a SELECT then I am
     * stuck. Also stuck if it is compared with another dynamic attribute. This seems
     * fundamentally intractible to me.
     *
     * So I decided that the user needs to help DynamicActiveQuery by distinguishing the names
     * of dynamic attributes and by explicitly specifying the type. The format I chose is:
     *
     *     (!name|type!)
     *
     * Omitting type implies the default type: CHAR. Children of dynamic attributes, i.e.
     * array elements, are separated from parents with . (period), e.g. (!address.country|CHAR!).
     * Spaces are not tolerated. So a user can do:
     *
     *     $blackShirts = Product::find()
     *         ->where(['category' => Product::SHIRT, '(!color!)' => 'black'])
     *         ->all();
     *
     *     $cheapShirts = Product::find()
     *         ->select(['sale' => 'MAX((!cost|decimal(6,2)!), 0.75 * (!price.wholesale.12|decimal(6,2)!))'])
     *         ->where(['category' => Product::SHIRT])
     *         ->andWhere('(!price.retail.unit|decimal(6,2)!) < 20.00')
     *         ->all();
     *
     * The implementation is like db\Connection's quoting of [[string]] and {{string}}. Once
     * the full SQL string is ready, `preg_repalce()` it. The regex pattern is a bit complex
     * and the replacement callback isn't pretty either. Is there a better way to add to
     * `$params` in the callback than this? And for the parameter placeholder counter `$i`?
     *
     * @param null|\yii\db\Connection $db
     *
     * @return \yii\db\Command
     */
    public function createCommand($db = null)
    {
        /** @var DynamicActiveRecord $modelClass */
        $modelClass = $this->modelClass;
        if ($db === null) {
            $db = $modelClass::getDb();
        }

        if ($this->sql === null) {
            list ($sql, $params) = $db->getQueryBuilder()->build($this);
        } else {
            $sql = $this->sql;
            $params = $this->params;
        }

        $dynamicColumn = $modelClass::dynamicColumn();
        $callback = function ($matches) use (&$params, $dynamicColumn) {
            $type = !empty($matches[3]) ? $matches[3] : 'CHAR';
            $sql = $dynamicColumn;
            foreach (explode('.', $matches[2]) as $column) {
                $placeholder = DynamicActiveRecord::placeholder();
                $params[$placeholder] = $column;
                $sql = "COLUMN_GET($sql, $placeholder AS $type)";
            }

            return $sql;
        };

        $pattern = <<<'REGEXP'
            % (`?) \(!
                ( [a-z_\x7f-\xff][a-z0-9_\x7f-\xff]* (?: \. [^.|\s]+)* )
                (?:  \| (binary (?:\(\d+\))? | char (?:\(\d+\))? | time (?:\(\d+\))? | datetime (?:\(\d+\))? | date
                        | decimal (?:\(\d\d?(?:,\d\d?)?\))?  | double (?:\(\d\d?,\d\d?\))?
                        | int(eger)? | (?:un)? signed (?:\s+int(eger)?)?)  )?
            !\) \1 %ix
REGEXP;
        $sql = preg_replace_callback($pattern, $callback, $sql);

        return $db->createCommand($sql, $params);
    }

    protected function getDotNotatedValue($array, $attribute)
    {
        $pieces = explode('.', $attribute);
        foreach ($pieces as $piece) {
            if (!is_array($array) || !array_key_exists($piece, $array)) {
                return null;
            }
            $array = $array[$piece];
        }

        return $array;
    }
}
