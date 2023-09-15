<?php
namespace Framework\Schema;

use Framework\Schema\Field;
use Framework\Utils\Numbers;
use Framework\Utils\Strings;

/**
 * The Schema Count
 */
class Count {

    public Field  $field;
    public string $key       = "";
    public bool   $isSum     = false;
    public bool   $isCount   = false;
    public string $value     = "";
    public int    $mult      = 1;

    public string $table     = "";
    public string $onTable   = "";
    public string $leftKey   = "";
    public string $rightKey  = "";
    public bool   $noDeleted = false;

    /** @var mixed[] */
    private array  $where    = [];


    /**
     * Creates a new Count instance
     * @param string  $key
     * @param array{} $data
     */
    public function __construct(string $key, array $data) {
        $this->field     = new Field($key, $data);
        $this->key       = $key;

        $this->isSum     = !empty($data["isSum"]) && $data["isSum"];
        $this->isCount   = empty($data["isSum"])  || !$data["isSum"];
        $this->value     = !empty($data["value"])    ? $data["value"]     : "";
        $this->mult      = !empty($data["mult"])     ? (int)$data["mult"] : 1;

        $this->table     = $data["table"];
        $this->onTable   = !empty($data["onTable"])  ? $data["onTable"]   : "";
        $this->rightKey  = !empty($data["rightKey"]) ? $data["rightKey"]  : $data["key"];
        $this->leftKey   = !empty($data["leftKey"])  ? $data["leftKey"]   : $data["key"];
        $this->where     = !empty($data["where"])    ? $data["where"]     : [];
        $this->noDeleted = !empty($data["noDeleted"]) && $data["noDeleted"];
    }



    /**
     * Returns the Expression for the Query
     * @param string $asTable
     * @param string $mainKey
     * @return string
     */
    public function getExpression(string $asTable, string $mainKey): string {
        $key        = $this->key;
        $what       = $this->isSum ? "SUM({$this->mult} * {$this->value})" : "COUNT(*)";
        $table      = $this->table;
        $onTable    = $this->onTable ?: $mainKey;
        $leftKey    = $this->leftKey;
        $rightKey   = $this->rightKey;
        $groupKey   = "$table.$rightKey";
        $where      = $this->getWhere();
        $select     = "SELECT $groupKey, $what AS $key FROM $table $where GROUP BY $groupKey";
        $expression = "LEFT JOIN ($select) AS $asTable ON ($asTable.$leftKey = $onTable.$rightKey)";
        return $expression;
    }

    /**
     * Returns the Count Where
     * @return string
     */
    private function getWhere(): string {
        if (empty($this->where) && !$this->noDeleted) {
            return "";
        }

        $query = [];
        if ($this->noDeleted) {
            $query[] = "isDeleted = 0";
        }

        $total = count($this->where);
        if ($total % 3 == 0) {
            for ($i = 0; $i < $total; $i += 3) {
                $query[] = "{$this->where[$i]} {$this->where[$i + 1]} {$this->where[$i + 2]}";
            }
        }

        if (empty($query)) {
            return "";
        }
        return "WHERE " . Strings::join($query, " AND ");
    }

    /**
     * Returns the Count select name
     * @param string $joinKey
     * @return string
     */
    public function getSelect(string $joinKey): string {
        return "$joinKey.{$this->key}";
    }



    /**
     * Returns the Count Value
     * @param array{} $data
     * @return mixed
     */
    public function getValue(array $data): mixed {
        $key    = $this->key;
        $result = !empty($data[$key]) ? $data[$key] : 0;

        if ($this->field->type == Field::Float) {
            $result = Numbers::toFloat($result, $this->field->decimals);
        } elseif ($this->field->type == Field::Price) {
            $result = Numbers::fromCents($result);
        }
        return $result;
    }
}
