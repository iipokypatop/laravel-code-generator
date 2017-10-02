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
       COLUMN_NAME as COLUMN_NAME
      ,COLUMN_DEFAULT
      ,UPPER(IS_NULLABLE)  AS IS_NULLABLE
      ,LOWER(DATA_TYPE) AS DATA_TYPE
      ,CHARACTER_MAXIMUM_LENGTH
      --,UPPER(COLUMN_KEY) AS COLUMN_KEY  
      ,'' AS COLUMN_KEY
      ,'' AS EXTRA
      ,'' as COLUMN_COMMENT
      ,data_type as COLUMN_TYPE
      FROM INFORMATION_SCHEMA.COLUMNS
      -- WHERE TABLE_NAME = 'project' AND TABLE_SCHEMA = 'pepsico_uws'
          WHERE TABLE_NAME = ? AND TABLE_CATALOG = ?
      
      
      
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

            //var_export($column);die;

            $properties['name'] = $column->column_name;
            $properties['labels'] = $this->getLabel($column->column_name);
            $properties['is-nullable'] = ($column->is_nullable == 'yes');
            $properties['data-value'] = $column->column_default;
            $properties['data-type'] = $this->getDataType($column->data_type);
            $properties['data-type-params'] = $this->getPrecision($column->character_maximum_length, $column->data_type, $column->column_type);

            $properties['is-primary'] = ($column->column_name == 'id');

            $properties['is-index'] = false;

            $properties['is-unique'] = ($column->column_name == 'id');
            $properties['is-auto-increment'] = ($column->column_name == 'id');

            $properties['comment'] = $column->column_comment ?: null;
            ///$properties['options'] = $this->gethtmloptions($column->data_type, $column->column_type); //????
            $properties['options'] = [];
            $properties['is-unsigned'] = (stripos($column->column_type, 'unsigned') !== false);
            //$properties['html-type'] = $this->gethtmltype($column->data_type);
            $properties['html-type'] = 'string';

            $properties['foreign-constraint'] = null;

            if (intval($column->character_maximum_length) > 255
                || in_array($column->data_type, ['varbinary', 'blob', 'mediumblob', 'longblob', 'text', 'mediumtext', 'longtext'])
            ) {
                $properties['is-on-index'] = false;
            }

            $collection[] = $properties;
        }
        $localegroup = str_plural(strtolower($this->tableName));

        $fields = FieldTransformer::fromArray($collection, $localegroup);

       # var_export($fields);die;
        
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
            echo "####$type####\n";
            throw new Exception("The type " . $type . " is not mapped in the 'eloquent_type_to_method' key in the config file.");
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
            $match = [];

            preg_match('#\((.*?)\)#', $columnType, $match);

            if (!isset($match[1])) {
                return null;
            }

            return explode(',', $match[1]);
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
        if (is_null($this->constrains)) {
            $this->constrains = DB::select('SELECT 
                                            r.referenced_table_name AS `REFERENCES`
                                           ,r.CONSTRAINT_NAME AS `NAME`
                                           ,r.UPDATE_RULE AS `onUpdate`
                                           ,r.DELETE_RULE AS `onDelete`
                                           ,u.referenced_column_name AS `ON`
                                           ,u.column_name AS `FOREIGN`
                                           FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS AS r
                                           INNER JOIN information_schema.key_column_usage AS u ON u.CONSTRAINT_NAME = r.CONSTRAINT_NAME
                                                                                               AND u.table_schema = r.constraint_schema
                                                                                               AND u.table_name = r.table_name
                                           WHERE u.table_name = ? AND u.constraint_schema = ?;',
                [$this->tableName, $this->databaseName]);
        }

        return $this->constrains;
    }
}
