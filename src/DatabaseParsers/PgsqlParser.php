<?php

namespace CrestApps\CodeGenerator\DatabaseParsers;

use DB;
use App;
use CrestApps\CodeGenerator\DatabaseParsers\ParserBase;
use CrestApps\CodeGenerator\Models\Field;
use CrestApps\CodeGenerator\Models\ForeignConstraint;
use CrestApps\CodeGenerator\Support\FieldTransformer;
use CrestApps\CodeGenerator\Support\Config;

class PgsqlParser extends \CrestApps\CodeGenerator\DatabaseParsers\ParserBase
{
    /**
     * List of the foreign constraints.
     *
     * @var array
     */
    protected $constrains;

    /**
     * Gets column meta info from the information schema.
     *
     * @return array
     */
    protected function getColumn()
    {

        $q = <<<SQL
SELECT
       
       COLUMN_NAME AS COLUMN_NAME
      ,COLUMN_DEFAULT
      ,UPPER(IS_NULLABLE)  AS IS_NULLABLE
      ,LOWER(DATA_TYPE) AS DATA_TYPE
      ,CHARACTER_MAXIMUM_LENGTH
      --,UPPER(COLUMN_KEY) AS COLUMN_KEY  
      ,'' AS COLUMN_KEY
      ,'' AS EXTRA
      ,'' AS COLUMN_COMMENT
      ,data_type AS COLUMN_TYPE
      FROM INFORMATION_SCHEMA.COLUMNS
      -- WHERE TABLE_NAME = 'project' AND TABLE_SCHEMA = 'pepsico_uws'
          WHERE TABLE_NAME = ? AND TABLE_CATALOG = ?
      order by ordinal_position asc
      
      
SQL;

        return DB::select(
            $q, [$this->tableName, $this->databaseName]
        );
    }

    /**
     * Gets the field after transfering it from a giving query object.
     *
     * @param object $column
     *
     * @return CrestApps\CodeGenerator\Model\Field;
     */
    protected function getTransfredFields(array $columns)
    {
        $collection = [];

        foreach ($columns as $column) {

            $properties['name'] = $column->column_name;
            $properties['labels'] = $this->getLabel($column->column_name);
            $properties['is-nullable'] = ($column->is_nullable == strtolower('yes'));
            $properties['data-value'] = $column->column_default;
            $properties['data-type'] = $this->getDataType($column->data_type);
            $properties['data-type-params'] = $this->getPrecision($column->character_maximum_length, $column->data_type, $column->column_type);
            $properties['is-primary'] = ($column->column_name == 'id');
            $properties['is-index'] = false;
            $properties['is-unique'] = ($column->column_name == 'id');
            $properties['is-auto-increment'] = strpos($column->column_default, 'nextval(') === 0;
            $properties['comment'] = $column->column_comment ?: null;
            ///$properties['options'] = $this->gethtmloptions($column->data_type, $column->column_type); //????
            $properties['options'] = [];
            $properties['is-unsigned'] = (stripos($column->column_type, 'unsigned') !== false);
            $properties['html-type'] = $this->gethtmltype($column->data_type);
            ///$properties['html-type'] = 'string';


            if ($this->isInRelationIgnore($this->tableName, $column->column_name)) {
                $properties['is-foreign-relation'] = false;
            }

            if (!$this->isInRelationIgnore($this->tableName, $column->column_name)) {
                $properties['foreign-constraint'] = $this->getForeignConstraint($column->column_name);
            }


            if (intval($column->character_maximum_length) > 255
                || in_array($column->data_type, ['varbinary', 'blob', 'mediumblob', 'longblob', 'text', 'mediumtext', 'longtext'])
            ) {
                $properties['is-on-index'] = false;
            }

            $collection[] = $properties;
        }
        $localegroup = str_plural(strtolower($this->tableName));

        $fields = FieldTransformer::fromArray($collection, $localegroup);


        return $fields;
    }

    /**
     * Gets the data type for a giving field.
     *
     * @param string $type
     *
     * @return $this
     */
    protected function getDataType($type)
    {
        $map = Config::dataTypeMap();

        if (!array_key_exists($type, $map)) {

            throw new \Exception("The type " . $type . " is not mapped in the 'eloquent_type_to_method' key in the config file.");
        }

        return $map[$type];
    }

    /**
     * Gets the type params
     *
     * @param string $length
     * @param string $dataType
     * @param string $columnType
     *
     * @return $this
     */
    protected function getPrecision($length, $dataType, $columnType)
    {
        if (in_array($dataType, ['decimal', 'double', 'float', 'real'])) {
//            $match = [];
//
//            preg_match('#\((.*?)\)#', $columnType, $match);
//
//            if (!isset($match[1])) {
//                return null;
//            }

            return [30,10];
        }

        if (intval($length) > 0) {
            return [$length];
        }

        return [];
    }



    /**
     * Set the options for a giving field.
     *
     * @param CrestApps\CodeGenerator\Models\Field $field
     * @param string $type
     *
     * @return array
     */
    protected function getHtmlOptions($dataType, $columnType)
    {
        if ($dataType == 'tinyint(1)') {
            return $this->getBooleanOptions();
        }

        if (($options = $this->getEnumOptions($columnType)) !== null) {
            return $options;
        }

        return [];
    }

    /**
     * Get boolean options
     *
     * @return array
     */
    protected function getBooleanOptions()
    {
        $options = [];
        if (!$this->hasLanguages()) {
            return $this->booleanOptions;
        }

        foreach ($this->booleanOptions as $key => $title) {
            foreach ($this->languages as $language) {
                $options[$key][$language] = $title;
            }
        }

        return $options;
    }

    /**
     * Parses out the options from a giving type
     *
     * @param string $type
     *
     * @return mix (null|array)
     */
    protected function getEnumOptions($type)
    {
        $match = [];

        preg_match('#enum\((.*?)\)#', $type, $match);

        if (!isset($match[1])) {
            return null;
        }

        $options = array_map(function ($option) {
            return trim($option, "'");
        }, explode(',', $match[1]));

        $finals = [];

        foreach ($options as $option) {
            if ($this->hasLanguages()) {
                foreach ($this->languages as $language) {
                    $finals[$language][$option] = $option;
                }
                continue;
            }

            $finals[$option] = $option;
        }

        return $finals;
    }

    /**
     * Gets the foreign constrain for the giving field.
     *
     * @param string $name
     *
     * @return $this
     */
    protected function getForeignConstraint($name)
    {
        $raw = $this->getConstraint($name);

        if (is_null($raw)) {
            return null;
        }

        return [
            'field' => strtolower($raw->foreign),
            'references' => strtolower($raw->references),
            'on' => strtolower($raw->on),
            'on-delete' => strtolower($raw->onDelete),
            'on-update' => strtolower($raw->onUpdate)
        ];
    }

    /**
     * Gets foreign key constraint info for a giving column name.
     *
     * @return mix (null|object)
     */
    protected function getConstraint($foreign)
    {
        foreach ($this->getConstraints() as $constraint) {
            if ($constraint->foreign == $foreign) {
                return (object)$constraint;
            }
        }

        return null;
    }

    /**
     * Gets foreign key constraints info from the information schema.
     *
     * @return array
     */
    protected function getConstraints()
    {
        $q = <<<SQL
SELECT
    tc.constraint_name, 
    tc.table_name AS "table_name", 
    kcu.column_name AS "foreign", 
    ccu.table_name AS "references" , --foreign_table_name,
    ccu.column_name AS "on" , --foreign_column_name 
    NULL AS "onUpdate",
    NULL AS "onDelete"
    
FROM 
    information_schema.table_constraints AS tc 
    JOIN information_schema.key_column_usage AS kcu ON tc.constraint_name = kcu.constraint_name
    JOIN information_schema.constraint_column_usage AS ccu  ON ccu.constraint_name = tc.constraint_name
WHERE 
  constraint_type = 'FOREIGN KEY'
  AND tc.table_name = ? AND tc.table_catalog    = ? 
SQL;

        if (is_null($this->constrains)) {
            $this->constrains = DB::select(
                $q,
                [
                    $this->tableName,
                    $this->databaseName
                ]
            );
        }

        return $this->constrains;
    }

    protected function isInRelationIgnore($table_name, $field_name)
    {
        $ignore_foreign_constraint = config('codegenerator.ignore_foreign_constraint', []);

        if (empty($ignore_foreign_constraint)) {
            return false;
        }

        if (
            !empty($ignore_foreign_constraint[$table_name])
            && is_array($ignore_foreign_constraint[$table_name])
            && in_array($field_name, $ignore_foreign_constraint[$table_name], true)
        ) {
            return true;
        }

        return false;
    }
}
